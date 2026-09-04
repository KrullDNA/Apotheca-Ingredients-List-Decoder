<?php
/**
 * The parser: turning a pasted ingredient list into clean, ordered tokens.
 *
 * A real ingredient list is messy. It arrives with bracketed common names, a
 * "may contain" shade section, asterisks for organic content, supplier "(and)"
 * blends, stray full stops and inconsistent spacing and case. This class does
 * one job: reduce that raw text to a tidy, ordered list of ingredient tokens,
 * with the shade declaration held apart, without touching the database. The
 * matcher then looks each token up.
 *
 * Two things are deliberate. Bracketed content is kept, because the brackets
 * usually carry the common name (Aqua (Water), Butyrospermum Parkii (Shea)
 * Butter) and keeping them helps a token line up with the stored INCI name. And
 * the shade range — everything after "may contain", "+/-" or "±" — is separated
 * out: it is not concentration-ordered, so it must never reach the ordering
 * logic, and its individual CI colourants are collapsed to a single flag rather
 * than listed (Titanium Dioxide and Zinc Oxide excepted, as they double as UV
 * filters and opacifiers).
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
	 * The CI colour-index numbers of the two shade-block exceptions.
	 *
	 * Titanium Dioxide (CI 77891) and Zinc Oxide (CI 77947) also work as UV
	 * filters and opacifiers, so even inside a "may contain" shade range they are
	 * kept as full entries rather than collapsed into the generic colourant line.
	 *
	 * @var string
	 */
	const CI_TITANIUM_DIOXIDE = '77891';
	const CI_ZINC_OXIDE       = '77947';

	/**
	 * Parse a raw pasted list into ordered tokens plus a separate shade block.
	 *
	 * The result is an array of:
	 *  - 'items' — the ordered ingredient tokens, each with:
	 *      - 'position'   its zero-based place in the list (order is preserved),
	 *      - 'original'   the cleaned token as a human would read it,
	 *      - 'normalised' the lower-cased, whitespace-collapsed form used to match.
	 *  - 'shade' — the shade declaration, held apart from the ordered list:
	 *      - 'present'    whether a shade block was found,
	 *      - 'colourants' whether it named colourants (collapsed, never listed),
	 *      - 'items'      the kept exceptions (Titanium Dioxide, Zinc Oxide) as
	 *                     { original, normalised } tokens.
	 *
	 * Bracketed content is retained — brackets usually carry the common name
	 * (Aqua (Water), Butyrospermum Parkii (Shea) Butter), and keeping them helps
	 * the token line up with the stored INCI name.
	 *
	 * @param string $raw The pasted ingredient list.
	 * @return array|WP_Error The { items, shade } result, or an error if the input
	 *                        is too long. An empty paste returns the empty result.
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
			return self::empty_result();
		}

		// 0a. Drop a leading label. If the text carries the word "ingredients"
		//     (as in "INGREDIENTS: Water (Aqua)"), remove it and everything before
		//     it, so any heading — or OCR noise above the label — is discarded and
		//     the list starts at the first real ingredient.
		$raw = self::strip_label( $raw );

		// 0b. Rejoin names split across lines. When commas or semicolons separate
		//     the ingredients, a line break is a wrap inside one name (a photo of a
		//     narrow label), not a separator — so join those lines back together
		//     rather than reading one ingredient as two.
		$raw = self::join_wrapped_lines( $raw );

		// 1. Separate the shade declaration. Everything from a "may contain",
		//    "+/-" or "±" marker to the end is a shade range, not a concentration-
		//    ordered list, so it is held apart and never ranked.
		list( $main_text, $shade_text ) = self::split_shade( $raw );

		// 2. Turn the supplier "(and)" blend separator into a real split point, so
		//    a blend like "Cetearyl Alcohol (and) Ceteareth-20" becomes two tokens.
		//    Other brackets are left in place.
		$main_text = preg_replace( '/\(\s*and\s*\)/iu', ',', $main_text );

		// 3. Protect a comma that sits between two digits — it is part of a chemical
		//    name's numbering (1,2-Hexanediol, 1,3-Propanediol), not a separator — so
		//    the name is not torn into "1" and "2-Hexanediol".
		$main_text = self::protect_numeric_commas( $main_text );

		// 4. Split into tokens on commas, semicolons and line breaks, keeping order.
		$parts = preg_split( '/[;,\r\n]+/u', $main_text );

		$tokens   = array();
		$position = 0;
		foreach ( (array) $parts as $part ) {
			$original = self::clean_token( self::restore_numeric_commas( $part ) );
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

		return array(
			'items' => $tokens,
			'shade' => self::parse_shade( $shade_text ),
		);
	}

	/**
	 * Remove a leading "ingredients" label and everything before it.
	 *
	 * Product labels and OCR reads often begin with a heading — "INGREDIENTS:",
	 * "Active Ingredients:", or a line of noise above it. Everything up to and
	 * including the word "ingredient(s)" (and a colon straight after it) is
	 * dropped, so the list starts at the first real ingredient. The match is
	 * greedy, so where two labels appear ("Active… Inactive Ingredients:") the
	 * list starts after the last one. If the word never appears, nothing changes.
	 *
	 * @param string $text The raw text.
	 * @return string
	 */
	private static function strip_label( $text ) {
		$stripped = preg_replace( '/^.*\bingredients?\b\s*:?\s*/isu', '', (string) $text, 1 );
		return ( null === $stripped || '' === $stripped ) ? $text : $stripped;
	}

	/**
	 * Join lines that are wraps inside one ingredient name, not separators.
	 *
	 * When the text uses commas or semicolons to separate ingredients, a bare line
	 * break is a wrap inside a single name (common when a tall, narrow label is
	 * photographed), so every run of line breaks becomes a single space. When
	 * there are no commas or semicolons, line breaks are the separators and are
	 * left untouched.
	 *
	 * @param string $text The raw text.
	 * @return string
	 */
	private static function join_wrapped_lines( $text ) {
		$text = (string) $text;
		if ( preg_match( '/[;,]/u', $text ) ) {
			$text = preg_replace( '/[ \t]*[\r\n]+[ \t]*/u', ' ', $text );
		}
		return $text;
	}

	/**
	 * The shape returned for an empty or shade-less parse.
	 *
	 * @return array
	 */
	private static function empty_result() {
		return array(
			'items' => array(),
			'shade' => array(
				'present'    => false,
				'colourants' => false,
				'items'      => array(),
			),
		);
	}

	/**
	 * Split the raw text into the main list and the shade declaration.
	 *
	 * The shade block starts at the first "may contain", "+/-" or "±" marker,
	 * whichever comes first, and runs to the end. The marker and everything after
	 * it is the shade text; everything before it is the ordered list.
	 *
	 * @param string $raw The raw pasted text.
	 * @return array{0:string,1:string} The main text and the shade text ('' if none).
	 */
	private static function split_shade( $raw ) {
		if ( preg_match( '/\bmay\s+contain\b|\+\/-|±/iu', $raw, $matches, PREG_OFFSET_CAPTURE ) ) {
			$offset = (int) $matches[0][1]; // Byte offset, matched by the same engine.
			return array( substr( $raw, 0, $offset ), substr( $raw, $offset ) );
		}

		return array( $raw, '' );
	}

	/**
	 * Read the shade block: collapse colourants, keep the two exceptions.
	 *
	 * Individual CI numbers are never listed; they collapse to a single flag
	 * meaning "this product contains colourants". Titanium Dioxide and Zinc Oxide
	 * are the exceptions — matched by name or by their CI numbers — and are kept
	 * as full entries, canonicalised to their INCI name so they still match.
	 *
	 * @param string $shade_text The shade text, including its marker.
	 * @return array The shade block { present, colourants, items }.
	 */
	private static function parse_shade( $shade_text ) {
		$block = array(
			'present'    => '' !== trim( (string) $shade_text ),
			'colourants' => false,
			'items'      => array(),
		);

		if ( ! $block['present'] ) {
			return $block;
		}

		// Drop the leading marker and any "may contain:" label before the list.
		$body  = preg_replace( '/^\s*(may\s+contain|\+\/-|±)\s*:?\s*/iu', '', $shade_text );
		$body  = self::protect_numeric_commas( (string) $body );
		$parts = preg_split( '/[;,\r\n]+/u', $body );

		foreach ( (array) $parts as $part ) {
			$original = self::clean_token( self::restore_numeric_commas( $part ) );
			if ( '' === $original ) {
				continue;
			}
			$normalised = self::normalise( $original );
			if ( '' === $normalised ) {
				continue;
			}

			// The two exceptions are kept; everything else is a colourant that is
			// collapsed to the single flag without being named.
			$canonical = self::shade_exception( $normalised );
			if ( '' !== $canonical ) {
				$block['items'][] = array(
					'original'   => $original,
					'normalised' => $canonical,
				);
			} else {
				$block['colourants'] = true;
			}
		}

		return $block;
	}

	/**
	 * Whether a shade token is one of the two kept exceptions, canonicalised.
	 *
	 * Matches Titanium Dioxide and Zinc Oxide by name or by their CI numbers, and
	 * returns the INCI name to match on ('' when it is an ordinary colourant).
	 *
	 * @param string $normalised The normalised shade token.
	 * @return string 'titanium dioxide', 'zinc oxide', or ''.
	 */
	private static function shade_exception( $normalised ) {
		if ( false !== strpos( $normalised, 'titanium dioxide' ) || preg_match( '/\b' . self::CI_TITANIUM_DIOXIDE . '\b/u', $normalised ) ) {
			return 'titanium dioxide';
		}
		if ( false !== strpos( $normalised, 'zinc oxide' ) || preg_match( '/\b' . self::CI_ZINC_OXIDE . '\b/u', $normalised ) ) {
			return 'zinc oxide';
		}

		return '';
	}

	/**
	 * The placeholder standing in for a protected numeric comma while splitting.
	 *
	 * A control character (unit separator) that will never appear in a pasted
	 * ingredient list, so it can be swapped in and back out without collision.
	 *
	 * @var string
	 */
	const COMMA_PLACEHOLDER = "\x1f";

	/**
	 * Replace a comma between two digits with the placeholder, so it survives the
	 * split. Matches "1,2" but never "Glycerin, Water", whose comma is a real
	 * separator followed by a space.
	 *
	 * @param string $text The text about to be split.
	 * @return string
	 */
	private static function protect_numeric_commas( $text ) {
		return preg_replace( '/(?<=\d),(?=\d)/u', self::COMMA_PLACEHOLDER, (string) $text );
	}

	/**
	 * Put any protected numeric commas back, once splitting is done.
	 *
	 * @param string $text A token that may hold the placeholder.
	 * @return string
	 */
	private static function restore_numeric_commas( $text ) {
		return str_replace( self::COMMA_PLACEHOLDER, ',', (string) $text );
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
