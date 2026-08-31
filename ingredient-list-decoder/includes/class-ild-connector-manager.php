<?php
/**
 * The connector queue: deciding when to push a lead, and recording the outcome.
 *
 * Leads are captured locally by the core regardless of any connector. This
 * manager sits between the lead store and whichever connectors are installed: on
 * capture (and on a retry from the failed-sync view) it queues a push, sends the
 * lead to every configured connector, and records the result on the lead —
 * synced, or failed with the rejection message so it surfaces in the failed-sync
 * view. A failed push is retried a few times with a growing backoff.
 *
 * A lead is only ever pushed where consent is recorded.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queues pushes to the registered connectors and records their outcome.
 */
class ILD_Connector_Manager {

	/**
	 * The scheduled-event hook that runs a push.
	 *
	 * @var string
	 */
	const PUSH_HOOK = 'ild_push_lead';

	/**
	 * The meta key holding how many times a lead's push has been attempted.
	 *
	 * @var string
	 */
	const ATTEMPTS_META = '_ild_sync_attempts';

	/**
	 * How many attempts before giving up (the failure stays in the view).
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 4;

	/**
	 * The backoff, in seconds, before each retry.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 60, 300, 1800, 7200 );

	/**
	 * Hook the capture, the retry and the scheduled push.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'ild_lead_captured', array( $this, 'on_capture' ) );
		add_action( 'ild_retry_lead_sync', array( $this, 'on_retry' ) );
		add_action( self::PUSH_HOOK, array( $this, 'do_push' ) );
	}

	/**
	 * Every registered connector instance, validated against the interface.
	 *
	 * @return ILD_Email_Connector[]
	 */
	public static function connectors() {
		$connectors = apply_filters( 'ild_email_connectors', array() );
		$valid      = array();
		foreach ( (array) $connectors as $connector ) {
			if ( $connector instanceof ILD_Email_Connector ) {
				$valid[] = $connector;
			}
		}
		return $valid;
	}

	/**
	 * The connectors that are configured and ready to push.
	 *
	 * @return ILD_Email_Connector[]
	 */
	public static function active_connectors() {
		return array_values(
			array_filter(
				self::connectors(),
				function ( $connector ) {
					return $connector->is_configured();
				}
			)
		);
	}

	/**
	 * On a new lead, queue a push if any connector is active.
	 *
	 * @param int $lead_id The lead.
	 * @return void
	 */
	public function on_capture( $lead_id ) {
		if ( empty( self::active_connectors() ) ) {
			return; // Captured locally; nothing to push to.
		}
		$this->enqueue( (int) $lead_id, 0 );
	}

	/**
	 * On a manual retry from the failed-sync view, start the attempts over.
	 *
	 * @param int $lead_id The lead.
	 * @return void
	 */
	public function on_retry( $lead_id ) {
		delete_post_meta( (int) $lead_id, self::ATTEMPTS_META );
		$this->enqueue( (int) $lead_id, 0 );
	}

	/**
	 * Schedule a push for a lead, unless one is already scheduled.
	 *
	 * @param int $lead_id The lead.
	 * @param int $delay   Seconds to wait.
	 * @return void
	 */
	private function enqueue( $lead_id, $delay ) {
		$args = array( $lead_id );
		if ( ! wp_next_scheduled( self::PUSH_HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::PUSH_HOOK, $args );
		}
	}

	/**
	 * Push a lead to every active connector, recording the outcome.
	 *
	 * @param int $lead_id The lead.
	 * @return void
	 */
	public function do_push( $lead_id ) {
		$lead_id    = (int) $lead_id;
		$connectors = self::active_connectors();
		if ( empty( $connectors ) ) {
			return;
		}

		$lead = ILD_Leads::get_lead( $lead_id );

		// Only push where consent is recorded and not withdrawn.
		if ( 'yes' !== $lead['consent'] || '' !== $lead['unsubscribed'] ) {
			return;
		}

		$payload = self::payload( $lead );

		foreach ( $connectors as $connector ) {
			$result = $connector->push( $payload );

			if ( is_wp_error( $result ) ) {
				// Record the failure so it shows in the failed-sync view, then
				// schedule a backed-off retry if there are attempts left.
				ILD_Leads::set_sync_status( $lead_id, ILD_Leads::SYNC_FAILED, $result->get_error_message(), $connector->get_id() );

				$attempts = (int) get_post_meta( $lead_id, self::ATTEMPTS_META, true );
				if ( $attempts + 1 < self::MAX_ATTEMPTS ) {
					update_post_meta( $lead_id, self::ATTEMPTS_META, $attempts + 1 );
					$backoff = self::BACKOFF;
					$delay   = isset( $backoff[ $attempts ] ) ? $backoff[ $attempts ] : $backoff[ count( $backoff ) - 1 ];
					$this->enqueue( $lead_id, $delay );
				}
				return; // Stop at the first failure; try again on the retry.
			}
		}

		// Every connector accepted it.
		ILD_Leads::set_sync_status( $lead_id, ILD_Leads::SYNC_SYNCED );
		delete_post_meta( $lead_id, self::ATTEMPTS_META );
	}

	/**
	 * Build the normalised payload the connectors receive.
	 *
	 * @param array $lead The lead, from ILD_Leads::get_lead().
	 * @return array
	 */
	public static function payload( $lead ) {
		$name = get_post_meta( (int) $lead['id'], '_ild_name', true );

		return array(
			'name'         => is_string( $name ) ? $name : '',
			'email'        => $lead['email'],
			'source'       => $lead['source'],
			'consent_date' => $lead['captured'],
			'tool'         => (string) apply_filters( 'ild_connector_tool_name', __( 'Ingredient List Decoder', 'ingredient-list-decoder' ) ),
		);
	}
}
