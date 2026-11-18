<?php
/**
 * Run logs repository.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Every flow run is a log row; it doubles as the job queue (status queued/retrying).
 */
class Logs {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		return Installer::table( 'logs' );
	}

	/**
	 * Hydrate.
	 *
	 * @param object|null $row  Row.
	 * @param bool        $full Include input/steps.
	 * @return array|null
	 */
	private static function hydrate( $row, $full = true ) {
		if ( ! $row ) {
			return null;
		}
		$row = (array) $row;
		$out = array(
			'id'              => (int) $row['id'],
			'flow_id'         => (int) $row['flow_id'],
			'flow_name'       => (string) $row['flow_name'],
			'trigger_type'    => (string) $row['trigger_type'],
			'status'          => (string) $row['status'],
			'is_test'         => (bool) $row['is_test'],
			'message'         => (string) $row['message'],
			'attempts'        => (int) $row['attempts'],
			'duration_ms'     => (int) $row['duration_ms'],
			'next_attempt_at' => Util::iso( $row['next_attempt_at'] ),
			'created_at'      => Util::iso( $row['created_at'] ),
			'finished_at'     => Util::iso( $row['finished_at'] ),
		);
		if ( $full ) {
			$out['input']       = Util::unjson( $row['input'] );
			$out['steps']       = Util::unjson( $row['steps'] );
			$out['meta']        = Util::unjson( $row['meta'] );
			$out['has_payload'] = ! empty( $row['payload'] );
		} elseif ( isset( $row['steps_summary'] ) ) {
			$out['steps_summary'] = $row['steps_summary'];
		}
		return $out;
	}

	/**
	 * Create a run.
	 *
	 * @param array $data Data.
	 * @return int
	 */
	public static function create( array $data ) {
		global $wpdb;
		$input = isset( $data['input'] ) ? (array) $data['input'] : array();
		$wpdb->insert(
			self::table(),
			array(
				'flow_id'      => isset( $data['flow_id'] ) ? (int) $data['flow_id'] : 0,
				'flow_name'    => isset( $data['flow_name'] ) ? substr( (string) $data['flow_name'], 0, 190 ) : '',
				'trigger_type' => isset( $data['trigger_type'] ) ? (string) $data['trigger_type'] : '',
				'status'       => isset( $data['status'] ) ? (string) $data['status'] : 'queued',
				'is_test'      => ! empty( $data['is_test'] ) ? 1 : 0,
				'input'        => Util::json( Settings::get( 'mask_sensitive' ) ? Util::redact( $input ) : $input ),
				'payload'      => Util::json( $input ),
				'steps'        => Util::json( array() ),
				'meta'         => Util::json( isset( $data['meta'] ) ? (array) $data['meta'] : array() ),
				'message'      => isset( $data['message'] ) ? (string) $data['message'] : '',
				'created_at'   => Util::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Raw row (internal, includes payload).
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function raw( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * One run for the UI.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		return self::hydrate( self::raw( $id ) );
	}

	/**
	 * Atomically claim a queued run for processing.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function claim( $id ) {
		global $wpdb;
		// next_attempt_at doubles as a 15-minute lease while running, so crashed workers can be recovered.
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = 'running', next_attempt_at = %s WHERE id = %d AND status IN ('queued','retrying','waiting')", Util::now( 900 ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return 1 === (int) $n;
	}

	/**
	 * Update fields.
	 *
	 * @param int   $id     ID.
	 * @param array $fields Fields.
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		foreach ( array( 'steps', 'meta' ) as $json ) {
			if ( isset( $fields[ $json ] ) && is_array( $fields[ $json ] ) ) {
				$fields[ $json ] = Util::json( $fields[ $json ] );
			}
		}
		$wpdb->update( self::table(), $fields, array( 'id' => (int) $id ) );
	}

	/**
	 * Delete one.
	 *
	 * @param int $id ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Query with filters for the UI list.
	 *
	 * @param array $args flow_id, status, search, page, per_page, since.
	 * @return array {items, total, pages}
	 */
	public static function query( array $args ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$per   = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 30;
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$table = self::table();

		$total_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$sql  = "SELECT id, flow_id, flow_name, trigger_type, status, is_test, message, attempts, duration_ms, next_attempt_at, created_at, finished_at, steps FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$items = array();
		foreach ( (array) $rows as $r ) {
			$steps   = Util::unjson( $r->steps );
			$summary = array();
			foreach ( (array) $steps as $s ) {
				$summary[] = array(
					'type'   => isset( $s['type'] ) ? $s['type'] : '',
					'status' => isset( $s['status'] ) ? $s['status'] : '',
				);
			}
			$r->steps_summary = $summary;
			$r->payload       = null;
			$items[]          = self::hydrate( $r, false );
		}
		return array(
			'items' => $items,
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
		);
	}

	/**
	 * WHERE builder.
	 *
	 * @param array $args Args.
	 * @return array [sql, params]
	 */
	private static function where( array $args ) {
		global $wpdb;
		$w = array();
		$p = array();
		if ( ! empty( $args['flow_id'] ) ) {
			$w[] = 'flow_id = %d';
			$p[] = (int) $args['flow_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$statuses = array_filter( array_map( 'sanitize_key', explode( ',', (string) $args['status'] ) ) );
			if ( $statuses ) {
				$w[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
				$p   = array_merge( $p, $statuses );
			}
		}
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$w[]  = '(input LIKE %s OR message LIKE %s OR flow_name LIKE %s)';
			array_push( $p, $like, $like, $like );
		}
		if ( ! empty( $args['since'] ) ) {
			$w[] = 'created_at >= %s';
			$p[] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $args['since'] ) );
		}
		return array( $w ? 'WHERE ' . implode( ' AND ', $w ) : '', $p );
	}

	/**
	 * Clear logs matching filters (never queued/running/retrying jobs).
	 *
	 * @param array $args Filters.
	 * @return int Deleted rows.
	 */
	public static function clear( array $args ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$where                  = ( $where ? $where . ' AND ' : 'WHERE ' ) . "status NOT IN ('queued','running','retrying','waiting')";
		$sql                    = 'DELETE FROM ' . self::table() . ' ' . $where;
		return (int) ( $params ? $wpdb->query( $wpdb->prepare( $sql, $params ) ) : $wpdb->query( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Retention purge.
	 *
	 * @param int $days Days.
	 */
	public static function purge( $days ) {
		global $wpdb;
		$cutoff = Util::now( -DAY_IN_SECONDS * max( 1, (int) $days ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE created_at < %s AND status NOT IN ('queued','running','retrying','waiting') LIMIT 5000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// Test runs interrupted mid-request stay "running" forever otherwise.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE is_test = 1 AND created_at < %s LIMIT 5000', Util::now( -DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Runs waiting to be processed (stuck queued > 90s or due retries).
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public static function due( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE (status = 'queued' AND created_at <= %s) OR (status IN ('retrying','waiting') AND next_attempt_at <= %s) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Util::now( -60 ),
				Util::now(),
				(int) $limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Reset runs stuck in "running" for 15+ minutes (crashed workers) back to retrying.
	 */
	public static function recover_stuck() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status = IF(attempts < 10, 'retrying', 'failed'), attempts = attempts + 1, next_attempt_at = %s, message = 'Worker stopped unexpectedly (timeout?)' WHERE status = 'running' AND next_attempt_at IS NOT NULL AND next_attempt_at <= %s", Util::now(), Util::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Dashboard statistics.
	 *
	 * @param int $days Days in the chart.
	 * @return array
	 */
	public static function stats( $days = 14 ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) d, status, COUNT(*) c FROM {$table} WHERE created_at >= %s AND is_test = 0 GROUP BY d, status", $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d            = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[ $d ] = array(
				'date'     => $d,
				'success'  => 0,
				'failed'   => 0,
				'filtered' => 0,
			);
		}
		foreach ( (array) $rows as $r ) {
			if ( ! isset( $series[ $r->d ] ) ) {
				continue;
			}
			$bucket = in_array( $r->status, array( 'success' ), true ) ? 'success' : ( in_array( $r->status, array( 'failed', 'partial', 'retrying' ), true ) ? 'failed' : ( 'filtered' === $r->status ? 'filtered' : null ) );
			if ( $bucket ) {
				$series[ $r->d ][ $bucket ] += (int) $r->c;
			}
		}

		$day_ago = Util::now( -DAY_IN_SECONDS );
		$week    = Util::now( -7 * DAY_IN_SECONDS );
		$runs24  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND is_test = 0 AND status <> 'filtered'", $day_ago ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fail24  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND is_test = 0 AND status IN ('failed','partial')", $day_ago ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok7     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND is_test = 0 AND status = 'success'", $week ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all7    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND is_test = 0 AND status IN ('success','failed','partial')", $week ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$queue   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','running','retrying','waiting') AND is_test = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avg     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT AVG(duration_ms) FROM {$table} WHERE created_at >= %s AND is_test = 0 AND status = 'success'", $week ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'series'       => array_values( $series ),
			'runs_24h'     => $runs24,
			'failed_24h'   => $fail24,
			'success_rate' => $all7 ? round( $ok7 / $all7 * 100, 1 ) : null,
			'queue'        => $queue,
			'avg_ms'       => $avg,
		);
	}
}
