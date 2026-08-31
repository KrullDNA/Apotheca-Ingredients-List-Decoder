<?php
/**
 * The controlled role vocabulary.
 *
 * A formulation ingredient does one or more jobs in a formula: it might be an
 * emulsifier, a humectant, a preservative and so on. The analysis engine in a
 * later stage reads these roles to work out what a product is built on, so the
 * list of allowed roles has to be fixed and identical everywhere it is used.
 *
 * This class is that single source of truth. Nothing else in the plugin should
 * ever hard-code a role name; it should ask this class. Defining it once means
 * the vocabulary cannot quietly drift apart between the admin screens, the
 * importer and the engine.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds and serves the fixed list of ingredient roles.
 */
class ILD_Roles {

	/**
	 * The complete, ordered list of allowed roles.
	 *
	 * The key is the stored value (a stable machine slug that never changes).
	 * The value is the human label shown in the admin (translatable, so it can
	 * be localised without breaking any saved data).
	 *
	 * @return array<string,string> Role slug => human label.
	 */
	public static function get_roles() {
		// The roles come straight from the brief's controlled vocabulary. Slugs
		// are lower-case and hyphenated so they are safe as stored values.
		return array(
			'active'       => __( 'Active', 'ingredient-list-decoder' ),
			'antioxidant'  => __( 'Antioxidant', 'ingredient-list-decoder' ),
			'humectant'    => __( 'Humectant', 'ingredient-list-decoder' ),
			'emollient'    => __( 'Emollient', 'ingredient-list-decoder' ),
			'occlusive'    => __( 'Occlusive', 'ingredient-list-decoder' ),
			'emulsifier'   => __( 'Emulsifier', 'ingredient-list-decoder' ),
			'thickener'    => __( 'Thickener', 'ingredient-list-decoder' ),
			'solvent'      => __( 'Solvent', 'ingredient-list-decoder' ),
			'surfactant'   => __( 'Surfactant', 'ingredient-list-decoder' ),
			'preservative' => __( 'Preservative', 'ingredient-list-decoder' ),
			'chelator'     => __( 'Chelator', 'ingredient-list-decoder' ),
			'ph-adjuster'  => __( 'pH adjuster', 'ingredient-list-decoder' ),
			'film-former'  => __( 'Film former', 'ingredient-list-decoder' ),
			'fragrance'    => __( 'Fragrance', 'ingredient-list-decoder' ),
			'colourant'    => __( 'Colourant', 'ingredient-list-decoder' ),
			'uv-filter'    => __( 'UV filter', 'ingredient-list-decoder' ),
		);
	}

	/**
	 * Just the role slugs, in order.
	 *
	 * Handy when validating saved input: a submitted role is only kept if it
	 * appears in this list.
	 *
	 * @return string[] The list of valid role slugs.
	 */
	public static function get_role_slugs() {
		return array_keys( self::get_roles() );
	}

	/**
	 * Check whether a given slug is a real, allowed role.
	 *
	 * @param string $slug The slug to test.
	 * @return bool True if it is a recognised role.
	 */
	public static function is_valid_role( $slug ) {
		return in_array( $slug, self::get_role_slugs(), true );
	}

	/**
	 * Get the human label for a single role slug.
	 *
	 * @param string $slug The role slug.
	 * @return string The label, or the slug itself if it is not recognised.
	 */
	public static function get_label( $slug ) {
		$roles = self::get_roles();

		return isset( $roles[ $slug ] ) ? $roles[ $slug ] : $slug;
	}
}
