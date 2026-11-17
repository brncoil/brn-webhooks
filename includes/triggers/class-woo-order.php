<?php
/**
 * WooCommerce order trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Flows;
use BRN\Webhooks\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce order status changed.
 */
class Woo_Order extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'woo_order';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WooCommerce order status', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs when an order reaches a status (e.g. Processing = paid).', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'WooCommerce';
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'woocommerce';
	}

	/**
	 * Available?
	 *
	 * @return bool
	 */
	public function is_available() {
		return class_exists( '\WooCommerce' );
	}

	/**
	 * Reason.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Requires WooCommerce.', 'brn-webhooks' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		$statuses = array( '' => __( 'Any status change', 'brn-webhooks' ) );
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $k => $l ) {
				$statuses[ substr( $k, 3 ) ] = $l;
			}
		} else {
			$statuses += array(
				'processing' => 'Processing',
				'completed'  => 'Completed',
			);
		}
		return array(
			array(
				'key'     => 'to_status',
				'type'    => 'select',
				'label'   => __( 'When the order becomes', 'brn-webhooks' ),
				'default' => 'processing',
				'options' => self::options( $statuses ),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_change' ), 20, 4 );
	}

	/**
	 * Matches status.
	 *
	 * @param array $config Config.
	 * @param array $event  Event.
	 * @return bool
	 */
	public function matches( array $config, array $event ) {
		return empty( $config['to_status'] ) || ( isset( $event['to'] ) && $event['to'] === $config['to_status'] );
	}

	/**
	 * Handler.
	 *
	 * @param int    $order_id Order id.
	 * @param string $from     From.
	 * @param string $to       To.
	 * @param object $order    Order.
	 */
	public function on_change( $order_id, $from, $to, $order = null ) {
		if ( ! Flows::has_active( $this->id() ) && ! Flows::listening_ids() ) {
			return;
		}
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$data                = self::order_data( $order );
		$data['from_status'] = $from;
		$data['status']      = $to;
		$this->fire( $data, array( 'to' => $to ) );
	}

	/**
	 * Flatten an order.
	 *
	 * @param \WC_Order $order Order.
	 * @return array
	 */
	public static function order_data( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'product_id' => $item->get_product_id(),
				'sku'        => $product ? $product->get_sku() : '',
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'total'      => (float) $item->get_total(),
			);
		}
		$created = $order->get_date_created();
		return array(
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'total'            => (float) $order->get_total(),
			'subtotal'         => (float) $order->get_subtotal(),
			'shipping_total'   => (float) $order->get_shipping_total(),
			'discount_total'   => (float) $order->get_discount_total(),
			'currency'         => $order->get_currency(),
			'payment_method'   => $order->get_payment_method_title(),
			'customer_note'    => $order->get_customer_note(),
			'coupons'          => implode( ', ', $order->get_coupon_codes() ),
			'first_name'       => $order->get_billing_first_name(),
			'last_name'        => $order->get_billing_last_name(),
			'email'            => $order->get_billing_email(),
			'phone'            => $order->get_billing_phone(),
			'company'          => $order->get_billing_company(),
			'address'          => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'city'             => $order->get_billing_city(),
			'postcode'         => $order->get_billing_postcode(),
			'country'          => $order->get_billing_country(),
			'shipping_address' => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() . ', ' . $order->get_shipping_city(), ' ,' ),
			'items'            => $items,
			'items_text'       => implode(
				', ',
				array_map(
					static function ( $i ) {
						return $i['name'] . ' × ' . $i['quantity'];
					},
					$items
				)
			),
			'created_at'       => $created ? $created->date( 'c' ) : '',
			'admin_url'        => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			'_meta'            => array(
				'ip'         => $order->get_customer_ip_address(),
				'user_agent' => $order->get_customer_user_agent(),
				'user_id'    => $order->get_customer_id(),
				'page_url'   => home_url( '/' ),
			),
		);
	}
}
