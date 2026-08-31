<?php
/**
 * Plugin Name:       Ingredient List Decoder — Campaign Monitor
 * Plugin URI:        https://apotheca.com.au/
 * Description:        Pushes consented Ingredient List Decoder leads to a Campaign Monitor list. An add-on for the Ingredient List Decoder core plugin.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            KDNA for Apotheca
 * Author URI:        https://apotheca.com.au/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ild-campaign-monitor
 *
 * @package IngredientListDecoder\CampaignMonitor
 */

// Stop anyone loading this file directly in a browser.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ILDCM_VERSION', '1.0.0' );
define( 'ILDCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ILDCM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Boot the add-on, but only when the core plugin is present.
 *
 * The connector class implements the core's interface, so it can only be loaded
 * once that interface exists. Runs late on plugins_loaded, after the core has
 * defined it. Without the core, the add-on stays inert and shows a notice.
 *
 * @return void
 */
function ildcm_boot() {
	if ( ! interface_exists( 'ILD_Email_Connector' ) ) {
		add_action( 'admin_notices', 'ildcm_missing_core_notice' );
		return;
	}

	require_once ILDCM_DIR . 'includes/class-ildcm-connector.php';
	require_once ILDCM_DIR . 'includes/class-ildcm-admin.php';

	// Register the connector with the core's queue.
	add_filter( 'ild_email_connectors', 'ildcm_register_connector' );

	// Wire up the settings and the test-connection tool.
	$admin = new ILDCM_Admin();
	$admin->register_hooks();
}
add_action( 'plugins_loaded', 'ildcm_boot', 20 );

/**
 * Add the Campaign Monitor connector to the core's list.
 *
 * @param array $connectors The registered connectors.
 * @return array
 */
function ildcm_register_connector( $connectors ) {
	$connectors[] = new ILDCM_Connector();
	return $connectors;
}

/**
 * Warn that the core plugin is required.
 *
 * @return void
 */
function ildcm_missing_core_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Ingredient List Decoder — Campaign Monitor needs the Ingredient List Decoder core plugin to be active.', 'ild-campaign-monitor' );
	echo '</p></div>';
}
