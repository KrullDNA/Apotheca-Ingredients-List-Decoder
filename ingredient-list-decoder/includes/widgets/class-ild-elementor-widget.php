<?php
/**
 * The Ingredient List Decoder Elementor widget.
 *
 * A native Elementor widget that wraps the very same rendering the shortcode
 * uses (ILD_Shortcode::render_tool), so wording and markup never drift. On top
 * of that it exposes the full set of style controls from section 11 of the brief
 * — groups A, D, E, F, G, H and K — with responsive values on every typography
 * and spacing control.
 *
 * The controls emit CSS scoped to the widget instance ({{WRAPPER}}), and the
 * AJAX result fragment lands inside that wrapper, so a designer's styling reaches
 * the summary, the ingredient rows and every state as well as the form itself.
 *
 * Built for optimized markup: a single wrapper div, no reliance on Elementor's
 * container divs in the selectors, and registered into the KDNA Tools category.
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
class ILD_Elementor_Widget extends \Elementor\Widget_Base {

	/**
	 * The widget's machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'ild_decoder';
	}

	/**
	 * The widget's display title in the editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Ingredient List Decoder', 'ingredient-list-decoder' );
	}

	/**
	 * The widget's editor icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-search';
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
		return array( 'ingredient', 'skincare', 'inci', 'decoder', 'formula', 'apotheca' );
	}

	/**
	 * The style handles this widget depends on.
	 *
	 * Returning the shared handle means Elementor enqueues the base stylesheet
	 * only on pages where this widget is present.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( ILD_Shortcode::STYLE );
	}

	/**
	 * The script handles this widget depends on.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( ILD_Shortcode::SCRIPT );
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
	 * Register every control, content then style, group by group.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->controls_content();

		$this->controls_group_a_input();      // Group A: input & form.
		$this->controls_group_b_upload();     // Group B: photo upload / dropzone.
		$this->controls_group_c_verify();     // Group C: verification.
		$this->controls_group_d_buttons();    // Group D: buttons.
		$this->controls_group_e_summary();    // Group E: summary block.
		$this->controls_group_f_rows();       // Group F: ingredient rows.
		$this->controls_group_g_findings();   // Group G: findings & unmatched.
		$this->controls_group_h_states();     // Group H: states.
		$this->controls_group_i_readnext();   // Group I: read-next block.
		$this->controls_group_j_gate();       // Group J: email gate.
		$this->controls_group_k_global();     // Group K: global.
	}

	/**
	 * Content tab: the editor preview, and settings that are not pure styling.
	 *
	 * The tool's wording is managed centrally in one PHP file (so it can be
	 * edited in one place), which is why there are no text-content controls here.
	 *
	 * @return void
	 */
	private function controls_content() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Tool', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'ild_preview_state',
			array(
				'label'       => __( 'Preview state (editor only)', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'form',
				'options'     => array(
					'form'    => __( 'Form', 'ingredient-list-decoder' ),
					'loading' => __( 'Loading', 'ingredient-list-decoder' ),
					'result'  => __( 'Result', 'ingredient-list-decoder' ),
					'empty'   => __( 'Empty', 'ingredient-list-decoder' ),
					'error'   => __( 'Error', 'ingredient-list-decoder' ),
					'verify'  => __( 'Photo verification', 'ingredient-list-decoder' ),
					'gate'    => __( 'Email gate', 'ingredient-list-decoder' ),
				),
				'description' => __( 'Show a state in the editor so you can style it. This has no effect on the live page.', 'ingredient-list-decoder' ),
			)
		);

		$this->add_control(
			'ild_show_photo',
			array(
				'label'        => __( 'Enable photo upload', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'On', 'ingredient-list-decoder' ),
				'label_off'    => __( 'Off', 'ingredient-list-decoder' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Offer reading the list from a photo. Reading is free in the visitor\'s browser by default; add an Anthropic API key under Ingredient Decoder → Settings to also offer a more accurate AI reading. The whole photo feature can be switched off under Settings too.', 'ingredient-list-decoder' ),
			)
		);

		$this->add_control(
			'ild_wording_note',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => __( 'All wording is edited in one place (the plugin\'s phrases file), so the whole voice stays consistent. This panel is for styling.', 'ingredient-list-decoder' ),
				'content_classes' => 'elementor-descriptor',
			)
		);

		$this->add_control(
			'ild_primary_icon',
			array(
				'label' => __( 'Primary button icon', 'ingredient-list-decoder' ),
				'type'  => Controls_Manager::ICONS,
			)
		);

		// The two editable pieces of gate wording. The privacy link and the
		// unsubscribe line are deliberately not editable.
		$this->add_control(
			'ild_gate_wording_heading',
			array(
				'label'     => __( 'Email gate wording', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'ild_exchange_text',
			array(
				'label'       => __( 'Exchange text', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'default'     => ILD_Phrases::exchange_default(),
				'description' => __( 'Shown near the input and again at the gate, so the terms are visible before pasting.', 'ingredient-list-decoder' ),
			)
		);

		$this->add_control(
			'ild_consent_text',
			array(
				'label'       => __( 'Consent checkbox text', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'default'     => ILD_Phrases::consent_default(),
				'description' => __( 'Must cover both emailing the result and marketing from Apotheca®. Stored verbatim as the consent record.', 'ingredient-list-decoder' ),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group A — input container, labels, textarea, helpers, product field
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group A controls.
	 *
	 * @return void
	 */
	private function controls_group_a_input() {
		$this->start_controls_section(
			'section_input',
			array(
				'label' => __( 'A · Input & form', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// Input container.
		$this->add_control(
			'input_container_heading',
			array(
				'label' => __( 'Input container', 'ingredient-list-decoder' ),
				'type'  => Controls_Manager::HEADING,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'input_container_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-form',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'input_container_border',
				'selector' => '{{WRAPPER}} .ild-form',
			)
		);

		$this->add_responsive_control(
			'input_container_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-form' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'input_container_shadow',
				'selector' => '{{WRAPPER}} .ild-form',
			)
		);

		$this->add_responsive_control(
			'input_container_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-form' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		// Label.
		$this->add_control(
			'label_heading',
			array(
				'label'     => __( 'Field label', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'label_typography',
				'selector' => '{{WRAPPER}} .ild-label',
			)
		);

		$this->add_control(
			'label_colour',
			array(
				'label'     => __( 'Label colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-label' => 'color: {{VALUE}};',
				),
			)
		);

		// Textarea.
		$this->add_control(
			'textarea_heading',
			array(
				'label'     => __( 'Textarea', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'textarea_typography',
				'selector' => '{{WRAPPER}} .ild-textarea',
			)
		);

		$this->add_control(
			'textarea_colour',
			array(
				'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-textarea' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'textarea_placeholder_colour',
			array(
				'label'     => __( 'Placeholder colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-textarea::placeholder' => 'color: {{VALUE}}; opacity: 1;',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'textarea_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-textarea',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'textarea_border',
				'selector' => '{{WRAPPER}} .ild-textarea',
			)
		);

		$this->add_responsive_control(
			'textarea_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-textarea' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'textarea_min_height',
			array(
				'label'      => __( 'Minimum height', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'vh', 'em' ),
				'range'      => array( 'px' => array( 'min' => 40, 'max' => 500 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-textarea' => 'min-height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'textarea_focus_heading',
			array(
				'label' => __( 'Focus state', 'ingredient-list-decoder' ),
				'type'  => Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'textarea_focus_border_colour',
			array(
				'label'     => __( 'Focus border colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-textarea:focus' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'textarea_focus_shadow',
				'selector' => '{{WRAPPER}} .ild-textarea:focus',
			)
		);

		// Helper & character count.
		$this->add_control(
			'helper_heading',
			array(
				'label'     => __( 'Helper & character count', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'helper_typography',
				'selector' => '{{WRAPPER}} .ild-help, {{WRAPPER}} .ild-charcount',
			)
		);

		$this->add_control(
			'helper_colour',
			array(
				'label'     => __( 'Helper colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-help' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'charcount_colour',
			array(
				'label'     => __( 'Character count colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-charcount' => 'color: {{VALUE}};',
				),
			)
		);

		// Product field.
		$this->add_control(
			'product_heading',
			array(
				'label'     => __( 'Product-name field', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'product_hide',
			array(
				'label'        => __( 'Hide product-name field', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'Hidden', 'ingredient-list-decoder' ),
				'label_off'    => __( 'Shown', 'ingredient-list-decoder' ),
				'return_value' => 'yes',
				'default'      => '',
				'selectors'    => array(
					'{{WRAPPER}} .ild-field--product' => 'display: none;',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'      => 'product_typography',
				'selector'  => '{{WRAPPER}} .ild-product',
				'condition' => array( 'product_hide!' => 'yes' ),
			)
		);

		$this->add_control(
			'product_colour',
			array(
				'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-product' => 'color: {{VALUE}};',
				),
				'condition' => array( 'product_hide!' => 'yes' ),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'      => 'product_border',
				'selector'  => '{{WRAPPER}} .ild-product',
				'condition' => array( 'product_hide!' => 'yes' ),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group B — photo upload / dropzone
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group B controls.
	 *
	 * @return void
	 */
	private function controls_group_b_upload() {
		$this->start_controls_section(
			'section_upload',
			array(
				'label' => __( 'B · Photo upload', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'dropzone_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-dropzone',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'dropzone_border',
				'selector' => '{{WRAPPER}} .ild-dropzone',
			)
		);

		$this->add_responsive_control(
			'dropzone_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-dropzone' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'dropzone_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-dropzone' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		// Per-state background and border colour.
		$this->start_controls_tabs( 'dropzone_state_tabs' );

		$states = array(
			'normal'    => array( __( 'Normal', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-dropzone' ),
			'hover'     => array( __( 'Hover', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-dropzone:hover' ),
			'dragover'  => array( __( 'Drag-over', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-dropzone.is-dragover' ),
			'uploading' => array( __( 'Uploading', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-dropzone.is-uploading' ),
			'error'     => array( __( 'Error', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-dropzone.is-error' ),
		);

		foreach ( $states as $state => $info ) {
			list( $state_label, $state_selector ) = $info;

			$this->start_controls_tab( 'dropzone_tab_' . $state, array( 'label' => $state_label ) );

			$this->add_control(
				'dropzone_' . $state . '_bg',
				array(
					'label'     => __( 'Background', 'ingredient-list-decoder' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						$state_selector => 'background-color: {{VALUE}};',
					),
				)
			);

			$this->add_control(
				'dropzone_' . $state . '_border',
				array(
					'label'     => __( 'Border colour', 'ingredient-list-decoder' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						$state_selector => 'border-color: {{VALUE}};',
					),
				)
			);

			$this->end_controls_tab();
		}

		$this->end_controls_tabs();

		// Dropzone icon.
		$this->add_control(
			'dropzone_icon_heading',
			array(
				'label'     => __( 'Icon', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->icon_slot_controls( 'dropzone_icon', '{{WRAPPER}} .ild-dropzone__icon' );

		// Prompt & hint typography.
		$this->add_control(
			'dropzone_prompt_heading',
			array(
				'label'     => __( 'Prompt', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'dropzone_prompt_typography',
				'selector' => '{{WRAPPER}} .ild-dropzone__prompt',
			)
		);

		$this->add_control(
			'dropzone_prompt_colour',
			array(
				'label'     => __( 'Prompt colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-dropzone__prompt' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'dropzone_hint_heading',
			array(
				'label'     => __( 'Hint', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'dropzone_hint_typography',
				'selector' => '{{WRAPPER}} .ild-dropzone__hint',
			)
		);

		$this->add_control(
			'dropzone_hint_colour',
			array(
				'label'     => __( 'Hint colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-dropzone__hint' => 'color: {{VALUE}};',
				),
			)
		);

		// The two upload buttons ("Choose a photo" and "Take a photo"). They share
		// the .ild-dropzone__button class, so one control set styles both; the full
		// button control set gives typography, padding, radius, border and every
		// state, matching the other buttons.
		$this->button_controls( 'dropzone_button', __( 'Upload buttons', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-dropzone__button' );

		// Progress indicator.
		$this->add_control(
			'progress_heading',
			array(
				'label'     => __( 'Progress indicator', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'progress_colour',
			array(
				'label'     => __( 'Bar colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-photo__progress-bar' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'progress_track_colour',
			array(
				'label'     => __( 'Track colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-photo__progress' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'progress_height',
			array(
				'label'      => __( 'Height', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 2, 'max' => 24 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-photo__progress' => 'height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'progress_radius',
			array(
				'label'      => __( 'Corner radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 999 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-photo__progress' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Thumbnail.
		$this->add_control(
			'thumb_heading',
			array(
				'label'     => __( 'Thumbnail', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'thumb_size',
			array(
				'label'      => __( 'Size', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 48, 'max' => 320 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-verify__thumb' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'thumb_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-verify__thumb' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group C — verification
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group C controls.
	 *
	 * @return void
	 */
	private function controls_group_c_verify() {
		$this->start_controls_section(
			'section_verify',
			array(
				'label' => __( 'C · Verification', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'verify_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-verify',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'verify_border',
				'selector' => '{{WRAPPER}} .ild-verify',
			)
		);

		$this->add_responsive_control(
			'verify_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-verify' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'verify_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-verify' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'verify_heading_heading',
			array(
				'label'     => __( 'Heading', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'verify_heading_typography',
				'selector' => '{{WRAPPER}} .ild-verify__heading',
			)
		);

		$this->add_control(
			'verify_heading_colour',
			array(
				'label'     => __( 'Heading colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-verify__heading' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'verify_notice_heading',
			array(
				'label'     => __( 'Notice', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'verify_notice_typography',
				'selector' => '{{WRAPPER}} .ild-verify__notice',
			)
		);

		$this->add_control(
			'verify_notice_colour',
			array(
				'label'     => __( 'Notice colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-verify__notice' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'verify_field_heading',
			array(
				'label'     => __( 'Transcription field', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'verify_field_typography',
				'selector' => '{{WRAPPER}} .ild-verify__text',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'verify_field_bg',
				'types'    => array( 'classic' ),
				'selector' => '{{WRAPPER}} .ild-verify__text',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'verify_field_border',
				'selector' => '{{WRAPPER}} .ild-verify__text',
			)
		);

		// Confirm and retake buttons, styled independently.
		$this->button_controls( 'verify_confirm', __( 'Confirm button', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-verify__confirm' );
		$this->button_controls( 'verify_retake', __( 'Retake button', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-verify__retake' );

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group D — buttons (primary, secondary, text link)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group D controls.
	 *
	 * @return void
	 */
	private function controls_group_d_buttons() {
		$this->start_controls_section(
			'section_buttons',
			array(
				'label' => __( 'D · Buttons', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->button_controls( 'primary', __( 'Primary button', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-submit' );
		$this->button_controls( 'secondary', __( 'Secondary button', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-button--secondary' );
		$this->button_controls( 'link', __( 'Text-link button', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-button--link' );

		$this->end_controls_section();
	}

	/**
	 * The full control set for one button style.
	 *
	 * Typography, padding, radius, border, icon size and gap, plus normal, hover,
	 * focus, active and disabled states, plus a per-breakpoint full-width toggle.
	 *
	 * @param string $key      A short key, unique per button (primary/secondary/link).
	 * @param string $label    The heading shown for this button.
	 * @param string $selector The CSS selector for this button.
	 * @return void
	 */
	private function button_controls( $key, $label, $selector ) {
		$this->add_control(
			$key . '_button_heading',
			array(
				'label'     => $label,
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $key . '_button_typography',
				'selector' => $selector,
			)
		);

		$this->add_responsive_control(
			$key . '_button_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					$selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			$key . '_button_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					$selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			$key . '_button_icon_size',
			array(
				'label'      => __( 'Icon size', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 6, 'max' => 60 ) ),
				'selectors'  => array(
					$selector . ' .ild-submit__icon' => 'font-size: {{SIZE}}{{UNIT}};',
					$selector . ' .ild-submit__icon svg' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			$key . '_button_icon_gap',
			array(
				'label'      => __( 'Icon spacing', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array(
					$selector => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			$key . '_button_full_width',
			array(
				'label'                => __( 'Full width', 'ingredient-list-decoder' ),
				'type'                 => Controls_Manager::SELECT,
				'default'              => '',
				'options'              => array(
					''      => __( 'Auto', 'ingredient-list-decoder' ),
					'block' => __( 'Full width', 'ingredient-list-decoder' ),
				),
				'selectors_dictionary' => array(
					'block' => 'width: 100%; justify-content: center;',
				),
				'selectors'            => array(
					$selector => '{{VALUE}}',
				),
			)
		);

		// State tabs: normal / hover / focus / active / disabled.
		$this->start_controls_tabs( $key . '_button_tabs' );

		$states = array(
			'normal'   => array( __( 'Normal', 'ingredient-list-decoder' ), $selector ),
			'hover'    => array( __( 'Hover', 'ingredient-list-decoder' ), $selector . ':hover' ),
			'focus'    => array( __( 'Focus', 'ingredient-list-decoder' ), $selector . ':focus-visible' ),
			'active'   => array( __( 'Active', 'ingredient-list-decoder' ), $selector . ':active' ),
			'disabled' => array( __( 'Disabled', 'ingredient-list-decoder' ), $selector . ':disabled' ),
		);

		foreach ( $states as $state => $info ) {
			list( $state_label, $state_selector ) = $info;

			$this->start_controls_tab(
				$key . '_button_tab_' . $state,
				array( 'label' => $state_label )
			);

			$this->add_control(
				$key . '_button_' . $state . '_colour',
				array(
					'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						$state_selector => 'color: {{VALUE}};',
					),
				)
			);

			$this->add_group_control(
				Group_Control_Background::get_type(),
				array(
					'name'     => $key . '_button_' . $state . '_bg',
					'types'    => array( 'classic', 'gradient' ),
					'selector' => $state_selector,
				)
			);

			$this->add_control(
				$key . '_button_' . $state . '_border_colour',
				array(
					'label'     => __( 'Border colour', 'ingredient-list-decoder' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						$state_selector => 'border-color: {{VALUE}};',
					),
				)
			);

			$this->add_group_control(
				Group_Control_Box_Shadow::get_type(),
				array(
					'name'     => $key . '_button_' . $state . '_shadow',
					'selector' => $state_selector,
				)
			);

			$this->end_controls_tab();
		}

		$this->end_controls_tabs();

		$this->add_control(
			$key . '_button_transition',
			array(
				'label'      => __( 'Transition duration (ms)', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 1000 ) ),
				'selectors'  => array(
					$selector => 'transition-duration: {{SIZE}}ms;',
				),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group E — summary block
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group E controls.
	 *
	 * @return void
	 */
	private function controls_group_e_summary() {
		$this->start_controls_section(
			'section_summary',
			array(
				'label' => __( 'E · Summary block', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'summary_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-summary',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'summary_border',
				'selector' => '{{WRAPPER}} .ild-summary',
			)
		);

		$this->add_responsive_control(
			'summary_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-summary' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'summary_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-summary' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'summary_shadow',
				'selector' => '{{WRAPPER}} .ild-summary',
			)
		);

		$this->add_control(
			'summary_heading_heading',
			array(
				'label'     => __( 'Heading', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'summary_heading_typography',
				'selector' => '{{WRAPPER}} .ild-summary__heading',
			)
		);

		$this->add_control(
			'summary_heading_colour',
			array(
				'label'     => __( 'Heading colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-summary__heading' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'summary_body_heading',
			array(
				'label'     => __( 'Body', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'summary_body_typography',
				'selector' => '{{WRAPPER}} .ild-summary__point-text, {{WRAPPER}} .ild-summary__caveat',
			)
		);

		$this->add_control(
			'summary_body_colour',
			array(
				'label'     => __( 'Body colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-summary__point-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'summary_accent_colour',
			array(
				'label'     => __( 'Accent colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-summary' => '--ild-summary-accent: {{VALUE}};',
					'{{WRAPPER}} .ild-summary__caveat' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'summary_icon_heading',
			array(
				'label'     => __( 'Optional icon', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->icon_slot_controls( 'summary_icon', '{{WRAPPER}} .ild-summary__icon' );

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group F — ingredient rows
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group F controls.
	 *
	 * @return void
	 */
	private function controls_group_f_rows() {
		$this->start_controls_section(
			'section_rows',
			array(
				'label' => __( 'F · Ingredient rows', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'row_bg',
			array(
				'label'     => __( 'Row background', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'row_alt_bg',
			array(
				'label'     => __( 'Alternating row background', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient:nth-child(even)' => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_padding',
			array(
				'label'      => __( 'Row padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-ingredient' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'row_divider_colour',
			array(
				'label'     => __( 'Divider colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient' => 'border-bottom-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_divider_width',
			array(
				'label'      => __( 'Divider width', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 10 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-ingredient' => 'border-bottom-width: {{SIZE}}{{UNIT}}; border-bottom-style: solid;',
				),
			)
		);

		// Position number.
		$this->add_control(
			'row_pos_heading',
			array(
				'label'     => __( 'Position number', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'row_pos_typography',
				'selector' => '{{WRAPPER}} .ild-ingredient__pos',
			)
		);

		$this->add_control(
			'row_pos_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient__pos' => 'color: {{VALUE}};',
				),
			)
		);

		// INCI name.
		$this->add_control(
			'row_name_heading',
			array(
				'label'     => __( 'INCI name', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'row_name_typography',
				'selector' => '{{WRAPPER}} .ild-ingredient__name',
			)
		);

		$this->add_control(
			'row_name_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient__name' => 'color: {{VALUE}};',
				),
			)
		);

		// Badges.
		$this->badge_controls( 'role', __( 'Role badge', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-ingredient__role' );
		$this->badge_controls( 'family', __( 'Family badge', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-ingredient__family' );

		$this->add_responsive_control(
			'badge_gap',
			array(
				'label'      => __( 'Badge gap', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 40 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-ingredient__meta' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Expand icon.
		$this->add_control(
			'expand_icon_heading',
			array(
				'label'     => __( 'Expand icon', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'expand_icon_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient__expand-icon' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'expand_icon_size',
			array(
				'label'      => __( 'Size', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 4, 'max' => 30 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-ingredient__expand-icon' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'expand_icon_rotation',
			array(
				'label'      => __( 'Open rotation (deg)', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'range'      => array( 'px' => array( 'min' => -360, 'max' => 360 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-ingredient__detail[open] .ild-ingredient__expand-icon' => 'transform: rotate({{SIZE}}deg);',
				),
			)
		);

		// Expanded panel.
		$this->add_control(
			'panel_heading',
			array(
				'label'     => __( 'Expanded panel', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'panel_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-ingredient__detail-panel',
			)
		);

		$this->add_responsive_control(
			'panel_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-ingredient__detail-panel' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'panel_border',
				'selector' => '{{WRAPPER}} .ild-ingredient__detail-panel',
			)
		);

		// Description, evidence note, founder take.
		$this->add_control(
			'description_heading',
			array(
				'label'     => __( 'Description', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'selector' => '{{WRAPPER}} .ild-ingredient__description',
			)
		);

		$this->add_control(
			'description_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient__description' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'evidence_heading',
			array(
				'label'     => __( 'Evidence note', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'evidence_typography',
				'selector' => '{{WRAPPER}} .ild-ingredient__evidence-body',
			)
		);

		$this->add_control(
			'evidence_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient__evidence-body' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'founder_heading',
			array(
				'label'     => __( 'Founder take', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'founder_typography',
				'selector' => '{{WRAPPER}} .ild-ingredient__founder-body',
			)
		);

		$this->add_control(
			'founder_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient__founder-body' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'founder_accent_colour',
			array(
				'label'     => __( 'Accent border colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient__founder' => 'border-left-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'founder_accent_width',
			array(
				'label'      => __( 'Accent border width', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 12 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-ingredient__founder' => 'border-left-width: {{SIZE}}{{UNIT}}; border-left-style: solid;',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The control set for one badge (role or family).
	 *
	 * @param string $key      A short key, unique per badge.
	 * @param string $label    The heading shown.
	 * @param string $selector The CSS selector for the badge.
	 * @return void
	 */
	private function badge_controls( $key, $label, $selector ) {
		$this->add_control(
			$key . '_badge_heading',
			array(
				'label'     => $label,
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $key . '_badge_typography',
				'selector' => $selector,
			)
		);

		$this->add_control(
			$key . '_badge_colour',
			array(
				'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					$selector => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			$key . '_badge_bg',
			array(
				'label'     => __( 'Background', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					$selector => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			$key . '_badge_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					$selector => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			$key . '_badge_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					$selector => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group G — findings by confidence, and the unmatched states
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group G controls.
	 *
	 * @return void
	 */
	private function controls_group_g_findings() {
		$this->start_controls_section(
			'section_findings',
			array(
				'label' => __( 'G · Findings & unmatched', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// A findings block treatment (applied to the summary that carries them).
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'findings_block_bg',
				'types'    => array( 'classic' ),
				'selector' => '{{WRAPPER}} .ild-summary__point',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'findings_block_border',
				'selector' => '{{WRAPPER}} .ild-summary__point',
			)
		);

		// Per confidence level: typography, colour, optional icon.
		$levels = array(
			'high'   => __( 'High confidence', 'ingredient-list-decoder' ),
			'medium' => __( 'Medium confidence', 'ingredient-list-decoder' ),
			'low'    => __( 'Low confidence', 'ingredient-list-decoder' ),
		);

		foreach ( $levels as $level => $level_label ) {
			$point    = '{{WRAPPER}} .ild-summary__point--' . $level;
			$point_tx = $point . ' .ild-summary__point-text';
			$point_ic = $point . ' .ild-summary__point-icon';

			$this->add_control(
				'finding_' . $level . '_heading',
				array(
					'label'     => $level_label,
					'type'      => Controls_Manager::HEADING,
					'separator' => 'before',
				)
			);

			$this->add_group_control(
				Group_Control_Typography::get_type(),
				array(
					'name'     => 'finding_' . $level . '_typography',
					'selector' => $point_tx,
				)
			);

			$this->add_control(
				'finding_' . $level . '_colour',
				array(
					'label'     => __( 'Colour', 'ingredient-list-decoder' ),
					'type'      => Controls_Manager::COLOR,
					'selectors' => array(
						$point_tx => 'color: {{VALUE}};',
					),
				)
			);

			$this->icon_slot_controls( 'finding_' . $level . '_icon', $point_ic );
		}

		// Unmatched: suggestion.
		$this->add_control(
			'suggestion_heading',
			array(
				'label'     => __( 'Did-you-mean suggestion', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'suggestion_typography',
				'selector' => '{{WRAPPER}} .ild-ingredient--suggestion .ild-ingredient__status-text',
			)
		);

		$this->add_control(
			'suggestion_colour',
			array(
				'label'     => __( 'Link colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient--suggestion .ild-ingredient__status-text' => 'color: {{VALUE}};',
				),
			)
		);

		// Unmatched: not in library.
		$this->add_control(
			'notinlibrary_heading',
			array(
				'label'     => __( 'Not-in-library notice', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'notinlibrary_bg',
				'types'    => array( 'classic' ),
				'selector' => '{{WRAPPER}} .ild-ingredient--unknown .ild-ingredient__status',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'notinlibrary_border',
				'selector' => '{{WRAPPER}} .ild-ingredient--unknown .ild-ingredient__status',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'notinlibrary_typography',
				'selector' => '{{WRAPPER}} .ild-ingredient--unknown .ild-ingredient__status-text',
			)
		);

		$this->add_control(
			'notinlibrary_colour',
			array(
				'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-ingredient--unknown .ild-ingredient__status-text' => 'color: {{VALUE}};',
				),
			)
		);

		$this->icon_slot_controls( 'notinlibrary_icon', '{{WRAPPER}} .ild-ingredient--unknown .ild-ingredient__status-icon' );

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group H — states: loading, empty, error, rate limit
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group H controls.
	 *
	 * @return void
	 */
	private function controls_group_h_states() {
		$this->start_controls_section(
			'section_states',
			array(
				'label' => __( 'H · States', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// Loading.
		$this->add_control(
			'loading_heading',
			array(
				'label' => __( 'Loading', 'ingredient-list-decoder' ),
				'type'  => Controls_Manager::HEADING,
			)
		);

		$this->add_control(
			'loading_style',
			array(
				'label'   => __( 'Indicator', 'ingredient-list-decoder' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'spinner',
				'options' => array(
					'spinner'  => __( 'Spinner', 'ingredient-list-decoder' ),
					'skeleton' => __( 'Skeleton', 'ingredient-list-decoder' ),
				),
			)
		);

		$this->add_control(
			'loading_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-loading__spinner' => 'border-color: {{VALUE}}; border-top-color: transparent;',
					'{{WRAPPER}} .ild-loading__label'   => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'loading_size',
			array(
				'label'      => __( 'Spinner size', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 8, 'max' => 80 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-loading__spinner' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Empty.
		$this->add_control(
			'empty_heading',
			array(
				'label'     => __( 'Empty', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'empty_typography',
				'selector' => '{{WRAPPER}} .ild-empty .ild-state__message',
			)
		);

		$this->add_control(
			'empty_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-empty .ild-state__message' => 'color: {{VALUE}};',
				),
			)
		);

		$this->icon_slot_controls( 'empty_icon', '{{WRAPPER}} .ild-empty__icon' );

		// Error.
		$this->add_control(
			'error_heading',
			array(
				'label'     => __( 'Error', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'error_bg',
				'types'    => array( 'classic' ),
				'selector' => '{{WRAPPER}} .ild-error',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'error_border',
				'selector' => '{{WRAPPER}} .ild-error',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'error_typography',
				'selector' => '{{WRAPPER}} .ild-error .ild-state__message',
			)
		);

		$this->add_control(
			'error_colour',
			array(
				'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-error .ild-state__message' => 'color: {{VALUE}};',
				),
			)
		);

		// Rate limit (Stage 14 sets .ild-error--rate-limit; style it now).
		$this->add_control(
			'ratelimit_heading',
			array(
				'label'     => __( 'Rate-limit message', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'ratelimit_bg',
				'types'    => array( 'classic' ),
				'selector' => '{{WRAPPER}} .ild-error--rate-limit',
			)
		);

		$this->add_control(
			'ratelimit_colour',
			array(
				'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-error--rate-limit .ild-state__message' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group I — read-next block
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group I controls.
	 *
	 * @return void
	 */
	private function controls_group_i_readnext() {
		$this->start_controls_section(
			'section_readnext',
			array(
				'label' => __( 'I · Read-next block', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// Section heading.
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'readnext_heading_typography',
				'selector' => '{{WRAPPER}} .ild-readnext__heading',
			)
		);

		$this->add_control(
			'readnext_heading_colour',
			array(
				'label'     => __( 'Heading colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-readnext__heading' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'readnext_heading_spacing',
			array(
				'label'      => __( 'Heading spacing', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 80 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-readnext__heading' => 'margin-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'readnext_block_spacing',
			array(
				'label'      => __( 'Block top spacing', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 160 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-readnext' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Layout: columns and gap per breakpoint.
		$this->add_responsive_control(
			'readnext_columns',
			array(
				'label'       => __( 'Columns', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::SLIDER,
				'range'       => array( 'px' => array( 'min' => 1, 'max' => 4 ) ),
				'default'     => array( 'size' => 3 ),
				'tablet_default' => array( 'size' => 2 ),
				'mobile_default' => array( 'size' => 1 ),
				'selectors'   => array(
					'{{WRAPPER}} .ild-readnext__grid' => 'grid-template-columns: repeat({{SIZE}}, minmax(0, 1fr));',
				),
			)
		);

		$this->add_responsive_control(
			'readnext_gap',
			array(
				'label'      => __( 'Gap', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-readnext__grid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Card.
		$this->add_control(
			'readnext_card_heading',
			array(
				'label'     => __( 'Card', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'readnext_card_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-readnext__card',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'readnext_card_border',
				'selector' => '{{WRAPPER}} .ild-readnext__card',
			)
		);

		$this->add_responsive_control(
			'readnext_card_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-readnext__card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'readnext_card_shadow',
				'selector' => '{{WRAPPER}} .ild-readnext__card',
			)
		);

		$this->add_responsive_control(
			'readnext_card_padding',
			array(
				'label'      => __( 'Content padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-readnext__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		// Hover state.
		$this->add_control(
			'readnext_hover_heading',
			array(
				'label'     => __( 'Hover', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_control(
			'readnext_hover_border',
			array(
				'label'     => __( 'Border colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-readnext__card:hover' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'readnext_hover_title',
			array(
				'label'     => __( 'Title colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-readnext__card:hover .ild-readnext__title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'readnext_hover_shadow',
				'selector' => '{{WRAPPER}} .ild-readnext__card:hover',
			)
		);

		// Thumbnail.
		$this->add_control(
			'readnext_thumb_heading',
			array(
				'label'     => __( 'Thumbnail', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'readnext_thumb_ratio',
			array(
				'label'       => __( 'Aspect ratio (width ÷ height)', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::SLIDER,
				'range'       => array( 'px' => array( 'min' => 0.5, 'max' => 3, 'step' => 0.05 ) ),
				'selectors'   => array(
					'{{WRAPPER}} .ild-readnext__thumb' => 'aspect-ratio: {{SIZE}}; height: auto;',
				),
			)
		);

		$this->add_responsive_control(
			'readnext_thumb_size',
			array(
				'label'       => __( 'Fixed height (overrides ratio)', 'ingredient-list-decoder' ),
				'type'        => Controls_Manager::SLIDER,
				'size_units'  => array( 'px', 'rem' ),
				'range'       => array( 'px' => array( 'min' => 60, 'max' => 400 ) ),
				'selectors'   => array(
					'{{WRAPPER}} .ild-readnext__thumb' => 'height: {{SIZE}}{{UNIT}}; aspect-ratio: auto;',
				),
			)
		);

		$this->add_responsive_control(
			'readnext_thumb_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-readnext__thumb' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		// Title, excerpt, meta typography.
		$this->add_control(
			'readnext_title_heading',
			array(
				'label'     => __( 'Title', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'readnext_title_typography',
				'selector' => '{{WRAPPER}} .ild-readnext__title',
			)
		);

		$this->add_control(
			'readnext_title_colour',
			array(
				'label'     => __( 'Title colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-readnext__title' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'readnext_excerpt_heading',
			array(
				'label'     => __( 'Excerpt', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'readnext_excerpt_typography',
				'selector' => '{{WRAPPER}} .ild-readnext__excerpt',
			)
		);

		$this->add_control(
			'readnext_excerpt_colour',
			array(
				'label'     => __( 'Excerpt colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-readnext__excerpt' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'readnext_meta_heading',
			array(
				'label'     => __( 'Meta', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'readnext_meta_typography',
				'selector' => '{{WRAPPER}} .ild-readnext__meta',
			)
		);

		$this->add_control(
			'readnext_meta_colour',
			array(
				'label'     => __( 'Meta colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-readnext__meta' => 'color: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group J — email gate
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group J controls.
	 *
	 * @return void
	 */
	private function controls_group_j_gate() {
		$this->start_controls_section(
			'section_gate',
			array(
				'label' => __( 'J · Email gate', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		// Container.
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'gate_bg',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .ild-gate',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'gate_border',
				'selector' => '{{WRAPPER}} .ild-gate',
			)
		);

		$this->add_responsive_control(
			'gate_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', '%', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-gate' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'gate_padding',
			array(
				'label'      => __( 'Padding', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-gate' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'gate_shadow',
				'selector' => '{{WRAPPER}} .ild-gate',
			)
		);

		// Heading and body typography.
		$this->add_control(
			'gate_heading_heading',
			array(
				'label'     => __( 'Heading', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'gate_heading_typography',
				'selector' => '{{WRAPPER}} .ild-gate__heading',
			)
		);

		$this->add_control(
			'gate_heading_colour',
			array(
				'label'     => __( 'Heading colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-gate__heading' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'gate_body_heading',
			array(
				'label'     => __( 'Body', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'gate_body_typography',
				'selector' => '{{WRAPPER}} .ild-gate__body, {{WRAPPER}} .ild-exchange',
			)
		);

		$this->add_control(
			'gate_body_colour',
			array(
				'label'     => __( 'Body colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-gate__body' => 'color: {{VALUE}};',
					'{{WRAPPER}} .ild-exchange'   => 'color: {{VALUE}};',
				),
			)
		);

		// Email field — overrides, or leave blank to inherit.
		$this->add_control(
			'gate_field_heading',
			array(
				'label'     => __( 'Email field', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'gate_field_typography',
				'selector' => '{{WRAPPER}} .ild-gate__email',
			)
		);

		$this->add_control(
			'gate_field_colour',
			array(
				'label'     => __( 'Text colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-gate__email' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'gate_field_bg',
				'types'    => array( 'classic' ),
				'selector' => '{{WRAPPER}} .ild-gate__email',
			)
		);

		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'gate_field_border',
				'selector' => '{{WRAPPER}} .ild-gate__email',
			)
		);

		// Checkbox: size, colour, checked colour, radius.
		$this->add_control(
			'gate_checkbox_heading',
			array(
				'label'     => __( 'Consent checkbox', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_responsive_control(
			'gate_checkbox_size',
			array(
				'label'      => __( 'Size', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 12, 'max' => 40 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-gate__checkbox' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'gate_checkbox_colour',
			array(
				'label'     => __( 'Border colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-gate__checkbox' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'gate_checkbox_checked',
			array(
				'label'     => __( 'Checked colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-gate__checkbox:checked' => 'background-color: {{VALUE}}; border-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'gate_checkbox_radius',
			array(
				'label'      => __( 'Border radius', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 20 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-gate__checkbox' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		// Consent text.
		$this->add_control(
			'gate_consent_heading',
			array(
				'label'     => __( 'Consent text', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before',
			)
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => 'gate_consent_typography',
				'selector' => '{{WRAPPER}} .ild-gate__consent-text',
			)
		);

		$this->add_control(
			'gate_consent_colour',
			array(
				'label'     => __( 'Colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .ild-gate__consent-text' => 'color: {{VALUE}};',
				),
			)
		);

		// Privacy link colour.
		$this->add_control(
			'gate_privacy_colour',
			array(
				'label'     => __( 'Privacy link colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .ild-gate__privacy a' => 'color: {{VALUE}};',
				),
			)
		);

		// Submit button — inherits, or override including its disabled state.
		$this->button_controls( 'gate_submit', __( 'Submit button', 'ingredient-list-decoder' ), '{{WRAPPER}} .ild-gate__submit' );

		$this->end_controls_section();
	}

	/*
	 * -----------------------------------------------------------------------
	 * Group K — global
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Group K controls.
	 *
	 * @return void
	 */
	private function controls_group_k_global() {
		$this->start_controls_section(
			'section_global',
			array(
				'label' => __( 'K · Global', 'ingredient-list-decoder' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'global_max_width',
			array(
				'label'      => __( 'Maximum width', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', '%', 'rem', 'vw' ),
				'range'      => array(
					'px' => array( 'min' => 280, 'max' => 1200 ),
					'%'  => array( 'min' => 10, 'max' => 100 ),
				),
				'selectors'  => array(
					'{{WRAPPER}} .ild-tool' => 'max-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'global_section_spacing',
			array(
				'label'      => __( 'Section spacing', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em', 'rem' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 120 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .ild-summary'      => 'margin-top: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .ild-ingredients'  => 'margin-top: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .ild-result__actions' => 'margin-top: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'global_disable_motion',
			array(
				'label'        => __( 'Reduce motion', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'On', 'ingredient-list-decoder' ),
				'label_off'    => __( 'Off', 'ingredient-list-decoder' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Turn off the spinner and expand animations for everyone. The visitor\'s own reduce-motion setting is always honoured regardless.', 'ingredient-list-decoder' ),
			)
		);

		$this->add_control(
			'global_disable_print',
			array(
				'label'        => __( 'Print styles', 'ingredient-list-decoder' ),
				'type'         => Controls_Manager::SWITCHER,
				'label_on'     => __( 'On', 'ingredient-list-decoder' ),
				'label_off'    => __( 'Off', 'ingredient-list-decoder' ),
				'return_value' => '',
				'default'      => 'yes',
				'description'  => __( 'A tidy, ink-light layout when the page is printed. Turn off to print the tool exactly as shown.', 'ingredient-list-decoder' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * A small, reusable set of controls for one optional icon slot.
	 *
	 * The result fragment is rendered by AJAX, decoupled from this widget's
	 * settings, so glyphs cannot be piped into it. Instead each icon slot is a
	 * real, empty, hidden-by-default box that a designer switches on by giving it
	 * a size and a colour or background image — honest, and fully in their hands.
	 *
	 * @param string $key      A unique key for this icon.
	 * @param string $selector The CSS selector for the icon slot.
	 * @return void
	 */
	private function icon_slot_controls( $key, $selector ) {
		$this->add_responsive_control(
			$key . '_size',
			array(
				'label'      => __( 'Icon size', 'ingredient-list-decoder' ),
				'type'       => Controls_Manager::SLIDER,
				'size_units' => array( 'px', 'em' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 60 ) ),
				'selectors'  => array(
					$selector => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			$key . '_colour',
			array(
				'label'     => __( 'Icon colour', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => array(
					$selector => 'background-color: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			$key . '_image',
			array(
				'label'     => __( 'Icon image (optional)', 'ingredient-list-decoder' ),
				'type'      => Controls_Manager::MEDIA,
				'selectors' => array(
					$selector => 'background-color: transparent; background-image: url({{URL}});',
				),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Render
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Render the widget: the same tool the shortcode renders, plus wrapper
	 * classes and (in the editor) the chosen preview state.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Wrapper classes driven by the global and loading controls.
		$classes = array();
		if ( isset( $settings['loading_style'] ) && 'skeleton' === $settings['loading_style'] ) {
			$classes[] = 'ild-tool--loading-skeleton';
		}
		if ( isset( $settings['global_disable_motion'] ) && 'yes' === $settings['global_disable_motion'] ) {
			$classes[] = 'ild-tool--no-motion';
		}
		// The print switcher stores '' when on and 'yes'... inverted: return_value
		// is '' for on, so a truthy value here means "off".
		if ( ! empty( $settings['global_disable_print'] ) ) {
			$classes[] = 'ild-tool--print-off';
		}

		// Editor-only preview state.
		$preview = '';
		if ( $this->is_edit_mode() && ! empty( $settings['ild_preview_state'] ) && 'form' !== $settings['ild_preview_state'] ) {
			$preview = $settings['ild_preview_state'];
		}

		// The photo control: off if the toggle is off; otherwise let the shared
		// renderer decide from settings, but always show it in the editor so it
		// (and the verification step) can be styled.
		$show_photo = null;
		if ( 'yes' !== ( isset( $settings['ild_show_photo'] ) ? $settings['ild_show_photo'] : 'yes' ) ) {
			$show_photo = false;
		} elseif ( $this->is_edit_mode() ) {
			$show_photo = true;
		}

		// The primary button icon, rendered here where we have the widget settings.
		$icon_html = '';
		if ( ! empty( $settings['ild_primary_icon']['value'] ) && class_exists( '\Elementor\Icons_Manager' ) ) {
			ob_start();
			\Elementor\Icons_Manager::render_icon( $settings['ild_primary_icon'], array( 'aria-hidden' => 'true' ) );
			$icon_html = ob_get_clean();
		}

		// The tool markup comes from the shared renderer, already escaped.
		// The editable gate wording, falling back to the defaults.
		$exchange_text = ! empty( $settings['ild_exchange_text'] ) ? $settings['ild_exchange_text'] : ILD_Phrases::exchange_default();
		$consent_text  = ! empty( $settings['ild_consent_text'] ) ? $settings['ild_consent_text'] : ILD_Phrases::consent_default();

		echo ILD_Shortcode::render_tool( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-rendered, pre-escaped template markup.
			array(
				'class'         => implode( ' ', $classes ),
				'preview'       => $preview,
				'submit_icon'   => $icon_html,
				'show_photo'    => $show_photo,
				'exchange_text' => $exchange_text,
				'consent_text'  => $consent_text,
			)
		);
	}

	/**
	 * Whether Elementor is currently in edit mode.
	 *
	 * @return bool
	 */
	private function is_edit_mode() {
		return class_exists( '\Elementor\Plugin' )
			&& isset( \Elementor\Plugin::$instance->editor )
			&& \Elementor\Plugin::$instance->editor->is_edit_mode();
	}
}
