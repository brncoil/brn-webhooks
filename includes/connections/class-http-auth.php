<?php
/**
 * Generic API credentials.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Connections;

use BRN\Webhooks\Connection_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Bearer token / Basic auth / API key header or query parameter for HTTP requests.
 */
class Http_Auth extends Connection_Type {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'http_auth';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'API credentials', 'brn-webhooks' );
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'key';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'     => 'auth_type',
				'type'    => 'choice',
				'label'   => __( 'Type', 'brn-webhooks' ),
				'default' => 'bearer',
				'options' => self::options(
					array(
						'bearer' => __( 'Bearer token', 'brn-webhooks' ),
						'basic'  => __( 'Basic auth', 'brn-webhooks' ),
						'header' => __( 'API key header', 'brn-webhooks' ),
						'query'  => __( 'API key in URL', 'brn-webhooks' ),
					)
				),
			),
			array(
				'key'     => 'token',
				'type'    => 'password',
				'label'   => __( 'Token', 'brn-webhooks' ),
				'secret'  => true,
				'show_if' => array( 'auth_type' => 'bearer' ),
			),
			array(
				'key'     => 'username',
				'type'    => 'text',
				'label'   => __( 'Username', 'brn-webhooks' ),
				'show_if' => array( 'auth_type' => 'basic' ),
			),
			array(
				'key'     => 'password',
				'type'    => 'password',
				'label'   => __( 'Password', 'brn-webhooks' ),
				'secret'  => true,
				'show_if' => array( 'auth_type' => 'basic' ),
			),
			array(
				'key'         => 'key_name',
				'type'        => 'text',
				'label'       => __( 'Header / parameter name', 'brn-webhooks' ),
				'placeholder' => 'X-API-Key',
				'show_if'     => array( 'auth_type' => array( 'header', 'query' ) ),
			),
			array(
				'key'     => 'key_value',
				'type'    => 'password',
				'label'   => __( 'API key', 'brn-webhooks' ),
				'secret'  => true,
				'show_if' => array( 'auth_type' => array( 'header', 'query' ) ),
			),
		);
	}

	/**
	 * Help.
	 *
	 * @return string
	 */
	public function help() {
		return '<p>' . __( 'Store API keys once and reuse them in any "HTTP request" step. The key is encrypted and never shown again.', 'brn-webhooks' ) . '</p>';
	}

	/**
	 * Summary.
	 *
	 * @param array $conn Connection.
	 * @return string
	 */
	public function summary( array $conn ) {
		$t = isset( $conn['credentials']['auth_type'] ) ? $conn['credentials']['auth_type'] : 'bearer';
		$m = array(
			'bearer' => 'Bearer',
			'basic'  => 'Basic',
			'header' => isset( $conn['credentials']['key_name'] ) ? $conn['credentials']['key_name'] : 'Header',
			'query'  => '?' . ( isset( $conn['credentials']['key_name'] ) ? $conn['credentials']['key_name'] : 'key' ),
		);
		return isset( $m[ $t ] ) ? $m[ $t ] : '';
	}

	/**
	 * Test.
	 *
	 * @param array $creds Credentials.
	 * @return string
	 */
	public function test( array $creds ) {
		return __( 'Saved. These credentials are tested when a step uses them.', 'brn-webhooks' );
	}

	/**
	 * Apply credentials to request headers / query.
	 *
	 * @param array $creds   Credentials.
	 * @param array $headers Headers (by ref).
	 * @param array $query   Query (by ref).
	 * @return string[] Secrets to redact.
	 */
	public static function apply( array $creds, array &$headers, array &$query ) {
		$type = isset( $creds['auth_type'] ) ? $creds['auth_type'] : 'bearer';
		switch ( $type ) {
			case 'basic':
				$headers['Authorization'] = 'Basic ' . base64_encode( ( isset( $creds['username'] ) ? $creds['username'] : '' ) . ':' . ( isset( $creds['password'] ) ? $creds['password'] : '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				return array( isset( $creds['password'] ) ? $creds['password'] : '' );
			case 'header':
				$name             = ! empty( $creds['key_name'] ) ? $creds['key_name'] : 'X-API-Key';
				$headers[ $name ] = isset( $creds['key_value'] ) ? $creds['key_value'] : '';
				return array( $headers[ $name ] );
			case 'query':
				$name           = ! empty( $creds['key_name'] ) ? $creds['key_name'] : 'key';
				$query[ $name ] = isset( $creds['key_value'] ) ? $creds['key_value'] : '';
				return array( $query[ $name ] );
			default:
				$headers['Authorization'] = 'Bearer ' . ( isset( $creds['token'] ) ? $creds['token'] : '' );
				return array( isset( $creds['token'] ) ? $creds['token'] : '' );
		}
	}
}
