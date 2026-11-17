<?php
/**
 * Telegram bot connection.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Connections;

use BRN\Webhooks\Connection_Type;
use BRN\Webhooks\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Telegram bot token.
 */
class Telegram extends Connection_Type {

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
		return 'Telegram';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'bot_token',
				'type'        => 'password',
				'label'       => __( 'Bot token', 'brn-webhooks' ),
				'placeholder' => '123456789:AA…',
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
		return '<p>' . __( 'Talk to <b>@BotFather</b> in Telegram, send <code>/newbot</code> and paste the token. Add the bot to your group, then use the group\'s chat ID in the step (tip: send a message in the group and open https://api.telegram.org/bot&lt;token&gt;/getUpdates).', 'brn-webhooks' ) . '</p>';
	}

	/**
	 * Summary.
	 *
	 * @param array $conn Connection.
	 * @return string
	 */
	public function summary( array $conn ) {
		return isset( $conn['meta']['bot'] ) ? '@' . $conn['meta']['bot'] : '';
	}

	/**
	 * Meta (bot username is fetched on test).
	 *
	 * @param array $creds Credentials.
	 * @return array
	 */
	public function meta( array $creds ) {
		try {
			$res = self::call( $creds['bot_token'], 'getMe', array() );
			return array( 'bot' => isset( $res['result']['username'] ) ? $res['result']['username'] : '' );
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Test.
	 *
	 * @param array $creds Credentials.
	 * @return string
	 */
	public function test( array $creds ) {
		$res = self::call( $creds['bot_token'], 'getMe', array() );
		/* translators: %s: bot username */
		return sprintf( __( 'Connected as @%s.', 'brn-webhooks' ), isset( $res['result']['username'] ) ? $res['result']['username'] : '?' );
	}

	/**
	 * Bot API call.
	 *
	 * @param string $token  Token.
	 * @param string $method Method.
	 * @param array  $params Params.
	 * @return array
	 */
	public static function call( $token, $method, array $params ) {
		$res = Http::request(
			'POST',
			'https://api.telegram.org/bot' . $token . '/' . $method,
			array(
				'json'   => (object) $params,
				'redact' => array( $token ),
				'label'  => 'Telegram',
			)
		);
		return is_array( $res['json'] ) ? $res['json'] : array();
	}
}
