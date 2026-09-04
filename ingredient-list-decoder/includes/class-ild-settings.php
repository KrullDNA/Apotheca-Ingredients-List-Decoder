<?php
/**
 * The single settings page and the section-registration API behind it.
 *
 * There is deliberately one settings page for the whole plugin. Every later
 * stage that needs settings adds a *section* to this page rather than a page of
 * its own, by hooking 'ild_register_settings' and calling add_section(). That
 * keeps all of the tool's controls in one predictable place.
 *
 * All values are stored inside one options row (an associative array keyed by
 * field id), read anywhere through the ild_get_setting() helper.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the settings page and exposes the section-registration API.
 */
class ILD_Settings {

	/**
	 * The single wp_options key holding every plugin setting.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'ild_settings';

	/**
	 * The settings page slug (its ?page= value in the admin URL).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ild-settings';

	/**
	 * The registered sections, keyed by section id.
	 *
	 * Filled once, lazily, by collect_sections(). Each section holds a title,
	 * an optional description and an ordered list of field definitions.
	 *
	 * @var array<string,array>|null
	 */
	private $sections = null;

	/**
	 * Hook the settings page and its registration onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * The section-registration API.
	 *
	 * Later stages call this from an 'ild_register_settings' callback to add
	 * their own section. A section looks like:
	 *
	 *     $settings->add_section( array(
	 *         'id'          => 'ild_section_email',
	 *         'title'       => 'Email',
	 *         'description' => 'How result emails are sent.',
	 *         'fields'      => array(
	 *             array(
	 *                 'id'       => 'sender_name',
	 *                 'label'    => 'Sender name',
	 *                 'type'     => 'text',        // text | email | number | textarea | checkbox | select
	 *                 'default'  => 'Apotheca',
	 *                 'description' => 'Shown as the from-name on emails.',
	 *                 'sanitize' => 'sanitize_text_field', // optional; sensible default per type
	 *                 'options'  => array(),        // required for 'select' only
	 *                 'min'      => 0, 'max' => 100, // optional, for 'number'
	 *             ),
	 *         ),
	 *     ) );
	 *
	 * @param array $section The section definition (see above).
	 * @return void
	 */
	public function add_section( $section ) {
		// A section must at least have an id to be addressable.
		if ( empty( $section['id'] ) ) {
			return;
		}

		// Fill in anything the caller left out so the rest of the class can
		// rely on a complete shape.
		$section = wp_parse_args(
			$section,
			array(
				'title'       => '',
				'description' => '',
				'fields'      => array(),
			)
		);

		$this->sections[ $section['id'] ] = $section;
	}

	/**
	 * Gather every section, once.
	 *
	 * Registers the plugin's own general section first, then lets every later
	 * stage add theirs via the 'ild_register_settings' action. Guards against
	 * running twice within a single request.
	 *
	 * @return array<string,array> All registered sections.
	 */
	private function collect_sections() {
		// Already gathered this request? Hand back what we have.
		if ( null !== $this->sections ) {
			return $this->sections;
		}

		$this->sections = array();

		// --- The general section, owned by this stage. --------------------
		$this->add_section(
			array(
				'id'          => 'ild_section_general',
				'title'       => __( 'General', 'ingredient-list-decoder' ),
				'description' => __( 'Core settings for the Ingredient List Decoder.', 'ingredient-list-decoder' ),
				'fields'      => array(
					array(
						'id'          => 'sender_name',
						'label'       => __( 'Email sender name', 'ingredient-list-decoder' ),
						'type'        => 'text',
						'default'     => get_bloginfo( 'name' ),
						'description' => __( 'The name result emails appear to come from.', 'ingredient-list-decoder' ),
						'sanitize'    => 'sanitize_text_field',
					),
					array(
						'id'          => 'sender_address',
						'label'       => __( 'Email sender address', 'ingredient-list-decoder' ),
						'type'        => 'email',
						'default'     => get_bloginfo( 'admin_email' ),
						'description' => __( 'The from-address for result emails.', 'ingredient-list-decoder' ),
						'sanitize'    => 'sanitize_email',
					),
					array(
						'id'          => 'cookie_duration',
						'label'       => __( 'Cookie duration (months)', 'ingredient-list-decoder' ),
						'type'        => 'number',
						'default'     => 12,
						'min'         => 1,
						'max'         => 120,
						'description' => __( 'How long, in months, before someone is asked for their email again on the same device.', 'ingredient-list-decoder' ),
						'sanitize'    => 'absint',
					),
					array(
						'id'          => 'email_form_always',
						'label'       => __( 'Always offer the email form', 'ingredient-list-decoder' ),
						'type'        => 'checkbox',
						'default'     => 1,
						'description' => __( 'On by default. The "email me a copy" form is shown beneath every reading, so a visitor can always send the current reading to their inbox. Turn this off to hide the form once a visitor has given their address on that device (they will not be asked again, but also cannot email a later reading).', 'ingredient-list-decoder' ),
					),
					array(
						'id'          => 'delete_data_on_uninstall',
						'label'       => __( 'Delete all plugin data when the plugin is deleted', 'ingredient-list-decoder' ),
						'type'        => 'checkbox',
						'default'     => 0,
						'description' => __( 'Off by default. When off, deleting the plugin leaves every ingredient, term and setting untouched so nothing is lost by accident.', 'ingredient-list-decoder' ),
					),
				),
			)
		);

		// --- Let every later stage add its own section. -------------------
		// A stage hooks this action and calls $settings->add_section( ... ).
		do_action( 'ild_register_settings', $this );

		return $this->sections;
	}

	/**
	 * Add the Settings screen under the Ingredient Decoder menu.
	 *
	 * It nests under the ingredient post type's own menu, so everything to do
	 * with the tool lives in one place in the admin sidebar.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_submenu_page(
			'edit.php?post_type=' . ILD_Post_Types::POST_TYPE,
			__( 'Ingredient Decoder Settings', 'ingredient-list-decoder' ),
			__( 'Settings', 'ingredient-list-decoder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the option, its sections and its fields with WordPress.
	 *
	 * Uses the core Settings API so saving, nonces and the update notice are all
	 * handled for us. Every field is wired to sanitize_settings() through the
	 * single registered option.
	 *
	 * @return void
	 */
	public function register_settings() {
		// One registered option holds the whole settings array.
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		// Turn every collected section and field into Settings API entries.
		foreach ( $this->collect_sections() as $section ) {
			add_settings_section(
				$section['id'],
				$section['title'],
				function () use ( $section ) {
					// Print the section's description, if it has one.
					if ( ! empty( $section['description'] ) ) {
						echo '<p>' . esc_html( $section['description'] ) . '</p>';
					}
				},
				self::PAGE_SLUG
			);

			foreach ( $section['fields'] as $field ) {
				add_settings_field(
					$field['id'],
					isset( $field['label'] ) ? $field['label'] : '',
					array( $this, 'render_field' ),
					self::PAGE_SLUG,
					$section['id'],
					array_merge( $field, array( 'label_for' => 'ild-setting-' . $field['id'] ) )
				);
			}
		}
	}

	/**
	 * Draw a single settings field.
	 *
	 * Reads the field's current value from the shared option and prints the
	 * control matching its type. Every control's name is namespaced into the
	 * one option array, e.g. ild_settings[sender_name].
	 *
	 * @param array $field The field definition (with 'label_for' added).
	 * @return void
	 */
	public function render_field( $field ) {
		// The saved value, or the field's default if it has never been saved.
		$default = isset( $field['default'] ) ? $field['default'] : '';
		$value   = ild_get_setting( $field['id'], $default );

		$field_id = 'ild-setting-' . $field['id'];
		$name     = self::OPTION_KEY . '[' . $field['id'] . ']';
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';

		switch ( $type ) {
			case 'callback':
				// A field that renders itself (used for the email preview/test UI).
				if ( isset( $field['render'] ) && is_callable( $field['render'] ) ) {
					call_user_func( $field['render'], $field );
				}
				return;

			case 'color':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="ild-color-field" data-default-color="%4$s" />',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( isset( $field['default'] ) ? $field['default'] : '' )
				);
				break;

			case 'media':
				printf(
					'<span class="ild-media-field-wrap"><input type="url" id="%1$s" name="%2$s" value="%3$s" class="ild-media-field regular-text" /> <button type="button" class="button ild-media-button">%4$s</button><span class="ild-media-preview">%5$s</span></span>',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_html__( 'Choose', 'ingredient-list-decoder' ),
					'' !== $value ? '<img src="' . esc_url( (string) $value ) . '" alt="" style="max-width:160px;height:auto;display:block;margin-top:8px;" />' : ''
				);
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%2$s" rows="4" class="large-text">%3$s</textarea>',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_textarea( (string) $value )
				);
				break;

			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $field_id ),
					esc_attr( $name ),
					checked( 1, (int) $value, false ),
					isset( $field['description'] ) ? esc_html( $field['description'] ) : ''
				);
				// The label already carries the description for a checkbox, so
				// return early to avoid printing it twice.
				return;

			case 'select':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $field_id ), esc_attr( $name ) );
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				foreach ( $options as $opt_value => $opt_label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $opt_value ),
						selected( (string) $value, (string) $opt_value, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%2$s" value="%3$s" class="small-text" %4$s %5$s step="%6$s" />',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					isset( $field['min'] ) ? 'min="' . esc_attr( $field['min'] ) . '"' : '',
					isset( $field['max'] ) ? 'max="' . esc_attr( $field['max'] ) . '"' : '',
					isset( $field['step'] ) ? esc_attr( $field['step'] ) : '1'
				);
				break;

			case 'email':
				printf(
					'<input type="email" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;

			case 'text':
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $field_id ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
		}

		// The helper line beneath the control (checkbox handled its own above).
		if ( ! empty( $field['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $field['description'] ) );
		}
	}

	/**
	 * Clean the whole settings array before it is saved.
	 *
	 * Walks every known field across every section, cleaning each submitted
	 * value with its own function (or a sensible default for its type), and
	 * carries forward any already-saved value for a field not in this submission.
	 * Unknown keys are dropped, so nothing unexpected can be written.
	 *
	 * @param mixed $input The raw submitted settings array.
	 * @return array The cleaned settings array to store.
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( self::OPTION_KEY, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$clean    = $existing; // Start from what is already saved.

		foreach ( $this->collect_sections() as $section ) {
			foreach ( $section['fields'] as $field ) {
				$key  = $field['id'];
				$type = isset( $field['type'] ) ? $field['type'] : 'text';

				// A checkbox that is missing from the POST means "unticked", so
				// it must be forced to 0 rather than left at its old value.
				if ( 'checkbox' === $type ) {
					$clean[ $key ] = ( isset( $input[ $key ] ) && $input[ $key ] ) ? 1 : 0;
					continue;
				}

				// Non-checkbox fields not present in this submission keep their
				// current saved value untouched.
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}

				// Clean the value with the field's function, or a default that
				// suits its type.
				$callback = isset( $field['sanitize'] ) ? $field['sanitize'] : $this->default_sanitizer_for( $type );
				$clean[ $key ] = call_user_func( $callback, wp_unslash( $input[ $key ] ) );
			}
		}

		return $clean;
	}

	/**
	 * Pick a sensible default cleaning function for a field type.
	 *
	 * Used when a field definition does not name its own 'sanitize' callback.
	 *
	 * @param string $type The field type.
	 * @return callable The cleaning function to use.
	 */
	private function default_sanitizer_for( $type ) {
		switch ( $type ) {
			case 'email':
				return 'sanitize_email';
			case 'number':
				return 'absint';
			case 'textarea':
				return 'sanitize_textarea_field';
			case 'color':
				return 'sanitize_hex_color';
			case 'media':
				return 'esc_url_raw';
			default:
				return 'sanitize_text_field';
		}
	}

	/**
	 * Draw the settings page itself.
	 *
	 * A standard WordPress settings form: the sections and fields registered
	 * above are printed by do_settings_sections(), and core handles the save.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		// Only administrators may see or change these settings.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				// Nonce, action and option-group hidden fields.
				settings_fields( self::PAGE_SLUG );
				// Print every registered section and its fields.
				do_settings_sections( self::PAGE_SLUG );
				// The Save Changes button.
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
