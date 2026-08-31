<?php
/**
 * Draft a needs-review ingredient from an unknown token, using the Anthropic API.
 *
 * Given a token from the unknown queue, this asks the model to draft the fields
 * from section 4 of the brief — aliases, roles, typical use range, the sub-one
 * marker, description, evidence note, founder take, family and topics — and
 * creates an `ild_ingredient` in needs-review status with them filled in.
 *
 * Nothing is ever published automatically. A drafted entry always lands in
 * needs-review for a human to check.
 *
 * The API key is read from a constant in wp-config.php (ILD_ANTHROPIC_API_KEY),
 * never from the options table, so the drafting key is a deliberate, server-side
 * secret rather than a stored setting.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drafts ingredients from unknown tokens.
 */
class ILD_AI_Drafter {

	/**
	 * The Anthropic messages endpoint.
	 *
	 * @var string
	 */
	const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * The API key, from the wp-config.php constant only.
	 *
	 * @return string
	 */
	public static function api_key() {
		return ( defined( 'ILD_ANTHROPIC_API_KEY' ) && ILD_ANTHROPIC_API_KEY ) ? trim( (string) ILD_ANTHROPIC_API_KEY ) : '';
	}

	/**
	 * Whether drafting is available (the constant is defined).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::api_key();
	}

	/**
	 * The model used for drafting.
	 *
	 * @return string
	 */
	public static function model() {
		return (string) apply_filters( 'ild_draft_model', 'claude-sonnet-5' );
	}

	/**
	 * Draft a needs-review ingredient for a token.
	 *
	 * @param string $token     The INCI name / token to draft.
	 * @param int    $frequency The token's appearance count, carried onto the entry.
	 * @return int|WP_Error The new ingredient ID, or an error.
	 */
	public static function draft( $token, $frequency = 0 ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'ild_draft_empty', __( 'No token to draft.', 'ingredient-list-decoder' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'ild_draft_no_key', __( 'Define ILD_ANTHROPIC_API_KEY in wp-config.php to draft entries.', 'ingredient-list-decoder' ) );
		}

		// Never create a second entry for an INCI name we already hold.
		if ( ild_find_ingredient_by_title( $token ) ) {
			return new WP_Error( 'ild_draft_exists', __( 'An ingredient with this name already exists.', 'ingredient-list-decoder' ) );
		}

		$fields = self::request_draft( $token );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		return self::create_ingredient( $token, $fields, $frequency );
	}

	/**
	 * Ask the model for the drafted fields, as a validated array.
	 *
	 * @param string $token The INCI name.
	 * @return array|WP_Error
	 */
	private static function request_draft( $token ) {
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => self::api_key(),
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => self::model(),
						'max_tokens' => 1200,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => self::prompt( $token ),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ild_draft_http', __( 'The drafting request failed.', 'ingredient-list-decoder' ), array( 'status' => $code ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		return self::parse( $text );
	}

	/**
	 * The drafting prompt, naming the exact fields and controlled vocabularies.
	 *
	 * @param string $token The INCI name.
	 * @return string
	 */
	private static function prompt( $token ) {
		$roles    = implode( ', ', ILD_Roles::get_role_slugs() );
		$families = implode( ', ', self::term_names( ILD_Post_Types::TAX_FAMILY ) );
		$topics   = implode( ', ', self::term_names( ILD_Post_Types::TAX_TOPIC ) );

		$instruction = 'You are drafting a cosmetic ingredient database entry for the INCI name "' . $token . '". '
			. 'Reply with ONLY a JSON object, no prose and no code fences, with exactly these keys: '
			. '"inci_name" (the correctly capitalised INCI name as a string), '
			. '"also_known_as" (array of alternate names and common spellings), '
			. '"roles" (array; choose only from: ' . $roles . '), '
			. '"use_low" (number or null; typical low use percentage), '
			. '"use_high" (number or null; typical high use percentage), '
			. '"sub_one" (boolean; true if it is almost always used below one per cent), '
			. '"description" (2-3 plain-English sentences on what it does in a formula), '
			. '"evidence_note" (what the evidence supports, and where it does not; may be empty), '
			. '"founder_take" (leave as an empty string), '
			. '"family" (one of: ' . $families . '; or empty string), '
			. '"topics" (array; choose only from: ' . $topics . '). '
			. 'Be accurate and cautious. If you are unsure of a value, use null, an empty string, or an empty array. Do not invent unsafe or unsupported claims.';

		return $instruction;
	}

	/**
	 * The names of the terms in a taxonomy.
	 *
	 * @param string $taxonomy The taxonomy.
	 * @return array
	 */
	private static function term_names( $taxonomy ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'names' ) );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Parse and validate the model's JSON reply.
	 *
	 * @param string $text The reply text.
	 * @return array|WP_Error
	 */
	private static function parse( $text ) {
		$text = trim( (string) $text );
		// Tolerate accidental code fences.
		$text = preg_replace( '/^```(?:json)?|```$/m', '', $text );

		// Take from the first { to the last }.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return new WP_Error( 'ild_draft_parse', __( 'The drafting reply could not be read.', 'ingredient-list-decoder' ) );
		}

		$json = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'ild_draft_parse', __( 'The drafting reply could not be read.', 'ingredient-list-decoder' ) );
		}

		return $json;
	}

	/**
	 * Create the needs-review ingredient from the drafted fields.
	 *
	 * @param string $token     The token drafted.
	 * @param array  $fields    The parsed fields.
	 * @param int    $frequency The token's appearance count.
	 * @return int|WP_Error
	 */
	private static function create_ingredient( $token, $fields, $frequency ) {
		$inci = isset( $fields['inci_name'] ) && '' !== trim( (string) $fields['inci_name'] ) ? sanitize_text_field( $fields['inci_name'] ) : $token;

		$post_id = wp_insert_post(
			array(
				'post_type'   => ILD_Post_Types::POST_TYPE,
				'post_status' => ILD_Post_Types::STATUS_NEEDS_REVIEW,
				'post_title'  => $inci,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Aliases (one per line).
		$aliases = isset( $fields['also_known_as'] ) && is_array( $fields['also_known_as'] ) ? $fields['also_known_as'] : array();
		update_post_meta( $post_id, '_ild_also_known_as', ILD_Meta_Fields::sanitize_textarea( implode( "\n", array_map( 'sanitize_text_field', $aliases ) ) ) );

		// Roles (validated against the controlled vocabulary).
		$roles = isset( $fields['roles'] ) && is_array( $fields['roles'] ) ? $fields['roles'] : array();
		$roles = ILD_Meta_Fields::sanitize_roles( array_map( 'sanitize_key', $roles ) );
		if ( ! empty( $roles ) ) {
			update_post_meta( $post_id, '_ild_role', $roles );
		}

		// Use range.
		if ( isset( $fields['use_low'] ) && '' !== $fields['use_low'] && null !== $fields['use_low'] ) {
			update_post_meta( $post_id, '_ild_use_low', ILD_Meta_Fields::sanitize_percent( $fields['use_low'] ) );
		}
		if ( isset( $fields['use_high'] ) && '' !== $fields['use_high'] && null !== $fields['use_high'] ) {
			update_post_meta( $post_id, '_ild_use_high', ILD_Meta_Fields::sanitize_percent( $fields['use_high'] ) );
		}

		// Sub-one marker.
		if ( ! empty( $fields['sub_one'] ) ) {
			update_post_meta( $post_id, '_ild_sub_one_marker', 'yes' );
		}

		// Descriptive fields.
		if ( isset( $fields['description'] ) ) {
			update_post_meta( $post_id, '_ild_description', ILD_Meta_Fields::sanitize_textarea( $fields['description'] ) );
		}
		if ( isset( $fields['evidence_note'] ) ) {
			update_post_meta( $post_id, '_ild_evidence_note', ILD_Meta_Fields::sanitize_textarea( $fields['evidence_note'] ) );
		}
		if ( isset( $fields['founder_take'] ) ) {
			update_post_meta( $post_id, '_ild_founder_take', ILD_Meta_Fields::sanitize_textarea( $fields['founder_take'] ) );
		}

		// Family and topics: only assign terms that already exist.
		self::assign_existing_term( $post_id, ILD_Post_Types::TAX_FAMILY, isset( $fields['family'] ) ? array( $fields['family'] ) : array() );
		self::assign_existing_term( $post_id, ILD_Post_Types::TAX_TOPIC, isset( $fields['topics'] ) && is_array( $fields['topics'] ) ? $fields['topics'] : array() );

		// The demand behind this entry, used to order the review queue.
		update_post_meta( $post_id, '_ild_submission_frequency', max( 0, (int) $frequency ) );

		// A note that this was AI-drafted, for the reviewer.
		update_post_meta( $post_id, '_ild_ai_drafted', current_time( 'mysql', true ) );

		return (int) $post_id;
	}

	/**
	 * Assign only the given term names that already exist in a taxonomy.
	 *
	 * @param int    $post_id  The ingredient.
	 * @param string $taxonomy The taxonomy.
	 * @param array  $names    Candidate term names.
	 * @return void
	 */
	private static function assign_existing_term( $post_id, $taxonomy, $names ) {
		$ids = array();
		foreach ( (array) $names as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name ) {
				continue;
			}
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		if ( ! empty( $ids ) ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy );
		}
	}
}
