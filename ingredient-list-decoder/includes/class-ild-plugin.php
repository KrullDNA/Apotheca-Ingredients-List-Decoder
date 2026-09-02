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
	 * The INCI key store and duplicate-prevention component.
	 *
	 * @var ILD_Ingredient_Keys
	 */
	private $ingredient_keys;

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
	 * The parser/matcher test screen component.
	 *
	 * @var ILD_Matcher
	 */
	private $matcher;

	/**
	 * The front-end shortcode and AJAX component.
	 *
	 * @var ILD_Shortcode
	 */
	private $shortcode;

	/**
	 * The photo transcription component.
	 *
	 * @var ILD_Transcription
	 */
	private $transcription;

	/**
	 * The AI drafting component (settings and the scheduled batch).
	 *
	 * @var ILD_AI_Drafter
	 */
	private $ai_drafter;

	/**
	 * The read-next block component (its cache-busting hooks).
	 *
	 * @var ILD_Read_Next
	 */
	private $read_next;

	/**
	 * The submissions store component.
	 *
	 * @var ILD_Submissions
	 */
	private $submissions;

	/**
	 * The lead store component.
	 *
	 * @var ILD_Leads
	 */
	private $leads;

	/**
	 * The leads admin screen component.
	 *
	 * @var ILD_Leads_Admin
	 */
	private $leads_admin;

	/**
	 * The unknown-ingredients admin screen component.
	 *
	 * @var ILD_Unknown_Admin
	 */
	private $unknown_admin;

	/**
	 * The result cache component.
	 *
	 * @var ILD_Cache
	 */
	private $cache;

	/**
	 * The rate-limit and daily-cap component.
	 *
	 * @var ILD_Rate_Limit
	 */
	private $rate_limit;

	/**
	 * The dashboard panel component.
	 *
	 * @var ILD_Dashboard
	 */
	private $dashboard;

	/**
	 * The connector queue/push manager.
	 *
	 * @var ILD_Connector_Manager
	 */
	private $connectors;

	/**
	 * The email gate component.
	 *
	 * @var ILD_Gate
	 */
	private $gate;

	/**
	 * The result email component.
	 *
	 * @var ILD_Email
	 */
	private $email;

	/**
	 * The Elementor widget integration.
	 *
	 * @var ILD_Elementor
	 */
	private $elementor;

	/**
	 * Build the plugin's components, ready to be hooked in.
	 */
	public function __construct() {
		$this->post_types  = new ILD_Post_Types();
		$this->meta_fields = new ILD_Meta_Fields();
		$this->ingredient_keys = new ILD_Ingredient_Keys();
		$this->settings    = new ILD_Settings();
		$this->csv         = new ILD_CSV();
		$this->library     = new ILD_Library();
		$this->matcher     = new ILD_Matcher();
		$this->shortcode     = new ILD_Shortcode();
		$this->transcription = new ILD_Transcription();
		$this->ai_drafter    = new ILD_AI_Drafter();
		$this->read_next     = new ILD_Read_Next();
		$this->submissions   = new ILD_Submissions();
		$this->leads         = new ILD_Leads();
		$this->leads_admin   = new ILD_Leads_Admin();
		$this->unknown_admin = new ILD_Unknown_Admin();
		$this->cache         = new ILD_Cache();
		$this->rate_limit    = new ILD_Rate_Limit();
		$this->dashboard     = new ILD_Dashboard();
		$this->connectors    = new ILD_Connector_Manager();
		$this->gate          = new ILD_Gate();
		$this->email         = new ILD_Email();
		$this->elementor     = new ILD_Elementor();
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
		$this->ingredient_keys->register_hooks();
		$this->settings->register_hooks();
		$this->csv->register_hooks();
		$this->library->register_hooks();
		$this->matcher->register_hooks();
		$this->shortcode->register_hooks();
		$this->transcription->register_hooks();
		$this->ai_drafter->register_hooks();
		$this->read_next->register_hooks();
		$this->submissions->register_hooks();
		$this->leads->register_hooks();
		$this->leads_admin->register_hooks();
		$this->unknown_admin->register_hooks();
		$this->cache->register_hooks();
		$this->rate_limit->register_hooks();
		$this->dashboard->register_hooks();
		$this->connectors->register_hooks();
		$this->gate->register_hooks();
		$this->email->register_hooks();

		// Keep the custom tables in step with the schema version.
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
		$this->elementor->register_hooks();
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

	/**
	 * Build or update the custom tables when the schema version changes.
	 *
	 * Runs on admin_init so a site that had the plugin before these tables
	 * existed gets them without needing to reactivate.
	 *
	 * @return void
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'ild_db_version' ) === ILD_DB_VERSION ) {
			return;
		}
		ILD_Submissions::install();
		ILD_Unknown_Tokens::install();
		ILD_Ingredient_Keys::install();
		update_option( 'ild_db_version', ILD_DB_VERSION );
	}
}
