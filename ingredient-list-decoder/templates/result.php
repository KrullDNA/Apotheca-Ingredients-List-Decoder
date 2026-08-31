<?php
/**
 * The result view: summary, the full ingredient list, and the read-next region.
 *
 * The three parts the brief calls for. Unstyled; Stage 7 styles by class name.
 * Every phrase is already assembled by ILD_Presenter/ILD_Phrases — this file
 * only places and escapes it.
 *
 * A few elements carry no wording of their own and exist only so Stage 7 has a
 * real hook to style: the confidence marker on each summary point, the optional
 * icon slots, and the badges around role and family. They stay invisible until
 * a designer styles them.
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
							<span class="ild-ingredient__badge ild-ingredient__role">
								<span class="ild-ingredient__meta-label"><?php echo esc_html( ILD_Phrases::row_role_label() ); ?>:</span>
								<?php echo esc_html( $row['roles_text'] ); ?>
							</span>
							<span class="ild-ingredient__badge ild-ingredient__family">
								<span class="ild-ingredient__meta-label"><?php echo esc_html( ILD_Phrases::row_family_label() ); ?>:</span>
								<?php echo esc_html( $row['family_text'] ); ?>
							</span>
						</div>

						<?php
						$has_evidence = ! empty( $row['evidence'] );
						$has_founder  = ! empty( $row['founder'] );
						if ( '' !== $row['description'] || $has_evidence || $has_founder ) :
							?>
							<details class="ild-ingredient__detail">
								<summary class="ild-ingredient__detail-toggle">
									<span class="ild-ingredient__detail-toggle-label"><?php echo esc_html( ILD_Phrases::detail_toggle() ); ?></span>
									<span class="ild-ingredient__expand-icon" aria-hidden="true"></span>
								</summary>
								<div class="ild-ingredient__detail-panel">
									<?php if ( '' !== $row['description'] ) : ?>
										<p class="ild-ingredient__description"><?php echo nl2br( esc_html( $row['description'] ) ); ?></p>
									<?php endif; ?>

									<?php if ( $has_evidence ) : ?>
										<div class="ild-ingredient__evidence">
											<span class="ild-ingredient__evidence-label"><?php echo esc_html( ILD_Phrases::label_evidence() ); ?></span>
											<p class="ild-ingredient__evidence-body"><?php echo nl2br( esc_html( $row['evidence'] ) ); ?></p>
										</div>
									<?php endif; ?>

									<?php if ( $has_founder ) : ?>
										<div class="ild-ingredient__founder">
											<span class="ild-ingredient__founder-label"><?php echo esc_html( ILD_Phrases::label_founder() ); ?></span>
											<p class="ild-ingredient__founder-body"><?php echo nl2br( esc_html( $row['founder'] ) ); ?></p>
										</div>
									<?php endif; ?>
								</div>
							</details>
						<?php endif; ?>

					<?php else : ?>
						<p class="ild-ingredient__status">
							<span class="ild-ingredient__status-icon" aria-hidden="true"></span>
							<span class="ild-ingredient__status-text"><?php echo esc_html( $row['status_text'] ); ?></span>
						</p>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</section>

	<?php // A quiet way back to the top of the tool, without a page reload. ?>
	<div class="ild-result__actions">
		<button type="button" class="ild-button ild-button--secondary ild-restart" data-ild-restart>
			<?php echo esc_html( ILD_Phrases::restart() ); ?>
		</button>
	</div>

	<?php // Part three: an empty region reserved for the read-next block (Stage 9). ?>
	<section class="ild-readnext" data-ild-readnext aria-live="polite"><!-- Reserved for the read-next block. --></section>

</div>
