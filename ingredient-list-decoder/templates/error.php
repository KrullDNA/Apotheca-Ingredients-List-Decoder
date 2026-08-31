<?php
/**
 * The error state: something stopped the list being read.
 *
 * Expects: $view (array) with a 'message' string.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = isset( $view['message'] ) ? $view['message'] : ILD_Phrases::error_generic();
?>
<div class="ild-state ild-error" role="alert">
	<p class="ild-state__message"><?php echo esc_html( $message ); ?></p>
</div>
