<?php
/**
 * The unknown-ingredients admin screen.
 *
 * Lists the unmatched tokens by how often they've been submitted, with a dismiss
 * action for typos and rubbish and a draft-entry button that asks the Anthropic
 * API to draft a needs-review ingredient. Nothing is ever published from here.
 *
 * It also feeds the appearance count into the Stage 3 review queue ordering, so
 * the most-requested drafts float to the top of the review list.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the unknown-ingredients screen.
 */
class ILD_Unknown_Admin {

	/**
	 * The admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ild-unknown-ingredients';

	/**
	 * The page hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Hook the menu, the action processing and the review-queue ordering.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_filter( 'ild_review_queue_query_args', array( $this, 'order_review_queue' ) );
	}

	/**
	 * Add the Unknown ingredients screen under the Ingredient Decoder menu.
	 *
	 * @return void
	 */
	public function add_page() {
		$this->hook = add_submenu_page(
			'edit.php?post_type=' . ILD_Post_Types::POST_TYPE,
			__( 'Unknown ingredients', 'ingredient-list-decoder' ),
			__( 'Unknown ingredients', 'ingredient-list-decoder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook ) {
			add_action( 'load-' . $this->hook, array( $this, 'process_actions' ) );
		}
	}

	/**
	 * This screen's URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url( $args = array() ) {
		return add_query_arg(
			array_merge(
				array( 'post_type' => ILD_Post_Types::POST_TYPE, 'page' => self::PAGE_SLUG ),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Handle a bulk action (delete or export), a single dismiss or a draft.
	 *
	 * Runs on the screen's load hook, before any HTML is sent, so the CSV export
	 * can stream a file straight to the browser.
	 *
	 * @return void
	 */
	public function process_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Bulk actions (delete / export) arrive by POST from the queue form.
		if ( isset( $_POST['ild_unknown_bulk'] ) ) {
			$this->process_bulk();
			return;
		}

		// Single dismiss/draft actions arrive as nonce-checked GET links.
		if ( empty( $_GET['action'] ) || empty( $_GET['token'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		$id     = absint( wp_unslash( $_GET['token'] ) );
		check_admin_referer( 'ild_token_' . $id );

		$row = ILD_Unknown_Tokens::get( $id );
		if ( ! $row ) {
			$this->redirect( 'gone' );
		}

		if ( 'dismiss' === $action ) {
			ILD_Unknown_Tokens::dismiss( $id );
			$this->redirect( 'dismissed' );
		}

		if ( 'draft' === $action ) {
			$result = ILD_AI_Drafter::draft( $row['token'], (int) $row['appearances'] );
			if ( is_wp_error( $result ) ) {
				$this->redirect( 'draft_failed', 0, $result->get_error_message() );
			}
			ILD_Unknown_Tokens::mark_drafted( $id, $result );
			$this->redirect( 'drafted', (int) $result );
		}

		$this->redirect( '' );
	}

	/**
	 * Handle a bulk action from the queue form: delete, export selected, export all.
	 *
	 * Delete redirects back with a count; either export streams a CSV and exits.
	 * The CSV uses the importer's own columns with each token in the INCI-name
	 * column and the rest blank, so it can be filled in (by hand or with Claude)
	 * and imported straight back with no re-shaping.
	 *
	 * @return void
	 */
	private function process_bulk() {
		check_admin_referer( 'ild_unknown_bulk' );

		// The chosen row ids (may be empty for "export all").
		$ids = array();
		if ( isset( $_POST['ild_ids'] ) && is_array( $_POST['ild_ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['ild_ids'] ) ) ) );
		}

		// The "Export all" button ignores the selection entirely.
		if ( isset( $_POST['ild_export_all'] ) ) {
			$this->stream_csv( ILD_Unknown_Tokens::get_all_open(), 'all' );
		}

		// Every other bulk action works on the selection, so it must not be empty.
		if ( empty( $ids ) ) {
			$this->redirect( 'none' );
		}

		$action = isset( $_POST['ild_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['ild_bulk_action'] ) ) : '';

		if ( 'export' === $action ) {
			$this->stream_csv( ILD_Unknown_Tokens::get_by_ids( $ids ), 'selection' );
		}

		if ( 'delete' === $action ) {
			$removed = ILD_Unknown_Tokens::delete_ids( $ids );
			$this->redirect( 'deleted', 0, '', $removed );
		}

		// No recognised action chosen: just go back.
		$this->redirect( '' );
	}

	/**
	 * Stream a set of tokens out as a CSV and exit.
	 *
	 * The header row and column order match the ingredient importer exactly, with
	 * the token placed in the INCI-name column and every other column left blank,
	 * so a filled-in file re-imports cleanly (each row lands as a needs-review
	 * ingredient).
	 *
	 * @param array  $rows  The token rows to write.
	 * @param string $scope 'all' or 'selection', used only in the filename.
	 * @return void
	 */
	private function stream_csv( $rows, $scope = 'selection' ) {
		$columns    = array_keys( ILD_CSV::get_columns() );
		$inci_index = array_search( 'inci_name', $columns, true );
		if ( false === $inci_index ) {
			$inci_index = 0;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ild-unknown-ingredients-' . ( 'all' === $scope ? 'all' : 'selection' ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		// A byte-order mark, so Excel opens the file as UTF-8.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to the output stream, not the filesystem.

		// The header row: the importer's field names, in order.
		fputcsv( $output, $columns );

		foreach ( (array) $rows as $row ) {
			$line                = array_fill( 0, count( $columns ), '' );
			$line[ $inci_index ] = isset( $row['token'] ) ? (string) $row['token'] : '';
			fputcsv( $output, $line );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the output stream.
		exit;
	}

	/**
	 * Redirect back to the screen with a notice.
	 *
	 * @param string $notice        The notice key.
	 * @param int    $ingredient_id The drafted ingredient, if any.
	 * @param string $message       An extra message (a draft error).
	 * @param int    $count         A count to carry (e.g. how many were deleted).
	 * @return void
	 */
	private function redirect( $notice, $ingredient_id = 0, $message = '', $count = 0 ) {
		$args = array( 'ild_notice' => $notice );
		if ( $ingredient_id ) {
			$args['ild_new'] = $ingredient_id;
		}
		if ( $count ) {
			$args['ild_count'] = (int) $count;
		}
		if ( '' !== $message ) {
			$args['ild_msg'] = rawurlencode( $message );
		}
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tokens = ILD_Unknown_Tokens::get_open( 200 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Unknown ingredients', 'ingredient-list-decoder' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Ingredients people have pasted that aren\'t in the library yet, most-submitted first. Dismiss typos and rubbish; draft a real one into a needs-review entry to check.', 'ingredient-list-decoder' ); ?></p>
			<p class="description"><?php esc_html_e( 'Tick the ones you want, then use the bulk actions to delete them or export them to a CSV. The export uses the same columns as the ingredient importer, with each name already filled in — so you can complete the rest (by hand or by handing the file to Claude) and import it straight back under Import / Export.', 'ingredient-list-decoder' ); ?></p>

			<?php $this->render_notice(); ?>

			<?php if ( ! ILD_AI_Drafter::is_available() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Drafting is off. Add an Anthropic API key in Settings (or define ILD_ANTHROPIC_API_KEY in wp-config.php) to enable the draft-entry button.', 'ingredient-list-decoder' ); ?>
				</p></div>
			<?php elseif ( ILD_AI_Drafter::auto_on() ) : ?>
				<div class="notice notice-info"><p>
					<?php esc_html_e( 'Automatic drafting is on. The most-requested unknown ingredients are drafted on a schedule and drop off this list once added. You can still draft any one now with the button.', 'ingredient-list-decoder' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $tokens ) ) : ?>
				<p><?php esc_html_e( 'The queue is empty. Nothing unmatched is waiting.', 'ingredient-list-decoder' ); ?></p>
			<?php else : ?>
				<form method="post" id="ild-unknown-form">
					<?php wp_nonce_field( 'ild_unknown_bulk' ); ?>
					<input type="hidden" name="ild_unknown_bulk" value="1" />

					<div class="tablenav top">
						<div class="alignleft actions bulkactions">
							<label for="ild-bulk-action" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'ingredient-list-decoder' ); ?></label>
							<select name="ild_bulk_action" id="ild-bulk-action">
								<option value=""><?php esc_html_e( 'Bulk actions', 'ingredient-list-decoder' ); ?></option>
								<option value="export"><?php esc_html_e( 'Export selected to CSV', 'ingredient-list-decoder' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete selected', 'ingredient-list-decoder' ); ?></option>
							</select>
							<?php submit_button( __( 'Apply', 'ingredient-list-decoder' ), 'action', 'ild_apply', false ); ?>
						</div>
						<div class="alignleft actions">
							<?php submit_button( __( 'Export all to CSV', 'ingredient-list-decoder' ), 'secondary', 'ild_export_all', false ); ?>
						</div>
					</div>

					<table class="widefat striped">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column"><input type="checkbox" id="ild-cb-select-all" aria-label="<?php esc_attr_e( 'Select all', 'ingredient-list-decoder' ); ?>" /></td>
								<th><?php esc_html_e( 'Token', 'ingredient-list-decoder' ); ?></th>
								<th><?php esc_html_e( 'Appearances', 'ingredient-list-decoder' ); ?></th>
								<th><?php esc_html_e( 'First seen', 'ingredient-list-decoder' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'ingredient-list-decoder' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $tokens as $row ) : ?>
								<?php
								$id         = (int) $row['id'];
								$draft_url  = wp_nonce_url( self::page_url( array( 'action' => 'draft', 'token' => $id ) ), 'ild_token_' . $id );
								$dismiss_url = wp_nonce_url( self::page_url( array( 'action' => 'dismiss', 'token' => $id ) ), 'ild_token_' . $id );
								?>
								<tr>
									<th scope="row" class="check-column">
										<input type="checkbox" name="ild_ids[]" value="<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: the ingredient token. */ __( 'Select %s', 'ingredient-list-decoder' ), $row['token'] ) ); ?>" />
									</th>
									<td><strong><?php echo esc_html( $row['token'] ); ?></strong></td>
									<td><?php echo esc_html( (int) $row['appearances'] ); ?></td>
									<td><?php echo esc_html( mysql2date( 'Y-m-d', get_date_from_gmt( $row['first_seen'] ) ) ); ?></td>
									<td>
										<?php if ( ILD_AI_Drafter::is_available() ) : ?>
											<a href="<?php echo esc_url( $draft_url ); ?>" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Draft a needs-review entry for this token with the AI?', 'ingredient-list-decoder' ) ); ?>');"><?php esc_html_e( 'Draft entry', 'ingredient-list-decoder' ); ?></a>
										<?php endif; ?>
										<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'ingredient-list-decoder' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</form>

				<script>
				( function () {
					var form = document.getElementById( 'ild-unknown-form' );
					if ( ! form ) { return; }

					// Select-all toggles every row checkbox.
					var all = document.getElementById( 'ild-cb-select-all' );
					if ( all ) {
						all.addEventListener( 'change', function () {
							var boxes = form.querySelectorAll( 'input[name="ild_ids[]"]' );
							for ( var i = 0; i < boxes.length; i++ ) { boxes[ i ].checked = all.checked; }
						} );
					}

					// Confirm before a bulk delete.
					var apply = document.getElementById( 'ild_apply' );
					if ( apply ) {
						apply.addEventListener( 'click', function ( e ) {
							var sel = document.getElementById( 'ild-bulk-action' );
							if ( sel && 'delete' === sel.value ) {
								if ( ! window.confirm( '<?php echo esc_js( __( 'Delete the selected tokens permanently? This cannot be undone.', 'ingredient-list-decoder' ) ); ?>' ) ) {
									e.preventDefault();
								}
							}
						} );
					}
				} )();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Show a notice after an action.
	 *
	 * @return void
	 */
	private function render_notice() {
		if ( empty( $_GET['ild_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['ild_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'dismissed' === $notice ) {
			$this->notice( 'success', __( 'Token dismissed.', 'ingredient-list-decoder' ) );
		} elseif ( 'drafted' === $notice ) {
			$new = isset( $_GET['ild_new'] ) ? absint( wp_unslash( $_GET['ild_new'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$link = $new ? ' <a href="' . esc_url( get_edit_post_link( $new ) ) . '">' . esc_html__( 'Review the draft', 'ingredient-list-decoder' ) . '</a>' : '';
			printf( '<div class="notice notice-success is-dismissible"><p>%s%s</p></div>', esc_html__( 'Draft created in needs-review status.', 'ingredient-list-decoder' ), wp_kses_post( $link ) );
		} elseif ( 'draft_failed' === $notice ) {
			$msg = isset( $_GET['ild_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ild_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->notice( 'error', __( 'The draft could not be created.', 'ingredient-list-decoder' ) . ( '' !== $msg ? ' ' . $msg : '' ) );
		} elseif ( 'gone' === $notice ) {
			$this->notice( 'warning', __( 'That token is no longer in the queue.', 'ingredient-list-decoder' ) );
		} elseif ( 'deleted' === $notice ) {
			$count = isset( $_GET['ild_count'] ) ? absint( wp_unslash( $_GET['ild_count'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->notice(
				'success',
				sprintf(
					/* translators: %d: how many tokens were deleted. */
					_n( '%d token deleted.', '%d tokens deleted.', $count, 'ingredient-list-decoder' ),
					$count
				)
			);
		} elseif ( 'none' === $notice ) {
			$this->notice( 'warning', __( 'No tokens were selected.', 'ingredient-list-decoder' ) );
		}
	}

	/**
	 * Print a dismissible notice.
	 *
	 * @param string $type    success|error|warning.
	 * @param string $message The message.
	 * @return void
	 */
	private function notice( $type, $message ) {
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * Order the review queue by submission demand, then alphabetically.
	 *
	 * Entries drafted from the unknown queue carry a submission-frequency meta;
	 * the most-requested rise to the top. Entries without it (added by hand) fall
	 * below, ordered by name.
	 *
	 * @param array $args The review-queue WP_Query args.
	 * @return array
	 */
	public function order_review_queue( $args ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			'freq'     => array( 'key' => '_ild_submission_frequency', 'compare' => 'EXISTS', 'type' => 'NUMERIC' ),
			'nofreq'   => array( 'key' => '_ild_submission_frequency', 'compare' => 'NOT EXISTS' ),
		);
		$args['orderby'] = array( 'freq' => 'DESC', 'title' => 'ASC' );
		unset( $args['order'] );

		return $args;
	}
}
