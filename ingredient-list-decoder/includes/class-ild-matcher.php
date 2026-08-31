<?php
/**
 * The matcher: mapping cleaned tokens onto ingredients in the library.
 *
 * Once the parser has produced an ordered list of tokens, this class decides
 * what each one is. It matches on the INCI name first, then on the "also known
 * as" aliases, and for anything still unmatched it runs a fuzzy match and offers
 * a single best suggestion only when it is close enough to be believable.
 *
 * The result keeps the original order intact — the ordered "items" list is the
 * spine the analysis engine will later walk — and also groups the outcomes into
 * matched, suggested and unmatched for convenience.
 *
 * It is exposed as plain PHP (ild_match_ingredient_list()) and as a small admin
 * test screen; there is no front end yet.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches parsed tokens against the ingredient library.
 */
class ILD_Matcher {

	/**
	 * The slug of the admin test screen.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ild-test-parser';

	/**
	 * The shortest token length we will attempt a fuzzy match on.
	 *
	 * Below this, a one- or two-letter edit could turn almost anything into
	 * almost anything else, so a fuzzy suggestion would do more harm than good.
	 *
	 * @var int
	 */
	const MIN_FUZZY_LENGTH = 4;

	/**
	 * Hook the admin test screen onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	/**
	 * Add the "Test Parser" screen under the Ingredient Decoder menu.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . ILD_Post_Types::POST_TYPE,
			__( 'Test Parser', 'ingredient-list-decoder' ),
			__( 'Test Parser', 'ingredient-list-decoder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * The matching logic (usable as plain PHP)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Parse and match a raw pasted ingredient list.
	 *
	 * @param string $raw The pasted ingredient list.
	 * @return array|WP_Error The structured result, or a parser error. The result
	 *                        is an array of:
	 *                        - 'items'       ordered outcomes, one per token,
	 *                        - 'matched'     the matched items,
	 *                        - 'suggestions' the suggested items,
	 *                        - 'unmatched'   the unmatched original tokens,
	 *                        - 'counts'      totals for each of the above.
	 */
	public static function match( $raw ) {
		// Parsing may reject the input (too long); pass that straight back.
		$tokens = ILD_Parser::parse( $raw );
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		// Build the lookup once for the whole list.
		$index = self::build_index();

		$items       = array();
		$matched     = array();
		$suggestions = array();
		$unmatched   = array();

		foreach ( $tokens as $token ) {
			$norm = $token['normalised'];

			// The parts every outcome shares.
			$item = array(
				'position' => $token['position'],
				'original' => $token['original'],
			);

			if ( isset( $index['inci'][ $norm ] ) ) {
				// A direct hit on an INCI name.
				$id                 = $index['inci'][ $norm ];
				$item['status']     = 'matched';
				$item['matched_by'] = 'inci';
				$item['post_id']    = $id;
				$item['inci_name']  = $index['names'][ $id ];
				$matched[]          = $item;

			} elseif ( isset( $index['alias'][ $norm ] ) ) {
				// A hit on one of an ingredient's "also known as" aliases.
				$id                 = $index['alias'][ $norm ];
				$item['status']     = 'matched';
				$item['matched_by'] = 'alias';
				$item['post_id']    = $id;
				$item['inci_name']  = $index['names'][ $id ];
				$matched[]          = $item;

			} else {
				// No exact match: try a fuzzy one and offer a single suggestion.
				$best = self::fuzzy_best( $norm, $index['candidates'] );
				if ( $best ) {
					$item['status']     = 'suggestion';
					$item['suggestion'] = $best;
					$suggestions[]      = $item;
				} else {
					$item['status'] = 'unmatched';
					$unmatched[]    = $token['original'];
				}
			}

			// Whatever the outcome, it keeps its place in the ordered list.
			$items[] = $item;
		}

		return array(
			'items'       => $items,
			'matched'     => $matched,
			'suggestions' => $suggestions,
			'unmatched'   => $unmatched,
			'counts'      => array(
				'total'       => count( $items ),
				'matched'     => count( $matched ),
				'suggestions' => count( $suggestions ),
				'unmatched'   => count( $unmatched ),
			),
		);
	}

	/**
	 * Build the lookup index of the whole library.
	 *
	 * Produces three things from one pass over the ingredients:
	 *  - 'inci'       normalised INCI name  => post ID,
	 *  - 'alias'      normalised alias       => post ID,
	 *  - 'candidates' a stable, ordered list of { id, name, norm } for fuzzy
	 *                 matching, plus 'names' (post ID => INCI name) for display.
	 *
	 * Which statuses count as "in the library" can be filtered; by default every
	 * entry that is not trashed is included, since the library is still being
	 * built and drafts and needs-review entries are legitimate matches.
	 *
	 * @return array The index described above.
	 */
	public static function build_index() {
		// Which statuses to treat as part of the library.
		$statuses = apply_filters(
			'ild_matcher_statuses',
			array( 'publish', ILD_Post_Types::STATUS_NEEDS_REVIEW, 'draft', 'pending' )
		);

		$query = new WP_Query(
			array(
				'post_type'              => ILD_Post_Types::POST_TYPE,
				'post_status'            => $statuses,
				'posts_per_page'         => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$inci       = array();
		$alias      = array();
		$names      = array();
		$candidates = array();

		foreach ( $query->posts as $post ) {
			$id    = (int) $post->ID;
			$title = $post->post_title;

			$names[ $id ] = $title;

			// The INCI name itself. First entry wins if two ever normalise alike
			// (duplicates are blocked elsewhere, so this is only a safety net).
			$inci_norm = ILD_Parser::normalise( $title );
			if ( '' !== $inci_norm && ! isset( $inci[ $inci_norm ] ) ) {
				$inci[ $inci_norm ] = $id;
				$candidates[]       = array(
					'id'   => $id,
					'name' => $title,
					'norm' => $inci_norm,
				);
			}

			// The aliases, held one per line in the "also known as" meta.
			$aka = get_post_meta( $id, '_ild_also_known_as', true );
			if ( is_string( $aka ) && '' !== $aka ) {
				$lines = preg_split( '/[\r\n]+/', $aka );
				foreach ( (array) $lines as $line ) {
					$alias_norm = ILD_Parser::normalise( $line );
					if ( '' !== $alias_norm && ! isset( $alias[ $alias_norm ] ) ) {
						$alias[ $alias_norm ] = $id;
					}
				}
			}
		}

		return array(
			'inci'       => $inci,
			'alias'      => $alias,
			'names'      => $names,
			'candidates' => $candidates,
		);
	}

	/**
	 * Find the single best fuzzy match for a token, if it is close enough.
	 *
	 * Uses the edit (Levenshtein) distance against every INCI name, with a
	 * threshold that scales to the token's length: short tokens must match almost
	 * exactly, longer ones may differ by a few characters. Returns nothing for
	 * very short tokens, where fuzzy matching is unsafe.
	 *
	 * @param string $norm       The normalised token to match.
	 * @param array  $candidates The ordered { id, name, norm } candidate list.
	 * @return array|null A suggestion { post_id, inci_name, distance }, or null.
	 */
	private static function fuzzy_best( $norm, $candidates ) {
		$len = strlen( $norm );

		// Too short to guess at safely.
		if ( $len < self::MIN_FUZZY_LENGTH ) {
			return null;
		}

		// Allow roughly one edit per four characters, at least one and at most
		// four, so a believable typo is caught but a different word is not.
		$threshold = min( 4, max( 1, (int) floor( $len / 4 ) ) );

		$best   = null;
		$best_d = PHP_INT_MAX;

		foreach ( $candidates as $candidate ) {
			// Levenshtein is byte-based and capped at 255 characters; ingredient
			// tokens are far shorter, so this is both safe and fast.
			$distance = levenshtein( $norm, $candidate['norm'] );
			if ( $distance < $best_d ) {
				$best_d = $distance;
				$best   = $candidate;
			}
		}

		if ( null !== $best && $best_d <= $threshold ) {
			return array(
				'post_id'   => $best['id'],
				'inci_name' => $best['name'],
				'distance'  => $best_d,
			);
		}

		return null;
	}

	/*
	 * -----------------------------------------------------------------------
	 * The admin test screen
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Render the "Test Parser" screen: a box to paste a list, and the result.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ingredient-list-decoder' ) );
		}

		$input  = '';
		$result = null;
		$error  = '';

		// Run the parser/matcher when the form is submitted.
		if ( isset( $_POST['ild_test_submit'] ) ) {
			check_admin_referer( 'ild_test_parser', 'ild_test_nonce' );

			$input  = isset( $_POST['ild_test_input'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ild_test_input'] ) ) : '';
			$result = self::match( $input );

			if ( is_wp_error( $result ) ) {
				$error  = $result->get_error_message();
				$result = null;
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Test the ingredient parser', 'ingredient-list-decoder' ) . '</h1>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Paste a product\'s ingredient list to see how it is split, cleaned and matched against the library. This is a diagnostic tool; nothing is saved.', 'ingredient-list-decoder' )
		);

		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}

		echo '<form method="post">';
		wp_nonce_field( 'ild_test_parser', 'ild_test_nonce' );
		echo '<input type="hidden" name="ild_test_submit" value="1" />';
		printf(
			'<p><textarea name="ild_test_input" rows="5" style="width:100%%;" placeholder="%s">%s</textarea></p>',
			esc_attr__( 'Aqua (Water), Glycerin, Cetearyl Alcohol (and) Ceteareth-20, Sodium Hyaluronate*, Phenoxyethanol. May contain: CI 77891.', 'ingredient-list-decoder' ),
			esc_textarea( $input )
		);
		submit_button( __( 'Parse and match', 'ingredient-list-decoder' ) );
		echo '</form>';

		if ( is_array( $result ) ) {
			$this->render_result( $result );
		}

		echo '</div>';
	}

	/**
	 * Draw the result table, in the original list order.
	 *
	 * @param array $result The structured result from match().
	 * @return void
	 */
	private function render_result( $result ) {
		$counts = $result['counts'];

		echo '<h2>' . esc_html__( 'Result', 'ingredient-list-decoder' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: total tokens, 2: matched, 3: suggested, 4: unmatched. */
					__( '%1$d ingredients read: %2$d matched, %3$d suggested, %4$d unmatched.', 'ingredient-list-decoder' ),
					$counts['total'],
					$counts['matched'],
					$counts['suggestions'],
					$counts['unmatched']
				)
			)
		);

		if ( empty( $result['items'] ) ) {
			printf( '<p>%s</p>', esc_html__( 'No ingredients were found in that text.', 'ingredient-list-decoder' ) );
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:3em;">' . esc_html__( '#', 'ingredient-list-decoder' ) . '</th>';
		echo '<th>' . esc_html__( 'Token', 'ingredient-list-decoder' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'ingredient-list-decoder' ) . '</th>';
		echo '<th>' . esc_html__( 'Ingredient', 'ingredient-list-decoder' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $result['items'] as $item ) {
			echo '<tr>';
			printf( '<td>%d</td>', (int) $item['position'] + 1 );
			printf( '<td>%s</td>', esc_html( $item['original'] ) );

			if ( 'matched' === $item['status'] ) {
				$label = ( 'alias' === $item['matched_by'] )
					? __( 'Matched (alias)', 'ingredient-list-decoder' )
					: __( 'Matched', 'ingredient-list-decoder' );
				printf( '<td><span style="color:#1a7f37;font-weight:600;">%s</span></td>', esc_html( $label ) );
				$this->render_ingredient_cell( $item['post_id'], $item['inci_name'] );

			} elseif ( 'suggestion' === $item['status'] ) {
				printf(
					'<td><span style="color:#8a6d00;font-weight:600;">%s</span></td>',
					esc_html(
						sprintf(
							/* translators: %d: the edit distance of the suggestion. */
							__( 'Did you mean… (distance %d)', 'ingredient-list-decoder' ),
							(int) $item['suggestion']['distance']
						)
					)
				);
				$this->render_ingredient_cell( $item['suggestion']['post_id'], $item['suggestion']['inci_name'] );

			} else {
				printf( '<td><span style="color:#b32d2e;font-weight:600;">%s</span></td>', esc_html__( 'Unmatched', 'ingredient-list-decoder' ) );
				echo '<td><span aria-hidden="true">&mdash;</span></td>';
			}

			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Draw the "Ingredient" cell, linking to the entry's editor where possible.
	 *
	 * @param int    $post_id The ingredient's ID.
	 * @param string $name    The INCI name to show.
	 * @return void
	 */
	private function render_ingredient_cell( $post_id, $name ) {
		$link = get_edit_post_link( $post_id );
		if ( $link ) {
			printf( '<td><a href="%s">%s</a></td>', esc_url( $link ), esc_html( $name ) );
		} else {
			printf( '<td>%s</td>', esc_html( $name ) );
		}
	}
}
