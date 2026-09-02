<?php
/**
 * The gated breakdown: the full ingredient list and the read-next block.
 *
 * Everything below the summary lives here, so it can be shown inline (when the
 * visitor already has access) or returned on its own after the email gate is
 * completed. It carries no wording of its own beyond ILD_Phrases and only places
 * and escapes the view model.
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
$ingredients = isset( $view['ingredients'] ) ? $view['ingredients'] : array();
$readnext    = isset( $view['readnext'] ) && is_array( $view['readnext'] ) ? $view['readnext'] : array();
?>
<div class="ild-breakdown">

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

					<?php
					$is_matched    = ( 'matched' === $row['kind'] );
					$is_suggestion = ( 'suggestion' === $row['kind'] );
					?>

					<?php // A near-miss: name the match, and offer to swap it in. ?>
					<?php if ( $is_suggestion ) : ?>
						<p class="ild-ingredient__status ild-ingredient__status--suggestion">
							<span class="ild-ingredient__status-icon" aria-hidden="true"></span>
							<span class="ild-ingredient__status-text"><?php echo esc_html( $row['status_text'] ); ?></span>
							<button
								type="button"
								class="ild-ingredient__apply"
								data-ild-apply
								data-original="<?php echo esc_attr( $row['apply_original'] ); ?>"
								data-replacement="<?php echo esc_attr( $row['apply_replacement'] ); ?>"
							><?php echo esc_html( $row['apply_label'] ); ?></button>
						</p>
					<?php endif; ?>

					<?php if ( $is_matched || $is_suggestion ) : ?>
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
					<?php unset( $is_matched, $is_suggestion ); ?>
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

	<?php // Part three: the read-next block. Rendered only when posts actually ?>
	<?php // share a term with the findings — otherwise nothing at all. ?>
	<?php if ( ! empty( $readnext ) ) : ?>
		<section class="ild-readnext" data-ild-readnext aria-labelledby="ild-readnext-heading">
			<h2 class="ild-readnext__heading" id="ild-readnext-heading"><?php echo esc_html( ILD_Phrases::heading_readnext() ); ?></h2>
			<ul class="ild-readnext__grid">
				<?php foreach ( $readnext as $card ) : ?>
					<li class="ild-readnext__item">
						<a class="ild-readnext__card" href="<?php echo esc_url( $card['url'] ); ?>">
							<?php if ( ! empty( $card['thumb'] ) ) : ?>
								<span class="ild-readnext__thumb-wrap">
									<img class="ild-readnext__thumb" src="<?php echo esc_url( $card['thumb'] ); ?>" alt="" loading="lazy" />
								</span>
							<?php endif; ?>
							<span class="ild-readnext__body">
								<span class="ild-readnext__title"><?php echo esc_html( $card['title'] ); ?></span>
								<?php if ( ! empty( $card['excerpt'] ) ) : ?>
									<span class="ild-readnext__excerpt"><?php echo esc_html( $card['excerpt'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $card['meta'] ) ) : ?>
									<span class="ild-readnext__meta"><?php echo esc_html( $card['meta'] ); ?></span>
								<?php endif; ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

</div>
