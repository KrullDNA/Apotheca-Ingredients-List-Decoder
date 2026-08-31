<?php
/**
 * The Campaign Monitor connector.
 *
 * Implements the core's ILD_Email_Connector interface: it knows how to test the
 * connection and how to push one lead to a Campaign Monitor list. The core does
 * everything else — the queue, when to push, consent, retries, and recording the
 * outcome. A failed push returns a WP_Error, which the core surfaces in the
 * failed-sync view.
 *
 * The tool name is pushed as a custom field so these leads can be segmented apart
 * from Founding Faces members.
 *
 * @package IngredientListDecoder\CampaignMonitor
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes leads to Campaign Monitor.
 */
class ILDCM_Connector implements ILD_Email_Connector {

	/**
	 * The Campaign Monitor API base.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.createsend.com/api/v3.3/';

	/**
	 * The connector id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'campaign-monitor';
	}

	/**
	 * The connector name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Campaign Monitor';
	}

	/**
	 * The stored API key.
	 *
	 * @return string
	 */
	public static function api_key() {
		return trim( (string) ild_get_setting( 'cm_api_key', '' ) );
	}

	/**
	 * The stored list id.
	 *
	 * @return string
	 */
	public static function list_id() {
		return trim( (string) ild_get_setting( 'cm_list_id', '' ) );
	}

	/**
	 * Whether both the API key and the list id are set.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== self::api_key() && '' !== self::list_id();
	}

	/**
	 * Test the connection with the stored credentials.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		return self::test( self::api_key(), self::list_id() );
	}

	/**
	 * Test a given key and list by fetching the list's details.
	 *
	 * @param string $api_key The API key.
	 * @param string $list_id The list id.
	 * @return true|WP_Error
	 */
	public static function test( $api_key, $list_id ) {
		$api_key = trim( (string) $api_key );
		$list_id = trim( (string) $list_id );
		if ( '' === $api_key || '' === $list_id ) {
			return new WP_Error( 'ildcm_missing', __( 'Enter both an API key and a list ID.', 'ild-campaign-monitor' ) );
		}

		$response = self::request( 'GET', 'lists/' . rawurlencode( $list_id ) . '.json', null, $api_key );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Push one lead to the list.
	 *
	 * @param array $lead The normalised lead payload from the core.
	 * @return true|WP_Error
	 */
	public function push( array $lead ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'ildcm_not_configured', __( 'Campaign Monitor is not configured.', 'ild-campaign-monitor' ) );
		}

		$email = isset( $lead['email'] ) ? sanitize_email( $lead['email'] ) : '';
		if ( '' === $email ) {
			return new WP_Error( 'ildcm_no_email', __( 'The lead has no email address.', 'ild-campaign-monitor' ) );
		}

		$body = array(
			'EmailAddress'                          => $email,
			'ConsentToTrack'                        => 'Yes',
			'Resubscribe'                           => true,
			'RestartSubscriptionBasedAutoresponders' => false,
			'CustomFields'                          => array(
				array( 'Key' => 'Source', 'Value' => isset( $lead['source'] ) ? (string) $lead['source'] : '' ),
				array( 'Key' => 'ConsentDate', 'Value' => isset( $lead['consent_date'] ) ? (string) $lead['consent_date'] : '' ),
				array( 'Key' => 'Tool', 'Value' => isset( $lead['tool'] ) ? (string) $lead['tool'] : '' ),
			),
		);

		// Name only where given.
		if ( ! empty( $lead['name'] ) ) {
			$body['Name'] = sanitize_text_field( $lead['name'] );
		}

		$response = self::request( 'POST', 'subscribers/' . rawurlencode( self::list_id() ) . '.json', $body, self::api_key() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Make a request to the Campaign Monitor API.
	 *
	 * @param string     $method  GET or POST.
	 * @param string     $path    The API path after the base.
	 * @param array|null $body    The JSON body, for POST.
	 * @param string     $api_key The API key (basic auth username).
	 * @return array|WP_Error The decoded body on 2xx, or an error carrying the
	 *                        service's rejection message.
	 */
	private static function request( $method, $path, $body, $api_key ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':x' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return json_decode( wp_remote_retrieve_body( $response ), true );
		}

		// Surface the service's own message, so nothing is swallowed.
		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$message = ( is_array( $data ) && ! empty( $data['Message'] ) )
			? $data['Message']
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'Campaign Monitor returned HTTP %d.', 'ild-campaign-monitor' ), $code );

		return new WP_Error( 'ildcm_http_' . $code, $message, array( 'status' => $code ) );
	}
}
