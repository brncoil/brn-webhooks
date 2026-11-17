<?php
/**
 * Connections repository (encrypted credentials for third-party services).
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for connections. Secrets never leave the server: the UI only sees MASK.
 */
class Connections {

	const MASK = '__brnwh_keep__';

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		return Installer::table( 'connections' );
	}

	/**
	 * Hydrate for internal use (decrypted credentials).
	 *
	 * @param object|null $row Row.
	 * @return array|null
	 */
	private static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row   = (array) $row;
		$creds = Crypto::decrypt_array( $row['credentials'] );
		return array(
			'id'             => (int) $row['id'],
			'name'           => (string) $row['name'],
			'type'           => (string) $row['type'],
			'credentials'    => is_array( $creds ) ? $creds : array(),
			'unreadable'     => null === $creds,
			'meta'           => (array) Util::unjson( $row['meta'] ),
			'status'         => (string) $row['status'],
			'last_tested_at' => Util::iso( $row['last_tested_at'] ),
			'created_at'     => Util::iso( $row['created_at'] ),
			'updated_at'     => Util::iso( $row['updated_at'] ),
		);
	}

	/**
	 * Public (UI) representation: secrets masked.
	 *
	 * @param array $conn Connection.
	 * @return array
	 */
	public static function for_ui( array $conn ) {
		$type   = Registry::connection_type( $conn['type'] );
		$secret = $type ? $type->secret_keys() : array();
		$fields = array();
		foreach ( $conn['credentials'] as $k => $v ) {
			$fields[ $k ] = in_array( $k, $secret, true ) && '' !== (string) $v ? self::MASK : $v;
		}
		$conn['credentials'] = $fields;
		$conn['summary']     = $type ? $type->summary( $conn ) : '';
		$conn['usage']       = self::usage_count( $conn['id'] );
		return $conn;
	}

	/**
	 * All connections (internal).
	 *
	 * @param string $type Optional type filter.
	 * @return array
	 */
	public static function all( $type = '' ) {
		global $wpdb;
		if ( $type ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE type = %s ORDER BY name ASC', $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY name ASC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * One connection (internal, decrypted).
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		if ( ! $id ) {
			return null;
		}
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Merge UI input with stored secrets and clean.
	 *
	 * @param Connection_Type $type     Type.
	 * @param array           $input    Input credentials.
	 * @param array           $existing Existing credentials.
	 * @return array
	 */
	public static function merge_credentials( $type, array $input, array $existing = array() ) {
		$out = array();
		foreach ( $type->fields() as $f ) {
			if ( empty( $f['key'] ) || in_array( $f['type'], array( 'info', 'heading' ), true ) ) {
				continue;
			}
			$k = $f['key'];
			$v = isset( $input[ $k ] ) ? $input[ $k ] : ( isset( $f['default'] ) ? $f['default'] : '' );
			if ( self::MASK === $v ) {
				$v = isset( $existing[ $k ] ) ? $existing[ $k ] : '';
			}
			if ( is_array( $v ) ) {
				$v = Util::clean_deep( $v );
			} elseif ( 'textarea' === $f['type'] || 'code' === $f['type'] ) {
				$v = trim( (string) Util::clean_deep( (string) $v ) );
			} else {
				$v = trim( sanitize_text_field( (string) $v ) );
			}
			$out[ $k ] = $v;
		}
		return $out;
	}

	/**
	 * Create or update.
	 *
	 * @param array    $in Input: name, type, credentials.
	 * @param int|null $id ID for update.
	 * @return array|\WP_Error Internal connection.
	 */
	public static function save( array $in, $id = null ) {
		global $wpdb;
		$existing = $id ? self::get( $id ) : null;
		if ( $id && ! $existing ) {
			return new \WP_Error( 'brnwh_not_found', __( 'Connection not found.', 'brn-webhooks' ), array( 'status' => 404 ) );
		}
		$type_id = $existing ? $existing['type'] : ( isset( $in['type'] ) ? sanitize_key( $in['type'] ) : '' );
		$type    = Registry::connection_type( $type_id );
		if ( ! $type ) {
			return new \WP_Error( 'brnwh_bad_type', __( 'Unknown connection type.', 'brn-webhooks' ), array( 'status' => 400 ) );
		}
		$creds = self::merge_credentials( $type, isset( $in['credentials'] ) && is_array( $in['credentials'] ) ? $in['credentials'] : array(), $existing ? $existing['credentials'] : array() );
		$valid = $type->validate( $creds );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$name = isset( $in['name'] ) ? substr( sanitize_text_field( $in['name'] ), 0, 190 ) : '';
		if ( '' === $name ) {
			$name = $existing ? $existing['name'] : $type->label();
		}
		$row = array(
			'name'        => $name,
			'type'        => $type_id,
			'credentials' => Crypto::encrypt_array( $creds ),
			'meta'        => Util::json( $type->meta( $creds ) ),
			'updated_at'  => Util::now(),
		);
		if ( $existing ) {
			if ( $creds !== $existing['credentials'] ) {
				$row['status'] = 'untested';
			}
			$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
			do_action( 'brnwh_connection_saved', (int) $id );
			return self::get( $id );
		}
		$row['created_at'] = Util::now();
		$row['status']     = 'untested';
		$wpdb->insert( self::table(), $row );
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Test credentials and store the result.
	 *
	 * @param int $id ID.
	 * @return array [ok, message]
	 */
	public static function test( $id ) {
		global $wpdb;
		$conn = self::get( $id );
		if ( ! $conn ) {
			return array( false, __( 'Connection not found.', 'brn-webhooks' ) );
		}
		if ( $conn['unreadable'] ) {
			return array( false, __( 'Stored credentials cannot be decrypted (the site keys changed). Please enter them again.', 'brn-webhooks' ) );
		}
		$type = Registry::connection_type( $conn['type'] );
		try {
			$message = $type ? $type->test( $conn['credentials'] ) : '';
			$ok      = true;
		} catch ( \Throwable $e ) {
			$message = $e->getMessage();
			$ok      = false;
		}
		$wpdb->update(
			self::table(),
			array(
				'status'         => $ok ? 'ok' : 'error',
				'last_tested_at' => Util::now(),
			),
			array( 'id' => (int) $id )
		);
		return array( $ok, $message ? $message : __( 'Connection works.', 'brn-webhooks' ) );
	}

	/**
	 * Delete.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * How many flow actions/triggers reference a connection.
	 *
	 * @param int $id ID.
	 * @return int
	 */
	public static function usage_count( $id ) {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			foreach ( Flows::all() as $flow ) {
				$ids = array();
				foreach ( $flow['actions'] as $a ) {
					$ids[] = (int) $a['connection_id'];
				}
				if ( ! empty( $flow['trigger_config']['connection_id'] ) ) {
					$ids[] = (int) $flow['trigger_config']['connection_id'];
				}
				foreach ( array_unique( $ids ) as $cid ) {
					$map[ $cid ] = ( isset( $map[ $cid ] ) ? $map[ $cid ] : 0 ) + 1;
				}
			}
		}
		return isset( $map[ (int) $id ] ) ? $map[ (int) $id ] : 0;
	}
}
