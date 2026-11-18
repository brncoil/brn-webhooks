<?php
/**
 * If / else node.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Two branches: "yes" when the conditions match, "no" otherwise.
 */
class Condition extends Logic_Step {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'if';
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'if';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'If / else', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Check conditions and go down the "Yes" or the "No" branch.', 'brn-webhooks' );
	}
}
