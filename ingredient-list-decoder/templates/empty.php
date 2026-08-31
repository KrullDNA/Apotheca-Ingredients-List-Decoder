<?php
/**
 * The empty state: the text held nothing we could read as ingredients.
 *
 * Expects: $view (array) with a 'message' string.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$message = isset( $view['message'] ) ? $view['message'] : ILD_Phrases::empty_no_tokens();
?>
<div class="ild-state ild-empty" role="status">
	<p class="ild-state__message"><?php echo esc_html( $message ); ?></p>
</div>
