<?php
/**
 * Slack action.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Context;
use BRN\Webhooks\Http;
use BRN\Webhooks\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Post a message to a Slack channel.
 */
class Slack extends Action {

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
		return __( 'Slack — send message', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Post a message to a Slack channel.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return __( 'Messages', 'brn-webhooks' );
	}

	/**
	 * Connection.
	 *
	 * @return string
	 */
	public function connection_type() {
		return 'slack';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'      => 'text',
				'type'     => 'textarea',
				'rows'     => 5,
				'label'    => __( 'Message', 'brn-webhooks' ),
				'default'  => __( ':tada: New lead: *{{name}}* — {{email}}', 'brn-webhooks' ),
				'template' => true,
				'help'     => __( 'Slack formatting: *bold*, _italic_, <https://url|link>.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'append_all',
				'type'    => 'toggle',
				'label'   => __( 'Append all fields', 'brn-webhooks' ),
				'default' => false,
			),
		);
	}

	/**
	 * Execute.
	 *
	 * @param array      $config     Config.
	 * @param Context    $ctx        Context.
	 * @param array|null $connection Connection.
	 * @return array
	 * @throws Action_Error On failure.
	 */
	public function execute( array $config, Context $ctx, $connection ) {
		$creds = $this->creds( $connection );
		$text  = $ctx->render( self::cfg( $config, 'text' ) );
		if ( self::cfg( $config, 'append_all', false ) ) {
			$lines = array();
			foreach ( Template::public_fields( $ctx->data ) as $k => $v ) {
				$lines[] = '*' . $k . ':* ' . Template::stringify( $v );
			}
			$text .= "\n" . implode( "\n", $lines );
		}
		if ( '' === trim( $text ) ) {
			throw new Action_Error( __( 'The message is empty.', 'brn-webhooks' ), false );
		}
		Http::request(
			'POST',
			$creds['webhook_url'],
			array(
				'json'   => array( 'text' => $text ),
				'redact' => array( $creds['webhook_url'] ),
				'label'  => 'Slack',
			)
		);
		return array( 'sent' => true );
	}
}
