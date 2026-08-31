<?php
/**
 * A small, deliberately narrow CSS inliner for the result email.
 *
 * Email clients ignore most stylesheet CSS, so styles have to live in each
 * element's own style attribute. Rather than repeat styles by hand on every
 * cell, the template carries plain class names and one block of simple class
 * rules; this moves those rules onto the elements.
 *
 * It only understands single-class selectors (".foo, .bar { … }") — no
 * combinators, pseudo-classes, ids or media queries. That is all the template
 * uses, and keeping the inliner simple keeps it reliable. Media queries and
 * hover rules stay in the head, untouched, for the clients that honour them.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves simple class-based CSS onto the elements that use it.
 */
class ILD_Email_Inliner {

	/**
	 * Inline a block of simple class rules into an HTML document.
	 *
	 * @param string $html The full email HTML.
	 * @param string $css  The inlinable rules (only ".class { … }" selectors).
	 * @return string The HTML with the rules applied as inline styles.
	 */
	public static function inline( $html, $css ) {
		$rules = self::parse( $css );
		if ( empty( $rules ) ) {
			return $html;
		}

		// Protect Outlook conditional comments from the parser, restore after.
		$stash = array();
		$html  = preg_replace_callback(
			'/<!--\[if[\s\S]*?<!\[endif\]-->/i',
			function ( $m ) use ( &$stash ) {
				$token           = '<!--ILDMSO' . count( $stash ) . '-->';
				$stash[ $token ] = $m[0];
				return $token;
			},
			$html
		);

		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML pi keeps UTF-8 intact without the deprecated HTML-ENTITIES path.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		// Drop the xml processing instruction we added.
		foreach ( iterator_to_array( $dom->childNodes ) as $node ) {
			if ( XML_PI_NODE === $node->nodeType ) {
				$dom->removeChild( $node );
			}
		}

		$xpath = new DOMXPath( $dom );

		foreach ( $rules as $class => $declarations ) {
			$nodes = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]' );
			if ( ! $nodes ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$existing = $node->getAttribute( 'style' );
				// Stylesheet declarations first, so any hand-written inline style
				// on the element still wins.
				$merged = self::join( $declarations, $existing );
				$node->setAttribute( 'style', $merged );
			}
		}

		$out = $dom->saveHTML();

		// Restore the conditional comments.
		if ( ! empty( $stash ) ) {
			$out = strtr( $out, $stash );
		}

		return $out;
	}

	/**
	 * Parse simple class rules into a class => declarations map.
	 *
	 * @param string $css The CSS.
	 * @return array<string,string> Class name (no dot) => declaration string.
	 */
	private static function parse( $css ) {
		$rules = array();

		// Strip comments.
		$css = preg_replace( '#/\*[\s\S]*?\*/#', '', (string) $css );

		// Each "selector { declarations }" block.
		if ( ! preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER ) ) {
			return $rules;
		}

		foreach ( $matches as $match ) {
			$selectors    = array_map( 'trim', explode( ',', $match[1] ) );
			$declarations = trim( $match[2] );
			if ( '' === $declarations ) {
				continue;
			}
			$declarations = rtrim( $declarations, ';' ) . ';';

			foreach ( $selectors as $selector ) {
				// Only bare single-class selectors; skip anything else.
				if ( ! preg_match( '/^\.([A-Za-z0-9_-]+)$/', $selector, $sel ) ) {
					continue;
				}
				$class = $sel[1];
				$rules[ $class ] = isset( $rules[ $class ] ) ? self::join( $rules[ $class ], $declarations ) : $declarations;
			}
		}

		return $rules;
	}

	/**
	 * Join two declaration strings, each ending in a semicolon.
	 *
	 * @param string $a First.
	 * @param string $b Second (may be empty).
	 * @return string
	 */
	private static function join( $a, $b ) {
		$a = trim( (string) $a );
		$b = trim( (string) $b );
		if ( '' === $b ) {
			return $a;
		}
		if ( '' === $a ) {
			return $b;
		}
		$a = rtrim( $a, ';' ) . ';';
		$b = rtrim( $b, ';' ) . ';';
		return $a . ' ' . $b;
	}
}
