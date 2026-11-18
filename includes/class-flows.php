<?php
/**
 * Flows repository.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for automation flows (trigger → conditions → actions).
 */
class Flows {

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		return Installer::table( 'flows' );
	}

	/**
	 * Default per-flow settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			'async'   => true,
			'retries' => (int) Settings::get( 'default_retries' ),
			'log'     => 'default', // default | all | errors | none.
		);
	}

	/**
	 * Hydrate a DB row.
	 *
	 * @param object|array|null $row Row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row    = (array) $row;
		$config = Util::unjson( $row['trigger_config'] );
		foreach ( $config as $k => $v ) {
			if ( is_string( $v ) && 0 === strpos( $v, Crypto::PREFIX ) ) {
				$dec          = Crypto::decrypt( $v );
				$config[ $k ] = false === $dec ? '' : $dec;
			}
		}
		$sample = Util::unjson( $row['sample'], null );
		return array(
			'id'             => (int) $row['id'],
			'name'           => (string) $row['name'],
			'status'         => (string) $row['status'],
			'trigger_type'   => (string) $row['trigger_type'],
			'trigger_config' => $config,
			'conditions'     => Conditions::sanitize( Util::unjson( $row['conditions'] ) ),
			'actions'        => array_values( (array) Util::unjson( $row['actions'] ) ),
			'settings'       => array_merge( self::default_settings(), (array) Util::unjson( $row['settings'] ) ),
			'sample'         => is_array( $sample ) ? $sample : null,
			'hook_key'       => $row['hook_key'] ? (string) $row['hook_key'] : '',
			'run_count'      => (int) $row['run_count'],
			'last_run_at'    => Util::iso( $row['last_run_at'] ),
			'last_status'    => $row['last_status'] ? (string) $row['last_status'] : '',
			'created_at'     => Util::iso( $row['created_at'] ),
			'updated_at'     => Util::iso( $row['updated_at'] ),
		);
	}

	/**
	 * All flows (without samples, for lists).
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY updated_at DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$f = self::hydrate( $r );
			unset( $f['sample'] );
			$out[] = $f;
		}
		return $out;
	}

	/**
	 * One flow.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Flow by its endpoint key.
	 *
	 * @param string $key Key.
	 * @return array|null
	 */
	public static function find_by_hook_key( $key ) {
		global $wpdb;
		if ( ! preg_match( '/^[a-zA-Z0-9]{16,64}$/', (string) $key ) ) {
			return null;
		}
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE hook_key = %s', $key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Flows for a trigger type. Includes paused flows when $include_paused (for sample capture).
	 *
	 * @param string $type           Trigger type.
	 * @param bool   $include_paused Include paused.
	 * @return array
	 */
	public static function by_trigger( $type, $include_paused = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table() . ' WHERE trigger_type = %s' . ( $include_paused ? '' : " AND status = 'active'" );
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $type ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[] = self::hydrate( $r );
		}
		return $out;
	}

	/**
	 * Sanitize a flow coming from the UI.
	 *
	 * @param array      $in       Input.
	 * @param array|null $existing Existing flow.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $in, $existing = null ) {
		$base = $existing ? $existing : array(
			'name'           => '',
			'status'         => 'paused',
			'trigger_type'   => '',
			'trigger_config' => array(),
			'conditions'     => Conditions::sanitize( array() ),
			'actions'        => array(),
			'settings'       => self::default_settings(),
			'sample'         => null,
		);

		$out = $base;
		if ( isset( $in['name'] ) ) {
			$out['name'] = substr( sanitize_text_field( (string) $in['name'] ), 0, 190 );
		}
		if ( isset( $in['status'] ) ) {
			$out['status'] = 'active' === $in['status'] ? 'active' : 'paused';
		}
		if ( isset( $in['trigger_type'] ) ) {
			$type = sanitize_key( (string) $in['trigger_type'] );
			if ( '' !== $type && ! Registry::trigger( $type ) ) {
				return new \WP_Error( 'brnwh_bad_trigger', __( 'Unknown trigger type.', 'brn-webhooks' ), array( 'status' => 400 ) );
			}
			$out['trigger_type'] = $type;
		}
		if ( isset( $in['trigger_config'] ) && is_array( $in['trigger_config'] ) ) {
			$out['trigger_config'] = Util::clean_deep( $in['trigger_config'] );
		}
		if ( isset( $in['conditions'] ) ) {
			$out['conditions'] = Conditions::sanitize( $in['conditions'] );
		}
		if ( isset( $in['actions'] ) && is_array( $in['actions'] ) ) {
			$out['actions'] = self::sanitize_nodes( $in['actions'] );
		}
		if ( isset( $in['settings'] ) && is_array( $in['settings'] ) ) {
			$s               = $in['settings'];
			$out['settings'] = array(
				'async'   => ! isset( $s['async'] ) || (bool) $s['async'],
				'retries' => isset( $s['retries'] ) ? max( 0, min( 10, (int) $s['retries'] ) ) : (int) Settings::get( 'default_retries' ),
				'log'     => isset( $s['log'] ) && in_array( $s['log'], array( 'default', 'all', 'errors', 'none' ), true ) ? $s['log'] : 'default',
			);
			if ( isset( $s['trigger_ui'] ) && is_array( $s['trigger_ui'] ) ) {
				$out['settings']['trigger_ui'] = array(
					'x' => isset( $s['trigger_ui']['x'] ) ? max( -100000, min( 100000, (int) $s['trigger_ui']['x'] ) ) : 0,
					'y' => isset( $s['trigger_ui']['y'] ) ? max( -100000, min( 100000, (int) $s['trigger_ui']['y'] ) ) : 0,
				);
			}
		}
		if ( array_key_exists( 'sample', $in ) ) {
			$sample = $in['sample'];
			if ( is_array( $sample ) && strlen( (string) wp_json_encode( $sample ) ) <= 512000 ) {
				$out['sample'] = Util::clean_deep( $sample );
			} elseif ( null === $sample ) {
				$out['sample'] = null;
			}
		}
		if ( '' === trim( $out['name'] ) ) {
			$out['name'] = __( 'Untitled flow', 'brn-webhooks' );
		}
		return $out;
	}

	/**
	 * Sanitize the step nodes of a flow.
	 *
	 * Canvas flows are a tree: every node has a parent ("trigger" or another node id) and the
	 * port of the parent it hangs from (out, error, yes, no, p:<path id>, else, each, done).
	 * Nodes without a parent are kept (drawn on the canvas) but never run. Older flows have
	 * no "parent" keys at all and run as a simple chain.
	 *
	 * @param array $list Raw nodes.
	 * @return array
	 */
	public static function sanitize_nodes( array $list ) {
		$nodes = array();
		$graph = false;
		foreach ( array_slice( array_values( $list ), 0, 150 ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$type = isset( $a['type'] ) ? sanitize_key( (string) $a['type'] ) : '';
			if ( '' !== $type && ! Registry::action( $type ) ) {
				continue;
			}
			$node = array(
				'id'                => isset( $a['id'] ) && preg_match( '/^[a-z0-9]{4,32}$/', (string) $a['id'] ) ? (string) $a['id'] : Util::uid(),
				'type'              => $type,
				'name'              => isset( $a['name'] ) ? substr( sanitize_text_field( (string) $a['name'] ), 0, 190 ) : '',
				'connection_id'     => isset( $a['connection_id'] ) ? absint( $a['connection_id'] ) : 0,
				'config'            => isset( $a['config'] ) && is_array( $a['config'] ) ? Util::clean_deep( $a['config'] ) : array(),
				'enabled'           => ! isset( $a['enabled'] ) || (bool) $a['enabled'],
				'continue_on_error' => ! empty( $a['continue_on_error'] ),
				'conditions'        => Conditions::sanitize( isset( $a['conditions'] ) ? $a['conditions'] : array() ),
			);
			if ( 'router' === $type ) {
				$node['config'] = self::sanitize_router( $node['config'] );
			}
			if ( array_key_exists( 'parent', $a ) ) {
				$graph          = true;
				$parent         = (string) $a['parent'];
				$node['parent'] = ( 'trigger' === $parent || preg_match( '/^[a-z0-9]{4,32}$/', $parent ) ) ? $parent : '';
				$port           = isset( $a['port'] ) ? (string) $a['port'] : 'out';
				$node['port']   = preg_match( '/^[a-z0-9_:]{1,48}$/', $port ) ? $port : 'out';
				$ui             = isset( $a['ui'] ) && is_array( $a['ui'] ) ? $a['ui'] : array();
				$node['ui']     = array(
					'x' => isset( $ui['x'] ) ? max( -100000, min( 100000, (int) $ui['x'] ) ) : 0,
					'y' => isset( $ui['y'] ) ? max( -100000, min( 100000, (int) $ui['y'] ) ) : 0,
				);
			}
			$nodes[] = $node;
		}
		if ( ! $graph ) {
			return $nodes;
		}
		// Unique ids, existing parents, no cycles.
		$ids = array();
		foreach ( $nodes as $i => $n ) {
			if ( isset( $ids[ $n['id'] ] ) ) {
				$nodes[ $i ]['id'] = Util::uid();
			}
			$ids[ $nodes[ $i ]['id'] ] = $i;
		}
		foreach ( $nodes as $i => $n ) {
			if ( ! isset( $n['parent'] ) ) {
				$nodes[ $i ]['parent'] = '';
				$nodes[ $i ]['port']   = 'out';
				$nodes[ $i ]['ui']     = array( 'x' => 0, 'y' => 0 );
				continue;
			}
			if ( '' !== $n['parent'] && 'trigger' !== $n['parent'] && ! isset( $ids[ $n['parent'] ] ) ) {
				$nodes[ $i ]['parent'] = '';
			}
		}
		foreach ( $nodes as $i => $n ) {
			$seen = array( $n['id'] => true );
			$cur  = $nodes[ $i ]['parent'];
			while ( '' !== $cur && 'trigger' !== $cur ) {
				if ( isset( $seen[ $cur ] ) ) {
					$nodes[ $i ]['parent'] = '';
					break;
				}
				$seen[ $cur ] = true;
				$cur          = $nodes[ $ids[ $cur ] ]['parent'];
			}
		}
		return $nodes;
	}

	/**
	 * Sanitize a Paths node config.
	 *
	 * @param array $c Config.
	 * @return array
	 */
	private static function sanitize_router( array $c ) {
		$paths = array();
		foreach ( array_slice( isset( $c['paths'] ) && is_array( $c['paths'] ) ? array_values( $c['paths'] ) : array(), 0, 20 ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$paths[] = array(
				'id'         => isset( $p['id'] ) && preg_match( '/^[a-z0-9]{2,32}$/', (string) $p['id'] ) ? (string) $p['id'] : Util::uid(),
				'name'       => isset( $p['name'] ) ? substr( sanitize_text_field( (string) $p['name'] ), 0, 120 ) : '',
				'conditions' => Conditions::sanitize( isset( $p['conditions'] ) ? $p['conditions'] : array() ),
			);
		}
		return array(
			'mode'  => isset( $c['mode'] ) && 'all' === $c['mode'] ? 'all' : 'first',
			'else'  => ! isset( $c['else'] ) || (bool) $c['else'],
			'paths' => $paths,
		);
	}

	/**
	 * Row data for the DB.
	 *
	 * @param array $flow Sanitized flow.
	 * @return array
	 */
	private static function to_row( array $flow ) {
		$config  = (array) $flow['trigger_config'];
		$trigger = Registry::trigger( $flow['trigger_type'] );
		if ( $trigger ) {
			foreach ( $trigger->secret_keys() as $k ) {
				if ( isset( $config[ $k ] ) && is_string( $config[ $k ] ) && '' !== $config[ $k ] ) {
					$config[ $k ] = Crypto::encrypt( $config[ $k ] );
				}
			}
		}
		return array(
			'name'           => $flow['name'],
			'status'         => $flow['status'],
			'trigger_type'   => $flow['trigger_type'],
			'trigger_config' => Util::json( $config ),
			'conditions'     => Util::json( $flow['conditions'] ),
			'actions'        => Util::json( array_values( $flow['actions'] ) ),
			'settings'       => Util::json( $flow['settings'] ),
			'sample'         => null === $flow['sample'] ? null : Util::json( $flow['sample'] ),
		);
	}

	/**
	 * Ensure an endpoint key exists for triggers that receive HTTP calls.
	 *
	 * @param array  $flow Flow.
	 * @param string $key  Current key.
	 * @return string|null
	 */
	private static function hook_key_for( array $flow, $key ) {
		$trigger = Registry::trigger( $flow['trigger_type'] );
		if ( ! $trigger || ! $trigger->has_endpoint() ) {
			return $key ? $key : null;
		}
		return $key ? $key : Crypto::token( 16 );
	}

	/**
	 * Create.
	 *
	 * @param array $in Input.
	 * @return array|\WP_Error
	 */
	public static function create( array $in ) {
		global $wpdb;
		$flow = self::sanitize( $in );
		if ( is_wp_error( $flow ) ) {
			return $flow;
		}
		$row               = self::to_row( $flow );
		$row['hook_key']   = self::hook_key_for( $flow, '' );
		$row['created_at'] = Util::now();
		$row['updated_at'] = Util::now();
		$ok                = $wpdb->insert( self::table(), $row );
		if ( ! $ok ) {
			return new \WP_Error( 'brnwh_db', __( 'Could not save the flow.', 'brn-webhooks' ), array( 'status' => 500 ) );
		}
		self::reindex();
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Update.
	 *
	 * @param int   $id ID.
	 * @param array $in Input.
	 * @return array|\WP_Error
	 */
	public static function update( $id, array $in ) {
		global $wpdb;
		$existing = self::get( $id );
		if ( ! $existing ) {
			return new \WP_Error( 'brnwh_not_found', __( 'Flow not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
		}
		$flow = self::sanitize( $in, $existing );
		if ( is_wp_error( $flow ) ) {
			return $flow;
		}
		$row               = self::to_row( $flow );
		$row['hook_key']   = self::hook_key_for( $flow, $existing['hook_key'] );
		$row['updated_at'] = Util::now();
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		self::reindex();
		return self::get( $id );
	}

	/**
	 * Regenerate the endpoint key.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function rotate_key( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'hook_key' => Crypto::token( 16 ) ), array( 'id' => (int) $id ) );
		return self::get( $id );
	}

	/**
	 * Store the latest captured sample.
	 *
	 * @param int   $id   ID.
	 * @param array $data Trigger data.
	 */
	public static function save_sample( $id, array $data ) {
		global $wpdb;
		$flow   = self::get( $id );
		$sample = ( $flow && is_array( $flow['sample'] ) ) ? $flow['sample'] : array();
		$sample['data']        = $data;
		$sample['captured_at'] = gmdate( 'c' );
		$json                  = Util::json( $sample );
		if ( strlen( $json ) > 512000 ) {
			return;
		}
		$wpdb->update( self::table(), array( 'sample' => $json ), array( 'id' => (int) $id ) );
	}

	/**
	 * Record a finished run on the flow.
	 *
	 * @param int    $id     ID.
	 * @param string $status Status.
	 */
	public static function touch_run( $id, $status ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET run_count = run_count + 1, last_run_at = %s, last_status = %s WHERE id = %d', Util::now(), $status, (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Set status.
	 *
	 * @param int    $id     ID.
	 * @param string $status active|paused.
	 * @return array|null
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'active' === $status ? 'active' : 'paused', 'updated_at' => Util::now() ), array( 'id' => (int) $id ) );
		self::reindex();
		return self::get( $id );
	}

	/**
	 * Delete.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$ok = (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		self::reindex();
		return $ok;
	}

	/**
	 * Duplicate (paused, new endpoint key).
	 *
	 * @param int $id ID.
	 * @return array|\WP_Error
	 */
	public static function duplicate( $id ) {
		$flow = self::get( $id );
		if ( ! $flow ) {
			return new \WP_Error( 'brnwh_not_found', __( 'Flow not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
		}
		/* translators: %s: flow name */
		$flow['name']   = sprintf( __( '%s (copy)', 'brn-webhooks' ), $flow['name'] );
		$flow['status'] = 'paused';
		return self::create( $flow );
	}

	/**
	 * Export (portable JSON without ids, keys or samples).
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function export( $id ) {
		$flow = self::get( $id );
		if ( ! $flow ) {
			return null;
		}
		$trigger = Registry::trigger( $flow['trigger_type'] );
		if ( $trigger ) {
			foreach ( $trigger->secret_keys() as $k ) {
				unset( $flow['trigger_config'][ $k ] );
			}
		}
		foreach ( $flow['actions'] as &$a ) {
			$a['connection_id'] = 0;
		}
		unset( $a );
		return array(
			'brn_webhooks' => BRNWH_VERSION,
			'flow'         => array_intersect_key( $flow, array_flip( array( 'name', 'trigger_type', 'trigger_config', 'conditions', 'actions', 'settings' ) ) ),
		);
	}

	/**
	 * Keep an index of trigger types that have active flows (cheap check on hot hooks).
	 */
	public static function reindex() {
		global $wpdb;
		$types = $wpdb->get_col( 'SELECT DISTINCT trigger_type FROM ' . self::table() . " WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( 'brnwh_trigger_index', array_fill_keys( array_map( 'strval', (array) $types ), true ), true );
	}

	/**
	 * Is there any active flow for a trigger type?
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function has_active( $type ) {
		$index = get_option( 'brnwh_trigger_index', array() );
		return is_array( $index ) && ! empty( $index[ $type ] );
	}

	/**
	 * Mark a flow as listening for sample data (10 minutes).
	 *
	 * @param int $id ID.
	 */
	public static function listen( $id ) {
		set_transient( 'brnwh_listen_' . (int) $id, 1, 10 * MINUTE_IN_SECONDS );
		$listening                = get_option( 'brnwh_listening', array() );
		$listening               = is_array( $listening ) ? $listening : array();
		$listening[ (int) $id ] = time() + 10 * MINUTE_IN_SECONDS;
		update_option( 'brnwh_listening', $listening, true );
	}

	/**
	 * Is the flow listening?
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function is_listening( $id ) {
		return (bool) get_transient( 'brnwh_listen_' . (int) $id );
	}

	/**
	 * Any flow listening for a trigger type? (cheap — reads an autoloaded option).
	 *
	 * @return int[] Flow ids.
	 */
	public static function listening_ids() {
		$listening = get_option( 'brnwh_listening', array() );
		if ( ! is_array( $listening ) || ! $listening ) {
			return array();
		}
		$now   = time();
		$alive = array_filter(
			$listening,
			static function ( $until ) use ( $now ) {
				return $until > $now;
			}
		);
		if ( count( $alive ) !== count( $listening ) ) {
			update_option( 'brnwh_listening', $alive, true );
		}
		return array_map( 'intval', array_keys( $alive ) );
	}
}
