<?php
/**
 * The submissions store: the ingredient lists people have decoded.
 *
 * A custom table (not a post type), because these are rows of data, not
 * content: it holds the normalised ingredient list, a findings summary, the
 * time, the optional product name, and the ID of the lead who submitted it.
 * It is indexed on lead ID and on date.
 *
 * A submission made before an address is given is stored with a null lead ID
 * and a session token; the moment the gate is completed in the same session,
 * those rows are attached to the new lead, so nothing is left orphaned. When a
 * lead is deleted, its submissions go with it in the same operation.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the submissions table.
 */
class ILD_Submissions {

	/**
	 * The unqualified table name (without the WordPress prefix).
	 *
	 * @var string
	 */
	const TABLE = 'ild_submissions';

	/**
	 * The session cookie used to attach pre-gate submissions to a lead.
	 *
	 * @var string
	 */
	const SESSION_COOKIE = 'ild_session';

	/**
	 * Nothing to hook: the table is created on activation and on upgrade.
	 *
	 * @return void
	 */
	public function register_hooks() {}

	/**
	 * The full, prefixed table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or update the table. Safe to run repeatedly.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			lead_id bigint(20) unsigned DEFAULT NULL,
			session_token varchar(64) DEFAULT NULL,
			product_name varchar(200) DEFAULT NULL,
			normalised_list longtext,
			findings_summary longtext,
			source_url varchar(255) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY created_at (created_at),
			KEY session_token (session_token)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Store one submission.
	 *
	 * @param array $data {
	 *     @type int|null $lead_id          The lead, or null when made pre-gate.
	 *     @type string   $session_token    The session token, for later attaching.
	 *     @type string   $normalised_list  The normalised ingredient list.
	 *     @type string   $findings_summary A findings summary (already a string).
	 *     @type string   $product          An optional product name.
	 *     @type string   $source           The source page URL.
	 * }
	 * @return int The new submission ID, or 0 on failure.
	 */
	public static function store( $data ) {
		global $wpdb;

		$lead_id = isset( $data['lead_id'] ) && $data['lead_id'] ? (int) $data['lead_id'] : null;

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'lead_id'          => $lead_id,
				'session_token'    => isset( $data['session_token'] ) ? substr( (string) $data['session_token'], 0, 64 ) : null,
				'product_name'     => isset( $data['product'] ) ? substr( sanitize_text_field( $data['product'] ), 0, 200 ) : null,
				'normalised_list'  => isset( $data['normalised_list'] ) ? (string) $data['normalised_list'] : '',
				'findings_summary' => isset( $data['findings_summary'] ) ? (string) $data['findings_summary'] : '',
				'source_url'       => isset( $data['source'] ) ? esc_url_raw( $data['source'] ) : '',
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Attach every pending pre-gate submission for a session to a lead.
	 *
	 * Called the moment the gate is completed, so a list decoded before the
	 * address was given still ends up on that person's record.
	 *
	 * @param string $session_token The session token from the cookie.
	 * @param int    $lead_id       The lead to attach them to.
	 * @return int The number attached.
	 */
	public static function attach_session_to_lead( $session_token, $lead_id ) {
		global $wpdb;

		$session_token = substr( (string) $session_token, 0, 64 );
		$lead_id       = (int) $lead_id;
		if ( '' === $session_token || $lead_id <= 0 ) {
			return 0;
		}

		$table = self::table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE $table SET lead_id = %d WHERE session_token = %s AND lead_id IS NULL",
				$lead_id,
				$session_token
			)
		);
	}

	/**
	 * A lead's submission history, newest first.
	 *
	 * @param int $lead_id The lead.
	 * @return array<int,array> Each: { id, captured, product, list, source }.
	 */
	public static function get_by_lead( $lead_id ) {
		global $wpdb;

		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return array();
		}

		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM $table WHERE lead_id = %d ORDER BY created_at DESC, id DESC", $lead_id ),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'       => (int) $row['id'],
				'captured' => (string) $row['created_at'],
				'product'  => (string) $row['product_name'],
				'list'     => (string) $row['normalised_list'],
				'source'   => (string) $row['source_url'],
			);
		}

		return $out;
	}

	/**
	 * Delete every submission belonging to a lead.
	 *
	 * @param int $lead_id The lead.
	 * @return int The number deleted.
	 */
	public static function delete_by_lead( $lead_id ) {
		global $wpdb;

		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return 0;
		}

		return (int) $wpdb->delete( self::table(), array( 'lead_id' => $lead_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Count submissions since a given GMT datetime.
	 *
	 * @param string $since_gmt A 'Y-m-d H:i:s' GMT datetime.
	 * @return int
	 */
	public static function count_since( $since_gmt ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_at >= %s", $since_gmt )
		);
	}

	/**
	 * Read (and, if needed, set) the session token for attaching submissions.
	 *
	 * @return string
	 */
	public static function session_token() {
		if ( ! empty( $_COOKIE[ self::SESSION_COOKIE ] ) ) {
			return substr( sanitize_text_field( wp_unslash( $_COOKIE[ self::SESSION_COOKIE ] ) ), 0, 64 );
		}

		$token = wp_generate_password( 32, false );

		if ( ! headers_sent() ) {
			setcookie(
				self::SESSION_COOKIE,
				$token,
				array(
					'expires'  => 0, // Session cookie.
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::SESSION_COOKIE ] = $token;

		return $token;
	}
}
