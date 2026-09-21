<?php
/**
 * The product edit screen's ingredient-library status box.
 *
 * A read-only meta box on the WooCommerce product screen that reads the product's
 * ingredient list (the JetEngine field) and shows how it maps to the library: how
 * many ingredients are recognised, and — the point of it — which are not in the
 * library yet, so they can be added. It writes nothing; the field stays owned by
 * JetEngine. The same missing names are also recorded to the Unknown ingredients
 * queue when the product is saved (see ILD_Products), so this box is the at-a-glance
 * view while the queue is the working list.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the ingredient-library status meta box to products.
 */
class ILD_Product_Admin {

	/**
	 * Hook the meta box onto the product edit screen.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes_product', array( $this, 'add_box' ) );
	}

	/**
	 * Register the meta box on the product post type.
	 *
	 * @return void
	 */
	public function add_box() {
		add_meta_box(
			'ild_product_ingredients_status',
			__( 'Ingredient library status', 'ingredient-list-decoder' ),
			array( $this, 'render_box' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the status box for one product.
	 *
	 * @param WP_Post $post The product being edited.
	 * @return void
	 */
	public function render_box( $post ) {
		$rows = ILD_Products::rows( (int) $post->ID );

		if ( empty( $rows ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'No ingredient list found for this product yet. Add one to the "ingredients" field and save, and it will map to the library here.', 'ingredient-list-decoder' )
			);
			return;
		}

		// Split matched from missing, keeping bottle order.
		$missing = array();
		$matched = 0;
		foreach ( $rows as $row ) {
			if ( 'missing' === $row['status'] ) {
				$missing[] = $row['name'];
			} else {
				$matched++;
			}
		}

		$total = count( $rows );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: matched count, 2: total count. */
					__( '%1$d of %2$d ingredients are in the library.', 'ingredient-list-decoder' ),
					$matched,
					$total
				)
			)
		);

		if ( empty( $missing ) ) {
			printf(
				'<p style="color:#2e7d32;">%s</p>',
				esc_html__( 'Every ingredient on this product is in the library.', 'ingredient-list-decoder' )
			);
			return;
		}

		printf(
			'<p><strong>%s</strong></p>',
			esc_html(
				sprintf(
					/* translators: %d: how many ingredients are missing. */
					_n(
						'%d ingredient is not in the library yet:',
						'%d ingredients are not in the library yet:',
						count( $missing ),
						'ingredient-list-decoder'
					),
					count( $missing )
				)
			)
		);

		echo '<ul style="list-style:disc;margin-left:1.2em;">';
		foreach ( $missing as $name ) {
			printf( '<li>%s</li>', esc_html( $name ) );
		}
		echo '</ul>';

		// A quick route to add them: the Unknown ingredients queue collects these on
		// save and the CSV round-trip fills them in.
		if ( class_exists( 'ILD_Unknown_Admin' ) ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( ILD_Unknown_Admin::page_url() ),
				esc_html__( 'Add missing ingredients →', 'ingredient-list-decoder' )
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Missing names are added to the Unknown ingredients queue when you save this product.', 'ingredient-list-decoder' )
		);
	}
}
