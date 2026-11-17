<?php
/**
 * Admin REST API.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes used by the admin app. Every route requires the brnwh capability (manage_options
 * by default) and the standard wp_rest nonce (cookie auth).
 */
class Rest {

	const NS = 'brn-webhooks/v1';

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public static function capability() {
		return (string) apply_filters( 'brnwh_capability', 'manage_options' );
	}

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public function can() {
		return current_user_can( self::capability() );
	}

	/**
	 * Route helper.
	 *
	 * @param string          $path     Path.
	 * @param string|string[] $methods  Methods.
	 * @param callable        $callback Callback.
	 */
	private function route( $path, $methods, $callback ) {
		register_rest_route(
			self::NS,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	/**
	 * Register routes.
	 */
	public function routes() {
		$this->route( '/bootstrap', 'GET', array( $this, 'bootstrap' ) );
		$this->route( '/stats', 'GET', array( $this, 'stats' ) );

		$this->route( '/flows', 'GET', array( $this, 'flows' ) );
		$this->route( '/flows', 'POST', array( $this, 'flow_create' ) );
		$this->route( '/flows/import', 'POST', array( $this, 'flow_import' ) );
		$this->route( '/flows/test', 'POST', array( $this, 'flow_test' ) );
		$this->route( '/flows/test-step', 'POST', array( $this, 'flow_test_step' ) );
		$this->route( '/flows/(?P<id>\d+)', 'GET', array( $this, 'flow_get' ) );
		$this->route( '/flows/(?P<id>\d+)', array( 'PUT', 'POST', 'PATCH' ), array( $this, 'flow_update' ) );
		$this->route( '/flows/(?P<id>\d+)', 'DELETE', array( $this, 'flow_delete' ) );
		$this->route( '/flows/(?P<id>\d+)/duplicate', 'POST', array( $this, 'flow_duplicate' ) );
		$this->route( '/flows/(?P<id>\d+)/status', 'POST', array( $this, 'flow_status' ) );
		$this->route( '/flows/(?P<id>\d+)/listen', 'POST', array( $this, 'flow_listen' ) );
		$this->route( '/flows/(?P<id>\d+)/sample', 'GET', array( $this, 'flow_sample' ) );
		$this->route( '/flows/(?P<id>\d+)/rotate-key', 'POST', array( $this, 'flow_rotate' ) );
		$this->route( '/flows/(?P<id>\d+)/export', 'GET', array( $this, 'flow_export' ) );

		$this->route( '/connections', 'GET', array( $this, 'connections' ) );
		$this->route( '/connections', 'POST', array( $this, 'connection_save' ) );
		$this->route( '/connections/(?P<id>\d+)', array( 'PUT', 'POST', 'PATCH' ), array( $this, 'connection_save' ) );
		$this->route( '/connections/(?P<id>\d+)', 'DELETE', array( $this, 'connection_delete' ) );
		$this->route( '/connections/(?P<id>\d+)/test', 'POST', array( $this, 'connection_test' ) );

		$this->route( '/logs', 'GET', array( $this, 'logs' ) );
		$this->route( '/logs', 'DELETE', array( $this, 'logs_clear' ) );
		$this->route( '/logs/(?P<id>\d+)', 'GET', array( $this, 'log_get' ) );
		$this->route( '/logs/(?P<id>\d+)', 'DELETE', array( $this, 'log_delete' ) );
		$this->route( '/logs/(?P<id>\d+)/replay', 'POST', array( $this, 'log_replay' ) );
		$this->route( '/logs/(?P<id>\d+)/retry', 'POST', array( $this, 'log_retry' ) );

		$this->route( '/options', 'POST', array( $this, 'options' ) );
		$this->route( '/fields', 'POST', array( $this, 'known_fields' ) );

		$this->route( '/settings', 'GET', array( $this, 'settings' ) );
		$this->route( '/settings', array( 'PUT', 'POST' ), array( $this, 'settings_save' ) );
		$this->route( '/updates/check', 'POST', array( $this, 'updates_check' ) );
	}

	/**
	 * JSON body as array.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return array
	 */
	private function body( \WP_REST_Request $r ) {
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : (array) $r->get_body_params();
	}

	/**
	 * Flow for the UI (endpoint URL added).
	 *
	 * @param array|null $flow Flow.
	 * @return array|null
	 */
	private function flow_ui( $flow ) {
		if ( ! $flow ) {
			return null;
		}
		$flow['endpoint'] = Incoming::url( $flow['hook_key'] );
		unset( $flow['hook_key'] );
		return $flow;
	}

	/**
	 * Wrap result.
	 *
	 * @param mixed $data   Data or WP_Error.
	 * @param int   $status Status.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function out( $data, $status = 200 ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * Bootstrap payload for the app.
	 *
	 * @return \WP_REST_Response
	 */
	public function bootstrap() {
		return $this->out(
			array(
				'integrations' => Registry::for_ui(),
				'settings'     => Settings::for_ui(),
				'version'      => BRNWH_VERSION,
			)
		);
	}

	/**
	 * Dashboard stats.
	 *
	 * @return \WP_REST_Response
	 */
	public function stats() {
		$stats          = Logs::stats( 14 );
		$flows          = Flows::all();
		$stats['flows'] = array(
			'total'  => count( $flows ),
			'active' => count(
				array_filter(
					$flows,
					static function ( $f ) {
						return 'active' === $f['status'];
					}
				)
			),
		);
		$stats['recent']      = Logs::query( array( 'per_page' => 8 ) )['items'];
		$stats['connections'] = count( Connections::all() );
		return $this->out( $stats );
	}

	/**
	 * List flows.
	 *
	 * @return \WP_REST_Response
	 */
	public function flows() {
		return $this->out( array_map( array( $this, 'flow_ui' ), Flows::all() ) );
	}

	/**
	 * Get flow.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_get( \WP_REST_Request $r ) {
		$flow = Flows::get( (int) $r['id'] );
		return $flow ? $this->out( $this->flow_ui( $flow ) ) : new \WP_Error( 'brnwh_not_found', __( 'Flow not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
	}

	/**
	 * Create flow.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_create( \WP_REST_Request $r ) {
		$flow = Flows::create( $this->body( $r ) );
		return is_wp_error( $flow ) ? $flow : $this->out( $this->flow_ui( $flow ), 201 );
	}

	/**
	 * Update flow.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_update( \WP_REST_Request $r ) {
		$flow = Flows::update( (int) $r['id'], $this->body( $r ) );
		return is_wp_error( $flow ) ? $flow : $this->out( $this->flow_ui( $flow ) );
	}

	/**
	 * Delete flow.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function flow_delete( \WP_REST_Request $r ) {
		return $this->out( array( 'deleted' => Flows::delete( (int) $r['id'] ) ) );
	}

	/**
	 * Duplicate.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_duplicate( \WP_REST_Request $r ) {
		$flow = Flows::duplicate( (int) $r['id'] );
		return is_wp_error( $flow ) ? $flow : $this->out( $this->flow_ui( $flow ), 201 );
	}

	/**
	 * Toggle status.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function flow_status( \WP_REST_Request $r ) {
		$b = $this->body( $r );
		return $this->out( $this->flow_ui( Flows::set_status( (int) $r['id'], isset( $b['status'] ) ? $b['status'] : 'paused' ) ) );
	}

	/**
	 * Start listening for sample data.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function flow_listen( \WP_REST_Request $r ) {
		Flows::listen( (int) $r['id'] );
		return $this->out( array( 'listening' => true ) );
	}

	/**
	 * Current sample (polled while listening).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_sample( \WP_REST_Request $r ) {
		$flow = Flows::get( (int) $r['id'] );
		if ( ! $flow ) {
			return new \WP_Error( 'brnwh_not_found', __( 'Flow not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
		}
		return $this->out(
			array(
				'sample'    => $flow['sample'],
				'listening' => Flows::is_listening( $flow['id'] ),
			)
		);
	}

	/**
	 * Rotate the endpoint key.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function flow_rotate( \WP_REST_Request $r ) {
		return $this->out( $this->flow_ui( Flows::rotate_key( (int) $r['id'] ) ) );
	}

	/**
	 * Export.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_export( \WP_REST_Request $r ) {
		$data = Flows::export( (int) $r['id'] );
		return $data ? $this->out( $data ) : new \WP_Error( 'brnwh_not_found', __( 'Flow not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
	}

	/**
	 * Import.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_import( \WP_REST_Request $r ) {
		$b    = $this->body( $r );
		$flow = isset( $b['flow'] ) && is_array( $b['flow'] ) ? $b['flow'] : null;
		if ( ! $flow || empty( $flow['trigger_type'] ) ) {
			return new \WP_Error( 'brnwh_bad_import', __( 'This file is not a BRN Webhooks flow export.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$flow['status'] = 'paused';
		$created        = Flows::create( $flow );
		return is_wp_error( $created ) ? $created : $this->out( $this->flow_ui( $created ), 201 );
	}

	/**
	 * Prepare an editor flow for testing (unsaved changes allowed).
	 *
	 * @param array $b Body.
	 * @return array|\WP_Error [flow, data]
	 */
	private function test_input( array $b ) {
		$id       = isset( $b['flow']['id'] ) ? (int) $b['flow']['id'] : 0;
		$existing = $id ? Flows::get( $id ) : null;
		$flow     = Flows::sanitize( isset( $b['flow'] ) && is_array( $b['flow'] ) ? $b['flow'] : array(), $existing );
		if ( is_wp_error( $flow ) ) {
			return $flow;
		}
		$flow['id'] = $id;
		$data       = isset( $b['data'] ) && is_array( $b['data'] ) ? Util::clean_deep( $b['data'] ) : null;
		if ( null === $data ) {
			$data = ( $existing && ! empty( $existing['sample']['data'] ) ) ? $existing['sample']['data'] : array();
		}
		return array( $flow, $data );
	}

	/**
	 * Test a whole flow.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_test( \WP_REST_Request $r ) {
		$in = $this->test_input( $this->body( $r ) );
		if ( is_wp_error( $in ) ) {
			return $in;
		}
		return $this->out( Engine::test( $in[0], $in[1] ) );
	}

	/**
	 * Test a single step (earlier steps' sample outputs can be passed in).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function flow_test_step( \WP_REST_Request $r ) {
		$b  = $this->body( $r );
		$in = $this->test_input( $b );
		if ( is_wp_error( $in ) ) {
			return $in;
		}
		list( $flow, $data ) = $in;
		$index = isset( $b['index'] ) ? (int) $b['index'] : 0;
		if ( ! isset( $flow['actions'][ $index ] ) ) {
			return new \WP_Error( 'brnwh_bad_step', __( 'Step not found.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$step                        = $flow['actions'][ $index ];
		$step['enabled']             = true;
		$step['conditions']          = Conditions::sanitize( array() );
		$prior                       = array();
		$outputs                     = isset( $b['step_outputs'] ) && is_array( $b['step_outputs'] ) ? $b['step_outputs'] : array();
		foreach ( array_slice( $flow['actions'], 0, $index ) as $i => $a ) {
			$prior[] = array(
				'action_id' => $a['id'],
				'status'    => 'success',
				'type'      => $a['type'],
				'name'      => $a['name'],
				'position'  => $i + 1,
				'output'    => isset( $outputs[ $i + 1 ] ) ? Util::clean_deep( $outputs[ $i + 1 ] ) : null,
				'note'      => __( 'Not run (single step test).', 'brn-webhooks' ),
			);
		}
		$flow['actions']    = array_merge( array_slice( $flow['actions'], 0, $index ), array( $step ) );
		$flow['conditions'] = Conditions::sanitize( array() );
		$run_id             = Logs::create(
			array(
				'flow_id'      => $flow['id'],
				'flow_name'    => $flow['name'],
				'trigger_type' => $flow['trigger_type'],
				'status'       => 'running',
				'is_test'      => true,
				'input'        => $data,
				'meta'         => array( 'source' => 'test_step' ),
			)
		);
		$flow['settings']['retries'] = 0;
		return $this->out( Engine::execute( $run_id, $flow, $data, $prior, 0, true, array( 'source' => 'test_step' ) ) );
	}

	/**
	 * Connections list.
	 *
	 * @return \WP_REST_Response
	 */
	public function connections() {
		return $this->out( array_map( array( Connections::class, 'for_ui' ), Connections::all() ) );
	}

	/**
	 * Create / update connection.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function connection_save( \WP_REST_Request $r ) {
		$id   = isset( $r['id'] ) ? (int) $r['id'] : null;
		$conn = Connections::save( $this->body( $r ), $id );
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		list( $ok, $message ) = Connections::test( $conn['id'] );
		$conn                 = Connections::get( $conn['id'] );
		return $this->out(
			array(
				'connection' => Connections::for_ui( $conn ),
				'test'       => array(
					'ok'      => $ok,
					'message' => $message,
				),
			),
			$id ? 200 : 201
		);
	}

	/**
	 * Delete connection.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function connection_delete( \WP_REST_Request $r ) {
		return $this->out( array( 'deleted' => Connections::delete( (int) $r['id'] ) ) );
	}

	/**
	 * Test connection.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function connection_test( \WP_REST_Request $r ) {
		list( $ok, $message ) = Connections::test( (int) $r['id'] );
		$conn                 = Connections::get( (int) $r['id'] );
		return $this->out(
			array(
				'ok'         => $ok,
				'message'    => $message,
				'connection' => $conn ? Connections::for_ui( $conn ) : null,
			)
		);
	}

	/**
	 * Logs list.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function logs( \WP_REST_Request $r ) {
		return $this->out(
			Logs::query(
				array(
					'flow_id'  => (int) $r->get_param( 'flow_id' ),
					'status'   => (string) $r->get_param( 'status' ),
					'search'   => sanitize_text_field( (string) $r->get_param( 'search' ) ),
					'page'     => (int) $r->get_param( 'page' ),
					'per_page' => (int) $r->get_param( 'per_page' ),
				)
			)
		);
	}

	/**
	 * Clear logs.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function logs_clear( \WP_REST_Request $r ) {
		return $this->out(
			array(
				'deleted' => Logs::clear(
					array(
						'flow_id' => (int) $r->get_param( 'flow_id' ),
						'status'  => (string) $r->get_param( 'status' ),
					)
				),
			)
		);
	}

	/**
	 * One log.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function log_get( \WP_REST_Request $r ) {
		$log = Logs::get( (int) $r['id'] );
		return $log ? $this->out( $log ) : new \WP_Error( 'brnwh_not_found', __( 'Run not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
	}

	/**
	 * Delete a log.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function log_delete( \WP_REST_Request $r ) {
		Logs::delete( (int) $r['id'] );
		return $this->out( array( 'deleted' => true ) );
	}

	/**
	 * Replay as a new run.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function log_replay( \WP_REST_Request $r ) {
		return $this->out( Engine::replay( (int) $r['id'] ) );
	}

	/**
	 * Retry the failed steps now.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function log_retry( \WP_REST_Request $r ) {
		return $this->out( Engine::retry_now( (int) $r['id'] ) );
	}

	/**
	 * Dynamic options from an integration.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function options( \WP_REST_Request $r ) {
		$b      = $this->body( $r );
		$kind   = isset( $b['kind'] ) ? $b['kind'] : 'action';
		$id     = isset( $b['integration'] ) ? sanitize_key( $b['integration'] ) : '';
		$source = isset( $b['source'] ) ? sanitize_key( $b['source'] ) : '';
		$config = isset( $b['config'] ) && is_array( $b['config'] ) ? Util::clean_deep( $b['config'] ) : array();
		$item   = 'trigger' === $kind ? Registry::trigger( $id ) : Registry::action( $id );
		if ( ! $item ) {
			return new \WP_Error( 'brnwh_bad_integration', __( 'Unknown integration.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$conn_id = isset( $b['connection_id'] ) ? (int) $b['connection_id'] : ( isset( $config['connection_id'] ) ? (int) $config['connection_id'] : 0 );
		$conn    = $conn_id ? Connections::get( $conn_id ) : null;
		$result  = $item->get_options( $source, $config, $conn );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
		}
		return $this->out( $result );
	}

	/**
	 * Known fields of a trigger config (from form definitions).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function known_fields( \WP_REST_Request $r ) {
		$b       = $this->body( $r );
		$trigger = Registry::trigger( isset( $b['trigger'] ) ? sanitize_key( $b['trigger'] ) : '' );
		$config  = isset( $b['config'] ) && is_array( $b['config'] ) ? Util::clean_deep( $b['config'] ) : array();
		return $this->out( $trigger ? (object) $trigger->known_fields( $config ) : new \stdClass() );
	}

	/**
	 * Settings.
	 *
	 * @return \WP_REST_Response
	 */
	public function settings() {
		$s           = Settings::for_ui();
		$s['update'] = Updater::status();
		return $this->out( $s );
	}

	/**
	 * Save settings.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function settings_save( \WP_REST_Request $r ) {
		$s           = Settings::update( $this->body( $r ) );
		$s['update'] = Updater::status();
		return $this->out( $s );
	}

	/**
	 * Check for updates now.
	 *
	 * @return \WP_REST_Response
	 */
	public function updates_check() {
		delete_site_transient( Updater::CACHE_KEY );
		Updater::latest( true );
		wp_clean_plugins_cache( false );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		return $this->out( Updater::status() );
	}
}
