<?php
/**
 * Public endpoint for incoming webhooks.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Routes /wp-json/brn-webhooks/v1/hook/{key} to the flow's trigger.
 *
 * The route is public by design (third parties call it); access is controlled by the unguessable
 * key, optional token / HMAC / basic auth, IP allowlist, rate limiting and payload size limits.
 */
class Incoming {

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'application_password_is_api_request', array( $this, 'skip_app_passwords' ) );
	}

	/**
	 * WordPress tries any Basic Authorization header as an Application Password on REST requests
	 * and rejects unknown users before our route runs. Our webhook URLs use their own Basic auth.
	 *
	 * @param bool $is_api Is API request.
	 * @return bool
	 */
	public function skip_app_passwords( $is_api ) {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( false !== strpos( $uri . ' ' . rawurldecode( $route ), Rest::NS . '/hook/' ) ) {
			return false;
		}
		return $is_api;
	}

	/**
	 * Public URL of a flow endpoint.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function url( $key ) {
		return $key ? rest_url( Rest::NS . '/hook/' . $key ) : '';
	}

	/**
	 * Register route.
	 */
	public function routes() {
		register_rest_route(
			Rest::NS,
			'/hook/(?P<key>[a-zA-Z0-9]{16,64})',
			array(
				'methods'             => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Public by design; authenticated inside handle().
				'args'                => array(
					'key' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Handle a call.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$flow = Flows::find_by_hook_key( (string) $request['key'] );
		if ( ! $flow ) {
			return self::error( 404, 'not_found', 'Unknown endpoint.' );
		}
		$trigger = Registry::trigger( $flow['trigger_type'] );
		if ( ! $trigger || ! $trigger->has_endpoint() || ! method_exists( $trigger, 'handle_request' ) ) {
			return self::error( 404, 'not_found', 'Unknown endpoint.' );
		}

		// Rate limit per flow per minute.
		$limit  = (int) Settings::get( 'rate_limit' );
		$bucket = 'brnwh_rl_' . $flow['id'] . '_' . gmdate( 'YmdHi' );
		$count  = (int) get_transient( $bucket );
		if ( $limit > 0 && $count >= $limit ) {
			$response = self::error( 429, 'rate_limited', 'Too many requests.' );
			$response->header( 'Retry-After', '60' );
			return $response;
		}
		set_transient( $bucket, $count + 1, 120 );

		// Payload size.
		$max = (int) Settings::get( 'max_payload_kb' ) * 1024;
		if ( strlen( (string) $request->get_body() ) > $max ) {
			return self::error( 413, 'too_large', 'Payload too large.' );
		}

		return $trigger->handle_request( $flow, $request );
	}

	/**
	 * JSON error response.
	 *
	 * @param int    $code    HTTP code.
	 * @param string $error   Error code.
	 * @param string $message Message.
	 * @return \WP_REST_Response
	 */
	public static function error( $code, $error, $message ) {
		return new \WP_REST_Response(
			array(
				'ok'      => false,
				'error'   => $error,
				'message' => $message,
			),
			$code
		);
	}

	/**
	 * Send a raw (non-JSON) response and stop.
	 *
	 * @param int    $code         HTTP code.
	 * @param string $body         Body.
	 * @param string $content_type Content type.
	 */
	public static function raw( $code, $body, $content_type = 'text/plain' ) {
		status_header( (int) $code );
		header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: sandbox; default-src 'none'" );
		header( "Content-Security-Policy: sandbox; default-src 'none'" );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw API response, content type set explicitly.
		exit;
	}

	/**
	 * Parse request data into an array: body fields at the root, plus _request info.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function parse( \WP_REST_Request $request, array $drop_headers = array() ) {
		$drop_headers = array_map(
			static function ( $h ) {
				return strtolower( str_replace( '_', '-', (string) $h ) );
			},
			$drop_headers
		);
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || null === $body ) {
			$body = $request->get_body_params();
		}
		$raw = (string) $request->get_body();
		if ( ( ! is_array( $body ) || ! $body ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$body = $decoded;
			} else {
				parse_str( $raw, $parsed );
				$body = ( $parsed && false === strpos( $raw, ' ' ) && false !== strpos( $raw, '=' ) ) ? $parsed : array( 'raw' => $raw );
			}
		}
		$body = is_array( $body ) ? $body : array();
		// A JSON list at the root becomes {items: [...]}.
		if ( $body && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			$body = array( 'items' => $body );
		}
		$query = $request->get_query_params();
		unset( $query['rest_route'] );

		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$name = strtolower( str_replace( '_', '-', $name ) );
			if ( in_array( $name, $drop_headers, true ) || preg_match( '/(authorization|cookie|token|secret|signature|key|pass|auth)/', $name ) ) {
				continue;
			}
			$headers[ $name ] = is_array( $values ) ? implode( ', ', $values ) : (string) $values;
		}

		// For GET requests without a body, the query string is the data.
		if ( ! $body && 'GET' === $request->get_method() ) {
			$body  = $query;
			unset( $body['token'] );
		}

		$data             = Util::clean_deep( $body );
		$data['_request'] = array(
			'method'     => $request->get_method(),
			'query'      => Util::clean_deep( array_diff_key( $query, array( 'token' => 1 ) ) ),
			'headers'    => $headers,
			'ip'         => Util::client_ip(),
			'received_at' => gmdate( 'c' ),
		);
		return $data;
	}
}
