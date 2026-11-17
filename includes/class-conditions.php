<?php
/**
 * Condition evaluation.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates condition groups:
 *
 * {"logic":"or","groups":[{"logic":"and","rules":[{"field":"email","op":"contains","value":"@gmail"}]}]}
 */
class Conditions {

	/**
	 * Operators that take no value.
	 *
	 * @var string[]
	 */
	const UNARY = array( 'empty', 'not_empty', 'is_true', 'is_false', 'is_email', 'is_phone', 'exists', 'not_exists' );

	/**
	 * Has any rule?
	 *
	 * @param mixed $c Conditions.
	 * @return bool
	 */
	public static function has_rules( $c ) {
		if ( ! is_array( $c ) || empty( $c['groups'] ) || ! is_array( $c['groups'] ) ) {
			return false;
		}
		foreach ( $c['groups'] as $g ) {
			if ( ! empty( $g['rules'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluate. Returns [bool passed, array explanation].
	 *
	 * @param mixed $c    Conditions.
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function evaluate( $c, array $vars ) {
		if ( ! self::has_rules( $c ) ) {
			return array( true, array() );
		}
		$outer   = ( isset( $c['logic'] ) && 'and' === $c['logic'] ) ? 'and' : 'or';
		$explain = array();
		$results = array();
		foreach ( $c['groups'] as $gi => $group ) {
			if ( empty( $group['rules'] ) || ! is_array( $group['rules'] ) ) {
				continue;
			}
			$inner  = ( isset( $group['logic'] ) && 'or' === $group['logic'] ) ? 'or' : 'and';
			$gres   = array();
			foreach ( $group['rules'] as $rule ) {
				list( $ok, $actual ) = self::rule( $rule, $vars );
				$gres[]    = $ok;
				$explain[] = array(
					'group'  => $gi + 1,
					'field'  => isset( $rule['field'] ) ? (string) $rule['field'] : '',
					'op'     => isset( $rule['op'] ) ? (string) $rule['op'] : '',
					'value'  => isset( $rule['value'] ) ? Template::render( (string) $rule['value'], $vars ) : '',
					'actual' => self::short( $actual ),
					'passed' => $ok,
				);
			}
			$results[] = 'and' === $inner ? ! in_array( false, $gres, true ) : in_array( true, $gres, true );
		}
		if ( ! $results ) {
			return array( true, $explain );
		}
		$passed = 'and' === $outer ? ! in_array( false, $results, true ) : in_array( true, $results, true );
		return array( $passed, $explain );
	}

	/**
	 * Short printable value.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function short( $v ) {
		$s = Template::stringify( $v );
		return strlen( $s ) > 200 ? substr( $s, 0, 200 ) . '…' : $s;
	}

	/**
	 * Evaluate one rule.
	 *
	 * @param array $rule Rule.
	 * @param array $vars Vars.
	 * @return array [bool, actual]
	 */
	public static function rule( $rule, array $vars ) {
		$field = isset( $rule['field'] ) ? trim( (string) $rule['field'] ) : '';
		$op    = isset( $rule['op'] ) ? (string) $rule['op'] : 'equals';
		if ( '' === $field ) {
			return array( true, null );
		}
		// A field may be a bare path ("email") or a template ("{{first}} {{last}}").
		if ( false !== strpos( $field, '{{' ) ) {
			$actual = Template::render( $field, $vars );
			$exists = true;
		} else {
			$path   = preg_replace( '/^\{\{\s*|\s*\}\}$/', '', $field );
			$actual = Template::lookup( $path, $vars );
			$exists = null !== $actual;
		}
		$expected = isset( $rule['value'] ) ? Template::render( (string) $rule['value'], $vars ) : '';
		$a        = Template::stringify( $actual );
		$lower    = static function ( $s ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ) ) : strtolower( trim( $s ) );
		};
		$al = $lower( $a );
		$el = $lower( $expected );

		switch ( $op ) {
			case 'equals':
				$ok = $al === $el;
				break;
			case 'not_equals':
				$ok = $al !== $el;
				break;
			case 'contains':
				$ok = '' !== $el && false !== strpos( $al, $el );
				break;
			case 'not_contains':
				$ok = '' === $el || false === strpos( $al, $el );
				break;
			case 'starts_with':
				$ok = '' !== $el && 0 === strpos( $al, $el );
				break;
			case 'ends_with':
				$ok = '' !== $el && substr( $al, -strlen( $el ) ) === $el;
				break;
			case 'gt':
			case 'gte':
			case 'lt':
			case 'lte':
				if ( ! is_numeric( $a ) || ! is_numeric( $expected ) ) {
					$ok = false;
					break;
				}
				$x  = (float) $a;
				$y  = (float) $expected;
				$ok = ( 'gt' === $op && $x > $y ) || ( 'gte' === $op && $x >= $y ) || ( 'lt' === $op && $x < $y ) || ( 'lte' === $op && $x <= $y );
				break;
			case 'empty':
				$ok = '' === trim( $a );
				break;
			case 'not_empty':
				$ok = '' !== trim( $a );
				break;
			case 'exists':
				$ok = $exists;
				break;
			case 'not_exists':
				$ok = ! $exists;
				break;
			case 'in_list':
			case 'not_in_list':
				$list = array_filter( array_map( $lower, preg_split( '/[,\n]/', $expected ) ), 'strlen' );
				$in   = in_array( $al, $list, true );
				$ok   = 'in_list' === $op ? $in : ! $in;
				break;
			case 'regex':
				$pattern = '~' . str_replace( '~', '\~', $expected ) . '~iu';
				$ok      = '' !== $expected && 1 === @preg_match( $pattern, $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			case 'is_true':
				$ok = in_array( $al, array( '1', 'true', 'yes', 'on', 'כן' ), true );
				break;
			case 'is_false':
				$ok = in_array( $al, array( '', '0', 'false', 'no', 'off', 'לא' ), true );
				break;
			case 'is_email':
				$ok = (bool) is_email( trim( $a ) );
				break;
			case 'is_phone':
				$d  = preg_replace( '/\D+/', '', $a );
				$ok = strlen( $d ) >= 9 && strlen( $d ) <= 15;
				break;
			case 'date_before':
			case 'date_after':
				$x  = strtotime( $a );
				$y  = strtotime( $expected );
				$ok = false !== $x && false !== $y && ( 'date_before' === $op ? $x < $y : $x > $y );
				break;
			default:
				$ok = false;
		}
		return array( (bool) $ok, $actual );
	}

	/**
	 * Sanitize a conditions structure from the UI.
	 *
	 * @param mixed $c Raw.
	 * @return array
	 */
	public static function sanitize( $c ) {
		$out = array(
			'logic'  => ( is_array( $c ) && isset( $c['logic'] ) && 'and' === $c['logic'] ) ? 'and' : 'or',
			'groups' => array(),
		);
		if ( ! is_array( $c ) || empty( $c['groups'] ) || ! is_array( $c['groups'] ) ) {
			return $out;
		}
		foreach ( array_slice( $c['groups'], 0, 20 ) as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$group = array(
				'logic' => ( isset( $g['logic'] ) && 'or' === $g['logic'] ) ? 'or' : 'and',
				'rules' => array(),
			);
			foreach ( array_slice( isset( $g['rules'] ) && is_array( $g['rules'] ) ? $g['rules'] : array(), 0, 50 ) as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$group['rules'][] = array(
					'field' => isset( $r['field'] ) ? substr( (string) $r['field'], 0, 500 ) : '',
					'op'    => isset( $r['op'] ) ? sanitize_key( $r['op'] ) : 'equals',
					'value' => isset( $r['value'] ) ? substr( (string) $r['value'], 0, 5000 ) : '',
				);
			}
			$out['groups'][] = $group;
		}
		return $out;
	}
}
