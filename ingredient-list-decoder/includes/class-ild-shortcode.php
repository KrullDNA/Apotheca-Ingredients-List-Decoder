<?php
/**
 * The front-end tool: a shortcode and its AJAX endpoint.
 *
 * Registers [ingredient_decoder], which prints the form and an empty results
 * region. Submitting runs over AJAX with no page reload: the request is nonced,
 * the input is sanitised, the engine runs, and a rendered HTML fragment comes
 * back to drop into the page.
 *
 * There is no styling here on purpose. All markup lives in the templates folder
 * so Stage 7 can style it, and all wording lives in ILD_Phrases so it can be
 * edited in one place.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the shortcode, its assets and the AJAX handler.
 */
class ILD_Shortcode {

	/**
	 * The AJAX action name (also the nonce action).
	 *
	 * @var string
	 */
	const ACTION = 'ild_analyse';

	/**
	 * The script handle for the front-end JavaScript.
	 *
	 * @var string
	 */
	const SCRIPT = 'ild-frontend';

	/**
	 * Hook the shortcode, its assets and the AJAX endpoints onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// The endpoint is open to logged-out visitors, because the tool is public.
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax_analyse' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'ajax_analyse' ) );
	}

	/**
	 * Register the shortcode.
	 *
	 * @return void
	 */
	public function register_shortcode() {
		add_shortcode( 'ingredient_decoder', array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register (but do not yet load) the front-end script.
	 *
	 * It is only enqueued when the shortcode actually runs, so it never loads on
	 * pages without the tool. The AJAX URL is passed through; the nonce travels
	 * in the form itself.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_script(
			self::SCRIPT,
			ILD_PLUGIN_URL . 'assets/js/frontend.js',
			array(),
			ILD_VERSION,
			true
		);

		wp_localize_script(
			self::SCRIPT,
			'ILD_Frontend',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Render the shortcode: the form and an empty results region.
	 *
	 * @param array $atts Shortcode attributes (none used yet).
	 * @return string The tool's HTML.
	 */
	public function render_shortcode( $atts = array() ) {
		// Load the script now that we know the tool is on this page.
		wp_enqueue_script( self::SCRIPT );

		// A unique id so several tools on one page never collide.
		$uid = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ild-' ) : uniqid( 'ild-' );

		return self::render_template( 'tool', array( 'uid' => $uid ) );
	}

	/**
	 * The AJAX handler: read a list and return a rendered result fragment.
	 *
	 * Always answers with a rendered fragment (result, empty or error) so the
	 * script can simply drop it into the page. Never trusts the input: the nonce
	 * is checked, the honeypot is checked, and every field is sanitised.
	 *
	 * @return void
	 */
	public function ajax_analyse() {
		// The request must carry our valid nonce, and the honeypot must be empty.
		$nonce_ok = check_ajax_referer( self::ACTION, 'ild_nonce', false );
		$is_bot   = ! empty( $_POST['ild_hp'] );

		if ( ! $nonce_ok || $is_bot ) {
			wp_send_json_success(
				array(
					'html' => self::render_view(
						array(
							'state'   => 'error',
							'message' => ILD_Phrases::error_generic(),
						)
					),
				)
			);
		}

		// The pasted list, kept as multi-line text.
		$raw = isset( $_POST['ild_list'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ild_list'] ) ) : '';

		// The optional product name is captured for later stages (stored lists),
		// but it is deliberately never shown in the reading — no product mentions.
		$product_name = isset( $_POST['ild_product_name'] ) ? sanitize_text_field( wp_unslash( $_POST['ild_product_name'] ) ) : '';
		unset( $product_name );

		// Run the pipeline. A parser error (too long) comes back as a WP_Error.
		$match = ild_match_ingredient_list( $raw );
		if ( is_wp_error( $match ) ) {
			$view = ILD_Presenter::present( $match, null );
		} else {
			$analysis = ILD_Analysis::analyse( $match );
			$view     = ILD_Presenter::present( $match, $analysis );
		}

		wp_send_json_success( array( 'html' => self::render_view( $view ) ) );
	}

	/**
	 * Render the right template for a view model's state.
	 *
	 * @param array $view The view model from ILD_Presenter.
	 * @return string The rendered HTML fragment.
	 */
	private static function render_view( $view ) {
		switch ( isset( $view['state'] ) ? $view['state'] : 'error' ) {
			case 'result':
				return self::render_template( 'result', array( 'view' => $view ) );
			case 'empty':
				return self::render_template( 'empty', array( 'view' => $view ) );
			case 'error':
			default:
				return self::render_template( 'error', array( 'view' => $view ) );
		}
	}

	/**
	 * Render a template file with a set of variables, returning its output.
	 *
	 * @param string $name The template file name (without .php).
	 * @param array  $vars Variables to make available inside the template.
	 * @return string The rendered HTML.
	 */
	private static function render_template( $name, $vars = array() ) {
		$file = ILD_PLUGIN_DIR . 'templates/' . $name . '.php';
		if ( ! file_exists( $file ) ) {
			return '';
		}

		// Make the variables available to the template as named variables.
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Controlled, internal template variables only.

		ob_start();
		include $file;

		return ob_get_clean();
	}
}
