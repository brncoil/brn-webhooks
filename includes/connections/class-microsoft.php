<?php
/**
 * Microsoft 365 (Graph) connection.
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
 * Microsoft Graph with an Entra ID (Azure AD) app registration, client-credentials flow.
 */
class Microsoft extends Connection_Type {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'microsoft';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Microsoft 365', 'brn-webhooks' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'excel';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'tenant_id',
				'type'        => 'text',
				'label'       => __( 'Directory (tenant) ID', 'brn-webhooks' ),
				'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
				'required'    => true,
				'mono'        => true,
			),
			array(
				'key'         => 'client_id',
				'type'        => 'text',
				'label'       => __( 'Application (client) ID', 'brn-webhooks' ),
				'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
				'required'    => true,
				'mono'        => true,
			),
			array(
				'key'      => 'client_secret',
				'type'     => 'password',
				'label'    => __( 'Client secret (value)', 'brn-webhooks' ),
				'required' => true,
				'secret'   => true,
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
				__( 'In %s go to <b>App registrations → New registration</b> (single tenant).', 'brn-webhooks' ),
				'<a href="https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank" rel="noopener">Microsoft Entra</a>'
			) . '</li>'
			. '<li>' . __( '<b>API permissions → Add → Microsoft Graph → Application permissions</b>: <code>Files.ReadWrite.All</code> and <code>Sites.ReadWrite.All</code>, then <b>Grant admin consent</b>.', 'brn-webhooks' ) . '</li>'
			. '<li>' . __( '<b>Certificates &amp; secrets → New client secret</b>, copy the <b>Value</b>.', 'brn-webhooks' ) . '</li>'
			. '<li>' . __( 'Copy the Tenant ID and Client ID from the app\'s Overview page.', 'brn-webhooks' ) . '</li>'
			. '</ol>';
	}

	/**
	 * Meta.
	 *
	 * @param array $creds Credentials.
	 * @return array
	 */
	public function meta( array $creds ) {
		return array( 'tenant_id' => isset( $creds['tenant_id'] ) ? $creds['tenant_id'] : '' );
	}

	/**
	 * Summary.
	 *
	 * @param array $conn Connection.
	 * @return string
	 */
	public function summary( array $conn ) {
		return isset( $conn['credentials']['client_id'] ) ? 'App ' . substr( (string) $conn['credentials']['client_id'], 0, 8 ) . '…' : '';
	}

	/**
	 * Test.
	 *
	 * @param array $creds Credentials.
	 * @return string
	 */
	public function test( array $creds ) {
		$token = self::token( $creds, true );
		try {
			$org = self::graph( 'GET', 'organization?$select=displayName', $token );
			if ( ! empty( $org['value'][0]['displayName'] ) ) {
				/* translators: %s: organization */
				return sprintf( __( 'Connected to %s.', 'brn-webhooks' ), $org['value'][0]['displayName'] );
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Organization.Read is optional.
		}
		return __( 'Authenticated with Microsoft Graph.', 'brn-webhooks' );
	}

	/**
	 * App-only access token.
	 *
	 * @param array $creds Credentials.
	 * @param bool  $fresh Skip cache.
	 * @return string
	 * @throws Action_Error On failure.
	 */
	public static function token( array $creds, $fresh = false ) {
		foreach ( array( 'tenant_id', 'client_id', 'client_secret' ) as $k ) {
			if ( empty( $creds[ $k ] ) ) {
				throw new Action_Error( __( 'Microsoft connection is incomplete.', 'brn-webhooks' ), false );
			}
		}
		$cache_key = 'brnwh_mtok_' . md5( $creds['tenant_id'] . $creds['client_id'] . $creds['client_secret'] );
		if ( ! $fresh ) {
			$cached = get_transient( $cache_key );
			$plain  = $cached ? Crypto::decrypt( $cached ) : '';
			if ( $plain ) {
				return $plain;
			}
		}
		$res = Http::request(
			'POST',
			'https://login.microsoftonline.com/' . rawurlencode( $creds['tenant_id'] ) . '/oauth2/v2.0/token',
			array(
				'body'          => array(
					'client_id'     => $creds['client_id'],
					'client_secret' => $creds['client_secret'],
					'scope'         => 'https://graph.microsoft.com/.default',
					'grant_type'    => 'client_credentials',
				),
				'label'         => 'Microsoft OAuth',
				'redact_body'   => true,
				'redact_response' => true,
				'fail_on_error' => false,
			)
		);
		if ( empty( $res['json']['access_token'] ) ) {
			$err = isset( $res['json']['error_description'] ) ? strtok( $res['json']['error_description'], "\r\n" ) : Http::error_detail( $res );
			/* translators: %s: error */
			throw new Action_Error( sprintf( __( 'Microsoft authentication failed: %s', 'brn-webhooks' ), $err ), $res['code'] >= 500 );
		}
		$ttl = isset( $res['json']['expires_in'] ) ? max( 60, (int) $res['json']['expires_in'] - 300 ) : 3000;
		set_transient( $cache_key, Crypto::encrypt( $res['json']['access_token'] ), $ttl );
		return $res['json']['access_token'];
	}

	/**
	 * Graph call.
	 *
	 * @param string $method Method.
	 * @param string $path   Path after /v1.0/.
	 * @param string $token  Token.
	 * @param mixed  $json   JSON body.
	 * @return array
	 * @throws Action_Error On failure.
	 */
	public static function graph( $method, $path, $token, $json = null ) {
		$opts = array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			'label'   => 'Microsoft Graph',
			'timeout' => 30,
		);
		if ( null !== $json ) {
			$opts['json'] = $json;
		}
		$res = Http::request( $method, 'https://graph.microsoft.com/v1.0/' . ltrim( $path, '/' ), $opts );
		return is_array( $res['json'] ) ? $res['json'] : array();
	}
}
