<?php
/**
 * The Campaign Monitor add-on's admin: its settings and the test-connection tool.
 *
 * The settings live in a section added to the core plugin's own settings page,
 * so the API key and list ID sit alongside everything else. A test-connection
 * button checks the credentials without leaving the page.
 *
 * @package IngredientListDecoder\CampaignMonitor
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the connector's settings and the test endpoint.
 */
class ILDCM_Admin {

	/**
	 * The test-connection AJAX action / nonce.
	 *
	 * @var string
	 */
	const TEST_ACTION = 'ildcm_test';

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
	 * Add the Campaign Monitor section to the core settings page.
	 *
	 * @param ILD_Settings $settings The core settings component.
	 * @return void
	 */
	public function register_section( $settings ) {
		$settings->add_section(
			array(
				'id'          => 'ildcm_section',
				'title'       => __( 'Campaign Monitor', 'ild-campaign-monitor' ),
				'description' => __( 'Push consented leads to a Campaign Monitor list. Create custom fields named Source, ConsentDate and Tool on the list first — the tool name lets you segment these leads apart from Founding Faces members.', 'ild-campaign-monitor' ),
				'fields'      => array(
					array( 'id' => 'cm_api_key', 'label' => __( 'API key', 'ild-campaign-monitor' ), 'type' => 'text', 'default' => '', 'sanitize' => 'sanitize_text_field' ),
					array( 'id' => 'cm_list_id', 'label' => __( 'List ID', 'ild-campaign-monitor' ), 'type' => 'text', 'default' => '', 'sanitize' => 'sanitize_text_field' ),
					array( 'id' => 'cm_tools', 'label' => __( 'Connection', 'ild-campaign-monitor' ), 'type' => 'callback', 'render' => array( $this, 'render_test' ) ),
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
		<button type="button" class="button" id="ildcm-test"><?php esc_html_e( 'Test connection', 'ild-campaign-monitor' ); ?></button>
		<span id="ildcm-test-status" role="status" style="margin-left:8px;"></span>
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

		wp_enqueue_script( 'ildcm-admin', ILDCM_URL . 'assets/js/admin.js', array( 'jquery' ), ILDCM_VERSION, true );
		wp_localize_script(
			'ildcm-admin',
			'ILDCM',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'action'   => self::TEST_ACTION,
				'nonce'    => wp_create_nonce( self::TEST_ACTION ),
				'testing'  => __( 'Testing…', 'ild-campaign-monitor' ),
				'ok'       => __( 'Connected.', 'ild-campaign-monitor' ),
				'failed'   => __( 'Connection failed.', 'ild-campaign-monitor' ),
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
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ild-campaign-monitor' ) ) );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';

		$result = ILDCM_Connector::test( $api_key, $list_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success();
	}
}
