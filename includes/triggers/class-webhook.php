<?php
/**
 * Incoming webhook trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Engine;
use BRN\Webhooks\Flows;
use BRN\Webhooks\Incoming;
use BRN\Webhooks\Logs;
use BRN\Webhooks\Template;
use BRN\Webhooks\Trigger;
use BRN\Webhooks\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Receives HTTP calls from any system (CRMs, Make, Zapier, payment providers, custom code).
 */
class Webhook extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'webhook';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Incoming webhook', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Get a unique URL. Any system that sends data to it starts the flow.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return __( 'Webhooks', 'brn-webhooks' );
	}

	/**
	 * Endpoint.
	 *
	 * @return bool
	 */
	public function has_endpoint() {
		return true;
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'   => '_endpoint',
				'type'  => 'endpoint',
				'label' => __( 'Your webhook URL', 'brn-webhooks' ),
				'help'  => __( 'Send POST requests with JSON or form data to this URL. Keep it private — anyone with the URL can call it unless you add authentication below.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'methods',
				'type'    => 'select',
				'label'   => __( 'Accepted methods', 'brn-webhooks' ),
				'default' => 'post',
				'options' => self::options(
					array(
						'post' => __( 'POST only', 'brn-webhooks' ),
						'any'  => __( 'GET, POST, PUT, PATCH', 'brn-webhooks' ),
					)
				),
			),
			array(
				'key'   => '_h_security',
				'type'  => 'heading',
				'label' => __( 'Security', 'brn-webhooks' ),
			),
			array(
				'key'     => 'auth',
				'type'    => 'choice',
				'label'   => __( 'Authentication', 'brn-webhooks' ),
				'default' => 'none',
				'options' => self::options(
					array(
						'none'  => __( 'None (URL only)', 'brn-webhooks' ),
						'token' => __( 'Secret token', 'brn-webhooks' ),
						'hmac'  => __( 'HMAC signature', 'brn-webhooks' ),
						'basic' => __( 'Basic auth', 'brn-webhooks' ),
					)
				),
			),
			array(
				'key'         => 'token_header',
				'type'        => 'text',
				'label'       => __( 'Header name', 'brn-webhooks' ),
				'default'     => 'X-Webhook-Token',
				'show_if'     => array( 'auth' => 'token' ),
				'help'        => __( 'The token may also be sent as ?token=… in the URL.', 'brn-webhooks' ),
			),
			array(
				'key'      => 'token',
				'type'     => 'password',
				'label'    => __( 'Token', 'brn-webhooks' ),
				'secret'   => true,
				'generate' => true,
				'show_if'  => array( 'auth' => 'token' ),
			),
			array(
				'key'     => 'hmac_header',
				'type'    => 'text',
				'label'   => __( 'Signature header', 'brn-webhooks' ),
				'default' => 'X-Signature',
				'show_if' => array( 'auth' => 'hmac' ),
			),
			array(
				'key'      => 'hmac_secret',
				'type'     => 'password',
				'label'    => __( 'Signing secret', 'brn-webhooks' ),
				'secret'   => true,
				'generate' => true,
				'show_if'  => array( 'auth' => 'hmac' ),
			),
			array(
				'key'     => 'hmac_algo',
				'type'    => 'select',
				'label'   => __( 'Algorithm', 'brn-webhooks' ),
				'default' => 'sha256',
				'options' => self::options(
					array(
						'sha256' => 'HMAC-SHA256',
						'sha1'   => 'HMAC-SHA1',
						'sha512' => 'HMAC-SHA512',
					)
				),
				'show_if' => array( 'auth' => 'hmac' ),
			),
			array(
				'key'     => 'hmac_encoding',
				'type'    => 'select',
				'label'   => __( 'Encoding', 'brn-webhooks' ),
				'default' => 'hex',
				'options' => self::options(
					array(
						'hex'    => 'Hex',
						'base64' => 'Base64',
					)
				),
				'show_if' => array( 'auth' => 'hmac' ),
			),
			array(
				'key'         => 'hmac_prefix',
				'type'        => 'text',
				'label'       => __( 'Signature prefix (optional)', 'brn-webhooks' ),
				'placeholder' => 'sha256=',
				'show_if'     => array( 'auth' => 'hmac' ),
				'help'        => __( 'The signature is computed over the raw request body.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'basic_user',
				'type'    => 'text',
				'label'   => __( 'Username', 'brn-webhooks' ),
				'show_if' => array( 'auth' => 'basic' ),
			),
			array(
				'key'      => 'basic_pass',
				'type'     => 'password',
				'label'    => __( 'Password', 'brn-webhooks' ),
				'secret'   => true,
				'generate' => true,
				'show_if'  => array( 'auth' => 'basic' ),
			),
			array(
				'key'         => 'ip_allowlist',
				'type'        => 'textarea',
				'rows'        => 2,
				'mono'        => true,
				'label'       => __( 'Allowed IPs (optional)', 'brn-webhooks' ),
				'placeholder' => "203.0.113.10\n198.51.100.0/24",
				'help'        => __( 'One IP or CIDR range per line. Leave empty to allow any IP.', 'brn-webhooks' ),
			),
			array(
				'key'   => '_h_response',
				'type'  => 'heading',
				'label' => __( 'Response', 'brn-webhooks' ),
			),
			array(
				'key'     => 'response',
				'type'    => 'choice',
				'label'   => __( 'Respond with', 'brn-webhooks' ),
				'default' => 'default',
				'options' => self::options(
					array(
						'default'   => __( 'Accepted (fast)', 'brn-webhooks' ),
						'custom'    => __( 'Custom response', 'brn-webhooks' ),
						'last_step' => __( 'Result of the last step', 'brn-webhooks' ),
					)
				),
				'help'    => __( '"Result of the last step" waits for the flow to finish and returns its output — useful to build a small API.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'response_code',
				'type'    => 'number',
				'label'   => __( 'Status code', 'brn-webhooks' ),
				'default' => 200,
				'show_if' => array( 'response' => 'custom' ),
			),
			array(
				'key'     => 'response_type',
				'type'    => 'select',
				'label'   => __( 'Content type', 'brn-webhooks' ),
				'default' => 'application/json',
				'options' => self::options(
					array(
						'application/json' => 'application/json',
						'text/plain'       => 'text/plain',
						'text/html'        => 'text/html',
						'application/xml'  => 'application/xml',
					)
				),
				'show_if' => array( 'response' => 'custom' ),
			),
			array(
				'key'         => 'response_body',
				'type'        => 'code',
				'rows'        => 4,
				'template'    => true,
				'label'       => __( 'Body', 'brn-webhooks' ),
				'placeholder' => '{"ok": true, "id": "{{_run.id}}"}',
				'show_if'     => array( 'response' => 'custom' ),
			),
		);
	}

	/**
	 * Handle the HTTP call.
	 *
	 * @param array            $flow    Flow.
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle_request( array $flow, \WP_REST_Request $request ) {
		$c      = $flow['trigger_config'];
		$method = $request->get_method();

		if ( ( empty( $c['methods'] ) || 'post' === $c['methods'] ) && 'POST' !== $method ) {
			return Incoming::error( 405, 'method_not_allowed', 'Only POST is accepted.' );
		}
		if ( 'DELETE' === $method ) {
			return Incoming::error( 405, 'method_not_allowed', 'Method not allowed.' );
		}
		if ( ! empty( $c['ip_allowlist'] ) && ! Util::ip_allowed( Util::client_ip( true ), $c['ip_allowlist'] ) ) {
			return Incoming::error( 403, 'forbidden', 'IP not allowed.' );
		}
		$auth = $this->authenticate( $c, $request );
		if ( true !== $auth ) {
			return Incoming::error( 401, 'unauthorized', $auth );
		}

		$data      = Incoming::parse( $request, array( isset( $c['token_header'] ) ? $c['token_header'] : '', isset( $c['hmac_header'] ) ? $c['hmac_header'] : '' ) );
		$listening = Flows::is_listening( $flow['id'] );
		if ( $listening || empty( $flow['sample']['data'] ) ) {
			Flows::save_sample( $flow['id'], $data );
		}
		if ( 'active' !== $flow['status'] ) {
			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'status'   => 'paused',
					'captured' => $listening,
				),
				200
			);
		}

		$mode = isset( $c['response'] ) ? $c['response'] : 'default';
		$sync = 'last_step' === $mode;
		$run  = Engine::dispatch( $flow, $data, array( 'source' => 'webhook', 'ip' => Util::client_ip() ), $sync );

		if ( 'last_step' === $mode ) {
			$log    = Engine::result( $run );
			$output = null;
			if ( $log && ! empty( $log['steps'] ) ) {
				$last   = end( $log['steps'] );
				$output = isset( $last['output'] ) ? $last['output'] : null;
			}
			$ok = $log && in_array( $log['status'], array( 'success', 'filtered' ), true );
			return new \WP_REST_Response(
				array(
					'ok'     => $ok,
					'run_id' => $run,
					'status' => $log ? $log['status'] : 'unknown',
					'result' => $output,
				),
				$ok ? 200 : 502
			);
		}
		if ( 'custom' === $mode ) {
			$vars = array_merge(
				$data,
				array(
					'_run'  => array( 'id' => $run ),
					'_flow' => array(
						'id'   => $flow['id'],
						'name' => $flow['name'],
					),
				)
			);
			$type = isset( $c['response_type'] ) ? $c['response_type'] : 'application/json';
			$esc  = 'application/json' === $type ? 'json' : ( 'text/plain' === $type ? '' : 'html' );
			$body = Template::render( isset( $c['response_body'] ) ? (string) $c['response_body'] : '', $vars, $esc );
			$code = isset( $c['response_code'] ) ? max( 100, min( 599, (int) $c['response_code'] ) ) : 200;
			$allowed = array( 'application/json', 'text/plain', 'text/html', 'application/xml' );
			Incoming::raw( $code, $body, in_array( $type, $allowed, true ) ? $type : 'text/plain' );
		}
		return new \WP_REST_Response(
			array(
				'ok'     => true,
				'run_id' => $run,
			),
			200
		);
	}

	/**
	 * Check authentication. Returns true or an error message.
	 *
	 * @param array            $c       Config.
	 * @param \WP_REST_Request $request Request.
	 * @return true|string
	 */
	private function authenticate( array $c, \WP_REST_Request $request ) {
		$mode = isset( $c['auth'] ) ? $c['auth'] : 'none';
		switch ( $mode ) {
			case 'token':
				$expected = isset( $c['token'] ) ? (string) $c['token'] : '';
				$header   = isset( $c['token_header'] ) && '' !== $c['token_header'] ? $c['token_header'] : 'X-Webhook-Token';
				$given    = (string) $request->get_header( $header );
				if ( '' === $given ) {
					$bearer = (string) $request->get_header( 'authorization' );
					if ( 0 === stripos( $bearer, 'Bearer ' ) ) {
						$given = trim( substr( $bearer, 7 ) );
					}
				}
				if ( '' === $given ) {
					$q     = $request->get_query_params();
					$given = isset( $q['token'] ) ? (string) $q['token'] : '';
				}
				return ( '' !== $expected && hash_equals( $expected, $given ) ) ? true : 'Invalid token.';

			case 'hmac':
				$secret = isset( $c['hmac_secret'] ) ? (string) $c['hmac_secret'] : '';
				$header = isset( $c['hmac_header'] ) && '' !== $c['hmac_header'] ? $c['hmac_header'] : 'X-Signature';
				$algo   = isset( $c['hmac_algo'] ) && in_array( $c['hmac_algo'], array( 'sha1', 'sha256', 'sha512' ), true ) ? $c['hmac_algo'] : 'sha256';
				$given  = trim( (string) $request->get_header( $header ) );
				$prefix = isset( $c['hmac_prefix'] ) ? (string) $c['hmac_prefix'] : '';
				if ( '' !== $prefix && 0 === strpos( $given, $prefix ) ) {
					$given = substr( $given, strlen( $prefix ) );
				}
				$raw      = hash_hmac( $algo, (string) $request->get_body(), $secret, true );
				$expected = ( isset( $c['hmac_encoding'] ) && 'base64' === $c['hmac_encoding'] ) ? base64_encode( $raw ) : bin2hex( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$cmp      = 'hex' === ( isset( $c['hmac_encoding'] ) ? $c['hmac_encoding'] : 'hex' ) ? strtolower( $given ) : $given;
				return ( '' !== $secret && '' !== $given && hash_equals( $expected, $cmp ) ) ? true : 'Invalid signature.';

			case 'basic':
				$user = isset( $c['basic_user'] ) ? (string) $c['basic_user'] : '';
				$pass = isset( $c['basic_pass'] ) ? (string) $c['basic_pass'] : '';
				$hdr  = (string) $request->get_header( 'authorization' );
				if ( 0 !== stripos( $hdr, 'Basic ' ) ) {
					return 'Basic authentication required.';
				}
				$decoded = base64_decode( trim( substr( $hdr, 6 ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
					return 'Invalid credentials.';
				}
				list( $u, $p ) = explode( ':', $decoded, 2 );
				return ( '' !== $pass && hash_equals( $user, $u ) && hash_equals( $pass, $p ) ) ? true : 'Invalid credentials.';
		}
		return true;
	}
}
