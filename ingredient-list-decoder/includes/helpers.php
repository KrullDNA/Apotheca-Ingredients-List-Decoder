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
