<?php
/**
 * Plugin Name:       Ingredient List Decoder
 * Plugin URI:        https://apotheca.com.au/
 * Description:        Reads a skincare ingredient list as a whole and explains how the formula is built. Stage 1: foundation, data layer and the settings framework every later stage registers into.
 * Version:           1.6.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            KDNA for Apotheca
 * Author URI:        https://apotheca.com.au/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ingredient-list-decoder
 * Domain Path:       /languages
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly in a browser.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Constants
 *
 * Everything the plugin needs to know about where it lives and which version
 * it is. Defining these once, up front, means no other file has to guess a
 * path or repeat the text domain.
 * ---------------------------------------------------------------------------
 */

// The plugin's own version number. Bumped each release; used to bust caches later.
define( 'ILD_VERSION', '1.6.6' );

// The custom-table schema version. Bumped when a table's structure changes, so
// the tables are (re)built via dbDelta on the next admin request.
define( 'ILD_DB_VERSION', '2' );

// The absolute path to this main plugin file.
define( 'ILD_PLUGIN_FILE', __FILE__ );

// The folder this plugin lives in, with a trailing slash (for require statements).
define( 'ILD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// The public URL to this plugin's folder, with a trailing slash (for assets later).
define( 'ILD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// The "folder/file.php" identifier WordPress uses for this plugin.
define( 'ILD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// The text domain, kept in a constant so it can never be mistyped elsewhere.
define( 'ILD_TEXT_DOMAIN', 'ingredient-list-decoder' );

/*
 * ---------------------------------------------------------------------------
 * Include the building blocks
 *
 * Each class has one job. The main file stays lean: it wires things together
 * and gets out of the way. All real logic lives in the includes below.
 * ---------------------------------------------------------------------------
 */
require_once ILD_PLUGIN_DIR . 'includes/helpers.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-roles.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-post-types.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-meta-fields.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-ingredient-keys.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-settings.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-csv.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-library.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-parser.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-matcher.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-analysis.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-phrases.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-presenter.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-read-next.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-cache.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-rate-limit.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-submissions.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-unknown-tokens.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-ai-drafter.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-leads.php';
require_once ILD_PLUGIN_DIR . 'includes/interface-ild-email-connector.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-connector-manager.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-leads-admin.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-unknown-admin.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-dashboard.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-shortcode.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-gate.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-email-inliner.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-email.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-transcription.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-elementor.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-activator.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-deactivator.php';
require_once ILD_PLUGIN_DIR . 'includes/class-ild-plugin.php';

/*
 * ---------------------------------------------------------------------------
 * Activation and deactivation
 *
 * These run once, at the moment the plugin is switched on or off. Activation
 * registers the data structures and then seeds the taxonomy terms; both hooks
 * flush the rewrite rules so WordPress knows about the changes immediately.
 * ---------------------------------------------------------------------------
 */

// Run the one-off setup when the plugin is activated.
register_activation_hook( __FILE__, array( 'ILD_Activator', 'activate' ) );

// Tidy up rewrite rules when the plugin is deactivated (data is left untouched).
register_deactivation_hook( __FILE__, array( 'ILD_Deactivator', 'deactivate' ) );

/*
 * ---------------------------------------------------------------------------
 * Start the plugin
 *
 * Hand control to the loader class, which hooks every component onto WordPress
 * at the right moment. One shared instance, created on plugins_loaded.
 * ---------------------------------------------------------------------------
 */

/**
 * Boot the plugin.
 *
 * Creates the single ILD_Plugin instance and lets it register its hooks. Held
 * behind a function so nothing runs until WordPress is ready for it.
 *
 * @return ILD_Plugin The running plugin instance.
 */
function ild_run_plugin() {
	static $plugin = null;

	// Only ever build one instance, however many times this is called.
	if ( null === $plugin ) {
		$plugin = new ILD_Plugin();
		$plugin->run();
	}

	return $plugin;
}
add_action( 'plugins_loaded', 'ild_run_plugin' );
