<?php
/**
 * The branded result email: template engine, settings, preview and test send.
 *
 * Builds a table-based HTML email (for client compatibility) from the same
 * result view model the front end uses, inlines its CSS, and generates a plain
 * text alternative alongside. The breakdown is flattened — email cannot expand
 * and collapse — and every send carries a working unsubscribe link and the
 * privacy link.
 *
 * All of the look is driven from a Settings section: logo, widths, colours,
 * type, the button, the font stack, and the editable intro, sign-off and footer.
 * The admin gets a live preview and a test send to any address. Mail goes out
 * through wp_mail(), so it uses whatever transactional transport the site has
 * configured, never PHP mail() directly.
 *
 * The font stack is web-safe by design: the default is a genuinely usable chain
 * that needs no web fonts, and the template's sizing and spacing are set for it.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles, previews, tests and sends the result email.
 */
class ILD_Email {

	/**
	 * The AJAX action for the live preview.
	 *
	 * @var string
	 */
	const PREVIEW_ACTION = 'ild_email_preview';

	/**
	 * The AJAX action for the test send.
	 *
	 * @var string
	 */
	const TEST_ACTION = 'ild_email_test';

	/**
	 * The nonce action shared by the admin email tools.
	 *
	 * @var string
	 */
	const NONCE = 'ild_email_tools';

	/**
	 * The query var that triggers an unsubscribe.
	 *
	 * @var string
	 */
	const UNSUB_VAR = 'ild_unsubscribe';

	/**
	 * The plain-text body held between wp_mail() and phpmailer_init.
	 *
	 * @var string
	 */
	private $alt_body = '';

	/**
	 * Hook the settings, admin tools, endpoints and the unsubscribe handler.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'ild_register_settings', array( $this, 'register_settings_section' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'wp_ajax_' . self::PREVIEW_ACTION, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::TEST_ACTION, array( $this, 'ajax_test' ) );
		add_action( 'init', array( $this, 'maybe_handle_unsubscribe' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The email settings and their defaults.
	 *
	 * @return array<string,mixed> Setting key => default value.
	 */
	public static function defaults() {
		return array(
			'email_logo'           => '',
			'email_logo_width'     => 160,
			'email_container_width' => 600,
			'email_corner_radius'  => 8,
			'email_header_bg'      => '#1f3d2b',
			'email_body_bg'        => '#f0efe9',
			'email_container_bg'   => '#ffffff',
			'email_heading_colour' => '#1f3d2b',
			'email_body_colour'    => '#3a3a3a',
			'email_heading_size'   => 22,
			'email_body_size'      => 16,
			'email_line_height'    => 1.6,
			'email_link_colour'    => '#1f3d2b',
			'email_divider_colour' => '#e3e1d8',
			'email_button_bg'      => '#1f3d2b',
			'email_button_colour'  => '#ffffff',
			'email_button_radius'  => 4,
			'email_button_padding' => '12px 22px',
			'email_font_stack'     => '"Helvetica Neue", Helvetica, Arial, "Segoe UI", sans-serif',
			'email_intro'          => '',
			'email_signoff'        => '',
			'email_footer'         => '',
		);
	}

	/**
	 * Resolve one email setting, falling back to its default.
	 *
	 * @param string $key       The setting key.
	 * @param array  $overrides Optional live overrides (for the preview).
	 * @return mixed
	 */
	public static function get( $key, $overrides = array() ) {
		if ( is_array( $overrides ) && array_key_exists( $key, $overrides ) ) {
			return $overrides[ $key ];
		}
		$defaults = self::defaults();
		$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
		return ild_get_setting( $key, $default );
	}

	/**
	 * Register the Result email section on the settings page.
	 *
	 * @param ILD_Settings $settings The settings component.
	 * @return void
	 */
	public function register_settings_section( $settings ) {
		$settings->add_section(
			array(
				'id'          => 'ild_section_email_template',
				'title'       => __( 'Result email', 'ingredient-list-decoder' ),
				'description' => __( 'The branded HTML email that carries a reading. Fonts are web-safe by design — the fallback chain is what actually renders.', 'ingredient-list-decoder' ),
				'fields'      => array(
					array( 'id' => 'email_logo', 'label' => __( 'Logo', 'ingredient-list-decoder' ), 'type' => 'media', 'default' => '', 'description' => __( 'Shown in the header. A PNG on a transparent or matching background works best.', 'ingredient-list-decoder' ) ),
					array( 'id' => 'email_logo_width', 'label' => __( 'Logo max width (px)', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 160, 'min' => 40, 'max' => 400, 'sanitize' => 'absint' ),
					array( 'id' => 'email_container_width', 'label' => __( 'Container width (px)', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 600, 'min' => 320, 'max' => 800, 'sanitize' => 'absint' ),
					array( 'id' => 'email_corner_radius', 'label' => __( 'Corner radius (px)', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 8, 'min' => 0, 'max' => 40, 'sanitize' => 'absint' ),
					array( 'id' => 'email_header_bg', 'label' => __( 'Header background', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#1f3d2b' ),
					array( 'id' => 'email_body_bg', 'label' => __( 'Page background', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#f0efe9' ),
					array( 'id' => 'email_container_bg', 'label' => __( 'Container background', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#ffffff' ),
					array( 'id' => 'email_heading_colour', 'label' => __( 'Heading colour', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#1f3d2b' ),
					array( 'id' => 'email_body_colour', 'label' => __( 'Body colour', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#3a3a3a' ),
					array( 'id' => 'email_heading_size', 'label' => __( 'Heading size (px)', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 22, 'min' => 14, 'max' => 40, 'sanitize' => 'absint' ),
					array( 'id' => 'email_body_size', 'label' => __( 'Body size (px)', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 16, 'min' => 12, 'max' => 22, 'sanitize' => 'absint' ),
					array( 'id' => 'email_line_height', 'label' => __( 'Line height', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 1.6, 'min' => 1, 'max' => 2.5, 'step' => '0.1', 'sanitize' => array( __CLASS__, 'sanitize_float' ) ),
					array( 'id' => 'email_link_colour', 'label' => __( 'Link colour', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#1f3d2b' ),
					array( 'id' => 'email_divider_colour', 'label' => __( 'Divider colour', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#e3e1d8' ),
					array( 'id' => 'email_button_bg', 'label' => __( 'Button background', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#1f3d2b' ),
					array( 'id' => 'email_button_colour', 'label' => __( 'Button text colour', 'ingredient-list-decoder' ), 'type' => 'color', 'default' => '#ffffff' ),
					array( 'id' => 'email_button_radius', 'label' => __( 'Button radius (px)', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 4, 'min' => 0, 'max' => 40, 'sanitize' => 'absint' ),
					array( 'id' => 'email_button_padding', 'label' => __( 'Button padding', 'ingredient-list-decoder' ), 'type' => 'text', 'default' => '12px 22px', 'description' => __( 'CSS padding, e.g. "12px 22px".', 'ingredient-list-decoder' ), 'sanitize' => array( __CLASS__, 'sanitize_padding' ) ),
					array( 'id' => 'email_font_stack', 'label' => __( 'Font stack', 'ingredient-list-decoder' ), 'type' => 'text', 'default' => self::defaults()['email_font_stack'], 'description' => __( 'Web-safe fonts only. The chain must look right without any web font loading.', 'ingredient-list-decoder' ) ),
					array( 'id' => 'email_intro', 'label' => __( 'Intro text', 'ingredient-list-decoder' ), 'type' => 'textarea', 'default' => '', 'description' => __( 'Leave blank to use the default intro.', 'ingredient-list-decoder' ) ),
					array( 'id' => 'email_signoff', 'label' => __( 'Sign-off text', 'ingredient-list-decoder' ), 'type' => 'textarea', 'default' => '', 'description' => __( 'Leave blank to use the default sign-off.', 'ingredient-list-decoder' ) ),
					array( 'id' => 'email_footer', 'label' => __( 'Footer text', 'ingredient-list-decoder' ), 'type' => 'textarea', 'default' => '', 'description' => __( 'Shown above the privacy and unsubscribe links. Leave blank for the default.', 'ingredient-list-decoder' ) ),
					array( 'id' => 'email_tools', 'label' => __( 'Preview & test', 'ingredient-list-decoder' ), 'type' => 'callback', 'render' => array( $this, 'render_tools' ) ),
				),
			)
		);
	}

	/**
	 * Sanitise a line-height style float.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_float( $value ) {
		$n = (float) $value;
		$n = max( 1, min( 3, $n ) );
		return (string) $n;
	}

	/**
	 * Sanitise a CSS padding value down to digits, spaces and units.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_padding( $value ) {
		$value = preg_replace( '/[^0-9a-z%\s]/i', '', (string) $value );
		return trim( $value );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Building the email
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Build the full email: subject, HTML and plain text.
	 *
	 * @param array $view    The result view model (summary, ingredients, readnext).
	 * @param array $context {
	 *     @type int    $lead_id   The lead, for the unsubscribe link (0 for tests).
	 *     @type array  $overrides Live setting overrides, for the preview.
	 * }
	 * @return array { subject, html, text }.
	 */
	public function build( $view, $context = array() ) {
		$overrides = isset( $context['overrides'] ) && is_array( $context['overrides'] ) ? $context['overrides'] : array();
		$lead_id   = isset( $context['lead_id'] ) ? (int) $context['lead_id'] : 0;
		$product   = isset( $context['product'] ) ? trim( (string) $context['product'] ) : '';

		$opts = $this->options( $overrides, $lead_id );

		// The product name (if given) titles the email and the subject line.
		$opts['product'] = $product;
		$subject = ( '' !== $product ) ? ILD_Phrases::email_subject_for( $product ) : ILD_Phrases::email_subject_default();

		return array(
			'subject' => $subject,
			'html'    => $this->render_html( $view, $opts ),
			'text'    => $this->render_text( $view, $opts ),
		);
	}

	/**
	 * Assemble the resolved options the templates need.
	 *
	 * @param array $overrides Live overrides.
	 * @param int   $lead_id   The lead id for the unsubscribe link.
	 * @return array
	 */
	private function options( $overrides, $lead_id ) {
		$intro   = self::get( 'email_intro', $overrides );
		$signoff = self::get( 'email_signoff', $overrides );
		$footer  = self::get( 'email_footer', $overrides );

		return array(
			'style'            => $this->style_values( $overrides ),
			'logo'             => self::get( 'email_logo', $overrides ),
			'logo_width'       => (int) self::get( 'email_logo_width', $overrides ),
			'brand'            => get_bloginfo( 'name' ),
			'home_url'         => home_url( '/' ),
			'intro'            => '' !== $intro ? $intro : ILD_Phrases::email_intro_default(),
			'signoff'          => '' !== $signoff ? $signoff : ILD_Phrases::email_signoff_default(),
			'footer'           => '' !== $footer ? $footer : ILD_Phrases::email_footer_default(),
			'privacy_url'      => function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '',
			'privacy_label'    => ILD_Phrases::email_privacy(),
			'unsubscribe_url'  => self::unsubscribe_url( $lead_id ),
			'unsubscribe_label' => ILD_Phrases::email_unsubscribe(),
			'read_next_heading' => ILD_Phrases::email_read_next_heading(),
			'read_more_label'  => ILD_Phrases::email_read_more(),
		);
	}

	/**
	 * The resolved style values used by both the CSS and the templates.
	 *
	 * @param array $overrides Live overrides.
	 * @return array
	 */
	private function style_values( $overrides ) {
		return array(
			'container_width' => (int) self::get( 'email_container_width', $overrides ),
			'radius'          => (int) self::get( 'email_corner_radius', $overrides ),
			'header_bg'       => self::get( 'email_header_bg', $overrides ),
			'body_bg'         => self::get( 'email_body_bg', $overrides ),
			'container_bg'    => self::get( 'email_container_bg', $overrides ),
			'heading'         => self::get( 'email_heading_colour', $overrides ),
			'body'            => self::get( 'email_body_colour', $overrides ),
			'heading_size'    => (int) self::get( 'email_heading_size', $overrides ),
			'body_size'       => (int) self::get( 'email_body_size', $overrides ),
			'line_height'     => self::get( 'email_line_height', $overrides ),
			'link'            => self::get( 'email_link_colour', $overrides ),
			'divider'         => self::get( 'email_divider_colour', $overrides ),
			'button_bg'       => self::get( 'email_button_bg', $overrides ),
			'button_colour'   => self::get( 'email_button_colour', $overrides ),
			'button_radius'   => (int) self::get( 'email_button_radius', $overrides ),
			'button_padding'  => self::get( 'email_button_padding', $overrides ),
			'font'            => self::get( 'email_font_stack', $overrides ),
		);
	}

	/**
	 * The inlinable class rules, built from the resolved style.
	 *
	 * @param array $s The style values.
	 * @return string
	 */
	private function css_block( $s ) {
		$font = $s['font'];
		$body = 'font-family:' . $font . '; color:' . $s['body'] . '; font-size:' . $s['body_size'] . 'px; line-height:' . $s['line_height'] . ';';

		$rules = array(
			'ild-e-body'     => 'margin:0; padding:0; background-color:' . $s['body_bg'] . '; ' . $body,
			'ild-e-container' => 'background-color:' . $s['container_bg'] . '; border-radius:' . $s['radius'] . 'px; overflow:hidden;',
			'ild-e-header'   => 'background-color:' . $s['header_bg'] . '; padding:24px; text-align:center;',
			'ild-e-content'  => 'padding:28px 28px 8px 28px;',
			// The product title above the first section: the full heading size, but
			// medium weight so the section headings below read as the headings.
			'ild-e-h1'       => 'font-family:' . $font . '; color:' . $s['heading'] . '; font-size:' . $s['heading_size'] . 'px; font-weight:500; line-height:1.3; margin:22px 0 4px 0;',
			// Section headings ("How this formula is built", "Every ingredient…").
			'ild-e-h2'       => 'font-family:' . $font . '; color:' . $s['heading'] . '; font-size:' . ( $s['heading_size'] - 4 ) . 'px; font-weight:bold; line-height:1.3; margin:24px 0 10px 0;',
			'ild-e-p'        => $body . ' margin:0 0 14px 0;',
			'ild-e-intro'    => $body . ' margin:0 0 18px 0;',
			'ild-e-caveat'   => 'font-family:' . $font . '; color:' . $s['body'] . '; font-size:' . ( $s['body_size'] - 2 ) . 'px; line-height:1.5; margin:0 0 8px 0; opacity:0.85;',
			'ild-e-ing'      => 'padding:12px 0; border-top:1px solid ' . $s['divider'] . ';',
			'ild-e-ing-name' => 'font-family:' . $font . '; color:' . $s['heading'] . '; font-size:' . $s['body_size'] . 'px; font-weight:bold;',
			'ild-e-ing-meta' => 'font-family:' . $font . '; color:' . $s['body'] . '; font-size:' . ( $s['body_size'] - 3 ) . 'px;',
			'ild-e-ing-desc' => 'font-family:' . $font . '; color:' . $s['body'] . '; font-size:' . ( $s['body_size'] - 2 ) . 'px; line-height:1.5; margin:4px 0 0 0;',
			'ild-e-rn-card'  => 'padding:14px 0; border-top:1px solid ' . $s['divider'] . ';',
			'ild-e-rn-title' => 'font-family:' . $font . '; color:' . $s['heading'] . '; font-size:' . $s['body_size'] . 'px; font-weight:bold; text-decoration:none;',
			'ild-e-rn-excerpt' => 'font-family:' . $font . '; color:' . $s['body'] . '; font-size:' . ( $s['body_size'] - 2 ) . 'px; line-height:1.5; margin:4px 0 0 0;',
			'ild-e-button'   => 'display:inline-block; background-color:' . $s['button_bg'] . '; color:' . $s['button_colour'] . '; text-decoration:none; border-radius:' . $s['button_radius'] . 'px; padding:' . $s['button_padding'] . '; font-family:' . $font . '; font-size:' . ( $s['body_size'] - 1 ) . 'px; font-weight:bold;',
			'ild-e-link'     => 'color:' . $s['link'] . ';',
			'ild-e-footer'   => 'font-family:' . $font . '; color:' . $s['body'] . '; font-size:' . ( $s['body_size'] - 4 ) . 'px; line-height:1.6; padding:20px 28px; text-align:center; opacity:0.85;',
			'ild-e-footer-link' => 'color:' . $s['link'] . ';',
		);

		$css = '';
		foreach ( $rules as $class => $decls ) {
			$css .= '.' . $class . ' { ' . $decls . ' }' . "\n";
		}
		return $css;
	}

	/**
	 * The media-query CSS kept in the head for clients that honour it.
	 *
	 * @param array $s The style values.
	 * @return string
	 */
	private function media_block( $s ) {
		return '@media only screen and (max-width:600px){'
			. '.ild-e-container{width:100% !important;}'
			. '.ild-e-content{padding:20px !important;}'
			. '}';
	}

	/**
	 * Render the HTML email and inline its CSS.
	 *
	 * @param array $view The result view model.
	 * @param array $opts The resolved options.
	 * @return string
	 */
	private function render_html( $view, $opts ) {
		$css   = $this->css_block( $opts['style'] );
		$mq    = $this->media_block( $opts['style'] );
		$html  = $this->render_template( 'email/html', array( 'view' => $view, 'opts' => $opts, 'mq' => $mq ) );
		return ILD_Email_Inliner::inline( $html, $css );
	}

	/**
	 * Render the plain-text alternative from the view model directly.
	 *
	 * @param array $view The result view model.
	 * @param array $opts The resolved options.
	 * @return string
	 */
	private function render_text( $view, $opts ) {
		return $this->render_template( 'email/text', array( 'view' => $view, 'opts' => $opts ) );
	}

	/**
	 * Render one email template file to a string.
	 *
	 * @param string $name The template name under /templates.
	 * @param array  $vars The variables.
	 * @return string
	 */
	private function render_template( $name, $vars ) {
		$file = ILD_PLUGIN_DIR . 'templates/' . $name . '.php';
		if ( ! file_exists( $file ) ) {
			return '';
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Controlled template variables.
		ob_start();
		include $file;
		return ob_get_clean();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Sending
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Send the result email to an address.
	 *
	 * @param string $to      The recipient.
	 * @param array  $view    The result view model.
	 * @param array  $context { lead_id, overrides }.
	 * @return bool Whether wp_mail() accepted it.
	 */
	public function send_result( $to, $view, $context = array() ) {
		$to = sanitize_email( $to );
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$email = $this->build( $view, $context );

		$from_name    = (string) ild_get_setting( 'sender_name', get_bloginfo( 'name' ) );
		$from_address = (string) ild_get_setting( 'sender_address', get_bloginfo( 'admin_email' ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( is_email( $from_address ) ) {
			$headers[] = 'From: ' . $from_name . ' <' . $from_address . '>';
		}

		// Attach the plain-text alternative for the duration of this send only.
		$this->alt_body = $email['text'];
		add_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );
		$sent = wp_mail( $to, $email['subject'], $email['html'], $headers );
		remove_action( 'phpmailer_init', array( $this, 'set_alt_body' ) );
		$this->alt_body = '';

		return (bool) $sent;
	}

	/**
	 * Set the plain-text alternative on the mailer. Hooked only during a send.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer|object $phpmailer The mailer.
	 * @return void
	 */
	public function set_alt_body( $phpmailer ) {
		$phpmailer->AltBody = $this->alt_body;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Unsubscribe
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The unsubscribe URL for a lead (a signed, first-party link).
	 *
	 * @param int $lead_id The lead id (0 for a test send).
	 * @return string
	 */
	public static function unsubscribe_url( $lead_id ) {
		$lead_id = (int) $lead_id;
		$token   = $lead_id . '.' . self::unsub_hash( $lead_id );
		return add_query_arg( self::UNSUB_VAR, rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * The verification hash for an unsubscribe token.
	 *
	 * @param int $lead_id The lead id.
	 * @return string
	 */
	private static function unsub_hash( $lead_id ) {
		return substr( wp_hash( 'ild_unsub|' . (int) $lead_id ), 0, 20 );
	}

	/**
	 * Handle an unsubscribe request, if one is present and valid.
	 *
	 * @return void
	 */
	public function maybe_handle_unsubscribe() {
		if ( ! isset( $_GET[ self::UNSUB_VAR ] ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $_GET[ self::UNSUB_VAR ] ) );
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return;
		}

		$lead_id = (int) $parts[0];
		if ( ! hash_equals( self::unsub_hash( $lead_id ), $parts[1] ) ) {
			return;
		}

		// A real lead is marked unsubscribed; a test link (id 0) just confirms.
		if ( $lead_id > 0 ) {
			update_post_meta( $lead_id, '_ild_consent', 'no' );
			update_post_meta( $lead_id, '_ild_unsubscribed_gmt', current_time( 'mysql', true ) );
		}

		wp_die(
			esc_html( ILD_Phrases::email_unsubscribed_confirmation() ),
			esc_html__( 'Unsubscribed', 'ingredient-list-decoder' ),
			array( 'response' => 200 )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Admin: preview and test send
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Enqueue the admin tools on the settings page only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin( $hook ) {
		if ( false === strpos( (string) $hook, ILD_Settings::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_style( 'ild-admin-email', ILD_PLUGIN_URL . 'assets/css/admin-email.css', array(), ILD_VERSION );
		wp_enqueue_script( 'ild-admin-email', ILD_PLUGIN_URL . 'assets/js/admin-email.js', array( 'jquery', 'wp-color-picker' ), ILD_VERSION, true );

		wp_localize_script(
			'ild-admin-email',
			'ILD_EmailAdmin',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( self::NONCE ),
				'previewAction' => self::PREVIEW_ACTION,
				'testAction'    => self::TEST_ACTION,
				'chooseLogo'    => __( 'Choose logo', 'ingredient-list-decoder' ),
				'testSent'      => __( 'Test email sent.', 'ingredient-list-decoder' ),
				'testFailed'    => __( 'The test email could not be sent. Check the mail settings.', 'ingredient-list-decoder' ),
				'testInvalid'   => __( 'Please enter a valid email address.', 'ingredient-list-decoder' ),
			)
		);
	}

	/**
	 * Render the preview + test-send tools inside the settings section.
	 *
	 * @param array $field The field definition.
	 * @return void
	 */
	public function render_tools( $field ) {
		?>
		<div class="ild-email-tools">
			<p>
				<button type="button" class="button" id="ild-email-refresh"><?php esc_html_e( 'Refresh preview', 'ingredient-list-decoder' ); ?></button>
			</p>
			<iframe id="ild-email-preview" class="ild-email-preview" title="<?php esc_attr_e( 'Email preview', 'ingredient-list-decoder' ); ?>"></iframe>
			<p class="ild-email-test">
				<label for="ild-email-test-to"><?php esc_html_e( 'Send a test to:', 'ingredient-list-decoder' ); ?></label>
				<input type="email" id="ild-email-test-to" class="regular-text" placeholder="you@example.com" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" />
				<button type="button" class="button button-secondary" id="ild-email-test-send"><?php esc_html_e( 'Send test', 'ingredient-list-decoder' ); ?></button>
				<span class="ild-email-test-status" id="ild-email-test-status" role="status"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Read the posted setting overrides for the preview/test endpoints.
	 *
	 * Only known email keys are read, each through its own sanitiser, so nothing
	 * unexpected reaches the templates.
	 *
	 * @return array
	 */
	private function read_overrides() {
		$overrides = array();
		if ( empty( $_POST['settings'] ) || ! is_array( $_POST['settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by the caller.
			return $overrides;
		}
		$raw = wp_unslash( $_POST['settings'] ); // phpcs:ignore WordPress.Security -- Sanitised per-key below.

		foreach ( self::defaults() as $key => $default ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$value = $raw[ $key ];
			if ( in_array( $key, array( 'email_intro', 'email_signoff', 'email_footer' ), true ) ) {
				$overrides[ $key ] = sanitize_textarea_field( $value );
			} elseif ( 'email_font_stack' === $key || 'email_button_padding' === $key ) {
				$overrides[ $key ] = sanitize_text_field( $value );
			} elseif ( 0 === strpos( (string) $value, '#' ) || in_array( $key, array( 'email_header_bg', 'email_body_bg', 'email_container_bg', 'email_heading_colour', 'email_body_colour', 'email_link_colour', 'email_divider_colour', 'email_button_bg', 'email_button_colour' ), true ) ) {
				$overrides[ $key ] = sanitize_hex_color( $value );
			} elseif ( 'email_logo' === $key ) {
				$overrides[ $key ] = esc_url_raw( $value );
			} elseif ( 'email_line_height' === $key ) {
				$overrides[ $key ] = self::sanitize_float( $value );
			} else {
				$overrides[ $key ] = absint( $value );
			}
		}

		return $overrides;
	}

	/**
	 * The AJAX live preview: render the email HTML with the posted overrides.
	 *
	 * @return void
	 */
	public function ajax_preview() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$email = $this->build( self::sample_view(), array( 'overrides' => $this->read_overrides(), 'lead_id' => 0 ) );
		wp_send_json_success( array( 'html' => $email['html'] ) );
	}

	/**
	 * The AJAX test send.
	 *
	 * @return void
	 */
	public function ajax_test() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( '' === $to || ! is_email( $to ) ) {
			wp_send_json_error( array( 'code' => 'invalid' ) );
		}

		$sent = $this->send_result( $to, self::sample_view(), array( 'overrides' => $this->read_overrides(), 'lead_id' => 0 ) );
		if ( $sent ) {
			wp_send_json_success();
		}
		wp_send_json_error( array( 'code' => 'failed' ) );
	}

	/**
	 * A representative result view for the preview and the test send.
	 *
	 * @return array
	 */
	public static function sample_view() {
		return array(
			'state'          => 'result',
			'summary'        => array(
				array( 'text' => ILD_Phrases::summary_built_on( 'humectants', 'emollients' ), 'level' => 'high' ),
				array( 'text' => ILD_Phrases::summary_line_confirmed(), 'level' => 'medium' ),
			),
			'summary_caveat' => ILD_Phrases::summary_line_caveat(),
			'ingredients'    => array(
				array( 'kind' => 'matched', 'position' => 1, 'label' => 'Aqua', 'roles_text' => 'Solvent', 'family_text' => 'Solvents', 'description' => 'Water, the base most formulas are built on.', 'evidence' => '', 'founder' => '', 'status_text' => '' ),
				array( 'kind' => 'matched', 'position' => 2, 'label' => 'Glycerin', 'roles_text' => 'Humectant', 'family_text' => 'Humectants', 'description' => 'Draws water into the upper layers of the skin.', 'evidence' => '', 'founder' => '', 'status_text' => '' ),
				array( 'kind' => 'suggestion', 'position' => 3, 'label' => 'Niacinimide', 'status_text' => ILD_Phrases::did_you_mean( 'Niacinamide' ) ),
				array( 'kind' => 'unknown', 'position' => 4, 'label' => 'Bakuchiol', 'status_text' => ILD_Phrases::not_in_library() ),
			),
			'readnext'       => array(
				array( 'id' => 0, 'title' => 'What niacinamide actually does', 'url' => home_url( '/' ), 'excerpt' => 'A closer look at a quietly capable active.', 'thumb' => '', 'meta' => '' ),
			),
			'counts'         => array( 'total' => 4, 'matched' => 2 ),
		);
	}
}
