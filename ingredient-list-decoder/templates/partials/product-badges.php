<?php
/**
 * The role and family badges for one product ingredient.
 *
 * Included from templates/product-ingredients.php and shares its loop variables:
 *   $has_role, $roles_text, $has_family, $family_text.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<span class="ild-product-ing__meta">
	<?php if ( ! empty( $has_role ) ) : ?>
		<span class="ild-product-ing__badge ild-product-ing__badge--role">
			<span class="ild-product-ing__meta-label"><?php echo esc_html( ILD_Phrases::row_role_label() ); ?>:</span>
			<?php echo esc_html( $roles_text ); ?>
		</span>
	<?php endif; ?>
	<?php if ( ! empty( $has_family ) ) : ?>
		<span class="ild-product-ing__badge ild-product-ing__badge--family">
			<span class="ild-product-ing__meta-label"><?php echo esc_html( ILD_Phrases::row_family_label() ); ?>:</span>
			<?php echo esc_html( $family_text ); ?>
		</span>
	<?php endif; ?>
</span>
