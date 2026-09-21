<?php
/**
 * The Product Ingredients Elementor widget.
 *
 * Displays the ingredients of an Apotheca product — read from the product's
 * "ingredients" field — with the explanation of each pulled from the ingredient
 * library, exactly as the decoder shows an entry. It offers display controls for
 * the order (bottle order, alphabetical, grouped by family or by role), which
 * fields to show (each toggleable), and whether each ingredient is expandable or
 * shown open. Every visible element carries its own style controls.
 *
 * Built for optimized markup: a single wrapper div, selectors scoped to the widget
 * instance ({{WRAPPER}}) that never rely on Elementor's container divs, and
 * registered into the KDNA Tools category.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Background;

/**
 * The widget.
 */
class ILD_Product_Ingredients_Widget extends \Elementor\Widget_Base {

	/**
	 * The widget's machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ild_product_ingredients';
	}

	/**
	 * The widget's display title in the editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Product Ingredients', 'ingredient-list-decoder' );
	}

	/**
	 * The widget's editor icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-bullet-list';
	}

	/**
	 * The categories this widget belongs to.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( ILD_Elementor::CATEGORY );
	}

	/**
	 * Search keywords for the editor's widget panel.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'ingredient', 'product', 'inci', 'skincare', 'apotheca', 'list' );
	}

	/**
	 * The style handles this widget depends on.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( ILD_Products::STYLE );
	}

	/**
	 * Drop Elementor's extra inner wrapper when optimized markup is on.
	 *
	 * @return bool
	 */
	public function has_widget_inner_wrapper(): bool {
		if (
			class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->experiments )
			&& method_exists( \Elementor\Plugin::$instance->experiments, 'is_feature_active' )
		) {
			return ! \Elementor\Plugin::$instance->experiments->is_feature_active( 'e_optimized_markup' );
		}

		return true;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Controls
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Register every control, content then style.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->section_source();
		$this->section_display();
		$this->section_heading();

		$this->style_container();
		$this->style_product_heading();
		$this->style_group_heading();
		$this->style_item();
		$this->style_dividers();
		$this->style_name();
		$this->style_badges();
		$this->style_description();
		$this->style_detail();
		$this->style_toggle();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Content sections
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Which product to read, and from which field.
	 *
	 * @return void
	 */
	private function section_source() {
		$this->start_controls_section(
			'section_source',
			array( 'label' => __( 'Product', 'ingredient-list-decoder' ) )
		);

		$this->add_control(
			'source',
			array(
				'label'   => __( 'Show ingredients for', 'ingredient-list-decoder' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'current',
				'options' => array(
					'current' => __( 'The current product (this page)', 'ingredient-list-decoder' ),
					'pick'    => __( 'A product I choose', 'ingredient-list-decoder' ),
					'all'     => __( 'All products', 'ingredient-list-decoder' ),
				),
			)
		);

		$this->add_control(
			'product_id',
			array(
				'label'       => __( 'Choose a product', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::SELECT2,
				'options'     => ILD_Products::product_options(),
				'label_block' => true,
				'condition'   => array( 'source' => 'pick' ),
			)
		);

		$this->add_control(
			'all_products_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'Every product with an ingredient list will be shown, each under its own name. Order, fields, layout and styling below apply to all of them.', 'ingredient-list-decoder' ),
				'content_classes' => 'elementor-descriptor',
				'condition'       => array( 'source' => 'all' ),
			)
		);

		$this->add_control(
			'meta_key',
			array(
				'label'       => __( 'Ingredient field key', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => ILD_Products::META_KEY_DEFAULT,
				'description' => __( 'The meta key the ingredient list is stored under. Leave blank to use "ingredients". Change it only if your JetEngine field saves under a different key.', 'ingredient-list-decoder' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * How the ingredients are ordered, which fields show, and the layout.
	 *
	 * @return void
	 */
	private function section_display() {
		$this->start_controls_section(
			'section_display',
			array( 'label' => __( 'Display', 'ingredient-list-decoder' ) )
		);

		$this->add_control(
			'order',
			array(
				'label'   => __( 'Order', 'ingredient-list-decoder' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'inci',
				'options' => array(
					'inci'   => __( 'As on the bottle (INCI order)', 'ingredient-list-decoder' ),
					'alpha'  => __( 'Alphabetical', 'ingredient-list-decoder' ),
					'family' => __( 'By ingredient type (grouped)', 'ingredient-list-decoder' ),
					'role'   => __( 'By role (grouped)', 'ingredient-list-decoder' ),
				),
			)
		);

		$this->add_control(
			'show_group_headings',
			array(
				'label'        => __( 'Show group headings', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'When grouped by type or role, show the group name above each group.', 'ingredient-list-decoder' ),
				'condition'    => array( 'order' => array( 'family', 'role' ) ),
			)
		);

		$this->add_control(
			'layout',
			array(
				'label'     => __( 'Each ingredient', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'expandable',
				'options'   => array(
					'expandable' => __( 'Expandable — detail on tap', 'ingredient-list-decoder' ),
					'open'       => __( 'Open — all detail shown', 'ingredient-list-decoder' ),
				),
				'separator' => 'before',
			)
		);

		$this->add_control(
			'fields_heading',
			array(
				'label'     => __( 'Fields to show', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$fields = array(
			'show_description' => __( 'Description', 'ingredient-list-decoder' ),
			'show_role'        => __( 'Role', 'ingredient-list-decoder' ),
			'show_family'      => __( 'Family / type', 'ingredient-list-decoder' ),
			'show_evidence'    => __( 'Evidence note', 'ingredient-list-decoder' ),
			'show_founder'     => __( "Founder's take", 'ingredient-list-decoder' ),
			'show_aka'         => __( 'Also known as', 'ingredient-list-decoder' ),
		);
		foreach ( $fields as $key => $label ) {
			$this->add_control(
				$key,
				array(
					'label'        => $label,
					'type'         => Controls_Manager::SWITCHER,
					'default'      => 'yes',
					'return_value' => 'yes',
				)
			);
		}

		$this->add_control(
			'show_missing',
			array(
				'label'        => __( 'Show ingredients not in the library', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'Show the name of any ingredient not yet in the library (without a description), so the list still matches the bottle.', 'ingredient-list-decoder' ),
				'separator'    => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The optional heading above the list.
	 *
	 * @return void
	 */
	private function section_heading() {
		$this->start_controls_section(
			'section_heading',
			array( 'label' => __( 'Heading', 'ingredient-list-decoder' ) )
		);

		$this->add_control(
			'show_heading',
			array(
				'label'        => __( 'Show a heading', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
			)
		);

		$this->add_control(
			'heading_text',
			array(
				'label'       => __( 'Heading text', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Ingredients', 'ingredient-list-decoder' ),
				'description' => __( 'Leave blank to use the word "Ingredients". Use {product} to insert the product name.', 'ingredient-list-decoder' ),
				'condition'   => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'heading_tag',
			array(
				'label'     => __( 'Heading HTML tag', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'h2',
				'options'   => array(
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'div'  => 'div',
					'span' => 'span',
				),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Style helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Add a typography group and a text colour control for one selector.
	 *
	 * @param string $key       A unique control-name prefix.
	 * @param string $selector  The CSS selector, scoped later with {{WRAPPER}}.
	 * @param string $separator 'before' to draw a rule above the colour control, or ''.
	 * @return void
	 */
	private function add_text_style( $key, $selector, $separator = '' ) {
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $key . '_typography',
				'selector' => '{{WRAPPER}} ' . $selector,
			)
		);

		$this->add_control(
			$key . '_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} ' . $selector => 'color: {{VALUE}};',
				),
				'separator' => $separator,
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Style sections
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The outer container.
	 *
	 * @return void
	 */
	private function style_container() {
		$this->start_controls_section(
			'style_container',
			array(
				'label' => __( 'Container', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'container_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-products',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'container_border',
				'selector' => '{{WRAPPER}} .ild-products',
			)
		);

		$this->add_responsive_control(
			'container_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'container_shadow',
				'selector' => '{{WRAPPER}} .ild-products',
			)
		);

		$this->add_responsive_control(
			'container_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'container_margin',
			array(
				'label'      => __( 'Margin', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The optional heading above the list.
	 *
	 * @return void
	 */
	private function style_product_heading() {
		$this->start_controls_section(
			'style_product_heading',
			array(
				'label'     => __( 'Heading', 'ingredient-list-decoder' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_text_style( 'product_heading', '.ild-products__title' );

		$this->add_responsive_control(
			'product_heading_spacing',
			array(
				'label'      => __( 'Spacing below', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products__title' => 'margin: 0 0 {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The group headings shown when grouping by type or role.
	 *
	 * @return void
	 */
	private function style_group_heading() {
		$this->start_controls_section(
			'style_group_heading',
			array(
				'label'     => __( 'Group headings', 'ingredient-list-decoder' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'order' => array( 'family', 'role' ) ),
			)
		);

		$this->add_text_style( 'group_heading', '.ild-products__group-heading' );

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'group_heading_border',
				'selector'  => '{{WRAPPER}} .ild-products__group-heading',
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'group_heading_spacing',
			array(
				'label'      => __( 'Spacing', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products__group-heading' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'group_heading_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products__group-heading' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Each ingredient item: spacing, dividers, background.
	 *
	 * @return void
	 */
	private function style_item() {
		$this->start_controls_section(
			'style_item',
			array(
				'label' => __( 'Ingredient item', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// Columns, per device. The list is a grid, so this lays the ingredients out
		// in one, two or more columns at each breakpoint.
		$this->add_responsive_control(
			'columns',
			array(
				'label'          => __( 'Columns', 'ingredient-list-decoder' ),
				'type'           => Controls_Manager::SELECT,
				'default'        => '1',
				'tablet_default' => '1',
				'mobile_default' => '1',
				'options'        => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
					'5' => '5',
					'6' => '6',
				),
				'selectors'      => array(
					'{{WRAPPER}} .ild-products__list' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
				),
			)
		);

		$this->add_responsive_control(
			'row_gap',
			array(
				'label'      => __( 'Row gap', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products__list' => 'row-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'column_gap',
			array(
				'label'      => __( 'Column gap', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products__list' => 'column-gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'item_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-product-ing',
			)
		);

		$this->add_responsive_control(
			'item_padding',
			array(
				'label'      => __( 'Item padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'item_radius',
			array(
				'label'      => __( 'Item border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'item_border',
				'selector'  => '{{WRAPPER}} .ild-product-ing',
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Optional dividing lines between items.
	 *
	 * The line is a bottom border on every item except the last in each group, so
	 * there is no line after the final item of a group or of the whole list.
	 *
	 * @return void
	 */
	private function style_dividers() {
		$this->start_controls_section(
			'style_dividers',
			array(
				'label' => __( 'Lines between items', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// A line is drawn under every item, so the rhythm is even whatever the
		// column count or how many items a group has. The very last item of the
		// whole list never gets one (handled in CSS), and the toggle below can drop
		// the line at the end of each group too.
		$this->add_control(
			'divider_style',
			array(
				'label'     => __( 'Line style', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'none',
				'options'   => array(
					'none'   => __( 'None', 'ingredient-list-decoder' ),
					'solid'  => __( 'Solid', 'ingredient-list-decoder' ),
					'dashed' => __( 'Dashed', 'ingredient-list-decoder' ),
					'dotted' => __( 'Dotted', 'ingredient-list-decoder' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .ild-products__list .ild-product-ing' => 'border-bottom-style: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'divider_colour',
			array(
				'label'     => __( 'Line colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-products__list .ild-product-ing' => 'border-bottom-color: {{VALUE}};',
				),
				'condition' => array( 'divider_style!' => 'none' ),
			)
		);

		$this->add_responsive_control(
			'divider_width',
			array(
				'label'      => __( 'Line thickness', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
				'default'    => array( 'size' => 1, 'unit' => 'px' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-products__list .ild-product-ing' => 'border-bottom-width: {{SIZE}}{{UNIT}};',
				),
				'condition'  => array( 'divider_style!' => 'none' ),
			)
		);

		$this->add_responsive_control(
			'divider_spacing',
			array(
				'label'       => __( 'Space above the line', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'em', 'rem' ),
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'description' => __( 'Padding below each item, between its content and the line.', 'ingredient-list-decoder' ),
				'selectors'   => array(
					'{{WRAPPER}} .ild-products__list .ild-product-ing' => 'padding-bottom: {{SIZE}}{{UNIT}};',
				),
				'condition'   => array( 'divider_style!' => 'none' ),
			)
		);

		$this->add_control(
			'divider_hide_group_end',
			array(
				'label'        => __( 'No line at the end of each group', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'default'      => '',
				'return_value' => 'yes',
				'description'  => __( 'When grouped by type or role, drop the line under the last item of each group.', 'ingredient-list-decoder' ),
				'selectors'    => array(
					'{{WRAPPER}} .ild-products__list .ild-product-ing:last-child' => 'border-bottom-width: 0;',
				),
				'condition'    => array( 'divider_style!' => 'none' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The ingredient name, including the missing (name-only) state.
	 *
	 * @return void
	 */
	private function style_name() {
		$this->start_controls_section(
			'style_name',
			array(
				'label' => __( 'Ingredient name', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_text_style( 'name', '.ild-product-ing__name' );

		$this->add_control(
			'name_missing_colour',
			array(
				'label'     => __( 'Colour when not in the library', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-product-ing--missing .ild-product-ing__name' => 'color: {{VALUE}};',
				),
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The role and family badges and their labels.
	 *
	 * @return void
	 */
	private function style_badges() {
		$this->start_controls_section(
			'style_badges',
			array(
				'label' => __( 'Role & family badges', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_text_style( 'badge', '.ild-product-ing__badge' );

		$this->add_control(
			'badge_bg',
			array(
				'label'     => __( 'Badge background', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-product-ing__badge' => 'background: {{VALUE}};',
				),
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'badge_padding',
			array(
				'label'      => __( 'Badge padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing__badge' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'badge_radius',
			array(
				'label'      => __( 'Badge radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing__badge' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'meta_label_colour',
			array(
				'label'     => __( 'Label colour ("Role:", "Family:")', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-product-ing__meta-label' => 'color: {{VALUE}};',
				),
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The description paragraph.
	 *
	 * @return void
	 */
	private function style_description() {
		$this->start_controls_section(
			'style_description',
			array(
				'label' => __( 'Description', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_text_style( 'description', '.ild-product-ing__description' );

		$this->end_controls_section();
	}

	/**
	 * The detail fields: also-known-as, evidence and founder (labels and bodies).
	 *
	 * @return void
	 */
	private function style_detail() {
		$this->start_controls_section(
			'style_detail',
			array(
				'label' => __( 'Detail (AKA / evidence / founder)', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// Spacing around the whole detail block, so it does not sit against the name
		// above it or the next ingredient below it.
		$this->add_control(
			'detail_spacing_heading',
			array(
				'label' => __( 'Spacing', 'ingredient-list-decoder' ),
				'type'  => Controls_Manager::HEADING,
			)
		);

		$this->add_responsive_control(
			'detail_margin',
			array(
				'label'      => __( 'Margin', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing__detail' => 'margin: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'detail_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing__detail' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'detail_field_gap',
			array(
				'label'      => __( 'Gap between detail fields', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 48 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing__detail > .ild-product-ing__description' => 'margin-bottom: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .ild-product-ing__detail > .ild-product-ing__field'       => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'detail_label_heading',
			array(
				'label'     => __( 'Labels', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		$this->add_text_style( 'detail_label', '.ild-product-ing__detail-label' );

		$this->add_control(
			'detail_body_heading',
			array(
				'label'     => __( 'Body text', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);
		$this->add_text_style( 'detail_body', '.ild-product-ing__detail-body' );

		$this->end_controls_section();
	}

	/**
	 * The expander toggle (only visible in the expandable layout).
	 *
	 * @return void
	 */
	private function style_toggle() {
		$this->start_controls_section(
			'style_toggle',
			array(
				'label'     => __( 'Expander', 'ingredient-list-decoder' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => array( 'layout' => 'expandable' ),
			)
		);

		$this->add_control(
			'toggle_icon',
			array(
				'label'       => __( 'Expander icon (closed)', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::ICONS,
				'description' => __( 'The icon shown while an ingredient is closed. Leave empty for the default chevron.', 'ingredient-list-decoder' ),
				'skin'        => 'inline',
			)
		);

		$this->add_control(
			'toggle_icon_active',
			array(
				'label'       => __( 'Expander icon (open)', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::ICONS,
				'description' => __( 'The icon shown while an ingredient is open (e.g. a minus). Leave empty and the closed icon simply rotates when open.', 'ingredient-list-decoder' ),
				'skin'        => 'inline',
			)
		);

		$this->add_responsive_control(
			'toggle_icon_size',
			array(
				'label'      => __( 'Icon size', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 6, 'max' => 60 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-product-ing__toggle-icon'     => 'font-size: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .ild-product-ing__toggle-icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .ild-product-ing__toggle-icon--chevron' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_text_style( 'toggle', '.ild-product-ing__toggle', 'before' );

		$this->add_control(
			'toggle_icon_colour',
			array(
				'label'     => __( 'Icon colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					// The default chevron is drawn with borders; a chosen icon uses
					// colour (icon fonts) or fill (SVG).
					'{{WRAPPER}} .ild-product-ing__toggle-icon--chevron' => 'border-color: {{VALUE}};',
					'{{WRAPPER}} .ild-product-ing__toggle-icon'          => 'color: {{VALUE}};',
					'{{WRAPPER}} .ild-product-ing__toggle-icon svg'      => 'fill: {{VALUE}};',
				),
				'separator' => 'before',
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Render
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Render the widget on the front end (and in the editor preview).
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$source   = isset( $settings['source'] ) ? $settings['source'] : 'current';

		// The build arguments and the display options are the same for every product,
		// so work them out once.
		$build_args = array(
			'meta_key'     => isset( $settings['meta_key'] ) ? $settings['meta_key'] : '',
			'order'        => isset( $settings['order'] ) ? $settings['order'] : 'inci',
			'show_missing' => ( 'yes' === ( isset( $settings['show_missing'] ) ? $settings['show_missing'] : 'yes' ) ),
		);

		$opts = array(
			'layout'         => ( isset( $settings['layout'] ) && 'open' === $settings['layout'] ) ? 'open' : 'expandable',
			'group_headings' => ( 'yes' === ( isset( $settings['show_group_headings'] ) ? $settings['show_group_headings'] : 'yes' ) ),
			'heading'        => '',
			'heading_tag'    => isset( $settings['heading_tag'] ) ? $settings['heading_tag'] : 'h2',
			'toggle'         => array(
				'icon'   => $this->icon_html( isset( $settings['toggle_icon'] ) ? $settings['toggle_icon'] : array() ),
				'active' => $this->icon_html( isset( $settings['toggle_icon_active'] ) ? $settings['toggle_icon_active'] : array() ),
			),
			'fields'         => array(
				'description' => ( 'yes' === ( isset( $settings['show_description'] ) ? $settings['show_description'] : 'yes' ) ),
				'role'        => ( 'yes' === ( isset( $settings['show_role'] ) ? $settings['show_role'] : 'yes' ) ),
				'family'      => ( 'yes' === ( isset( $settings['show_family'] ) ? $settings['show_family'] : 'yes' ) ),
				'evidence'    => ( 'yes' === ( isset( $settings['show_evidence'] ) ? $settings['show_evidence'] : 'yes' ) ),
				'founder'     => ( 'yes' === ( isset( $settings['show_founder'] ) ? $settings['show_founder'] : 'yes' ) ),
				'aka'         => ( 'yes' === ( isset( $settings['show_aka'] ) ? $settings['show_aka'] : 'yes' ) ),
			),
		);

		// All products: render each in turn under its own name, reusing one library
		// index so the whole page is built from a single library query.
		if ( 'all' === $source ) {
			$this->render_all( $build_args, $opts );
			return;
		}

		// A single product, chosen or from the current page.
		if ( 'pick' === $source ) {
			$product_id = isset( $settings['product_id'] ) ? (int) $settings['product_id'] : 0;
		} else {
			$product_id = (int) get_queried_object_id();
			if ( $product_id <= 0 ) {
				$product_id = (int) get_the_ID();
			}
		}

		if ( $product_id <= 0 ) {
			if ( $this->is_edit_mode() ) {
				echo '<div class="ild-products ild-products--placeholder"><p>' . esc_html__( 'Choose a product in the widget settings, or place this widget on a product page, to see its ingredients here.', 'ingredient-list-decoder' ) . '</p></div>';
			}
			return;
		}

		$view = ILD_Products::build( $product_id, $build_args );

		// The heading text, with {product} filled in.
		if ( 'yes' === ( isset( $settings['show_heading'] ) ? $settings['show_heading'] : '' ) ) {
			$raw            = ! empty( $settings['heading_text'] ) ? $settings['heading_text'] : __( 'Ingredients', 'ingredient-list-decoder' );
			$opts['heading'] = str_replace( '{product}', $view['product_name'], $raw );
		}

		echo ILD_Shortcode::render_named_template( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped template markup.
			'product-ingredients',
			array(
				'view' => $view,
				'opts' => $opts,
			)
		);
	}

	/**
	 * Render every product with an ingredient list, each under its own name.
	 *
	 * Builds the library index once and reuses it for every product, so the whole
	 * page costs one library query rather than one per product. Products with no
	 * ingredient list are skipped.
	 *
	 * @param array $build_args The build arguments (meta_key, order, show_missing).
	 * @param array $opts       The base display options (heading filled in per product).
	 * @return void
	 */
	private function render_all( $build_args, $opts ) {
		$ids   = ILD_Products::product_ids();
		$index = ILD_Matcher::build_index();

		$html = '';
		foreach ( $ids as $product_id ) {
			$view = ILD_Products::build( $product_id, $build_args, $index );
			if ( empty( $view['total'] ) ) {
				continue;
			}

			// Each product is headed by its own name.
			$opts['heading'] = $view['product_name'];

			$html .= ILD_Shortcode::render_named_template(
				'product-ingredients',
				array(
					'view' => $view,
					'opts' => $opts,
				)
			);
		}

		if ( '' === $html ) {
			if ( $this->is_edit_mode() ) {
				echo '<div class="ild-products ild-products--placeholder"><p>' . esc_html__( 'No products have an ingredient list yet. Add one to a product\'s "ingredients" field to see it here.', 'ingredient-list-decoder' ) . '</p></div>';
			}
			return;
		}

		echo '<div class="ild-products-all">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped template markup.
	}

	/**
	 * Render an Elementor icon control's value to HTML, or '' when none is set.
	 *
	 * @param array $icon The icon control value ({ value, library }).
	 * @return string The icon markup, or ''.
	 */
	private function icon_html( $icon ) {
		if ( empty( $icon ) || empty( $icon['value'] ) || ! class_exists( '\Elementor\Icons_Manager' ) ) {
			return '';
		}

		ob_start();
		\Elementor\Icons_Manager::render_icon( $icon, array( 'aria-hidden' => 'true' ) );
		return (string) ob_get_clean();
	}

	/**
	 * Whether Elementor is currently in edit mode.
	 *
	 * @return bool
	 */
	private function is_edit_mode() {
		return (
			class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& method_exists( \Elementor\Plugin::$instance->editor, 'is_edit_mode' )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode()
		);
	}
}
