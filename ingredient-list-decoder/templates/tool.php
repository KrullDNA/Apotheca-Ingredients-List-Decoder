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
 *           $show_photo (bool) whether to render the photo upload control.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$uid           = isset( $uid ) ? $uid : 'ild';
$extra_class   = isset( $extra_class ) ? trim( $extra_class ) : '';
$preview       = isset( $preview ) ? $preview : '';
$preview_html  = isset( $preview_html ) ? $preview_html : '';
$submit_icon   = isset( $submit_icon ) ? $submit_icon : '';
$show_photo    = isset( $show_photo ) ? (bool) $show_photo : false;
$ai_enhance    = isset( $ai_enhance ) ? (bool) $ai_enhance : false;
$exchange_text = isset( $exchange_text ) && '' !== $exchange_text ? $exchange_text : ILD_Phrases::exchange_default();
$consent_text  = isset( $consent_text ) && '' !== $consent_text ? $consent_text : ILD_Phrases::consent_default();
$wrap_class    = 'ild-tool' . ( '' !== $extra_class ? ' ' . $extra_class : '' );
?>
<div class="<?php echo esc_attr( $wrap_class ); ?>" data-ild-tool>

	<form class="ild-form" method="post" novalidate>
		<?php wp_nonce_field( ILD_Shortcode::ACTION, 'ild_nonce' ); ?>
		<?php // The page the tool sits on, so its own article is never shown as ?>
		<?php // "read next". Read back on submit and excluded from the block. ?>
		<input type="hidden" name="ild_page_id" value="<?php echo esc_attr( (int) get_queried_object_id() ); ?>" />
		<?php // The gate wording shown on this page, carried so the gate renders ?>
		<?php // with it and the exact consent wording can be stored. ?>
		<input type="hidden" name="ild_consent_text" value="<?php echo esc_attr( $consent_text ); ?>" />
		<input type="hidden" name="ild_exchange_text" value="<?php echo esc_attr( $exchange_text ); ?>" />

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

		<?php if ( $show_photo ) : ?>
			<?php // The photo route: upload or camera, beside the textarea. The image ?>
			<?php // is read on the server and its text returned for checking; nothing ?>
			<?php // is analysed until the verification step below is confirmed. ?>
			<div class="ild-field ild-field--photo">
				<?php wp_nonce_field( ILD_Transcription::ACTION, ILD_Transcription::NONCE ); ?>
				<p class="ild-label ild-photo__heading"><?php echo esc_html( ILD_Phrases::photo_heading() ); ?></p>

				<?php // The instruction sits under the heading, outside the drop box, so ?>
				<?php // it mirrors the paste box's intro and the box top lines up with the ?>
				<?php // textarea beside it. ?>
				<div class="ild-photo__intro">
					<p class="ild-dropzone__prompt"><?php echo esc_html( ILD_Phrases::dropzone_prompt() ); ?></p>
					<p class="ild-dropzone__hint" id="<?php echo esc_attr( $uid ); ?>-photo-hint"><?php echo esc_html( ILD_Phrases::dropzone_hint( ILD_Transcription::max_mb() ) ); ?></p>
				</div>

				<div class="ild-dropzone" data-ild-dropzone role="group" aria-describedby="<?php echo esc_attr( $uid ); ?>-photo-hint">
					<span class="ild-dropzone__icon" aria-hidden="true"></span>

					<?php // The file inputs are visually hidden but stay in the tab order, ?>
					<?php // so the label reads as a button and keyboard users can reach it. ?>
					<div class="ild-dropzone__actions">
						<label class="ild-button ild-dropzone__button ild-dropzone__choose">
							<span class="ild-dropzone__button-label"><?php echo esc_html( ILD_Phrases::dropzone_choose() ); ?></span>
							<input type="file" class="ild-photo__input ild-visually-hidden" data-ild-photo accept="image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif" />
						</label>
						<label class="ild-button ild-button--secondary ild-dropzone__button ild-dropzone__camera">
							<span class="ild-dropzone__button-label"><?php echo esc_html( ILD_Phrases::dropzone_camera() ); ?></span>
							<input type="file" class="ild-photo__input ild-visually-hidden" data-ild-photo accept="image/*" capture="environment" />
						</label>
					</div>

					<?php // Upload progress, shown by the script while reading. ?>
					<div class="ild-photo__progress" data-ild-photo-progress role="progressbar" aria-hidden="true" hidden>
						<span class="ild-photo__progress-bar"></span>
					</div>

					<?php // Reading status, announced to assistive tech. ?>
					<p class="ild-photo__status" data-ild-photo-status role="status" aria-live="polite" hidden></p>

					<?php // A photo-handling error, shown in place. Text set by the script. ?>
					<p class="ild-photo__error" data-ild-photo-error role="alert" hidden></p>
				</div>
			</div>

			<?php // The verification step: read the text, correct it, confirm. Hidden ?>
			<?php // until a transcription arrives (or shown for the editor preview). ?>
			<div class="ild-verify" data-ild-verify <?php echo ( 'verify' === $preview ) ? '' : 'hidden'; ?>>
				<div class="ild-verify__inner">
					<div class="ild-verify__media">
						<img class="ild-verify__thumb" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt="<?php echo esc_attr( ILD_Phrases::verify_thumb_alt() ); ?>" data-ild-verify-thumb />
					</div>
					<div class="ild-verify__body">
						<h3 class="ild-verify__heading"><?php echo esc_html( ILD_Phrases::verify_heading() ); ?></h3>
						<p class="ild-verify__notice"><?php echo esc_html( ILD_Phrases::verify_notice() ); ?></p>
						<label class="ild-label ild-verify__label" for="<?php echo esc_attr( $uid ); ?>-verify"><?php echo esc_html( ILD_Phrases::verify_label() ); ?></label>
						<textarea class="ild-verify__text" id="<?php echo esc_attr( $uid ); ?>-verify" rows="6" data-ild-verify-text><?php echo ( 'verify' === $preview ) ? esc_textarea( 'Aqua, Glycerin, Niacinamide, Cetearyl Alcohol, Phenoxyethanol' ) : ''; ?></textarea>
						<div class="ild-verify__actions">
							<button type="button" class="ild-button ild-verify__confirm" data-ild-verify-confirm><?php echo esc_html( ILD_Phrases::verify_confirm() ); ?></button>
							<?php // A more accurate AI reading, offered only when an API key is set. ?>
							<?php if ( $ai_enhance ) : ?>
								<button type="button" class="ild-button ild-button--secondary ild-verify__enhance" data-ild-verify-enhance><?php echo esc_html( ILD_Phrases::verify_enhance() ); ?></button>
							<?php endif; ?>
							<button type="button" class="ild-button ild-button--secondary ild-verify__retake" data-ild-verify-retake><?php echo esc_html( ILD_Phrases::verify_retake() ); ?></button>
						</div>
						<?php // Status/error for the more accurate reading, set by the script. ?>
						<p class="ild-verify__status" data-ild-verify-status role="status" aria-live="polite" hidden></p>
					</div>
				</div>
			</div>
		<?php endif; ?>

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

		<?php // The email exchange is not shown here: the reading appears first, and ?>
		<?php // the optional "email me a copy" form (with this wording) sits beneath ?>
		<?php // it. The wording still travels on the hidden field above for the gate. ?>

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
