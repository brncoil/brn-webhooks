<?php
/**
 * Outgoing HTTP.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps wp_safe_remote_request (blocks requests to private / loopback addresses unless allowed),
 * records a redacted trace of every call for the run log.
 */
class Http {

	/**
	 * Traces recorded during the current step.
	 *
	 * @var array
	 */
	private static $traces = array();

	/**
	 * Reset traces.
	 */
	public static function reset_traces() {
		self::$traces = array();
	}

	/**
	 * Get traces.
	 *
	 * @return array
	 */
	public static function traces() {
		return self::$traces;
	}

	/**
	 * Send a request.
	 *
	 * @param string $method  Method.
	 * @param string $url     URL.
	 * @param array  $options headers, body (string|array), json (mixed), query (array), timeout, fail_on_error, label, redact (string[]).
	 * @return array {code, headers, body, json}
	 * @throws Action_Error On transport errors or HTTP errors.
	 */
	public static function request( $method, $url, array $options = array() ) {
		$method  = strtoupper( $method ? $method : 'GET' );
		$headers = array();
		foreach ( isset( $options['headers'] ) ? (array) $options['headers'] : array() as $hk => $hv ) {
			$hk = trim( preg_replace( '/[\r\n:]+/', '', (string) $hk ) );
			if ( '' !== $hk ) {
				$headers[ $hk ] = preg_replace( '/[\r\n]+/', ' ', (string) $hv );
			}
		}
		$body    = null;

		if ( ! empty( $options['query'] ) && is_array( $options['query'] ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $options['query'] ) ), $url );
		}
		if ( array_key_exists( 'json', $options ) ) {
			$body = wp_json_encode( $options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( ! self::has_header( $headers, 'content-type' ) ) {
				$headers['Content-Type'] = 'application/json; charset=utf-8';
			}
		} elseif ( isset( $options['body'] ) ) {
			$body = $options['body'];
		}
		if ( ! self::has_header( $headers, 'user-agent' ) ) {
			$headers['User-Agent'] = 'BRN-Webhooks/' . BRNWH_VERSION . ' (+' . home_url( '/' ) . ')';
		}

		if ( ! wp_http_validate_url( $url ) && ! Settings::get( 'allow_internal_urls' ) ) {
			self::record( $method, $url, $headers, $body, null, 0, __( 'URL is invalid or points to a private network address.', 'brn-webhooks' ), $options );
			throw new Action_Error( __( 'URL is invalid or points to a private network address. Enable "Allow internal URLs" in settings if this is intended.', 'brn-webhooks' ), false );
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => isset( $options['timeout'] ) ? max( 1, min( 60, (int) $options['timeout'] ) ) : 20,
			'redirection' => isset( $options['redirection'] ) ? (int) $options['redirection'] : 3,
			'sslverify'   => true,
		);
		if ( null !== $body && 'GET' !== $method ) {
			$args['body'] = $body;
		}

		$start    = microtime( true );
		$response = Settings::get( 'allow_internal_urls' ) ? wp_remote_request( $url, $args ) : wp_safe_remote_request( $url, $args );
		$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			self::record( $method, $url, $headers, $body, null, $ms, $response->get_error_message(), $options );
			throw new Action_Error( $response->get_error_message(), true );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $raw, true );
		$result = array(
			'code'    => $code,
			'headers' => self::response_headers( $response ),
			'body'    => $raw,
			'json'    => ( null === $json && 'null' !== trim( $raw ) ) ? null : $json,
		);
		self::record( $method, $url, $headers, $body, $result, $ms, null, $options );

		$fail = ! isset( $options['fail_on_error'] ) || $options['fail_on_error'];
		if ( $fail && ( $code < 200 || $code >= 300 ) ) {
			$detail = self::error_detail( $result );
			/* translators: 1: HTTP status code, 2: error detail */
			$msg = sprintf( __( 'HTTP %1$d: %2$s', 'brn-webhooks' ), $code, $detail );
			// 408, 425, 429 and 5xx are worth retrying; other 4xx are permanent.
			$retry = $code >= 500 || in_array( $code, array( 0, 408, 425, 429 ), true );
			throw new Action_Error( $msg, $retry );
		}
		return $result;
	}

	/**
	 * Extract a readable error from an API response.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	public static function error_detail( array $result ) {
		$j = $result['json'];
		if ( is_array( $j ) ) {
			if ( isset( $j['error']['message'] ) ) {
				return (string) $j['error']['message'];
			}
			if ( isset( $j['error_description'] ) ) {
				return (string) $j['error_description'];
			}
			if ( isset( $j['message'] ) && is_string( $j['message'] ) ) {
				return $j['message'];
			}
			if ( isset( $j['error'] ) && is_string( $j['error'] ) ) {
				return $j['error'];
			}
			if ( isset( $j['description'] ) && is_string( $j['description'] ) ) {
				return $j['description'];
			}
		}
		$text = trim( wp_strip_all_tags( (string) $result['body'] ) );
		return '' === $text ? get_status_header_desc( $result['code'] ) : Util::truncate( $text, 300 );
	}

	/**
	 * Case-insensitive header check.
	 *
	 * @param array  $headers Headers.
	 * @param string $name    Name.
	 * @return bool
	 */
	private static function has_header( array $headers, $name ) {
		foreach ( $headers as $k => $v ) {
			if ( strtolower( (string) $k ) === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Response headers as array.
	 *
	 * @param array $response Response.
	 * @return array
	 */
	private static function response_headers( $response ) {
		$out     = array();
		$headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}
		foreach ( (array) $headers as $k => $v ) {
			$out[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
		return $out;
	}

	/**
	 * Mask secrets in URLs and headers.
	 *
	 * @param string $s       String.
	 * @param array  $secrets Extra secrets.
	 * @return string
	 */
	private static function mask( $s, array $secrets ) {
		$s = (string) $s;
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) >= 6 ) {
				$s = str_replace( $secret, substr( $secret, 0, 3 ) . '•••', $s );
			}
		}
		// Common token query params and JSON token fields.
		$s = preg_replace( '/([?&](access_token|token|key|api_key|apikey|secret|client_secret|assertion)=)[^&#\s"]+/i', '$1•••', $s );
		return preg_replace( '/("(access_token|refresh_token|id_token|client_secret|private_key)"\s*:\s*")[^"]*"/i', '$1•••"', $s );
	}

	/**
	 * Store a trace.
	 *
	 * @param string      $method  Method.
	 * @param string      $url     URL.
	 * @param array       $headers Headers.
	 * @param mixed       $body    Body.
	 * @param array|null  $result  Result.
	 * @param int         $ms      Duration.
	 * @param string|null $error   Error.
	 * @param array       $options Options.
	 */
	private static function record( $method, $url, array $headers, $body, $result, $ms, $error, array $options ) {
		$secrets = isset( $options['redact'] ) ? (array) $options['redact'] : array();
		$safe_h  = array();
		foreach ( $headers as $k => $v ) {
			$lk             = strtolower( (string) $k );
			$safe_h[ $k ]   = preg_match( '/^(authorization|x-api-key|api-key|x-auth-token|cookie|proxy-authorization)$/', $lk ) || preg_match( '/(token|secret|key|signature)/', $lk )
				? ( 0 === stripos( (string) $v, 'Bearer ' ) ? 'Bearer ' : ( 0 === stripos( (string) $v, 'Basic ' ) ? 'Basic ' : '' ) ) . '•••'
				: self::mask( $v, $secrets );
		}
		$body_s = is_array( $body ) ? http_build_query( $body ) : (string) $body;
		if ( ! empty( $options['redact_body'] ) ) {
			$body_s = '•••';
		}
		$trace = array(
			'label'       => isset( $options['label'] ) ? (string) $options['label'] : '',
			'method'      => $method,
			'url'         => self::mask( $url, $secrets ),
			'headers'     => $safe_h,
			'body'        => Util::truncate( self::mask( $body_s, $secrets ), 10000 ),
			'duration_ms' => $ms,
		);
		if ( $result ) {
			$trace['response'] = array(
				'code'    => $result['code'],
				'headers' => array_intersect_key( $result['headers'], array_flip( array( 'content-type', 'content-length', 'x-request-id', 'x-fb-trace-id', 'request-id', 'retry-after' ) ) ),
				'body'    => ! empty( $options['redact_response'] ) && $result['code'] >= 200 && $result['code'] < 300 ? '•••' : Util::truncate( self::mask( $result['body'], $secrets ), 10000 ),
			);
		}
		if ( $error ) {
			$trace['error'] = $error;
		}
		self::$traces[] = $trace;
	}
}
