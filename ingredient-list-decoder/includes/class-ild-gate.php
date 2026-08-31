<?php
/**
 * The email gate: hold the breakdown behind an email and consent.
 *
 * The summary is shown immediately; the ingredient breakdown and the read-next
 * block are gated. This class answers the gate submission: it checks the nonce
 * and honeypot, requires a valid email and a ticked consent box, stores the lead
 * (with the exact consent wording shown), sets a first-party cookie so the gate
 * is skipped on that device afterwards, and then returns the breakdown by
 * re-running the analysis on the carried list.
 *
 * The cookie duration is the plugin's existing "cookie duration" setting,
 * defaulting to twelve months.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the gate cookie and the gate submission.
 */
class ILD_Gate {

	/**
	 * The AJAX action name (also the nonce action).
	 *
	 * @var string
	 */
	const ACTION = 'ild_gate';

	/**
	 * The nonce field name.
	 *
	 * @var string
	 */
	const NONCE = 'ild_gate_nonce';

	/**
	 * The first-party cookie that records access.
	 *
	 * @var string
	 */
	const COOKIE = 'ild_result_access';

	/**
	 * Hook the gate submission endpoint onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax_submit' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'ajax_submit' ) );
	}

	/**
	 * Whether this device already has access (the cookie is present).
	 *
	 * @return bool
	 */
	public static function has_access() {
		return ! empty( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * The gate cookie duration in months, from settings (default twelve).
	 *
	 * @return int
	 */
	public static function cookie_months() {
		$months = (int) ild_get_setting( 'cookie_duration', 12 );
		return max( 1, min( 120, $months ) );
	}

	/**
	 * Handle the gate submission: validate, store, set the cookie, return breakdown.
	 *
	 * @return void
	 */
	public function ajax_submit() {
		// Nonce and honeypot.
		if ( ! check_ajax_referer( self::ACTION, self::NONCE, false ) ) {
			$this->fail( 'network' );
		}
		if ( ! empty( $_POST['ild_gate_hp'] ) ) {
			$this->fail( 'network' );
		}

		// A valid email is required.
		$email = isset( $_POST['ild_email'] ) ? sanitize_email( wp_unslash( $_POST['ild_email'] ) ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			$this->fail( 'invalid_email' );
		}

		// Consent must be given.
		$consent = ! empty( $_POST['ild_consent'] );
		if ( ! $consent ) {
			$this->fail( 'no_consent' );
		}

		// The exact wording shown, the source page, and the list to re-run.
		$consent_text = isset( $_POST['ild_consent_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ild_consent_text'] ) ) : '';
		$source       = isset( $_POST['ild_source'] ) ? esc_url_raw( wp_unslash( $_POST['ild_source'] ) ) : '';
		$raw          = isset( $_POST['ild_list'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ild_list'] ) ) : '';
		$exclude_id   = isset( $_POST['ild_page_id'] ) ? absint( wp_unslash( $_POST['ild_page_id'] ) ) : 0;

		// Store the lead: address, time, consent state, exact wording, source.
		$lead_id = ILD_Leads::store(
			array(
				'email'        => $email,
				'consent'      => $consent,
				'consent_text' => $consent_text,
				'source'       => $source,
			)
		);
		$lead_id = is_wp_error( $lead_id ) ? 0 : (int) $lead_id;

		// Attach this session's pre-gate submissions to the new lead, so a list
		// decoded before the address was given ends up on their record.
		if ( $lead_id > 0 && ! empty( $_COOKIE[ ILD_Submissions::SESSION_COOKIE ] ) ) {
			$session = sanitize_text_field( wp_unslash( $_COOKIE[ ILD_Submissions::SESSION_COOKIE ] ) );
			ILD_Submissions::attach_session_to_lead( $session, $lead_id );
		}

		// Set the first-party cookie so the gate is skipped on this device.
		$this->set_cookie();

		// Re-run the analysis and return just the gated breakdown.
		$match = ild_match_ingredient_list( $raw );
		if ( is_wp_error( $match ) ) {
			$this->fail( 'network' );
		}

		$analysis = ILD_Analysis::analyse( $match );
		$view     = ILD_Presenter::present( $match, $analysis, array( 'exclude_id' => $exclude_id ) );

		if ( ! isset( $view['state'] ) || 'result' !== $view['state'] ) {
			$this->fail( 'network' );
		}

		// Email the result. A failed send never blocks the on-screen breakdown.
		$mailer = new ILD_Email();
		$mailer->send_result( $email, $view, array( 'lead_id' => $lead_id ) );

		wp_send_json_success( array( 'html' => ILD_Shortcode::render_breakdown( $view ) ) );
	}

	/**
	 * Set the first-party access cookie.
	 *
	 * @return void
	 */
	private function set_cookie() {
		$expiry = time() + ( self::cookie_months() * 30 * DAY_IN_SECONDS );
		$value  = wp_hash( 'ild_gate|' . time() );

		$this->write_cookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expiry,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// Make it readable within this same request too.
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * Write the cookie. Wrapped so the flow can be exercised in tests; production
	 * behaviour is a normal setcookie() once headers are still open.
	 *
	 * @param string $name    The cookie name.
	 * @param string $value   The cookie value.
	 * @param array  $options The setcookie() options array.
	 * @return void
	 */
	protected function write_cookie( $name, $value, $options ) {
		if ( ! headers_sent() ) {
			setcookie( $name, $value, $options );
		}
	}

	/**
	 * End the request with a keyed error message.
	 *
	 * @param string $key The gate_messages() key.
	 * @return void
	 */
	private function fail( $key ) {
		$messages = ILD_Phrases::gate_messages();
		$message  = isset( $messages[ $key ] ) ? $messages[ $key ] : $messages['network'];

		wp_send_json_error( array( 'message' => $message ) );
	}
}
