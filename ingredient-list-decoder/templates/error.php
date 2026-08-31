<?php
/**
 * The error state: something stopped the list being read.
 *
 * An optional 'variant' lets a later stage mark a particular kind of error —
 * the rate-limit message from Stage 14, for example — so it can be styled
 * separately as .ild-error--{variant}. The icon slot stays invisible until
 * Stage 7 styles it.
 *
 * Expects: $view (array) with a 'message' string, and an optional 'variant'.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = isset( $view['message'] ) ? $view['message'] : ILD_Phrases::error_generic();
$variant = isset( $view['variant'] ) ? preg_replace( '/[^a-z0-9_-]/', '', (string) $view['variant'] ) : '';
$classes = 'ild-state ild-error';
if ( '' !== $variant ) {
	$classes .= ' ild-error--' . $variant;
}
?>
<div class="<?php echo esc_attr( $classes ); ?>" role="alert">
	<span class="ild-state__icon ild-error__icon" aria-hidden="true"></span>
	<p class="ild-state__message"><?php echo esc_html( $message ); ?></p>
</div>
