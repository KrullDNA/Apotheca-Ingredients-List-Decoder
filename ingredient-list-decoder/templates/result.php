<?php
/**
 * The result view: the summary and the full breakdown, both always on screen,
 * with an optional email form beneath so the visitor can keep a copy.
 *
 * The summary and the breakdown (every ingredient in order, and the read-next
 * block) are shown straight away. The email form is not a gate — the reading is
 * already visible; it is only an offer to send the reading to an inbox, shown
 * until the visitor has given an address.
 *
 * Expects: $view (array) the result view model.
 * Optional: $email_offer (bool) whether to show the email form; $carry (array),
 *           $consent_text (string), $exchange_text (string) — for that form.
 *           ($gated is accepted as an alias of $email_offer for editor previews.)
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view        = isset( $view ) ? $view : array();
$summary     = isset( $view['summary'] ) ? $view['summary'] : array();
$caveat      = isset( $view['summary_caveat'] ) ? $view['summary_caveat'] : '';
$email_offer = isset( $email_offer ) ? (bool) $email_offer : ( isset( $gated ) ? (bool) $gated : false );
?>
<div class="ild-result">

	<?php // Part one: a short descriptive summary. Always shown, never gated. ?>
	<section class="ild-summary">
		<?php // Kept on one line so the empty icon slot leaves no stray space that ?>
		<?php // would indent the heading past the ingredient heading below it. ?>
		<h2 class="ild-summary__heading"><span class="ild-summary__icon" aria-hidden="true"></span><?php echo esc_html( ILD_Phrases::heading_summary() ); ?></h2>
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
	// The full breakdown — every ingredient in order, and the read-next block —
	// always on screen.
	include ILD_PLUGIN_DIR . 'templates/breakdown.php';

	// Beneath it, the optional "email me a copy" form, until the visitor has
	// given an address. It no longer hides anything; the reading is above it.
	if ( $email_offer ) {
		include ILD_PLUGIN_DIR . 'templates/gate.php';
	}
	?>

</div>
