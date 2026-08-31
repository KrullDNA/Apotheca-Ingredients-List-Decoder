<?php
/**
 * The tool shell: the form, plus empty/loading/error regions the script drives.
 *
 * Rendered by the shortcode. Deliberately unstyled — Stage 7 styles it by the
 * class names below. All wording comes from ILD_Phrases so it can be edited in
 * one place.
 *
 * Expects: $uid (string) a unique id for this instance.
 * Optional: $extra_class (string) extra wrapper classes from the widget.
 *           $preview (string) an editor-only state to show in place.
 *           $preview_html (string) a pre-rendered fragment for the preview.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uid          = isset( $uid ) ? $uid : 'ild';
$extra_class  = isset( $extra_class ) ? trim( $extra_class ) : '';
$preview      = isset( $preview ) ? $preview : '';
$preview_html = isset( $preview_html ) ? $preview_html : '';
$submit_icon  = isset( $submit_icon ) ? $submit_icon : '';
$wrap_class   = 'ild-tool' . ( '' !== $extra_class ? ' ' . $extra_class : '' );
?>
<div class="<?php echo esc_attr( $wrap_class ); ?>" data-ild-tool>

	<form class="ild-form" method="post" novalidate>
		<?php wp_nonce_field( ILD_Shortcode::ACTION, 'ild_nonce' ); ?>

		<div class="ild-field ild-field--list">
			<label class="ild-label" for="<?php echo esc_attr( $uid ); ?>-list">
				<?php echo esc_html( ILD_Phrases::label_list() ); ?>
			</label>
			<p class="ild-help ild-form__intro" id="<?php echo esc_attr( $uid ); ?>-intro"><?php echo esc_html( ILD_Phrases::form_intro() ); ?></p>
			<textarea
				class="ild-textarea"
				id="<?php echo esc_attr( $uid ); ?>-list"
				name="ild_list"
				rows="6"
				maxlength="<?php echo esc_attr( ILD_Parser::MAX_INPUT_CHARS ); ?>"
				placeholder="<?php echo esc_attr( ILD_Phrases::placeholder_list() ); ?>"
				aria-describedby="<?php echo esc_attr( $uid ); ?>-intro <?php echo esc_attr( $uid ); ?>-count"
				required
			></textarea>
			<?php // A live count, filled in by the script. Wording comes from ILD_Phrases. ?>
			<p class="ild-charcount" id="<?php echo esc_attr( $uid ); ?>-count" data-ild-charcount aria-hidden="true"></p>
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
			<button type="submit" class="ild-submit">
				<?php if ( '' !== $submit_icon ) : ?>
					<?php // Icon markup comes from Elementor's icon manager, already escaped. ?>
					<span class="ild-submit__icon" aria-hidden="true"><?php echo $submit_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor Icons_Manager output. ?></span>
				<?php endif; ?>
				<span class="ild-submit__label"><?php echo esc_html( ILD_Phrases::submit() ); ?></span>
			</button>
		</div>
	</form>

	<?php // Shown by the script while a request is in flight. The spinner and the ?>
	<?php // skeleton are both here; Stage 7 chooses which to show. In the editor a ?>
	<?php // 'loading' preview leaves it visible so it can be styled. ?>
	<div class="ild-loading" role="status" aria-live="polite" <?php echo ( 'loading' === $preview ) ? '' : 'hidden'; ?>>
		<span class="ild-loading__spinner" aria-hidden="true"></span>
		<span class="ild-loading__label"><?php echo esc_html( ILD_Phrases::loading() ); ?></span>
		<div class="ild-loading__skeleton" aria-hidden="true">
			<span class="ild-skeleton ild-skeleton--line"></span>
			<span class="ild-skeleton ild-skeleton--line"></span>
			<span class="ild-skeleton ild-skeleton--line ild-skeleton--short"></span>
		</div>
	</div>

	<?php // Last-resort message if the request itself never completes. ?>
	<div class="ild-state ild-error ild-error--network" role="alert" hidden>
		<span class="ild-state__icon ild-error__icon" aria-hidden="true"></span>
		<p class="ild-state__message"><?php echo esc_html( ILD_Phrases::error_generic() ); ?></p>
	</div>

	<?php // The rendered result, empty or error fragment is dropped in here. In the ?>
	<?php // editor, a chosen preview state is placed here so it can be styled. ?>
	<div class="ild-results" aria-live="polite">
		<?php
		// $preview_html is built server-side by ILD_Shortcode for the editor only,
		// from the same templates the AJAX response uses, so it is already escaped.
		echo $preview_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-rendered, pre-escaped template fragment.
		?>
	</div>

</div>
