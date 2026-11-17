<?php
/**
 * Uninstall: data is removed only when "Delete all data on uninstall" is enabled in settings.
 *
 * @package BRN\Webhooks
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$brnwh_settings = get_option( 'brnwh_settings', array() );
wp_clear_scheduled_hook( 'brnwh_sweep' );
wp_clear_scheduled_hook( 'brnwh_daily' );

if ( is_array( $brnwh_settings ) && ! empty( $brnwh_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	foreach ( array( 'flows', 'connections', 'logs' ) as $brnwh_t ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'brnwh_' . $brnwh_t ); // phpcs:ignore WordPress.DB
	}
	foreach ( array( 'brnwh_db_version', 'brnwh_settings', 'brnwh_trigger_index', 'brnwh_worker_secret', 'brnwh_listening' ) as $brnwh_o ) {
		delete_option( $brnwh_o );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_brnwh\\_%' OR option_name LIKE '\\_transient\\_timeout\\_brnwh\\_%' OR option_name LIKE '\\_site\\_transient\\_brnwh\\_%' OR option_name LIKE '\\_site\\_transient\\_timeout\\_brnwh\\_%'" ); // phpcs:ignore WordPress.DB
}
