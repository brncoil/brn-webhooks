<?php
/**
 * Loop node.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Run the "For each" branch once per item of a list ({{_loop.item}}), then the "Done" branch.
 */
class Loop extends Logic_Step {

	/**
	 * Hard cap on iterations.
	 */
	const MAX_ITEMS = 500;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'loop';
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'loop';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Loop', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Repeat steps for each item of a list — e.g. every product in an order.', 'brn-webhooks' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'         => 'items',
				'type'        => 'text',
				'template'    => true,
				'required'    => true,
				'mono'        => true,
				'label'       => __( 'List to loop over', 'brn-webhooks' ),
				'placeholder' => '{{line_items}}',
				'help'        => __( 'A list field (e.g. order items), or text separated by the separator below.', 'brn-webhooks' ),
			),
			array(
				'key'     => 'separator',
				'type'    => 'text',
				'label'   => __( 'Separator for text lists', 'brn-webhooks' ),
				'default' => ',',
				'width'   => 'half',
				'mono'    => true,
			),
			array(
				'key'     => 'max',
				'type'    => 'number',
				'label'   => __( 'Maximum items', 'brn-webhooks' ),
				'default' => 100,
				'width'   => 'half',
			),
			array(
				'key'  => '_info',
				'type' => 'info',
				'html' => esc_html__( 'Inside the loop use {{_loop.item}} (or {{_loop.item.name}}), {{_loop.index}} and {{_loop.count}}.', 'brn-webhooks' ),
			),
		);
	}

	/**
	 * Resolve the list of items.
	 *
	 * @param array                 $config Config.
	 * @param \BRN\Webhooks\Context $ctx    Context.
	 * @return array
	 */
	public static function items( array $config, $ctx ) {
		$value = $ctx->value( isset( $config['items'] ) ? $config['items'] : '' );
		if ( is_string( $value ) ) {
			$trim = trim( $value );
			if ( '' === $trim ) {
				return array();
			}
			$decoded = ( '[' === $trim[0] || '{' === $trim[0] ) ? json_decode( $trim, true ) : null;
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			} else {
				$sep   = isset( $config['separator'] ) && '' !== $config['separator'] ? (string) $config['separator'] : ',';
				$sep   = in_array( strtolower( $sep ), array( '\n', 'newline' ), true ) ? "\n" : $sep;
				$value = array_values( array_filter( array_map( 'trim', explode( $sep, $trim ) ), 'strlen' ) );
			}
		}
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			$value = array( $value );
		} elseif ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			// An object: loop over its entries.
			$pairs = array();
			foreach ( $value as $k => $v ) {
				$pairs[] = array(
					'key'   => (string) $k,
					'value' => $v,
				);
			}
			$value = $pairs;
		}
		$max = isset( $config['max'] ) && (int) $config['max'] > 0 ? (int) $config['max'] : 100;
		return array_slice( $value, 0, min( $max, self::MAX_ITEMS ) );
	}
}
