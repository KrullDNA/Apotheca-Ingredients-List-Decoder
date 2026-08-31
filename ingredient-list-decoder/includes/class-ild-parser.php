<?php
/**
 * The parser: turning a pasted ingredient list into clean, ordered tokens.
 *
 * A real ingredient list is messy. It arrives with bracketed translations, "may
 * contain" pigment sections, asterisks for organic content, supplier "(and)"
 * blends, stray full stops and inconsistent spacing and case. This class does
 * one job: reduce that raw text to a tidy, ordered list of ingredient tokens,
 * without touching the database. The matcher then looks each token up.
 *
 * Order is everything: an ingredient list is ranked by amount, so the position
 * of each token is preserved from start to finish. That order is the whole basis
 * of the later analysis.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splits and cleans a raw ingredient list into ordered tokens.
 */
class ILD_Parser {

	/**
	 * The longest input we will even look at, in characters.
	 *
	 * A single product's ingredient list is at most a few thousand characters.
	 * Anything much larger is a paste of the wrong thing (a whole page, a
	 * spreadsheet), so it is rejected rather than processed.
	 *
	 * @var int
	 */
	const MAX_INPUT_CHARS = 10000;

	/**
	 * Parse a raw pasted list into an ordered array of tokens.
	 *
	 * Each returned token is an array of:
	 *  - 'position'   — its zero-based place in the list (order is preserved),
	 *  - 'original'   — the cleaned token as a human would read it,
	 *  - 'normalised' — the lower-cased, whitespace-collapsed form used to match.
	 *
	 * @param string $raw The pasted ingredient list.
	 * @return array|WP_Error The ordered tokens, or an error if the input is too
	 *                        long. An empty paste returns an empty array.
	 */
	public static function parse( $raw ) {
		$raw = is_string( $raw ) ? $raw : '';

		// Reject anything implausibly long before doing any work on it.
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $raw ) : strlen( $raw );
		if ( $length > self::MAX_INPUT_CHARS ) {
			return new WP_Error(
				'ild_input_too_long',
				sprintf(
					/* translators: %s: the maximum number of characters allowed. */
					__( 'That text is too long to be a single ingredient list (over %s characters). Please paste one product\'s list of ingredients.', 'ingredient-list-decoder' ),
					number_format_i18n( self::MAX_INPUT_CHARS )
				)
			);
		}

		// An empty paste simply has no tokens.
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$text = $raw;

		// 1. Drop the "may contain" / pigment tail. Everything from such a marker
		//    to the end is a list of things that might be present, not the
		//    formula itself, so it must not be read as ranked ingredients.
		$text = preg_replace( '/\bmay\s+contain.*$/isu', '', $text );
		$text = preg_replace( '/\+\/-.*$/su', '', $text );
		$text = preg_replace( '/±.*$/su', '', $text );

		// 2. Turn the supplier "(and)" blend separator into a real split point.
		//    This has to happen before the brackets around it are stripped, or
		//    the two blended ingredients would be glued into one token.
		$text = preg_replace( '/\(\s*and\s*\)/iu', ',', $text );

		// 3. Strip bracketed content — parentheses, square and curly brackets —
		//    repeatedly so nested groups clear, then remove any stray brackets.
		for ( $i = 0; $i < 5; $i++ ) {
			$stripped = preg_replace( '/\([^()]*\)|\[[^\[\]]*\]|\{[^{}]*\}/u', ' ', $text );
			if ( $stripped === $text ) {
				break;
			}
			$text = $stripped;
		}
		$text = str_replace( array( '(', ')', '[', ']', '{', '}' ), ' ', $text );

		// 4. Split into tokens on commas, semicolons and line breaks.
		$parts = preg_split( '/[;,\r\n]+/u', $text );

		// 5. Clean each token, dropping the empties, and keep the order.
		$tokens   = array();
		$position = 0;
		foreach ( (array) $parts as $part ) {
			$original = self::clean_token( $part );
			if ( '' === $original ) {
				continue;
			}

			$normalised = self::normalise( $original );
			if ( '' === $normalised ) {
				continue;
			}

			$tokens[] = array(
				'position'   => $position,
				'original'   => $original,
				'normalised' => $normalised,
			);
			$position++;
		}

		return $tokens;
	}

	/**
	 * Clean a single token down to a readable ingredient name.
	 *
	 * Removes asterisks, collapses whitespace and trims stray trailing full
	 * stops, keeping the original casing so it reads naturally when shown back.
	 *
	 * @param string $token The raw token from the split.
	 * @return string The cleaned, human-readable token.
	 */
	private static function clean_token( $token ) {
		$token = (string) $token;
		$token = str_replace( '*', '', $token );          // Organic / footnote markers.
		$token = preg_replace( '/\s+/u', ' ', $token );    // Collapse runs of whitespace.
		$token = trim( $token );
		$token = rtrim( $token, " .\t" );                  // Trailing full stops.

		return trim( $token );
	}

	/**
	 * Normalise a string to the form used for matching.
	 *
	 * Lower-cases, collapses whitespace and trims surrounding punctuation, so the
	 * same function can be applied to both a pasted token and a stored INCI name
	 * and the two will line up when they mean the same thing.
	 *
	 * @param string $string The value to normalise.
	 * @return string The normalised form.
	 */
	public static function normalise( $string ) {
		$string = (string) $string;
		$string = function_exists( 'mb_strtolower' ) ? mb_strtolower( $string, 'UTF-8' ) : strtolower( $string );
		$string = preg_replace( '/\s+/u', ' ', $string );
		$string = trim( $string, " \t\n\r\0\x0B.,;:*" );

		return $string;
	}
}
