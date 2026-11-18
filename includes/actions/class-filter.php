<?php
/**
 * Filter node.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Continue down this branch only when the conditions match.
 */
class Filter extends Logic_Step {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'filter';
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'filter';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Filter', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Continue only when the conditions match. Otherwise this branch stops.', 'brn-webhooks' );
	}
}
