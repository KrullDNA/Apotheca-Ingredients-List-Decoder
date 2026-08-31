<?php
/**
 * The tool shell: the form, plus empty/loading/error regions the script drives.
 *
 * Rendered by the shortcode. Deliberately unstyled — Stage 7 styles it by the
 * class names below. All wording comes from ILD_Phrases so it can be edited in
 * one place.
 *
 * Expects: $uid (string) a unique id for this instance.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uid = isset( $uid ) ? $uid : 'ild';
?>
<div class="ild-tool" data-ild-tool>

	<form class="ild-form" method="post" novalidate>
		<?php wp_nonce_field( ILD_Shortcode::ACTION, 'ild_nonce' ); ?>

		<div class="ild-field ild-field--list">
			<label class="ild-label" for="<?php echo esc_attr( $uid ); ?>-list">
				<?php echo esc_html( ILD_Phrases::label_list() ); ?>
			</label>
			<p class="ild-help ild-form__intro"><?php echo esc_html( ILD_Phrases::form_intro() ); ?></p>
			<textarea
				class="ild-textarea"
				id="<?php echo esc_attr( $uid ); ?>-list"
				name="ild_list"
				rows="6"
				placeholder="<?php echo esc_attr( ILD_Phrases::placeholder_list() ); ?>"
				required
			></textarea>
		</div>

		<div class="ild-field ild-field--product">
			<label class="ild-label" for="<?php echo esc_attr( $uid ); ?>-product">
				<?php echo esc_html( ILD_Phrases::label_product() ); ?>
			</label>
			<input
				class="ild-product"
				type="text"
				id="<?php echo esc_attr( $uid ); ?>-product"
				name="ild_product_name"
				autocomplete="off"
			/>
			<p class="ild-help ild-product__help"><?php echo esc_html( ILD_Phrases::help_product() ); ?></p>
		</div>

		<?php // Honeypot: hidden from people, tempting to bots. Must stay empty. ?>
		<div class="ild-hp" aria-hidden="true" style="position:absolute !important; left:-9999px !important; top:auto; width:1px; height:1px; overflow:hidden;">
			<label for="<?php echo esc_attr( $uid ); ?>-hp"><?php echo esc_html( ILD_Phrases::honeypot_label() ); ?></label>
			<input type="text" id="<?php echo esc_attr( $uid ); ?>-hp" name="ild_hp" tabindex="-1" autocomplete="off" value="" />
		</div>

		<div class="ild-actions">
			<button type="submit" class="ild-submit"><?php echo esc_html( ILD_Phrases::submit() ); ?></button>
		</div>
	</form>

	<?php // Shown by the script while a request is in flight. ?>
	<div class="ild-loading" role="status" aria-live="polite" hidden>
		<?php echo esc_html( ILD_Phrases::loading() ); ?>
	</div>

	<?php // Last-resort message if the request itself never completes. ?>
	<div class="ild-error ild-error--network" role="alert" hidden>
		<?php echo esc_html( ILD_Phrases::error_generic() ); ?>
	</div>

	<?php // The rendered result, empty or error fragment is dropped in here. ?>
	<div class="ild-results" aria-live="polite"></div>

</div>
