<?php
/**
 * The presenter: turning findings and matches into a phrased view model.
 *
 * This is the layer Stage 5 left open. The analysis engine returns data; the
 * templates render markup; this sits between them, turning findings into
 * Apotheca-voice sentences (via ILD_Phrases) and each token into a row the
 * templates can show. It decides nothing about the analysis and holds no
 * wording of its own — it only assembles.
 *
 * The output is a plain array (a "view model"). Templates read it and escape it;
 * nothing here echoes HTML.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assembles a phrased view model from the match result and the findings.
 */
class ILD_Presenter {

	/**
	 * Build the view model for a result, an empty state, or an error.
	 *
	 * @param array|WP_Error $match_result The Stage 4 match result, or a parser error.
	 * @param array|null     $analysis     The Stage 5 analysis, or null on error.
	 * @param array          $context      Optional context, e.g. { exclude_id }.
	 * @return array The view model: always has a 'state' of result|empty|error.
	 */
	public static function present( $match_result, $analysis, $context = array() ) {
		// A parser error (for example, input too long) becomes an error state.
		if ( is_wp_error( $match_result ) ) {
			$code    = $match_result->get_error_code();
			$message = ( 'ild_input_too_long' === $code )
				? ILD_Phrases::error_too_long()
				: ILD_Phrases::error_generic();

			return array(
				'state'   => 'error',
				'message' => $message,
			);
		}

		$meta = ( is_array( $analysis ) && isset( $analysis['meta'] ) ) ? $analysis['meta'] : array( 'total' => 0, 'matched' => 0 );

		// Nothing recognisable in the text at all.
		if ( empty( $meta['total'] ) ) {
			return array(
				'state'   => 'empty',
				'message' => ILD_Phrases::empty_no_tokens(),
			);
		}

		$items       = isset( $match_result['items'] ) ? $match_result['items'] : array();
		$summary     = self::build_summary( $analysis );
		$ingredients = self::build_ingredients( $items );

		// The read-next block: articles that share the terms of the ingredients
		// behind the findings. Empty when nothing shares a term — never a
		// fallback to recent or popular posts.
		$exclude_id = isset( $context['exclude_id'] ) ? (int) $context['exclude_id'] : 0;
		$readnext   = ILD_Read_Next::build( $analysis, $items, $exclude_id );

		return array(
			'state'          => 'result',
			'summary'        => $summary['points'],
			'summary_caveat' => $summary['caveat'],
			'ingredients'    => $ingredients,
			'readnext'       => $readnext,
			'counts'         => $meta,
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * The summary
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Turn the findings into an ordered set of summary sentences.
	 *
	 * @param array $analysis The Stage 5 analysis.
	 * @return array { 'points' => string[], 'caveat' => string }.
	 */
	private static function build_summary( $analysis ) {
		$findings = isset( $analysis['findings'] ) ? $analysis['findings'] : array();
		$meta     = isset( $analysis['meta'] ) ? $analysis['meta'] : array();

		// If almost nothing matched, say so plainly and stop.
		if ( empty( $meta['matched'] ) ) {
			return array(
				'points' => array( self::point( ILD_Phrases::summary_insufficient(), null ) ),
				'caveat' => '',
			);
		}

		$points = array();
		$caveat = '';

		$base   = self::find_finding( $findings, 'base' );
		$line   = self::find_finding( $findings, 'one_percent_line' );
		$active = self::find_finding( $findings, 'actives' );

		// What it's built on.
		if ( $base ) {
			$point = self::base_sentence( $base['data'] );
			if ( '' !== $point ) {
				$points[] = self::point( $point, $base );
			}
		}

		// The one per cent line (and its caveat where the line was placed).
		if ( $line ) {
			$status = $line['data']['status'];
			if ( 'located' === $status && ! empty( $line['data']['confirmed'] ) ) {
				$points[] = self::point( ILD_Phrases::summary_line_confirmed(), $line );
				$caveat   = ILD_Phrases::summary_line_caveat();
			} elseif ( 'located' === $status ) {
				$points[] = self::point( ILD_Phrases::summary_line_single(), $line );
				$caveat   = ILD_Phrases::summary_line_caveat();
			} else {
				$points[] = self::point( ILD_Phrases::summary_line_undetermined(), $line );
			}
		}

		// Where the actives fall against that line.
		if ( $active && $active['data']['count'] > 0 ) {
			$points[] = self::point( self::actives_sentence( $active['data'] ), $active );
		}

		// Shape observations, in the order the engine reported them.
		foreach ( $findings as $finding ) {
			if ( 'shape' !== $finding['type'] ) {
				continue;
			}
			$sentence = self::shape_sentence( $finding['data']['observation'] );
			if ( '' !== $sentence ) {
				$points[] = self::point( $sentence, $finding );
			}
		}

		return array(
			'points' => $points,
			'caveat' => $caveat,
		);
	}

	/**
	 * Wrap a summary sentence with the confidence of the finding behind it.
	 *
	 * The front end never states the confidence in words — it stays a hedged
	 * sentence — but carrying the level lets the templates (and Stage 7's style
	 * controls) mark high, medium and low findings differently if a designer
	 * chooses to. A finding with no recognised confidence carries an empty level.
	 *
	 * @param string     $text    The assembled sentence.
	 * @param array|null $finding The finding the sentence came from.
	 * @return array { 'text' => string, 'level' => string }.
	 */
	private static function point( $text, $finding = null ) {
		$level = is_array( $finding ) && isset( $finding['confidence'] ) ? $finding['confidence'] : '';
		if ( ! in_array( $level, array( 'high', 'medium', 'low' ), true ) ) {
			$level = '';
		}

		return array(
			'text'  => $text,
			'level' => $level,
		);
	}

	/**
	 * The "built on" sentence from the base finding.
	 *
	 * @param array $data The base finding's data.
	 * @return string The sentence, or '' if there is nothing to say.
	 */
	private static function base_sentence( $data ) {
		$ranked = isset( $data['ranked'] ) ? $data['ranked'] : array();
		if ( empty( $ranked ) ) {
			return '';
		}

		$top = $ranked[0]['count'];

		// When a role clearly leads (it appears more than once), name the
		// leaders, then up to two supporting roles beneath them.
		if ( $top >= 2 ) {
			$leading_roles   = isset( $data['leading_roles'] ) ? $data['leading_roles'] : array();
			$leading_text    = self::roles_to_text( $leading_roles );

			$secondary_roles = array();
			foreach ( $ranked as $row ) {
				if ( $row['count'] < $top ) {
					$secondary_roles[] = $row['role'];
				}
			}
			$secondary_text = self::roles_to_text( array_slice( $secondary_roles, 0, 2 ) );

			return ILD_Phrases::summary_built_on( $leading_text, $secondary_text );
		}

		// Otherwise the top of the list is a flat mix; name a few of the roles.
		$roles = array();
		foreach ( $ranked as $row ) {
			$roles[] = $row['role'];
		}

		return ILD_Phrases::summary_base_mixed( self::roles_to_text( array_slice( $roles, 0, 3 ) ) );
	}

	/**
	 * The actives sentence, chosen by which side of the line they fall on.
	 *
	 * @param array $data The actives finding's data.
	 * @return string
	 */
	private static function actives_sentence( $data ) {
		$above = array();
		$below = array();
		$all   = array();

		foreach ( $data['actives'] as $active ) {
			$all[] = $active['inci_name'];
			if ( 'above' === $active['side'] ) {
				$above[] = $active['inci_name'];
			} elseif ( 'below' === $active['side'] ) {
				$below[] = $active['inci_name'];
			}
		}

		// No line to place them against: just name them.
		if ( 'located' !== $data['line_status'] ) {
			return ILD_Phrases::summary_actives_names( ILD_Phrases::list_to_text( $all ) );
		}

		if ( empty( $below ) ) {
			return ILD_Phrases::summary_actives_above( ILD_Phrases::list_to_text( $above ) );
		}
		if ( empty( $above ) ) {
			return ILD_Phrases::summary_actives_below( ILD_Phrases::list_to_text( $below ) );
		}

		return ILD_Phrases::summary_actives_split(
			ILD_Phrases::list_to_text( $above ),
			ILD_Phrases::list_to_text( $below )
		);
	}

	/**
	 * Map a shape observation key to its sentence.
	 *
	 * @param string $observation The observation key.
	 * @return string The sentence, or '' if unknown.
	 */
	private static function shape_sentence( $observation ) {
		switch ( $observation ) {
			case 'short_list':
				return ILD_Phrases::summary_shape_short();
			case 'long_list':
				return ILD_Phrases::summary_shape_long();
			case 'fragrance_high':
				return ILD_Phrases::summary_shape_fragrance();
			case 'loaded_top_third':
				return ILD_Phrases::summary_shape_loaded();
			default:
				return '';
		}
	}

	/**
	 * Turn a list of role slugs into natural, pluralised English.
	 *
	 * @param array $slugs The role slugs.
	 * @return string
	 */
	private static function roles_to_text( $slugs ) {
		$labels = array();
		foreach ( (array) $slugs as $slug ) {
			$labels[] = ILD_Phrases::role_plural( ILD_Roles::get_label( $slug ) );
		}

		return ILD_Phrases::list_to_text( $labels );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The ingredient rows
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Build one display row per token, preserving order.
	 *
	 * Matched rows carry role, family and description; the rest carry one of the
	 * three unmatched states (a suggestion, not-in-library, or unreadable).
	 *
	 * @param array $items The ordered items from the matcher.
	 * @return array The display rows.
	 */
	private static function build_ingredients( $items ) {
		// Prime the meta cache for every entry we will show in full — the matched
		// ones, and the library entry behind each "did you mean…" suggestion.
		$ids = array();
		foreach ( $items as $item ) {
			$status = isset( $item['status'] ) ? $item['status'] : '';
			if ( 'matched' === $status && ! empty( $item['post_id'] ) ) {
				$ids[] = (int) $item['post_id'];
			} elseif ( 'suggestion' === $status && ! empty( $item['suggestion']['post_id'] ) ) {
				$ids[] = (int) $item['suggestion']['post_id'];
			}
		}
		if ( ! empty( $ids ) ) {
			update_meta_cache( 'post', array_unique( $ids ) );
		}

		$rows     = array();
		$position = 0;

		foreach ( $items as $item ) {
			$position++;
			$status   = isset( $item['status'] ) ? $item['status'] : 'unmatched';
			$original = isset( $item['original'] ) ? $item['original'] : '';

			if ( 'matched' === $status && ! empty( $item['post_id'] ) ) {
				$rows[] = self::matched_row( $position, $item );
			} elseif ( 'suggestion' === $status ) {
				$rows[] = self::suggestion_row( $position, $item );
			} else {
				// Unmatched: is it a plausible ingredient we simply lack, or noise?
				$kind        = self::classify_unmatched( $original );
				$status_text = ( 'unknown' === $kind ) ? ILD_Phrases::not_in_library() : ILD_Phrases::unreadable();
				$rows[]      = array(
					'kind'        => $kind,
					'position'    => $position,
					'label'       => $original,
					'status_text' => $status_text,
				);
			}
		}

		return $rows;
	}

	/**
	 * Build a matched ingredient row from its entry.
	 *
	 * @param int   $position The 1-based position in the list.
	 * @param array $item     The matched item.
	 * @return array The row.
	 */
	private static function matched_row( $position, $item ) {
		$id      = (int) $item['post_id'];
		$details = self::entry_details( $id );

		$label = ( isset( $item['inci_name'] ) && '' !== $item['inci_name'] ) ? $item['inci_name'] : ( isset( $item['original'] ) ? $item['original'] : '' );

		return array_merge(
			array(
				'kind'        => 'matched',
				'position'    => $position,
				'label'       => $label,
				'status_text' => '',
			),
			$details
		);
	}

	/**
	 * Build a "did you mean…" suggestion row from the entry it points at.
	 *
	 * The token as typed did not match, but one library entry is a believable
	 * near-miss. The row shows that entry in full — its roles, family and
	 * description, exactly as a matched row would — beneath a "Did you mean X?"
	 * line, and carries the two strings the front end needs to swap the mistyped
	 * token for the suggested INCI name in the textarea and read the list again.
	 *
	 * @param int   $position The 1-based position in the list.
	 * @param array $item     The suggestion item { original, suggestion }.
	 * @return array The row.
	 */
	private static function suggestion_row( $position, $item ) {
		$suggestion = isset( $item['suggestion'] ) ? $item['suggestion'] : array();
		$id         = isset( $suggestion['post_id'] ) ? (int) $suggestion['post_id'] : 0;
		$sug_name   = isset( $suggestion['inci_name'] ) ? $suggestion['inci_name'] : '';
		$original   = isset( $item['original'] ) ? $item['original'] : '';

		$details = self::entry_details( $id );

		return array_merge(
			array(
				'kind'              => 'suggestion',
				'position'          => $position,
				'label'             => $original,
				'status_text'       => ILD_Phrases::did_you_mean( $sug_name ),
				'suggested_name'    => $sug_name,
				'apply_original'    => $original,
				'apply_replacement' => $sug_name,
				'apply_label'       => ILD_Phrases::apply_suggestion(),
			),
			$details
		);
	}

	/**
	 * Gather the displayable details of one library entry.
	 *
	 * Shared by matched rows and suggestion rows so both show an entry the same
	 * way: its roles and family as readable text, its description, and the optional
	 * evidence note and founder take shown inside the expander.
	 *
	 * @param int $id The ingredient's post ID.
	 * @return array { roles_text, family_text, description, evidence, founder }.
	 */
	private static function entry_details( $id ) {
		$id = (int) $id;

		if ( $id <= 0 ) {
			return array(
				'roles_text'  => ILD_Phrases::row_none(),
				'family_text' => ILD_Phrases::row_none(),
				'description' => '',
				'evidence'    => '',
				'founder'     => '',
			);
		}

		// Roles, as human labels. Blank slugs (a stray empty value on an entry
		// still being built) are dropped so the row shows a dash, not "Role:".
		$roles       = get_post_meta( $id, '_ild_role', true );
		$roles       = is_array( $roles ) ? $roles : array();
		$role_labels = array_map( array( 'ILD_Roles', 'get_label' ), $roles );
		$role_labels = array_values( array_filter( array_map( 'trim', $role_labels ), 'strlen' ) );

		// Families, as term names, with any blank name dropped likewise.
		$families = wp_get_object_terms( $id, ILD_Post_Types::TAX_FAMILY, array( 'fields' => 'names' ) );
		if ( is_wp_error( $families ) ) {
			$families = array();
		}
		$families = array_values( array_filter( array_map( 'trim', $families ), 'strlen' ) );

		// The description, if any.
		$description = get_post_meta( $id, '_ild_description', true );
		$description = is_string( $description ) ? $description : '';

		// The optional evidence note and founder take. These are shown inside the
		// expanded panel beneath the description, only when they hold something.
		$evidence = get_post_meta( $id, '_ild_evidence_note', true );
		$evidence = is_string( $evidence ) ? $evidence : '';

		$founder = get_post_meta( $id, '_ild_founder_take', true );
		$founder = is_string( $founder ) ? $founder : '';

		return array(
			'roles_text'  => ! empty( $role_labels ) ? implode( ', ', $role_labels ) : ILD_Phrases::row_none(),
			'family_text' => ! empty( $families ) ? implode( ', ', $families ) : ILD_Phrases::row_none(),
			'description' => $description,
			'evidence'    => $evidence,
			'founder'     => $founder,
		);
	}

	/**
	 * Decide whether an unmatched token is a plausible ingredient or noise.
	 *
	 * A plausible-but-unknown token is one we simply do not have yet; a token
	 * that barely looks like a word at all is treated as unreadable. The two are
	 * shown very differently, so the caller must be able to tell them apart.
	 *
	 * @param string $token The original token.
	 * @return string 'unknown' (plausible, not in library) or 'unreadable'.
	 */
	private static function classify_unmatched( $token ) {
		$token = trim( (string) $token );

		// A colour-index code (CI 77891) is a real ingredient we may simply lack,
		// even though it is mostly digits — treat it as plausible up front.
		if ( preg_match( '/^ci\s*\d+/iu', $token ) ) {
			return 'unknown';
		}

		// Letters only, and the length ignoring spaces.
		$letters  = preg_match_all( '/\p{L}/u', $token );
		$nonspace = preg_replace( '/\s+/u', '', $token );
		$nonlen   = function_exists( 'mb_strlen' ) ? mb_strlen( $nonspace ) : strlen( $nonspace );

		// Too short, or barely any letters, to be a real name.
		if ( $nonlen < 3 || $letters < 2 ) {
			return 'unreadable';
		}

		// Mostly digits or symbols rather than letters.
		if ( $nonlen > 0 && ( $letters / $nonlen ) < 0.4 ) {
			return 'unreadable';
		}

		// A run of letters with no vowel at all is almost always noise — but a
		// colour-index code (CI 77891) is a legitimate exception.
		if ( $letters >= 4 && ! preg_match( '/[aeiouy]/iu', $token ) && ! preg_match( '/^ci\s*\d+/iu', $token ) ) {
			return 'unreadable';
		}

		return 'unknown';
	}

	/**
	 * Find the first finding of a given type.
	 *
	 * @param array  $findings The findings array.
	 * @param string $type     The type to look for.
	 * @return array|null The finding, or null.
	 */
	private static function find_finding( $findings, $type ) {
		foreach ( $findings as $finding ) {
			if ( isset( $finding['type'] ) && $type === $finding['type'] ) {
				return $finding;
			}
		}

		return null;
	}
}
