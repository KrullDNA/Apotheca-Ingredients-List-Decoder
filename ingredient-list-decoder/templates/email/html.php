<?php
/**
 * The branded result email, as table-based HTML.
 *
 * Class names carry the styling; ILD_Email_Inliner moves the rules onto each
 * element after this renders, and the media queries in the head are left for
 * clients that honour them. The breakdown is flattened — email cannot expand
 * and collapse — so every description is shown in full.
 *
 * Expects: $view (array) result view model, $opts (array) resolved options,
 *          $mq (string) media-query CSS for the head.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view        = isset( $view ) ? $view : array();
$opts        = isset( $opts ) ? $opts : array();
$mq          = isset( $mq ) ? $mq : '';
$s           = isset( $opts['style'] ) ? $opts['style'] : array();
$summary     = isset( $view['summary'] ) ? $view['summary'] : array();
$caveat      = isset( $view['summary_caveat'] ) ? $view['summary_caveat'] : '';
$ingredients = isset( $view['ingredients'] ) ? $view['ingredients'] : array();
$readnext    = isset( $view['readnext'] ) && is_array( $view['readnext'] ) ? $view['readnext'] : array();
$width       = isset( $s['container_width'] ) ? (int) $s['container_width'] : 600;
$body_bg     = isset( $s['body_bg'] ) ? $s['body_bg'] : '#f0efe9';
$container_bg = isset( $s['container_bg'] ) ? $s['container_bg'] : '#ffffff';
$header_bg   = isset( $s['header_bg'] ) ? $s['header_bg'] : '#1f3d2b';
?><!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<title><?php echo esc_html( ILD_Phrases::email_subject_default() ); ?></title>
	<style type="text/css">
		body { margin:0; padding:0; }
		img { border:0; outline:none; text-decoration:none; }
		table { border-collapse:collapse; }
		a { text-decoration:underline; }
		<?php echo $mq; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin-built media-query CSS. ?>
	</style>
</head>
<body class="ild-e-body" bgcolor="<?php echo esc_attr( $body_bg ); ?>">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php echo esc_attr( $body_bg ); ?>">
		<tr>
			<td align="center" style="padding:24px 12px;">
				<table role="presentation" class="ild-e-container" width="<?php echo esc_attr( $width ); ?>" cellpadding="0" cellspacing="0" border="0" bgcolor="<?php echo esc_attr( $container_bg ); ?>" style="width:<?php echo esc_attr( $width ); ?>px; max-width:100%;">

					<?php // Header: logo, or the brand name as a fallback. ?>
					<tr>
						<td class="ild-e-header" bgcolor="<?php echo esc_attr( $header_bg ); ?>" align="center">
							<?php if ( ! empty( $opts['logo'] ) ) : ?>
								<img class="ild-e-logo" src="<?php echo esc_url( $opts['logo'] ); ?>" width="<?php echo esc_attr( (int) $opts['logo_width'] ); ?>" alt="<?php echo esc_attr( $opts['brand'] ); ?>" style="max-width:<?php echo esc_attr( (int) $opts['logo_width'] ); ?>px; height:auto;" />
							<?php else : ?>
								<span style="color:#ffffff; font-size:20px; font-weight:bold;"><?php echo esc_html( $opts['brand'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>

					<tr>
						<td class="ild-e-content">

							<?php // Intro. ?>
							<p class="ild-e-intro"><?php echo nl2br( esc_html( $opts['intro'] ) ); ?></p>

							<?php // The summary. ?>
							<h1 class="ild-e-h1"><?php echo esc_html( ILD_Phrases::heading_summary() ); ?></h1>
							<?php foreach ( $summary as $point ) : ?>
								<?php $text = is_array( $point ) ? ( isset( $point['text'] ) ? $point['text'] : '' ) : $point; ?>
								<p class="ild-e-p"><?php echo esc_html( $text ); ?></p>
							<?php endforeach; ?>
							<?php if ( '' !== $caveat ) : ?>
								<p class="ild-e-caveat"><?php echo esc_html( $caveat ); ?></p>
							<?php endif; ?>

							<?php // The full breakdown, flattened. ?>
							<h2 class="ild-e-h2"><?php echo esc_html( ILD_Phrases::heading_ingredients() ); ?></h2>
							<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
								<?php foreach ( $ingredients as $row ) : ?>
									<tr>
										<td class="ild-e-ing">
											<span class="ild-e-ing-name"><?php echo esc_html( $row['position'] . '. ' . $row['label'] ); ?></span>
											<?php if ( 'matched' === $row['kind'] ) : ?>
												<br />
												<span class="ild-e-ing-meta"><?php echo esc_html( ILD_Phrases::row_role_label() . ': ' . $row['roles_text'] . '  ·  ' . ILD_Phrases::row_family_label() . ': ' . $row['family_text'] ); ?></span>
												<?php if ( '' !== $row['description'] ) : ?>
													<p class="ild-e-ing-desc"><?php echo esc_html( $row['description'] ); ?></p>
												<?php endif; ?>
											<?php else : ?>
												<br />
												<span class="ild-e-ing-meta"><?php echo esc_html( $row['status_text'] ); ?></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</table>

							<?php // Read-next articles with thumbnails. ?>
							<?php if ( ! empty( $readnext ) ) : ?>
								<h2 class="ild-e-h2"><?php echo esc_html( $opts['read_next_heading'] ); ?></h2>
								<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
									<?php foreach ( $readnext as $card ) : ?>
										<tr>
											<td class="ild-e-rn-card">
												<?php if ( ! empty( $card['thumb'] ) ) : ?>
													<a href="<?php echo esc_url( $card['url'] ); ?>"><img src="<?php echo esc_url( $card['thumb'] ); ?>" width="200" alt="" style="max-width:200px; height:auto; border-radius:6px; margin-bottom:8px;" /></a><br />
												<?php endif; ?>
												<a class="ild-e-rn-title" href="<?php echo esc_url( $card['url'] ); ?>"><?php echo esc_html( $card['title'] ); ?></a>
												<?php if ( ! empty( $card['excerpt'] ) ) : ?>
													<p class="ild-e-rn-excerpt"><?php echo esc_html( $card['excerpt'] ); ?></p>
												<?php endif; ?>
												<p style="margin:10px 0 0 0;">
													<a class="ild-e-button" href="<?php echo esc_url( $card['url'] ); ?>"><?php echo esc_html( $opts['read_more_label'] ); ?></a>
												</p>
											</td>
										</tr>
									<?php endforeach; ?>
								</table>
							<?php endif; ?>

							<?php // Sign-off. ?>
							<p class="ild-e-p" style="margin-top:20px;"><?php echo nl2br( esc_html( $opts['signoff'] ) ); ?></p>

						</td>
					</tr>

					<?php // Footer: the editable line, privacy and a working unsubscribe. ?>
					<tr>
						<td class="ild-e-footer" bgcolor="<?php echo esc_attr( $container_bg ); ?>">
							<?php echo nl2br( esc_html( $opts['footer'] ) ); ?>
							<br />
							<?php if ( ! empty( $opts['privacy_url'] ) ) : ?>
								<a class="ild-e-footer-link" href="<?php echo esc_url( $opts['privacy_url'] ); ?>"><?php echo esc_html( $opts['privacy_label'] ); ?></a>
								&nbsp;·&nbsp;
							<?php endif; ?>
							<a class="ild-e-footer-link" href="<?php echo esc_url( $opts['unsubscribe_url'] ); ?>"><?php echo esc_html( $opts['unsubscribe_label'] ); ?></a>
						</td>
					</tr>

				</table>
			</td>
		</tr>
	</table>
</body>
</html>
