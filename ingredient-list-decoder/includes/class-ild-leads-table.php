<?php
/**
 * The leads list table.
 *
 * A WP_List_Table over the captured addresses: date, consent, the consent
 * wording shown, source, and sync status, with per-row delete and (for failed
 * syncs) retry, a bulk delete, date-range and sync filters, and status views.
 *
 * Loaded only on the leads screen, after WP_List_Table itself is available.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The captured-leads table.
 */
class ILD_Leads_Table extends WP_List_Table {

	/**
	 * The active filters.
	 *
	 * @var array
	 */
	public $filters;

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private $per_page = 20;

	/**
	 * Build the table.
	 *
	 * @param array $filters The active filters.
	 */
	public function __construct( $filters = array() ) {
		$this->filters = $filters;
		parent::__construct(
			array(
				'singular' => 'lead',
				'plural'   => 'ild_leads',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'email'        => __( 'Address', 'ingredient-list-decoder' ),
			'captured'     => __( 'Date', 'ingredient-list-decoder' ),
			'consent'      => __( 'Consent', 'ingredient-list-decoder' ),
			'consent_text' => __( 'Consent wording shown', 'ingredient-list-decoder' ),
			'source'       => __( 'Source page', 'ingredient-list-decoder' ),
			'submissions'  => __( 'Submissions', 'ingredient-list-decoder' ),
			'sync'         => __( 'Sync status', 'ingredient-list-decoder' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'email'    => array( 'email', false ),
			'captured' => array( 'date', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'ingredient-list-decoder' ) );
	}

	/**
	 * The message when there are no leads.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No leads found.', 'ingredient-list-decoder' );
	}

	/**
	 * Load the items for the current page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'email' );

		$this->process_column_headers_sort();

		$filters             = $this->filters;
		$filters['per_page'] = $this->per_page;

		$args           = ILD_Leads::query_args( $filters );
		$args['fields'] = 'ids';

		$query = new WP_Query( $args );

		$this->items = array();
		foreach ( $query->posts as $lead_id ) {
			$this->items[] = ILD_Leads::get_lead( $lead_id );
		}

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $this->per_page,
				'total_pages' => (int) ceil( $query->found_posts / $this->per_page ),
			)
		);
	}

	/**
	 * Fold the current sort request into the filters.
	 *
	 * @return void
	 */
	private function process_column_headers_sort() {
		if ( isset( $this->filters['orderby'] ) && 'email' === $this->filters['orderby'] ) {
			$this->filters['orderby'] = 'email';
		}
	}

	/**
	 * The checkbox column.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="lead[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * The address column, with the row actions.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_email( $item ) {
		$id = (int) $item['id'];

		$delete_url = wp_nonce_url( ILD_Leads_Admin::page_url( array( 'action' => 'delete', 'lead' => $id ) ), 'ild_lead_row_' . $id );
		$actions    = array(
			'delete' => sprintf( '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>', esc_url( $delete_url ), esc_js( __( 'Delete this lead permanently?', 'ingredient-list-decoder' ) ), esc_html__( 'Delete', 'ingredient-list-decoder' ) ),
		);

		// A retry only makes sense on a failed sync.
		if ( ILD_Leads::SYNC_FAILED === $item['sync'] ) {
			$retry_url        = wp_nonce_url( ILD_Leads_Admin::page_url( array( 'action' => 'retry', 'lead' => $id ) ), 'ild_lead_row_' . $id );
			$actions['retry'] = sprintf( '<a href="%s">%s</a>', esc_url( $retry_url ), esc_html__( 'Retry sync', 'ingredient-list-decoder' ) );
		}

		return '<strong>' . esc_html( $item['email'] ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * The date column.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_captured( $item ) {
		if ( '' === $item['captured'] ) {
			return '&mdash;';
		}
		$local = get_date_from_gmt( $item['captured'] );
		return esc_html( mysql2date( 'Y-m-d H:i', $local ) );
	}

	/**
	 * The consent column.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_consent( $item ) {
		if ( '' !== $item['unsubscribed'] ) {
			return '<span style="color:#b3261e;">' . esc_html__( 'Unsubscribed', 'ingredient-list-decoder' ) . '</span>';
		}
		return 'yes' === $item['consent']
			? esc_html__( 'Given', 'ingredient-list-decoder' )
			: esc_html__( 'Not given', 'ingredient-list-decoder' );
	}

	/**
	 * The consent-wording column, truncated with the full text on hover.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_consent_text( $item ) {
		$text = $item['consent_text'];
		if ( '' === $text ) {
			return '&mdash;';
		}
		$short = wp_html_excerpt( $text, 80, '…' );
		return '<span title="' . esc_attr( $text ) . '">' . esc_html( $short ) . '</span>';
	}

	/**
	 * The source column.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_source( $item ) {
		if ( '' === $item['source'] ) {
			return '&mdash;';
		}
		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
			esc_url( $item['source'] ),
			esc_html( wp_html_excerpt( $item['source'], 40, '…' ) )
		);
	}

	/**
	 * The submission-history column: the lists this address has decoded.
	 *
	 * Read from the submissions store by lead ID, showing each with its date and
	 * product name where one was given.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_submissions( $item ) {
		$history = ILD_Submissions::get_by_lead( $item['id'] );
		if ( empty( $history ) ) {
			return '&mdash;';
		}

		$lines = array();
		foreach ( $history as $submission ) {
			$date    = '' !== $submission['captured'] ? mysql2date( 'Y-m-d', get_date_from_gmt( $submission['captured'] ) ) : '';
			$product = '' !== $submission['product'] ? $submission['product'] : __( '(no product name)', 'ingredient-list-decoder' );
			$lines[] = '<span>' . esc_html( trim( $date . ' — ' . $product, ' —' ) ) . '</span>';
		}

		return '<div class="ild-lead-submissions">' . implode( '<br />', $lines ) . '</div>';
	}

	/**
	 * The sync-status column.
	 *
	 * @param array $item The row.
	 * @return string
	 */
	public function column_sync( $item ) {
		$labels = ILD_Leads::sync_statuses();
		$label  = isset( $labels[ $item['sync'] ] ) ? $labels[ $item['sync'] ] : $item['sync'];
		$colour = ILD_Leads::SYNC_FAILED === $item['sync'] ? '#b3261e' : ( ILD_Leads::SYNC_SYNCED === $item['sync'] ? '#1f7a3d' : '#8a6d1a' );

		$out = '<span style="color:' . esc_attr( $colour ) . ';">' . esc_html( $label ) . '</span>';
		if ( ILD_Leads::SYNC_FAILED === $item['sync'] && '' !== $item['sync_error'] ) {
			$out .= '<br /><small>' . esc_html( $item['sync_error'] ) . '</small>';
		}
		return $out;
	}

	/**
	 * Fallback for any column without its own method.
	 *
	 * @param array  $item        The row.
	 * @param string $column_name The column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * The status views, including the failed-sync view.
	 *
	 * @return array
	 */
	public function get_views() {
		$link = function ( $sync, $label ) {
			$url   = '' === $sync ? ILD_Leads_Admin::page_url() : ILD_Leads_Admin::page_url( array( 'sync' => $sync ) );
			$class = ( $sync === $this->filters['sync'] ) ? ' class="current"' : '';
			return sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $class, esc_html( $label ), $this->count_for( $sync ) );
		};

		return array(
			'all'                    => $link( '', __( 'All', 'ingredient-list-decoder' ) ),
			ILD_Leads::SYNC_PENDING  => $link( ILD_Leads::SYNC_PENDING, __( 'Pending', 'ingredient-list-decoder' ) ),
			ILD_Leads::SYNC_SYNCED   => $link( ILD_Leads::SYNC_SYNCED, __( 'Synced', 'ingredient-list-decoder' ) ),
			ILD_Leads::SYNC_FAILED   => $link( ILD_Leads::SYNC_FAILED, __( 'Failed sync', 'ingredient-list-decoder' ) ),
		);
	}

	/**
	 * Count the leads for a sync status ('' means all).
	 *
	 * @param string $sync The status, or ''.
	 * @return int
	 */
	private function count_for( $sync ) {
		$args = array(
			'post_type'      => ILD_Leads::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);
		if ( '' !== $sync ) {
			$args['meta_query'] = array( array( 'key' => ILD_Leads::META_SYNC, 'value' => $sync ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/**
	 * The date-range filter controls, above the table.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="ild-date-from"><?php esc_html_e( 'From date', 'ingredient-list-decoder' ); ?></label>
			<input type="date" id="ild-date-from" name="date_from" value="<?php echo esc_attr( $this->filters['date_from'] ); ?>" />
			<label class="screen-reader-text" for="ild-date-to"><?php esc_html_e( 'To date', 'ingredient-list-decoder' ); ?></label>
			<input type="date" id="ild-date-to" name="date_to" value="<?php echo esc_attr( $this->filters['date_to'] ); ?>" />

			<label class="screen-reader-text" for="ild-sync"><?php esc_html_e( 'Sync status', 'ingredient-list-decoder' ); ?></label>
			<select id="ild-sync" name="sync">
				<option value=""><?php esc_html_e( 'All sync statuses', 'ingredient-list-decoder' ); ?></option>
				<?php foreach ( ILD_Leads::sync_statuses() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->filters['sync'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php submit_button( __( 'Filter', 'ingredient-list-decoder' ), 'secondary', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
