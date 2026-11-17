<?php
/**
 * Action base.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * An action does something with the data: an HTTP call, a spreadsheet row, a Meta event…
 */
abstract class Action extends Integration {

	/**
	 * Connection type id required by this action (null = none).
	 *
	 * @return string|null
	 */
	public function connection_type() {
		return null;
	}

	/**
	 * Is the connection optional?
	 *
	 * @return bool
	 */
	public function connection_optional() {
		return false;
	}

	/**
	 * Execute.
	 *
	 * @param array      $config     Raw (unrendered) config.
	 * @param Context    $ctx        Context.
	 * @param array|null $connection Decrypted connection.
	 * @return mixed Output available to later steps as {{_steps.N...}}.
	 * @throws Action_Error On failure.
	 */
	abstract public function execute( array $config, Context $ctx, $connection );

	/**
	 * Helper: config value.
	 *
	 * @param array  $config  Config.
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	protected static function cfg( array $config, $key, $default = '' ) {
		return isset( $config[ $key ] ) ? $config[ $key ] : $default;
	}

	/**
	 * Require a connection.
	 *
	 * @param array|null $connection Connection.
	 * @return array Credentials.
	 * @throws Action_Error When missing.
	 */
	protected function creds( $connection ) {
		if ( ! $connection ) {
			throw new Action_Error( __( 'No connection selected for this step.', 'brn-webhooks' ), false );
		}
		if ( ! empty( $connection['unreadable'] ) ) {
			throw new Action_Error( __( 'The connection credentials cannot be decrypted. Please enter them again.', 'brn-webhooks' ), false );
		}
		return $connection['credentials'];
	}

	/**
	 * For the admin app.
	 *
	 * @return array
	 */
	public function to_array() {
		$a                        = parent::to_array();
		$a['connection_type']     = $this->connection_type();
		$a['connection_optional'] = $this->connection_optional();
		return $a;
	}
}
