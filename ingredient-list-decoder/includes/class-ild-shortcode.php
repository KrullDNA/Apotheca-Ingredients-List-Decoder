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
	 * The style handle for the front-end CSS.
	 *
	 * Shared with the Elementor widget, which lists it as a style dependency so
	 * the same base styles load whether the tool is placed as a shortcode or a
	 * widget.
	 *
	 * @var string
	 */
	const STYLE = 'ild-frontend';

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
		wp_register_style(
			self::STYLE,
			ILD_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			ILD_VERSION
		);

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
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'charCount'       => ILD_Phrases::char_count_template(),
				// Photo transcription (Stage 8).
				'transcribeAction' => ILD_Transcription::ACTION,
				'photoEnabled'    => ILD_Transcription::is_enabled(),
				'maxImageBytes'   => ILD_Transcription::max_bytes(),
				'maxImageDim'     => (int) apply_filters( 'ild_image_max_dimension', 1800 ),
				'heicUrl'         => (string) apply_filters( 'ild_heic_converter_url', 'https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js' ),
				'photoMessages'   => ILD_Phrases::photo_messages(),
				'photoReading'    => ILD_Phrases::photo_reading(),
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
		return self::render_tool();
	}

	/**
	 * Render the tool shell, enqueuing its assets first.
	 *
	 * Shared by the shortcode and the Elementor widget so there is one source of
	 * the markup. The widget passes its own wrapper classes (max width, motion,
	 * print, loading style) and, in the editor only, a preview state to show.
	 *
	 * @param array $args {
	 *     Optional. Rendering options.
	 *
	 *     @type string $class   Extra classes to add to the .ild-tool wrapper.
	 *     @type string $preview A state to show in place (editor preview only):
	 *                           one of 'form', 'loading', 'empty', 'error',
	 *                           'result'. Empty for the normal, interactive tool.
	 * }
	 * @return string The tool's HTML.
	 */
	public static function render_tool( $args = array() ) {
		$args = array_merge(
			array(
				'class'       => '',
				'preview'     => '',
				'submit_icon' => '',
				// null = decide from settings; the widget may force true/false.
				'show_photo'  => null,
			),
			$args
		);

		// Show the photo control when transcription is configured, unless the
		// caller (the widget) has made an explicit choice. The editor preview
		// always shows it so it can be styled.
		if ( null === $args['show_photo'] ) {
			$args['show_photo'] = ILD_Transcription::is_enabled();
		}
		if ( in_array( $args['preview'], array( 'verify' ), true ) ) {
			$args['show_photo'] = true;
		}

		// Load the assets now that we know the tool is on this page.
		wp_enqueue_style( self::STYLE );
		wp_enqueue_script( self::SCRIPT );

		// A unique id so several tools on one page never collide.
		$uid = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ild-' ) : uniqid( 'ild-' );

		// In the editor, a chosen preview state is rendered in place so it can be
		// styled without triggering it. Never set on the front end.
		$preview_html = '';
		if ( in_array( $args['preview'], array( 'empty', 'error', 'result' ), true ) ) {
			$preview_html = self::render_state_preview( $args['preview'] );
		}

		return self::render_template(
			'tool',
			array(
				'uid'          => $uid,
				'extra_class'  => $args['class'],
				'preview'      => $args['preview'],
				'preview_html' => $preview_html,
				'submit_icon'  => $args['submit_icon'],
				'show_photo'   => (bool) $args['show_photo'],
			)
		);
	}

	/**
	 * Build a rendered fragment for one state, for the editor preview only.
	 *
	 * @param string $state 'empty', 'error' or 'result'.
	 * @return string The rendered fragment, or ''.
	 */
	public static function render_state_preview( $state ) {
		switch ( $state ) {
			case 'empty':
				return self::render_view(
					array(
						'state'   => 'empty',
						'message' => ILD_Phrases::empty_no_tokens(),
					)
				);
			case 'error':
				return self::render_view(
					array(
						'state'   => 'error',
						'message' => ILD_Phrases::error_generic(),
					)
				);
			case 'result':
				return self::render_view( self::sample_result_view() );
			default:
				return '';
		}
	}

	/**
	 * A hand-built, representative result view for the editor preview.
	 *
	 * It never touches the database, so it is safe to render in the editor. It
	 * shows every row kind and a confidence-tagged summary, so a designer can
	 * style the whole result without pasting a real list.
	 *
	 * @return array A result view model.
	 */
	private static function sample_result_view() {
		return array(
			'state'          => 'result',
			'summary'        => array(
				array( 'text' => ILD_Phrases::summary_built_on( 'humectants', 'emollients' ), 'level' => 'high' ),
				array( 'text' => ILD_Phrases::summary_line_confirmed(), 'level' => 'medium' ),
				array( 'text' => ILD_Phrases::summary_shape_short(), 'level' => 'low' ),
			),
			'summary_caveat' => ILD_Phrases::summary_line_caveat(),
			'ingredients'    => array(
				array(
					'kind'        => 'matched',
					'position'    => 1,
					'label'       => 'Glycerin',
					'roles_text'  => 'Humectant',
					'family_text' => 'Humectants',
					'description' => __( 'A humectant that draws water into the upper layers of the skin.', 'ingredient-list-decoder' ),
					'evidence'    => __( 'Well supported at typical use levels.', 'ingredient-list-decoder' ),
					'founder'     => __( 'A quiet workhorse we reach for often.', 'ingredient-list-decoder' ),
					'status_text' => '',
				),
				array(
					'kind'        => 'suggestion',
					'position'    => 2,
					'label'       => 'Niacinimide',
					'status_text' => ILD_Phrases::did_you_mean( 'Niacinamide' ),
				),
				array(
					'kind'        => 'unknown',
					'position'    => 3,
					'label'       => 'Bakuchiol',
					'status_text' => ILD_Phrases::not_in_library(),
				),
				array(
					'kind'        => 'unreadable',
					'position'    => 4,
					'label'       => 'xqzzt',
					'status_text' => ILD_Phrases::unreadable(),
				),
			),
			'counts'         => array( 'total' => 4, 'matched' => 1 ),
		);
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
