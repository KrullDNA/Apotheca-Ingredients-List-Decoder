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
	 * Handle a dismiss or a draft, then redirect.
	 *
	 * @return void
	 */
	public function process_actions() {
		if ( ! current_user_can( 'manage_options' ) || empty( $_GET['action'] ) || empty( $_GET['token'] ) ) {
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
	 * Redirect back to the screen with a notice.
	 *
	 * @param string $notice        The notice key.
	 * @param int    $ingredient_id The drafted ingredient, if any.
	 * @param string $message       An extra message (a draft error).
	 * @return void
	 */
	private function redirect( $notice, $ingredient_id = 0, $message = '' ) {
		$args = array( 'ild_notice' => $notice );
		if ( $ingredient_id ) {
			$args['ild_new'] = $ingredient_id;
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

			<?php $this->render_notice(); ?>

			<?php if ( ! ILD_AI_Drafter::is_available() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Drafting is off. Define ILD_ANTHROPIC_API_KEY in wp-config.php to enable the draft-entry button.', 'ingredient-list-decoder' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( empty( $tokens ) ) : ?>
				<p><?php esc_html_e( 'The queue is empty. Nothing unmatched is waiting.', 'ingredient-list-decoder' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
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
