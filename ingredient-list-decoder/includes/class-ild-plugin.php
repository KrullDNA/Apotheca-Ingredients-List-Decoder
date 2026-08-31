<?php
/**
 * The loader that ties the plugin together.
 *
 * The main file hands control here. This class holds one instance of each
 * component and lets each one hook itself onto WordPress at the right moment.
 * As later stages add components, they get created and run() here too, so the
 * main plugin file never grows.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every plugin component onto WordPress.
 */
class ILD_Plugin {

	/**
	 * The post types, taxonomies and statuses component.
	 *
	 * @var ILD_Post_Types
	 */
	private $post_types;

	/**
	 * The ingredient meta fields component.
	 *
	 * @var ILD_Meta_Fields
	 */
	private $meta_fields;

	/**
	 * The settings page component.
	 *
	 * @var ILD_Settings
	 */
	private $settings;

	/**
	 * The CSV import/export component.
	 *
	 * @var ILD_CSV
	 */
	private $csv;

	/**
	 * The ingredient library admin screens component.
	 *
	 * @var ILD_Library
	 */
	private $library;

	/**
	 * Build the plugin's components, ready to be hooked in.
	 */
	public function __construct() {
		$this->post_types  = new ILD_Post_Types();
		$this->meta_fields = new ILD_Meta_Fields();
		$this->settings    = new ILD_Settings();
		$this->csv         = new ILD_CSV();
		$this->library     = new ILD_Library();
	}

	/**
	 * Start everything.
	 *
	 * Loads translations, then lets each component register its own hooks.
	 * Called once, from ild_run_plugin() on plugins_loaded.
	 *
	 * @return void
	 */
	public function run() {
		// Load the translation files first, so every label can be localised.
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		// Let each component wire itself onto WordPress.
		$this->post_types->register_hooks();
		$this->meta_fields->register_hooks();
		$this->settings->register_hooks();
		$this->csv->register_hooks();
		$this->library->register_hooks();
	}

	/**
	 * Load the plugin's translation files.
	 *
	 * Makes every string wrapped in the translation functions available for
	 * localisation via a .mo file in the /languages folder.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'ingredient-list-decoder',
			false,
			dirname( ILD_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
