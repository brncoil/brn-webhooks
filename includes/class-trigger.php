<?php
/**
 * Trigger base.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * A trigger starts flows: a form submission, an incoming webhook, a WordPress event…
 */
abstract class Trigger extends Integration {

	/**
	 * Hook into WordPress / the source plugin. Called on plugins_loaded when available.
	 */
	public function register() {}

	/**
	 * Does this trigger receive HTTP calls on its own endpoint URL?
	 *
	 * @return bool
	 */
	public function has_endpoint() {
		return false;
	}

	/**
	 * Does a flow's trigger config match this event? (e.g. a specific form id)
	 *
	 * @param array $config Flow trigger config.
	 * @param array $event  Event info (form_id…).
	 * @return bool
	 */
	public function matches( array $config, array $event ) {
		if ( isset( $config['form_id'] ) && '' !== (string) $config['form_id'] && isset( $event['form_id'] ) ) {
			return (string) $config['form_id'] === (string) $event['form_id'];
		}
		return true;
	}

	/**
	 * Enrich data at run time, before conditions (e.g. fetch a Facebook lead).
	 *
	 * @param array $data Data.
	 * @param array $flow Flow.
	 * @return array
	 * @throws Action_Error When enrichment fails.
	 */
	public function prepare( array $data, array $flow ) {
		return $data;
	}

	/**
	 * Fields known from the source definition (shown in the picker before any submission).
	 *
	 * @param array $config Trigger config.
	 * @return array {key: label}
	 */
	public function known_fields( array $config ) {
		return array();
	}

	/**
	 * Fire: run every matching flow.
	 *
	 * @param array $data  Data.
	 * @param array $event Event info.
	 */
	protected function fire( array $data, array $event = array() ) {
		Engine::trigger( $this->id(), $data, $event );
	}

	/**
	 * For the admin app.
	 *
	 * @return array
	 */
	public function to_array() {
		$a                 = parent::to_array();
		$a['has_endpoint'] = $this->has_endpoint();
		return $a;
	}
}
