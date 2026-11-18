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

		// Pull a one-off "skip the wait" request out of the meta (set by "Continue now").
		$skip_wait = isset( $meta['skip_wait'] ) ? (string) $meta['skip_wait'] : '';
		if ( '' !== $skip_wait ) {
			unset( $meta['skip_wait'] );
			Logs::update( $run_id, array( 'meta' => $meta ) );
		}

		$st = array(
			'run_id'    => (int) $run_id,
			'is_test'   => (bool) $is_test,
			'skip_wait' => $skip_wait,
			'steps'     => $steps,
			'prior'     => self::index_prior( $prior ),
			'graph'     => self::graph( $flow['actions'] ),
			'failed'    => 0,
			'handled'   => 0,
			'succeeded' => 0,
			'filtered'  => 0,
			'retryable' => false,
			'message'   => '',
			'halt'      => false,
			'suspend'   => 0,
		);
		self::run_children( 'trigger', 'out', $ctx, $st, self::scope() );

		if ( $st['suspend'] ) {
			return self::suspend( $run_id, $st, $started );
		}

		if ( $st['failed'] === $st['handled'] ) {
			$status  = 'success';
			$message = '';
			if ( $st['handled'] ) {
				/* translators: %d: number of steps */
				$message = sprintf( _n( '%d step failed and was handled by its error path.', '%d steps failed and were handled by their error paths.', $st['handled'], 'brn-webhooks' ), $st['handled'] );
			} elseif ( ! $st['succeeded'] && $st['filtered'] ) {
				$status  = 'filtered';
				$message = __( 'A filter stopped the flow — no actions were run.', 'brn-webhooks' );
			} elseif ( ! $st['succeeded'] ) {
				$message = __( 'No actions to run.', 'brn-webhooks' );
			}
		} else {
			$status  = $st['succeeded'] ? 'partial' : 'failed';
			$message = $st['message'];
		}
		return self::finish( $run_id, $flow, $status, $message, $st['steps'], $attempts, $started, $is_test, $st['retryable'] );
	}

	/**
	 * Default traversal scope.
	 *
	 * @return array
	 */
	private static function scope() {
		return array(
			'depth'  => 0,
			'iter'   => '',
			'index'  => 0,
			'loop'   => false,
			'branch' => '',
		);
	}

	/**
	 * Previous attempt's steps by key.
	 *
	 * @param array $prior Steps.
	 * @return array
	 */
	private static function index_prior( array $prior ) {
		$out = array();
		foreach ( $prior as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$key = isset( $p['key'] ) ? $p['key'] : ( isset( $p['action_id'] ) ? $p['action_id'] : '' );
			if ( '' !== $key ) {
				$out[ $key ] = $p;
			}
		}
		return $out;
	}

	/**
	 * Build the node tree. Older flows (no "parent" keys) become a simple chain.
	 *
	 * @param array $nodes Nodes.
	 * @return array {by, kids, pos, legacy}
	 */
	public static function graph( array $nodes ) {
		$nodes  = array_values( $nodes );
		$legacy = $nodes && ! array_key_exists( 'parent', $nodes[0] );
		$by     = array();
		$kids   = array();
		$pos    = array();
		$order  = array();
		$prev   = 'trigger';
		foreach ( $nodes as $i => $n ) {
			if ( empty( $n['id'] ) ) {
				continue;
			}
			$pos[ $n['id'] ]   = $i + 1;
			$order[ $n['id'] ] = $i;
			if ( $legacy ) {
				if ( '' === $n['type'] ) {
					continue;
				}
				$n['parent'] = $prev;
				$n['port']   = 'out';
				$n['ui']     = array(
					'x' => 0,
					'y' => $i,
				);
				$prev        = $n['id'];
			}
			$n['parent']    = isset( $n['parent'] ) ? (string) $n['parent'] : '';
			$n['port']      = isset( $n['port'] ) ? (string) $n['port'] : 'out';
			$by[ $n['id'] ] = $n;
			if ( '' !== $n['parent'] ) {
				$kids[ $n['parent'] ][ $n['port'] ][] = $n['id'];
			}
		}
		// Siblings on the same port run left to right (then top to bottom).
		foreach ( $kids as $parent => $ports ) {
			foreach ( $ports as $port => $ids ) {
				usort(
					$ids,
					static function ( $a, $b ) use ( $by, $order ) {
						$ua = isset( $by[ $a ]['ui'] ) ? $by[ $a ]['ui'] : array( 'x' => 0, 'y' => 0 );
						$ub = isset( $by[ $b ]['ui'] ) ? $by[ $b ]['ui'] : array( 'x' => 0, 'y' => 0 );
						if ( (int) $ua['x'] !== (int) $ub['x'] ) {
							return (int) $ua['x'] < (int) $ub['x'] ? -1 : 1;
						}
						if ( (int) $ua['y'] !== (int) $ub['y'] ) {
							return (int) $ua['y'] < (int) $ub['y'] ? -1 : 1;
						}
						return $order[ $a ] - $order[ $b ];
					}
				);
				$kids[ $parent ][ $port ] = $ids;
			}
		}
		return array(
			'by'     => $by,
			'kids'   => $kids,
			'pos'    => $pos,
			'legacy' => $legacy,
		);
	}

	/**
	 * Does a node have children on a port?
	 *
	 * @param array  $st   State.
	 * @param string $id   Node id.
	 * @param string $port Port.
	 * @return bool
	 */
	private static function has_children( array $st, $id, $port ) {
		return ! empty( $st['graph']['kids'][ $id ][ $port ] );
	}

	/**
	 * Run the nodes hanging from a parent's port, in order.
	 *
	 * @param string  $parent Parent id ("trigger" for the root).
	 * @param string  $port   Port.
	 * @param Context $ctx    Context.
	 * @param array   $st     State (by reference).
	 * @param array   $scope  Scope.
	 */
	private static function run_children( $parent, $port, Context $ctx, array &$st, array $scope ) {
		if ( empty( $st['graph']['kids'][ $parent ][ $port ] ) ) {
			return;
		}
		foreach ( $st['graph']['kids'][ $parent ][ $port ] as $id ) {
			if ( $st['halt'] || $st['suspend'] ) {
				return;
			}
			self::run_node( $st['graph']['by'][ $id ], $ctx, $st, $scope );
		}
	}

	/**
	 * Letter for a path index (0 → A).
	 *
	 * @param int $i Index.
	 * @return string
	 */
	private static function letter( $i ) {
		return $i < 26 ? chr( 65 + $i ) : (string) ( $i + 1 );
	}

	/**
	 * Save progress so a crashed worker never repeats a step that already succeeded.
	 *
	 * @param array $st State.
	 */
	private static function save_progress( array $st ) {
		Logs::update( $st['run_id'], array( 'steps' => $st['steps'] ) );
	}

	/**
	 * Run one node (and, depending on the result, its children).
	 *
	 * @param array   $n     Node.
	 * @param Context $ctx   Context.
	 * @param array   $st    State (by reference).
	 * @param array   $scope Scope.
	 */
	private static function run_node( array $n, Context $ctx, array &$st, array $scope ) {
		if ( '' === $n['type'] ) {
			return; // Not configured yet.
		}
		$action = Registry::action( $n['type'] );
		$kind   = $action ? $action->kind() : 'action';
		$id     = $n['id'];
		$key    = $id . $scope['iter'];
		$pos    = isset( $st['graph']['pos'][ $id ] ) ? $st['graph']['pos'][ $id ] : null;
		$step   = array(
			'action_id' => $id,
			'key'       => $key,
			'type'      => $n['type'],
			'kind'      => $kind,
			'name'      => '' !== $n['name'] ? $n['name'] : ( $action ? $action->label() : $n['type'] ),
			'depth'     => $scope['depth'],
		);
		if ( $st['graph']['legacy'] ) {
			$step['position'] = $pos;
		}
		if ( '' !== $scope['branch'] ) {
			$step['branch'] = $scope['branch'];
		}
		if ( $scope['index'] ) {
			$step['iteration'] = $scope['index'];
		}
		$next = 'loop' === $kind ? 'done' : 'out';

		if ( empty( $n['enabled'] ) ) {
			$step['status'] = 'skipped';
			$step['note']   = __( 'Step is disabled.', 'brn-webhooks' );
			$st['steps'][]  = $step;
			if ( in_array( $kind, array( 'action', 'filter', 'delay', 'loop' ), true ) ) {
				self::run_children( $id, $next, $ctx, $st, $scope );
			}
			return;
		}

		if ( ! $action ) {
			$step['status'] = 'failed';
			$step['error']  = __( 'Unknown action type (is its plugin still active?).', 'brn-webhooks' );
			self::fail( $n, $step, $ctx, $st, $scope, true );
			return;
		}

		// "Only run this step if…" (older flows) — the step is skipped, the flow continues.
		if ( in_array( $kind, array( 'action', 'delay', 'loop' ), true ) ) {
			list( $ok, $why ) = Conditions::evaluate( $n['conditions'], $ctx->vars() );
			if ( ! $ok ) {
				$step['status']     = 'skipped';
				$step['note']       = __( 'Step conditions not met.', 'brn-webhooks' );
				$step['conditions'] = $why;
				$st['steps'][]      = $step;
				self::run_children( $id, $next, $ctx, $st, $scope );
				return;
			}
		}

		$deeper = array_merge( $scope, array( 'depth' => $scope['depth'] + 1 ) );

		switch ( $kind ) {
			case 'filter':
				list( $ok, $why )   = Conditions::evaluate( $n['conditions'], $ctx->vars() );
				$step['conditions'] = $why;
				$step['status']     = $ok ? 'success' : 'filtered';
				if ( ! $ok ) {
					$step['note'] = __( 'Conditions not met — this branch stopped here.', 'brn-webhooks' );
					++$st['filtered'];
				}
				$st['steps'][] = $step;
				if ( $ok ) {
					self::run_children( $id, 'out', $ctx, $st, $scope );
				}
				return;

			case 'if':
				list( $ok, $why )     = Conditions::evaluate( $n['conditions'], $ctx->vars() );
				$step['conditions']   = $why;
				$step['status']       = 'success';
				$step['branch_taken'] = $ok ? 'yes' : 'no';
				$step['note']         = $ok ? __( 'Conditions matched → "Yes" branch.', 'brn-webhooks' ) : __( 'Conditions not met → "No" branch.', 'brn-webhooks' );
				$step['output']       = array( 'result' => $ok );
				$ctx->set_step( $pos, $step['output'], $id );
				$st['steps'][] = $step;
				self::run_children( $id, $ok ? 'yes' : 'no', $ctx, $st, array_merge( $deeper, array( 'branch' => $ok ? __( 'Yes', 'brn-webhooks' ) : __( 'No', 'brn-webhooks' ) ) ) );
				return;

			case 'router':
				$cfg    = $n['config'];
				$mode   = isset( $cfg['mode'] ) ? $cfg['mode'] : 'first';
				$chosen = array();
				$report = array();
				foreach ( isset( $cfg['paths'] ) ? (array) $cfg['paths'] : array() as $i => $p ) {
					/* translators: %s: letter */
					$label = '' !== $p['name'] ? $p['name'] : sprintf( __( 'Path %s', 'brn-webhooks' ), self::letter( $i ) );
					if ( $chosen && 'all' !== $mode ) {
						$report[] = array(
							'name'    => $label,
							'matched' => false,
							'checked' => false,
						);
						continue;
					}
					list( $ok, $why ) = Conditions::evaluate( isset( $p['conditions'] ) ? $p['conditions'] : array(), $ctx->vars() );
					$report[]         = array(
						'name'       => $label,
						'matched'    => $ok,
						'checked'    => true,
						'conditions' => $why,
					);
					if ( $ok ) {
						$chosen[] = array(
							'port'  => 'p:' . $p['id'],
							'label' => $label,
						);
					}
				}
				if ( ! $chosen && ! empty( $cfg['else'] ) ) {
					$chosen[] = array(
						'port'  => 'else',
						'label' => __( 'Otherwise', 'brn-webhooks' ),
					);
					$report[] = array(
						'name'    => __( 'Otherwise', 'brn-webhooks' ),
						'matched' => true,
						'checked' => true,
					);
				}
				$labels         = wp_list_pluck( $chosen, 'label' );
				$step['status'] = 'success';
				$step['paths']  = $report;
				/* translators: %s: path names */
				$step['note']   = $chosen ? sprintf( __( 'Took: %s', 'brn-webhooks' ), implode( ', ', $labels ) ) : __( 'No path matched.', 'brn-webhooks' );
				$step['output'] = array( 'paths' => $labels );
				$ctx->set_step( $pos, $step['output'], $id );
				$st['steps'][] = $step;
				foreach ( $chosen as $c ) {
					if ( $st['halt'] || $st['suspend'] ) {
						return;
					}
					self::run_children( $id, $c['port'], $ctx, $st, array_merge( $deeper, array( 'branch' => $c['label'] ) ) );
				}
				return;

			case 'delay':
				self::run_delay( $n, $step, $ctx, $st, $scope );
				return;

			case 'loop':
				$items          = Actions\Loop::items( (array) $n['config'], $ctx );
				$count          = count( $items );
				$step['status'] = 'success';
				/* translators: %d: number of items */
				$step['note']  = sprintf( _n( '%d item', '%d items', $count, 'brn-webhooks' ), $count );
				$st['steps'][] = $step;
				$index         = count( $st['steps'] ) - 1;
				$outer         = $ctx->loop;
				foreach ( $items as $i => $item ) {
					if ( $st['halt'] || $st['suspend'] ) {
						break;
					}
					$ctx->set_loop(
						array(
							'item'  => $item,
							'index' => $i + 1,
							'count' => $count,
							'first' => 0 === $i,
							'last'  => $i === $count - 1,
						)
					);
					self::run_children(
						$id,
						'each',
						$ctx,
						$st,
						array(
							'depth'  => $scope['depth'] + 1,
							'iter'   => $scope['iter'] . '#' . ( $i + 1 ),
							'index'  => $i + 1,
							'loop'   => true,
							/* translators: %d: item number */
							'branch' => sprintf( __( 'Item %d', 'brn-webhooks' ), $i + 1 ),
						)
					);
				}
				$ctx->set_loop( $outer );
				$output                          = array(
					'count' => $count,
					'items' => array_slice( $items, 0, 50 ),
				);
				$st['steps'][ $index ]['output'] = self::shrink( $output );
				$ctx->set_step( $pos, $output, $id );
				if ( ! $st['halt'] && ! $st['suspend'] ) {
					self::run_children( $id, 'done', $ctx, $st, $scope );
				}
				return;
		}

		// A regular action. Already done in a previous attempt?
		if ( isset( $st['prior'][ $key ] ) && 'success' === $st['prior'][ $key ]['status'] ) {
			$done = $st['prior'][ $key ];
			$ctx->set_step( $pos, isset( $done['output'] ) ? $done['output'] : null, $id );
			$st['steps'][] = $done;
			++$st['succeeded'];
			self::run_children( $id, 'out', $ctx, $st, $scope );
			return;
		}

		$step = self::run_action( $n, $action, $ctx, $step );
		if ( 'success' === $step['status'] ) {
			$ctx->set_step( $pos, $step['_raw'], $id );
			unset( $step['_raw'] );
			++$st['succeeded'];
			$st['steps'][] = $step;
			self::save_progress( $st );
			self::run_children( $id, 'out', $ctx, $st, $scope );
			return;
		}
		self::fail( $n, $step, $ctx, $st, $scope, ! empty( $step['_retryable'] ) );
	}

	/**
	 * A step failed: follow its error path, continue, or stop the run.
	 *
	 * @param array   $n         Node.
	 * @param array   $step      Step record (status failed, error set).
	 * @param Context $ctx       Context.
	 * @param array   $st        State.
	 * @param array   $scope     Scope.
	 * @param bool    $retryable Retry makes sense.
	 */
	private static function fail( array $n, array $step, Context $ctx, array &$st, array $scope, $retryable ) {
		unset( $step['_retryable'], $step['_raw'] );
		++$st['failed'];
		$id  = $n['id'];
		$pos = isset( $st['graph']['pos'][ $id ] ) ? $st['graph']['pos'][ $id ] : null;
		$ctx->set_step( $pos, array( 'error' => $step['error'] ), $id );
		if ( self::has_children( $st, $id, 'error' ) ) {
			++$st['handled'];
			$step['note']  = __( 'Handled by the error path.', 'brn-webhooks' );
			$st['steps'][] = $step;
			self::save_progress( $st );
			self::run_children( $id, 'error', $ctx, $st, array_merge( $scope, array( 'depth' => $scope['depth'] + 1, 'branch' => __( 'On error', 'brn-webhooks' ) ) ) );
			return;
		}
		$st['retryable'] = $st['retryable'] || $retryable;
		if ( '' === $st['message'] ) {
			/* translators: 1: step name, 2: error */
			$st['message'] = sprintf( __( '"%1$s" failed: %2$s', 'brn-webhooks' ), $step['name'], $step['error'] );
		}
		$st['steps'][] = $step;
		self::save_progress( $st );
		if ( ! empty( $n['continue_on_error'] ) ) {
			self::run_children( $id, 'out', $ctx, $st, $scope );
		} else {
			$st['halt'] = true;
		}
	}

	/**
	 * Execute an action and describe the result.
	 *
	 * @param array   $n      Node.
	 * @param Action  $action Action.
	 * @param Context $ctx    Context.
	 * @param array   $step   Step record.
	 * @return array Step record (+ _raw output / _retryable).
	 */
	private static function run_action( array $n, Action $action, Context $ctx, array $step ) {
		$t0 = microtime( true );
		Http::reset_traces();
		try {
			$connection = null;
			if ( $action->connection_type() && ! empty( $n['connection_id'] ) ) {
				$connection = Connections::get( (int) $n['connection_id'] );
				if ( ! $connection && ! $action->connection_optional() ) {
					throw new Action_Error( __( 'The selected connection was deleted.', 'brn-webhooks' ), false );
				}
			}
			$output         = $action->execute( (array) $n['config'], $ctx, $connection );
			$step['status'] = 'success';
			$step['output'] = self::shrink( $output );
			$step['_raw']   = $output;
		} catch ( \Throwable $e ) {
			$step['status']     = 'failed';
			$step['error']      = $e->getMessage();
			$step['_retryable'] = ! ( $e instanceof Action_Error ) || $e->retryable;
		}
		$step['duration_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$step['requests']    = Http::traces();
		return $step;
	}

	/**
	 * Delay node.
	 *
	 * @param array   $n     Node.
	 * @param array   $step  Step record.
	 * @param Context $ctx   Context.
	 * @param array   $st    State.
	 * @param array   $scope Scope.
	 */
	private static function run_delay( array $n, array $step, Context $ctx, array &$st, array $scope ) {
		$id  = $n['id'];
		$key = $step['key'];
		if ( $st['is_test'] || $scope['loop'] ) {
			$step['status'] = 'skipped';
			$step['note']   = $st['is_test'] ? __( 'Test run — the wait was skipped.', 'brn-webhooks' ) : __( 'Delays inside a loop are skipped.', 'brn-webhooks' );
			$st['steps'][]  = $step;
			self::run_children( $id, 'out', $ctx, $st, $scope );
			return;
		}
		$prior = isset( $st['prior'][ $key ] ) ? $st['prior'][ $key ] : null;
		if ( $prior && 'success' === $prior['status'] ) {
			$st['steps'][] = $prior;
			self::run_children( $id, 'out', $ctx, $st, $scope );
			return;
		}
		if ( $prior && 'waiting' === $prior['status'] && ! empty( $prior['resume_at'] ) ) {
			$resume = (int) strtotime( $prior['resume_at'] );
			$skip   = $st['skip_wait'] === $key;
			if ( $skip || $resume <= time() ) {
				$prior['status'] = 'success';
				/* translators: %s: date */
				$prior['note'] = $skip ? __( 'The wait was skipped manually.', 'brn-webhooks' ) : sprintf( __( 'Waited until %s.', 'brn-webhooks' ), wp_date( 'Y-m-d H:i', $resume ) );
				$st['steps'][] = $prior;
				self::save_progress( $st );
				self::run_children( $id, 'out', $ctx, $st, $scope );
				return;
			}
			$st['steps'][] = $prior;
			$st['suspend'] = $resume;
			return;
		}
		$resume = Actions\Delay::resume_at( (array) $n['config'], $ctx );
		if ( null === $resume ) {
			$step['status'] = 'failed';
			$step['error']  = __( 'The delay date / time is empty or not valid.', 'brn-webhooks' );
			self::fail( $n, $step, $ctx, $st, $scope, false );
			return;
		}
		if ( $resume <= time() + 5 ) {
			$step['status'] = 'success';
			$step['note']   = __( 'That time has already passed — continued right away.', 'brn-webhooks' );
			$st['steps'][]  = $step;
			self::run_children( $id, 'out', $ctx, $st, $scope );
			return;
		}
		$step['status']    = 'waiting';
		$step['resume_at'] = gmdate( 'c', $resume );
		/* translators: %s: date */
		$step['note']  = sprintf( __( 'Waiting until %s.', 'brn-webhooks' ), wp_date( 'Y-m-d H:i', $resume ) );
		$st['steps'][] = $step;
		$st['suspend'] = $resume;
	}

	/**
	 * Park a run until a delay is over.
	 *
	 * @param int   $run_id  Run id.
	 * @param array $st      State.
	 * @param float $started Start time.
	 * @return array|null
	 */
	private static function suspend( $run_id, array $st, $started ) {
		/* translators: %s: date */
		$message = sprintf( __( 'Waiting until %s.', 'brn-webhooks' ), wp_date( 'Y-m-d H:i', $st['suspend'] ) );
		Logs::update(
			$run_id,
			array(
				'status'          => 'waiting',
				'message'         => $message,
				'steps'           => $st['steps'],
				'duration_ms'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'next_attempt_at' => Util::now( max( 0, $st['suspend'] - time() ) ),
			)
		);
		self::$results[ (int) $run_id ] = array(
			'id'      => (int) $run_id,
			'status'  => 'waiting',
			'message' => $message,
			'steps'   => $st['steps'],
		);
		return Logs::get( $run_id );
	}

	/**
	 * Test one action node on its own, with the sample outputs of earlier steps.
	 *
	 * @param array  $flow    Flow (possibly unsaved).
	 * @param array  $data    Trigger data.
	 * @param string $node_id Node id.
	 * @param array  $outputs Earlier outputs by node id.
	 * @return array|\WP_Error Log.
	 */
	public static function test_step( array $flow, array $data, $node_id, array $outputs ) {
		$g = self::graph( $flow['actions'] );
		if ( ! isset( $g['by'][ $node_id ] ) ) {
			return new \WP_Error( 'brnwh_bad_step', __( 'Step not found.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$n      = $g['by'][ $node_id ];
		$action = Registry::action( $n['type'] );
		if ( ! $action || 'action' !== $action->kind() ) {
			return new \WP_Error( 'brnwh_bad_step', __( 'Only action steps can be tested on their own. Test the whole flow instead.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$started = microtime( true );
		$run_id  = Logs::create(
			array(
				'flow_id'      => isset( $flow['id'] ) ? (int) $flow['id'] : 0,
				'flow_name'    => $flow['name'],
				'trigger_type' => $flow['trigger_type'],
				'status'       => 'running',
				'is_test'      => true,
				'input'        => $data,
				'meta'         => array( 'source' => 'test_step' ),
			)
		);
		$ctx = new Context( $data, $flow, $run_id, true );
		foreach ( $g['by'] as $id => $node ) {
			if ( isset( $outputs[ $id ] ) ) {
				$ctx->set_step( $g['pos'][ $id ], Util::clean_deep( $outputs[ $id ] ), $id );
			}
		}
		// Inside a loop: use its first item as {{_loop.item}}.
		$cur = $n;
		while ( '' !== $cur['parent'] && 'trigger' !== $cur['parent'] && isset( $g['by'][ $cur['parent'] ] ) ) {
			$parent = $g['by'][ $cur['parent'] ];
			if ( 'loop' === $parent['type'] && 'each' === $cur['port'] ) {
				$items = Actions\Loop::items( (array) $parent['config'], $ctx );
				$ctx->set_loop(
					array(
						'item'  => $items ? $items[0] : null,
						'index' => 1,
						'count' => count( $items ),
						'first' => true,
						'last'  => count( $items ) <= 1,
					)
				);
				break;
			}
			$cur = $parent;
		}
		$step = self::run_action(
			$n,
			$action,
			$ctx,
			array(
				'action_id' => $n['id'],
				'key'       => $n['id'],
				'type'      => $n['type'],
				'kind'      => 'action',
				'name'      => '' !== $n['name'] ? $n['name'] : $action->label(),
				'depth'     => 0,
			)
		);
		unset( $step['_raw'], $step['_retryable'] );
		$ok = 'success' === $step['status'];
		/* translators: 1: step name, 2: error */
		$message                     = $ok ? '' : sprintf( __( '"%1$s" failed: %2$s', 'brn-webhooks' ), $step['name'], $step['error'] );
		$flow['settings']['retries'] = 0;
		return self::finish( $run_id, $flow, $ok ? 'success' : 'failed', $message, array( $step ), 0, $started, true, false );
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
		if ( ! $row || ! in_array( $row['status'], array( 'failed', 'partial', 'retrying', 'waiting' ), true ) ) {
			return new \WP_Error( 'brnwh_bad_state', __( 'Only failed runs can be retried.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$fields = array( 'status' => 'queued' );
		if ( 'waiting' === $row['status'] ) {
			// "Continue now": skip the delay the run is waiting on.
			$meta = (array) Util::unjson( $row['meta'] );
			foreach ( array_reverse( (array) Util::unjson( $row['steps'] ) ) as $s ) {
				if ( isset( $s['status'], $s['key'] ) && 'waiting' === $s['status'] ) {
					$meta['skip_wait'] = $s['key'];
					break;
				}
			}
			$fields['meta'] = $meta;
		}
		Logs::update( (int) $run_id, $fields );
		$log = self::run( (int) $run_id );
		return $log ? $log : new \WP_Error( 'brnwh_gone', __( 'Run finished and was not logged (log level).', 'brn-webhooks' ) );
	}
}
