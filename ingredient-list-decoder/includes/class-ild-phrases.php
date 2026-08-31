<?php
/**
 * Every phrase the front end says, in one place.
 *
 * The analysis engine (Stage 5) deliberately produces findings with no wording.
 * This is where wording lives — and only here — so the whole voice of the tool
 * can be edited in a single file without touching logic or markup.
 *
 * The rules of the voice (section 7 of the brief) are baked in:
 *  - Inferences are hedged. "appears to sit below", never a stated percentage.
 *  - No brand names, no verdicts, no product mentions. The permitted
 *    construction for other companies is "some brands".
 *  - Category norms may be described; companies may not. Curious, never superior.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds and assembles every front-end phrase.
 */
class ILD_Phrases {

	/*
	 * -----------------------------------------------------------------------
	 * The form
	 * -----------------------------------------------------------------------
	 */

	/** @return string The intro line above the input. */
	public static function form_intro() {
		return __( 'Paste or type a product\'s ingredient list and we\'ll read it the way a formulator would — as a whole, not a glossary.', 'ingredient-list-decoder' );
	}

	/** @return string The label for the ingredient textarea. */
	public static function label_list() {
		return __( 'Ingredient list', 'ingredient-list-decoder' );
	}

	/** @return string The placeholder text inside the textarea. */
	public static function placeholder_list() {
		return __( 'Aqua, Glycerin, Niacinamide, Cetearyl Alcohol, Phenoxyethanol…', 'ingredient-list-decoder' );
	}

	/** @return string The label for the optional product-name field. */
	public static function label_product() {
		return __( 'Product name (optional)', 'ingredient-list-decoder' );
	}

	/** @return string The help line under the product-name field. */
	public static function help_product() {
		return __( 'Only for your own reference. It never appears in the reading.', 'ingredient-list-decoder' );
	}

	/** @return string The submit button label. */
	public static function submit() {
		return __( 'Read this formula', 'ingredient-list-decoder' );
	}

	/** @return string The button that clears the form to start over. */
	public static function restart() {
		return __( 'Start again', 'ingredient-list-decoder' );
	}

	/** @return string The label on the hidden anti-spam field. */
	public static function honeypot_label() {
		return __( 'Leave this field empty', 'ingredient-list-decoder' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Photo upload and the verification step (Stage 8)
	 * -----------------------------------------------------------------------
	 */

	/** @return string The small heading above the photo dropzone. */
	public static function photo_heading() {
		return __( 'Or read it from a photo', 'ingredient-list-decoder' );
	}

	/** @return string The main prompt inside the dropzone. */
	public static function dropzone_prompt() {
		return __( 'Drag a photo of the ingredient list here, or choose one below.', 'ingredient-list-decoder' );
	}

	/**
	 * The hint under the prompt, naming the accepted formats and size limit.
	 *
	 * @param int $max_mb The maximum file size in megabytes.
	 * @return string
	 */
	public static function dropzone_hint( $max_mb ) {
		/* translators: %d: the maximum file size in megabytes. */
		return sprintf( __( 'A single JPEG, PNG or HEIC photo, up to %d MB. iPhone HEIC photos are fine.', 'ingredient-list-decoder' ), (int) $max_mb );
	}

	/** @return string The button that opens the file picker. */
	public static function dropzone_choose() {
		return __( 'Choose a photo', 'ingredient-list-decoder' );
	}

	/** @return string The button that opens the camera. */
	public static function dropzone_camera() {
		return __( 'Take a photo', 'ingredient-list-decoder' );
	}

	/** @return string Announced/shown while the photo is being read. */
	public static function photo_reading() {
		return __( 'Reading the text from your photo…', 'ingredient-list-decoder' );
	}

	/** @return string The heading over the verification area. */
	public static function verify_heading() {
		return __( 'Check the transcription', 'ingredient-list-decoder' );
	}

	/** @return string The notice explaining the verification step. */
	public static function verify_notice() {
		return __( 'This is the text we read from your photo — we haven\'t analysed anything yet. Compare it with the image, fix anything we misread, then confirm to read the formula.', 'ingredient-list-decoder' );
	}

	/** @return string The label on the editable transcription field. */
	public static function verify_label() {
		return __( 'Transcribed ingredients', 'ingredient-list-decoder' );
	}

	/** @return string The alt text on the photo thumbnail. */
	public static function verify_thumb_alt() {
		return __( 'The photo you uploaded', 'ingredient-list-decoder' );
	}

	/** @return string The button that accepts the transcription and runs the engine. */
	public static function verify_confirm() {
		return __( 'Confirm and read the formula', 'ingredient-list-decoder' );
	}

	/** @return string The button that discards the photo and starts over. */
	public static function verify_retake() {
		return __( 'Use a different photo', 'ingredient-list-decoder' );
	}

	/**
	 * The small set of photo-handling messages the script may need to show.
	 *
	 * The wording still lives here; the script only displays whichever applies.
	 *
	 * @return array<string,string> Keyed by message id.
	 */
	public static function photo_messages() {
		return array(
			'too_large'      => __( 'That photo is larger than the limit. Please choose a smaller one.', 'ingredient-list-decoder' ),
			'wrong_type'     => __( 'That doesn\'t look like a JPEG, PNG or HEIC photo. Please choose one of those.', 'ingredient-list-decoder' ),
			'convert_failed' => __( 'We couldn\'t prepare that photo. Please try another, or type the list instead.', 'ingredient-list-decoder' ),
			'read_failed'    => __( 'We couldn\'t read the text from that photo. Try a clearer, straight-on shot, or type the list instead.', 'ingredient-list-decoder' ),
			'no_text'        => __( 'We couldn\'t find an ingredient list in that photo. Try a clearer photo of the back of the pack, or type the list instead.', 'ingredient-list-decoder' ),
			'network'        => __( 'Something interrupted reading your photo. Please try again.', 'ingredient-list-decoder' ),
			'not_ready'      => __( 'Reading photos isn\'t switched on yet. Please type the list instead.', 'ingredient-list-decoder' ),
		);
	}

	/**
	 * The live character-count template shown under the textarea.
	 *
	 * The running numbers are filled in by the script; the wording still lives
	 * here. The two tokens are replaced with the characters used and the limit.
	 *
	 * @return string A template containing the %used% and %max% tokens.
	 */
	public static function char_count_template() {
		return __( '%used% / %max% characters', 'ingredient-list-decoder' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * States: loading, empty, error
	 * -----------------------------------------------------------------------
	 */

	/** @return string Shown while the list is being read. */
	public static function loading() {
		return __( 'Reading the formula…', 'ingredient-list-decoder' );
	}

	/** @return string Shown when the text held no recognisable ingredients. */
	public static function empty_no_tokens() {
		return __( 'We couldn\'t find any ingredients in that text. Check it\'s an ingredient list and try again.', 'ingredient-list-decoder' );
	}

	/** @return string A general, calm failure message. */
	public static function error_generic() {
		return __( 'Something went wrong reading that list. Please try again.', 'ingredient-list-decoder' );
	}

	/** @return string Shown when the pasted text is far too long to be one list. */
	public static function error_too_long() {
		return __( 'That\'s more text than a single product\'s ingredient list. Please paste just the ingredients from one product.', 'ingredient-list-decoder' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Result headings
	 * -----------------------------------------------------------------------
	 */

	/** @return string Heading over the descriptive summary. */
	public static function heading_summary() {
		return __( 'How this formula is built', 'ingredient-list-decoder' );
	}

	/** @return string Heading over the full ingredient list. */
	public static function heading_ingredients() {
		return __( 'Every ingredient, in order', 'ingredient-list-decoder' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The summary sentences
	 * -----------------------------------------------------------------------
	 */

	/**
	 * When too little of the list could be identified to describe it.
	 *
	 * @return string
	 */
	public static function summary_insufficient() {
		return __( 'We couldn\'t identify enough of this list to describe how it\'s built. The ingredients we could read are shown below.', 'ingredient-list-decoder' );
	}

	/**
	 * What the formula is built on, when one or more roles clearly lead.
	 *
	 * @param string $leading   Natural-language list of the leading roles.
	 * @param string $secondary Natural-language list of supporting roles, or ''.
	 * @return string
	 */
	public static function summary_built_on( $leading, $secondary = '' ) {
		/* translators: %s: a list of ingredient roles, e.g. "humectants and emollients". */
		$sentence = sprintf( __( 'This formula looks to be built on %s.', 'ingredient-list-decoder' ), $leading );

		if ( '' !== $secondary ) {
			/* translators: %s: a list of supporting ingredient roles. */
			$sentence .= ' ' . sprintf( __( 'Beneath that, %s do the supporting work.', 'ingredient-list-decoder' ), $secondary );
		}

		return $sentence;
	}

	/**
	 * What the formula draws on, when no single role stands out.
	 *
	 * @param string $roles Natural-language list of roles seen near the top.
	 * @return string
	 */
	public static function summary_base_mixed( $roles ) {
		/* translators: %s: a list of ingredient roles. */
		return sprintf( __( 'Across the top of the list, this formula draws on %s.', 'ingredient-list-decoder' ), $roles );
	}

	/** @return string When a confirmed one per cent line was found. */
	public static function summary_line_confirmed() {
		return __( 'A number of ingredients appear to sit below the one per cent line.', 'ingredient-list-decoder' );
	}

	/** @return string When only a single sub-one marker was found. */
	public static function summary_line_single() {
		return __( 'At least one ingredient appears to sit below the one per cent line, though exactly where that line falls is harder to place here.', 'ingredient-list-decoder' );
	}

	/** @return string When no sub-one marker was found at all. */
	public static function summary_line_undetermined() {
		return __( 'There\'s no clear marker for where the one per cent line falls, so we haven\'t placed it.', 'ingredient-list-decoder' );
	}

	/** @return string The standing caveat about unregulated order below one per cent. */
	public static function summary_line_caveat() {
		return __( 'Ingredient order below one per cent isn\'t regulated, and some brands list it alphabetically, so read this as an interpretation rather than a measurement.', 'ingredient-list-decoder' );
	}

	/**
	 * The actives, when they all sit above the line.
	 *
	 * @param string $names Natural-language list of the active ingredient names.
	 * @return string
	 */
	public static function summary_actives_above( $names ) {
		/* translators: %s: a list of ingredient names. */
		return sprintf( __( '%s appear above that line.', 'ingredient-list-decoder' ), $names );
	}

	/**
	 * The actives, when they all sit below the line.
	 *
	 * @param string $names Natural-language list of the active ingredient names.
	 * @return string
	 */
	public static function summary_actives_below( $names ) {
		/* translators: %s: a list of ingredient names. */
		return sprintf( __( '%s appear to sit below the one per cent line.', 'ingredient-list-decoder' ), $names );
	}

	/**
	 * The actives, when some sit above the line and some below.
	 *
	 * @param string $above Natural-language list of names above the line.
	 * @param string $below Natural-language list of names below the line.
	 * @return string
	 */
	public static function summary_actives_split( $above, $below ) {
		/* translators: 1: names above the line, 2: names below the line. */
		return sprintf( __( '%1$s appear above the line, while %2$s appear to sit below it.', 'ingredient-list-decoder' ), $above, $below );
	}

	/**
	 * The actives, when the line itself could not be placed.
	 *
	 * @param string $names Natural-language list of the active ingredient names.
	 * @return string
	 */
	public static function summary_actives_names( $names ) {
		/* translators: %s: a list of ingredient names. */
		return sprintf( __( 'The actives we can see here are %s.', 'ingredient-list-decoder' ), $names );
	}

	/** @return string Shape note: an unusually short list. */
	public static function summary_shape_short() {
		return __( 'It\'s a short list — a simpler formula with fewer supporting ingredients.', 'ingredient-list-decoder' );
	}

	/** @return string Shape note: an unusually long list. */
	public static function summary_shape_long() {
		return __( 'It\'s a long list, with a lot of supporting ingredients alongside the actives.', 'ingredient-list-decoder' );
	}

	/** @return string Shape note: fragrance sitting high in the list. */
	public static function summary_shape_fragrance() {
		return __( 'Fragrance appears high in the list, which usually means there\'s more of it.', 'ingredient-list-decoder' );
	}

	/** @return string Shape note: the top of the list crowded with actives. */
	public static function summary_shape_loaded() {
		return __( 'The top of the list is busy with active ingredients.', 'ingredient-list-decoder' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Ingredient rows and the three unmatched states
	 * -----------------------------------------------------------------------
	 */

	/** @return string The "Role" label on a matched row. */
	public static function row_role_label() {
		return __( 'Role', 'ingredient-list-decoder' );
	}

	/** @return string The "Family" label on a matched row. */
	public static function row_family_label() {
		return __( 'Family', 'ingredient-list-decoder' );
	}

	/** @return string The placeholder when a field has no value. */
	public static function row_none() {
		return __( '—', 'ingredient-list-decoder' );
	}

	/** @return string The toggle that expands an ingredient's description. */
	public static function detail_toggle() {
		return __( 'Detail', 'ingredient-list-decoder' );
	}

	/** @return string The label above the evidence note in an expanded row. */
	public static function label_evidence() {
		return __( 'The evidence', 'ingredient-list-decoder' );
	}

	/** @return string The label above the founder take in an expanded row. */
	public static function label_founder() {
		return __( 'Founder\'s take', 'ingredient-list-decoder' );
	}

	/**
	 * State one: a fuzzy match — did you mean this instead?
	 *
	 * @param string $suggested The suggested INCI name.
	 * @return string
	 */
	public static function did_you_mean( $suggested ) {
		/* translators: %s: the suggested ingredient name. */
		return sprintf( __( 'Did you mean %s?', 'ingredient-list-decoder' ), $suggested );
	}

	/** @return string State two: a plausible ingredient not in the library yet. */
	public static function not_in_library() {
		return __( 'We don\'t have this one in the library yet.', 'ingredient-list-decoder' );
	}

	/** @return string State three: the token couldn't be read as an ingredient. */
	public static function unreadable() {
		return __( 'We couldn\'t read this one.', 'ingredient-list-decoder' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Small language helpers (still wording, so they live here)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Join a list of items into natural English, capped with "and others".
	 *
	 * @param array $items The items (already human-readable).
	 * @param int   $max   The most to name before falling back to "others".
	 * @return string The joined text, or '' when there is nothing.
	 */
	public static function list_to_text( $items, $max = 3 ) {
		// Drop blanks and re-index.
		$items = array_values( array_filter( array_map( 'trim', (array) $items ), 'strlen' ) );

		$count = count( $items );
		if ( 0 === $count ) {
			return '';
		}

		// Cap the number named, adding a gentle "and others".
		if ( $count > $max ) {
			$items   = array_slice( $items, 0, $max );
			$items[] = __( 'others', 'ingredient-list-decoder' );
		}

		if ( 1 === count( $items ) ) {
			return $items[0];
		}

		$last = array_pop( $items );

		/* translators: 1: a comma-separated list, 2: the final item. */
		return sprintf( __( '%1$s and %2$s', 'ingredient-list-decoder' ), implode( ', ', $items ), $last );
	}

	/**
	 * The plural of a role label, for use in a sentence.
	 *
	 * @param string $label The role's human label, e.g. "Humectant".
	 * @return string The plural, lower-cased, e.g. "humectants".
	 */
	public static function role_plural( $label ) {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );

		// Fragrance is uncountable; everything else takes a plain "s".
		if ( 'fragrance' === $lower ) {
			return $lower;
		}

		return $lower . 's';
	}
}
