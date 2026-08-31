<?php
/**
 * The submissions store: the ingredient lists people have decoded.
 *
 * Each submission records the pasted list, an optional product name, the source
 * page, when it happened, and — when it came through the gate — the lead it
 * belongs to. This is the store the leads screen reads a person's history from,
 * by lead ID.
 *
 * A later stage builds out the rest of this store (the unknown-ingredient queue
 * and the AI drafting over anonymous submissions); this stage provides the
 * storage, the per-lead lookup, and the per-lead delete that keeps the two in
 * step. A submission created without a lead (lead_id 0) is simply anonymous.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and writes the submissions store, and reads it by lead.
 */
class ILD_Submissions {

	/**
	 * The submission post type.
	 *
	 * @var string
	 */
	const POST_TYPE = 'ild_submission';

	// Meta keys.
	const META_LEAD     = '_ild_lead_id';
	const META_PRODUCT  = '_ild_product';
	const META_LIST     = '_ild_list';
	const META_SOURCE   = '_ild_source';
	const META_CAPTURED = '_ild_captured_gmt';

	/**
	 * Hook the post-type registration.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the private submission post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Submissions', 'ingredient-list-decoder' ),
					'singular_name' => __( 'Submission', 'ingredient-list-decoder' ),
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
	 * Store one submission.
	 *
	 * @param array $data {
	 *     @type int    $lead_id The lead it belongs to, or 0 for anonymous.
	 *     @type string $list    The pasted ingredient list.
	 *     @type string $product An optional product name.
	 *     @type string $source  The source page URL.
	 * }
	 * @return int|WP_Error The submission ID, or an error.
	 */
	public static function store( $data ) {
		$product = isset( $data['product'] ) ? sanitize_text_field( $data['product'] ) : '';
		$title   = '' !== $product ? $product : __( 'Submission', 'ingredient-list-decoder' );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_LEAD, isset( $data['lead_id'] ) ? (int) $data['lead_id'] : 0 );
		update_post_meta( $post_id, self::META_PRODUCT, $product );
		update_post_meta( $post_id, self::META_LIST, isset( $data['list'] ) ? sanitize_textarea_field( $data['list'] ) : '' );
		update_post_meta( $post_id, self::META_SOURCE, isset( $data['source'] ) ? esc_url_raw( $data['source'] ) : '' );
		update_post_meta( $post_id, self::META_CAPTURED, current_time( 'mysql', true ) );

		return $post_id;
	}

	/**
	 * A lead's submission history, newest first.
	 *
	 * @param int $lead_id The lead.
	 * @return array<int,array> Each: { id, captured, product, list, source }.
	 */
	public static function get_by_lead( $lead_id ) {
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'meta_key'       => self::META_LEAD, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $lead_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array(
				'id'       => (int) $id,
				'captured' => (string) get_post_meta( $id, self::META_CAPTURED, true ),
				'product'  => (string) get_post_meta( $id, self::META_PRODUCT, true ),
				'list'     => (string) get_post_meta( $id, self::META_LIST, true ),
				'source'   => (string) get_post_meta( $id, self::META_SOURCE, true ),
			);
		}

		return $out;
	}

	/**
	 * Delete every submission belonging to a lead.
	 *
	 * Called when a lead is deleted, so no orphan submissions are left behind.
	 *
	 * @param int $lead_id The lead.
	 * @return int The number deleted.
	 */
	public static function delete_by_lead( $lead_id ) {
		$lead_id = (int) $lead_id;
		if ( $lead_id <= 0 ) {
			return 0;
		}

		$deleted = 0;
		foreach ( self::get_by_lead( $lead_id ) as $submission ) {
			if ( wp_delete_post( $submission['id'], true ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}
}
