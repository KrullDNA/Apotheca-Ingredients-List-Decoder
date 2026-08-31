<?php
/**
 * The read-next block: the articles worth reading after a formula.
 *
 * This is the reserved region Stage 6 left empty. It takes the Stage 5 findings,
 * gathers the Skin Topic and Ingredient Family terms of the ingredients that
 * actually generated those findings, and finds published posts that share them.
 * Topic matches are weighted above family matches; posts are ranked by how many
 * terms they share (weighted) and then by recency, and at most three are shown.
 *
 * If nothing shares a term, it returns nothing. It never falls back to recent or
 * popular posts — an irrelevant "read next" is worse than none.
 *
 * The ranked candidates are cached per term-set. A cache generation number,
 * bumped whenever a post or an ingredient is saved, retires every cached set at
 * once without having to hunt down individual keys.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the read-next card list from the findings.
 */
class ILD_Read_Next {

	/**
	 * The prefix for the per-term-set cache transients.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'ild_rn_';

	/**
	 * How long a ranked candidate set is cached, in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * The option holding the cache generation number.
	 *
	 * @var string
	 */
	const GEN_OPTION = 'ild_readnext_cache_gen';

	/**
	 * How many cards to show at most.
	 *
	 * @var int
	 */
	const MAX_CARDS = 3;

	/**
	 * How many ranked candidates to hold in the cache, so the current page can be
	 * excluded at render time and still leave enough to show.
	 *
	 * @var int
	 */
	const CANDIDATE_COUNT = 6;

	/**
	 * How many matching posts to consider before ranking.
	 *
	 * @var int
	 */
	const QUERY_LIMIT = 50;

	/**
	 * Hook the cache-busting onto post and ingredient saves.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'save_post', array( __CLASS__, 'bust_cache' ), 10, 2 );
	}

	/**
	 * Build the read-next cards for a set of findings.
	 *
	 * @param array $analysis   The Stage 5 analysis (findings + meta).
	 * @param array $items      The Stage 4 ordered items (for the base ingredients).
	 * @param int   $exclude_id A post to leave out — the page the tool is on.
	 * @return array A list of card view models (possibly empty).
	 */
	public static function build( $analysis, $items, $exclude_id = 0 ) {
		// The ingredients that generated findings, then their terms.
		$ingredient_ids = self::contributing_ids( $analysis, is_array( $items ) ? $items : array() );
		if ( empty( $ingredient_ids ) ) {
			return array();
		}

		$terms = self::collect_terms( $ingredient_ids );
		if ( empty( $terms['topic'] ) && empty( $terms['family'] ) ) {
			return array();
		}

		// The ranked candidates for this term-set (cached).
		$ranked = self::ranked_candidates( $terms );

		// Exclude the current page and turn the top few into cards.
		$cards = array();
		foreach ( $ranked as $candidate ) {
			if ( $exclude_id && (int) $candidate['id'] === (int) $exclude_id ) {
				continue;
			}
			$card = self::card( (int) $candidate['id'] );
			if ( $card ) {
				$cards[] = $card;
			}
			if ( count( $cards ) >= self::MAX_CARDS ) {
				break;
			}
		}

		return $cards;
	}

	/**
	 * The post IDs of the ingredients that actually generated findings.
	 *
	 * Two sources: any ingredient a finding names (the sub-one markers, the
	 * actives, a high fragrance), collected straight from the findings; and the
	 * matched ingredients across the top third, which together generate the base
	 * finding but are not named individually in it.
	 *
	 * @param array $analysis The analysis.
	 * @param array $items    The ordered items.
	 * @return int[] The unique contributing ingredient IDs.
	 */
	private static function contributing_ids( $analysis, $items ) {
		$ids = array();

		// Every post_id named anywhere in the findings.
		$findings = isset( $analysis['findings'] ) ? $analysis['findings'] : array();
		array_walk_recursive(
			$findings,
			function ( $value, $key ) use ( &$ids ) {
				if ( 'post_id' === $key && $value ) {
					$ids[] = (int) $value;
				}
			}
		);

		// The matched ingredients in the top third, behind the base finding.
		$end      = isset( $analysis['meta']['top_third']['end'] ) ? (int) $analysis['meta']['top_third']['end'] : 0;
		$position = 0;
		foreach ( $items as $item ) {
			if ( $position < $end && isset( $item['status'] ) && 'matched' === $item['status'] && ! empty( $item['post_id'] ) ) {
				$ids[] = (int) $item['post_id'];
			}
			$position++;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Collect the topic and family term IDs of a set of ingredients.
	 *
	 * @param int[] $ingredient_ids The ingredient post IDs.
	 * @return array { 'topic' => int[], 'family' => int[] }.
	 */
	private static function collect_terms( $ingredient_ids ) {
		$topic  = array();
		$family = array();

		foreach ( $ingredient_ids as $id ) {
			$t = wp_get_object_terms( $id, ILD_Post_Types::TAX_TOPIC, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $t ) ) {
				$topic = array_merge( $topic, $t );
			}

			$f = wp_get_object_terms( $id, ILD_Post_Types::TAX_FAMILY, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $f ) ) {
				$family = array_merge( $family, $f );
			}
		}

		return array(
			'topic'  => array_values( array_unique( array_map( 'intval', $topic ) ) ),
			'family' => array_values( array_unique( array_map( 'intval', $family ) ) ),
		);
	}

	/**
	 * The taxonomy weights: a shared topic counts for more than a shared family.
	 *
	 * @return array { 'topic' => int, 'family' => int }.
	 */
	private static function weights() {
		$weights = apply_filters(
			'ild_readnext_weights',
			array(
				'topic'  => 2,
				'family' => 1,
			)
		);

		return array(
			'topic'  => isset( $weights['topic'] ) ? (int) $weights['topic'] : 2,
			'family' => isset( $weights['family'] ) ? (int) $weights['family'] : 1,
		);
	}

	/**
	 * The ranked candidate posts for a term-set, cached per term-set.
	 *
	 * @param array $terms The topic and family term IDs.
	 * @return array A ranked list of { id, score, shared }.
	 */
	private static function ranked_candidates( $terms ) {
		$key    = self::cache_key( $terms );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ranked = self::query_and_rank( $terms );
		set_transient( $key, $ranked, self::CACHE_TTL );

		return $ranked;
	}

	/**
	 * The transient key for a term-set, namespaced by the cache generation.
	 *
	 * @param array $terms The term IDs.
	 * @return string
	 */
	private static function cache_key( $terms ) {
		$topic  = $terms['topic'];
		$family = $terms['family'];
		sort( $topic );
		sort( $family );

		$signature = wp_json_encode(
			array(
				'topic'   => $topic,
				'family'  => $family,
				'weights' => self::weights(),
			)
		);

		return self::CACHE_PREFIX . self::generation() . '_' . md5( (string) $signature );
	}

	/**
	 * Query the posts sharing any term and rank them.
	 *
	 * Order is by weighted shared-term score (topic above family), then by
	 * recency. The query returns the most recent matches first, so a stable
	 * secondary sort by that original order gives the recency tiebreak.
	 *
	 * @param array $terms The topic and family term IDs.
	 * @return array A ranked list of { id, score, shared }, capped.
	 */
	private static function query_and_rank( $terms ) {
		$tax_query = array( 'relation' => 'OR' );
		if ( ! empty( $terms['topic'] ) ) {
			$tax_query[] = array(
				'taxonomy' => ILD_Post_Types::TAX_TOPIC,
				'field'    => 'term_id',
				'terms'    => $terms['topic'],
			);
		}
		if ( ! empty( $terms['family'] ) ) {
			$tax_query[] = array(
				'taxonomy' => ILD_Post_Types::TAX_FAMILY,
				'field'    => 'term_id',
				'terms'    => $terms['family'],
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => 'publish',
				'posts_per_page'         => self::QUERY_LIMIT,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Intentional term match, capped and cached.
			)
		);

		$weights = self::weights();
		$scored  = array();
		$index   = 0;

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;

			$post_topics   = wp_get_object_terms( $post_id, ILD_Post_Types::TAX_TOPIC, array( 'fields' => 'ids' ) );
			$post_families = wp_get_object_terms( $post_id, ILD_Post_Types::TAX_FAMILY, array( 'fields' => 'ids' ) );
			$post_topics   = is_wp_error( $post_topics ) ? array() : array_map( 'intval', $post_topics );
			$post_families = is_wp_error( $post_families ) ? array() : array_map( 'intval', $post_families );

			$shared_topics   = count( array_intersect( $post_topics, $terms['topic'] ) );
			$shared_families = count( array_intersect( $post_families, $terms['family'] ) );

			$score = ( $shared_topics * $weights['topic'] ) + ( $shared_families * $weights['family'] );
			if ( $score <= 0 ) {
				continue;
			}

			$scored[] = array(
				'id'     => $post_id,
				'score'  => $score,
				'shared' => $shared_topics + $shared_families,
				'index'  => $index,
			);
			$index++;
		}

		// Highest score first; ties broken by the original (recency) order.
		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] - $a['score'];
				}
				return $a['index'] - $b['index'];
			}
		);

		// Keep only what we need, and drop the internal sort index.
		$ranked = array();
		foreach ( array_slice( $scored, 0, self::CANDIDATE_COUNT ) as $row ) {
			$ranked[] = array(
				'id'     => $row['id'],
				'score'  => $row['score'],
				'shared' => $row['shared'],
			);
		}

		return $ranked;
	}

	/**
	 * Build a card view model for a post, or null if it is not showable.
	 *
	 * @param int $post_id The post ID.
	 * @return array|null The card, or null.
	 */
	private static function card( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || 'post' !== $post->post_type ) {
			return null;
		}

		return array(
			'id'      => $post_id,
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'excerpt' => self::excerpt( $post ),
			'thumb'   => (string) get_the_post_thumbnail_url( $post_id, 'medium' ),
			'meta'    => get_the_date( '', $post ),
		);
	}

	/**
	 * A short excerpt for a post: its own, or a trimmed one from the content.
	 *
	 * @param WP_Post $post The post.
	 * @return string
	 */
	private static function excerpt( $post ) {
		if ( has_excerpt( $post ) ) {
			return wp_strip_all_tags( get_the_excerpt( $post ) );
		}

		$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		return wp_trim_words( $content, 24, '…' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Cache generation
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The current cache generation number.
	 *
	 * @return int
	 */
	private static function generation() {
		return max( 1, (int) get_option( self::GEN_OPTION, 1 ) );
	}

	/**
	 * Retire every cached candidate set by bumping the generation number.
	 *
	 * Runs on any post or ingredient save. Skips autosaves and revisions so a
	 * routine editor keystroke does not churn the cache needlessly.
	 *
	 * @param int          $post_id The saved post.
	 * @param WP_Post|null $post    The post object, when provided.
	 * @return void
	 */
	public static function bust_cache( $post_id, $post = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post_type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( ! in_array( $post_type, array( 'post', ILD_Post_Types::POST_TYPE ), true ) ) {
			return;
		}

		update_option( self::GEN_OPTION, self::generation() + 1 );
	}
}
