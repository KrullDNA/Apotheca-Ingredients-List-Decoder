<?php
/**
 * Image transcription: read the text off a photo of an ingredient list.
 *
 * The flow is deliberately narrow. The browser converts and shrinks the photo,
 * then posts it here. This class sends the image to the Anthropic API with a
 * transcription-only instruction, deletes the uploaded file from the server the
 * instant it has the text back, and returns that text for a human to check
 * before the engine ever runs. Nothing here interprets the image beyond reading
 * the words printed on it, and no copy of the photo is kept.
 *
 * The API key, model and size limit live in their own Settings section,
 * registered through the plugin's section API.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the photo upload, the transcription request and the settings.
 */
class ILD_Transcription {

	/**
	 * The AJAX action name (also the nonce action).
	 *
	 * @var string
	 */
	const ACTION = 'ild_transcribe';

	/**
	 * The nonce field name posted with the image.
	 *
	 * @var string
	 */
	const NONCE = 'ild_transcribe_nonce';

	/**
	 * The name of the uploaded file field.
	 *
	 * @var string
	 */
	const FILE_FIELD = 'ild_image';

	/**
	 * The Anthropic messages endpoint.
	 *
	 * @var string
	 */
	const API_URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * The image formats the Anthropic API accepts. HEIC is not one of them, so
	 * the browser converts to JPEG before upload; a stray HEIC is rejected here.
	 *
	 * @var string[]
	 */
	const ACCEPTED_MEDIA = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * Hook the settings section and the AJAX endpoint onto WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'ild_register_settings', array( $this, 'register_settings_section' ) );

		// Open to logged-out visitors, because the tool is public.
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'ajax_transcribe' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'ajax_transcribe' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Add the Transcription section to the plugin's settings page.
	 *
	 * @param ILD_Settings $settings The settings component.
	 * @return void
	 */
	public function register_settings_section( $settings ) {
		$settings->add_section(
			array(
				'id'          => 'ild_section_transcription',
				'title'       => __( 'Photo transcription', 'ingredient-list-decoder' ),
				'description' => __( 'Reads the ingredient list from a photo. By default this happens free, in the visitor\'s own browser — the photo never leaves their device. Add an Anthropic API key below to also offer a more accurate AI reading; that reading sends the image to the Anthropic API for transcription only, then deletes it from the server immediately.', 'ingredient-list-decoder' ),
				'fields'      => array(
					array(
						'id'          => 'read_from_photo',
						'label'       => __( 'Read the list from a photo', 'ingredient-list-decoder' ),
						'type'        => 'checkbox',
						'default'     => 1,
						'description' => __( 'Offer reading the ingredient list from a photo. Free browser reading is used by default; it needs no API key and no photo leaves the visitor\'s device.', 'ingredient-list-decoder' ),
					),
					array(
						'id'          => 'anthropic_api_key',
						'label'       => __( 'Anthropic API key', 'ingredient-list-decoder' ),
						'type'        => 'text',
						'default'     => '',
						'description' => __( 'Optional. When set, the tool also offers a more accurate AI reading of the photo. Stored on your site and never shown in the tool. Leave blank to use free browser reading only.', 'ingredient-list-decoder' ),
						'sanitize'    => array( __CLASS__, 'sanitize_key_field' ),
					),
					array(
						'id'          => 'transcription_model',
						'label'       => __( 'Transcription model', 'ingredient-list-decoder' ),
						'type'        => 'text',
						'default'     => self::default_model(),
						'description' => __( 'The Anthropic model used to read the photo. A fast, vision-capable model is best.', 'ingredient-list-decoder' ),
						'sanitize'    => 'sanitize_text_field',
					),
					array(
						'id'          => 'max_image_mb',
						'label'       => __( 'Maximum photo size (MB)', 'ingredient-list-decoder' ),
						'type'        => 'number',
						'default'     => 8,
						'min'         => 1,
						'max'         => 20,
						'description' => __( 'Photos larger than this are refused before upload. The browser also shrinks photos, so most end up well under it.', 'ingredient-list-decoder' ),
						'sanitize'    => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Clean the API key: trim it, keep only plausible key characters.
	 *
	 * @param mixed $value The raw value.
	 * @return string
	 */
	public static function sanitize_key_field( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $value );
	}

	/**
	 * The default model for transcription.
	 *
	 * @return string
	 */
	public static function default_model() {
		return 'claude-haiku-4-5-20251001';
	}

	/**
	 * The stored API key, if any.
	 *
	 * @return string
	 */
	public static function api_key() {
		return (string) ild_get_setting( 'anthropic_api_key', '' );
	}

	/**
	 * Whether the photo route is offered at all.
	 *
	 * This governs whether the upload/camera control appears. Free browser
	 * reading needs no API key, so the control shows whenever the feature is on,
	 * with or without a key.
	 *
	 * @return bool
	 */
	public static function feature_on() {
		return 0 !== (int) ild_get_setting( 'read_from_photo', 1 );
	}

	/**
	 * Whether the paid, more-accurate AI reading is available (a key is present).
	 *
	 * The free browser reading is always the default; this only decides whether
	 * the "read it more accurately" option is offered on top of it.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '' !== trim( self::api_key() );
	}

	/**
	 * The maximum accepted photo size, in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		$mb = (int) ild_get_setting( 'max_image_mb', 8 );
		$mb = max( 1, min( 20, $mb ) );
		return $mb * 1024 * 1024;
	}

	/**
	 * The maximum accepted photo size, in megabytes.
	 *
	 * @return int
	 */
	public static function max_mb() {
		return (int) round( self::max_bytes() / ( 1024 * 1024 ) );
	}

	/**
	 * The transcription-only instruction sent with the image.
	 *
	 * It asks for the printed text and nothing else — no translation, no
	 * interpretation, no correction — so the model reads the label rather than
	 * reasoning about it.
	 *
	 * @return string
	 */
	public static function instruction() {
		$default = 'You are transcribing the text printed on a photograph of a cosmetic product label. '
			. 'Return only the ingredient list, exactly as printed, as plain text with the ingredients separated by commas. '
			. 'Do not translate, interpret, correct, reorder, summarise, or add any words that are not printed on the label. '
			. 'Ignore marketing text, directions and warnings; return only the list of ingredients. '
			. 'If there is no readable ingredient list in the image, return nothing at all.';

		return (string) apply_filters( 'ild_transcription_instruction', $default );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The AJAX endpoint
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Receive a photo, transcribe it, delete it, and return the text.
	 *
	 * Every guard runs before the file is touched: the nonce, that the feature is
	 * on, that exactly one uploaded file arrived, its size and its real type. The
	 * uploaded file is read once, sent for transcription, and unlinked straight
	 * afterwards — it is never moved into the media library or left on disk.
	 *
	 * @return void
	 */
	public function ajax_transcribe() {
		// The request must carry our valid nonce.
		if ( ! check_ajax_referer( self::ACTION, self::NONCE, false ) ) {
			$this->fail( 'network' );
		}

		// The feature must be switched on (an API key must be set).
		if ( ! self::is_enabled() ) {
			$this->fail( 'not_ready' );
		}

		// A tighter per-IP limit on photos, because each one costs money to read.
		if ( ILD_Rate_Limit::too_many( 'image' ) ) {
			$this->fail( 'rate_limited' );
		}

		// The hard, site-wide daily cap on paid requests. A graceful message.
		if ( ILD_Rate_Limit::is_capped() ) {
			$this->fail( 'capped' );
		}

		// Exactly one uploaded file, arriving cleanly.
		if ( empty( $_FILES[ self::FILE_FIELD ] ) || ! isset( $_FILES[ self::FILE_FIELD ]['tmp_name'] ) ) {
			$this->fail( 'read_failed' );
		}

		$file  = $_FILES[ self::FILE_FIELD ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Individual members are validated below.
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		$tmp   = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';

		if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! $this->is_uploaded( $tmp ) ) {
			$this->fail( 'read_failed' );
		}

		// Make sure the file is deleted however this request ends from here on.
		$transcribed = '';
		try {
			// Size guard.
			$size = isset( $file['size'] ) ? (int) $file['size'] : (int) filesize( $tmp );
			if ( $size <= 0 || $size > self::max_bytes() ) {
				$this->fail( 'too_large', $tmp );
			}

			// Real type guard: trust the file's contents, not its name.
			$check = wp_check_filetype_and_ext( $tmp, isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'upload' );
			$media = ! empty( $check['type'] ) ? $check['type'] : '';
			if ( ! in_array( $media, self::ACCEPTED_MEDIA, true ) ) {
				$this->fail( 'wrong_type', $tmp );
			}

			// Read the bytes once.
			$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local upload temp file.
			if ( false === $bytes || '' === $bytes ) {
				$this->fail( 'read_failed', $tmp );
			}

			// Transcribe, then immediately delete the upload.
			$transcribed = $this->transcribe( $bytes, $media );
		} finally {
			// Delete the uploaded image from the server immediately.
			if ( '' !== $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}

		if ( is_wp_error( $transcribed ) ) {
			$this->fail( 'read_failed' );
		}

		$transcribed = trim( (string) $transcribed );
		if ( '' === $transcribed ) {
			$this->fail( 'no_text' );
		}

		// Keep it as plain, multi-line text — never HTML.
		$transcribed = sanitize_textarea_field( $transcribed );

		wp_send_json_success( array( 'text' => $transcribed ) );
	}

	/**
	 * Whether a path is a genuine HTTP upload.
	 *
	 * Wrapped in a method purely so the upload flow can be exercised in tests
	 * without a real multipart upload; production behaviour is unchanged.
	 *
	 * @param string $tmp The temp path.
	 * @return bool
	 */
	protected function is_uploaded( $tmp ) {
		return is_uploaded_file( $tmp );
	}

	/**
	 * Send the image to the Anthropic API and return the transcribed text.
	 *
	 * @param string $bytes The raw image bytes.
	 * @param string $media The image media type (e.g. image/jpeg).
	 * @return string|WP_Error The transcribed text, or an error.
	 */
	private function transcribe( $bytes, $media ) {
		$body = array(
			'model'      => (string) ild_get_setting( 'transcription_model', self::default_model() ),
			'max_tokens' => 1500,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $media,
								'data'       => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by the API image format.
							),
						),
						array(
							'type' => 'text',
							'text' => self::instruction(),
						),
					),
				),
			),
		);

		// This call costs money: count it against today's cap before making it.
		ILD_Rate_Limit::record_paid_call();

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'x-api-key'         => self::api_key(),
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'ild_api_http', 'Transcription request failed.', array( 'status' => $code ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// The text lives in the first text content block of the reply.
		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}
		}

		return $text;
	}

	/**
	 * End the request with a keyed error message and, optionally, delete a file.
	 *
	 * @param string $key The photo_messages() key to send back.
	 * @param string $tmp An uploaded temp file to delete first, if any.
	 * @return void
	 */
	private function fail( $key, $tmp = '' ) {
		if ( '' !== $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		$messages = ILD_Phrases::photo_messages();
		$message  = isset( $messages[ $key ] ) ? $messages[ $key ] : $messages['network'];

		wp_send_json_error( array( 'message' => $message ) );
	}
}
