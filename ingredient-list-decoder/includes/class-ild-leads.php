<?php
/**
 * Lead storage: the email addresses captured by the gate.
 *
 * A lead is stored as one private `ild_lead` entry, holding the address, the
 * moment it was captured, the consent state, the exact consent wording shown at
 * that moment, and the page it came from. Keeping the wording verbatim matters:
 * it is the record of what the person actually agreed to.
 *
 * The admin screens for these come in a later stage; this stage only registers
 * the store and writes to it. The post type is private and not publicly
 * queryable — a lead is a record, never a page.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the lead store and writes leads to it.
 */
class ILD_Leads {

	/**
	 * The lead post type.
	 *
	 * @var string
	 */
	const POST_TYPE = 'ild_lead';

	/**
	 * Hook the post-type registration onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the private lead post type.
	 *
	 * It is deliberately not public and not queryable: leads never appear on the
	 * front end. A later stage adds the admin UI; for now show_ui is off.
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

		update_post_meta( $post_id, '_ild_email', $email );
		update_post_meta( $post_id, '_ild_consent', ! empty( $data['consent'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_ild_consent_text', isset( $data['consent_text'] ) ? sanitize_textarea_field( $data['consent_text'] ) : '' );
		update_post_meta( $post_id, '_ild_source', isset( $data['source'] ) ? esc_url_raw( $data['source'] ) : '' );
		// The capture time. The post date already records this, but a stored GMT
		// timestamp keeps it explicit and independent of any later re-save.
		update_post_meta( $post_id, '_ild_captured_gmt', current_time( 'mysql', true ) );

		return $post_id;
	}
}
