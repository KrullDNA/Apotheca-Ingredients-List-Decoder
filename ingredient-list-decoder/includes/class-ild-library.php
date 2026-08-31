<?php
/**
 * The ingredient library admin screens.
 *
 * Everything that makes the growing list of ingredients workable for the person
 * curating it:
 *
 *  - A tuned list table: columns for INCI name, family, role, status and last
 *    modified, all sortable; dropdown filters for status, family, role and the
 *    below-one-per-cent marker; and a search that looks in both the INCI name
 *    and the "also known as" aliases.
 *  - Bulk actions to change status, add a family or topic to the selection, and
 *    export just the selected rows to CSV.
 *  - A guard that stops two entries ever sharing an INCI name, naming the entry
 *    that already holds it.
 *  - A review queue screen listing everything still in draft or needs-review.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the ingredient library list, filters, bulk actions and review queue.
 */
class ILD_Library {

	/**
	 * The slug of the (menu-less) intermediate "assign a term" screen.
	 *
	 * @var string
	 */
	const ASSIGN_PAGE_SLUG = 'ild-bulk-assign';

	/**
	 * The slug of the review queue screen.
	 *
	 * @var string
	 */
	const REVIEW_PAGE_SLUG = 'ild-review-queue';

	/**
	 * How long a saved "export these rows" selection lives, in seconds.
	 *
	 * @var int
	 */
	const SELECTION_TTL = 300;

	/**
	 * Hook every part of the library screens onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$post_type = ILD_Post_Types::POST_TYPE;

		// The list table: columns, their rendering, and which are sortable.
		add_filter( "manage_{$post_type}_posts_columns", array( $this, 'set_columns' ) );
		add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'render_column' ), 10, 2 );
		add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'set_sortable_columns' ) );
		// Turn our custom sort keys into real SQL ordering.
		add_filter( 'posts_clauses', array( $this, 'sort_clauses' ), 10, 2 );

		// The dropdown filters above the list, and the query changes they drive.
		add_action( 'restrict_manage_posts', array( $this, 'render_filters' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_admin_filters' ) );

		// The bulk actions and their handler. Exporting a selection reuses the
		// existing CSV export handler in ILD_CSV, so nothing extra is hooked here.
		add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'add_bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk_actions' ), 10, 3 );

		// The flash notices shown after a bulk action or a blocked duplicate.
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );

		// Stop two entries ever sharing an INCI name.
		add_filter( 'wp_insert_post_data', array( $this, 'block_duplicate_inci' ), 10, 2 );

		// The review queue and the (hidden) bulk-assign screens.
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * List table columns
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Choose the columns shown on the ingredient list.
	 *
	 * Rebuilds the set in a deliberate order: the checkbox, the INCI name (the
	 * title, relabelled), family, role, status, topic and last modified. The
	 * default date and author columns are dropped in favour of "last modified".
	 *
	 * @param array $columns The columns WordPress proposed.
	 * @return array The columns to actually show.
	 */
	public function set_columns( $columns ) {
		$new = array();

		// Keep the row checkbox if it is there (it drives the bulk actions).
		if ( isset( $columns['cb'] ) ) {
			$new['cb'] = $columns['cb'];
		}

		$new['title']               = __( 'INCI name', 'ingredient-list-decoder' );
		$new['taxonomy-ild_family'] = __( 'Family', 'ingredient-list-decoder' );
		$new['ild_role']            = __( 'Role', 'ingredient-list-decoder' );
		$new['ild_status']          = __( 'Status', 'ingredient-list-decoder' );
		$new['taxonomy-ild_topic']  = __( 'Topic', 'ingredient-list-decoder' );
		$new['ild_modified']        = __( 'Last modified', 'ingredient-list-decoder' );

		return $new;
	}

	/**
	 * Fill in the value for each of our custom columns, row by row.
	 *
	 * WordPress renders the title and taxonomy columns itself; this only handles
	 * the three columns we added.
	 *
	 * @param string $column  The column key being drawn.
	 * @param int    $post_id The ingredient in this row.
	 * @return void
	 */
	public function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'ild_role':
				// Show the roles as their human labels, comma separated.
				$roles = get_post_meta( $post_id, '_ild_role', true );
				if ( is_array( $roles ) && ! empty( $roles ) ) {
					$labels = array_map( array( 'ILD_Roles', 'get_label' ), $roles );
					echo esc_html( implode( ', ', $labels ) );
				} else {
					echo '<span aria-hidden="true">&mdash;</span>';
				}
				break;

			case 'ild_status':
				echo esc_html( $this->status_label( get_post_status( $post_id ) ) );
				break;

			case 'ild_modified':
				// The date, in the site's own date format, plus the below-1% flag
				// as a small hint where it is set.
				echo esc_html( get_the_modified_date( '', $post_id ) );
				break;
		}
	}

	/**
	 * Declare which columns can be clicked to sort.
	 *
	 * The values are the "orderby" keys WordPress will pass back; title and
	 * modified are understood natively, the other three are turned into SQL by
	 * sort_clauses() below.
	 *
	 * @param array $columns The sortable columns so far.
	 * @return array The sortable columns, extended.
	 */
	public function set_sortable_columns( $columns ) {
		$columns['title']               = 'title';
		$columns['taxonomy-ild_family'] = 'ild_family';
		$columns['ild_role']            = 'ild_role';
		$columns['ild_status']          = 'ild_status';
		$columns['ild_modified']        = 'modified';

		return $columns;
	}

	/**
	 * Turn our custom sort keys into real ORDER BY clauses.
	 *
	 * WordPress can sort by title and modified date on its own. Sorting by role
	 * (an array in post meta), by family (a taxonomy term) or by status needs a
	 * join or a different column, added here only on the ingredient list screen.
	 *
	 * @param array    $clauses The SQL pieces of the query.
	 * @param WP_Query $query   The query being built.
	 * @return array The (possibly adjusted) SQL pieces.
	 */
	public function sort_clauses( $clauses, $query ) {
		if ( ! $this->is_ingredient_admin_query( $query ) ) {
			return $clauses;
		}

		$orderby = $query->get( 'orderby' );
		if ( ! is_string( $orderby ) ) {
			return $clauses;
		}

		$order = ( 'asc' === strtolower( (string) $query->get( 'order' ) ) ) ? 'ASC' : 'DESC';

		global $wpdb;

		switch ( $orderby ) {
			case 'ild_role':
				// Join the role meta and sort by its stored value. A missing role
				// still appears (a LEFT JOIN), sorting to the empty end.
				$clauses['join']   .= " LEFT JOIN {$wpdb->postmeta} ild_role_sort ON ( {$wpdb->posts}.ID = ild_role_sort.post_id AND ild_role_sort.meta_key = '_ild_role' ) ";
				$clauses['orderby'] = "ild_role_sort.meta_value {$order}, {$wpdb->posts}.post_title ASC";
				break;

			case 'ild_family':
				// Join through to the family term names and sort by the first one.
				// Grouped by ID so an entry in two families is not listed twice.
				$tax                = esc_sql( ILD_Post_Types::TAX_FAMILY );
				$clauses['join']   .= " LEFT JOIN {$wpdb->term_relationships} ild_fam_tr ON ( {$wpdb->posts}.ID = ild_fam_tr.object_id )"
					. " LEFT JOIN {$wpdb->term_taxonomy} ild_fam_tt ON ( ild_fam_tr.term_taxonomy_id = ild_fam_tt.term_taxonomy_id AND ild_fam_tt.taxonomy = '{$tax}' )"
					. " LEFT JOIN {$wpdb->terms} ild_fam_t ON ( ild_fam_tt.term_id = ild_fam_t.term_id ) ";
				$clauses['orderby'] = "MIN( ild_fam_t.name ) {$order}, {$wpdb->posts}.post_title ASC";
				$clauses['groupby'] = "{$wpdb->posts}.ID";
				break;

			case 'ild_status':
				$clauses['orderby'] = "{$wpdb->posts}.post_status {$order}, {$wpdb->posts}.post_title ASC";
				break;
		}

		return $clauses;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Filters and search
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Draw the dropdown filters above the ingredient list.
	 *
	 * Status, family, role and the below-1% marker. The "Filter" button that
	 * applies them is provided by WordPress once we print something here.
	 *
	 * @param string $post_type The post type of the list being shown.
	 * @return void
	 */
	public function render_filters( $post_type ) {
		if ( ILD_Post_Types::POST_TYPE !== $post_type ) {
			return;
		}

		// --- Status. Uses its own request key (not "post_status") so it never
		// clashes with the hidden post_status field the list table already emits.
		$current_status = isset( $_GET['ild_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['ild_status_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$statuses       = array(
			''                                  => __( 'All statuses', 'ingredient-list-decoder' ),
			'publish'                           => __( 'Published', 'ingredient-list-decoder' ),
			ILD_Post_Types::STATUS_NEEDS_REVIEW => __( 'Needs Review', 'ingredient-list-decoder' ),
			'draft'                             => __( 'Draft', 'ingredient-list-decoder' ),
		);
		echo '<label class="screen-reader-text" for="ild_status_filter">' . esc_html__( 'Filter by status', 'ingredient-list-decoder' ) . '</label>';
		echo '<select name="ild_status_filter" id="ild_status_filter">';
		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current_status, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		// --- Family. ------------------------------------------------------
		$current_family = isset( $_GET['ild_family_filter'] ) ? absint( $_GET['ild_family_filter'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		wp_dropdown_categories(
			array(
				'taxonomy'         => ILD_Post_Types::TAX_FAMILY,
				'name'             => 'ild_family_filter',
				'id'               => 'ild_family_filter',
				'show_option_all'  => __( 'All families', 'ingredient-list-decoder' ),
				'hierarchical'     => true,
				'hide_empty'       => false,
				'orderby'          => 'name',
				'selected'         => $current_family,
				'value_field'      => 'term_id',
				'show_count'       => false,
			)
		);

		// --- Role. --------------------------------------------------------
		$current_role = isset( $_GET['ild_role_filter'] ) ? sanitize_key( wp_unslash( $_GET['ild_role_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		echo '<label class="screen-reader-text" for="ild_role_filter">' . esc_html__( 'Filter by role', 'ingredient-list-decoder' ) . '</label>';
		echo '<select name="ild_role_filter" id="ild_role_filter">';
		printf( '<option value="">%s</option>', esc_html__( 'All roles', 'ingredient-list-decoder' ) );
		foreach ( ILD_Roles::get_roles() as $slug => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $current_role, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		// --- Below-1% marker. ---------------------------------------------
		$current_sub = isset( $_GET['ild_sub_filter'] ) ? sanitize_key( wp_unslash( $_GET['ild_sub_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$sub_options = array(
			''    => __( 'Any below-1% marker', 'ingredient-list-decoder' ),
			'yes' => __( 'Marked below 1%', 'ingredient-list-decoder' ),
			'no'  => __( 'Not marked below 1%', 'ingredient-list-decoder' ),
		);
		echo '<label class="screen-reader-text" for="ild_sub_filter">' . esc_html__( 'Filter by below-1% marker', 'ingredient-list-decoder' ) . '</label>';
		echo '<select name="ild_sub_filter" id="ild_sub_filter">';
		foreach ( $sub_options as $value => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current_sub, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Apply the dropdown filters and the widened search to the list query.
	 *
	 * Runs only for the main ingredient list query in the admin. It reads the
	 * filter values from the request and narrows the query with a meta query, a
	 * tax query, or a set of matching IDs for the search.
	 *
	 * @param WP_Query $query The query about to run.
	 * @return void
	 */
	public function apply_admin_filters( $query ) {
		if ( ! $this->is_ingredient_admin_query( $query ) ) {
			return;
		}

		// --- Search across INCI name AND "also known as". -----------------
		$search = $query->get( 's' );
		if ( ! empty( $search ) ) {
			// Work out every matching ID ourselves, then restrict to them and
			// clear the built-in search (which would only look at the title).
			$ids = $this->search_ingredient_ids( $search );
			$query->set( 'post__in', empty( $ids ) ? array( 0 ) : $ids );
			$query->set( 's', '' );
		}

		// --- Status filter. Restricts to one of our known statuses. -------
		$status = isset( $_GET['ild_status_filter'] ) ? sanitize_key( wp_unslash( $_GET['ild_status_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( in_array( $status, array( 'publish', ILD_Post_Types::STATUS_NEEDS_REVIEW, 'draft' ), true ) ) {
			$query->set( 'post_status', $status );
		}

		// --- Meta-based filters: role and the below-1% marker. ------------
		$meta_query = (array) $query->get( 'meta_query' );

		$role = isset( $_GET['ild_role_filter'] ) ? sanitize_key( wp_unslash( $_GET['ild_role_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( '' !== $role && ILD_Roles::is_valid_role( $role ) ) {
			// Roles are stored as a serialised array, so match the quoted slug
			// inside it. The quotes stop "active" also matching "inactive".
			$meta_query[] = array(
				'key'     => '_ild_role',
				'value'   => '"' . $role . '"',
				'compare' => 'LIKE',
			);
		}

		$sub = isset( $_GET['ild_sub_filter'] ) ? sanitize_key( wp_unslash( $_GET['ild_sub_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( 'yes' === $sub ) {
			$meta_query[] = array(
				'key'   => '_ild_sub_one_marker',
				'value' => 'yes',
			);
		} elseif ( 'no' === $sub ) {
			// Blank markers are deleted, so "not marked" means the key is absent.
			$meta_query[] = array(
				'key'     => '_ild_sub_one_marker',
				'compare' => 'NOT EXISTS',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query );
		}

		// --- Family filter. -----------------------------------------------
		$family = isset( $_GET['ild_family_filter'] ) ? absint( $_GET['ild_family_filter'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( $family > 0 ) {
			$tax_query   = (array) $query->get( 'tax_query' );
			$tax_query[] = array(
				'taxonomy' => ILD_Post_Types::TAX_FAMILY,
				'field'    => 'term_id',
				'terms'    => $family,
			);
			$query->set( 'tax_query', $tax_query );
		}
	}

	/**
	 * Find every ingredient whose INCI name or aliases match a search term.
	 *
	 * The INCI name is the post title; the aliases live in the "_ild_also_known_as"
	 * meta. A single prepared query looks in both.
	 *
	 * @param string $term The raw search term.
	 * @return int[] The matching ingredient IDs.
	 */
	private function search_ingredient_ids( $term ) {
		global $wpdb;

		// Escape the term for a LIKE, then wrap it so it matches anywhere.
		$like = '%' . $wpdb->esc_like( trim( (string) $term ) ) . '%';

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON ( p.ID = m.post_id AND m.meta_key = '_ild_also_known_as' )
				WHERE p.post_type = %s
				AND ( p.post_title LIKE %s OR m.meta_value LIKE %s )
				GROUP BY p.ID",
				ILD_Post_Types::POST_TYPE,
				$like,
				$like
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Bulk actions
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Add the plugin's own bulk actions to the ingredient list.
	 *
	 * @param array $actions The existing bulk actions.
	 * @return array The extended list.
	 */
	public function add_bulk_actions( $actions ) {
		$actions['ild_set_published']    = __( 'Set status: Published', 'ingredient-list-decoder' );
		$actions['ild_set_needs_review'] = __( 'Set status: Needs Review', 'ingredient-list-decoder' );
		$actions['ild_set_draft']        = __( 'Set status: Draft', 'ingredient-list-decoder' );
		$actions['ild_assign_family']    = __( 'Add to family…', 'ingredient-list-decoder' );
		$actions['ild_assign_topic']     = __( 'Add to topic…', 'ingredient-list-decoder' );
		$actions['ild_export_selected']  = __( 'Export selected to CSV', 'ingredient-list-decoder' );

		return $actions;
	}

	/**
	 * Carry out one of the plugin's bulk actions.
	 *
	 * WordPress verifies the bulk nonce before this runs. Status changes are made
	 * here and the person is sent back with a count. Assigning a term needs a
	 * choice of term, so it hands off to the intermediate screen. Exporting a
	 * selection stashes the IDs and offers a download link on return.
	 *
	 * @param string $redirect Where to send the person afterwards.
	 * @param string $action   The chosen bulk action.
	 * @param array  $post_ids The selected ingredient IDs.
	 * @return string The redirect URL.
	 */
	public function handle_bulk_actions( $redirect, $action, $post_ids ) {
		$post_ids = array_map( 'absint', (array) $post_ids );
		$post_ids = array_filter( $post_ids );

		if ( empty( $post_ids ) ) {
			return $redirect;
		}

		// The three status changes share the same shape.
		$status_map = array(
			'ild_set_published'    => 'publish',
			'ild_set_needs_review' => ILD_Post_Types::STATUS_NEEDS_REVIEW,
			'ild_set_draft'        => 'draft',
		);

		if ( isset( $status_map[ $action ] ) ) {
			$target = $status_map[ $action ];
			$count  = 0;

			foreach ( $post_ids as $id ) {
				// Only touch entries this user is allowed to edit.
				if ( ! current_user_can( 'edit_post', $id ) ) {
					continue;
				}
				$result = wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => $target,
					),
					true
				);
				if ( ! is_wp_error( $result ) ) {
					$count++;
				}
			}

			return add_query_arg(
				array(
					'ild_status_done' => $count,
					'ild_status_to'   => $target,
				),
				$redirect
			);
		}

		// Assigning a family or a topic needs a term chosen on the next screen.
		if ( 'ild_assign_family' === $action || 'ild_assign_topic' === $action ) {
			$tax_key = ( 'ild_assign_family' === $action ) ? 'family' : 'topic';

			return wp_nonce_url(
				add_query_arg(
					array(
						'post_type' => ILD_Post_Types::POST_TYPE,
						'page'      => self::ASSIGN_PAGE_SLUG,
						'ild_tax'   => $tax_key,
						'ids'       => implode( ',', $post_ids ),
					),
					admin_url( 'edit.php' )
				),
				'ild_bulk_assign'
			);
		}

		// Exporting a selection: hold the IDs briefly, then offer a download.
		if ( 'ild_export_selected' === $action ) {
			$token = wp_generate_password( 20, false );
			set_transient( 'ild_export_sel_' . $token, $post_ids, self::SELECTION_TTL );

			return add_query_arg(
				array(
					'ild_export_ready' => $token,
					'ild_export_count' => count( $post_ids ),
				),
				$redirect
			);
		}

		return $redirect;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Extra screens: bulk-assign and the review queue
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Register the review queue and the hidden bulk-assign screens.
	 *
	 * @return void
	 */
	public function add_pages() {
		// The review queue, listed under the Ingredient Decoder menu.
		add_submenu_page(
			'edit.php?post_type=' . ILD_Post_Types::POST_TYPE,
			__( 'Review Queue', 'ingredient-list-decoder' ),
			__( 'Review Queue', 'ingredient-list-decoder' ),
			'edit_posts',
			self::REVIEW_PAGE_SLUG,
			array( $this, 'render_review_queue' )
		);

		// The "choose a term" step for the bulk assign actions. Parent null keeps
		// it out of the menu while still giving it a real, routable URL.
		add_submenu_page(
			null,
			__( 'Assign a term', 'ingredient-list-decoder' ),
			'',
			'edit_posts',
			self::ASSIGN_PAGE_SLUG,
			array( $this, 'render_bulk_assign' )
		);
	}

	/**
	 * Draw the intermediate "choose a term" screen for the assign bulk actions.
	 *
	 * On first arrival it shows a dropdown of the taxonomy's terms and the count
	 * of selected entries. On submit it adds the chosen term to each and returns
	 * to the list with a summary.
	 *
	 * @return void
	 */
	public function render_bulk_assign() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ingredient-list-decoder' ) );
		}

		// --- Applying the choice. -----------------------------------------
		if ( isset( $_POST['ild_assign_apply'] ) ) {
			check_admin_referer( 'ild_bulk_assign_apply' );

			$tax_key  = isset( $_POST['ild_tax'] ) ? sanitize_key( wp_unslash( $_POST['ild_tax'] ) ) : '';
			$taxonomy = $this->taxonomy_from_key( $tax_key );
			$term_id  = isset( $_POST['ild_term'] ) ? absint( $_POST['ild_term'] ) : 0;
			$ids      = isset( $_POST['ild_ids'] ) ? $this->parse_id_list( wp_unslash( $_POST['ild_ids'] ) ) : array();

			$count = 0;
			if ( $taxonomy && $term_id && ! empty( $ids ) ) {
				foreach ( $ids as $id ) {
					if ( ! current_user_can( 'edit_post', $id ) ) {
						continue;
					}
					// Append (do not replace) so an existing family or topic stays.
					$result = wp_set_object_terms( $id, array( $term_id ), $taxonomy, true );
					if ( ! is_wp_error( $result ) ) {
						$count++;
					}
				}
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'ild_assigned'     => $count,
						'ild_assigned_tax' => $tax_key,
					),
					admin_url( 'edit.php?post_type=' . ILD_Post_Types::POST_TYPE )
				)
			);
			exit;
		}

		// --- Showing the chooser (arrived from the bulk action). ----------
		check_admin_referer( 'ild_bulk_assign' );

		$tax_key  = isset( $_GET['ild_tax'] ) ? sanitize_key( wp_unslash( $_GET['ild_tax'] ) ) : '';
		$taxonomy = $this->taxonomy_from_key( $tax_key );
		$ids      = isset( $_GET['ids'] ) ? $this->parse_id_list( wp_unslash( $_GET['ids'] ) ) : array();

		if ( ! $taxonomy || empty( $ids ) ) {
			wp_die( esc_html__( 'There was nothing to assign. Please go back and select some ingredients.', 'ingredient-list-decoder' ) );
		}

		$heading = ( 'family' === $tax_key )
			? __( 'Add a family to the selected ingredients', 'ingredient-list-decoder' )
			: __( 'Add a topic to the selected ingredients', 'ingredient-list-decoder' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $heading ) . '</h1>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of selected ingredients. */
					_n( '%d ingredient selected.', '%d ingredients selected.', count( $ids ), 'ingredient-list-decoder' ),
					count( $ids )
				)
			)
		);

		echo '<form method="post">';
		wp_nonce_field( 'ild_bulk_assign_apply' );
		echo '<input type="hidden" name="ild_assign_apply" value="1" />';
		printf( '<input type="hidden" name="ild_tax" value="%s" />', esc_attr( $tax_key ) );
		printf( '<input type="hidden" name="ild_ids" value="%s" />', esc_attr( implode( ',', $ids ) ) );

		echo '<p>';
		wp_dropdown_categories(
			array(
				'taxonomy'        => $taxonomy,
				'name'            => 'ild_term',
				'id'              => 'ild_term',
				'show_option_none' => __( '— Choose a term —', 'ingredient-list-decoder' ),
				'option_none_value' => 0,
				'hierarchical'    => true,
				'hide_empty'      => false,
				'orderby'         => 'name',
				'value_field'     => 'term_id',
			)
		);
		echo '</p>';

		submit_button( __( 'Add to selected ingredients', 'ingredient-list-decoder' ) );

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'edit.php?post_type=' . ILD_Post_Types::POST_TYPE ) ),
			esc_html__( 'Cancel and return to the list', 'ingredient-list-decoder' )
		);

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Draw the review queue: everything still in draft or needs-review.
	 *
	 * For now the queue is ordered alphabetically by INCI name. Once submission
	 * data exists (a later stage), the most-requested entries can be floated to
	 * the top by filtering the query args through 'ild_review_queue_query_args'.
	 *
	 * @return void
	 */
	public function render_review_queue() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ingredient-list-decoder' ) );
		}

		// The default ordering: alphabetical. A later stage can reorder this by
		// hooking the filter to sort on a submission-count meta value instead.
		$args = apply_filters(
			'ild_review_queue_query_args',
			array(
				'post_type'      => ILD_Post_Types::POST_TYPE,
				'post_status'    => array( 'draft', ILD_Post_Types::STATUS_NEEDS_REVIEW ),
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);

		$query = new WP_Query( $args );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Review Queue', 'ingredient-list-decoder' ) . '</h1>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Entries still in draft or needing review. Ordered alphabetically for now; once ingredients can be requested from the front end, the most-requested entries will rise to the top.', 'ingredient-list-decoder' )
		);

		if ( ! $query->have_posts() ) {
			printf( '<p>%s</p>', esc_html__( 'Nothing is waiting for review. Everything is either published or trashed.', 'ingredient-list-decoder' ) );
			echo '</div>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %d: number of entries awaiting review. */
					_n( '%d entry waiting.', '%d entries waiting.', (int) $query->found_posts, 'ingredient-list-decoder' ),
					(int) $query->found_posts
				)
			)
		);

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'INCI name', 'ingredient-list-decoder' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ingredient-list-decoder' ) . '</th>';
		echo '<th>' . esc_html__( 'Family', 'ingredient-list-decoder' ) . '</th>';
		echo '<th>' . esc_html__( 'Role', 'ingredient-list-decoder' ) . '</th>';
		echo '<th>' . esc_html__( 'Last modified', 'ingredient-list-decoder' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $query->posts as $post ) {
			$edit_link = get_edit_post_link( $post->ID );
			$title     = get_the_title( $post ) ? get_the_title( $post ) : __( '(no INCI name)', 'ingredient-list-decoder' );

			// Family names for this entry.
			$families      = wp_get_object_terms( $post->ID, ILD_Post_Types::TAX_FAMILY, array( 'fields' => 'names' ) );
			$family_text   = ( is_array( $families ) && ! empty( $families ) ) ? implode( ', ', $families ) : '—';

			// Role labels for this entry.
			$roles      = get_post_meta( $post->ID, '_ild_role', true );
			$role_text  = ( is_array( $roles ) && ! empty( $roles ) ) ? implode( ', ', array_map( array( 'ILD_Roles', 'get_label' ), $roles ) ) : '—';

			echo '<tr>';
			if ( $edit_link ) {
				printf( '<td><a href="%s"><strong>%s</strong></a></td>', esc_url( $edit_link ), esc_html( $title ) );
			} else {
				printf( '<td><strong>%s</strong></td>', esc_html( $title ) );
			}
			printf( '<td>%s</td>', esc_html( $this->status_label( $post->post_status ) ) );
			printf( '<td>%s</td>', esc_html( $family_text ) );
			printf( '<td>%s</td>', esc_html( $role_text ) );
			printf( '<td>%s</td>', esc_html( get_the_modified_date( '', $post->ID ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Duplicate blocking
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Stop a save that would create a second entry with an existing INCI name.
	 *
	 * Runs just before the post is written. If another ingredient already holds
	 * this exact INCI name, the entry being saved is forced down to a draft (so a
	 * duplicate can never go live) and a message is queued naming the entry that
	 * already has the name. The importer and status changes are unaffected: they
	 * always pass the entry's own ID, which is excluded from the check.
	 *
	 * @param array $data    The sanitised post fields about to be written.
	 * @param array $postarr The raw submitted post array (holds the ID, if any).
	 * @return array The (possibly adjusted) post fields.
	 */
	public function block_duplicate_inci( $data, $postarr ) {
		// Only our post type is guarded.
		if ( empty( $data['post_type'] ) || ILD_Post_Types::POST_TYPE !== $data['post_type'] ) {
			return $data;
		}

		// Leave auto-drafts and revisions alone; they are not real entries yet.
		if ( isset( $data['post_status'] ) && 'auto-draft' === $data['post_status'] ) {
			return $data;
		}
		if ( ! empty( $postarr['post_type'] ) && 'revision' === $postarr['post_type'] ) {
			return $data;
		}

		// A blank title cannot clash with anything.
		$title = isset( $data['post_title'] ) ? trim( wp_unslash( $data['post_title'] ) ) : '';
		if ( '' === $title ) {
			return $data;
		}

		// Look for another entry with the same name, ignoring this one.
		$exclude  = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$existing = ild_find_ingredient_by_title( $title, $exclude );

		if ( $existing ) {
			// Never let the duplicate reach a live or review state.
			if ( isset( $data['post_status'] ) && ! in_array( $data['post_status'], array( 'draft', 'trash' ), true ) ) {
				$data['post_status'] = 'draft';
			}

			// Remember which entry it clashed with, so a notice can name it.
			set_transient( 'ild_dupe_' . get_current_user_id(), $existing, MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Admin notices
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Show the flash messages: a blocked duplicate, or the result of a bulk action.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || ILD_Post_Types::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// On the single editor: the "that INCI name already exists" message.
		if ( 'post' === $screen->base ) {
			$existing = get_transient( 'ild_dupe_' . get_current_user_id() );
			if ( $existing ) {
				delete_transient( 'ild_dupe_' . get_current_user_id() );

				$existing = (int) $existing;
				$name     = get_the_title( $existing );
				$link     = get_edit_post_link( $existing );

				$message = sprintf(
					/* translators: 1: the INCI name, 2: the existing entry's ID. */
					__( 'An ingredient with the INCI name “%1$s” already exists (entry #%2$d). This entry was kept as a draft so the library never holds two entries for the same INCI name.', 'ingredient-list-decoder' ),
					$name,
					$existing
				);

				echo '<div class="notice notice-error"><p>';
				echo esc_html( $message );
				if ( $link ) {
					printf( ' <a href="%s">%s</a>', esc_url( $link ), esc_html__( 'Edit the existing entry', 'ingredient-list-decoder' ) );
				}
				echo '</p></div>';
			}
			return;
		}

		// On the list screen: results of the bulk actions.
		if ( 'edit' !== $screen->base ) {
			return;
		}

		// Status change.
		if ( isset( $_GET['ild_status_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash message.
			$done   = absint( $_GET['ild_status_done'] );
			$to     = isset( $_GET['ild_status_to'] ) ? sanitize_key( wp_unslash( $_GET['ild_status_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sprintf(
				/* translators: 1: number of entries, 2: the new status label. */
				_n( '%1$d ingredient set to %2$s.', '%1$d ingredients set to %2$s.', $done, 'ingredient-list-decoder' ),
				$done,
				$this->status_label( $to )
			);
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		// Term assigned.
		if ( isset( $_GET['ild_assigned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash message.
			$done     = absint( $_GET['ild_assigned'] );
			$tax_key  = isset( $_GET['ild_assigned_tax'] ) ? sanitize_key( wp_unslash( $_GET['ild_assigned_tax'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$tax_word = ( 'family' === $tax_key ) ? __( 'family', 'ingredient-list-decoder' ) : __( 'topic', 'ingredient-list-decoder' );
			$message  = sprintf(
				/* translators: 1: number of entries, 2: "family" or "topic". */
				_n( 'Added the %2$s to %1$d ingredient.', 'Added the %2$s to %1$d ingredients.', $done, 'ingredient-list-decoder' ),
				$done,
				$tax_word
			);
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}

		// A selection is ready to export: offer the download.
		if ( isset( $_GET['ild_export_ready'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flash message.
			$token = sanitize_text_field( wp_unslash( $_GET['ild_export_ready'] ) );
			$count = isset( $_GET['ild_export_count'] ) ? absint( $_GET['ild_export_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// The download reuses the CSV exporter, restricted to the held IDs.
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=ild_export_ingredients&sel=' . rawurlencode( $token ) ),
				'ild_csv_export'
			);

			$message = sprintf(
				/* translators: %d: number of selected ingredients. */
				_n( '%d ingredient is ready to export.', '%d ingredients are ready to export.', $count, 'ingredient-list-decoder' ),
				$count
			);

			echo '<div class="notice notice-info is-dismissible"><p>';
			echo esc_html( $message ) . ' ';
			printf(
				'<a class="button button-primary" href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'Download CSV', 'ingredient-list-decoder' )
			);
			echo '</p></div>';
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Small shared helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Whether a query is the main ingredient list query in the admin.
	 *
	 * @param WP_Query $query The query to test.
	 * @return bool True if it is the ingredient list on edit.php.
	 */
	private function is_ingredient_admin_query( $query ) {
		if ( ! is_admin() || ! ( $query instanceof WP_Query ) || ! $query->is_main_query() ) {
			return false;
		}

		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return false;
		}

		return ILD_Post_Types::POST_TYPE === $query->get( 'post_type' );
	}

	/**
	 * Map a short taxonomy key ('family' or 'topic') to its taxonomy name.
	 *
	 * @param string $key The short key.
	 * @return string The taxonomy name, or '' if the key is not recognised.
	 */
	private function taxonomy_from_key( $key ) {
		if ( 'family' === $key ) {
			return ILD_Post_Types::TAX_FAMILY;
		}
		if ( 'topic' === $key ) {
			return ILD_Post_Types::TAX_TOPIC;
		}

		return '';
	}

	/**
	 * Turn a comma-separated string of IDs into a clean array of positive ints.
	 *
	 * @param string $raw The raw "1,2,3" string.
	 * @return int[] The cleaned IDs.
	 */
	private function parse_id_list( $raw ) {
		$ids = array_map( 'absint', explode( ',', (string) $raw ) );

		return array_values( array_filter( $ids ) );
	}

	/**
	 * The human label for a post status, as shown in the Status column.
	 *
	 * @param string $status The WordPress post status.
	 * @return string The label to display.
	 */
	private function status_label( $status ) {
		switch ( $status ) {
			case 'publish':
				return __( 'Published', 'ingredient-list-decoder' );
			case ILD_Post_Types::STATUS_NEEDS_REVIEW:
				return __( 'Needs Review', 'ingredient-list-decoder' );
			case 'draft':
				return __( 'Draft', 'ingredient-list-decoder' );
			case 'pending':
				return __( 'Pending', 'ingredient-list-decoder' );
			case 'trash':
				return __( 'Trash', 'ingredient-list-decoder' );
			default:
				return ucfirst( $status );
		}
	}
}
