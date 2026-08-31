<?php
/**
 * What happens when the plugin is deleted (not merely deactivated).
 *
 * By default this removes nothing. The tool is built to hold a hand-written
 * ingredient library that took real effort, so losing it to a stray "Delete"
 * click would be a disaster. Data is only removed when the administrator has
 * explicitly ticked "Delete all plugin data when the plugin is deleted" on the
 * settings page.
 *
 * This file runs on its own, without the rest of the plugin loaded, so it uses
 * plain option keys and slugs rather than the plugin's class constants.
 *
 * @package IngredientListDecoder
 */

// Only ever run in the real uninstall context, never on a normal page load.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/*
 * The keys and slugs this file needs. These mirror the constants used
 * elsewhere in the plugin; they are repeated here because the plugin's classes
 * are not loaded during uninstall.
 */
$ild_option_key   = 'ild_settings';                 // The single settings row.
$ild_version_key  = 'ild_version';                  // The stored version marker.
$ild_post_type    = 'ild_ingredient';               // The ingredient post type.
$ild_taxonomies   = array( 'ild_family', 'ild_topic' ); // The two taxonomies.

// Read the settings and the opt-in flag. Default to "keep everything".
$ild_settings = get_option( $ild_option_key, array() );
$ild_delete   = is_array( $ild_settings ) && ! empty( $ild_settings['delete_data_on_uninstall'] );

// If the administrator did not opt in, leave every scrap of data in place.
if ( ! $ild_delete ) {
	return;
}

/*
 * The administrator opted in. Remove the plugin's data, in order:
 * ingredients first (which clears their meta and revisions), then the terms in
 * the two taxonomies, then the plugin's own options.
 */

// 1. Delete every ingredient entry, permanently (true = skip the trash).
$ild_ingredients = get_posts(
	array(
		'post_type'      => $ild_post_type,
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'suppress_filters' => true,
	)
);

foreach ( $ild_ingredients as $ild_ingredient_id ) {
	wp_delete_post( $ild_ingredient_id, true );
}

// 2. Delete every term in each of the plugin's taxonomies.
foreach ( $ild_taxonomies as $ild_taxonomy ) {
	$ild_terms = get_terms(
		array(
			'taxonomy'   => $ild_taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);

	// get_terms can return a WP_Error if the taxonomy is not registered; only
	// loop when we actually got a list back.
	if ( is_array( $ild_terms ) ) {
		foreach ( $ild_terms as $ild_term_id ) {
			wp_delete_term( $ild_term_id, $ild_taxonomy );
		}
	}
}

// 3. Delete the plugin's own options.
delete_option( $ild_option_key );
delete_option( $ild_version_key );
