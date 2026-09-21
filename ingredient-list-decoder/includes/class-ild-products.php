<?php
/**
 * Product ingredient lists: reading a product's back-of-bottle list and linking it.
 *
 * A product (a WooCommerce product, by default) carries its ingredient list in a
 * meta field — Apotheca enters it into a JetEngine field named "ingredients". This
 * class only ever reads that field. It never writes to it, so the field stays owned
 * by JetEngine and can be used elsewhere on the front end too.
 *
 * Given a product, it:
 *   - reads and cleans the field (tolerating a plain list, one-per-line, or WYSIWYG
 *     HTML),
 *   - parses it into tokens with the same parser the decoder uses,
 *   - links each token to a library entry by a direct match (INCI name, alias, or a
 *     bracket/slash variant) — never a fuzzy guess, so a curated list is never
 *     silently swapped for a different ingredient,
 *   - arranges the linked ingredients in the order the widget asks for (bottle
 *     order, alphabetical, grouped by family, or grouped by role).
 *
 * It also flags, on save, any ingredient a product lists that the library does not
 * hold yet, so those names reach the Unknown ingredients queue to be added.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and links a product's ingredient list.
 */
class ILD_Products {

	/**
	 * The stylesheet handle for the Product Ingredients widget.
	 *
	 * @var string
	 */
	const STYLE = 'ild-product';

	/**
	 * The script handle for the Product Ingredients widget (accordion animation).
	 *
	 * @var string
	 */
	const SCRIPT = 'ild-product';

	/**
	 * The default meta key the product's ingredient list is read from.
	 *
	 * Apotheca's JetEngine field is named "ingredients", which JetEngine stores as
	 * post meta under this key. It is overridable per widget and via a filter, so a
	 * prefixed or renamed key never needs a code change.
	 *
	 * @var string
	 */
	const META_KEY_DEFAULT = 'ingredients';

	/**
	 * Hook the stylesheet registration and the save-time missing-ingredient flag.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'elementor/preview/enqueue_styles', array( $this, 'register_assets' ) );

		// After a product is saved (priority 99, so JetEngine has stored its field
		// first), flag any ingredient the library still lacks.
		add_action( 'save_post_product', array( $this, 'flag_missing_on_save' ), 99, 1 );
	}

	/**
	 * Register (but do not force-load) the widget stylesheet.
	 *
	 * The widget declares this handle as a style dependency, so Elementor enqueues
	 * it only on pages where the widget is placed.
	 *
	 * @return void
	 */
	public function register_assets() {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE,
				ILD_PLUGIN_URL . 'assets/css/product.css',
				array(),
				ILD_VERSION
			);
		}

		if ( ! wp_script_is( self::SCRIPT, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT,
				ILD_PLUGIN_URL . 'assets/js/product.js',
				array(),
				ILD_VERSION,
				true
			);
		}
	}

	/**
	 * The meta key to read a product's ingredient list from.
	 *
	 * @param string $override An explicit key from a widget, or '' for the default.
	 * @return string
	 */
	public static function meta_key( $override = '' ) {
		$override = sanitize_key( (string) $override );
		$key      = ( '' !== $override ) ? $override : self::META_KEY_DEFAULT;

		return (string) apply_filters( 'ild_product_ingredients_meta_key', $key );
	}

	/**
	 * Read and clean a product's ingredient-list field into plain text.
	 *
	 * Tolerates the three shapes the field may take: a plain comma-separated list,
	 * one ingredient per line, or WYSIWYG HTML. Block and line-break tags become
	 * line breaks, any remaining markup is stripped, and HTML entities are decoded,
	 * so the parser always sees clean text.
	 *
	 * @param int    $product_id The product's post ID.
	 * @param string $meta_key   The meta key, or '' for the default.
	 * @return string The cleaned list text, or ''.
	 */
	public static function raw( $product_id, $meta_key = '' ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return '';
		}

		$value = get_post_meta( $product_id, self::meta_key( $meta_key ), true );

		// A field stored as an array (some field types) is joined into a list.
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'strval', $value ) );
		}
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return '';
		}

		// Turn block and line-break tags into line breaks so a WYSIWYG list keeps
		// its separators, then strip any remaining markup and decode entities.
		$value = preg_replace( '#<\s*(br|/p|/div|/li|/h[1-6])\s*/?\s*>#i', "\n", $value );
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		return trim( $value );
	}

	/**
	 * Build one row per ingredient a product lists, in bottle order.
	 *
	 * Each row carries its match status ('matched' or 'missing'), and a matched row
	 * carries the full library view (name, roles, family, description, evidence,
	 * founder, also-known-as) plus the primary family and role used for grouping.
	 *
	 * @param int        $product_id The product's post ID.
	 * @param string     $meta_key   The meta key, or '' for the default.
	 * @param array|null $index      A prebuilt library index, or null to build one.
	 *                               Passing one lets "all products" reuse a single
	 *                               index instead of rebuilding it per product.
	 * @return array<int,array> The ordered rows.
	 */
	public static function rows( $product_id, $meta_key = '', $index = null ) {
		$raw = self::raw( $product_id, $meta_key );
		if ( '' === $raw ) {
			return array();
		}

		$parsed = ILD_Parser::parse( $raw );
		if ( is_wp_error( $parsed ) || empty( $parsed['items'] ) ) {
			return array();
		}

		if ( null === $index ) {
			$index = ILD_Matcher::build_index();
		}
		$rows     = array();
		$position = 0;

		foreach ( $parsed['items'] as $token ) {
			$position++;
			$norm     = isset( $token['normalised'] ) ? $token['normalised'] : '';
			$original = isset( $token['original'] ) ? $token['original'] : '';
			$id       = ILD_Matcher::resolve_id( $norm, $index );

			if ( $id > 0 ) {
				$view = ILD_Presenter::ingredient_view( $id );
				$name = ( '' !== $view['name'] ) ? $view['name'] : $original;

				$rows[] = array(
					'status'       => 'matched',
					'position'     => $position,
					'post_id'      => $id,
					'name'         => $name,
					'aka_text'     => $view['aka_text'],
					'roles_text'   => $view['roles_text'],
					'role_labels'  => $view['role_labels'],
					'family_text'  => $view['family_text'],
					'family_names' => $view['family_names'],
					'description'  => $view['description'],
					'evidence'     => $view['evidence'],
					'founder'      => $view['founder'],
					// The primary family and role decide which group the row falls in.
					'sort_family'  => ! empty( $view['family_names'] ) ? $view['family_names'][0] : '',
					'sort_role'    => ! empty( $view['role_labels'] ) ? $view['role_labels'][0] : '',
				);
			} else {
				$rows[] = array(
					'status'      => 'missing',
					'position'    => $position,
					'post_id'     => 0,
					'name'        => $original,
					'sort_family' => '',
					'sort_role'   => '',
				);
			}
		}

		return $rows;
	}

	/**
	 * Build the arranged view model for the Product Ingredients widget.
	 *
	 * @param int   $product_id The product's post ID.
	 * @param array $args       { meta_key, order, show_missing }.
	 * @return array {
	 *     product_id, product_name, order, grouped (bool),
	 *     groups[] (each { key, heading, rows[] }),
	 *     missing[] (names not in the library), total (visible count)
	 * }
	 */
	public static function build( $product_id, $args = array(), $index = null ) {
		$args = wp_parse_args(
			$args,
			array(
				'meta_key'     => self::META_KEY_DEFAULT,
				'order'        => 'inci',
				'show_missing' => true,
			)
		);

		$product_id = (int) $product_id;
		$rows       = self::rows( $product_id, $args['meta_key'], $index );

		return self::assemble( $rows, $args, $product_id );
	}

	/**
	 * Build one combined view of every ingredient used across all products.
	 *
	 * A master directory: each library ingredient any product uses appears once,
	 * de-duplicated, with no product names — "what we use and what it does". Missing
	 * names are de-duplicated too. The chosen order and grouping apply to the pooled
	 * set. Built from a single library index, whatever the size of the catalogue.
	 *
	 * @param array      $args  { meta_key, order, show_missing }.
	 * @param array|null $index A prebuilt library index, or null to build one.
	 * @return array The view model, with product_id 0 and no product name.
	 */
	public static function build_combined( $args = array(), $index = null ) {
		$args = wp_parse_args(
			$args,
			array(
				'meta_key'     => self::META_KEY_DEFAULT,
				'order'        => 'inci',
				'show_missing' => true,
			)
		);

		if ( null === $index ) {
			$index = ILD_Matcher::build_index();
		}

		$seen_ids     = array();
		$seen_missing = array();
		$rows         = array();
		$position     = 0;

		foreach ( self::product_ids() as $product_id ) {
			foreach ( self::rows( $product_id, $args['meta_key'], $index ) as $row ) {
				if ( 'matched' === $row['status'] ) {
					$id = (int) $row['post_id'];
					if ( isset( $seen_ids[ $id ] ) ) {
						continue;
					}
					$seen_ids[ $id ] = true;
				} else {
					$key = strtolower( trim( (string) $row['name'] ) );
					if ( '' === $key || isset( $seen_missing[ $key ] ) ) {
						continue;
					}
					$seen_missing[ $key ] = true;
				}

				$position++;
				$row['position'] = $position;
				$rows[]          = $row;
			}
		}

		return self::assemble( $rows, $args, 0 );
	}

	/**
	 * Assemble a view model from a set of rows: split missing, order and group.
	 *
	 * @param array $rows       The rows (from rows() or the combined builder).
	 * @param array $args       { order, show_missing }.
	 * @param int   $product_id The product this view is for, or 0 for a combined view.
	 * @return array The view model.
	 */
	private static function assemble( $rows, $args, $product_id = 0 ) {
		$missing = array();
		$visible = array();
		foreach ( $rows as $row ) {
			if ( 'missing' === $row['status'] ) {
				$missing[] = $row['name'];
				if ( empty( $args['show_missing'] ) ) {
					continue;
				}
			}
			$visible[] = $row;
		}

		$order = in_array( $args['order'], array( 'inci', 'alpha', 'family', 'role' ), true ) ? $args['order'] : 'inci';

		return array(
			'product_id'   => (int) $product_id,
			'product_name' => $product_id > 0 ? get_the_title( $product_id ) : '',
			'order'        => $order,
			'grouped'      => in_array( $order, array( 'family', 'role' ), true ),
			'groups'       => self::arrange( $visible, $order ),
			'missing'      => $missing,
			'total'        => count( $visible ),
		);
	}

	/**
	 * The names a product lists that the library does not hold yet.
	 *
	 * @param int    $product_id The product's post ID.
	 * @param string $meta_key   The meta key, or '' for the default.
	 * @return string[] The missing ingredient names, in bottle order.
	 */
	public static function missing_names( $product_id, $meta_key = '' ) {
		$missing = array();
		foreach ( self::rows( $product_id, $meta_key ) as $row ) {
			if ( 'missing' === $row['status'] && '' !== trim( (string) $row['name'] ) ) {
				$missing[] = $row['name'];
			}
		}

		return $missing;
	}

	/**
	 * Arrange rows into one or more display groups by the chosen order.
	 *
	 * Flat orders (bottle order, alphabetical) return a single group with no
	 * heading; grouped orders (family, role) return a group per family or role, each
	 * with a heading, ordered alphabetically with an "Other" catch-all last.
	 *
	 * @param array  $rows  The rows to arrange.
	 * @param string $order inci|alpha|family|role.
	 * @return array<int,array> Groups, each { key, heading, rows[] }.
	 */
	private static function arrange( $rows, $order ) {
		if ( empty( $rows ) ) {
			return array();
		}

		switch ( $order ) {
			case 'alpha':
				usort(
					$rows,
					static function ( $a, $b ) {
						return strcasecmp( (string) $a['name'], (string) $b['name'] );
					}
				);
				return array( array( 'key' => '', 'heading' => '', 'rows' => $rows ) );

			case 'family':
				return self::group_by( $rows, 'sort_family' );

			case 'role':
				return self::group_by( $rows, 'sort_role' );

			case 'inci':
			default:
				usort(
					$rows,
					static function ( $a, $b ) {
						return $a['position'] - $b['position'];
					}
				);
				return array( array( 'key' => '', 'heading' => '', 'rows' => $rows ) );
		}
	}

	/**
	 * Group rows under a heading taken from one of their sort fields.
	 *
	 * A row with no value for that field falls under an "Other" group, which always
	 * sorts last. Within every group the bottle order is kept.
	 *
	 * @param array  $rows  The rows to group.
	 * @param string $field The row key to group on ('sort_family' or 'sort_role').
	 * @return array<int,array> Groups, each { key, heading, rows[] }.
	 */
	private static function group_by( $rows, $field ) {
		$other  = __( 'Other', 'ingredient-list-decoder' );
		$groups = array();

		foreach ( $rows as $row ) {
			$heading = ( isset( $row[ $field ] ) && '' !== $row[ $field ] ) ? $row[ $field ] : $other;
			$key     = strtolower( $heading );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array( 'key' => $key, 'heading' => $heading, 'rows' => array() );
			}
			$groups[ $key ]['rows'][] = $row;
		}

		// Alphabetical by heading, but keep the "Other" catch-all last.
		uasort(
			$groups,
			static function ( $a, $b ) use ( $other ) {
				if ( $a['heading'] === $other && $b['heading'] !== $other ) {
					return 1;
				}
				if ( $b['heading'] === $other && $a['heading'] !== $other ) {
					return -1;
				}
				return strcasecmp( (string) $a['heading'], (string) $b['heading'] );
			}
		);

		// Keep bottle order inside each group.
		foreach ( $groups as &$group ) {
			usort(
				$group['rows'],
				static function ( $a, $b ) {
					return $a['position'] - $b['position'];
				}
			);
		}
		unset( $group );

		return array_values( $groups );
	}

	/**
	 * Every WooCommerce product, for the widget's picker: id => title.
	 *
	 * Returns the whole catalogue, in title order, across every editable status
	 * (published, draft, pending, private, scheduled), so the picker lists all
	 * products — not just published ones and with no arbitrary cap. Uses
	 * WooCommerce's own product query when available, falling back to a direct post
	 * query so it still works if the WooCommerce helpers are not loaded yet.
	 *
	 * @return array<int,string>
	 */
	public static function product_options() {
		$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
		$options  = array();

		// Prefer WooCommerce's own query, which knows every product type.
		if ( function_exists( 'wc_get_products' ) ) {
			$ids = wc_get_products(
				array(
					'status'  => $statuses,
					'limit'   => -1,
					'orderby' => 'title',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);

			foreach ( (array) $ids as $id ) {
				$options[ (int) $id ] = get_the_title( (int) $id );
			}
		}

		// Fall back to a direct query if WooCommerce helpers are unavailable, or if
		// the WooCommerce query returned nothing.
		if ( empty( $options ) ) {
			$posts = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => $statuses,
					'numberposts'      => -1,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			);

			foreach ( (array) $posts as $post ) {
				$options[ (int) $post->ID ] = $post->post_title;
			}
		}

		return $options;
	}

	/**
	 * Every WooCommerce product's ID, in title order.
	 *
	 * Used by the widget's "All products" mode to render each product in turn.
	 *
	 * @return int[]
	 */
	public static function product_ids() {
		return array_map( 'intval', array_keys( self::product_options() ) );
	}

	/**
	 * On saving a product, flag any ingredient the library still lacks.
	 *
	 * Runs late so JetEngine has already stored the field. Each missing name is
	 * recorded to the Unknown ingredients queue once, without inflating its count on
	 * repeated saves, so product gaps show up alongside decoder submissions to be
	 * added to the library.
	 *
	 * @param int $post_id The product's post ID.
	 * @return void
	 */
	public function flag_missing_on_save( $post_id ) {
		$post_id = (int) $post_id;

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( self::missing_names( $post_id ) as $name ) {
			ILD_Unknown_Tokens::record_once( $name );
		}
	}
}
