<?php
/**
 * The result view: the summary (always shown), then the breakdown — either
 * inline, or behind the email gate.
 *
 * The summary appears immediately. The full ingredient list and the read-next
 * block (the "breakdown") are shown straight away when the visitor already has
 * access, or held behind the email gate when they don't. When gated, the
 * breakdown markup is not in the page at all — it is delivered only after the
 * gate is completed, so it can't simply be read from the source.
 *
 * Expects: $view (array) the result view model.
 * Optional: $gated (bool), $carry (array), $consent_text (string),
 *           $exchange_text (string) — passed when the breakdown is gated.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view    = isset( $view ) ? $view : array();
$summary = isset( $view['summary'] ) ? $view['summary'] : array();
$caveat  = isset( $view['summary_caveat'] ) ? $view['summary_caveat'] : '';
$gated   = isset( $gated ) ? (bool) $gated : false;
?>
<div class="ild-result">

	<?php // Part one: a short descriptive summary. Always shown, never gated. ?>
	<section class="ild-summary">
		<h2 class="ild-summary__heading">
			<span class="ild-summary__icon" aria-hidden="true"></span>
			<?php echo esc_html( ILD_Phrases::heading_summary() ); ?>
		</h2>
		<?php
		foreach ( $summary as $point ) :
			// Points arrive as { text, level }; tolerate a plain string too.
			$text  = is_array( $point ) ? ( isset( $point['text'] ) ? $point['text'] : '' ) : $point;
			$level = is_array( $point ) && ! empty( $point['level'] ) ? $point['level'] : '';
			$class = 'ild-summary__point';
			if ( '' !== $level ) {
				$class .= ' ild-summary__point--' . $level;
			}
			?>
			<p class="<?php echo esc_attr( $class ); ?>"<?php echo ( '' !== $level ) ? ' data-confidence="' . esc_attr( $level ) . '"' : ''; ?>>
				<span class="ild-summary__point-icon" aria-hidden="true"></span>
				<span class="ild-summary__point-text"><?php echo esc_html( $text ); ?></span>
			</p>
		<?php endforeach; ?>
		<?php if ( '' !== $caveat ) : ?>
			<p class="ild-summary__caveat"><?php echo esc_html( $caveat ); ?></p>
		<?php endif; ?>
	</section>

	<?php
	// The breakdown: gated behind the email form, or shown inline.
	if ( $gated ) {
		include ILD_PLUGIN_DIR . 'templates/gate.php';
	} else {
		include ILD_PLUGIN_DIR . 'templates/breakdown.php';
	}
	?>

</div>
