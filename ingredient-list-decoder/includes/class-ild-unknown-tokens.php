<?php
/**
 * The unknown-ingredient queue: tokens the matcher couldn't place.
 *
 * A custom table holding each unmatched token, a count of how many times it has
 * appeared, and the date it was first seen. It holds no lead reference at all —
 * it is a working queue of things to add to the library, not a record of anyone.
 *
 * A token that is a typo or rubbish can be dismissed; a genuine one can be drafted
 * into a needs-review ingredient. Its appearance count is what orders the queue,
 * and it is carried onto the drafted ingredient so the review queue can be
 * ordered by demand too.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the unknown-tokens table.
 */
class ILD_Unknown_Tokens {

	/**
	 * The unqualified table name (without the WordPress prefix).
	 *
	 * @var string
	 */
	const TABLE = 'ild_unknown_tokens';

	// Statuses.
	const STATUS_OPEN      = 'open';
	const STATUS_DISMISSED = 'dismissed';
	const STATUS_DRAFTED   = 'drafted';

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
			token varchar(191) NOT NULL,
			appearances bigint(20) unsigned NOT NULL DEFAULT 1,
			first_seen datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'open',
			ingredient_id bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY status (status),
			KEY appearances (appearances)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Record one appearance of an unmatched token.
	 *
	 * New tokens are inserted; a token already seen has its count bumped, keeping
	 * its first-seen date and its status (a dismissed token stays dismissed).
	 *
	 * @param string $token The token (will be normalised for de-duplication).
	 * @return void
	 */
	public static function record( $token ) {
		global $wpdb;

		$token = self::normalise( $token );
		if ( '' === $token ) {
			return;
		}

		$table = self::table();

		// One upsert: insert at count 1, or add one to an existing row.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"INSERT INTO $table (token, appearances, first_seen, status) VALUES (%s, 1, %s, %s)
				 ON DUPLICATE KEY UPDATE appearances = appearances + 1",
				$token,
				current_time( 'mysql', true ),
				self::STATUS_OPEN
			)
		);
	}

	/**
	 * Normalise a token for storage and de-duplication.
	 *
	 * @param string $token The token.
	 * @return string
	 */
	public static function normalise( $token ) {
		$token = trim( (string) $token );
		if ( function_exists( 'mb_strtolower' ) ) {
			$token = mb_strtolower( $token, 'UTF-8' );
		} else {
			$token = strtolower( $token );
		}
		$token = preg_replace( '/\s+/u', ' ', $token );
		return substr( $token, 0, 191 );
	}

	/**
	 * The open tokens, most frequent first.
	 *
	 * @param int $limit How many to return.
	 * @return array<int,array> Each row as an associative array.
	 */
	public static function get_open( $limit = 200 ) {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, (int) $limit );

		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM $table WHERE status = %s ORDER BY appearances DESC, first_seen ASC LIMIT %d",
				self::STATUS_OPEN,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Fetch one token row.
	 *
	 * @param int $id The row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * The appearance count for a token string (0 if unseen).
	 *
	 * @param string $token The token.
	 * @return int
	 */
	public static function count_for( $token ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT appearances FROM $table WHERE token = %s", self::normalise( $token ) )
		);
	}

	/**
	 * Mark a token dismissed (a typo or rubbish).
	 *
	 * @param int $id The row id.
	 * @return void
	 */
	public static function dismiss( $id ) {
		self::set_status( $id, self::STATUS_DISMISSED );
	}

	/**
	 * Mark a token drafted, linking the ingredient it became.
	 *
	 * @param int $id            The row id.
	 * @param int $ingredient_id The created ingredient.
	 * @return void
	 */
	public static function mark_drafted( $id, $ingredient_id ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'status' => self::STATUS_DRAFTED, 'ingredient_id' => (int) $ingredient_id ),
			array( 'id' => (int) $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Set a token's status.
	 *
	 * @param int    $id     The row id.
	 * @param string $status The status.
	 * @return void
	 */
	private static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'status' => $status ),
			array( 'id' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
