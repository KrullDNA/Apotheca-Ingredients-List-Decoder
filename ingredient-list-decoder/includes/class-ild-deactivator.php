<?php
/**
 * What happens the moment the plugin is switched off.
 *
 * Deactivation is deliberately gentle: it only tidies the rewrite rules. It
 * leaves every ingredient, term and setting exactly where it is, so switching
 * the plugin off and on again loses nothing. Removing data is a separate,
 * opt-in step handled only by uninstall.php.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the tidy-up when the plugin is deactivated.
 */
class ILD_Deactivator {

	/**
	 * The deactivation routine.
	 *
	 * The post type is no longer registered once the plugin is off, so we clear
	 * the rewrite rules that referred to it. No data is touched.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
