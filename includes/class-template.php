<?php
/**
 * {{placeholder|filter}} rendering.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny, safe template language. No code execution — only path lookups and a fixed list of filters.
 *
 *   {{email}}  {{user.name|upper}}  {{phone|phone:972}}  {{date|date:Y-m-d}}  {{name|default:Guest}}
 */
class Template {

	const PATTERN = '/\{\{\s*(.+?)\s*\}\}/s';

	/**
	 * Render a template to a string.
	 *
	 * @param mixed  $tpl    Template.
	 * @param array  $vars   Variables.
	 * @param string $escape '' | 'json' | 'html' | 'url'.
	 * @return string
	 */
	public static function render( $tpl, array $vars, $escape = '' ) {
		if ( ! is_string( $tpl ) ) {
			return is_scalar( $tpl ) ? (string) $tpl : '';
		}
		if ( false === strpos( $tpl, '{{' ) ) {
			return $tpl;
		}
		return preg_replace_callback(
			self::PATTERN,
			static function ( $m ) use ( $vars, $escape ) {
				$value = self::evaluate( $m[1], $vars );
				$str   = self::stringify( $value );
				// {{_all_table}} is HTML generated (and escaped) by us.
				if ( 'html' === $escape && '_all_table' === trim( $m[1] ) ) {
					return $str;
				}
				switch ( $escape ) {
					case 'json':
						return substr( wp_json_encode( $str, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 1, -1 );
					case 'html':
						return esc_html( $str );
					case 'url':
						return rawurlencode( $str );
				}
				return $str;
			},
			$tpl
		);
	}

	/**
	 * Render keeping the native type when the template is exactly one placeholder
	 * (so {{items}} stays an array and {{total}} stays a number inside JSON bodies).
	 *
	 * @param mixed $tpl  Template.
	 * @param array $vars Variables.
	 * @return mixed
	 */
	public static function render_value( $tpl, array $vars ) {
		if ( is_string( $tpl ) && preg_match( '/^\s*\{\{\s*([^{}]+?)\s*\}\}\s*$/s', $tpl, $m ) ) {
			$v = self::evaluate( $m[1], $vars );
			return null === $v ? '' : $v;
		}
		return self::render( $tpl, $vars );
	}

	/**
	 * Evaluate "path|filter:arg|filter".
	 *
	 * @param string $expr Expression.
	 * @param array  $vars Vars.
	 * @return mixed
	 */
	public static function evaluate( $expr, array $vars ) {
		$parts = self::split_filters( $expr );
		$path  = trim( array_shift( $parts ) );
		$value = self::lookup( $path, $vars );
		foreach ( $parts as $filter ) {
			$value = self::apply_filter( $value, $filter, $vars );
		}
		return $value;
	}

	/**
	 * Split on | that is not inside quotes.
	 *
	 * @param string $expr Expression.
	 * @return string[]
	 */
	private static function split_filters( $expr ) {
		$out   = array();
		$buf   = '';
		$quote = '';
		$len   = strlen( $expr );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $expr[ $i ];
			if ( '' !== $quote ) {
				if ( $c === $quote ) {
					$quote = '';
				}
				$buf .= $c;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
				$buf  .= $c;
				continue;
			}
			if ( '|' === $c ) {
				$out[] = $buf;
				$buf   = '';
				continue;
			}
			$buf .= $c;
		}
		$out[] = $buf;
		return $out;
	}

	/**
	 * Look up a dot path. Exact keys (that may contain dots) win.
	 *
	 * @param string $path Path.
	 * @param array  $vars Vars.
	 * @return mixed
	 */
	public static function lookup( $path, array $vars ) {
		if ( '' === $path ) {
			return null;
		}
		// Quoted literal: {{"text"|upper}}.
		if ( preg_match( '/^(["\'])(.*)\1$/s', $path, $m ) ) {
			return $m[2];
		}
		if ( array_key_exists( $path, $vars ) ) {
			return $vars[ $path ];
		}
		switch ( $path ) {
			case '_all':
				return wp_json_encode( self::public_fields( $vars ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
			case '_all_text':
				$lines = array();
				foreach ( self::public_fields( $vars ) as $k => $v ) {
					$lines[] = $k . ': ' . self::stringify( $v );
				}
				return implode( "\n", $lines );
			case '_all_table':
				$rows = '';
				foreach ( self::public_fields( $vars ) as $k => $v ) {
					$rows .= '<tr><th style="text-align:start;padding:6px 12px;background:#f6f7fb;border:1px solid #e3e5ee">' . esc_html( $k ) . '</th><td style="padding:6px 12px;border:1px solid #e3e5ee">' . nl2br( esc_html( self::stringify( $v ) ) ) . '</td></tr>';
				}
				return '<table style="border-collapse:collapse;font-family:sans-serif;font-size:14px">' . $rows . '</table>';
		}
		$cur = $vars;
		foreach ( explode( '.', $path ) as $seg ) {
			if ( is_array( $cur ) && array_key_exists( $seg, $cur ) ) {
				$cur = $cur[ $seg ];
			} elseif ( is_object( $cur ) && isset( $cur->$seg ) ) {
				$cur = $cur->$seg;
			} else {
				return null;
			}
		}
		return $cur;
	}

	/**
	 * Trigger fields without system variables.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function public_fields( array $vars ) {
		$out = array();
		foreach ( $vars as $k => $v ) {
			if ( is_string( $k ) && '' !== $k && '_' === $k[0] ) {
				continue;
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/**
	 * Convert any value to a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function stringify( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list ) {
				$all_scalar = true;
				foreach ( $value as $v ) {
					if ( ! is_scalar( $v ) && null !== $v ) {
						$all_scalar = false;
						break;
					}
				}
				if ( $all_scalar ) {
					return implode( ', ', array_map( 'strval', $value ) );
				}
			}
		}
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Parse "name:arg1:arg2" with optional quotes.
	 *
	 * @param string $filter Filter spec.
	 * @return array [name, args]
	 */
	private static function parse_filter( $filter ) {
		$filter = trim( $filter );
		$pos    = strpos( $filter, ':' );
		if ( false === $pos ) {
			return array( strtolower( $filter ), array() );
		}
		$name = strtolower( trim( substr( $filter, 0, $pos ) ) );
		$rest = substr( $filter, $pos + 1 );
		$args = array();
		$buf  = '';
		$q    = '';
		$len  = strlen( $rest );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $rest[ $i ];
			if ( '' !== $q ) {
				if ( $c === $q ) {
					$q = '';
				} else {
					$buf .= $c;
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$q = $c;
				continue;
			}
			if ( ':' === $c && 'date' !== $name ) {
				$args[] = $buf;
				$buf    = '';
				continue;
			}
			$buf .= $c;
		}
		$args[] = $buf;
		return array( $name, $args );
	}

	/**
	 * Apply a single filter.
	 *
	 * @param mixed  $value  Value.
	 * @param string $filter Filter.
	 * @param array  $vars   Vars (for nested placeholders in defaults).
	 * @return mixed
	 */
	private static function apply_filter( $value, $filter, array $vars ) {
		list( $name, $args ) = self::parse_filter( $filter );
		$a0                  = isset( $args[0] ) ? $args[0] : '';
		$s                   = self::stringify( $value );

		switch ( $name ) {
			case 'upper':
				return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $s ) : strtoupper( $s );
			case 'lower':
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
			case 'title':
				return function_exists( 'mb_convert_case' ) ? mb_convert_case( $s, MB_CASE_TITLE ) : ucwords( $s );
			case 'trim':
				return trim( $s );
			case 'default':
				return ( null === $value || '' === $s || array() === $value ) ? $a0 : $value;
			case 'date':
				if ( '' === $s ) {
					return '';
				}
				$ts = is_numeric( $s ) ? (int) $s : strtotime( $s );
				if ( false === $ts ) {
					return $s;
				}
				return wp_date( '' !== $a0 ? $a0 : 'Y-m-d H:i:s', $ts );
			case 'timestamp':
				$ts = is_numeric( $s ) ? (int) $s : strtotime( $s );
				return false === $ts ? '' : $ts;
			case 'number':
				$n = (float) preg_replace( '/[^\d.\-]/', '', $s );
				return '' !== $a0 ? round( $n, (int) $a0 ) : ( floor( $n ) == $n ? (int) $n : $n ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			case 'digits':
				return preg_replace( '/\D+/', '', $s );
			case 'phone':
				return self::phone_international( $s, '' !== $a0 ? $a0 : '972' );
			case 'phone_local':
				$d = preg_replace( '/\D+/', '', $s );
				$cc = '' !== $a0 ? $a0 : '972';
				if ( 0 === strpos( $d, $cc ) ) {
					$d = '0' . substr( $d, strlen( $cc ) );
				}
				return $d;
			case 'replace':
				return str_replace( $a0, isset( $args[1] ) ? $args[1] : '', $s );
			case 'truncate':
				$n = max( 1, (int) $a0 );
				return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $n ) : substr( $s, 0, $n );
			case 'first_word':
				$parts = preg_split( '/\s+/u', trim( $s ) );
				return $parts ? $parts[0] : '';
			case 'last_words':
				$parts = preg_split( '/\s+/u', trim( $s ) );
				array_shift( $parts );
				return implode( ' ', $parts );
			case 'split':
				return array_map( 'trim', explode( '' !== $a0 ? $a0 : ',', $s ) );
			case 'join':
				return is_array( $value ) ? implode( '' !== $a0 ? $a0 : ', ', array_map( array( __CLASS__, 'stringify' ), $value ) ) : $s;
			case 'first':
				return is_array( $value ) ? reset( $value ) : $s;
			case 'last':
				return is_array( $value ) ? end( $value ) : $s;
			case 'count':
				return is_array( $value ) ? count( $value ) : ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s ) );
			case 'json':
				return wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			case 'urlencode':
				return rawurlencode( $s );
			case 'strip_tags':
				return wp_strip_all_tags( $s );
			case 'md5':
				return md5( $s );
			case 'sha256':
				return hash( 'sha256', $s );
			case 'base64':
				return base64_encode( $s ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			case 'slug':
				return sanitize_title( $s );
			case 'nl2br':
				return nl2br( $s );
			case 'if_empty':
				return '' === $s ? $a0 : $value;
			case 'prefix':
				return '' === $s ? '' : $a0 . $s;
			case 'suffix':
				return '' === $s ? '' : $s . $a0;
		}
		return $value;
	}

	/**
	 * Normalise a phone number to international digits (e.g. 0501234567 -> 972501234567).
	 *
	 * @param string $phone Phone.
	 * @param string $cc    Default country code.
	 * @return string
	 */
	public static function phone_international( $phone, $cc = '972' ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}
		$plus = 0 === strpos( $phone, '+' ) || 0 === strpos( $phone, '00' );
		$d    = preg_replace( '/\D+/', '', $phone );
		if ( 0 === strpos( $d, '00' ) ) {
			$d = substr( $d, 2 );
		}
		if ( $plus ) {
			return $d;
		}
		$cc = preg_replace( '/\D+/', '', (string) $cc );
		if ( '' !== $cc && 0 === strpos( $d, $cc ) && strlen( $d ) > 10 ) {
			return $d;
		}
		if ( '' !== $cc && 0 === strpos( $d, '0' ) ) {
			return $cc . substr( $d, 1 );
		}
		if ( '' !== $cc && strlen( $d ) <= 10 ) {
			return $cc . $d;
		}
		return $d;
	}

	/**
	 * Recursively render every string in a structure (render_value for leaves).
	 *
	 * @param mixed $data Data.
	 * @param array $vars Vars.
	 * @return mixed
	 */
	public static function render_deep( $data, array $vars ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $k ] = self::render_deep( $v, $vars );
			}
			return $out;
		}
		return is_string( $data ) ? self::render_value( $data, $vars ) : $data;
	}
}
