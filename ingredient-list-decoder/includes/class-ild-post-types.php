<?php
/**
 * Registers the plugin's data structures: the ingredient post type, its two
 * taxonomies, and the extra "needs review" lifecycle status.
 *
 * These are the shapes every later stage relies on, so they are defined here in
 * one place and reused (activation seeds the same taxonomies this class
 * registers on every load).
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the ingredient post type, taxonomies and lifecycle status.
 */
class ILD_Post_Types {

	/**
	 * The post type key for a single ingredient entry.
	 *
	 * @var string
	 */
	const POST_TYPE = 'ild_ingredient';

	/**
	 * The taxonomy key for Ingredient Family (applied to ingredients only).
	 *
	 * @var string
	 */
	const TAX_FAMILY = 'ild_family';

	/**
	 * The taxonomy key for Skin Topic (shared by ingredients and articles).
	 *
	 * @var string
	 */
	const TAX_TOPIC = 'ild_topic';

	/**
	 * The custom post status meaning "drafted, but not yet reviewed".
	 *
	 * The brief describes three lifecycle states: draft, needs review and
	 * published. WordPress already gives us "draft" (Draft) and "publish"
	 * (Published); this fills the gap in the middle. Using a real post status
	 * rather than a separate meta field means the status column, the status
	 * filters and the "nothing unreviewed reaches the front end" query all work
	 * the native WordPress way, with one source of truth for the state.
	 *
	 * @var string
	 */
	const STATUS_NEEDS_REVIEW = 'ild-needs-review';

	/**
	 * Hook everything this class provides onto WordPress.
	 *
	 * Called by the main loader. Registration runs on 'init', which is where
	 * WordPress expects post types, taxonomies and statuses to be declared.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_taxonomies' ), 9 );
		add_action( 'init', array( $this, 'register_post_type' ), 10 );
		add_action( 'init', array( $this, 'register_statuses' ), 11 );

		// Make the "Needs Review" status usable in the post editor's status box.
		add_action( 'admin_footer-post.php', array( $this, 'print_status_editor_script' ) );
		add_action( 'admin_footer-post-new.php', array( $this, 'print_status_editor_script' ) );

		// Show "Needs Review" correctly in the post list and the published-date row.
		add_filter( 'display_post_states', array( $this, 'filter_display_post_states' ), 10, 2 );
	}

	/**
	 * Register the ild_ingredient custom post type.
	 *
	 * Admin interface is on (so entries can be created and edited), but it is
	 * not publicly queryable: an ingredient is a database record, not a page on
	 * the site. Public front-end pages for ingredients are deliberately out of
	 * scope (see the brief's open decisions).
	 *
	 * @return void
	 */
	public function register_post_type() {
		// The labels shown throughout the admin for this post type.
		$labels = array(
			'name'                  => _x( 'Ingredients', 'post type general name', 'ingredient-list-decoder' ),
			'singular_name'         => _x( 'Ingredient', 'post type singular name', 'ingredient-list-decoder' ),
			'menu_name'             => _x( 'Ingredient Decoder', 'admin menu', 'ingredient-list-decoder' ),
			'name_admin_bar'        => _x( 'Ingredient', 'add new on admin bar', 'ingredient-list-decoder' ),
			'add_new'               => __( 'Add New', 'ingredient-list-decoder' ),
			'add_new_item'          => __( 'Add New Ingredient', 'ingredient-list-decoder' ),
			'new_item'              => __( 'New Ingredient', 'ingredient-list-decoder' ),
			'edit_item'             => __( 'Edit Ingredient', 'ingredient-list-decoder' ),
			'view_item'             => __( 'View Ingredient', 'ingredient-list-decoder' ),
			'all_items'             => __( 'All Ingredients', 'ingredient-list-decoder' ),
			'search_items'          => __( 'Search Ingredients', 'ingredient-list-decoder' ),
			'not_found'             => __( 'No ingredients found.', 'ingredient-list-decoder' ),
			'not_found_in_trash'    => __( 'No ingredients found in Trash.', 'ingredient-list-decoder' ),
			'items_list'            => __( 'Ingredients list', 'ingredient-list-decoder' ),
			'items_list_navigation' => __( 'Ingredients list navigation', 'ingredient-list-decoder' ),
		);

		// The full definition. Comments call out the choices that matter.
		$args = array(
			'labels'              => $labels,
			'public'              => false, // Not a public-facing thing at all.
			'publicly_queryable'  => false, // Cannot be reached by a front-end URL.
			'exclude_from_search' => true,  // Never appears in site search results.
			'show_ui'             => true,  // But it does have a full admin interface.
			'show_in_menu'        => true,  // Gets its own top-level admin menu.
			'show_in_nav_menus'   => false, // Not offered when building nav menus.
			'show_in_admin_bar'   => true,  // "Add New" shortcut in the toolbar.
			'menu_position'       => 30,    // Sits below the core content menus.
			'menu_icon'           => 'dashicons-editor-ul', // A list icon suits it.
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'has_archive'         => false, // No archive page, it is not public.
			'rewrite'             => false, // No pretty permalinks needed.
			'query_var'           => false,
			'show_in_rest'        => false, // Kept out of the public REST API for now.
			// INCI name lives in the title; revisions let a bad edit be rolled
			// back (fleshed out further in a later stage).
			'supports'            => array( 'title', 'revisions' ),
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register the two taxonomies used to classify ingredients.
	 *
	 * Ingredient Family describes what kind of ingredient it is and only ever
	 * applies to ingredients. Skin Topic describes what part of skin it relates
	 * to and is shared with normal articles, so a single tag can link an
	 * ingredient to the posts worth reading next.
	 *
	 * @return void
	 */
	public function register_taxonomies() {
		// --- Ingredient Family: ingredients only. -------------------------
		$family_labels = array(
			'name'              => _x( 'Ingredient Families', 'taxonomy general name', 'ingredient-list-decoder' ),
			'singular_name'     => _x( 'Ingredient Family', 'taxonomy singular name', 'ingredient-list-decoder' ),
			'search_items'      => __( 'Search Families', 'ingredient-list-decoder' ),
			'all_items'         => __( 'All Families', 'ingredient-list-decoder' ),
			'edit_item'         => __( 'Edit Family', 'ingredient-list-decoder' ),
			'update_item'       => __( 'Update Family', 'ingredient-list-decoder' ),
			'add_new_item'      => __( 'Add New Family', 'ingredient-list-decoder' ),
			'new_item_name'     => __( 'New Family Name', 'ingredient-list-decoder' ),
			'menu_name'         => __( 'Ingredient Families', 'ingredient-list-decoder' ),
		);

		register_taxonomy(
			self::TAX_FAMILY,
			array( self::POST_TYPE ),
			array(
				'labels'            => $family_labels,
				'public'            => false, // Not a public archive.
				'show_ui'           => true,  // But manageable in the admin.
				'show_admin_column' => true,  // Appears as a column on the list.
				'show_in_rest'      => false,
				'hierarchical'      => true,  // Behaves like categories, not tags.
				'query_var'         => false,
				'rewrite'           => false,
			)
		);

		// --- Skin Topic: ingredients AND standard posts. ------------------
		$topic_labels = array(
			'name'              => _x( 'Skin Topics', 'taxonomy general name', 'ingredient-list-decoder' ),
			'singular_name'     => _x( 'Skin Topic', 'taxonomy singular name', 'ingredient-list-decoder' ),
			'search_items'      => __( 'Search Topics', 'ingredient-list-decoder' ),
			'all_items'         => __( 'All Topics', 'ingredient-list-decoder' ),
			'edit_item'         => __( 'Edit Topic', 'ingredient-list-decoder' ),
			'update_item'       => __( 'Update Topic', 'ingredient-list-decoder' ),
			'add_new_item'      => __( 'Add New Topic', 'ingredient-list-decoder' ),
			'new_item_name'     => __( 'New Topic Name', 'ingredient-list-decoder' ),
			'menu_name'         => __( 'Skin Topics', 'ingredient-list-decoder' ),
		);

		register_taxonomy(
			self::TAX_TOPIC,
			// Shared between ingredients and articles so one tag links them.
			array( self::POST_TYPE, 'post' ),
			array(
				'labels'            => $topic_labels,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true, // On for the block editor on posts.
				'hierarchical'      => true,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);
	}

	/**
	 * Register the extra "Needs Review" post status.
	 *
	 * WordPress ships with Draft and Published; this adds the reviewed-yet? step
	 * in between. It is not public, so nothing with this status can ever be seen
	 * on the front end.
	 *
	 * @return void
	 */
	public function register_statuses() {
		register_post_status(
			self::STATUS_NEEDS_REVIEW,
			array(
				'label'                     => _x( 'Needs Review', 'post status', 'ingredient-list-decoder' ),
				'public'                    => false, // Never shown on the front end.
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true, // Counts in the "All" tab.
				'show_in_admin_status_list' => true, // Gets its own filter tab.
				/* translators: %s: number of ingredients needing review. */
				'label_count'               => _n_noop(
					'Needs Review <span class="count">(%s)</span>',
					'Needs Review <span class="count">(%s)</span>',
					'ingredient-list-decoder'
				),
			)
		);
	}

	/**
	 * Add "Needs Review" to the post editor's status dropdown.
	 *
	 * WordPress does not show custom statuses in the Publish box on its own, so
	 * this small script slots the option in and keeps the label visible after
	 * saving. It only runs on the ingredient edit screen.
	 *
	 * @return void
	 */
	public function print_status_editor_script() {
		global $post;

		// Only touch the editor for our own post type.
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Work out whether this entry is currently in the needs-review state,
		// so the dropdown and the label can show the right thing on load.
		$is_needs_review = ( self::STATUS_NEEDS_REVIEW === $post->post_status );
		$label           = esc_js( __( 'Needs Review', 'ingredient-list-decoder' ) );
		$status_slug     = esc_js( self::STATUS_NEEDS_REVIEW );
		$selected_now    = $is_needs_review ? 'true' : 'false';
		?>
		<script type="text/javascript">
			// Add the custom status to the Publish box and keep its label shown.
			( function ( $ ) {
				var statusSlug = '<?php echo $status_slug; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>';
				var statusLabel = '<?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>';
				var isSelected = <?php echo $selected_now; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

				// Add our status to the dropdown if it is not already there.
				if ( 0 === $( 'select#post_status option[value="' + statusSlug + '"]' ).length ) {
					$( 'select#post_status' ).append(
						$( '<option></option>' ).attr( 'value', statusSlug ).text( statusLabel )
					);
				}

				// When our entry is already in that state, show it as selected.
				if ( isSelected ) {
					$( 'select#post_status' ).val( statusSlug );
					$( '#post-status-display' ).text( statusLabel );
				}
			} )( jQuery );
		</script>
		<?php
	}

	/**
	 * Label the "Needs Review" state in the ingredients list table.
	 *
	 * Without this, an entry in a custom status shows no state label next to its
	 * title. This adds a clear "Needs Review" tag on our post type only.
	 *
	 * @param array   $states The existing post-state labels.
	 * @param WP_Post $post   The post being displayed.
	 * @return array The (possibly extended) list of state labels.
	 */
	public function filter_display_post_states( $states, $post ) {
		if ( self::POST_TYPE === $post->post_type && self::STATUS_NEEDS_REVIEW === $post->post_status ) {
			$states[ self::STATUS_NEEDS_REVIEW ] = _x( 'Needs Review', 'post status', 'ingredient-list-decoder' );
		}

		return $states;
	}

	/**
	 * The Ingredient Family terms to seed on activation.
	 *
	 * Straight from the brief. Kept here, beside the taxonomy, so the seeding
	 * routine and the taxonomy definition never fall out of step.
	 *
	 * @return string[] The family term names.
	 */
	public static function get_default_family_terms() {
		return array(
			'Retinoids',
			'Vitamin C and antioxidants',
			'Exfoliating acids',
			'Peptides',
			'Niacinamide',
			'Humectants',
			'Barrier lipids',
			'Emollients',
			'Emulsifiers',
			'Texture and thickeners',
			'Preservatives',
			'Fragrance and essential oils',
			'UV filters',
			'Solvents and carriers',
			'Botanical extracts',
		);
	}

	/**
	 * The Skin Topic terms to seed on activation.
	 *
	 * Straight from the brief. Shared with articles, so publishing an article
	 * under one of these tags makes it eligible for the read-next block later.
	 *
	 * @return string[] The topic term names.
	 */
	public static function get_default_topic_terms() {
		return array(
			'Pigmentation',
			'Firmness and collagen',
			'Barrier and sensitivity',
			'Hydration',
			'Texture and pores',
			'Congestion',
			'Perimenopause and skin',
			'Ageing and cell turnover',
			'Formulation and use levels',
			'Clean and natural origin',
		);
	}
}
