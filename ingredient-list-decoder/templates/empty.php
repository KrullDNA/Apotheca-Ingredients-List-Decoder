<?php
/**
 * The empty state: the text held nothing we could read as ingredients.
 *
 * The icon slot carries no wording and stays invisible until Stage 7 styles it.
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
	<span class="ild-state__icon ild-empty__icon" aria-hidden="true"></span>
	<p class="ild-state__message"><?php echo esc_html( $message ); ?></p>
</div>
