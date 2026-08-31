<?php
/**
 * The result view: summary, the full ingredient list, and the read-next region.
 *
 * The three parts the brief calls for. Unstyled; Stage 7 styles by class name.
 * Every phrase is already assembled by ILD_Presenter/ILD_Phrases — this file
 * only places and escapes it.
 *
 * Expects: $view (array) the result view model from ILD_Presenter.
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
$ingredients = isset( $view['ingredients'] ) ? $view['ingredients'] : array();
?>
<div class="ild-result">

	<?php // Part one: a short descriptive summary of what the formula is built on. ?>
	<section class="ild-summary">
		<h2 class="ild-summary__heading"><?php echo esc_html( ILD_Phrases::heading_summary() ); ?></h2>
		<?php foreach ( $summary as $point ) : ?>
			<p class="ild-summary__point"><?php echo esc_html( $point ); ?></p>
		<?php endforeach; ?>
		<?php if ( '' !== $caveat ) : ?>
			<p class="ild-summary__caveat"><?php echo esc_html( $caveat ); ?></p>
		<?php endif; ?>
	</section>

	<?php // Part two: every ingredient, in order, with detail on tap. ?>
	<section class="ild-ingredients">
		<h2 class="ild-ingredients__heading"><?php echo esc_html( ILD_Phrases::heading_ingredients() ); ?></h2>
		<ol class="ild-ingredients__list">
			<?php foreach ( $ingredients as $row ) : ?>
				<li class="ild-ingredient ild-ingredient--<?php echo esc_attr( $row['kind'] ); ?>">
					<div class="ild-ingredient__head">
						<span class="ild-ingredient__pos"><?php echo esc_html( $row['position'] ); ?></span>
						<span class="ild-ingredient__name"><?php echo esc_html( $row['label'] ); ?></span>
					</div>

					<?php if ( 'matched' === $row['kind'] ) : ?>
						<div class="ild-ingredient__meta">
							<span class="ild-ingredient__role">
								<span class="ild-ingredient__meta-label"><?php echo esc_html( ILD_Phrases::row_role_label() ); ?>:</span>
								<?php echo esc_html( $row['roles_text'] ); ?>
							</span>
							<span class="ild-ingredient__family">
								<span class="ild-ingredient__meta-label"><?php echo esc_html( ILD_Phrases::row_family_label() ); ?>:</span>
								<?php echo esc_html( $row['family_text'] ); ?>
							</span>
						</div>

						<?php if ( '' !== $row['description'] ) : ?>
							<details class="ild-ingredient__detail">
								<summary class="ild-ingredient__detail-toggle"><?php echo esc_html( ILD_Phrases::detail_toggle() ); ?></summary>
								<div class="ild-ingredient__detail-body"><?php echo nl2br( esc_html( $row['description'] ) ); ?></div>
							</details>
						<?php endif; ?>

					<?php else : ?>
						<p class="ild-ingredient__status"><?php echo esc_html( $row['status_text'] ); ?></p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>

	<?php // Part three: an empty region reserved for the read-next block (Stage 9). ?>
	<section class="ild-readnext" data-ild-readnext aria-live="polite"><!-- Reserved for the read-next block. --></section>

</div>
