<?php
/**
 * Paths (router / switch) node.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Several named paths, each with its own conditions, plus an optional "otherwise" path.
 *
 * Config: { mode: first|all, else: bool, paths: [ {id, name, conditions} ] }
 */
class Router extends Logic_Step {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'router';
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'router';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Paths', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Split the flow into several paths, each with its own rules (like a switch).', 'brn-webhooks' );
	}
}
