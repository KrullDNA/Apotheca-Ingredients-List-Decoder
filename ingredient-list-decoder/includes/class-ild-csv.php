<?php
/**
 * The CSV importer and exporter for the ingredient library.
 *
 * Two jobs, one screen under the Ingredient Decoder menu:
 *
 *  - Import a UTF-8 CSV whose header row matches the field names from the brief.
 *    The upload is never written straight into the library: a mapping screen is
 *    shown first, so the person can confirm which CSV column feeds which field
 *    before anything is created. On import, a new INCI name creates a new
 *    ingredient and an existing one updates it in place, never a duplicate.
 *    Everything comes in as "needs review", never published.
 *
 *  - Export the whole library to a CSV using the same column names, so a file
 *    exported here can be edited and imported straight back (a round trip).
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles CSV import and export for ingredients.
 */
class ILD_CSV {

	/**
	 * The settings/tools page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'ild-csv';

	/**
	 * The largest CSV upload accepted, in bytes (2 MB). The library is a few
	 * hundred short rows, so anything larger is almost certainly a mistake.
	 *
	 * @var int
	 */
	const MAX_UPLOAD_BYTES = 2097152;

	/**
	 * How long the held upload survives between the upload and import steps.
	 *
	 * @var int
	 */
	const TRANSIENT_TTL = HOUR_IN_SECONDS;

	/**
	 * Hook the import/export page and the export handler onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		// The export streams a file, so it runs on admin-post (before any HTML).
		add_action( 'admin_post_ild_export_ingredients', array( $this, 'do_export' ) );
	}

	/**
	 * The canonical CSV columns, in order.
	 *
	 * The array key is the CSV header name (matching the brief's field names).
	 * Each definition says how that column maps into an ingredient: into the
	 * title, a meta field, the roles, a taxonomy, or the post status. This is the
	 * single source of truth used by auto-mapping, import and export alike.
	 *
	 * @return array<string,array> Column name => definition.
	 */
	public static function get_columns() {
		return array(
			'inci_name'         => array(
				'label' => __( 'INCI name', 'ingredient-list-decoder' ),
				'kind'  => 'title',
			),
			'also_known_as'     => array(
				'label'    => __( 'Also known as', 'ingredient-list-decoder' ),
				'kind'     => 'meta',
				'meta_key' => '_ild_also_known_as',
			),
			'role'              => array(
				'label'    => __( 'Role', 'ingredient-list-decoder' ),
				'kind'     => 'roles',
				'meta_key' => '_ild_role',
			),
			'use_low'           => array(
				'label'    => __( 'Use range, low (%)', 'ingredient-list-decoder' ),
				'kind'     => 'percent',
				'meta_key' => '_ild_use_low',
			),
			'use_high'          => array(
				'label'    => __( 'Use range, high (%)', 'ingredient-list-decoder' ),
				'kind'     => 'percent',
				'meta_key' => '_ild_use_high',
			),
			'sub_one_marker'    => array(
				'label'    => __( 'Below 1% marker', 'ingredient-list-decoder' ),
				'kind'     => 'bool',
				'meta_key' => '_ild_sub_one_marker',
			),
			'ingredient_family' => array(
				'label'    => __( 'Ingredient family', 'ingredient-list-decoder' ),
				'kind'     => 'tax',
				'taxonomy' => ILD_Post_Types::TAX_FAMILY,
			),
			'skin_topic'        => array(
				'label'    => __( 'Skin topic', 'ingredient-list-decoder' ),
				'kind'     => 'tax',
				'taxonomy' => ILD_Post_Types::TAX_TOPIC,
			),
			'description'       => array(
				'label'    => __( 'Description', 'ingredient-list-decoder' ),
				'kind'     => 'meta',
				'meta_key' => '_ild_description',
			),
			'evidence_note'     => array(
				'label'    => __( 'Evidence note', 'ingredient-list-decoder' ),
				'kind'     => 'meta',
				'meta_key' => '_ild_evidence_note',
			),
			'founder_take'      => array(
				'label'    => __( 'Founder take', 'ingredient-list-decoder' ),
				'kind'     => 'meta',
				'meta_key' => '_ild_founder_take',
			),
			'status'            => array(
				'label' => __( 'Status', 'ingredient-list-decoder' ),
				'kind'  => 'status',
			),
		);
	}

	/**
	 * Add the Import / Export screen under the Ingredient Decoder menu.
	 *
	 * @return void
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . ILD_Post_Types::POST_TYPE,
			__( 'Import / Export Ingredients', 'ingredient-list-decoder' ),
			__( 'Import / Export', 'ingredient-list-decoder' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the Import / Export screen and route between its steps.
	 *
	 * Which step to show is decided by the hidden 'ild_csv_step' field, so the
	 * page moves upload -> mapping -> summary within the one screen.
	 *
	 * @return void
	 */
	public function render_page() {
		// Only administrators may import or export.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ingredient-list-decoder' ) );
		}

		// Which step are we on? Sanitised to a known key.
		$step = isset( $_POST['ild_csv_step'] ) ? sanitize_key( wp_unslash( $_POST['ild_csv_step'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Import / Export Ingredients', 'ingredient-list-decoder' ) . '</h1>';

		if ( 'map' === $step ) {
			// Coming from the upload form: check the nonce, read the file, and
			// show the mapping screen (or an error back on the upload screen).
			check_admin_referer( 'ild_csv_upload', 'ild_csv_nonce' );
			$prepared = $this->handle_upload();

			if ( is_wp_error( $prepared ) ) {
				$this->render_upload( $prepared->get_error_message() );
			} else {
				$this->render_mapping( $prepared );
			}
		} elseif ( 'import' === $step ) {
			// Coming from the mapping form: check the nonce and run the import.
			check_admin_referer( 'ild_csv_import', 'ild_csv_nonce' );
			$summary = $this->handle_import();

			if ( is_wp_error( $summary ) ) {
				$this->render_upload( $summary->get_error_message() );
			} else {
				$this->render_summary( $summary );
			}
		} else {
			// First visit: show the upload form and the export button.
			$this->render_upload();
		}

		echo '</div>';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Step 1: upload
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Draw the upload form and the export button.
	 *
	 * @param string $error Optional error message to show at the top.
	 * @return void
	 */
	private function render_upload( $error = '' ) {
		// Show any error handed back from a failed upload or import.
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
		}

		// The list of expected column names, to guide whoever builds the CSV.
		$columns = implode( ', ', array_keys( self::get_columns() ) );

		// --- Import panel. ------------------------------------------------
		echo '<h2>' . esc_html__( 'Import a CSV', 'ingredient-list-decoder' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Upload a UTF-8 CSV. The first row must be a header row. You will confirm how each column maps to a field before anything is imported.', 'ingredient-list-decoder' )
		);
		printf(
			'<p class="description">%s <code>%s</code></p>',
			esc_html__( 'Expected columns:', 'ingredient-list-decoder' ),
			esc_html( $columns )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: maximum file size, e.g. 2 MB. */
					__( 'Maximum file size: %s. Every imported row is set to "needs review" and is never published automatically.', 'ingredient-list-decoder' ),
					size_format( self::MAX_UPLOAD_BYTES )
				)
			)
		);

		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'ild_csv_upload', 'ild_csv_nonce' );
		echo '<input type="hidden" name="ild_csv_step" value="map" />';
		echo '<p><input type="file" name="ild_csv_file" accept=".csv,text/csv" required /></p>';
		submit_button( __( 'Upload and continue', 'ingredient-list-decoder' ) );
		echo '</form>';

		// --- Export panel. ------------------------------------------------
		echo '<hr />';
		echo '<h2>' . esc_html__( 'Export the library', 'ingredient-list-decoder' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Download the whole ingredient library as a CSV, using the same column names, so it can be edited and imported straight back.', 'ingredient-list-decoder' )
		);

		// A nonce-protected link to the admin-post export handler.
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ild_export_ingredients' ),
			'ild_csv_export'
		);
		printf(
			'<p><a href="%s" class="button button-secondary">%s</a></p>',
			esc_url( $export_url ),
			esc_html__( 'Export all ingredients (CSV)', 'ingredient-list-decoder' )
		);
	}

	/**
	 * Validate and read the uploaded file, then hold it for the mapping step.
	 *
	 * Runs every safety check (a real upload, within the size cap, a .csv file),
	 * parses the header row, and stashes the raw contents in a transient keyed by
	 * a random token so the next step can read it without a file left on disk.
	 *
	 * @return array|WP_Error An array with token, header and preview rows, or an
	 *                        error to show the user.
	 */
	private function handle_upload() {
		// There must be a file, uploaded without error.
		if ( empty( $_FILES['ild_csv_file'] ) || ! isset( $_FILES['ild_csv_file']['error'] ) ) {
			return new WP_Error( 'ild_no_file', __( 'No file was received. Please choose a CSV and try again.', 'ingredient-list-decoder' ) );
		}

		$file = $_FILES['ild_csv_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Individual members are validated below.

		// The file must have arrived cleanly.
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'ild_upload_error', __( 'The upload did not complete. Please try again.', 'ingredient-list-decoder' ) );
		}

		// It must be a genuinely uploaded file, not a path someone supplied.
		$tmp_name = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'ild_not_uploaded', __( 'The file could not be verified. Please try again.', 'ingredient-list-decoder' ) );
		}

		// It must be within the size cap and not empty.
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 ) {
			return new WP_Error( 'ild_empty', __( 'The file is empty.', 'ingredient-list-decoder' ) );
		}
		if ( $size > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error(
				'ild_too_big',
				sprintf(
					/* translators: %s: maximum file size, e.g. 2 MB. */
					__( 'The file is larger than the %s limit.', 'ingredient-list-decoder' ),
					size_format( self::MAX_UPLOAD_BYTES )
				)
			);
		}

		// It must look like a CSV by name and by WordPress's own file check.
		$filename  = sanitize_file_name( isset( $file['name'] ) ? $file['name'] : 'import.csv' );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $extension ) {
			return new WP_Error( 'ild_not_csv', __( 'Please upload a file with a .csv extension.', 'ingredient-list-decoder' ) );
		}

		// Read the contents from the temporary upload.
		$content = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a validated PHP upload temp file, not a remote URL.
		if ( false === $content || '' === trim( $content ) ) {
			return new WP_Error( 'ild_unreadable', __( 'The file could not be read or has no content.', 'ingredient-list-decoder' ) );
		}

		// Parse it into a header row and data rows.
		list( $header, $rows ) = $this->parse_csv( $content );

		if ( empty( $header ) ) {
			return new WP_Error( 'ild_no_header', __( 'No header row was found in the file.', 'ingredient-list-decoder' ) );
		}
		if ( empty( $rows ) ) {
			return new WP_Error( 'ild_no_rows', __( 'The file has a header but no data rows.', 'ingredient-list-decoder' ) );
		}

		// Hold the raw contents for the import step, under a one-time token.
		$token = wp_generate_password( 20, false );
		set_transient(
			'ild_csv_import_' . $token,
			array(
				'content'  => $content,
				'filename' => $filename,
			),
			self::TRANSIENT_TTL
		);

		// Hand back what the mapping screen needs: the token, the header, and a
		// few preview rows for confidence.
		return array(
			'token'   => $token,
			'header'  => $header,
			'preview' => array_slice( $rows, 0, 3 ),
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Step 2: mapping
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Draw the column mapping screen.
	 *
	 * For each target field, a dropdown of the CSV's columns, pre-selected to the
	 * column whose header matches the field name. Nothing is written yet; the
	 * import only happens when this form is submitted.
	 *
	 * @param array $prepared The token, header and preview from handle_upload().
	 * @return void
	 */
	private function render_mapping( $prepared ) {
		$header  = $prepared['header'];
		$preview = $prepared['preview'];

		echo '<h2>' . esc_html__( 'Map the columns', 'ingredient-list-decoder' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html__( 'Confirm which column in your file feeds each field. Columns matched by name are already selected. INCI name is required; leave any field on "Do not import" to skip it.', 'ingredient-list-decoder' )
		);

		echo '<form method="post">';
		wp_nonce_field( 'ild_csv_import', 'ild_csv_nonce' );
		echo '<input type="hidden" name="ild_csv_step" value="import" />';
		printf( '<input type="hidden" name="ild_token" value="%s" />', esc_attr( $prepared['token'] ) );

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::get_columns() as $field_key => $column ) {
			// Work out the best matching source column for this field.
			$auto = $this->guess_source_index( $field_key, $header );

			printf( '<tr><th scope="row"><label>%s</label>', esc_html( $column['label'] ) );
			if ( 'inci_name' === $field_key ) {
				echo ' <span class="description">' . esc_html__( '(required)', 'ingredient-list-decoder' ) . '</span>';
			}
			echo '</th><td>';

			printf( '<select name="ild_map[%s]">', esc_attr( $field_key ) );
			// The "skip this field" option.
			printf(
				'<option value="">%s</option>',
				esc_html__( '— Do not import —', 'ingredient-list-decoder' )
			);
			// One option per column in the uploaded file.
			foreach ( $header as $index => $col_name ) {
				printf(
					'<option value="%1$d" %2$s>%3$s</option>',
					(int) $index,
					selected( $auto, $index, false ),
					esc_html( '' === trim( (string) $col_name ) ? sprintf( /* translators: %d: column number. */ __( 'Column %d', 'ingredient-list-decoder' ), $index + 1 ) : $col_name )
				);
			}
			echo '</select>';

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		// A small preview of the first rows, so the mapping can be sanity-checked.
		if ( ! empty( $preview ) ) {
			echo '<h3>' . esc_html__( 'Preview', 'ingredient-list-decoder' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( $header as $col_name ) {
				printf( '<th>%s</th>', esc_html( $col_name ) );
			}
			echo '</tr></thead><tbody>';
			foreach ( $preview as $row ) {
				echo '<tr>';
				foreach ( $header as $index => $unused ) {
					$cell = isset( $row[ $index ] ) ? $row[ $index ] : '';
					printf( '<td>%s</td>', esc_html( $cell ) );
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		submit_button( __( 'Import ingredients', 'ingredient-list-decoder' ) );
		echo '</form>';
	}

	/**
	 * Pick the source column that best matches a target field name.
	 *
	 * Compares each header against the field key after flattening both to a
	 * common form (lower case, spaces and hyphens turned into underscores), so
	 * "INCI Name" matches "inci_name".
	 *
	 * @param string $field_key The target field, e.g. 'inci_name'.
	 * @param array  $header    The CSV header columns.
	 * @return int|null The matching column index, or null if none matched.
	 */
	private function guess_source_index( $field_key, $header ) {
		$target = $this->normalise_header( $field_key );

		foreach ( $header as $index => $col_name ) {
			if ( $this->normalise_header( $col_name ) === $target ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Flatten a header string for loose comparison.
	 *
	 * @param string $value The raw header or field name.
	 * @return string The normalised form.
	 */
	private function normalise_header( $value ) {
		$value = strtolower( trim( (string) $value ) );

		return str_replace( array( ' ', '-' ), '_', $value );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Step 3: import
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Run the import against the mapped columns.
	 *
	 * Reads the held file back from the transient, applies the chosen mapping,
	 * and walks every row: a new INCI name creates an ingredient, an existing one
	 * updates it, and everything lands as "needs review". Rows that cannot be
	 * imported are skipped with a reason. Returns a summary for the report.
	 *
	 * @return array|WP_Error The summary, or an error to show the user.
	 */
	private function handle_import() {
		// Read and clean the submitted mapping (target field => source index).
		$raw_map = isset( $_POST['ild_map'] ) && is_array( $_POST['ild_map'] ) ? wp_unslash( $_POST['ild_map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Values are cast to int below.
		$map     = array();
		foreach ( self::get_columns() as $field_key => $unused ) {
			if ( isset( $raw_map[ $field_key ] ) && '' !== $raw_map[ $field_key ] && is_numeric( $raw_map[ $field_key ] ) ) {
				$map[ $field_key ] = (int) $raw_map[ $field_key ];
			}
		}

		// The INCI name is what we match on, so it must be mapped.
		if ( ! isset( $map['inci_name'] ) ) {
			return new WP_Error( 'ild_no_inci_map', __( 'The INCI name column must be mapped. Please map it and import again.', 'ingredient-list-decoder' ) );
		}

		// Fetch the held upload back out of the transient.
		$token = isset( $_POST['ild_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ild_token'] ) ) : '';
		$held  = $token ? get_transient( 'ild_csv_import_' . $token ) : false;

		if ( ! is_array( $held ) || empty( $held['content'] ) ) {
			return new WP_Error( 'ild_expired', __( 'The uploaded file is no longer available. Please upload it again.', 'ingredient-list-decoder' ) );
		}

		// Parse it back into header and rows.
		list( $header, $rows ) = $this->parse_csv( $held['content'] );

		// Tally the outcome as we go.
		$created = array();
		$updated = array();
		$skipped = array();
		$seen    = array(); // INCI names already handled this run, to catch dupes.

		// Row 1 is the header, so data rows are numbered from 2.
		$row_number = 1;

		foreach ( $rows as $row ) {
			$row_number++;

			// Ignore a completely blank line.
			if ( 0 === count( array_filter( $row, array( $this, 'cell_has_value' ) ) ) ) {
				continue;
			}

			// The INCI name is required for every row.
			$inci = isset( $row[ $map['inci_name'] ] ) ? sanitize_text_field( trim( (string) $row[ $map['inci_name'] ] ) ) : '';
			if ( '' === $inci ) {
				$skipped[] = array(
					'row'    => $row_number,
					'reason' => __( 'Missing INCI name.', 'ingredient-list-decoder' ),
				);
				continue;
			}

			// Refuse a second row with the same INCI name in this same file.
			$key = strtolower( $inci );
			if ( isset( $seen[ $key ] ) ) {
				$skipped[] = array(
					'row'    => $row_number,
					/* translators: %s: the repeated INCI name. */
					'reason' => sprintf( __( 'Duplicate INCI name within this file: %s.', 'ingredient-list-decoder' ), $inci ),
				);
				continue;
			}
			$seen[ $key ] = true;

			// Create when the name is new, update when it already exists.
			$existing_id = $this->find_ingredient_by_inci( $inci );

			$postarr = array(
				'post_type'   => ILD_Post_Types::POST_TYPE,
				'post_title'  => $inci,
				'post_status' => ILD_Post_Types::STATUS_NEEDS_REVIEW,
			);

			if ( $existing_id ) {
				$postarr['ID'] = $existing_id;
				$result_id     = wp_update_post( $postarr, true );
			} else {
				$result_id = wp_insert_post( $postarr, true );
			}

			// A failure to save is a skip, with WordPress's own reason.
			if ( is_wp_error( $result_id ) ) {
				$skipped[] = array(
					'row'    => $row_number,
					'reason' => $result_id->get_error_message(),
				);
				continue;
			}

			// Fill in every mapped field on the saved ingredient.
			$this->apply_row( $result_id, $row, $map );

			if ( $existing_id ) {
				$updated[] = $inci;
			} else {
				$created[] = $inci;
			}
		}

		// The held upload has done its job; remove it.
		delete_transient( 'ild_csv_import_' . $token );

		return array(
			'created'  => $created,
			'updated'  => $updated,
			'skipped'  => $skipped,
			'filename' => isset( $held['filename'] ) ? $held['filename'] : '',
		);
	}

	/**
	 * Write every mapped field onto a saved ingredient.
	 *
	 * Each column is cleaned in the way its kind requires, reusing the Stage 1
	 * cleaning functions so the importer and the edit screen treat values
	 * identically. An empty value clears that field rather than storing a blank.
	 *
	 * @param int   $post_id The ingredient's ID.
	 * @param array $row     The raw CSV row.
	 * @param array $map     The field => source index mapping.
	 * @return void
	 */
	private function apply_row( $post_id, $row, $map ) {
		foreach ( self::get_columns() as $field_key => $column ) {
			// Skip the title (already saved) and the status (always forced).
			if ( 'title' === $column['kind'] || 'status' === $column['kind'] ) {
				continue;
			}

			// Skip a field the person chose not to import.
			if ( ! isset( $map[ $field_key ] ) ) {
				continue;
			}

			$raw = isset( $row[ $map[ $field_key ] ] ) ? (string) $row[ $map[ $field_key ] ] : '';

			switch ( $column['kind'] ) {
				case 'meta':
					$clean = ILD_Meta_Fields::sanitize_textarea( $raw );
					$this->store_meta( $post_id, $column['meta_key'], $clean );
					break;

				case 'percent':
					$clean = ILD_Meta_Fields::sanitize_percent( trim( $raw ) );
					$this->store_meta( $post_id, $column['meta_key'], $clean );
					break;

				case 'bool':
					$clean = $this->parse_bool( $raw );
					$this->store_meta( $post_id, $column['meta_key'], $clean );
					break;

				case 'roles':
					$clean = $this->parse_roles( $raw );
					if ( empty( $clean ) ) {
						delete_post_meta( $post_id, $column['meta_key'] );
					} else {
						update_post_meta( $post_id, $column['meta_key'], $clean );
					}
					break;

				case 'tax':
					$terms = $this->parse_terms( $raw );
					// Replace the entry's terms with those in the file, so a round
					// trip stays in sync. An empty value clears them.
					wp_set_object_terms( $post_id, $terms, $column['taxonomy'], false );
					break;
			}
		}
	}

	/**
	 * Store a single meta value, or delete it when the value is empty.
	 *
	 * @param int    $post_id  The ingredient's ID.
	 * @param string $meta_key The meta key.
	 * @param string $value    The cleaned value.
	 * @return void
	 */
	private function store_meta( $post_id, $meta_key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $meta_key );
		} else {
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Step 4: summary
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Draw the import summary.
	 *
	 * Shows how many entries were created, updated and skipped, and lists each
	 * skipped row with its number and the reason.
	 *
	 * @param array $summary The tallies from handle_import().
	 * @return void
	 */
	private function render_summary( $summary ) {
		$created = count( $summary['created'] );
		$updated = count( $summary['updated'] );
		$skipped = count( $summary['skipped'] );

		echo '<h2>' . esc_html__( 'Import complete', 'ingredient-list-decoder' ) . '</h2>';

		printf(
			'<div class="notice notice-success inline"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: created count, 2: updated count, 3: skipped count. */
					__( 'Created %1$d, updated %2$d, skipped %3$d. Every imported entry is set to "needs review".', 'ingredient-list-decoder' ),
					$created,
					$updated,
					$skipped
				)
			)
		);

		// The detail of every skipped row, so nothing fails silently.
		if ( $skipped > 0 ) {
			echo '<h3>' . esc_html__( 'Skipped rows', 'ingredient-list-decoder' ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Row', 'ingredient-list-decoder' ) . '</th>';
			echo '<th>' . esc_html__( 'Reason', 'ingredient-list-decoder' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $summary['skipped'] as $skip ) {
				printf(
					'<tr><td>%d</td><td>%s</td></tr>',
					(int) $skip['row'],
					esc_html( $skip['reason'] )
				);
			}
			echo '</tbody></table>';
		}

		// A way back to the list and to import another file.
		printf(
			'<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a></p>',
			esc_url( admin_url( 'edit.php?post_type=' . ILD_Post_Types::POST_TYPE ) ),
			esc_html__( 'View ingredients', 'ingredient-list-decoder' ),
			esc_url( admin_url( 'edit.php?post_type=' . ILD_Post_Types::POST_TYPE . '&page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Import another file', 'ingredient-list-decoder' )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Export
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Stream the whole library out as a CSV.
	 *
	 * Runs on admin-post so no page HTML has been sent yet. Writes a UTF-8 file
	 * (with a byte-order mark so spreadsheets read the encoding correctly) whose
	 * header row and columns match the importer exactly, making a round trip
	 * possible.
	 *
	 * @return void
	 */
	public function do_export() {
		// Same guards as everywhere else: permission and a valid nonce.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'ingredient-list-decoder' ) );
		}
		check_admin_referer( 'ild_csv_export' );

		$columns = self::get_columns();

		// Send the file headers.
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ild-ingredients-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );

		// A byte-order mark, so Excel opens the file as UTF-8.
		fwrite( $output, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writing to the output stream, not the filesystem.

		// The header row: the section-4 field names, in order.
		fputcsv( $output, array_keys( $columns ) );

		// Every ingredient, whatever its status, in title order.
		$query = new WP_Query(
			array(
				'post_type'      => ILD_Post_Types::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post ) {
			$line = array();

			foreach ( $columns as $field_key => $column ) {
				$line[] = $this->export_cell( $post, $column );
			}

			fputcsv( $output, $line );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the output stream.
		exit;
	}

	/**
	 * Work out the exported value for one column of one ingredient.
	 *
	 * @param WP_Post $post   The ingredient.
	 * @param array   $column The column definition.
	 * @return string The cell value.
	 */
	private function export_cell( $post, $column ) {
		switch ( $column['kind'] ) {
			case 'title':
				return $post->post_title;

			case 'status':
				return $this->status_to_label( $post->post_status );

			case 'roles':
				$roles = get_post_meta( $post->ID, $column['meta_key'], true );
				return is_array( $roles ) ? implode( '|', $roles ) : '';

			case 'bool':
				$value = get_post_meta( $post->ID, $column['meta_key'], true );
				return ( 'yes' === $value ) ? 'yes' : 'no';

			case 'tax':
				$names = wp_get_object_terms( $post->ID, $column['taxonomy'], array( 'fields' => 'names' ) );
				return is_array( $names ) ? implode( '|', $names ) : '';

			case 'percent':
			case 'meta':
			default:
				$value = get_post_meta( $post->ID, $column['meta_key'], true );
				return is_string( $value ) ? $value : '';
		}
	}

	/**
	 * Turn a post status into the human status word used in the CSV.
	 *
	 * @param string $status The WordPress post status.
	 * @return string 'draft', 'needs review' or 'published'.
	 */
	private function status_to_label( $status ) {
		switch ( $status ) {
			case 'publish':
				return 'published';
			case ILD_Post_Types::STATUS_NEEDS_REVIEW:
			case 'pending':
				return 'needs review';
			default:
				return 'draft';
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Shared helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Parse raw CSV text into a header row and data rows.
	 *
	 * Uses PHP's own CSV reader through an in-memory stream, so quoted fields and
	 * line breaks inside quotes are handled correctly. Any leading byte-order
	 * mark is stripped first.
	 *
	 * @param string $content The raw file contents.
	 * @return array{0:array,1:array} The header row and the list of data rows.
	 */
	private function parse_csv( $content ) {
		$content = $this->strip_bom( $content );

		$rows   = array();
		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory stream, not the filesystem.
		rewind( $handle );

		while ( false !== ( $data = fgetcsv( $handle ) ) ) {
			$rows[] = $data;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream.

		// The first row is the header; the rest are data.
		$header = array() === $rows ? array() : array_shift( $rows );

		return array( is_array( $header ) ? $header : array(), $rows );
	}

	/**
	 * Remove a UTF-8 byte-order mark from the start of a string, if present.
	 *
	 * @param string $content The raw content.
	 * @return string The content without a leading BOM.
	 */
	private function strip_bom( $content ) {
		$bom = chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF );

		if ( 0 === strncmp( $content, $bom, 3 ) ) {
			return substr( $content, 3 );
		}

		return $content;
	}

	/**
	 * Whether a single CSV cell holds anything once trimmed.
	 *
	 * Used to detect and skip completely blank lines.
	 *
	 * @param mixed $cell The cell value.
	 * @return bool True if the cell has visible content.
	 */
	public function cell_has_value( $cell ) {
		return '' !== trim( (string) $cell );
	}

	/**
	 * Find an existing ingredient by its exact INCI name.
	 *
	 * Matches on the post title. MySQL's default collation is case-insensitive,
	 * so "Glycerin" and "glycerin" are treated as the same entry, which is what
	 * keeps the importer from creating a near-duplicate.
	 *
	 * @param string $inci The INCI name to look for.
	 * @return int The matching post ID, or 0 if there is none.
	 */
	private function find_ingredient_by_inci( $inci ) {
		global $wpdb;

		// A direct, prepared lookup by title and type. Trashed entries are
		// ignored so a deleted-but-not-purged entry does not block a re-import.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' AND post_title = %s ORDER BY ID ASC LIMIT 1",
				ILD_Post_Types::POST_TYPE,
				$inci
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Turn a raw cell into 'yes' or an empty string.
	 *
	 * Accepts the obvious truthy words so a hand-edited file still imports.
	 *
	 * @param string $raw The raw cell value.
	 * @return string 'yes' when truthy, otherwise ''.
	 */
	private function parse_bool( $raw ) {
		$value = strtolower( trim( $raw ) );

		return in_array( $value, array( 'yes', 'y', '1', 'true' ), true ) ? 'yes' : '';
	}

	/**
	 * Turn a raw cell into a clean list of valid role slugs.
	 *
	 * Splits on the pipe, semicolon or comma, then flattens each token to a slug.
	 * Because a role's slug is the flattened form of its label ("pH adjuster" ->
	 * "ph-adjuster"), a file may hold either labels or slugs and both import.
	 * Anything that is not a real role is dropped.
	 *
	 * @param string $raw The raw cell value.
	 * @return string[] The valid role slugs.
	 */
	private function parse_roles( $raw ) {
		$tokens = preg_split( '/[|;,]/', $raw );
		$clean  = array();

		foreach ( (array) $tokens as $token ) {
			$token = trim( $token );
			if ( '' === $token ) {
				continue;
			}

			// Flatten to a slug and keep it only if it is a recognised role.
			$slug = sanitize_title( $token );
			if ( ILD_Roles::is_valid_role( $slug ) && ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}

		return $clean;
	}

	/**
	 * Turn a raw cell into a clean list of taxonomy term names.
	 *
	 * Splits on the pipe or semicolon (not the comma, since term names can
	 * contain one), trims each, and drops blanks. Names are used as-is so they
	 * match existing terms, or create them if missing, keeping a round trip
	 * lossless.
	 *
	 * @param string $raw The raw cell value.
	 * @return string[] The cleaned term names.
	 */
	private function parse_terms( $raw ) {
		$tokens = preg_split( '/[|;]/', $raw );
		$clean  = array();

		foreach ( (array) $tokens as $token ) {
			$token = sanitize_text_field( trim( $token ) );
			if ( '' !== $token && ! in_array( $token, $clean, true ) ) {
				$clean[] = $token;
			}
		}

		return $clean;
	}
}
