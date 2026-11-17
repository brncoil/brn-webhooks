<?php
/**
 * Database tables and lifecycle.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin tables.
 */
class Installer {

	/**
	 * Table names.
	 *
	 * @param string $name flows|connections|logs.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'brnwh_' . $name;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::install();
		Cron::schedule();
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		Cron::unschedule();
	}

	/**
	 * Run dbDelta when the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'brnwh_db_version' ) !== BRNWH_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create / update tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$flows   = self::table( 'flows' );
		$conns   = self::table( 'connections' );
		$logs    = self::table( 'logs' );

		dbDelta(
			"CREATE TABLE {$flows} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'paused',
				trigger_type varchar(64) NOT NULL DEFAULT '',
				trigger_config longtext NULL,
				conditions longtext NULL,
				actions longtext NULL,
				settings longtext NULL,
				sample longtext NULL,
				hook_key varchar(64) NULL,
				run_count bigint(20) unsigned NOT NULL DEFAULT 0,
				last_run_at datetime NULL,
				last_status varchar(20) NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY hook_key (hook_key),
				KEY trigger_status (trigger_type,status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$conns} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				type varchar(64) NOT NULL DEFAULT '',
				credentials longtext NULL,
				meta longtext NULL,
				status varchar(20) NOT NULL DEFAULT 'untested',
				last_tested_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY type (type)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				flow_id bigint(20) unsigned NOT NULL DEFAULT 0,
				flow_name varchar(190) NOT NULL DEFAULT '',
				trigger_type varchar(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'queued',
				is_test tinyint(1) NOT NULL DEFAULT 0,
				input longtext NULL,
				payload longtext NULL,
				steps longtext NULL,
				meta longtext NULL,
				message text NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				next_attempt_at datetime NULL,
				created_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY flow_id (flow_id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'brnwh_db_version', BRNWH_DB_VERSION, true );
	}
}
