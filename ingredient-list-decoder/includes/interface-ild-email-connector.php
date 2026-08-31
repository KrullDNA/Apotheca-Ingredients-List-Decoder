<?php
/**
 * The email-connector interface.
 *
 * Every marketing provider (Campaign Monitor, Klaviyo, and so on) is a separate
 * add-on plugin that implements this one interface. The core owns everything
 * else: it captures leads locally whether or not any connector is active, holds
 * the queue, decides when a push should happen (on capture, and on retry), and
 * records the outcome against the lead. A connector only has to know how to talk
 * to its own service.
 *
 * A connector registers itself by adding an instance to the `ild_email_connectors`
 * filter.
 *
 * @package IngredientListDecoder
 */

// Stop anyone loading this file directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a marketing-list connector must provide.
 */
interface ILD_Email_Connector {

	/**
	 * A stable machine id for this connector, e.g. 'campaign-monitor'.
	 *
	 * Recorded against a lead as the provider, so the failed-sync view can name
	 * which service rejected it.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * A human-readable name, e.g. 'Campaign Monitor'.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Whether this connector has everything it needs to push (keys, a list id).
	 *
	 * The core only pushes to configured connectors.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Check the connection to the service.
	 *
	 * @return true|WP_Error True when reachable and authorised, or an error.
	 */
	public function test_connection();

	/**
	 * Push one lead to the service.
	 *
	 * The core calls this only for a lead that has consented. The payload is
	 * normalised by the core:
	 *
	 *   array(
	 *     'name'         => string, // may be empty
	 *     'email'        => string,
	 *     'source'       => string, // the page URL the lead came from
	 *     'consent_date' => string, // GMT 'Y-m-d H:i:s' of capture
	 *     'tool'         => string, // which tool captured it, for segmenting
	 *   )
	 *
	 * @param array $lead The normalised lead payload.
	 * @return true|WP_Error True on success, or an error whose message is shown
	 *                       in the failed-sync view.
	 */
	public function push( array $lead );
}
