<?php
/**
 * The Klaviyo add-on's admin: its settings and the test-connection tool.
 *
 * The settings live in a section added to the core plugin's own settings page,
 * so the API key and list ID sit alongside everything else. A test-connection
 * button checks the credentials without leaving the page.
 *
 * @package IngredientListDecoder\Klaviyo
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the connector's settings and the test endpoint.
 */
class ILDK_Admin {

	/**
	 * The test-connection AJAX action / nonce.
	 *
	 * @var string
	 */
	const TEST_ACTION = 'ildk_test';

	/**
	 * Hook the settings section, the test endpoint and the admin script.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'ild_register_settings', array( $this, 'register_section' ) );
		add_action( 'wp_ajax_' . self::TEST_ACTION, array( $this, 'ajax_test' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the Klaviyo section to the core settings page.
	 *
	 * @param ILD_Settings $settings The core settings component.
	 * @return void
	 */
	public function register_section( $settings ) {
		$settings->add_section(
			array(
				'id'          => 'ildk_section',
				'title'       => __( 'Klaviyo', 'ild-klaviyo' ),
				'description' => __( 'Push consented leads to a Klaviyo list, directly via the API. The source page is set as both a profile property and a tag, and the tool name as a property, so these leads can be segmented apart from Founding Faces members.', 'ild-klaviyo' ),
				'fields'      => array(
					array( 'id' => 'klaviyo_api_key', 'label' => __( 'Private API key', 'ild-klaviyo' ), 'type' => 'text', 'default' => '', 'sanitize' => 'sanitize_text_field' ),
					array( 'id' => 'klaviyo_list_id', 'label' => __( 'List ID', 'ild-klaviyo' ), 'type' => 'text', 'default' => '', 'sanitize' => 'sanitize_text_field' ),
					array( 'id' => 'klaviyo_tools', 'label' => __( 'Connection', 'ild-klaviyo' ), 'type' => 'callback', 'render' => array( $this, 'render_test' ) ),
				),
			)
		);
	}

	/**
	 * Render the test-connection button and its status line.
	 *
	 * @param array $field The field definition.
	 * @return void
	 */
	public function render_test( $field ) {
		?>
		<button type="button" class="button" id="ildk-test"><?php esc_html_e( 'Test connection', 'ild-klaviyo' ); ?></button>
		<span id="ildk-test-status" role="status" style="margin-left:8px;"></span>
		<?php
	}

	/**
	 * Enqueue the small admin script on the core settings page only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( ! class_exists( 'ILD_Settings' ) || false === strpos( (string) $hook, ILD_Settings::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_script( 'ildk-admin', ILDK_URL . 'assets/js/admin.js', array( 'jquery' ), ILDK_VERSION, true );
		wp_localize_script(
			'ildk-admin',
			'ILDK',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::TEST_ACTION,
				'nonce'   => wp_create_nonce( self::TEST_ACTION ),
				'testing' => __( 'Testing…', 'ild-klaviyo' ),
				'ok'      => __( 'Connected.', 'ild-klaviyo' ),
				'failed'  => __( 'Connection failed.', 'ild-klaviyo' ),
			)
		);
	}

	/**
	 * The test-connection endpoint: test the values currently in the form.
	 *
	 * @return void
	 */
	public function ajax_test() {
		if ( ! check_ajax_referer( self::TEST_ACTION, 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ild-klaviyo' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';

		$result = ILDK_Connector::test( $api_key, $list_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success();
	}
}
