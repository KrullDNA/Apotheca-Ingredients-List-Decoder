<?php
/**
 * The result cache.
 *
 * The complete result of decoding a list is deterministic for a given list and a
 * given library, so it is cached keyed on a hash of the normalised ingredient
 * list. A cache generation number, bumped whenever an ingredient entry is saved
 * or removed, retires every cached result at once — so an edit to the library is
 * reflected immediately, without hunting down individual keys.
 *
 * The read-next block is not part of the cached payload: it varies by page, and
 * has its own per-term-set cache.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caches decoded results and invalidates them on library changes.
 */
class ILD_Cache {

	/**
	 * The cache-generation option.
	 *
	 * @var string
	 */
	const GEN_OPTION = 'ild_result_cache_gen';

	/**
	 * How long a cached result lives, in seconds.
	 *
	 * @var int
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Hook the invalidation onto ingredient saves and deletes.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'save_post_' . ILD_Post_Types::POST_TYPE, array( __CLASS__, 'bust' ) );
		add_action( 'deleted_post', array( __CLASS__, 'bust_on_delete' ) );
	}

	/**
	 * The current cache generation.
	 *
	 * @return int
	 */
	public static function generation() {
		return max( 1, (int) get_option( self::GEN_OPTION, 1 ) );
	}

	/**
	 * The transient key for a normalised list.
	 *
	 * The plugin version is part of the key so that a plugin update retires every
	 * cached result at once. A cached result is a pre-built view model; when the
	 * presenter or a template changes shape between versions, an old payload must
	 * never be replayed through the new templates, or it renders with missing
	 * pieces (an empty detail panel, an invisible action). Keying on the version
	 * makes a code change invalidate the cache exactly as a library edit does.
	 *
	 * @param string $normalised The normalised ingredient list.
	 * @return string
	 */
	private static function key( $normalised ) {
		$version = defined( 'ILD_VERSION' ) ? ILD_VERSION : '0';
		return 'ild_res_' . $version . '_' . self::generation() . '_' . md5( (string) $normalised );
	}

	/**
	 * Read a cached result for a normalised list.
	 *
	 * @param string $normalised The normalised ingredient list.
	 * @return array|false
	 */
	public static function get( $normalised ) {
		$value = get_transient( self::key( $normalised ) );
		return is_array( $value ) ? $value : false;
	}

	/**
	 * Store a result for a normalised list.
	 *
	 * @param string $normalised The normalised ingredient list.
	 * @param array  $payload    The payload to cache.
	 * @return void
	 */
	public static function set( $normalised, $payload ) {
		set_transient( self::key( $normalised ), $payload, self::TTL );
	}

	/**
	 * Retire every cached result, on an ingredient save.
	 *
	 * @param int $post_id The saved ingredient.
	 * @return void
	 */
	public static function bust( $post_id = 0 ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( $post_id && wp_is_post_revision( $post_id ) ) {
			return;
		}
		update_option( self::GEN_OPTION, self::generation() + 1 );
	}

	/**
	 * Retire the cache when an ingredient is deleted.
	 *
	 * @param int $post_id The deleted post.
	 * @return void
	 */
	public static function bust_on_delete( $post_id ) {
		if ( ILD_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			self::bust();
		}
	}
}
