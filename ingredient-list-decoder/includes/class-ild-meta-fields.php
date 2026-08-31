<?php
/**
 * The ingredient meta fields: the structured data behind each entry that makes
 * the analysis engine possible.
 *
 * These are the fields from the brief that are not the title (INCI name lives
 * there), not the status (that is the post status) and not the two taxonomies
 * (Family and Topic have their own boxes). Everything else — aliases, roles,
 * use range, the sub-one marker, the descriptive fields — is defined here once
 * and used both to register the meta and to draw the edit box.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers, renders and saves the ingredient meta fields.
 */
class ILD_Meta_Fields {

	/**
	 * The nonce action name used to protect the save.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'ild_save_ingredient_meta';

	/**
	 * The nonce field name posted with the form.
	 *
	 * @var string
	 */
	const NONCE_NAME = 'ild_ingredient_meta_nonce';

	/**
	 * Hook the meta registration, the edit box and the save handler onto
	 * WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . ILD_Post_Types::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
	}

	/**
	 * The full definition of every meta field.
	 *
	 * This is the single source of truth for the fields: their storage key, how
	 * they are shown, and how they are cleaned before saving. Registering the
	 * meta and drawing the box both read from here, so the two can never drift.
	 *
	 * The meta keys carry a leading underscore so WordPress hides them from the
	 * generic Custom Fields box (we provide our own tidy box instead).
	 *
	 * @return array<string,array> Meta key => field definition.
	 */
	public static function get_fields() {
		return array(
			'_ild_also_known_as' => array(
				'label'       => __( 'Also known as', 'ingredient-list-decoder' ),
				'type'        => 'textarea',
				'description' => __( 'Aliases, alternate spellings, common misspellings and trade names. One per line. Drives matching.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_textarea' ),
			),
			'_ild_role'          => array(
				'label'       => __( 'Role', 'ingredient-list-decoder' ),
				'type'        => 'roles', // Multi-select of the controlled vocabulary.
				'description' => __( 'What this ingredient does in a formula. Choose one or more.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_roles' ),
			),
			'_ild_use_low'       => array(
				'label'       => __( 'Typical use range, low (%)', 'ingredient-list-decoder' ),
				'type'        => 'percent',
				'description' => __( 'The bottom of the usual use level, as a percentage. The engine reads this.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_percent' ),
			),
			'_ild_use_high'      => array(
				'label'       => __( 'Typical use range, high (%)', 'ingredient-list-decoder' ),
				'type'        => 'percent',
				'description' => __( 'The top of the usual use level, as a percentage.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_percent' ),
			),
			'_ild_sub_one_marker' => array(
				'label'       => __( 'Almost always used below 1%', 'ingredient-list-decoder' ),
				'type'        => 'checkbox',
				'description' => __( 'Tick for ingredients that sit below the one per cent line. These are how the engine locates that line.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_checkbox' ),
			),
			'_ild_marker_confidence' => array(
				'label'       => __( 'Marker confidence', 'ingredient-list-decoder' ),
				'type'        => 'select',
				'options'     => self::marker_confidence_options(),
				'description' => __( 'How reliably this ingredient marks the one per cent line. Only used when "almost always used below 1%" is ticked; left blank otherwise.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_marker_confidence' ),
			),
			'_ild_description'   => array(
				'label'       => __( 'Description', 'ingredient-list-decoder' ),
				'type'        => 'textarea',
				'description' => __( 'Two or three sentences in plain English. What it does in a formula.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_textarea' ),
			),
			'_ild_evidence_note' => array(
				'label'       => __( 'Evidence note', 'ingredient-list-decoder' ),
				'type'        => 'textarea',
				'description' => __( 'What the evidence actually supports, and where it does not. Optional.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_textarea' ),
			),
			'_ild_founder_take'  => array(
				'label'       => __( 'Founder take', 'ingredient-list-decoder' ),
				'type'        => 'textarea',
				'description' => __( 'Founder voice, only where there is a real view. Optional.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_textarea' ),
			),
			'_ild_category'      => array(
				'label'       => __( 'Category', 'ingredient-list-decoder' ),
				'type'        => 'select',
				'options'     => self::category_options(),
				'description' => __( 'Which product world this ingredient belongs to. For filtering the library only — the engine does not read it.', 'ingredient-list-decoder' ),
				'sanitize'    => array( __CLASS__, 'sanitize_category' ),
			),
		);
	}

	/**
	 * The allowed marker-confidence values: an empty "not set", plus strong and
	 * moderate. Kept in one place so the field, its cleaning and the list column
	 * all agree.
	 *
	 * @return array<string,string> Stored value => label.
	 */
	public static function marker_confidence_options() {
		return array(
			''         => __( '— Not set', 'ingredient-list-decoder' ),
			'strong'   => __( 'Strong', 'ingredient-list-decoder' ),
			'moderate' => __( 'Moderate', 'ingredient-list-decoder' ),
		);
	}

	/**
	 * The allowed category values: an empty "not set", plus skincare, colour and
	 * both. For filtering only.
	 *
	 * @return array<string,string> Stored value => label.
	 */
	public static function category_options() {
		return array(
			''         => __( '— Not set', 'ingredient-list-decoder' ),
			'skincare' => __( 'Skincare', 'ingredient-list-decoder' ),
			'colour'   => __( 'Colour', 'ingredient-list-decoder' ),
			'both'     => __( 'Both', 'ingredient-list-decoder' ),
		);
	}

	/**
	 * Register every meta field with WordPress.
	 *
	 * Registering (rather than just saving raw post meta) gives each field a
	 * type, a cleaning function and proper edit-permission checks. Kept out of
	 * the REST API for now, in line with the post type.
	 *
	 * @return void
	 */
	public function register_meta() {
		foreach ( self::get_fields() as $meta_key => $field ) {
			register_post_meta(
				ILD_Post_Types::POST_TYPE,
				$meta_key,
				array(
					'type'              => ( 'roles' === $field['type'] ) ? 'array' : 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => $field['sanitize'],
					// Only users who can edit the ingredient may change its meta.
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Add the "Ingredient details" edit box to the ingredient screen.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'ild_ingredient_details',
			__( 'Ingredient details', 'ingredient-list-decoder' ),
			array( $this, 'render_meta_box' ),
			ILD_Post_Types::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Draw the edit box.
	 *
	 * Loops the field definitions and prints the right control for each type.
	 * A nonce is included so the save handler can trust the submission.
	 *
	 * @param WP_Post $post The ingredient being edited.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		// Security token: proves the save request came from this box.
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<div class="ild-meta-fields">';

		foreach ( self::get_fields() as $meta_key => $field ) {
			// The value currently saved for this field on this entry.
			$value    = get_post_meta( $post->ID, $meta_key, true );
			$field_id = 'ild-field-' . ltrim( $meta_key, '_' );

			echo '<p>';
			printf(
				'<label for="%1$s"><strong>%2$s</strong></label><br />',
				esc_attr( $field_id ),
				esc_html( $field['label'] )
			);

			// Draw the control appropriate to this field's type.
			switch ( $field['type'] ) {
				case 'textarea':
					printf(
						'<textarea id="%1$s" name="%2$s" rows="3" style="width:100%%;">%3$s</textarea>',
						esc_attr( $field_id ),
						esc_attr( $meta_key ),
						esc_textarea( is_string( $value ) ? $value : '' )
					);
					break;

				case 'percent':
					printf(
						'<input type="number" step="0.01" min="0" max="100" id="%1$s" name="%2$s" value="%3$s" style="width:120px;" />',
						esc_attr( $field_id ),
						esc_attr( $meta_key ),
						esc_attr( '' === $value ? '' : $value )
					);
					break;

				case 'checkbox':
					printf(
						'<input type="checkbox" id="%1$s" name="%2$s" value="yes" %3$s />',
						esc_attr( $field_id ),
						esc_attr( $meta_key ),
						checked( 'yes', $value, false )
					);
					break;

				case 'roles':
					$this->render_roles_control( $meta_key, $value );
					break;

				case 'select':
					$options = isset( $field['options'] ) ? $field['options'] : array();
					printf( '<select id="%1$s" name="%2$s">', esc_attr( $field_id ), esc_attr( $meta_key ) );
					foreach ( $options as $option_value => $option_label ) {
						printf(
							'<option value="%1$s" %2$s>%3$s</option>',
							esc_attr( $option_value ),
							selected( (string) $value, (string) $option_value, false ),
							esc_html( $option_label )
						);
					}
					echo '</select>';
					break;
			}

			// The plain-English helper line under each control.
			if ( ! empty( $field['description'] ) ) {
				printf( '<br /><span class="description">%s</span>', esc_html( $field['description'] ) );
			}

			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Draw the role multi-select as a group of checkboxes.
	 *
	 * Checkboxes are used rather than a native multi-select list because they
	 * are easier to read and fully keyboard-accessible for a fixed, short
	 * vocabulary. The options come straight from ILD_Roles, the single source
	 * of truth, so they can never disagree with the rest of the plugin.
	 *
	 * @param string $meta_key The meta key being drawn.
	 * @param mixed  $value    The saved value (an array of role slugs, or empty).
	 * @return void
	 */
	private function render_roles_control( $meta_key, $value ) {
		// Make sure we have an array to compare against, whatever was stored.
		$selected = is_array( $value ) ? $value : array();

		echo '<span class="ild-roles" style="display:block; margin-top:4px;">';

		foreach ( ILD_Roles::get_roles() as $slug => $label ) {
			printf(
				'<label style="display:inline-block; margin:0 16px 4px 0;"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $meta_key ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $label )
			);
		}

		echo '</span>';
	}

	/**
	 * Save the meta box when the ingredient is saved.
	 *
	 * Runs the full set of safety checks first (nonce, autosave, permissions),
	 * then cleans and stores each field. A field left blank is deleted rather
	 * than stored empty, keeping the database tidy.
	 *
	 * @param int     $post_id The ingredient being saved.
	 * @param WP_Post $post    The post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		// 1. Check the nonce: did this submission really come from our box?
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		// 2. Do not save during an autosave; the form fields are not present.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// 3. Only proceed for our own post type.
		if ( ILD_Post_Types::POST_TYPE !== $post->post_type ) {
			return;
		}

		// 4. The current user must be allowed to edit this entry.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Passed every check. Clean and store each field in turn.
		foreach ( self::get_fields() as $meta_key => $field ) {
			// A missing key (for example an unticked checkbox) means "clear it".
			if ( ! isset( $_POST[ $meta_key ] ) ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			// Clean the raw submitted value with this field's own function.
			// wp_unslash undoes the slashes WordPress adds to all POST data.
			$raw   = wp_unslash( $_POST[ $meta_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on the next line by the field's own callback.
			$clean = call_user_func( $field['sanitize'], $raw );

			// An empty result is stored as "no value" rather than an empty row.
			if ( '' === $clean || array() === $clean ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $clean );
			}
		}

		// Marker confidence only applies to sub-1% markers. If this entry is not
		// marked as sitting below 1%, drop any confidence value so the two can
		// never disagree.
		if ( 'yes' !== get_post_meta( $post_id, '_ild_sub_one_marker', true ) ) {
			delete_post_meta( $post_id, '_ild_marker_confidence' );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Cleaning functions
	 *
	 * One per field type. Each takes the raw submitted value and returns a safe,
	 * predictable value fit to store. They are static so register_post_meta can
	 * use them as sanitize callbacks too.
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Clean a multi-line text value, keeping line breaks.
	 *
	 * @param mixed $value The raw value.
	 * @return string The cleaned text.
	 */
	public static function sanitize_textarea( $value ) {
		return sanitize_textarea_field( is_string( $value ) ? $value : '' );
	}

	/**
	 * Clean a percentage value, clamped to the sensible 0–100 range.
	 *
	 * An empty box is kept as an empty string ("not set") rather than forced to
	 * zero, because zero and "unknown" mean different things for a use level.
	 *
	 * @param mixed $value The raw value.
	 * @return string The cleaned number, or an empty string when blank.
	 */
	public static function sanitize_percent( $value ) {
		// A blank field means the use level is simply not recorded.
		if ( '' === $value || null === $value ) {
			return '';
		}

		// Force it to a number, then hold it inside 0–100.
		$number = (float) $value;
		$number = max( 0, min( 100, $number ) );

		// Return as a string so "0" is stored and read back reliably.
		return (string) $number;
	}

	/**
	 * Clean a yes/no checkbox down to the string 'yes' or an empty string.
	 *
	 * @param mixed $value The raw value.
	 * @return string 'yes' when ticked, otherwise ''.
	 */
	public static function sanitize_checkbox( $value ) {
		return ( 'yes' === $value ) ? 'yes' : '';
	}

	/**
	 * Clean the marker-confidence select to one of its allowed values, or ''.
	 *
	 * @param mixed $value The raw value.
	 * @return string 'strong', 'moderate', or '' (not set).
	 */
	public static function sanitize_marker_confidence( $value ) {
		$value = is_string( $value ) ? $value : '';
		return array_key_exists( $value, self::marker_confidence_options() ) ? $value : '';
	}

	/**
	 * Clean the category select to one of its allowed values, or ''.
	 *
	 * @param mixed $value The raw value.
	 * @return string 'skincare', 'colour', 'both', or '' (not set).
	 */
	public static function sanitize_category( $value ) {
		$value = is_string( $value ) ? $value : '';
		return array_key_exists( $value, self::category_options() ) ? $value : '';
	}

	/**
	 * Clean the submitted roles down to a list of recognised role slugs.
	 *
	 * Anything that is not a real role in the controlled vocabulary is dropped,
	 * so a tampered form can never store a made-up role.
	 *
	 * @param mixed $value The raw value (expected to be an array of slugs).
	 * @return string[] The kept, valid role slugs.
	 */
	public static function sanitize_roles( $value ) {
		// If nothing was submitted as an array, there are no roles to keep.
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $slug ) {
			$slug = sanitize_key( $slug );

			// Keep it only if it is a genuine role, and avoid duplicates.
			if ( ILD_Roles::is_valid_role( $slug ) && ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}

		return $clean;
	}
}
