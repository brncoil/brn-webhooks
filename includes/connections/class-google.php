<?php
/**
 * Google service account connection.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Connections;

use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Connection_Type;
use BRN\Webhooks\Crypto;
use BRN\Webhooks\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Google APIs via a service account (no OAuth consent screen needed):
 * share the spreadsheet with the service account email and it can write to it.
 */
class Google extends Connection_Type {

	const SCOPE = 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive.readonly';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'google';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Google (service account)', 'brn-webhooks' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'google_sheets';
	}

	/**
	 * Available?
	 *
	 * @return bool
	 */
	public function is_available() {
		return function_exists( 'openssl_sign' );
	}

	/**
	 * Reason.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Requires the PHP OpenSSL extension.', 'brn-webhooks' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'service_account_json',
				'type'        => 'code',
				'rows'        => 6,
				'label'       => __( 'Service account key (JSON)', 'brn-webhooks' ),
				'placeholder' => '{ "type": "service_account", "client_email": "…", "private_key": "…" }',
				'secret'      => true,
				'required'    => true,
				'file'        => '.json',
			),
		);
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return '<ol>'
			. '<li>' . sprintf(
				/* translators: %s: link */
				__( 'Open %s, create (or pick) a project and enable the <b>Google Sheets API</b>.', 'brn-webhooks' ),
				'<a href="https://console.cloud.google.com/apis/library/sheets.googleapis.com" target="_blank" rel="noopener">Google Cloud Console</a>'
			) . '</li>'
			. '<li>' . __( 'Go to <b>IAM &amp; Admin → Service Accounts</b>, create a service account, then <b>Keys → Add key → JSON</b>.', 'brn-webhooks' ) . '</li>'
			. '<li>' . __( 'Paste the downloaded JSON here (or upload the file).', 'brn-webhooks' ) . '</li>'
			. '<li>' . __( '<b>Share each spreadsheet</b> with the service account email (Editor). That\'s it.', 'brn-webhooks' ) . '</li>'
			. '</ol>';
	}

	/**
	 * Validate.
	 *
	 * @param array $creds Credentials.
	 * @return true|\WP_Error
	 */
	public function validate( array $creds ) {
		$json = json_decode( isset( $creds['service_account_json'] ) ? $creds['service_account_json'] : '', true );
		if ( ! is_array( $json ) || empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
			return new \WP_Error( 'brnwh_google_json', __( 'This is not a valid service account JSON key (client_email / private_key missing).', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Meta.
	 *
	 * @param array $creds Credentials.
	 * @return array
	 */
	public function meta( array $creds ) {
		$json = json_decode( isset( $creds['service_account_json'] ) ? $creds['service_account_json'] : '', true );
		return array(
			'client_email' => is_array( $json ) && isset( $json['client_email'] ) ? $json['client_email'] : '',
			'project_id'   => is_array( $json ) && isset( $json['project_id'] ) ? $json['project_id'] : '',
		);
	}

	/**
	 * Summary.
	 *
	 * @param array $conn Connection.
	 * @return string
	 */
	public function summary( array $conn ) {
		return isset( $conn['meta']['client_email'] ) ? (string) $conn['meta']['client_email'] : '';
	}

	/**
	 * Test.
	 *
	 * @param array $creds Credentials.
	 * @return string
	 */
	public function test( array $creds ) {
		self::token( $creds, true );
		$meta = $this->meta( $creds );
		/* translators: %s: service account email */
		return sprintf( __( 'Authenticated. Share your spreadsheets with %s', 'brn-webhooks' ), $meta['client_email'] );
	}

	/**
	 * OAuth access token from the service account (cached ~55 minutes, encrypted).
	 *
	 * @param array $creds Credentials.
	 * @param bool  $fresh Skip cache.
	 * @return string
	 * @throws Action_Error On failure.
	 */
	public static function token( array $creds, $fresh = false ) {
		$sa = json_decode( isset( $creds['service_account_json'] ) ? $creds['service_account_json'] : '', true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			throw new Action_Error( __( 'Invalid Google service account key.', 'brn-webhooks' ), false );
		}
		$cache_key = 'brnwh_gtok_' . md5( $sa['client_email'] . self::SCOPE );
		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			if ( $cached ) {
				$plain = Crypto::decrypt( $cached );
				if ( $plain ) {
					return $plain;
				}
			}
		}
		$token_uri = ! empty( $sa['token_uri'] ) ? $sa['token_uri'] : 'https://oauth2.googleapis.com/token';
		$now       = time();
		$b64       = static function ( $s ) {
			return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		};
		$header    = $b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims    = $b64(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => self::SCOPE,
					'aud'   => $token_uri,
					'iat'   => $now - 30,
					'exp'   => $now + 3300,
				)
			)
		);
		$input     = $header . '.' . $claims;
		$signature = '';
		$key       = openssl_pkey_get_private( $sa['private_key'] );
		if ( ! $key || ! openssl_sign( $input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new Action_Error( __( 'Could not sign with the service account private key.', 'brn-webhooks' ), false );
		}
		$jwt = $input . '.' . $b64( $signature );

		$res = Http::request(
			'POST',
			$token_uri,
			array(
				'body'        => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
				'label'       => 'Google OAuth',
				'redact_body' => true,
				'redact_response' => true,
				'fail_on_error' => false,
			)
		);
		if ( empty( $res['json']['access_token'] ) ) {
			$err = isset( $res['json']['error_description'] ) ? $res['json']['error_description'] : Http::error_detail( $res );
			/* translators: %s: error */
			throw new Action_Error( sprintf( __( 'Google authentication failed: %s', 'brn-webhooks' ), $err ), $res['code'] >= 500 );
		}
		$ttl = isset( $res['json']['expires_in'] ) ? max( 60, (int) $res['json']['expires_in'] - 300 ) : 3000;
		set_transient( $cache_key, Crypto::encrypt( $res['json']['access_token'] ), $ttl );
		return $res['json']['access_token'];
	}
}
