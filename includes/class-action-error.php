<?php
/**
 * Action failure.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by actions / HTTP layer. $retryable=false marks permanent errors (4xx, bad config).
 */
class Action_Error extends \RuntimeException {

	/**
	 * Retry makes sense?
	 *
	 * @var bool
	 */
	public $retryable = true;

	/**
	 * Constructor.
	 *
	 * @param string $message   Message.
	 * @param bool   $retryable Retryable.
	 */
	public function __construct( $message, $retryable = true ) {
		parent::__construct( (string) $message );
		$this->retryable = (bool) $retryable;
	}
}
