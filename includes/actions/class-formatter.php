<?php
/**
 * Formatter tools.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Context;
use BRN\Webhooks\Math;

defined( 'ABSPATH' ) || exit;

/**
 * Set values, lookup tables, math, dates and text splitting — results are available to later steps.
 */
class Formatter extends Action {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'formatter';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Formatter', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Set values, lookup tables, math, dates and text tools.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return __( 'Logic & tools', 'brn-webhooks' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'     => 'operation',
				'type'    => 'select',
				'label'   => __( 'Tool', 'brn-webhooks' ),
				'default' => 'set',
				'options' => self::options(
					array(
						'set'    => __( 'Set values (variables)', 'brn-webhooks' ),
						'lookup' => __( 'Lookup table (if X then Y)', 'brn-webhooks' ),
						'math'   => __( 'Math (calculate)', 'brn-webhooks' ),
						'date'   => __( 'Date & time (add / format)', 'brn-webhooks' ),
						'split'  => __( 'Split text into a list', 'brn-webhooks' ),
					)
				),
			),
			// Set values.
			array(
				'key'       => 'values',
				'type'      => 'keyvalue',
				'label'     => __( 'Values', 'brn-webhooks' ),
				'template'  => true,
				'key_placeholder' => __( 'name', 'brn-webhooks' ),
				'help'      => __( 'Each name becomes a field later steps can use. Filters work here, e.g. {{name|upper}}.', 'brn-webhooks' ),
				'show_if'   => array( 'operation' => 'set' ),
			),
			// Lookup.
			array(
				'key'         => 'input',
				'type'        => 'text',
				'template'    => true,
				'label'       => __( 'Value to look up', 'brn-webhooks' ),
				'placeholder' => '{{utm_source}}',
				'show_if'     => array( 'operation' => array( 'lookup', 'split' ) ),
			),
			array(
				'key'       => 'table',
				'type'      => 'keyvalue',
				'template'  => true,
				'label'     => __( 'Table', 'brn-webhooks' ),
				'key_placeholder'   => __( 'when the value is…', 'brn-webhooks' ),
				'value_placeholder' => __( 'the result is…', 'brn-webhooks' ),
				'help'      => __( 'Compared without case or surrounding spaces. The result can contain fields.', 'brn-webhooks' ),
				'show_if'   => array( 'operation' => 'lookup' ),
			),
			array(
				'key'      => 'fallback',
				'type'     => 'text',
				'template' => true,
				'label'    => __( 'Otherwise (no match)', 'brn-webhooks' ),
				'show_if'  => array( 'operation' => 'lookup' ),
			),
			// Math.
			array(
				'key'         => 'expression',
				'type'        => 'text',
				'template'    => true,
				'mono'        => true,
				'label'       => __( 'Expression', 'brn-webhooks' ),
				'placeholder' => '({{price}} * {{quantity}}) * 1.17',
				'help'        => __( 'Numbers, fields and + - * / % ( ). Text like "1,200 ₪" is read as 1200.', 'brn-webhooks' ),
				'show_if'     => array( 'operation' => 'math' ),
			),
			array(
				'key'     => 'decimals',
				'type'    => 'number',
				'label'   => __( 'Decimal places', 'brn-webhooks' ),
				'default' => 2,
				'width'   => 'half',
				'show_if' => array( 'operation' => 'math' ),
			),
			// Date.
			array(
				'key'         => 'date',
				'type'        => 'text',
				'template'    => true,
				'label'       => __( 'Date', 'brn-webhooks' ),
				'placeholder' => __( 'Empty = now, or {{created_at}}', 'brn-webhooks' ),
				'show_if'     => array( 'operation' => 'date' ),
			),
			array(
				'key'         => 'adjust',
				'type'        => 'text',
				'label'       => __( 'Add / subtract', 'brn-webhooks' ),
				'placeholder' => '+2 days  /  -3 hours  /  next sunday',
				'mono'        => true,
				'show_if'     => array( 'operation' => 'date' ),
			),
			array(
				'key'     => 'format',
				'type'    => 'text',
				'label'   => __( 'Output format', 'brn-webhooks' ),
				'default' => 'd/m/Y H:i',
				'mono'    => true,
				'help'    => __( 'PHP date format: d/m/Y H:i → 31/12/2026 09:00, Y-m-d → 2026-12-31, c → ISO 8601.', 'brn-webhooks' ),
				'show_if' => array( 'operation' => 'date' ),
			),
			// Split.
			array(
				'key'     => 'separator',
				'type'    => 'text',
				'label'   => __( 'Separator', 'brn-webhooks' ),
				'default' => ',',
				'mono'    => true,
				'width'   => 'half',
				'help'    => __( 'For example , or ; or | — or the word newline for one item per line.', 'brn-webhooks' ),
				'show_if' => array( 'operation' => 'split' ),
			),
		);
	}

	/**
	 * Execute.
	 *
	 * @param array      $config     Config.
	 * @param Context    $ctx        Context.
	 * @param array|null $connection Unused.
	 * @return array
	 * @throws Action_Error On bad input.
	 */
	public function execute( array $config, Context $ctx, $connection ) {
		$op = self::cfg( $config, 'operation', 'set' );
		switch ( $op ) {
			case 'lookup':
				$needle = self::norm( $ctx->render( self::cfg( $config, 'input' ) ) );
				foreach ( (array) self::cfg( $config, 'table', array() ) as $row ) {
					if ( ! is_array( $row ) || ! isset( $row['key'] ) ) {
						continue;
					}
					if ( self::norm( $ctx->render( $row['key'] ) ) === $needle ) {
						return array(
							'value'   => $ctx->value( isset( $row['value'] ) ? $row['value'] : '' ),
							'matched' => true,
						);
					}
				}
				return array(
					'value'   => $ctx->value( self::cfg( $config, 'fallback' ) ),
					'matched' => false,
				);

			case 'math':
				$expr = $ctx->render( self::cfg( $config, 'expression' ) );
				try {
					$result = Math::evaluate( $expr );
				} catch ( \InvalidArgumentException $e ) {
					/* translators: 1: expression, 2: error */
					throw new Action_Error( sprintf( __( 'Cannot calculate "%1$s": %2$s', 'brn-webhooks' ), trim( $expr ), $e->getMessage() ), false );
				}
				$dec = self::cfg( $config, 'decimals', 2 );
				if ( '' !== $dec && null !== $dec ) {
					$result = round( $result, max( 0, min( 10, (int) $dec ) ) );
				}
				if ( floor( $result ) == $result && abs( $result ) < PHP_INT_MAX ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
					$result = (int) $result;
				}
				return array(
					'value'      => $result,
					'expression' => $expr,
				);

			case 'date':
				$raw = trim( $ctx->render( self::cfg( $config, 'date' ) ) );
				$tz  = wp_timezone();
				try {
					$d = '' === $raw ? new \DateTimeImmutable( 'now', $tz ) : ( is_numeric( $raw ) ? ( new \DateTimeImmutable( '@' . (int) $raw ) )->setTimezone( $tz ) : new \DateTimeImmutable( $raw, $tz ) );
					$adjust = trim( (string) self::cfg( $config, 'adjust' ) );
					if ( '' !== $adjust ) {
						$adjusted = $d->modify( $adjust );
						if ( false === $adjusted ) {
							throw new \Exception( 'adjust' );
						}
						$d = $adjusted;
					}
				} catch ( \Exception $e ) {
					/* translators: %s: the date text */
					throw new Action_Error( sprintf( __( 'Not a valid date: %s', 'brn-webhooks' ), '' !== $raw ? $raw : self::cfg( $config, 'adjust' ) ), false );
				}
				$format = (string) self::cfg( $config, 'format', 'd/m/Y H:i' );
				return array(
					'value'     => wp_date( '' !== $format ? $format : 'd/m/Y H:i', $d->getTimestamp(), $tz ),
					'iso'       => $d->format( 'c' ),
					'date'      => $d->format( 'Y-m-d' ),
					'time'      => $d->format( 'H:i' ),
					'timestamp' => $d->getTimestamp(),
				);

			case 'split':
				$text = $ctx->render( self::cfg( $config, 'input' ) );
				$sep  = (string) self::cfg( $config, 'separator', ',' );
				$sep  = '' === $sep ? ',' : ( in_array( strtolower( $sep ), array( '\n', 'newline' ), true ) ? "\n" : $sep );
				$list = array_values( array_filter( array_map( 'trim', explode( $sep, str_replace( "\r\n", "\n", $text ) ) ), 'strlen' ) );
				return array(
					'items' => $list,
					'count' => count( $list ),
					'first' => $list ? $list[0] : '',
					'last'  => $list ? $list[ count( $list ) - 1 ] : '',
				);

			default:
				return $ctx->render_pairs( self::cfg( $config, 'values', array() ), true, true );
		}
	}

	/**
	 * Normalise for comparisons.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function norm( $s ) {
		$s = trim( (string) $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}
}
