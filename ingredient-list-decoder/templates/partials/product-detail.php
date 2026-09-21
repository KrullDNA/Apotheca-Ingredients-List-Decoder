<?php
/**
 * The detail block for one product ingredient: description, then also-known-as,
 * evidence and founder's take.
 *
 * Included from templates/product-ingredients.php and shares its loop variables:
 *   $has_description, $description, $has_aka, $aka_text,
 *   $has_evidence, $evidence, $has_founder, $founder.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( ! empty( $has_description ) ) : ?>
	<p class="ild-product-ing__description"><?php echo nl2br( esc_html( $description ) ); ?></p>
<?php endif; ?>

<?php if ( ! empty( $has_aka ) ) : ?>
	<div class="ild-product-ing__field ild-product-ing__aka">
		<span class="ild-product-ing__detail-label"><?php echo esc_html( ILD_Phrases::label_aka() ); ?></span>
		<p class="ild-product-ing__detail-body"><?php echo esc_html( $aka_text ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $has_evidence ) ) : ?>
	<div class="ild-product-ing__field ild-product-ing__evidence">
		<span class="ild-product-ing__detail-label"><?php echo esc_html( ILD_Phrases::label_evidence() ); ?></span>
		<p class="ild-product-ing__detail-body"><?php echo nl2br( esc_html( $evidence ) ); ?></p>
	</div>
<?php endif; ?>

<?php if ( ! empty( $has_founder ) ) : ?>
	<div class="ild-product-ing__field ild-product-ing__founder">
		<span class="ild-product-ing__detail-label"><?php echo esc_html( ILD_Phrases::label_founder() ); ?></span>
		<p class="ild-product-ing__detail-body"><?php echo nl2br( esc_html( $founder ) ); ?></p>
	</div>
<?php endif; ?>
