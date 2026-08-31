<?php
/**
 * What happens the moment the plugin is switched on.
 *
 * Activation is a one-off: it makes sure the data structures exist and seeds the
 * two taxonomies with their starting terms, then flushes the rewrite rules so
 * WordPress notices the new post type straight away. It never deletes anything.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the one-off setup when the plugin is activated.
 */
class ILD_Activator {

	/**
	 * The activation routine.
	 *
	 * Registers the post type and taxonomies for this request (so the terms can
	 * be attached to real taxonomies), seeds the default terms, records the
	 * plugin version, then flushes rewrite rules.
	 *
	 * @return void
	 */
	public static function activate() {
		// Build a post-types instance and register everything now, so the
		// taxonomies exist before we try to seed terms into them.
		$post_types = new ILD_Post_Types();
		$post_types->register_taxonomies();
		$post_types->register_post_type();
		$post_types->register_statuses();

		// Seed the starting terms for both taxonomies.
		self::seed_terms();

		// Remember which version first set things up (useful for upgrades later).
		if ( false === get_option( 'ild_version' ) ) {
			add_option( 'ild_version', ILD_VERSION );
		} else {
			update_option( 'ild_version', ILD_VERSION );
		}

		// Refresh permalinks so the new post type is recognised immediately.
		flush_rewrite_rules();
	}

	/**
	 * Seed the default terms for both taxonomies.
	 *
	 * Adds each term only if it is not already there, so re-activating the
	 * plugin never creates duplicates and never overwrites edits. The term lists
	 * live on ILD_Post_Types, beside the taxonomies they belong to.
	 *
	 * @return void
	 */
	private static function seed_terms() {
		// Ingredient Family terms.
		foreach ( ILD_Post_Types::get_default_family_terms() as $term_name ) {
			if ( ! term_exists( $term_name, ILD_Post_Types::TAX_FAMILY ) ) {
				wp_insert_term( $term_name, ILD_Post_Types::TAX_FAMILY );
			}
		}

		// Skin Topic terms.
		foreach ( ILD_Post_Types::get_default_topic_terms() as $term_name ) {
			if ( ! term_exists( $term_name, ILD_Post_Types::TAX_TOPIC ) ) {
				wp_insert_term( $term_name, ILD_Post_Types::TAX_TOPIC );
			}
		}
	}
}
