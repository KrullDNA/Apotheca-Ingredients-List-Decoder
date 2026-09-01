<?php
/**
 * The analysis engine: reading a matched, ordered list as a formula.
 *
 * This is the heart of the tool, and it is deliberately not AI. It is plain
 * logic applied to the ordered list of matched ingredients and the metadata
 * behind them (role, sub-one marker, use range), so it is instant, free to run
 * and returns exactly the same answer every time — which matters, because the
 * result gets screenshotted.
 *
 * It produces findings only. It carries no HTML and no phrasing: every finding
 * is a bundle of data with a confidence flag, and a later stage decides how to
 * word it. Crucially, order below one per cent is unregulated, so the engine
 * never claims a precise figure; it states which side of an inferred line an
 * ingredient sits on, and hands the front end enough to phrase it as an
 * inference rather than a fact. The one per cent line is placed only when the
 * markers justify it — a single strong marker, or a moderate one corroborated by
 * another further down — and is otherwise reported as undetermined, not guessed.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a matched, ordered ingredient list into structured findings.
 */
class ILD_Analysis {

	/**
	 * Analyse a matched, ordered ingredient list.
	 *
	 * Accepts the result from ild_match_ingredient_list() (or its bare 'items'
	 * array), enriches each matched item with the metadata the rules need, and
	 * then runs the rules. All database work happens here; the rule logic itself
	 * is pure and lives in evaluate().
	 *
	 * @param array $input The Stage 4 match result, or its ordered items array.
	 * @return array The findings and supporting meta (see evaluate()).
	 */
	public static function analyse( $input ) {
		// Accept either the whole match result or just the ordered items.
		if ( isset( $input['items'] ) && is_array( $input['items'] ) ) {
			$items  = $input['items'];
			$counts = isset( $input['counts'] ) && is_array( $input['counts'] ) ? $input['counts'] : array();
		} else {
			$items  = is_array( $input ) ? $input : array();
			$counts = array();
		}

		// Enrich each matched item with the metadata the rules read.
		$sequence = self::enrich( $items );

		// Tally the outcome counts if the caller did not supply them.
		if ( empty( $counts ) ) {
			$counts = self::count_outcomes( $items );
		}

		// The rules themselves take over from here, with no more database work.
		$config = self::get_config();

		return self::evaluate( $sequence, $counts, $config );
	}

	/**
	 * Turn the ordered items into an enriched sequence the rules can read.
	 *
	 * Only confirmed matches carry metadata; suggestions and unmatched tokens
	 * still occupy their position in the list (order and length depend on every
	 * token) but contribute no role or marker data.
	 *
	 * @param array $items The ordered items from the matcher.
	 * @return array The enriched, re-indexed sequence.
	 */
	private static function enrich( $items ) {
		// Gather the matched post IDs so their meta can be primed in one go.
		$ids = array();
		foreach ( $items as $item ) {
			if ( isset( $item['status'] ) && 'matched' === $item['status'] && ! empty( $item['post_id'] ) ) {
				$ids[] = (int) $item['post_id'];
			}
		}
		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', array_unique( $ids ) );
		}

		$sequence = array();
		$position = 0;

		foreach ( $items as $item ) {
			$is_match = ( isset( $item['status'] ) && 'matched' === $item['status'] && ! empty( $item['post_id'] ) );

			$element = array(
				'position'  => $position,
				'matched'   => $is_match,
				'post_id'   => $is_match ? (int) $item['post_id'] : 0,
				'inci_name' => $is_match && isset( $item['inci_name'] ) ? $item['inci_name'] : '',
				'roles'             => array(),
				'sub_one'           => false,
				'marker_confidence' => '',
				'use_low'           => '',
				'use_high'          => '',
			);

			if ( $is_match ) {
				$roles              = get_post_meta( $element['post_id'], '_ild_role', true );
				$element['roles']   = is_array( $roles ) ? $roles : array();
				$element['sub_one'] = ( 'yes' === get_post_meta( $element['post_id'], '_ild_sub_one_marker', true ) );
				$low                = get_post_meta( $element['post_id'], '_ild_use_low', true );
				$high               = get_post_meta( $element['post_id'], '_ild_use_high', true );
				$element['use_low']  = is_string( $low ) ? $low : '';
				$element['use_high'] = is_string( $high ) ? $high : '';

				// The marker's confidence, but only where it is a sub-one marker.
				// An unrated marker is treated as moderate — the cautious tier — so
				// it never places the line on its own.
				if ( $element['sub_one'] ) {
					$confidence                   = get_post_meta( $element['post_id'], '_ild_marker_confidence', true );
					$element['marker_confidence'] = ( 'strong' === $confidence ) ? 'strong' : 'moderate';
				}
			}

			$sequence[] = $element;
			$position++;
		}

		return $sequence;
	}

	/**
	 * Count the matched / suggested / unmatched outcomes of a list.
	 *
	 * @param array $items The ordered items.
	 * @return array The counts.
	 */
	private static function count_outcomes( $items ) {
		$counts = array(
			'total'       => count( $items ),
			'matched'     => 0,
			'suggestions' => 0,
			'unmatched'   => 0,
		);
		foreach ( $items as $item ) {
			$status = isset( $item['status'] ) ? $item['status'] : 'unmatched';
			if ( 'matched' === $status ) {
				$counts['matched']++;
			} elseif ( 'suggestion' === $status ) {
				$counts['suggestions']++;
			} else {
				$counts['unmatched']++;
			}
		}
		return $counts;
	}

	/**
	 * The tunable thresholds the rules use, filterable in one place.
	 *
	 * @return array The configuration.
	 */
	private static function get_config() {
		return apply_filters(
			'ild_analysis_config',
			array(
				// A list at or below this many ingredients is "unusually short".
				'short_list_max'              => 5,
				// A list at or above this many ingredients is "unusually long".
				'long_list_min'               => 40,
				// This many actives in the top third counts as "heavily loaded".
				'loaded_top_third_min_actives' => 3,
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * The rules (pure: no database, no WordPress, no phrasing)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Run the section-6 rules over an enriched sequence.
	 *
	 * Pure and deterministic: given the same sequence it always returns the same
	 * findings. Produces data only — every finding carries a confidence flag and
	 * the underlying numbers, and nothing here decides what to say.
	 *
	 * @param array $sequence The enriched, ordered sequence.
	 * @param array $counts   The outcome counts (total/matched/suggestions/unmatched).
	 * @param array $config   The thresholds from get_config().
	 * @return array { 'findings' => array, 'meta' => array }.
	 */
	public static function evaluate( $sequence, $counts, $config ) {
		$n = count( $sequence );

		// Boundaries used by several rules. "end" values are exclusive.
		$top_third_end = ( $n > 0 ) ? (int) ceil( $n / 3 ) : 0;
		$top_half_end  = ( $n > 0 ) ? (int) ceil( $n / 2 ) : 0;

		$meta = array(
			'total'       => isset( $counts['total'] ) ? (int) $counts['total'] : $n,
			'matched'     => isset( $counts['matched'] ) ? (int) $counts['matched'] : 0,
			'suggestions' => isset( $counts['suggestions'] ) ? (int) $counts['suggestions'] : 0,
			'unmatched'   => isset( $counts['unmatched'] ) ? (int) $counts['unmatched'] : 0,
			'top_third'   => array(
				'start' => 0,
				'end'   => $top_third_end,
			),
		);

		// An empty list has nothing to say.
		if ( 0 === $n ) {
			return array(
				'findings' => array(),
				'meta'     => $meta,
			);
		}

		$findings = array();

		// Rule 1: locate the one per cent line.
		$line       = self::rule_one_percent_line( $sequence );
		$findings[] = $line['finding'];

		// Rule 2: place the actives against that line.
		$findings[] = self::rule_actives( $sequence, $line, $counts );

		// Rule 3: describe the base from the top third.
		$findings[] = self::rule_base( $sequence, $top_third_end );

		// Rule 4: note the shape (zero or more observations).
		foreach ( self::rule_shape( $sequence, $top_third_end, $top_half_end, $config ) as $shape ) {
			$findings[] = $shape;
		}

		return array(
			'findings' => $findings,
			'meta'     => $meta,
		);
	}

	/**
	 * Rule 1: locate the probable one per cent line.
	 *
	 * Placement turns on the confidence of the sub-one markers:
	 *  - a single marker of confidence "strong" is enough to place the line;
	 *  - a "moderate" marker (an unrated one counts as moderate) places the line
	 *    only when a second marker of either level appears further down the list
	 *    to corroborate it;
	 *  - a lone moderate marker with nothing to corroborate it, or no marker at
	 *    all, leaves the line undetermined rather than guessing.
	 *
	 * The line, when placed, sits at the first marker's position; everything from
	 * there on is treated as below one per cent. Only the ordered list reaches
	 * this rule — the shade block from the parser is held apart and never ranked,
	 * so it can never contribute a marker here.
	 *
	 * @param array $sequence The enriched sequence (the ordered list only).
	 * @return array { 'finding' => array, 'line_position' => int|null }.
	 */
	private static function rule_one_percent_line( $sequence ) {
		$n       = count( $sequence );
		$markers = array();

		foreach ( $sequence as $el ) {
			if ( $el['matched'] && $el['sub_one'] ) {
				$markers[] = array(
					'position'   => $el['position'],
					'post_id'    => $el['post_id'],
					'inci_name'  => $el['inci_name'],
					'confidence' => $el['marker_confidence'],
				);
			}
		}

		// Decide whether the markers are strong enough to place the line, and on
		// what basis.
		$line_position = null;
		$basis         = '';
		if ( ! empty( $markers ) ) {
			if ( 'strong' === $markers[0]['confidence'] ) {
				// A single strong marker is sufficient on its own.
				$line_position = $markers[0]['position'];
				$basis         = 'strong';
			} elseif ( count( $markers ) >= 2 ) {
				// The first marker is moderate, but a later marker (of either
				// level) corroborates it — the markers are ordered, so any second
				// one is further down the list.
				$line_position = $markers[0]['position'];
				$basis         = 'corroborated';
			}
			// Otherwise a lone moderate marker: not enough to place. Undetermined.
		}

		// Undetermined: no marker, or a single moderate one with no corroboration.
		if ( null === $line_position ) {
			return array(
				'line_position' => null,
				'finding'       => array(
					'type'       => 'one_percent_line',
					'confidence' => 'low',
					'data'       => array(
						'status'        => 'undetermined',
						'line_position' => null,
						'confirmed'     => false,
						'basis'         => '',
						'markers'       => $markers,
						'above_count'   => null,
						'below_count'   => null,
						'total'         => $n,
					),
				),
			);
		}

		// Everything from the line onward is treated as below one per cent.
		$below_count = 0;
		foreach ( $sequence as $el ) {
			if ( $el['position'] >= $line_position ) {
				$below_count++;
			}
		}
		$above_count = $n - $below_count;

		return array(
			'line_position' => $line_position,
			'finding'       => array(
				'type'       => 'one_percent_line',
				// A placed line rests on either a strong marker or corroboration,
				// so it is reported with high confidence either way.
				'confidence' => 'high',
				'data'       => array(
					'status'        => 'located',
					'line_position' => $line_position,
					'confirmed'     => true,
					'basis'         => $basis,
					'markers'       => $markers,
					'above_count'   => $above_count,
					'below_count'   => $below_count,
					'total'         => $n,
				),
			),
		);
	}

	/**
	 * Rule 2: place each active, and say which side of the line it sits on.
	 *
	 * @param array $sequence The enriched sequence.
	 * @param array $line     The output of rule_one_percent_line().
	 * @param array $counts   The outcome counts (for coverage-based confidence).
	 * @return array The actives finding.
	 */
	private static function rule_actives( $sequence, $line, $counts ) {
		$line_position = $line['line_position'];
		$located       = ( null !== $line_position );

		$actives = array();
		$above   = 0;
		$below   = 0;
		$undet   = 0;

		foreach ( $sequence as $el ) {
			if ( ! $el['matched'] || ! in_array( 'active', $el['roles'], true ) ) {
				continue;
			}

			if ( ! $located ) {
				$side = 'undetermined';
				$undet++;
			} elseif ( $el['position'] >= $line_position ) {
				$side = 'below';
				$below++;
			} else {
				$side = 'above';
				$above++;
			}

			$actives[] = array(
				'position'  => $el['position'],
				'post_id'   => $el['post_id'],
				'inci_name' => $el['inci_name'],
				'side'      => $side,
			);
		}

		// Confidence: to be sure which side an active is on you need both a
		// located, confirmed line and decent coverage of the list.
		$line_conf     = $line['finding']['confidence'];
		$coverage_conf = self::coverage_confidence( $counts );

		if ( empty( $actives ) ) {
			// No actives found among the matches — coverage decides how sure.
			$confidence = $coverage_conf;
		} else {
			$confidence = self::lower_confidence( $line_conf, $coverage_conf );
		}

		return array(
			'type'       => 'actives',
			'confidence' => $confidence,
			'data'       => array(
				'count'               => count( $actives ),
				'above_count'         => $above,
				'below_count'         => $below,
				'undetermined_count'  => $undet,
				'line_status'         => $located ? 'located' : 'undetermined',
				'actives'             => $actives,
			),
		);
	}

	/**
	 * Rule 3: describe the base by counting roles across the top third.
	 *
	 * @param array $sequence      The enriched sequence.
	 * @param int   $top_third_end The exclusive end index of the top third.
	 * @return array The base finding.
	 */
	private static function rule_base( $sequence, $top_third_end ) {
		$role_counts = array();
		$in_top      = 0;
		$matched_top = 0;

		foreach ( $sequence as $el ) {
			if ( $el['position'] >= $top_third_end ) {
				continue;
			}
			$in_top++;

			if ( ! $el['matched'] ) {
				continue;
			}
			$matched_top++;

			foreach ( $el['roles'] as $role ) {
				if ( ! isset( $role_counts[ $role ] ) ) {
					$role_counts[ $role ] = 0;
				}
				$role_counts[ $role ]++;
			}
		}

		// Sort roles by count (desc). arsort keeps the association; ties keep
		// their insertion order, which is the order roles first appear.
		arsort( $role_counts );

		$ranked = array();
		foreach ( $role_counts as $role => $count ) {
			$ranked[] = array(
				'role'  => $role,
				'count' => $count,
			);
		}

		// The roles sharing the highest count — what the base leans on.
		$leading = array();
		if ( ! empty( $ranked ) ) {
			$top = $ranked[0]['count'];
			foreach ( $ranked as $row ) {
				if ( $row['count'] === $top ) {
					$leading[] = $row['role'];
				}
			}
		}

		// Confidence follows how much of the top third we could actually read.
		$ratio      = ( $in_top > 0 ) ? ( $matched_top / $in_top ) : 0.0;
		$confidence = self::confidence_from_ratio( $ratio );

		return array(
			'type'       => 'base',
			'confidence' => $confidence,
			'data'       => array(
				'top_third'            => array(
					'start' => 0,
					'end'   => $top_third_end,
				),
				'items_in_top_third'   => $in_top,
				'matched_in_top_third' => $matched_top,
				'role_counts'          => $role_counts,
				'ranked'               => $ranked,
				'leading_roles'        => $leading,
			),
		);
	}

	/**
	 * Rule 4: note shape observations. Returns zero or more shape findings.
	 *
	 * @param array $sequence      The enriched sequence.
	 * @param int   $top_third_end The exclusive end index of the top third.
	 * @param int   $top_half_end  The exclusive end index of the top half.
	 * @param array $config        The thresholds.
	 * @return array A list of shape findings (possibly empty).
	 */
	private static function rule_shape( $sequence, $top_third_end, $top_half_end, $config ) {
		$n     = count( $sequence );
		$shape = array();

		// Unusually short list. List length is factual, so confidence is high.
		if ( $n <= (int) $config['short_list_max'] ) {
			$shape[] = array(
				'type'       => 'shape',
				'confidence' => 'high',
				'data'       => array(
					'observation' => 'short_list',
					'length'      => $n,
					'threshold'   => (int) $config['short_list_max'],
				),
			);
		}

		// Unusually long list.
		if ( $n >= (int) $config['long_list_min'] ) {
			$shape[] = array(
				'type'       => 'shape',
				'confidence' => 'high',
				'data'       => array(
					'observation' => 'long_list',
					'length'      => $n,
					'threshold'   => (int) $config['long_list_min'],
				),
			);
		}

		// Fragrance sitting in the top half of the list.
		$fragrance = array();
		foreach ( $sequence as $el ) {
			if ( $el['matched'] && $el['position'] < $top_half_end && in_array( 'fragrance', $el['roles'], true ) ) {
				$fragrance[] = array(
					'position'  => $el['position'],
					'post_id'   => $el['post_id'],
					'inci_name' => $el['inci_name'],
				);
			}
		}
		if ( ! empty( $fragrance ) ) {
			$shape[] = array(
				'type'       => 'shape',
				'confidence' => 'medium',
				'data'       => array(
					'observation'  => 'fragrance_high',
					'ingredients'  => $fragrance,
					'top_half_end' => $top_half_end,
				),
			);
		}

		// A heavily loaded top third: several actives crowded near the top.
		$active_positions = array();
		foreach ( $sequence as $el ) {
			if ( $el['matched'] && $el['position'] < $top_third_end && in_array( 'active', $el['roles'], true ) ) {
				$active_positions[] = $el['position'];
			}
		}
		if ( count( $active_positions ) >= (int) $config['loaded_top_third_min_actives'] ) {
			$shape[] = array(
				'type'       => 'shape',
				'confidence' => 'medium',
				'data'       => array(
					'observation'         => 'loaded_top_third',
					'actives_in_top_third' => count( $active_positions ),
					'positions'           => $active_positions,
					'threshold'           => (int) $config['loaded_top_third_min_actives'],
					'top_third_end'       => $top_third_end,
				),
			);
		}

		return $shape;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Confidence helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * A confidence flag from how much of the whole list was matched.
	 *
	 * @param array $counts The outcome counts.
	 * @return string 'high', 'medium' or 'low'.
	 */
	private static function coverage_confidence( $counts ) {
		$total   = isset( $counts['total'] ) ? (int) $counts['total'] : 0;
		$matched = isset( $counts['matched'] ) ? (int) $counts['matched'] : 0;
		$ratio   = ( $total > 0 ) ? ( $matched / $total ) : 0.0;

		return self::confidence_from_ratio( $ratio );
	}

	/**
	 * Map a 0–1 coverage ratio to a confidence flag.
	 *
	 * @param float $ratio The ratio.
	 * @return string 'high' (>= 0.8), 'medium' (>= 0.5) or 'low'.
	 */
	private static function confidence_from_ratio( $ratio ) {
		if ( $ratio >= 0.8 ) {
			return 'high';
		}
		if ( $ratio >= 0.5 ) {
			return 'medium';
		}
		return 'low';
	}

	/**
	 * Return the lower of two confidence flags.
	 *
	 * @param string $a One flag.
	 * @param string $b The other.
	 * @return string The weaker of the two.
	 */
	private static function lower_confidence( $a, $b ) {
		$rank = array(
			'low'    => 0,
			'medium' => 1,
			'high'   => 2,
		);
		$ra = isset( $rank[ $a ] ) ? $rank[ $a ] : 0;
		$rb = isset( $rank[ $b ] ) ? $rank[ $b ] : 0;

		return ( $ra <= $rb ) ? $a : $b;
	}
}
