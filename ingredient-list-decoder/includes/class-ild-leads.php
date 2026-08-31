<?php
/**
 * Lead storage: the email addresses captured by the gate.
 *
 * A lead is stored as one private `ild_lead` entry, holding the address, the
 * moment it was captured, the consent state, the exact consent wording shown at
 * that moment, the page it came from, and its connector sync status. Keeping the
 * wording verbatim matters: it is the record of what the person actually agreed
 * to.
 *
 * This store is deliberately kept entirely separate from the submitted-lists
 * store (a later stage): there is no reference from a lead to a pasted list, and
 * no way to join the two. An email is never tied to the ingredients someone
 * looked up.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the lead store, writes leads, tracks sync status, and answers
 * WordPress privacy requests.
 */
class ILD_Leads {

	/**
	 * The lead post type.
	 *
	 * @var string
	 */
	const POST_TYPE = 'ild_lead';

	// Meta keys.
	const META_EMAIL         = '_ild_email';
	const META_CONSENT       = '_ild_consent';
	const META_CONSENT_TEXT  = '_ild_consent_text';
	const META_SOURCE        = '_ild_source';
	const META_CAPTURED      = '_ild_captured_gmt';
	const META_UNSUB         = '_ild_unsubscribed_gmt';
	const META_SYNC          = '_ild_sync_status';
	const META_SYNC_ERROR    = '_ild_sync_error';
	const META_SYNC_PROVIDER = '_ild_sync_provider';

	// Sync statuses.
	const SYNC_PENDING = 'pending';
	const SYNC_SYNCED  = 'synced';
	const SYNC_FAILED  = 'failed';

	/**
	 * Hook the post-type registration and the privacy handlers.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Register the private lead post type.
	 *
	 * It is deliberately not public and not queryable: leads never appear on the
	 * front end, and the admin screen is a custom one, so show_ui stays off.
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Leads', 'ingredient-list-decoder' ),
					'singular_name' => __( 'Lead', 'ingredient-list-decoder' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Store one captured lead.
	 *
	 * @param array $data {
	 *     The lead details.
	 *
	 *     @type string $email        The email address (already sanitised).
	 *     @type bool   $consent      Whether consent was given.
	 *     @type string $consent_text The exact consent wording shown.
	 *     @type string $source       The URL of the page it came from.
	 * }
	 * @return int|WP_Error The new lead ID, or an error.
	 */
	public static function store( $data ) {
		$email = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'ild_lead_email', 'A valid email is required to store a lead.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $email,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_EMAIL, $email );
		update_post_meta( $post_id, self::META_CONSENT, ! empty( $data['consent'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, self::META_CONSENT_TEXT, isset( $data['consent_text'] ) ? sanitize_textarea_field( $data['consent_text'] ) : '' );
		update_post_meta( $post_id, self::META_SOURCE, isset( $data['source'] ) ? esc_url_raw( $data['source'] ) : '' );
		// The capture time. The post date already records this, but a stored GMT
		// timestamp keeps it explicit and independent of any later re-save.
		update_post_meta( $post_id, self::META_CAPTURED, current_time( 'mysql', true ) );
		// Not yet handed to any marketing connector.
		update_post_meta( $post_id, self::META_SYNC, self::SYNC_PENDING );

		return $post_id;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Sync status (set by the connector stages, shown and retried here)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The recognised sync statuses and their labels.
	 *
	 * @return array<string,string>
	 */
	public static function sync_statuses() {
		return array(
			self::SYNC_PENDING => __( 'Pending', 'ingredient-list-decoder' ),
			self::SYNC_SYNCED  => __( 'Synced', 'ingredient-list-decoder' ),
			self::SYNC_FAILED  => __( 'Failed', 'ingredient-list-decoder' ),
		);
	}

	/**
	 * The sync status of a lead.
	 *
	 * @param int $lead_id The lead.
	 * @return string
	 */
	public static function get_sync_status( $lead_id ) {
		$status = get_post_meta( (int) $lead_id, self::META_SYNC, true );
		return in_array( $status, array( self::SYNC_PENDING, self::SYNC_SYNCED, self::SYNC_FAILED ), true ) ? $status : self::SYNC_PENDING;
	}

	/**
	 * Record a sync outcome on a lead.
	 *
	 * The connector stages call this. A failed sync keeps the rejection message
	 * so the failed-sync view can show why, because an outage is otherwise silent.
	 *
	 * @param int    $lead_id  The lead.
	 * @param string $status   One of the SYNC_* statuses.
	 * @param string $error    A rejection message, for a failed sync.
	 * @param string $provider The connector name, for the record.
	 * @return void
	 */
	public static function set_sync_status( $lead_id, $status, $error = '', $provider = '' ) {
		$lead_id = (int) $lead_id;
		if ( ! in_array( $status, array( self::SYNC_PENDING, self::SYNC_SYNCED, self::SYNC_FAILED ), true ) ) {
			$status = self::SYNC_PENDING;
		}

		update_post_meta( $lead_id, self::META_SYNC, $status );

		if ( self::SYNC_FAILED === $status ) {
			update_post_meta( $lead_id, self::META_SYNC_ERROR, sanitize_text_field( $error ) );
		} else {
			delete_post_meta( $lead_id, self::META_SYNC_ERROR );
		}

		if ( '' !== $provider ) {
			update_post_meta( $lead_id, self::META_SYNC_PROVIDER, sanitize_text_field( $provider ) );
		}
	}

	/**
	 * Queue a failed lead to be synced again.
	 *
	 * Sets it back to pending and fires an action the connector stages hook, so a
	 * transient outage can be recovered from without re-capturing the address.
	 *
	 * @param int $lead_id The lead.
	 * @return void
	 */
	public static function retry_sync( $lead_id ) {
		$lead_id = (int) $lead_id;
		if ( self::POST_TYPE !== get_post_type( $lead_id ) ) {
			return;
		}
		self::set_sync_status( $lead_id, self::SYNC_PENDING );
		do_action( 'ild_retry_lead_sync', $lead_id );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Reading and deleting
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The stored fields of a lead, as a flat array for display and export.
	 *
	 * @param int $lead_id The lead.
	 * @return array
	 */
	public static function get_lead( $lead_id ) {
		$lead_id = (int) $lead_id;
		return array(
			'id'           => $lead_id,
			'email'        => (string) get_post_meta( $lead_id, self::META_EMAIL, true ),
			'captured'     => (string) get_post_meta( $lead_id, self::META_CAPTURED, true ),
			'consent'      => 'yes' === get_post_meta( $lead_id, self::META_CONSENT, true ) ? 'yes' : 'no',
			'consent_text' => (string) get_post_meta( $lead_id, self::META_CONSENT_TEXT, true ),
			'source'       => (string) get_post_meta( $lead_id, self::META_SOURCE, true ),
			'sync'         => self::get_sync_status( $lead_id ),
			'sync_error'   => (string) get_post_meta( $lead_id, self::META_SYNC_ERROR, true ),
			'unsubscribed' => (string) get_post_meta( $lead_id, self::META_UNSUB, true ),
		);
	}

	/**
	 * Delete a lead permanently, and its submissions with it.
	 *
	 * The lead's submission history is deleted in the same operation, so no
	 * orphan submission rows are ever left behind.
	 *
	 * @param int $lead_id The lead.
	 * @return bool
	 */
	public static function delete_lead( $lead_id ) {
		$lead_id = (int) $lead_id;
		if ( self::POST_TYPE !== get_post_type( $lead_id ) ) {
			return false;
		}
		ILD_Submissions::delete_by_lead( $lead_id );
		return (bool) wp_delete_post( $lead_id, true );
	}

	/**
	 * Build WP_Query arguments from the admin filters.
	 *
	 * Shared by the list table and the CSV export, so both see the same set.
	 *
	 * @param array $filters { s, sync, date_from, date_to, paged, per_page, orderby, order }.
	 * @return array
	 */
	public static function query_args( $filters ) {
		$args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => isset( $filters['per_page'] ) ? (int) $filters['per_page'] : 20,
			'paged'          => isset( $filters['paged'] ) ? max( 1, (int) $filters['paged'] ) : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( isset( $filters['orderby'] ) && 'email' === $filters['orderby'] ) {
			$args['orderby'] = 'title';
		}
		if ( isset( $filters['order'] ) && 'asc' === strtolower( $filters['order'] ) ) {
			$args['order'] = 'ASC';
		}

		// Search by address (the email is the title).
		if ( ! empty( $filters['s'] ) ) {
			$args['s'] = $filters['s'];
		}

		// Filter by sync status.
		if ( ! empty( $filters['sync'] ) && in_array( $filters['sync'], array( self::SYNC_PENDING, self::SYNC_SYNCED, self::SYNC_FAILED ), true ) ) {
			$args['meta_query'] = array(
				array(
					'key'   => self::META_SYNC,
					'value' => $filters['sync'],
				),
			);
		}

		// Filter by capture date range (inclusive).
		$date_query = array();
		if ( ! empty( $filters['date_from'] ) ) {
			$date_query['after'] = $filters['date_from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['date_to'] ) ) {
			$date_query['before'] = $filters['date_to'] . ' 23:59:59';
		}
		if ( ! empty( $date_query ) ) {
			$date_query['inclusive'] = true;
			$args['date_query']      = array( $date_query );
		}

		return $args;
	}

	/**
	 * Find lead IDs for an exact email address.
	 *
	 * @param string $email The address.
	 * @return int[]
	 */
	public static function find_by_email( $email ) {
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Privacy: exporter and eraser
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Register the personal-data exporter.
	 *
	 * @param array $exporters The registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['ingredient-list-decoder-leads'] = array(
			'exporter_friendly_name' => __( 'Ingredient Decoder leads', 'ingredient-list-decoder' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the personal-data eraser.
	 *
	 * @param array $erasers The registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['ingredient-list-decoder-leads'] = array(
			'eraser_friendly_name' => __( 'Ingredient Decoder leads', 'ingredient-list-decoder' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export the leads for an email address, for a privacy request.
	 *
	 * @param string $email_address The address requested.
	 * @param int    $page          The page (all returned on page 1).
	 * @return array
	 */
	public static function export_personal_data( $email_address, $page = 1 ) {
		$export = array();

		foreach ( self::find_by_email( $email_address ) as $lead_id ) {
			$lead = self::get_lead( $lead_id );

			$export[] = array(
				'group_id'    => 'ild_leads',
				'group_label' => __( 'Ingredient Decoder leads', 'ingredient-list-decoder' ),
				'item_id'     => 'ild-lead-' . $lead_id,
				'data'        => array(
					array( 'name' => __( 'Email', 'ingredient-list-decoder' ), 'value' => $lead['email'] ),
					array( 'name' => __( 'Captured', 'ingredient-list-decoder' ), 'value' => $lead['captured'] ),
					array( 'name' => __( 'Consent', 'ingredient-list-decoder' ), 'value' => $lead['consent'] ),
					array( 'name' => __( 'Consent wording shown', 'ingredient-list-decoder' ), 'value' => $lead['consent_text'] ),
					array( 'name' => __( 'Source page', 'ingredient-list-decoder' ), 'value' => $lead['source'] ),
				),
			);

			// The lead's submission history, alongside the lead record.
			foreach ( ILD_Submissions::get_by_lead( $lead_id ) as $submission ) {
				$export[] = array(
					'group_id'    => 'ild_submissions',
					'group_label' => __( 'Ingredient Decoder submissions', 'ingredient-list-decoder' ),
					'item_id'     => 'ild-submission-' . $submission['id'],
					'data'        => array(
						array( 'name' => __( 'Date', 'ingredient-list-decoder' ), 'value' => $submission['captured'] ),
						array( 'name' => __( 'Product name', 'ingredient-list-decoder' ), 'value' => $submission['product'] ),
						array( 'name' => __( 'Ingredient list', 'ingredient-list-decoder' ), 'value' => $submission['list'] ),
					),
				);
			}
		}

		return array(
			'data' => $export,
			'done' => true,
		);
	}

	/**
	 * Erase the leads for an email address, for a privacy request.
	 *
	 * @param string $email_address The address requested.
	 * @param int    $page          The page (all handled on page 1).
	 * @return array
	 */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		$removed = 0;

		foreach ( self::find_by_email( $email_address ) as $lead_id ) {
			if ( self::delete_lead( $lead_id ) ) {
				$removed++;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
