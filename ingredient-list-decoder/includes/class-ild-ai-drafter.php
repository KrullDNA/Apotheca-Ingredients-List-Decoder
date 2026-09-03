<?php
/**
 * Draft a needs-review ingredient from an unknown token, using the Anthropic API.
 *
 * Given a token from the unknown queue, this asks the model to draft the fields
 * from section 4 of the brief — aliases, roles, typical use range, the sub-one
 * marker, description, evidence note, founder take, family and topics — and
 * creates an `ild_ingredient` in needs-review status with them filled in.
 *
 * Drafting can be run by hand (the button on the Unknown ingredients screen) or
 * automatically on a schedule: when automatic drafting is on, a background job
 * drafts the most-requested unknown tokens that have appeared at least a set
 * number of times, and — for entries the model is confident in — can publish
 * them straight away. Either way a drafted token leaves the unknown queue.
 *
 * The API key is read from a constant in wp-config.php (ILD_ANTHROPIC_API_KEY)
 * when one is defined, otherwise from the shared "Anthropic API key" setting, so
 * one key can serve both drafting and photo transcription. Drafting and the paid
 * photo reading are switched on independently, so either can run without the
 * other.
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
	 * The WP-Cron hook that runs a batch of automatic drafts.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'ild_auto_draft_event';

	/**
	 * Hook the settings section and the scheduled drafting job.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'ild_register_settings', array( $this, 'register_settings_section' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_auto_draft' ) );

		// Keep the schedule in step with the setting: schedule it when automatic
		// drafting is on, clear it when off. Runs on init so a saved setting change
		// takes effect without needing a reactivation.
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * The API key: the wp-config.php constant if defined, else the stored setting.
	 *
	 * A constant always wins, so a site that prefers a server-side secret can keep
	 * one; otherwise the same "Anthropic API key" the photo reading uses is shared.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'ILD_ANTHROPIC_API_KEY' ) && ILD_ANTHROPIC_API_KEY ) {
			return trim( (string) ILD_ANTHROPIC_API_KEY );
		}
		return trim( (string) ild_get_setting( 'anthropic_api_key', '' ) );
	}

	/**
	 * Whether drafting is available (a key is present).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return '' !== self::api_key();
	}

	/**
	 * Whether automatic (scheduled) drafting is switched on.
	 *
	 * @return bool
	 */
	public static function auto_on() {
		return 1 === (int) ild_get_setting( 'auto_draft_unknowns', 0 );
	}

	/**
	 * The model used for drafting.
	 *
	 * @return string
	 */
	public static function model() {
		$model = (string) ild_get_setting( 'draft_model', 'claude-sonnet-5' );
		if ( '' === trim( $model ) ) {
			$model = 'claude-sonnet-5';
		}
		return (string) apply_filters( 'ild_draft_model', $model );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Add the Automatic drafting section to the settings page.
	 *
	 * @param ILD_Settings $settings The settings component.
	 * @return void
	 */
	public function register_settings_section( $settings ) {
		$settings->add_section(
			array(
				'id'          => 'ild_section_drafting',
				'title'       => __( 'Automatic drafting of unknown ingredients', 'ingredient-list-decoder' ),
				'description' => __( 'Lets Claude fill the library on its own. When on, a background job drafts the most-requested unknown ingredients into entries and — where it is confident — publishes them, then clears them from the Unknown ingredients queue. It uses the Anthropic API key below, shared with photo transcription; each is switched on separately, so drafting can run with the paid photo reading off.', 'ingredient-list-decoder' ),
				'fields'      => array(
					array(
						'id'          => 'auto_draft_unknowns',
						'label'       => __( 'Draft unknown ingredients automatically', 'ingredient-list-decoder' ),
						'type'        => 'checkbox',
						'default'     => 0,
						'description' => __( 'When ticked, Claude drafts unknown ingredients on a schedule. Needs an Anthropic API key (below, or the ILD_ANTHROPIC_API_KEY constant).', 'ingredient-list-decoder' ),
					),
					array(
						'id'          => 'auto_draft_threshold',
						'label'       => __( 'Draft after this many appearances', 'ingredient-list-decoder' ),
						'type'        => 'number',
						'default'     => 3,
						'min'         => 1,
						'max'         => 100,
						'sanitize'    => 'absint',
						'description' => __( 'A token must have been pasted at least this many times before it is drafted, which keeps typos and one-off rubbish out.', 'ingredient-list-decoder' ),
					),
					array(
						'id'          => 'auto_draft_status',
						'label'       => __( 'Status for drafted entries', 'ingredient-list-decoder' ),
						'type'        => 'select',
						'default'     => 'publish',
						'options'     => array(
							'publish'      => __( 'Publish (confident ones go live; shakier ones held for review)', 'ingredient-list-decoder' ),
							'needs_review' => __( 'Needs review (nothing goes live until you approve it)', 'ingredient-list-decoder' ),
						),
						'sanitize'    => array( __CLASS__, 'sanitize_status' ),
						'description' => __( 'On "Publish", an entry only goes live when Claude reports high confidence in it; anything less is created in needs-review for you to check. Nothing Claude does not recognise as a real ingredient is ever created.', 'ingredient-list-decoder' ),
					),
					array(
						'id'          => 'auto_draft_per_run',
						'label'       => __( 'Most drafts per run', 'ingredient-list-decoder' ),
						'type'        => 'number',
						'default'     => 5,
						'min'         => 1,
						'max'         => 50,
						'sanitize'    => 'absint',
						'description' => __( 'How many entries a single scheduled run will draft, so cost stays predictable. The run also stops at the daily paid-request cap in "Limits & cost".', 'ingredient-list-decoder' ),
					),
					array(
						'id'          => 'draft_model',
						'label'       => __( 'Drafting model', 'ingredient-list-decoder' ),
						'type'        => 'text',
						'default'     => 'claude-sonnet-5',
						'sanitize'    => 'sanitize_text_field',
						'description' => __( 'The Anthropic model used to draft entries. A capable model is best here, since it writes the description and picks the roles.', 'ingredient-list-decoder' ),
					),
				),
			)
		);
	}

	/**
	 * Keep the status setting to one of the two allowed values.
	 *
	 * @param mixed $value The submitted value.
	 * @return string
	 */
	public static function sanitize_status( $value ) {
		return ( 'needs_review' === $value ) ? 'needs_review' : 'publish';
	}

	/*
	 * -----------------------------------------------------------------------
	 * The schedule
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Schedule the drafting job when automatic drafting is on, clear it when off.
	 *
	 * @return void
	 */
	public static function sync_schedule() {
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );

		if ( self::auto_on() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
			}
		} elseif ( $scheduled ) {
			self::clear_schedule();
		}
	}

	/**
	 * Remove any scheduled drafting job.
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The scheduled batch
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Draft a batch of the most-requested unknown tokens.
	 *
	 * Runs from WP-Cron. Only tokens seen at least the configured number of times
	 * are considered; the run stops at the per-run limit or the daily cost cap,
	 * whichever comes first. A token Claude does not recognise as a real ingredient
	 * is dismissed rather than created, so it also leaves the queue.
	 *
	 * @return void
	 */
	public static function run_auto_draft() {
		if ( ! self::auto_on() || ! self::is_available() ) {
			return;
		}

		$threshold = max( 1, (int) ild_get_setting( 'auto_draft_threshold', 3 ) );
		$per_run   = max( 1, min( 50, (int) ild_get_setting( 'auto_draft_per_run', 5 ) ) );
		$status    = self::sanitize_status( ild_get_setting( 'auto_draft_status', 'publish' ) );

		$tokens = ILD_Unknown_Tokens::get_open_over( $threshold, $per_run );

		foreach ( $tokens as $row ) {
			// Stop the moment the site-wide daily cap is reached.
			if ( ILD_Rate_Limit::is_capped() ) {
				break;
			}

			$id     = (int) $row['id'];
			$result = self::draft( $row['token'], (int) $row['appearances'], array( 'status' => $status ) );

			if ( is_wp_error( $result ) ) {
				$code = $result->get_error_code();
				// The model does not recognise it as a real ingredient: it is a typo
				// or noise, so dismiss it and move on — it leaves the queue.
				if ( 'ild_draft_unrecognised' === $code ) {
					ILD_Unknown_Tokens::dismiss( $id );
				}
				// An entry with this name already exists: the token is resolved, so
				// take it off the queue too, linking the existing entry where we can.
				if ( 'ild_draft_exists' === $code ) {
					$existing = ild_find_ingredient_by_title( $row['token'] );
					if ( $existing ) {
						ILD_Unknown_Tokens::mark_drafted( $id, (int) $existing );
					} else {
						ILD_Unknown_Tokens::dismiss( $id );
					}
				}
				// Any other error (a transient API failure, the cap): leave the token
				// open so the next run tries again.
				continue;
			}

			ILD_Unknown_Tokens::mark_drafted( $id, (int) $result );
		}
	}

	/**
	 * Draft an ingredient for a token.
	 *
	 * @param string $token     The INCI name / token to draft.
	 * @param int    $frequency The token's appearance count, carried onto the entry.
	 * @param array  $args       Options: { status: 'needs_review'|'publish' }. The
	 *                           default is needs-review (the manual button). On
	 *                           'publish', only an entry the model is confident in
	 *                           goes live; anything less falls back to needs-review.
	 * @return int|WP_Error The new ingredient ID, or an error.
	 */
	public static function draft( $token, $frequency = 0, $args = array() ) {
		$token = trim( (string) $token );
		if ( '' === $token ) {
			return new WP_Error( 'ild_draft_empty', __( 'No token to draft.', 'ingredient-list-decoder' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'ild_draft_no_key', __( 'Add an Anthropic API key in settings (or define ILD_ANTHROPIC_API_KEY in wp-config.php) to draft entries.', 'ingredient-list-decoder' ) );
		}

		// Never create a second entry for an INCI name we already hold.
		if ( ild_find_ingredient_by_title( $token ) ) {
			return new WP_Error( 'ild_draft_exists', __( 'An ingredient with this name already exists.', 'ingredient-list-decoder' ) );
		}

		// Drafting costs money: respect the site-wide daily cap.
		if ( ILD_Rate_Limit::is_capped() ) {
			return new WP_Error( 'ild_draft_capped', __( 'The daily paid-request limit has been reached. Try again tomorrow, or raise the cap in settings.', 'ingredient-list-decoder' ) );
		}

		$fields = self::request_draft( $token );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// Refuse to create anything the model does not recognise as a real
		// ingredient — that is a typo or noise, not a library entry.
		if ( isset( $fields['recognised'] ) && false === $fields['recognised'] ) {
			return new WP_Error( 'ild_draft_unrecognised', __( 'Not recognised as a real ingredient.', 'ingredient-list-decoder' ) );
		}

		$want   = ( isset( $args['status'] ) && 'publish' === $args['status'] ) ? 'publish' : 'needs_review';
		$status = self::decide_status( $want, $fields );

		return self::create_ingredient( $token, $fields, $frequency, $status );
	}

	/**
	 * Decide the post status for a drafted entry.
	 *
	 * Needs-review is never promoted. "Publish" only goes live when the model
	 * reports high confidence; medium or low confidence is held in needs-review so
	 * a human checks the shakier entries. The gate is filterable.
	 *
	 * @param string $want   The requested status ('publish' or 'needs_review').
	 * @param array  $fields The parsed fields (may carry a 'confidence').
	 * @return string A real post status.
	 */
	private static function decide_status( $want, $fields ) {
		if ( 'publish' !== $want ) {
			return ILD_Post_Types::STATUS_NEEDS_REVIEW;
		}

		$confidence = isset( $fields['confidence'] ) ? strtolower( (string) $fields['confidence'] ) : '';
		$publish    = ( 'high' === $confidence );

		/**
		 * Filter whether a confident auto-draft may be published straight away.
		 *
		 * @param bool  $publish    Whether to publish (true) or hold for review.
		 * @param array $fields     The drafted fields.
		 * @param string $confidence The reported confidence.
		 */
		$publish = (bool) apply_filters( 'ild_auto_draft_publish', $publish, $fields, $confidence );

		return $publish ? 'publish' : ILD_Post_Types::STATUS_NEEDS_REVIEW;
	}

	/**
	 * Ask the model for the drafted fields, as a validated array.
	 *
	 * @param string $token The INCI name.
	 * @return array|WP_Error
	 */
	private static function request_draft( $token ) {
		// This call costs money: count it against today's cap.
		ILD_Rate_Limit::record_paid_call();

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
			. 'Work carefully and in two passes. First, identify the ingredient from its INCI name and decide whether it is a real, recognised cosmetic ingredient (not a typo, brand name, or nonsense). '
			. 'Then draft each field, and before you answer, double-check every field against what you know about the ingredient and correct anything doubtful. '
			. 'Reply with ONLY a JSON object, no prose and no code fences, with exactly these keys: '
			. '"recognised" (boolean; true only if this is a real, recognised cosmetic INCI ingredient you can identify with confidence — if it looks like a typo, a brand or trade name, or nonsense, set it to false and leave the other fields at their empty defaults), '
			. '"confidence" (one of "high", "medium", "low"; how sure you are that this entry is correct and complete — use "high" only when you are certain of the identity and the key fields), '
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
			. 'Be accurate and cautious. If you are unsure of a value, use null, an empty string, or an empty array, and lower the confidence. Do not invent unsafe or unsupported claims.';

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
	 * @param string $status    The post status to create the entry in.
	 * @return int|WP_Error
	 */
	private static function create_ingredient( $token, $fields, $frequency, $status = null ) {
		$inci = isset( $fields['inci_name'] ) && '' !== trim( (string) $fields['inci_name'] ) ? sanitize_text_field( $fields['inci_name'] ) : $token;

		$status = ( null === $status ) ? ILD_Post_Types::STATUS_NEEDS_REVIEW : $status;

		$post_id = wp_insert_post(
			array(
				'post_type'   => ILD_Post_Types::POST_TYPE,
				'post_status' => $status,
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

		// A note that this was AI-drafted, for the reviewer, with the model's own
		// confidence and whether it was published automatically.
		update_post_meta( $post_id, '_ild_ai_drafted', current_time( 'mysql', true ) );
		if ( isset( $fields['confidence'] ) ) {
			update_post_meta( $post_id, '_ild_ai_confidence', sanitize_key( (string) $fields['confidence'] ) );
		}
		if ( 'publish' === $status ) {
			update_post_meta( $post_id, '_ild_ai_auto_published', current_time( 'mysql', true ) );
		}

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
