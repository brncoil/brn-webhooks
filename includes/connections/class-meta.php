<?php
/**
 * Meta (Facebook) connection.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Connections;

use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connection_Type;
use BRN\Webhooks\Http;
use BRN\Webhooks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Meta Graph API credentials (Conversions API + Lead Ads).
 */
class Meta extends Connection_Type {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'meta';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Meta (Facebook)', 'brn-webhooks' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'meta';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'      => 'access_token',
				'type'     => 'password',
				'label'    => __( 'Access token', 'brn-webhooks' ),
				'required' => true,
				'secret'   => true,
				'help'     => __( 'For the Conversions API: Events Manager → your dataset/pixel → Settings → Generate access token. For Lead Ads: a Page or system user token.', 'brn-webhooks' ),
			),
			array(
				'key'         => 'pixel_id',
				'type'        => 'text',
				'label'       => __( 'Pixel / dataset ID (for Conversions API)', 'brn-webhooks' ),
				'placeholder' => '123456789012345',
				'mono'        => true,
			),
			array(
				'key'    => 'app_secret',
				'type'   => 'password',
				'label'  => __( 'App secret (required for Lead Ads)', 'brn-webhooks' ),
				'secret' => true,
				'help'   => __( 'Incoming Lead Ads webhooks are verified with the X-Hub-Signature-256 header; calls are rejected until it is set.', 'brn-webhooks' ),
			),
		);
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return '<p>' . __( '<b>Conversions API (server-side events):</b> open Meta Events Manager, pick your pixel (dataset), go to <b>Settings → Conversions API → Generate access token</b> and paste it here together with the pixel ID.', 'brn-webhooks' ) . '</p>'
			. '<p>' . __( '<b>Lead Ads:</b> create a Meta app (Business type), add the <b>Webhooks</b> product, and use a system user or Page token with <code>leads_retrieval</code>, <code>pages_manage_metadata</code>, <code>pages_show_list</code> and <code>pages_read_engagement</code>. Paste the app secret to verify incoming calls.', 'brn-webhooks' ) . '</p>';
	}

	/**
	 * Meta.
	 *
	 * @param array $creds Credentials.
	 * @return array
	 */
	public function meta( array $creds ) {
		return array( 'pixel_id' => isset( $creds['pixel_id'] ) ? $creds['pixel_id'] : '' );
	}

	/**
	 * Summary.
	 *
	 * @param array $conn Connection.
	 * @return string
	 */
	public function summary( array $conn ) {
		return ! empty( $conn['credentials']['pixel_id'] ) ? 'Pixel ' . $conn['credentials']['pixel_id'] : '';
	}

	/**
	 * Test.
	 *
	 * @param array $creds Credentials.
	 * @return string
	 */
	public function test( array $creds ) {
		if ( ! empty( $creds['pixel_id'] ) ) {
			$px = self::graph( 'GET', $creds['pixel_id'], $creds['access_token'], array( 'fields' => 'id,name' ) );
			/* translators: %s: pixel name */
			return sprintf( __( 'Connected to pixel "%s".', 'brn-webhooks' ), isset( $px['name'] ) ? $px['name'] : $creds['pixel_id'] );
		}
		$me = self::graph( 'GET', 'me', $creds['access_token'], array( 'fields' => 'id,name' ) );
		/* translators: %s: account name */
		return sprintf( __( 'Connected as %s.', 'brn-webhooks' ), isset( $me['name'] ) ? $me['name'] : $me['id'] );
	}

	/**
	 * Graph API call.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param string $token  Access token.
	 * @param array  $params Query (GET) or JSON body params.
	 * @return array
	 * @throws Action_Error On failure.
	 */
	public static function graph( $method, $path, $token, array $params = array() ) {
		$version = Settings::get( 'meta_api_version' );
		$url     = 'https://graph.facebook.com/' . $version . '/' . ltrim( $path, '/' );
		$opts    = array(
			'label'   => 'Meta Graph API',
			'redact'  => array( $token ),
			'timeout' => 20,
		);
		if ( 'GET' === strtoupper( $method ) ) {
			$opts['query'] = array_merge( $params, array( 'access_token' => $token ) );
		} else {
			$opts['query'] = array( 'access_token' => $token );
			$opts['json']  = $params;
		}
		$res = Http::request( $method, $url, $opts );
		return is_array( $res['json'] ) ? $res['json'] : array();
	}

	/**
	 * Page access token for a page (falls back to the connection token).
	 *
	 * @param array  $creds   Credentials.
	 * @param string $page_id Page id.
	 * @return string
	 */
	public static function page_token( array $creds, $page_id ) {
		$token = isset( $creds['access_token'] ) ? (string) $creds['access_token'] : '';
		if ( '' === (string) $page_id ) {
			return $token;
		}
		$cache = 'brnwh_ptok_' . md5( $token . $page_id );
		$hit   = get_transient( $cache );
		if ( $hit ) {
			$plain = \BRN\Webhooks\Crypto::decrypt( $hit );
			if ( $plain ) {
				return $plain;
			}
		}
		try {
			$res = self::graph( 'GET', (string) $page_id, $token, array( 'fields' => 'access_token' ) );
			if ( ! empty( $res['access_token'] ) ) {
				set_transient( $cache, \BRN\Webhooks\Crypto::encrypt( $res['access_token'] ), 6 * HOUR_IN_SECONDS );
				return $res['access_token'];
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Probably already a page token.
		}
		return $token;
	}
}
