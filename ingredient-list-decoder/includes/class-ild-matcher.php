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
		$parsed = ILD_Parser::parse( $raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		// Build the lookup once for the whole list.
		$index = self::build_index();

		$items       = array();
		$matched     = array();
		$suggestions = array();
		$unmatched   = array();

		foreach ( $parsed['items'] as $token ) {
			// Classify the token, then keep its place in the ordered list.
			$item             = self::classify( $token['original'], $token['normalised'], $index );
			$item['position'] = $token['position'];

			if ( 'matched' === $item['status'] ) {
				$matched[] = $item;
			} elseif ( 'suggestion' === $item['status'] ) {
				$suggestions[] = $item;
			} else {
				$unmatched[] = $token['original'];
			}

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
			// The shade declaration, matched but held apart from the ordered list.
			'shade'       => self::match_shade( $parsed['shade'], $index ),
		);
	}

	/**
	 * Classify one token against the library.
	 *
	 * Returns an item carrying its outcome: a match (INCI or alias, with the post
	 * ID and name), a single fuzzy suggestion, or unmatched. Shared by the ordered
	 * list and the shade block so both are read the same way.
	 *
	 * @param string $original The token as shown to a human.
	 * @param string $norm     The normalised token to look up.
	 * @param array  $index    The library index from build_index().
	 * @return array The item { original, status, ... }.
	 */
	private static function classify( $original, $norm, $index ) {
		$item = array( 'original' => $original );

		$hit = self::lookup( $norm, $index );
		if ( $hit ) {
			$item['status']     = 'matched';
			$item['matched_by'] = $hit['by'];
			$item['post_id']    = $hit['id'];
			$item['inci_name']  = $index['names'][ $hit['id'] ];
			return $item;
		}

		// No exact hit: offer a single fuzzy suggestion if one is close enough.
		$best = self::fuzzy_best( $norm, $index['candidates'] );
		if ( $best ) {
			$item['status']     = 'suggestion';
			$item['suggestion'] = $best;
			return $item;
		}

		$item['status'] = 'unmatched';
		return $item;
	}

	/**
	 * Look a normalised token up against the INCI names and aliases.
	 *
	 * Tries the token as-is first, so a name whose stored INCI includes a
	 * parenthetical (Butyrospermum Parkii (Shea) Butter) matches directly. If that
	 * misses, it tries again with parentheticals removed, so a token that carries
	 * only a common name in brackets (Aqua (Water)) still finds its entry. Failing
	 * that, and when the token is a slash-joined multi-name form (Aqua/Water/Eau,
	 * Parfum/Fragrance), each name in turn is tried, so the token resolves to
	 * whichever one the library holds.
	 *
	 * @param string $norm  The normalised token.
	 * @param array  $index The library index.
	 * @return array{id:int,by:string}|null The hit, or null.
	 */
	private static function lookup( $norm, $index ) {
		// The whole token, then the same token with any brackets removed.
		$hit = self::lookup_direct( $norm, $index );
		if ( $hit ) {
			return $hit;
		}

		// A slash-joined synonym form: try each name on its own. Aqua/Water/Eau is
		// one ingredient printed with all three names, so any one matching is the
		// same entry — it stays a single token, resolved to the stored name.
		if ( false !== strpos( $norm, '/' ) ) {
			foreach ( self::slash_parts( $norm ) as $part ) {
				$hit = self::lookup_direct( $part, $index );
				if ( $hit ) {
					return $hit;
				}
			}
		}

		return null;
	}

	/**
	 * Look one normalised name up directly: as-is, then bracket-stripped.
	 *
	 * @param string $norm  The normalised name.
	 * @param array  $index The library index.
	 * @return array{id:int,by:string}|null The hit, or null.
	 */
	private static function lookup_direct( $norm, $index ) {
		if ( '' === $norm ) {
			return null;
		}
		if ( isset( $index['inci'][ $norm ] ) ) {
			return array( 'id' => $index['inci'][ $norm ], 'by' => 'inci' );
		}
		if ( isset( $index['alias'][ $norm ] ) ) {
			return array( 'id' => $index['alias'][ $norm ], 'by' => 'alias' );
		}

		$bare = self::strip_parentheticals( $norm );
		if ( $bare !== $norm && '' !== $bare ) {
			if ( isset( $index['inci'][ $bare ] ) ) {
				return array( 'id' => $index['inci'][ $bare ], 'by' => 'inci' );
			}
			if ( isset( $index['alias'][ $bare ] ) ) {
				return array( 'id' => $index['alias'][ $bare ], 'by' => 'alias' );
			}
		}

		// Finally, the content of any bracketed group. On a "Water (Aqua)" label the
		// INCI name is in the brackets, not outside them, so "Aqua" must be tried
		// too — otherwise the common name alone ("Water") never resolves.
		foreach ( self::bracket_contents( $norm ) as $inside ) {
			if ( isset( $index['inci'][ $inside ] ) ) {
				return array( 'id' => $index['inci'][ $inside ], 'by' => 'inci' );
			}
			if ( isset( $index['alias'][ $inside ] ) ) {
				return array( 'id' => $index['alias'][ $inside ], 'by' => 'alias' );
			}
		}

		return null;
	}

	/**
	 * The normalised content of each bracketed group in a token.
	 *
	 * "water (aqua)" yields [ 'aqua' ]; "aqua (water)" yields [ 'water' ]. Each is
	 * normalised again so it lines up with the index the same way a bare token
	 * would. Nested brackets are not expected on an ingredient label.
	 *
	 * @param string $norm The normalised token.
	 * @return string[] The non-empty bracket contents, in order.
	 */
	private static function bracket_contents( $norm ) {
		$out = array();
		if ( preg_match_all( '/\(([^()]+)\)|\[([^\[\]]+)\]|\{([^{}]+)\}/u', $norm, $matches ) ) {
			$groups = array_merge( $matches[1], $matches[2], $matches[3] );
			foreach ( $groups as $inside ) {
				$inside = ILD_Parser::normalise( $inside );
				if ( '' !== $inside ) {
					$out[] = $inside;
				}
			}
		}
		return $out;
	}

	/**
	 * Split a slash-joined token into its individual normalised names.
	 *
	 * "aqua/water/eau" becomes [ 'aqua', 'water', 'eau' ]. Each part is normalised
	 * again so surrounding spaces and punctuation around the slashes are trimmed.
	 *
	 * @param string $norm The normalised token containing one or more slashes.
	 * @return string[] The non-empty parts, in order.
	 */
	private static function slash_parts( $norm ) {
		$parts = array();
		foreach ( preg_split( '#\s*/\s*#u', $norm ) as $part ) {
			$part = ILD_Parser::normalise( $part );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		return $parts;
	}

	/**
	 * Remove bracketed groups from a normalised token, collapsing the gap.
	 *
	 * @param string $norm The normalised token.
	 * @return string The token with any ( ), [ ] or { } groups removed.
	 */
	private static function strip_parentheticals( $norm ) {
		$bare = preg_replace( '/\([^()]*\)|\[[^\[\]]*\]|\{[^{}]*\}/u', ' ', $norm );
		$bare = preg_replace( '/\s+/u', ' ', $bare );
		return trim( $bare );
	}

	/**
	 * Match the shade block's kept exceptions, carrying its flags through.
	 *
	 * The shade block is never ranked, so its items carry no position. Titanium
	 * Dioxide and Zinc Oxide are looked up like any other token; the collapsed
	 * colourant flag is passed straight through from the parser.
	 *
	 * @param array $shade The parser's shade block { present, colourants, items }.
	 * @param array $index The library index.
	 * @return array The matched shade block { present, colourants, items }.
	 */
	private static function match_shade( $shade, $index ) {
		$items = array();
		foreach ( (array) $shade['items'] as $token ) {
			$items[] = self::classify( $token['original'], $token['normalised'], $index );
		}

		return array(
			'present'    => ! empty( $shade['present'] ),
			'colourants' => ! empty( $shade['colourants'] ),
			'items'      => $items,
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
	 * Find the nearest existing entry to a name, for a near-match warning.
	 *
	 * Reuses the same fuzzy matcher the front-end analysis uses, so "close enough"
	 * means the same thing everywhere. It never blocks a save — Ceramide NP and
	 * Ceramide AP resemble each other but are different ingredients — it only names
	 * the entry a curator may want to glance at.
	 *
	 * @param string $name       The raw name being typed or saved.
	 * @param int    $exclude_id An entry to leave out (the one being edited).
	 * @return array|null A suggestion { post_id, inci_name, distance }, or null.
	 */
	public static function nearest( $name, $exclude_id = 0 ) {
		$norm = ILD_Parser::normalise( $name );
		if ( '' === $norm ) {
			return null;
		}

		$exclude_id = (int) $exclude_id;
		$index      = self::build_index();

		// Drop the entry being edited so it never resembles itself.
		$candidates = array();
		foreach ( $index['candidates'] as $candidate ) {
			if ( (int) $candidate['id'] !== $exclude_id ) {
				$candidates[] = $candidate;
			}
		}

		$best = self::fuzzy_best( $norm, $candidates );

		// An exact normalised hit is a collision, handled elsewhere; only report a
		// genuine near-miss here.
		if ( $best && isset( $best['distance'] ) && 0 === (int) $best['distance'] ) {
			return null;
		}

		return $best;
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
			// Show the raw analysis findings too, as a developer diagnostic. This
			// is the Stage 5 engine's structured output, with no phrasing applied.
			$this->render_findings( ILD_Analysis::analyse( $result ) );
		}

		echo '</div>';
	}

	/**
	 * Dump the analysis engine's findings, for diagnosis only.
	 *
	 * Deliberately raw: this is the structured findings array from Stage 5, shown
	 * so the rules can be checked. It applies no phrasing — that is a later stage.
	 *
	 * @param array $analysis The result of ILD_Analysis::analyse().
	 * @return void
	 */
	private function render_findings( $analysis ) {
		echo '<h2>' . esc_html__( 'Analysis findings (developer view)', 'ingredient-list-decoder' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The raw output of the analysis engine: data and confidence flags only, with no wording applied. A later stage turns these into readable findings.', 'ingredient-list-decoder' )
		);
		printf(
			'<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;overflow:auto;max-height:32em;">%s</pre>',
			esc_html( print_r( $analysis, true ) ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Intentional diagnostic output on an admin-only screen.
		);
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

		$this->render_shade( $result );
	}

	/**
	 * Draw the shade declaration, held apart from the ordered list.
	 *
	 * @param array $result The structured result from match().
	 * @return void
	 */
	private function render_shade( $result ) {
		$shade = isset( $result['shade'] ) ? $result['shade'] : array();
		if ( empty( $shade['present'] ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Shade declaration', 'ingredient-list-decoder' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'A “may contain” / “+/-” shade range, held apart from the concentration-ordered list and never ranked.', 'ingredient-list-decoder' )
		);

		if ( ! empty( $shade['colourants'] ) ) {
			printf( '<p>%s</p>', esc_html__( 'This product contains colourants (individual CI numbers are not listed).', 'ingredient-list-decoder' ) );
		}

		if ( ! empty( $shade['items'] ) ) {
			printf( '<p>%s</p>', esc_html__( 'Kept from the shade block (they also work as UV filters / opacifiers):', 'ingredient-list-decoder' ) );
			echo '<ul style="list-style:disc;margin-left:1.5em;">';
			foreach ( $shade['items'] as $item ) {
				$name = ( 'matched' === $item['status'] && ! empty( $item['inci_name'] ) ) ? $item['inci_name'] : $item['original'];
				printf( '<li>%s</li>', esc_html( $name ) );
			}
			echo '</ul>';
		}
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
