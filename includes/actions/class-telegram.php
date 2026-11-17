<?php
/**
 * Telegram action.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connections\Telegram as Bot;
use BRN\Webhooks\Context;
use BRN\Webhooks\Template;

defined( 'ABSPATH' ) || exit;

/**
 * Send a Telegram message through a bot.
 */
class Telegram extends Action {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'telegram';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Telegram — send message', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Send a message to a Telegram chat or group via your bot.', 'brn-webhooks' );
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
		return 'telegram';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'chat_id',
				'type'        => 'text',
				'label'       => __( 'Chat ID', 'brn-webhooks' ),
				'placeholder' => '-1001234567890',
				'required'    => true,
				'template'    => true,
			),
			array(
				'key'      => 'text',
				'type'     => 'textarea',
				'rows'     => 5,
				'label'    => __( 'Message', 'brn-webhooks' ),
				'default'  => __( "🔔 <b>New lead</b>\n{{_all_text}}", 'brn-webhooks' ),
				'template' => true,
				'help'     => __( 'HTML formatting: <b>bold</b>, <i>italic</i>, <a href="…">link</a>. Values are escaped automatically.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'silent',
				'type'    => 'toggle',
				'label'   => __( 'Send silently (no notification sound)', 'brn-webhooks' ),
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
		$chat  = trim( $ctx->render( self::cfg( $config, 'chat_id' ) ) );
		if ( '' === $chat ) {
			throw new Action_Error( __( 'Chat ID is empty.', 'brn-webhooks' ), false );
		}
		// Escape inserted values so user data can't break the HTML parse mode.
		$text = Template::render( (string) self::cfg( $config, 'text' ), $ctx->vars(), 'html' );
		$text = strip_tags( $text, '<b><strong><i><em><u><s><a><code><pre><br>' );
		$text = str_replace( array( '<br>', '<br/>', '<br />' ), "\n", $text );
		if ( '' === trim( $text ) ) {
			throw new Action_Error( __( 'The message is empty.', 'brn-webhooks' ), false );
		}
		$res = Bot::call(
			$creds['bot_token'],
			'sendMessage',
			array(
				'chat_id'                  => $chat,
				'text'                     => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 4000 ) : substr( $text, 0, 4000 ),
				'parse_mode'               => 'HTML',
				'disable_web_page_preview' => true,
				'disable_notification'     => (bool) self::cfg( $config, 'silent', false ),
			)
		);
		return array(
			'sent'       => true,
			'message_id' => isset( $res['result']['message_id'] ) ? $res['result']['message_id'] : null,
		);
	}
}
