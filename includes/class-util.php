<?php
/**
 * Helpers.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Small shared helpers.
 */
class Util {

	/**
	 * Current UTC time as MySQL datetime.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + (int) $offset );
	}

	/**
	 * UTC MySQL datetime to ISO-8601 for the UI.
	 *
	 * @param string|null $dt Datetime.
	 * @return string|null
	 */
	public static function iso( $dt ) {
		return $dt ? str_replace( ' ', 'T', $dt ) . 'Z' : null;
	}

	/**
	 * JSON encode for storage.
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function json( $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return false === $json ? '' : $json;
	}

	/**
	 * JSON decode to array.
	 *
	 * @param string|null $json    JSON.
	 * @param mixed       $default Default.
	 * @return mixed
	 */
	public static function unjson( $json, $default = array() ) {
		if ( null === $json || '' === $json ) {
			return $default;
		}
		$data = json_decode( $json, true );
		return null === $data ? $default : $data;
	}

	/**
	 * Recursively clean user-supplied structures (strings, numbers, bools, arrays only).
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @param int   $max   Max string bytes.
	 * @return mixed
	 */
	public static function clean_deep( $value, $depth = 0, $max = 65535 ) {
		if ( $depth > 10 ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out = array();
			$i   = 0;
			foreach ( $value as $k => $v ) {
				if ( ++$i > 1000 ) {
					break;
				}
				$key         = is_int( $k ) ? $k : substr( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $k ), 0, 190 );
				$out[ $key ] = self::clean_deep( $v, $depth + 1, $max );
			}
			return $out;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			return self::clean_deep( (array) $value, $depth, $max );
		}
		$s = wp_check_invalid_utf8( (string) $value, true );
		$s = str_replace( "\0", '', $s );
		if ( strlen( $s ) > $max ) {
			$s = function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, $max ) : substr( $s, 0, $max );
		}
		return $s;
	}

	/**
	 * Client IP.
	 *
	 * For security decisions (IP allowlists) proxy headers are only trusted when the
	 * "trust proxy headers" setting is on, because they can be forged by any client.
	 * For informational use (Meta CAPI, logs) the forwarded address is preferred.
	 *
	 * @param bool $for_security Used for an access decision.
	 * @return string
	 */
	public static function client_ip( $for_security = false ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( $for_security ) {
			if ( ! Settings::get( 'trust_proxy' ) ) {
				return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
			}
			// Cloudflare's header is only believable when the connection comes from Cloudflare.
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::ip_allowed( $ip, implode( "\n", self::cloudflare_ranges() ) ) ) {
				$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
				if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
					return $cf;
				}
			}
			// The rightmost X-Forwarded-For entry was added by the proxy in front of us.
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$parts = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ) );
				$last  = end( $parts );
				if ( filter_var( $last, FILTER_VALIDATE_IP ) ) {
					return $last;
				}
			}
			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}
		$headers = apply_filters( 'brnwh_trusted_ip_headers', array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) );
		foreach ( (array) $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$candidate = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					return $candidate;
				}
			}
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Cloudflare edge ranges (https://www.cloudflare.com/ips/).
	 *
	 * @return string[]
	 */
	public static function cloudflare_ranges() {
		return apply_filters(
			'brnwh_cloudflare_ranges',
			array(
				'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
				'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
				'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
				'2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
			)
		);
	}

	/**
	 * Request context collected for form triggers (used by Meta CAPI etc.).
	 *
	 * @param array $extra Extra.
	 * @return array
	 */
	public static function request_meta( array $extra = array() ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$cookie  = static function ( $k ) {
			return isset( $_COOKIE[ $k ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $k ] ) ) : '';
		};
		$referer = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
		if ( '' === $referer && isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}
		$meta = array(
			'page_url'     => $referer,
			'ip'           => self::client_ip(),
			'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 500 ) : '',
			'fbp'          => $cookie( '_fbp' ),
			'fbc'          => $cookie( '_fbc' ),
			'user_id'      => get_current_user_id(),
			'submitted_at' => gmdate( 'c' ),
		);
		// Build fbc from fbclid when the cookie is missing.
		if ( '' === $meta['fbc'] && $referer ) {
			$q = wp_parse_url( $referer, PHP_URL_QUERY );
			if ( $q ) {
				parse_str( $q, $qs );
				if ( ! empty( $qs['fbclid'] ) ) {
					$meta['fbc'] = 'fb.1.' . ( time() * 1000 ) . '.' . sanitize_text_field( $qs['fbclid'] );
				}
			}
		}
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid' ) as $utm ) {
			$meta[ $utm ] = '';
			if ( $referer ) {
				$q = wp_parse_url( $referer, PHP_URL_QUERY );
				if ( $q ) {
					parse_str( $q, $qs );
					if ( ! empty( $qs[ $utm ] ) ) {
						$meta[ $utm ] = sanitize_text_field( $qs[ $utm ] );
					}
				}
			}
		}
		// phpcs:enable
		return array_merge( $meta, $extra );
	}

	/**
	 * Short unique id.
	 *
	 * @return string
	 */
	public static function uid() {
		return substr( Crypto::token( 6 ), 0, 10 );
	}

	/**
	 * Truncate a string for logs.
	 *
	 * @param string $s   String.
	 * @param int    $max Max bytes.
	 * @return string
	 */
	public static function truncate( $s, $max = 20000 ) {
		$s = (string) $s;
		if ( strlen( $s ) <= $max ) {
			return $s;
		}
		return ( function_exists( 'mb_strcut' ) ? mb_strcut( $s, 0, $max ) : substr( $s, 0, $max ) ) . "\n…[truncated " . ( strlen( $s ) - $max ) . ' bytes]';
	}

	/**
	 * Does an IP match an allowlist of IPs / CIDRs?
	 *
	 * @param string $ip   IP.
	 * @param string $list Newline/comma separated list.
	 * @return bool
	 */
	public static function ip_allowed( $ip, $list ) {
		$entries = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $list ) ) );
		if ( ! $entries ) {
			return true;
		}
		foreach ( $entries as $entry ) {
			if ( false === strpos( $entry, '/' ) ) {
				if ( $entry === $ip ) {
					return true;
				}
				continue;
			}
			list( $subnet, $bits ) = explode( '/', $entry, 2 );
			$ip_bin                = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$sub_bin               = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
				continue;
			}
			$bits  = (int) $bits;
			$bytes = intdiv( $bits, 8 );
			$rem   = $bits % 8;
			if ( substr( $ip_bin, 0, $bytes ) !== substr( $sub_bin, 0, $bytes ) ) {
				continue;
			}
			if ( 0 === $rem ) {
				return true;
			}
			$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
			if ( ( $ip_bin[ $bytes ] & $mask ) === ( $sub_bin[ $bytes ] & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mask values of sensitive keys (for logs).
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$pattern = apply_filters( 'brnwh_sensitive_key_pattern', '/(pass(word)?|pwd|secret|token|api[_-]?key|authorization|cvv|cvc|card[_-]?(number|num)|credit[_-]?card|ssn|id[_-]?number|teudat)/i' );
		$out     = array();
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && preg_match( $pattern, $k ) && ! is_array( $v ) && '' !== (string) $v ) {
				$out[ $k ] = '••••••';
			} else {
				$out[ $k ] = is_array( $v ) ? self::redact( $v ) : $v;
			}
		}
		return $out;
	}
}
