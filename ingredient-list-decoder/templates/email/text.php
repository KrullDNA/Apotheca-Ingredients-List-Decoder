<?php
/**
 * The plain-text alternative to the result email.
 *
 * Built from the view model directly (not by stripping the HTML), so it reads
 * cleanly. The breakdown is flattened here too.
 *
 * Expects: $view (array) result view model, $opts (array) resolved options.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view        = isset( $view ) ? $view : array();
$opts        = isset( $opts ) ? $opts : array();
$summary     = isset( $view['summary'] ) ? $view['summary'] : array();
$caveat      = isset( $view['summary_caveat'] ) ? $view['summary_caveat'] : '';
$ingredients = isset( $view['ingredients'] ) ? $view['ingredients'] : array();
$readnext    = isset( $view['readnext'] ) && is_array( $view['readnext'] ) ? $view['readnext'] : array();

$lines   = array();
$lines[] = $opts['brand'];
$lines[] = '';
$lines[] = $opts['intro'];
$lines[] = '';
if ( ! empty( $opts['product'] ) ) {
	$lines[] = $opts['product'];
	$lines[] = '';
}
$lines[] = strtoupper( ILD_Phrases::heading_summary() );

foreach ( $summary as $point ) {
	$text    = is_array( $point ) ? ( isset( $point['text'] ) ? $point['text'] : '' ) : $point;
	$lines[] = '- ' . $text;
}
if ( '' !== $caveat ) {
	$lines[] = '';
	$lines[] = $caveat;
}

$lines[] = '';
$lines[] = strtoupper( ILD_Phrases::heading_ingredients() );

foreach ( $ingredients as $row ) {
	if ( 'matched' === $row['kind'] ) {
		$lines[] = $row['position'] . '. ' . $row['label'] . ' — ' . ILD_Phrases::row_role_label() . ': ' . $row['roles_text'] . '; ' . ILD_Phrases::row_family_label() . ': ' . $row['family_text'];
		if ( '' !== $row['description'] ) {
			$lines[] = '   ' . $row['description'];
		}
	} else {
		$lines[] = $row['position'] . '. ' . $row['label'] . ' — ' . $row['status_text'];
	}
}

if ( ! empty( $readnext ) ) {
	$lines[] = '';
	$lines[] = strtoupper( $opts['read_next_heading'] );
	foreach ( $readnext as $card ) {
		$lines[] = $card['title'];
		$lines[] = $card['url'];
	}
}

$lines[] = '';
$lines[] = $opts['signoff'];
$lines[] = '';
$lines[] = '--';
$lines[] = $opts['footer'];
if ( ! empty( $opts['privacy_url'] ) ) {
	$lines[] = $opts['privacy_label'] . ': ' . $opts['privacy_url'];
}
$lines[] = $opts['unsubscribe_label'] . ': ' . $opts['unsubscribe_url'];

echo implode( "\n", $lines ) . "\n";
