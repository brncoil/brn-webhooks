<?php
/**
 * Base for logic nodes (filter, if/else, paths, delay, loop).
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

use BRN\Webhooks\Action;
use BRN\Webhooks\Action_Error;
use BRN\Webhooks\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Logic nodes are executed by the engine itself; they only describe themselves here.
 */
abstract class Logic_Step extends Action {

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return __( 'Logic & tools', 'brn-webhooks' );
	}

	/**
	 * Never called — the engine handles logic nodes.
	 *
	 * @param array      $config     Config.
	 * @param Context    $ctx        Context.
	 * @param array|null $connection Connection.
	 * @throws Action_Error Always.
	 */
	public function execute( array $config, Context $ctx, $connection ) {
		throw new Action_Error( 'Logic nodes are run by the engine.', false );
	}
}
