<?php
/**
 * Slack incoming webhook connection.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Connections;

use BRN\Webhooks\Connection_Type;
use BRN\Webhooks\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Slack incoming webhook URL.
 */
class Slack extends Connection_Type {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'slack';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Slack';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'webhook_url',
				'type'        => 'password',
				'label'       => __( 'Incoming webhook URL', 'brn-webhooks' ),
				'placeholder' => 'https://hooks.slack.com/services/…',
				'required'    => true,
				'secret'      => true,
			),
		);
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return '<p>' . sprintf(
			/* translators: %s: link */
			__( 'Create a Slack app at %s, enable <b>Incoming Webhooks</b>, add one for your channel and paste its URL here.', 'brn-webhooks' ),
			'<a href="https://api.slack.com/apps" target="_blank" rel="noopener">api.slack.com/apps</a>'
		) . '</p>';
	}

	/**
	 * Validate.
	 *
	 * @param array $creds Credentials.
	 * @return true|\WP_Error
	 */
	public function validate( array $creds ) {
		if ( empty( $creds['webhook_url'] ) || 0 !== strpos( $creds['webhook_url'], 'https://hooks.slack.com/' ) ) {
			return new \WP_Error( 'brnwh_slack', __( 'The URL must start with https://hooks.slack.com/', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Test (sends a message).
	 *
	 * @param array $creds Credentials.
	 * @return string
	 */
	public function test( array $creds ) {
		Http::request(
			'POST',
			$creds['webhook_url'],
			array(
				'json'   => array( 'text' => '✅ BRN Webhooks is connected to this channel.' ),
				'redact' => array( $creds['webhook_url'] ),
			)
		);
		return __( 'A test message was sent to the channel.', 'brn-webhooks' );
	}
}
