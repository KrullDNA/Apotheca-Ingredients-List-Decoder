<?php
/**
 * Rate limiting and the daily cost cap.
 *
 * Per-IP limits keep any one visitor from hammering the tool: a limit on
 * analysis submissions and a separate, tighter one on image uploads, both
 * configurable. On top of that sits a hard, site-wide daily cap on any request
 * that costs money (image transcription and AI drafting), so a bad day can't run
 * up a bill — when it is reached, callers get a graceful message, not an error.
 *
 * Nothing that identifies a person is stored. The per-IP counters are keyed on a
 * salted hash of the address, never the address itself, and the daily cap is a
 * plain count.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-IP throttling and the daily paid-request cap.
 */
class ILD_Rate_Limit {

	/**
	 * The per-IP window, in seconds.
	 *
	 * @var int
	 */
	const WINDOW = HOUR_IN_SECONDS;

	/**
	 * The option holding the daily paid-request tally.
	 *
	 * @var string
	 */
	const DAILY_OPTION = 'ild_api_daily';

	/**
	 * Hook the settings section.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'ild_register_settings', array( $this, 'register_settings_section' ) );
	}

	/**
	 * Add the Limits section to the settings page.
	 *
	 * @param ILD_Settings $settings The settings component.
	 * @return void
	 */
	public function register_settings_section( $settings ) {
		$settings->add_section(
			array(
				'id'          => 'ild_section_limits',
				'title'       => __( 'Limits & cost', 'ingredient-list-decoder' ),
				'description' => __( 'Guards against abuse and runaway API cost. Counters are keyed on a hashed address — no IP is stored.', 'ingredient-list-decoder' ),
				'fields'      => array(
					array( 'id' => 'limit_analysis_per_hour', 'label' => __( 'Analyses per hour, per visitor', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 30, 'min' => 1, 'max' => 1000, 'sanitize' => 'absint' ),
					array( 'id' => 'limit_image_per_hour', 'label' => __( 'Photo uploads per hour, per visitor', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 8, 'min' => 1, 'max' => 200, 'sanitize' => 'absint', 'description' => __( 'Tighter than analyses, because each photo costs money to read.', 'ingredient-list-decoder' ) ),
					array( 'id' => 'daily_api_cap', 'label' => __( 'Daily paid-request cap (site-wide)', 'ingredient-list-decoder' ), 'type' => 'number', 'default' => 200, 'min' => 1, 'max' => 100000, 'sanitize' => 'absint', 'description' => __( 'The most money-costing requests (photo reads and AI drafts) allowed across the whole site in a day.', 'ingredient-list-decoder' ) ),
				),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Per-IP limits
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The configured per-hour limit for an action.
	 *
	 * @param string $action 'analysis' or 'image'.
	 * @return int
	 */
	public static function limit_for( $action ) {
		if ( 'image' === $action ) {
			return max( 1, (int) ild_get_setting( 'limit_image_per_hour', 8 ) );
		}
		return max( 1, (int) ild_get_setting( 'limit_analysis_per_hour', 30 ) );
	}

	/**
	 * Whether this visitor has used up their allowance for an action.
	 *
	 * Records the request when it is allowed, using a fixed window that starts at
	 * the first request. Filterable so a firewall or a logged-in exemption can be
	 * layered on.
	 *
	 * @param string $action 'analysis' or 'image'.
	 * @return bool True when the request should be refused.
	 */
	public static function too_many( $action ) {
		$limit = self::limit_for( $action );

		$key  = 'ild_rl_' . $action . '_' . self::ip_hash();
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || ( $now - (int) $data['start'] ) >= self::WINDOW ) {
			$data = array( 'start' => $now, 'count' => 0 );
		}

		$blocked = ( (int) $data['count'] >= $limit );

		if ( ! $blocked ) {
			$data['count']++;
			set_transient( $key, $data, self::WINDOW );
		}

		return (bool) apply_filters( 'ild_rate_limited', $blocked, $action );
	}

	/**
	 * A salted, non-reversible hash of the caller's address.
	 *
	 * @return string
	 */
	private static function ip_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return substr( wp_hash( 'ild_ip|' . $ip ), 0, 16 );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The daily site-wide cap on paid requests
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The configured daily cap.
	 *
	 * @return int
	 */
	public static function daily_cap() {
		return max( 1, (int) ild_get_setting( 'daily_api_cap', 200 ) );
	}

	/**
	 * How many paid requests have been made today.
	 *
	 * @return int
	 */
	public static function daily_count() {
		$data = get_option( self::DAILY_OPTION );
		if ( ! is_array( $data ) || ! isset( $data['date'] ) || $data['date'] !== self::today() ) {
			return 0;
		}
		return (int) $data['count'];
	}

	/**
	 * Whether the daily cap has been reached.
	 *
	 * @return bool
	 */
	public static function is_capped() {
		return self::daily_count() >= self::daily_cap();
	}

	/**
	 * Record one paid request against today's tally.
	 *
	 * @return void
	 */
	public static function record_paid_call() {
		$data = get_option( self::DAILY_OPTION );
		if ( ! is_array( $data ) || ! isset( $data['date'] ) || $data['date'] !== self::today() ) {
			$data = array( 'date' => self::today(), 'count' => 0 );
		}
		$data['count'] = (int) $data['count'] + 1;
		update_option( self::DAILY_OPTION, $data, false );
	}

	/**
	 * Today's date, in GMT, as a Y-m-d key.
	 *
	 * @return string
	 */
	private static function today() {
		return gmdate( 'Y-m-d' );
	}
}
