<?php
/**
 * Small shared helper functions used across the plugin.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read a single saved setting value.
 *
 * All of the plugin's settings live inside one options row (an associative
 * array) so the database stays tidy and later stages just add keys. This
 * helper hides that detail: callers ask for a key and get its value, or the
 * fallback if it has never been saved.
 *
 * @param string $key     The setting key, for example 'sender_name'.
 * @param mixed  $default What to return when the key has no saved value.
 * @return mixed The saved value, or the supplied default.
 */
function ild_get_setting( $key, $default = '' ) {
	// Pull the whole settings array (defaults to an empty array if unset).
	$settings = get_option( ILD_Settings::OPTION_KEY, array() );

	// Hand back the requested key when we have it, otherwise the default.
	if ( is_array( $settings ) && array_key_exists( $key, $settings ) ) {
		return $settings[ $key ];
	}

	return $default;
}

/**
 * Find an existing ingredient by its exact INCI name (its post title).
 *
 * The whole library is keyed on the INCI name, so several places need to ask
 * "is there already an entry called this?" — the CSV importer (to update rather
 * than duplicate), the list screen, and the duplicate guard on save. Keeping the
 * lookup here means they all match the same way.
 *
 * The match is case-insensitive because MySQL's default collation treats
 * "Glycerin" and "glycerin" as the same string, which is exactly what stops two
 * near-identical entries being created. Trashed entries are ignored so a
 * deleted-but-not-purged entry never blocks a fresh one.
 *
 * @param string $title      The INCI name to look for.
 * @param int    $exclude_id An entry ID to ignore (so an entry never clashes
 *                           with itself when it is being edited). 0 = none.
 * @return int The matching ingredient's ID, or 0 when there is none.
 */
function ild_find_ingredient_by_title( $title, $exclude_id = 0 ) {
	global $wpdb;

	// A blank title can never match a real entry.
	$title = trim( (string) $title );
	if ( '' === $title ) {
		return 0;
	}

	$exclude_id = (int) $exclude_id;

	// A direct, prepared lookup by title and type, skipping the trash and the
	// entry we were told to ignore.
	$id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' AND ID != %d AND post_title = %s ORDER BY ID ASC LIMIT 1",
			ILD_Post_Types::POST_TYPE,
			$exclude_id,
			$title
		)
	);

	return $id ? (int) $id : 0;
}

/**
 * Parse a raw pasted ingredient list into ordered, cleaned tokens.
 *
 * A thin public wrapper around ILD_Parser::parse(), so callers (and later
 * stages) can reach the parser as a plain function without knowing the class.
 *
 * @param string $raw The pasted ingredient list.
 * @return array|WP_Error The ordered tokens, or an error if the input is too long.
 */
function ild_parse_ingredient_list( $raw ) {
	return ILD_Parser::parse( $raw );
}

/**
 * Parse and match a raw pasted ingredient list against the library.
 *
 * The main entry point for the engine: hand it raw pasted text and get back a
 * structured, order-preserving result of matched ingredients, suggestions and
 * unmatched tokens. A thin public wrapper around ILD_Matcher::match().
 *
 * @param string $raw The pasted ingredient list.
 * @return array|WP_Error The structured match result, or an error if the input
 *                        is too long.
 */
function ild_match_ingredient_list( $raw ) {
	return ILD_Matcher::match( $raw );
}
