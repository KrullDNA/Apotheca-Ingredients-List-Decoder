<?php
/**
 * The Klaviyo connector.
 *
 * Implements the core's ILD_Email_Connector interface: it knows how to test the
 * connection and how to push one lead to a Klaviyo list, directly via the API —
 * never a CSV import. The core does everything else: the queue, when to push,
 * consent, retries, and recording the outcome. A failed push returns a WP_Error,
 * which the core surfaces in the failed-sync view.
 *
 * A push is two API calls, both idempotent so a core retry is safe:
 *
 *   1. Upsert the profile with the name (where given), the source as a profile
 *      property AND as a tag (a "Tags" array property), the consent date and the
 *      capturing tool — matching the existing Founding Faces convention of
 *      keying off both a tag and a property.
 *   2. Subscribe the profile to the configured list, recording email marketing
 *      consent.
 *
 * Klaviyo has no native per-profile tags (its Tags API tags lists, segments and
 * flows, not people), so the "tag" is carried as a multi-value profile property
 * named to match the Founding Faces setup. Segments key off it exactly as they
 * would a native tag.
 *
 * @package IngredientListDecoder\Klaviyo
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pushes leads to Klaviyo.
 */
class ILDK_Connector implements ILD_Email_Connector {

	/**
	 * The Klaviyo API base.
	 *
	 * @var string
	 */
	const API_BASE = 'https://a.klaviyo.com/api/';

	/**
	 * The Klaviyo API revision this connector is written against.
	 *
	 * @var string
	 */
	const API_REVISION = '2024-10-15';

	/**
	 * The profile property that carries the source page.
	 *
	 * @var string
	 */
	const SOURCE_PROPERTY = 'Source';

	/**
	 * The multi-value profile property used as tags (Founding Faces convention).
	 *
	 * @var string
	 */
	const TAGS_PROPERTY = 'Tags';

	/**
	 * The connector id.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'klaviyo';
	}

	/**
	 * The connector name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Klaviyo';
	}

	/**
	 * The stored private API key.
	 *
	 * @return string
	 */
	public static function api_key() {
		return trim( (string) ild_get_setting( 'klaviyo_api_key', '' ) );
	}

	/**
	 * The stored list id.
	 *
	 * @return string
	 */
	public static function list_id() {
		return trim( (string) ild_get_setting( 'klaviyo_list_id', '' ) );
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
	 * @param string $api_key The private API key.
	 * @param string $list_id The list id.
	 * @return true|WP_Error
	 */
	public static function test( $api_key, $list_id ) {
		$api_key = trim( (string) $api_key );
		$list_id = trim( (string) $list_id );
		if ( '' === $api_key || '' === $list_id ) {
			return new WP_Error( 'ildk_missing', __( 'Enter both an API key and a list ID.', 'ild-klaviyo' ) );
		}

		$response = self::request( 'GET', 'lists/' . rawurlencode( $list_id ) . '/', null, $api_key );
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
			return new WP_Error( 'ildk_not_configured', __( 'Klaviyo is not configured.', 'ild-klaviyo' ) );
		}

		$email = isset( $lead['email'] ) ? sanitize_email( $lead['email'] ) : '';
		if ( '' === $email ) {
			return new WP_Error( 'ildk_no_email', __( 'The lead has no email address.', 'ild-klaviyo' ) );
		}

		// 1. Upsert the profile with the name, properties and the source tag.
		$upsert = $this->upsert_profile( $email, $lead );
		if ( is_wp_error( $upsert ) ) {
			return $upsert;
		}

		// 2. Subscribe the profile to the list, recording consent.
		return $this->subscribe( $email, $lead );
	}

	/**
	 * Create the profile, or update it if it already exists.
	 *
	 * Klaviyo returns 409 with the existing profile id when the email is already
	 * known; we then PATCH that profile. Both paths set the same attributes, so
	 * a core retry lands the profile in the same state either way.
	 *
	 * @param string $email The email address.
	 * @param array  $lead  The normalised lead payload.
	 * @return true|WP_Error
	 */
	private function upsert_profile( $email, array $lead ) {
		$attributes = array(
			'email'      => $email,
			'properties' => $this->profile_properties( $lead ),
		);

		// Name only where given. Klaviyo takes a first name; a single-field name
		// goes there whole.
		if ( ! empty( $lead['name'] ) ) {
			$attributes['first_name'] = sanitize_text_field( $lead['name'] );
		}

		$body = array(
			'data' => array(
				'type'       => 'profile',
				'attributes' => $attributes,
			),
		);

		$response = self::request( 'POST', 'profiles/', $body, self::api_key() );

		// Already exists: Klaviyo hands back the id to update.
		if ( is_wp_error( $response ) && 409 === (int) $response->get_error_data() ) {
			$existing_id = self::duplicate_id_from( $response );
			if ( '' === $existing_id ) {
				return $response; // Could not find the id; surface the conflict.
			}

			$body['data']['id'] = $existing_id;
			$patch              = self::request( 'PATCH', 'profiles/' . rawurlencode( $existing_id ) . '/', $body, self::api_key() );
			if ( is_wp_error( $patch ) ) {
				return $patch;
			}
			return true;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * The profile properties: the source as a property AND as a tag, plus the
	 * consent date and the capturing tool for segmenting.
	 *
	 * @param array $lead The normalised lead payload.
	 * @return array
	 */
	private function profile_properties( array $lead ) {
		$source = isset( $lead['source'] ) ? (string) $lead['source'] : '';

		return array(
			self::SOURCE_PROPERTY => $source,
			self::TAGS_PROPERTY   => array( $source ), // The source as a tag.
			'ConsentDate'         => isset( $lead['consent_date'] ) ? (string) $lead['consent_date'] : '',
			'Tool'                => isset( $lead['tool'] ) ? (string) $lead['tool'] : '',
		);
	}

	/**
	 * Subscribe the profile to the list, recording email marketing consent.
	 *
	 * @param string $email The email address.
	 * @param array  $lead  The normalised lead payload.
	 * @return true|WP_Error
	 */
	private function subscribe( $email, array $lead ) {
		$profile = array(
			'type'       => 'profile',
			'attributes' => array(
				'email'         => $email,
				'subscriptions' => array(
					'email' => array(
						'marketing' => array( 'consent' => 'SUBSCRIBED' ),
					),
				),
			),
		);

		$body = array(
			'data' => array(
				'type'          => 'profile-subscription-bulk-create-job',
				'attributes'    => array(
					'custom_source' => isset( $lead['tool'] ) ? (string) $lead['tool'] : '',
					'profiles'      => array( 'data' => array( $profile ) ),
				),
				'relationships' => array(
					'list' => array(
						'data' => array(
							'type' => 'list',
							'id'   => self::list_id(),
						),
					),
				),
			),
		);

		$response = self::request( 'POST', 'profile-subscription-bulk-create-jobs/', $body, self::api_key() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return true;
	}

	/**
	 * Pull the existing profile id out of a 409 conflict response.
	 *
	 * @param WP_Error $error The conflict error, carrying the decoded body.
	 * @return string The duplicate profile id, or '' if not found.
	 */
	private static function duplicate_id_from( $error ) {
		$body = $error->get_error_data( 'body' );
		if ( is_array( $body ) && ! empty( $body['errors'][0]['meta']['duplicate_profile_id'] ) ) {
			return (string) $body['errors'][0]['meta']['duplicate_profile_id'];
		}
		return '';
	}

	/**
	 * Make a request to the Klaviyo API.
	 *
	 * @param string     $method  GET, POST or PATCH.
	 * @param string     $path    The API path after the base.
	 * @param array|null $body    The JSON:API body, for POST/PATCH.
	 * @param string     $api_key The private API key.
	 * @return array|WP_Error The decoded body on 2xx, or an error carrying the
	 *                        service's rejection message. The error data is the
	 *                        HTTP status code; the decoded body is attached under
	 *                        the 'body' key so a 409 can be inspected.
	 */
	private static function request( $method, $path, $body, $api_key ) {
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Klaviyo-API-Key ' . $api_key,
				'revision'      => self::API_REVISION,
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
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		// Surface the service's own message, so nothing is swallowed.
		$message = self::message_from( $data, $code );

		$error = new WP_Error( 'ildk_http_' . $code, $message, $code );
		if ( is_array( $data ) ) {
			$error->add_data( $data, 'body' );
		}
		return $error;
	}

	/**
	 * Build a human message from a Klaviyo error body, falling back to the code.
	 *
	 * @param mixed $data The decoded response body.
	 * @param int   $code The HTTP status code.
	 * @return string
	 */
	private static function message_from( $data, $code ) {
		if ( is_array( $data ) && ! empty( $data['errors'][0] ) ) {
			$first  = $data['errors'][0];
			$detail = isset( $first['detail'] ) ? (string) $first['detail'] : '';
			$title  = isset( $first['title'] ) ? (string) $first['title'] : '';
			if ( '' !== $detail ) {
				return $detail;
			}
			if ( '' !== $title ) {
				return $title;
			}
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'Klaviyo returned HTTP %d.', 'ild-klaviyo' ), $code );
	}
}
