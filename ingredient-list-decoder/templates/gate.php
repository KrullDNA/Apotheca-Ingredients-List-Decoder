<?php
/**
 * The email gate: shown in place of the breakdown until the visitor gives an
 * email and consent.
 *
 * The summary is already above this. This form carries a copy of the pasted list
 * so the server can re-run the analysis and return the breakdown once the gate
 * is completed — the breakdown is never in the page until then. One consent
 * checkbox covers both emailing the result and marketing from Apotheca®; the
 * submit button stays disabled until it is ticked, with the reason shown rather
 * than failing silently.
 *
 * Expects (from result.php's scope):
 *   $carry (array)         { list, page_id } to re-run the analysis.
 *   $consent_text (string) the exact consent wording to show and to store.
 *   $exchange_text (string) the up-front exchange wording, shown here too.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$carry         = isset( $carry ) && is_array( $carry ) ? $carry : array();
$consent_text  = isset( $consent_text ) && '' !== $consent_text ? $consent_text : ILD_Phrases::consent_default();
$exchange_text = isset( $exchange_text ) && '' !== $exchange_text ? $exchange_text : ILD_Phrases::exchange_default();
$carry_list    = isset( $carry['list'] ) ? $carry['list'] : '';
$carry_page    = isset( $carry['page_id'] ) ? (int) $carry['page_id'] : 0;

$guid         = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'ild-gate-' ) : uniqid( 'ild-gate-' );
$privacy_url  = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
?>
<div class="ild-gate" data-ild-gate>
	<h3 class="ild-gate__heading"><?php echo esc_html( ILD_Phrases::gate_heading() ); ?></h3>
	<p class="ild-gate__body"><?php echo esc_html( $exchange_text ); ?></p>

	<form class="ild-gate__form" method="post" novalidate>
		<?php wp_nonce_field( ILD_Gate::ACTION, ILD_Gate::NONCE ); ?>

		<?php // Carried so the server can re-run the analysis after consent. ?>
		<input type="hidden" name="ild_list" value="<?php echo esc_attr( $carry_list ); ?>" />
		<input type="hidden" name="ild_page_id" value="<?php echo esc_attr( $carry_page ); ?>" />
		<input type="hidden" name="ild_consent_text" value="<?php echo esc_attr( $consent_text ); ?>" />
		<?php // The exact page, filled in by the script at submit time. ?>
		<input type="hidden" name="ild_source" value="" data-ild-gate-source />

		<?php // Honeypot: hidden from people, tempting to bots. Must stay empty. ?>
		<div class="ild-hp" aria-hidden="true">
			<label for="<?php echo esc_attr( $guid ); ?>-hp"><?php echo esc_html( ILD_Phrases::honeypot_label() ); ?></label>
			<input type="text" id="<?php echo esc_attr( $guid ); ?>-hp" name="ild_gate_hp" tabindex="-1" autocomplete="off" value="" />
		</div>

		<div class="ild-gate__field">
			<label class="ild-gate__label" for="<?php echo esc_attr( $guid ); ?>-email"><?php echo esc_html( ILD_Phrases::gate_email_label() ); ?></label>
			<input
				class="ild-gate__email"
				type="email"
				id="<?php echo esc_attr( $guid ); ?>-email"
				name="ild_email"
				placeholder="<?php echo esc_attr( ILD_Phrases::gate_email_placeholder() ); ?>"
				autocomplete="email"
				required
			/>
		</div>

		<div class="ild-gate__consent">
			<label class="ild-gate__consent-label">
				<input class="ild-gate__checkbox" type="checkbox" name="ild_consent" value="yes" data-ild-gate-consent required />
				<span class="ild-gate__consent-text"><?php echo esc_html( $consent_text ); ?></span>
			</label>
		</div>

		<div class="ild-gate__actions">
			<button type="submit" class="ild-button ild-gate__submit" data-ild-gate-submit disabled aria-describedby="<?php echo esc_attr( $guid ); ?>-note">
				<?php echo esc_html( ILD_Phrases::gate_submit() ); ?>
			</button>
			<p class="ild-gate__note" id="<?php echo esc_attr( $guid ); ?>-note" data-ild-gate-note><?php echo esc_html( ILD_Phrases::gate_consent_required() ); ?></p>
			<p class="ild-gate__error" role="alert" data-ild-gate-error hidden></p>
		</div>

		<p class="ild-gate__legal">
			<span class="ild-gate__privacy">
				<?php echo esc_html( ILD_Phrases::gate_privacy_prefix() ); ?>
				<?php if ( $privacy_url ) : ?>
					<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( ILD_Phrases::gate_privacy_link_text() ); ?></a>.
				<?php else : ?>
					<?php echo esc_html( ILD_Phrases::gate_privacy_link_text() ); ?>.
				<?php endif; ?>
			</span>
			<span class="ild-gate__unsubscribe"><?php echo esc_html( ILD_Phrases::gate_unsubscribe() ); ?></span>
		</p>
	</form>
</div>
