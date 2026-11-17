<?php
/**
 * Flow execution engine.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatches trigger events to flows and runs them.
 *
 * Background runs: the visitor's request is finished first (fastcgi_finish_request /
 * litespeed_finish_request) and the flow runs afterwards in the same PHP process. Where that
 * is not possible, a signed non-blocking loopback request to admin-ajax.php runs it. A cron
 * sweep every minute picks up anything left behind and processes scheduled retries.
 */
class Engine {

	/**
	 * Runs deferred to the end of the request.
	 *
	 * @var int[]
	 */
	private static $deferred = array();

	/**
	 * Shutdown hook added.
	 *
	 * @var bool
	 */
	private static $shutdown_hooked = false;

	/**
	 * Retry back-off in seconds per attempt.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 60, 300, 900, 3600, 10800, 21600 );

	/**
	 * Results of runs finished in this request (survive log-level deletion).
	 *
	 * @var array
	 */
	private static $results = array();

	/**
	 * Result of a run finished during this request.
	 *
	 * @param int $run_id Run id.
	 * @return array|null {status, steps, message}
	 */
	public static function result( $run_id ) {
		return isset( self::$results[ (int) $run_id ] ) ? self::$results[ (int) $run_id ] : Logs::get( $run_id );
	}

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'wp_ajax_nopriv_brnwh_worker', array( __CLASS__, 'worker' ) );
		add_action( 'wp_ajax_brnwh_worker', array( __CLASS__, 'worker' ) );
	}

	/**
	 * A trigger fired: run every matching flow and capture samples for listening flows.
	 *
	 * @param string $type  Trigger type.
	 * @param array  $data  Data.
	 * @param array  $event Event info.
	 */
	public static function trigger( $type, array $data, array $event = array() ) {
		$listening = Flows::listening_ids();
		if ( ! Flows::has_active( $type ) && ! $listening ) {
			return;
		}
		$trigger = Registry::trigger( $type );
		if ( ! $trigger ) {
			return;
		}
		foreach ( Flows::by_trigger( $type, ! empty( $listening ) ) as $flow ) {
			if ( ! $trigger->matches( $flow['trigger_config'], $event ) ) {
				continue;
			}
			if ( in_array( $flow['id'], $listening, true ) || empty( $flow['sample']['data'] ) ) {
				Flows::save_sample( $flow['id'], $data );
			}
			if ( 'active' === $flow['status'] ) {
				self::dispatch( $flow, $data, array( 'source' => $type ) + $event );
			}
		}
	}

	/**
	 * Queue (or run) a flow.
	 *
	 * @param array $flow  Flow.
	 * @param array $data  Trigger data.
	 * @param array $meta  Run meta.
	 * @param bool  $sync  Force synchronous run.
	 * @return int Run id.
	 */
	public static function dispatch( array $flow, array $data, array $meta = array(), $sync = false ) {
		$run_id = Logs::create(
			array(
				'flow_id'      => $flow['id'],
				'flow_name'    => $flow['name'],
				'trigger_type' => $flow['trigger_type'],
				'status'       => 'queued',
				'input'        => $data,
				'meta'         => $meta,
			)
		);
		if ( ! $run_id ) {
			return 0;
		}
		if ( $sync || empty( $flow['settings']['async'] ) ) {
			self::run( $run_id );
		} else {
			self::defer( $run_id );
		}
		return $run_id;
	}

	/**
	 * Defer a run until the response is sent.
	 *
	 * @param int $run_id Run id.
	 */
	private static function defer( $run_id ) {
		self::$deferred[] = (int) $run_id;
		if ( ! self::$shutdown_hooked ) {
			self::$shutdown_hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'process_deferred' ), 9999 );
		}
	}

	/**
	 * Process deferred runs after the response has been sent.
	 */
	public static function process_deferred() {
		$ids            = self::$deferred;
		self::$deferred = array();
		if ( ! $ids ) {
			return;
		}
		$can_finish = function_exists( 'fastcgi_finish_request' ) || function_exists( 'litespeed_finish_request' );
		if ( $can_finish && apply_filters( 'brnwh_run_after_response', true ) ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} else {
				litespeed_finish_request();
			}
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			foreach ( $ids as $id ) {
				self::run( $id );
			}
			return;
		}
		foreach ( $ids as $id ) {
			self::spawn_worker( $id );
		}
	}

	/**
	 * Secret used to sign worker requests.
	 *
	 * @return string
	 */
	private static function worker_secret() {
		$secret = get_option( 'brnwh_worker_secret' );
		if ( ! $secret ) {
			$secret = Crypto::token( 32 );
			update_option( 'brnwh_worker_secret', $secret, false );
		}
		return (string) $secret;
	}

	/**
	 * Fire a non-blocking loopback request that processes one run.
	 *
	 * @param int $run_id Run id.
	 */
	private static function spawn_worker( $run_id ) {
		$sig = hash_hmac( 'sha256', 'run:' . (int) $run_id, self::worker_secret() );
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.5,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'brnwh_worker',
					'run'    => (int) $run_id,
					'sig'    => $sig,
				),
			)
		);
	}

	/**
	 * Loopback worker endpoint (signed).
	 */
	public static function worker() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authenticated by HMAC signature.
		$run_id = isset( $_POST['run'] ) ? absint( $_POST['run'] ) : 0;
		$sig    = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : '';
		// phpcs:enable
		$expected = hash_hmac( 'sha256', 'run:' . $run_id, self::worker_secret() );
		if ( ! $run_id || ! hash_equals( $expected, $sig ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::run( $run_id );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	/**
	 * Cron sweep: due retries + runs a worker never picked up.
	 */
	public static function sweep() {
		Logs::recover_stuck();
		$start = time();
		foreach ( Logs::due( 25 ) as $id ) {
			if ( time() - $start > 45 ) {
				break;
			}
			self::run( $id );
		}
	}

	/**
	 * Execute a queued run.
	 *
	 * @param int $run_id Run id.
	 * @return array|null Final log.
	 */
	public static function run( $run_id ) {
		$before = Logs::raw( $run_id );
		if ( ! $before || ! Logs::claim( $run_id ) ) {
			return Logs::get( $run_id );
		}
		$row  = Logs::raw( $run_id );
		$flow = Flows::get( (int) $row['flow_id'] );
		// A run recovered after a crash may exceed the flow's retry budget.
		if ( $flow && 'retrying' === $before['status'] && (int) $row['attempts'] > (int) $flow['settings']['retries'] ) {
			Logs::update(
				$run_id,
				array(
					'status'      => 'failed',
					'finished_at' => Util::now(),
				)
			);
			return Logs::get( $run_id );
		}
		if ( ! $flow ) {
			Logs::update(
				$run_id,
				array(
					'status'      => 'failed',
					'message'     => __( 'The flow no longer exists.', 'brn-webhooks' ),
					'finished_at' => Util::now(),
				)
			);
			return Logs::get( $run_id );
		}
		$data  = Util::unjson( ! empty( $row['payload'] ) ? $row['payload'] : $row['input'] );
		$prior = Util::unjson( $row['steps'] );
		return self::execute( $run_id, $flow, (array) $data, (array) $prior, (int) $row['attempts'], (bool) $row['is_test'], Util::unjson( $row['meta'] ) );
	}

	/**
	 * Core execution.
	 *
	 * @param int   $run_id   Run id.
	 * @param array $flow     Flow (may be an unsaved editor version for tests).
	 * @param array $data     Trigger data.
	 * @param array $prior    Steps from previous attempts (successful ones are not repeated).
	 * @param int   $attempts Attempts so far.
	 * @param bool  $is_test  Test run.
	 * @param array $meta     Meta.
	 * @return array|null
	 */
	public static function execute( $run_id, array $flow, array $data, array $prior, $attempts, $is_test, $meta = array() ) {
		$started = microtime( true );
		$trigger = Registry::trigger( $flow['trigger_type'] );
		$ctx     = new Context( $data, $flow, $run_id, $is_test );
		$steps   = array();
		$meta    = is_array( $meta ) ? $meta : array();

		// Enrich (e.g. Facebook lead details) — only on the first attempt or if it failed before.
		if ( $trigger && empty( $meta['prepared'] ) ) {
			try {
				$prepared = $trigger->prepare( $data, $flow );
				if ( $prepared !== $data ) {
					$ctx->set_data( $prepared );
					$data = $prepared;
					$redacted = Settings::get( 'mask_sensitive' ) ? Util::redact( $data ) : $data;
					Logs::update(
						$run_id,
						array(
							'input'   => Util::json( $redacted ),
							'payload' => Util::json( $data ),
						)
					);
				}
				$meta['prepared'] = true;
				Logs::update( $run_id, array( 'meta' => $meta ) );
			} catch ( \Throwable $e ) {
				return self::finish( $run_id, $flow, 'failed', $e->getMessage(), array(), $attempts, $started, $is_test, $e instanceof Action_Error ? $e->retryable : true );
			}
		}

		// Flow-level conditions.
		list( $passed, $explain ) = Conditions::evaluate( $flow['conditions'], $ctx->vars() );
		if ( ! $passed ) {
			$steps[] = array(
				'type'       => '_filter',
				'name'       => __( 'Filter', 'brn-webhooks' ),
				'status'     => 'filtered',
				'conditions' => $explain,
			);
			return self::finish( $run_id, $flow, 'filtered', __( 'Conditions not met — no actions were run.', 'brn-webhooks' ), $steps, $attempts, $started, $is_test, false );
		}
		if ( $explain ) {
			$steps[] = array(
				'type'       => '_filter',
				'name'       => __( 'Filter', 'brn-webhooks' ),
				'status'     => 'success',
				'conditions' => $explain,
			);
		}

		$prior_by_id = array();
		foreach ( $prior as $p ) {
			if ( isset( $p['action_id'] ) ) {
				$prior_by_id[ $p['action_id'] ] = $p;
			}
		}

		$failed    = 0;
		$succeeded = 0;
		$retryable = false;
		$stopped   = false;
		$message   = '';

		foreach ( array_values( $flow['actions'] ) as $i => $action_cfg ) {
			$pos    = $i + 1;
			$action = Registry::action( $action_cfg['type'] );
			$step   = array(
				'action_id' => $action_cfg['id'],
				'position'  => $pos,
				'type'      => $action_cfg['type'],
				'name'      => '' !== $action_cfg['name'] ? $action_cfg['name'] : ( $action ? $action->label() : $action_cfg['type'] ),
			);

			if ( $stopped ) {
				$step['status'] = 'not_run';
				$steps[]        = $step;
				continue;
			}

			// Already done in a previous attempt.
			if ( isset( $prior_by_id[ $action_cfg['id'] ] ) && 'success' === $prior_by_id[ $action_cfg['id'] ]['status'] ) {
				$done = $prior_by_id[ $action_cfg['id'] ];
				$ctx->set_step( $pos, isset( $done['output'] ) ? $done['output'] : null );
				$steps[] = $done;
				++$succeeded;
				continue;
			}

			if ( empty( $action_cfg['enabled'] ) ) {
				$step['status'] = 'skipped';
				$step['note']   = __( 'Step is disabled.', 'brn-webhooks' );
				$steps[]        = $step;
				continue;
			}
			if ( ! $action ) {
				$step['status'] = 'failed';
				$step['error']  = __( 'Unknown action type.', 'brn-webhooks' );
				$steps[]        = $step;
				++$failed;
				$stopped = empty( $action_cfg['continue_on_error'] );
				continue;
			}

			list( $ok, $why ) = Conditions::evaluate( $action_cfg['conditions'], $ctx->vars() );
			if ( ! $ok ) {
				$step['status']     = 'skipped';
				$step['note']       = __( 'Step conditions not met.', 'brn-webhooks' );
				$step['conditions'] = $why;
				$steps[]            = $step;
				continue;
			}

			$t0 = microtime( true );
			Http::reset_traces();
			try {
				$connection = null;
				if ( $action->connection_type() && ! empty( $action_cfg['connection_id'] ) ) {
					$connection = Connections::get( (int) $action_cfg['connection_id'] );
					if ( ! $connection && ! $action->connection_optional() ) {
						throw new Action_Error( __( 'The selected connection was deleted.', 'brn-webhooks' ), false );
					}
				}
				$output         = $action->execute( (array) $action_cfg['config'], $ctx, $connection );
				$step['status'] = 'success';
				$step['output'] = self::shrink( $output );
				$ctx->set_step( $pos, $output );
				++$succeeded;
			} catch ( \Throwable $e ) {
				$step['status'] = 'failed';
				$step['error']  = $e->getMessage();
				$retryable      = $retryable || ! ( $e instanceof Action_Error ) || $e->retryable;
				++$failed;
				if ( '' === $message ) {
					/* translators: 1: step number, 2: error */
					$message = sprintf( __( 'Step %1$d failed: %2$s', 'brn-webhooks' ), $pos, $e->getMessage() );
				}
				if ( empty( $action_cfg['continue_on_error'] ) ) {
					$stopped = true;
				}
			}
			$step['duration_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$step['requests']    = Http::traces();
			$steps[]             = $step;
			// Save progress so a crashed worker never repeats a step that already succeeded.
			Logs::update( $run_id, array( 'steps' => $steps ) );
		}

		if ( 0 === $failed ) {
			$status  = 'success';
			$message = $succeeded ? '' : __( 'No actions to run.', 'brn-webhooks' );
		} else {
			$status = $succeeded ? 'partial' : 'failed';
		}
		return self::finish( $run_id, $flow, $status, $message, $steps, $attempts, $started, $is_test, $retryable );
	}

	/**
	 * Keep outputs small enough for the log.
	 *
	 * @param mixed $output Output.
	 * @return mixed
	 */
	private static function shrink( $output ) {
		$json = Util::json( $output );
		if ( strlen( $json ) <= 30000 ) {
			return $output;
		}
		return array( '_truncated' => Util::truncate( $json, 30000 ) );
	}

	/**
	 * Store the result, schedule retries, notify, apply log level.
	 *
	 * @param int    $run_id    Run id.
	 * @param array  $flow      Flow.
	 * @param string $status    Status.
	 * @param string $message   Message.
	 * @param array  $steps     Steps.
	 * @param int    $attempts  Attempts before this one.
	 * @param float  $started   Start time.
	 * @param bool   $is_test   Test.
	 * @param bool   $retryable Retry makes sense.
	 * @return array|null
	 */
	private static function finish( $run_id, array $flow, $status, $message, array $steps, $attempts, $started, $is_test, $retryable ) {
		$attempts++;
		$max    = isset( $flow['settings']['retries'] ) ? (int) $flow['settings']['retries'] : 0;
		$fields = array(
			'status'          => $status,
			'message'         => $message,
			'steps'           => $steps,
			'attempts'        => $attempts,
			'duration_ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'finished_at'     => Util::now(),
			'next_attempt_at' => null,
		);

		$will_retry = in_array( $status, array( 'failed', 'partial' ), true ) && $retryable && ! $is_test && $attempts <= $max;
		if ( $will_retry ) {
			$delay                     = self::BACKOFF[ min( $attempts - 1, count( self::BACKOFF ) - 1 ) ];
			$fields['status']          = 'retrying';
			$fields['next_attempt_at'] = Util::now( $delay );
			/* translators: %s: minutes */
			$fields['message'] = $message . ' ' . sprintf( __( '(retry in %s min)', 'brn-webhooks' ), max( 1, (int) round( $delay / 60 ) ) );
		}
		if ( in_array( $status, array( 'success', 'filtered' ), true ) ) {
			$fields['payload'] = null; // Raw data no longer needed.
		}
		Logs::update( $run_id, $fields );
		self::$results[ (int) $run_id ] = array(
			'id'      => (int) $run_id,
			'status'  => $fields['status'],
			'message' => $fields['message'],
			'steps'   => $steps,
		);

		if ( ! $is_test && ! empty( $flow['id'] ) && ! $will_retry ) {
			Flows::touch_run( $flow['id'], $status );
			if ( in_array( $status, array( 'failed', 'partial' ), true ) ) {
				self::notify_failure( $flow, $run_id, $message );
			}
		}

		// Log level.
		$level = isset( $flow['settings']['log'] ) && 'default' !== $flow['settings']['log'] ? $flow['settings']['log'] : Settings::get( 'log_level' );
		if ( ! $is_test && ! $will_retry ) {
			$drop = ( 'none' === $level )
				|| ( 'errors' === $level && in_array( $status, array( 'success', 'filtered' ), true ) )
				|| ( 'filtered' === $status && ! Settings::get( 'log_filtered' ) );
			if ( $drop ) {
				Logs::delete( $run_id );
				return array_merge( $fields, array( 'id' => $run_id, 'deleted' => true ) );
			}
		}

		do_action( 'brnwh_run_finished', $run_id, $status, $flow );
		return Logs::get( $run_id );
	}

	/**
	 * Email the admin about failures (max one email per flow per hour).
	 *
	 * @param array  $flow    Flow.
	 * @param int    $run_id  Run id.
	 * @param string $message Message.
	 */
	private static function notify_failure( array $flow, $run_id, $message ) {
		if ( ! Settings::get( 'notify_failures' ) ) {
			return;
		}
		$key = 'brnwh_notified_' . (int) $flow['id'];
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		$to = Settings::get( 'notify_email' );
		$to = $to ? $to : get_option( 'admin_email' );
		$url = admin_url( 'admin.php?page=brn-webhooks#/logs/' . (int) $run_id );
		/* translators: 1: site name, 2: flow name */
		$subject = sprintf( __( '[%1$s] Automation failed: %2$s', 'brn-webhooks' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $flow['name'] );
		$body    = sprintf(
			/* translators: 1: flow name, 2: error, 3: log url */
			__( "The automation \"%1\$s\" failed.\n\n%2\$s\n\nDetails: %3\$s\n\nYou will get at most one email per flow per hour.", 'brn-webhooks' ),
			$flow['name'],
			$message,
			$url
		);
		wp_mail( $to, $subject, $body );
	}

	/**
	 * Run a flow synchronously as a test (editor "Test" button).
	 *
	 * @param array $flow Flow (possibly unsaved).
	 * @param array $data Test data.
	 * @return array|null Log.
	 */
	public static function test( array $flow, array $data ) {
		$run_id = Logs::create(
			array(
				'flow_id'      => isset( $flow['id'] ) ? (int) $flow['id'] : 0,
				'flow_name'    => $flow['name'],
				'trigger_type' => $flow['trigger_type'],
				'status'       => 'running',
				'is_test'      => true,
				'input'        => $data,
				'meta'         => array( 'source' => 'test' ),
			)
		);
		$flow['settings']['retries'] = 0;
		return self::execute( $run_id, $flow, $data, array(), 0, true, array( 'source' => 'test' ) );
	}

	/**
	 * Replay a logged run as a new run.
	 *
	 * @param int $run_id Run id.
	 * @return array|\WP_Error New log.
	 */
	public static function replay( $run_id ) {
		$row = Logs::raw( $run_id );
		if ( ! $row ) {
			return new \WP_Error( 'brnwh_not_found', __( 'Run not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
		}
		$flow = Flows::get( (int) $row['flow_id'] );
		if ( ! $flow ) {
			return new \WP_Error( 'brnwh_not_found', __( 'The flow no longer exists.', 'brn-webhooks' ), array( 'status' => 404 ) );
		}
		$data = Util::unjson( ! empty( $row['payload'] ) ? $row['payload'] : $row['input'] );
		if ( empty( $row['payload'] ) && false !== strpos( (string) $row['input'], '••••••' ) ) {
			return new \WP_Error( 'brnwh_masked', __( 'This run contained masked sensitive fields, so it cannot be replayed with the original data.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$new  = self::dispatch( $flow, (array) $data, array( 'source' => 'replay', 'replay_of' => (int) $run_id ), true );
		return Logs::get( $new );
	}

	/**
	 * Retry a failed run now (keeps successful steps).
	 *
	 * @param int $run_id Run id.
	 * @return array|\WP_Error
	 */
	public static function retry_now( $run_id ) {
		$row = Logs::raw( $run_id );
		if ( ! $row || ! in_array( $row['status'], array( 'failed', 'partial', 'retrying' ), true ) ) {
			return new \WP_Error( 'brnwh_bad_state', __( 'Only failed runs can be retried.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		Logs::update( (int) $run_id, array( 'status' => 'queued' ) );
		$log = self::run( (int) $run_id );
		return $log ? $log : new \WP_Error( 'brnwh_gone', __( 'Run finished and was not logged (log level).', 'brn-webhooks' ) );
	}
}
