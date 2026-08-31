<?php
/**
 * The INCI key store: the backbone of duplicate prevention.
 *
 * Every ingredient carries a single normalised key derived from its INCI name.
 * The key is what duplicate checks compare — never the raw string — so that
 * "Retinol", "retinol " and "RETINOL" are one and the same, while "Ceramide NP"
 * and "Ceramide AP" stay distinct.
 *
 * The keys live in their own table with a UNIQUE index on the key column, so the
 * database itself refuses a second entry for the same key. That matters because
 * two saves arriving at the same instant would both pass a PHP-only check; the
 * unique index is the backstop the PHP check cannot be.
 *
 * The table is kept in step with the library automatically: on every save the
 * saved entry claims (or updates) its key, and on trash or delete it releases it.
 * Because every route that creates or renames an ingredient — the editor, Quick
 * Edit, the CSV importer, the AI drafter, any wp_insert_post — ends in save_post,
 * one hook covers them all.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores, matches and maintains the normalised INCI key for every ingredient.
 */
class ILD_Ingredient_Keys {

	/**
	 * The table name without the site prefix.
	 *
	 * @var string
	 */
	const TABLE = 'ild_ingredient_keys';

	/**
	 * The AJAX action (and nonce) for the as-you-type check.
	 *
	 * @var string
	 */
	const CHECK_ACTION = 'ild_check_inci';

	/**
	 * The full, prefixed table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Hook the key maintenance and the as-you-type check onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$type = ILD_Post_Types::POST_TYPE;

		// Keep the key table in step with the library, on every route.
		add_action( 'save_post_' . $type, array( $this, 'on_save' ), 20, 3 );
		add_action( 'trashed_post', array( $this, 'on_trash' ) );
		add_action( 'untrashed_post', array( $this, 'on_untrash' ) );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );

		// The as-you-type duplicate check on the editor screen.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_check' ) );
		add_action( 'wp_ajax_' . self::CHECK_ACTION, array( $this, 'ajax_check' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The normalised key
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Turn an INCI name into its normalised key.
	 *
	 * Lower-cased, whitespace collapsed to single spaces, leading and trailing
	 * punctuation stripped, and every hyphen or en-dash variant folded to a plain
	 * hyphen. This — and only this — is what duplicate checks compare.
	 *
	 * @param string $name The raw INCI name.
	 * @return string The normalised key (may be empty for a blank name).
	 */
	public static function key( $name ) {
		$string = (string) $name;

		// Fold hyphen and en-dash variants to a plain ASCII hyphen: U+2010 hyphen,
		// U+2011 non-breaking hyphen, U+2012 figure dash, U+2013 en dash, U+2212
		// minus sign, and the fullwidth hyphen-minus.
		$string = preg_replace( '/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2212}\x{FF0D}]/u', '-', $string );

		// Lower-case, using the multibyte-aware function where it exists.
		$string = function_exists( 'mb_strtolower' ) ? mb_strtolower( $string, 'UTF-8' ) : strtolower( $string );

		// Collapse every run of whitespace to a single space.
		$string = preg_replace( '/\s+/u', ' ', $string );

		// Strip leading and trailing punctuation and whitespace (a stray hyphen or
		// full stop at either end must not make two names look different).
		$string = preg_replace( '/^[\p{P}\s]+|[\p{P}\s]+$/u', '', $string );

		return (string) $string;
	}

	/*
	 * -----------------------------------------------------------------------
	 * The table
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Create or update the table. Safe to run repeatedly.
	 *
	 * The UNIQUE index on inci_key is the whole point: the database will not let a
	 * second row hold the same key, whatever a racing PHP check believes.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			post_id bigint(20) unsigned NOT NULL,
			inci_key varchar(191) NOT NULL,
			PRIMARY KEY  (post_id),
			UNIQUE KEY inci_key (inci_key)
		) $charset_collate;";

		dbDelta( $sql );

		// Backfill any existing ingredients that predate the table.
		self::backfill();
	}

	/**
	 * Populate keys for ingredients that have none yet.
	 *
	 * Runs once at install/upgrade. Entries are processed oldest first, so if two
	 * historical rows share a key the earliest keeps it and the later ones are
	 * simply left keyless (they will surface as duplicates on their next save).
	 *
	 * @return void
	 */
	private static function backfill() {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' ORDER BY ID ASC",
				ILD_Post_Types::POST_TYPE
			)
		);

		foreach ( (array) $ids as $id ) {
			$id  = (int) $id;
			$key = self::key( get_the_title( $id ) );
			if ( '' !== $key ) {
				self::claim( $id, $key );
			}
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Matching
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The ingredient that already holds a key, if any, ignoring one entry.
	 *
	 * @param string $key        The normalised key to look up.
	 * @param int    $exclude_id An entry to ignore (the one being saved).
	 * @return int The owning ingredient's ID, or 0 if the key is free.
	 */
	public static function owner_of_key( $key, $exclude_id = 0 ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE inci_key = %s AND post_id != %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				$key,
				(int) $exclude_id
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * The ingredient whose "also known as" aliases include a given key, if any.
	 *
	 * A match here is a warning, not a block, so it is worked out on demand rather
	 * than stored: the aliases are free text and change less predictably than the
	 * INCI name. Each alias line is put through the same key normalisation as the
	 * name, so the comparison is like for like.
	 *
	 * @param string $key        The normalised key to look for.
	 * @param int    $exclude_id An entry to ignore.
	 * @return array{id:int,alias:string}|null The owner and the matching alias, or null.
	 */
	public static function alias_owner( $key, $exclude_id = 0 ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return null;
		}

		global $wpdb;
		$exclude_id = (int) $exclude_id;

		// Only entries that actually have aliases, newest activity aside.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, m.meta_value AS aka
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON ( p.ID = m.post_id AND m.meta_key = '_ild_also_known_as' )
				WHERE p.post_type = %s AND p.post_status != 'trash' AND p.ID != %d",
				ILD_Post_Types::POST_TYPE,
				$exclude_id
			)
		);

		foreach ( (array) $rows as $row ) {
			$lines = preg_split( '/[\r\n]+/', (string) $row->aka );
			foreach ( (array) $lines as $line ) {
				if ( self::key( $line ) === $key ) {
					return array(
						'id'    => (int) $row->id,
						'alias' => trim( (string) $line ),
					);
				}
			}
		}

		return null;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Claiming and releasing
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Claim a key for an entry, or report the entry that already holds it.
	 *
	 * The PHP check comes first, but the write leans on the unique index: if a
	 * racing save claimed the key in between, the INSERT/UPDATE fails rather than
	 * quietly stealing it, and that failure is read back as a collision.
	 *
	 * @param int    $post_id The entry claiming the key.
	 * @param string $key     The normalised key.
	 * @return true|int True when claimed; otherwise the colliding entry's ID (or 0
	 *                  when a race left the winner unknown).
	 */
	public static function claim( $post_id, $key ) {
		$post_id = (int) $post_id;
		$key     = (string) $key;
		if ( $post_id <= 0 || '' === $key ) {
			return 0;
		}

		global $wpdb;
		$table = self::table();

		// Already taken by someone else?
		$owner = self::owner_of_key( $key, $post_id );
		if ( $owner ) {
			return $owner;
		}

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.

		// Let a unique-index breach surface as a return value, not a PHP notice.
		$suppress = $wpdb->suppress_errors( true );

		if ( null !== $existing ) {
			$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET inci_key = %s WHERE post_id = %d", $key, $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		} else {
			$result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} ( post_id, inci_key ) VALUES ( %d, %s )", $post_id, $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		}

		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			// A concurrent save took the key between the check and the write.
			$owner = self::owner_of_key( $key, $post_id );
			return $owner ? $owner : 0;
		}

		return true;
	}

	/**
	 * Remove an entry's key, freeing it for reuse.
	 *
	 * @param int $post_id The entry.
	 * @return void
	 */
	public static function remove( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Lifecycle hooks
	 * -----------------------------------------------------------------------
	 */

	/**
	 * On save, claim or update the entry's key — unless it is a duplicate.
	 *
	 * A duplicate save has already been forced to a draft by the block filter; its
	 * key is left with the original owner, so claim() simply declines and returns.
	 *
	 * @param int     $post_id The saved entry.
	 * @param WP_Post $post    The post object.
	 * @param bool    $update  Whether this was an update.
	 * @return void
	 */
	public function on_save( $post_id, $post, $update = false ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		// A trashed entry frees its key.
		if ( 'trash' === $post->post_status ) {
			self::remove( $post_id );
			return;
		}

		$key = self::key( $post->post_title );
		if ( '' === $key ) {
			self::remove( $post_id );
			return;
		}

		self::claim( $post_id, $key );
	}

	/**
	 * Free the key when an entry is trashed.
	 *
	 * @param int $post_id The trashed entry.
	 * @return void
	 */
	public function on_trash( $post_id ) {
		if ( ILD_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			self::remove( $post_id );
		}
	}

	/**
	 * Reclaim the key when an entry is restored from the trash, if it is free.
	 *
	 * @param int $post_id The restored entry.
	 * @return void
	 */
	public function on_untrash( $post_id ) {
		if ( ILD_Post_Types::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$key = self::key( get_the_title( $post_id ) );
		if ( '' !== $key ) {
			self::claim( $post_id, $key );
		}
	}

	/**
	 * Free the key when an entry is deleted for good.
	 *
	 * @param int $post_id The deleted entry.
	 * @return void
	 */
	public function on_delete( $post_id ) {
		if ( ILD_Post_Types::POST_TYPE === get_post_type( $post_id ) ) {
			self::remove( $post_id );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * The as-you-type check
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Load the editor check script on the ingredient add/edit screens.
	 *
	 * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue_editor_check( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ILD_Post_Types::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'ild-ingredient-check',
			ILD_PLUGIN_URL . 'assets/js/admin-ingredient.js',
			array(),
			ILD_VERSION,
			true
		);

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the edited post id only.

		wp_localize_script(
			'ild-ingredient-check',
			'ILD_Check',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => self::CHECK_ACTION,
				'nonce'    => wp_create_nonce( self::CHECK_ACTION ),
				'postId'   => $post_id,
				'messages' => array(
					/* translators: %s: the existing entry's INCI name. */
					'collision' => __( 'An ingredient with this INCI name already exists: %s. Saving is blocked so the library never holds two entries for the same name.', 'ingredient-list-decoder' ),
					/* translators: 1: the existing entry's INCI name, 2: the matching alias. */
					'alias'     => __( 'This name is already listed as an alias (“%2$s”) of %1$s. You can still save, but check they are not the same ingredient.', 'ingredient-list-decoder' ),
					/* translators: %s: the resembling entry's INCI name. */
					'near'      => __( 'This closely resembles an existing entry: %s. That is fine if they are genuinely different (like Ceramide NP and Ceramide AP) — saving is allowed.', 'ingredient-list-decoder' ),
					'edit'      => __( 'Edit it', 'ingredient-list-decoder' ),
				),
			)
		);
	}

	/**
	 * The as-you-type check endpoint.
	 *
	 * Returns, for a typed name: an exact key collision (a block), an alias match
	 * (a warning) and the nearest fuzzy match (a warning). Any of the three may be
	 * null. The save itself is enforced server-side by the block filter; this only
	 * warns early.
	 *
	 * @return void
	 */
	public function ajax_check() {
		if ( ! check_ajax_referer( self::CHECK_ACTION, 'nonce', false ) || ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$key     = self::key( $name );

		if ( '' === $key ) {
			wp_send_json_success( array( 'collision' => null, 'alias' => null, 'near' => null ) );
		}

		// Exact collision (a block).
		$collision   = null;
		$collision_id = self::owner_of_key( $key, $post_id );
		if ( $collision_id ) {
			$collision = self::entry_summary( $collision_id );
		}

		// Alias match (a warning). Skipped if it is the same entry as the collision.
		$alias      = null;
		$alias_hit  = self::alias_owner( $key, $post_id );
		if ( $alias_hit && $alias_hit['id'] !== $collision_id ) {
			$alias          = self::entry_summary( $alias_hit['id'] );
			$alias['alias'] = $alias_hit['alias'];
		}

		// Nearest fuzzy match (a warning). Skipped when it just repeats the exact
		// or alias entry above.
		$near     = null;
		$nearest  = ILD_Matcher::nearest( $name, $post_id );
		if ( $nearest ) {
			$near_id = (int) $nearest['post_id'];
			if ( $near_id !== $collision_id && ( ! $alias_hit || $near_id !== $alias_hit['id'] ) ) {
				$near = self::entry_summary( $near_id );
			}
		}

		wp_send_json_success(
			array(
				'collision' => $collision,
				'alias'     => $alias,
				'near'      => $near,
			)
		);
	}

	/**
	 * A small summary of an entry for the check response.
	 *
	 * @param int $post_id The entry.
	 * @return array{id:int,name:string,edit:string}
	 */
	private static function entry_summary( $post_id ) {
		$post_id = (int) $post_id;
		return array(
			'id'   => $post_id,
			'name' => get_the_title( $post_id ),
			'edit' => (string) get_edit_post_link( $post_id, 'raw' ),
		);
	}
}
