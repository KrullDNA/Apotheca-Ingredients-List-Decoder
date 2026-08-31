<?php
/**
 * The admin dashboard panel.
 *
 * A single at-a-glance widget: submissions this week, leads this week, the top
 * unmatched ingredients, the library size by status, and how much of today's
 * paid-request cap has been used.
 *
 * It shows only aggregate counts — nothing that identifies a person.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the dashboard widget.
 */
class ILD_Dashboard {

	/**
	 * Hook the widget onto the dashboard.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * Add the widget, for administrators only.
	 *
	 * @return void
	 */
	public function add_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'ild_dashboard', __( 'Ingredient Decoder', 'ingredient-list-decoder' ), array( $this, 'render' ) );
	}

	/**
	 * Render the panel.
	 *
	 * @return void
	 */
	public function render() {
		$since = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

		$submissions = ILD_Submissions::count_since( $since );
		$leads       = $this->leads_this_week();
		$tokens      = ILD_Unknown_Tokens::get_open( 5 );
		$library     = $this->library_by_status();
		$used        = ILD_Rate_Limit::daily_count();
		$cap         = ILD_Rate_Limit::daily_cap();
		?>
		<ul class="ild-dashboard">
			<li>
				<strong><?php echo esc_html( number_format_i18n( $submissions ) ); ?></strong>
				<?php esc_html_e( 'submissions this week', 'ingredient-list-decoder' ); ?>
			</li>
			<li>
				<strong><?php echo esc_html( number_format_i18n( $leads ) ); ?></strong>
				<?php esc_html_e( 'leads this week', 'ingredient-list-decoder' ); ?>
			</li>
		</ul>

		<p><strong><?php esc_html_e( 'Library', 'ingredient-list-decoder' ); ?>:</strong>
			<?php
			printf(
				/* translators: 1: published count, 2: needs-review count, 3: draft count. */
				esc_html__( '%1$s published, %2$s needs review, %3$s draft', 'ingredient-list-decoder' ),
				esc_html( number_format_i18n( $library['publish'] ) ),
				esc_html( number_format_i18n( $library['needs_review'] ) ),
				esc_html( number_format_i18n( $library['draft'] ) )
			);
			?>
		</p>

		<p><strong><?php esc_html_e( 'Paid API today', 'ingredient-list-decoder' ); ?>:</strong>
			<?php
			printf(
				/* translators: 1: requests used today, 2: the daily cap. */
				esc_html__( '%1$s of %2$s used', 'ingredient-list-decoder' ),
				esc_html( number_format_i18n( $used ) ),
				esc_html( number_format_i18n( $cap ) )
			);
			if ( $used >= $cap ) {
				echo ' <span style="color:#b3261e;">' . esc_html__( '(cap reached)', 'ingredient-list-decoder' ) . '</span>';
			}
			?>
		</p>

		<p><strong><?php esc_html_e( 'Top unmatched ingredients', 'ingredient-list-decoder' ); ?></strong></p>
		<?php if ( empty( $tokens ) ) : ?>
			<p><?php esc_html_e( 'None waiting.', 'ingredient-list-decoder' ); ?></p>
		<?php else : ?>
			<ol style="margin-left:1.2em;">
				<?php foreach ( $tokens as $row ) : ?>
					<li>
						<?php echo esc_html( $row['token'] ); ?>
						<span style="color:#666;">&times;<?php echo esc_html( number_format_i18n( (int) $row['appearances'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
			<p><a href="<?php echo esc_url( ILD_Unknown_Admin::page_url() ); ?>"><?php esc_html_e( 'Work the queue', 'ingredient-list-decoder' ); ?> &rarr;</a></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Count leads captured in the last week.
	 *
	 * @return int
	 */
	private function leads_this_week() {
		$query = new WP_Query(
			array(
				'post_type'      => ILD_Leads::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'date_query'     => array(
					array( 'after' => '1 week ago', 'inclusive' => true ),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * The ingredient counts by status.
	 *
	 * @return array { publish, needs_review, draft }.
	 */
	private function library_by_status() {
		$counts = wp_count_posts( ILD_Post_Types::POST_TYPE );
		return array(
			'publish'      => isset( $counts->publish ) ? (int) $counts->publish : 0,
			'needs_review' => isset( $counts->{ILD_Post_Types::STATUS_NEEDS_REVIEW} ) ? (int) $counts->{ILD_Post_Types::STATUS_NEEDS_REVIEW} : 0,
			'draft'        => isset( $counts->draft ) ? (int) $counts->draft : 0,
		);
	}
}
