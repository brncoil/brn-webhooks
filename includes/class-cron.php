<?php
/**
 * Scheduled tasks.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Minute sweep (queue + retries) and daily log retention.
 */
class Cron {

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( 'brnwh_sweep', array( Engine::class, 'sweep' ) );
		add_action( 'brnwh_daily', array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'ensure' ) );
	}

	/**
	 * Every-minute schedule.
	 *
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		$s['brnwh_minute'] = array(
			'interval' => 60,
			'display'  => 'Every minute (BRN Webhooks)',
		);
		return $s;
	}

	/**
	 * Make sure events exist (e.g. after migration).
	 */
	public static function ensure() {
		if ( ! wp_next_scheduled( 'brnwh_sweep' ) || ! wp_next_scheduled( 'brnwh_daily' ) ) {
			self::schedule();
		}
	}

	/**
	 * Schedule.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'brnwh_sweep' ) ) {
			wp_schedule_event( time() + 60, 'brnwh_minute', 'brnwh_sweep' );
		}
		if ( ! wp_next_scheduled( 'brnwh_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'brnwh_daily' );
		}
	}

	/**
	 * Unschedule.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( 'brnwh_sweep' );
		wp_clear_scheduled_hook( 'brnwh_daily' );
	}

	/**
	 * Daily maintenance.
	 */
	public static function daily() {
		Logs::purge( (int) Settings::get( 'log_retention_days' ) );
	}
}
