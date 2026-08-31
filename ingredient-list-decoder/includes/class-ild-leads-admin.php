<?php
/**
 * The leads admin screen.
 *
 * A custom list table of every captured address, with its date, consent state,
 * the consent wording shown at the time, the source page, and its connector
 * sync status. It can be filtered by date range and sync status, searched by
 * address, exported to CSV, and records can be deleted. A failed-sync view lists
 * anything a connector rejected, each row offering a retry — because a connector
 * outage is otherwise silent.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the leads screen and its actions.
 */
class ILD_Leads_Admin {

	/**
	 * The admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ild-leads';

	/**
	 * The admin-post action for the CSV export.
	 *
	 * @var string
	 */
	const EXPORT_ACTION = 'ild_export_leads';

	/**
	 * The page hook suffix, set when the menu page is added.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Hook the menu, the action processing and the export endpoint.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export_csv' ) );
	}

	/**
	 * Add the Leads screen under the Ingredient Decoder menu.
	 *
	 * @return void
	 */
	public function add_page() {
		$this->hook = add_submenu_page(
			'edit.php?post_type=' . ILD_Post_Types::POST_TYPE,
			__( 'Leads', 'ingredient-list-decoder' ),
			__( 'Leads', 'ingredient-list-decoder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook ) {
			// Process row and bulk actions before the screen renders, so a
			// delete or retry can redirect cleanly.
			add_action( 'load-' . $this->hook, array( $this, 'process_actions' ) );
		}
	}

	/**
	 * The URL of this screen, with optional extra query args.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => ILD_Post_Types::POST_TYPE,
					'page'      => self::PAGE_SLUG,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Read the current filters from the request, all sanitised.
	 *
	 * @return array
	 */
	public static function current_filters() {
		$get = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security -- Read-only display filters, sanitised below; actions verify their own nonces.

		$date = function ( $value ) {
			$value = isset( $value ) ? sanitize_text_field( $value ) : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};

		$sync = isset( $get['sync'] ) ? sanitize_key( $get['sync'] ) : '';
		if ( ! in_array( $sync, array( ILD_Leads::SYNC_PENDING, ILD_Leads::SYNC_SYNCED, ILD_Leads::SYNC_FAILED ), true ) ) {
			$sync = '';
		}

		return array(
			's'         => isset( $get['s'] ) ? sanitize_text_field( $get['s'] ) : '',
			'sync'      => $sync,
			'date_from' => $date( isset( $get['date_from'] ) ? $get['date_from'] : '' ),
			'date_to'   => $date( isset( $get['date_to'] ) ? $get['date_to'] : '' ),
			'orderby'   => isset( $get['orderby'] ) && 'email' === $get['orderby'] ? 'email' : 'date',
			'order'     => isset( $get['order'] ) && 'asc' === strtolower( (string) $get['order'] ) ? 'asc' : 'desc',
			'paged'     => isset( $get['paged'] ) ? max( 1, (int) $get['paged'] ) : 1,
		);
	}

	/**
	 * Handle a delete, retry, or bulk delete, then redirect.
	 *
	 * @return void
	 */
	public function process_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		}
		if ( '' === $action && isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		if ( '' === $action ) {
			return;
		}

		$notice = '';
		$count  = 0;

		// Bulk delete from the list table.
		if ( 'delete' === $action && isset( $_REQUEST['lead'] ) && is_array( $_REQUEST['lead'] ) ) {
			check_admin_referer( 'bulk-ild_leads' );
			foreach ( array_map( 'absint', wp_unslash( $_REQUEST['lead'] ) ) as $lead_id ) {
				if ( ILD_Leads::delete_lead( $lead_id ) ) {
					$count++;
				}
			}
			$notice = 'deleted';
		} elseif ( in_array( $action, array( 'delete', 'retry' ), true ) && isset( $_REQUEST['lead'] ) ) {
			// Single-row delete or retry.
			$lead_id = absint( wp_unslash( $_REQUEST['lead'] ) );
			check_admin_referer( 'ild_lead_row_' . $lead_id );

			if ( 'delete' === $action ) {
				$count  = ILD_Leads::delete_lead( $lead_id ) ? 1 : 0;
				$notice = 'deleted';
			} else {
				ILD_Leads::retry_sync( $lead_id );
				$count  = 1;
				$notice = 'retried';
			}
		} else {
			return;
		}

		$filters = self::current_filters();
		wp_safe_redirect(
			self::page_url(
				array(
					'sync'         => $filters['sync'],
					's'            => $filters['s'],
					'date_from'    => $filters['date_from'],
					'date_to'      => $filters['date_to'],
					'ild_notice'   => $notice,
					'ild_count'    => $count,
				)
			)
		);
		exit;
	}

	/**
	 * Render the leads screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ILD_PLUGIN_DIR . 'includes/class-ild-leads-table.php';

		$table = new ILD_Leads_Table( self::current_filters() );
		$table->prepare_items();

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::EXPORT_ACTION . '&' . http_build_query( array_filter( array(
				'sync'      => $table->filters['sync'],
				's'         => $table->filters['s'],
				'date_from' => $table->filters['date_from'],
				'date_to'   => $table->filters['date_to'],
			) ) ) ),
			self::EXPORT_ACTION
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Leads', 'ingredient-list-decoder' ); ?></h1>
			<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export filtered to CSV', 'ingredient-list-decoder' ); ?></a>
			<hr class="wp-header-end" />

			<?php $this->render_notice(); ?>

			<?php $table->views(); ?>

			<form method="get">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( ILD_Post_Types::POST_TYPE ); ?>" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<?php
				// The sync filter is offered as a select in the table's toolbar, so
				// no hidden field here — otherwise two "sync" values would submit.
				$table->search_box( __( 'Search addresses', 'ingredient-list-decoder' ), 'ild-lead-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Show a notice after a delete or retry.
	 *
	 * @return void
	 */
	private function render_notice() {
		if ( empty( $_GET['ild_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice after a redirect.
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['ild_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$count  = isset( $_GET['ild_count'] ) ? (int) $_GET['ild_count'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'deleted' === $notice ) {
			/* translators: %d: number of leads deleted. */
			$message = sprintf( _n( '%d lead deleted.', '%d leads deleted.', $count, 'ingredient-list-decoder' ), $count );
		} elseif ( 'retried' === $notice ) {
			$message = __( 'Queued for another sync attempt.', 'ingredient-list-decoder' );
		} elseif ( 'exported' === $notice ) {
			$message = __( 'Export complete.', 'ingredient-list-decoder' );
		} else {
			return;
		}

		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/**
	 * Stream the filtered leads as a CSV download.
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ingredient-list-decoder' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );

		$filters             = self::current_filters();
		$args                = ILD_Leads::query_args( $filters );
		$args['posts_per_page'] = -1;
		$args['paged']       = 1;
		$args['fields']      = 'ids';

		$ids = get_posts( $args );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ild-leads-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				__( 'Email', 'ingredient-list-decoder' ),
				__( 'Captured (GMT)', 'ingredient-list-decoder' ),
				__( 'Consent', 'ingredient-list-decoder' ),
				__( 'Consent wording shown', 'ingredient-list-decoder' ),
				__( 'Source page', 'ingredient-list-decoder' ),
				__( 'Sync status', 'ingredient-list-decoder' ),
				__( 'Sync error', 'ingredient-list-decoder' ),
			)
		);

		foreach ( $ids as $lead_id ) {
			$lead = ILD_Leads::get_lead( $lead_id );
			fputcsv(
				$out,
				array(
					$lead['email'],
					$lead['captured'],
					$lead['consent'],
					$lead['consent_text'],
					$lead['source'],
					$lead['sync'],
					$lead['sync_error'],
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
