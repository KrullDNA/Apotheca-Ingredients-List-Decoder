<?php
/**
 * The Product Ingredients widget's markup.
 *
 * Renders a product's ingredients — each linked to its library entry — in the order
 * and with the fields the widget was set to. Carries no wording of its own beyond
 * ILD_Phrases and only places and escapes the view model.
 *
 * Expects:
 *   $view (array) from ILD_Products::build()  — product_name, groups[], grouped, …
 *   $opts (array) — layout, group_headings, heading, heading_tag, fields[]
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$view = isset( $view ) ? $view : array();
$opts = isset( $opts ) ? $opts : array();

$groups = isset( $view['groups'] ) && is_array( $view['groups'] ) ? $view['groups'] : array();
if ( empty( $groups ) ) {
	return;
}

$fields         = isset( $opts['fields'] ) ? $opts['fields'] : array();
$layout         = isset( $opts['layout'] ) ? $opts['layout'] : 'expandable';
$group_headings = ! empty( $opts['group_headings'] ) && ! empty( $view['grouped'] );
$none           = ILD_Phrases::row_none();

// The chosen expander icons (already rendered to safe markup by the widget). When
// no base icon is chosen, the template falls back to the default CSS chevron.
$toggle        = isset( $opts['toggle'] ) ? $opts['toggle'] : array();
$toggle_icon   = isset( $toggle['icon'] ) ? $toggle['icon'] : '';
$toggle_active = isset( $toggle['active'] ) ? $toggle['active'] : '';
$toggle_custom = ( '' !== $toggle_icon );

// Allowed heading tags, so a setting can never inject markup.
$allowed_tags = array( 'h2', 'h3', 'h4', 'div', 'span' );
$heading_tag  = ( isset( $opts['heading_tag'] ) && in_array( $opts['heading_tag'], $allowed_tags, true ) ) ? $opts['heading_tag'] : 'h2';
?>
<div class="ild-products ild-products--<?php echo esc_attr( $layout ); ?>">

	<?php if ( ! empty( $opts['heading'] ) ) : ?>
		<<?php echo esc_attr( $heading_tag ); ?> class="ild-products__title"><?php echo esc_html( $opts['heading'] ); ?></<?php echo esc_attr( $heading_tag ); ?>>
	<?php endif; ?>

	<?php foreach ( $groups as $group ) : ?>
		<?php if ( $group_headings && ! empty( $group['heading'] ) ) : ?>
			<div class="ild-products__group-heading"><?php echo esc_html( $group['heading'] ); ?></div>
		<?php endif; ?>

		<ol class="ild-products__list">
			<?php foreach ( $group['rows'] as $row ) : ?>
				<?php
				$is_missing = ( isset( $row['status'] ) && 'missing' === $row['status'] );
				$name       = isset( $row['name'] ) ? $row['name'] : '';

				// A missing row shows its name only.
				if ( $is_missing ) :
					?>
					<li class="ild-product-ing ild-product-ing--missing">
						<div class="ild-product-ing__head">
							<span class="ild-product-ing__name"><?php echo esc_html( $name ); ?></span>
						</div>
					</li>
					<?php
					continue;
				endif;

				// Which badges and detail fields this row actually has.
				$roles_text  = isset( $row['roles_text'] ) ? $row['roles_text'] : $none;
				$family_text = isset( $row['family_text'] ) ? $row['family_text'] : $none;
				$has_role    = ! empty( $fields['role'] ) && '' !== $roles_text && $none !== $roles_text;
				$has_family  = ! empty( $fields['family'] ) && '' !== $family_text && $none !== $family_text;
				$has_badges  = ( $has_role || $has_family );

				$description = isset( $row['description'] ) ? trim( (string) $row['description'] ) : '';
				$aka_text    = isset( $row['aka_text'] ) ? trim( (string) $row['aka_text'] ) : '';
				$evidence    = isset( $row['evidence'] ) ? trim( (string) $row['evidence'] ) : '';
				$founder     = isset( $row['founder'] ) ? trim( (string) $row['founder'] ) : '';

				$has_description = ! empty( $fields['description'] ) && '' !== $description;
				$has_aka         = ! empty( $fields['aka'] ) && '' !== $aka_text;
				$has_evidence    = ! empty( $fields['evidence'] ) && '' !== $evidence;
				$has_founder     = ! empty( $fields['founder'] ) && '' !== $founder;
				$has_detail      = ( $has_description || $has_aka || $has_evidence || $has_founder );

				// Expandable only when there is something to reveal.
				$expandable = ( 'expandable' === $layout && $has_detail );
				?>

				<?php if ( $expandable ) : ?>
					<li class="ild-product-ing">
						<details class="ild-product-ing__box">
							<summary class="ild-product-ing__head">
								<span class="ild-product-ing__name"><?php echo esc_html( $name ); ?></span>
								<?php if ( $has_badges ) : ?>
									<?php require ILD_PLUGIN_DIR . 'templates/partials/product-badges.php'; ?>
								<?php endif; ?>
								<span class="ild-product-ing__toggle">
									<?php
									$has_open = ( '' !== $toggle_active );

									// The closed-state icon: the chosen icon, or the default chevron.
									// In swap mode (an open icon is set) it carries --base so the CSS
									// hides it when open; on its own it rotates when open.
									if ( $toggle_custom ) {
										$closed_class = 'ild-product-ing__toggle-icon ild-product-ing__toggle-icon--custom ild-product-ing__toggle-icon--base';
										$closed_class .= $has_open ? '' : ' ild-product-ing__toggle-icon--rotates';
										printf(
											'<span class="%s" aria-hidden="true">%s</span>',
											esc_attr( $closed_class ),
											$toggle_icon // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon markup from Elementor's Icons_Manager, already safe.
										);
									} else {
										$closed_class = 'ild-product-ing__toggle-icon ild-product-ing__toggle-icon--chevron';
										$closed_class .= $has_open ? ' ild-product-ing__toggle-icon--base' : '';
										printf( '<span class="%s" aria-hidden="true"></span>', esc_attr( $closed_class ) );
									}

									// The open-state icon, shown only while open.
									if ( $has_open ) {
										printf(
											'<span class="ild-product-ing__toggle-icon ild-product-ing__toggle-icon--custom ild-product-ing__toggle-icon--active" aria-hidden="true">%s</span>',
											$toggle_active // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icon markup from Elementor's Icons_Manager, already safe.
										);
									}
									?>
								</span>
							</summary>
							<div class="ild-product-ing__detail">
								<?php require ILD_PLUGIN_DIR . 'templates/partials/product-detail.php'; ?>
							</div>
						</details>
					</li>
				<?php else : ?>
					<li class="ild-product-ing">
						<div class="ild-product-ing__head">
							<span class="ild-product-ing__name"><?php echo esc_html( $name ); ?></span>
							<?php if ( $has_badges ) : ?>
								<?php require ILD_PLUGIN_DIR . 'templates/partials/product-badges.php'; ?>
							<?php endif; ?>
						</div>
						<?php if ( $has_detail ) : ?>
							<div class="ild-product-ing__detail">
								<?php require ILD_PLUGIN_DIR . 'templates/partials/product-detail.php'; ?>
							</div>
						<?php endif; ?>
					</li>
				<?php endif; ?>

			<?php endforeach; ?>
		</ol>
	<?php endforeach; ?>

</div>
