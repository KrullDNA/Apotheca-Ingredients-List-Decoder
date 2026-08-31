<?php
/**
 * Elementor integration: the category and the widget registration.
 *
 * This class only ever does anything when Elementor is active — its hooks are
 * Elementor's own, so on a site without Elementor nothing here runs and the
 * shortcode remains the way the tool is placed. The widget wraps the very same
 * rendering the shortcode uses (ILD_Shortcode::render_tool), so the two can
 * never drift; the widget adds the full set of Elementor style controls on top.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the KDNA Tools category and the Ingredient List Decoder widget.
 */
class ILD_Elementor {

	/**
	 * The Elementor category custom widgets are grouped under.
	 *
	 * @var string
	 */
	const CATEGORY = 'kdna-tools';

	/**
	 * Hook the category and widget registration onto Elementor.
	 *
	 * Both actions belong to Elementor, so they simply never fire if Elementor
	 * is not installed. No separate "is Elementor active" check is needed.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'elementor/elements/categories_registered', array( $this, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
	}

	/**
	 * Add the "KDNA Tools" category, so custom widgets sit apart from the rest.
	 *
	 * @param \Elementor\Elements_Manager $elements_manager Elementor's manager.
	 * @return void
	 */
	public function register_category( $elements_manager ) {
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'KDNA Tools', 'ingredient-list-decoder' ),
				'icon'  => 'eicon-apps',
			)
		);
	}

	/**
	 * Register the widget.
	 *
	 * The widget class is required here, inside the hook, rather than at plugin
	 * load, because it extends an Elementor base class that only exists once
	 * Elementor itself has loaded.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor's manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ) {
		require_once ILD_PLUGIN_DIR . 'includes/widgets/class-ild-elementor-widget.php';
		$widgets_manager->register( new ILD_Elementor_Widget() );
	}
}
