<?php
/**
 * Delay node.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Pause the branch for a while, or until a date. The run is parked as "waiting" and resumed by
 * the cron sweep; steps that already ran are not repeated.
 */
class Delay extends Logic_Step {

	/**
	 * Longest allowed delay.
	 */
	const MAX_SECONDS = 30 * DAY_IN_SECONDS;

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'delay';
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind() {
		return 'delay';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Delay', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Wait some minutes, hours or days — or until a date — before continuing.', 'brn-webhooks' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'     => 'mode',
				'type'    => 'choice',
				'label'   => __( 'Wait', 'brn-webhooks' ),
				'default' => 'for',
				'options' => self::options(
					array(
						'for'   => __( 'For a period of time', 'brn-webhooks' ),
						'until' => __( 'Until a date / time', 'brn-webhooks' ),
					)
				),
			),
			array(
				'key'     => 'amount',
				'type'    => 'number',
				'label'   => __( 'How long', 'brn-webhooks' ),
				'default' => 10,
				'width'   => 'half',
				'show_if' => array( 'mode' => 'for' ),
			),
			array(
				'key'     => 'unit',
				'type'    => 'select',
				'label'   => __( 'Unit', 'brn-webhooks' ),
				'default' => 'minutes',
				'width'   => 'half',
				'show_if' => array( 'mode' => 'for' ),
				'options' => self::options(
					array(
						'minutes' => __( 'Minutes', 'brn-webhooks' ),
						'hours'   => __( 'Hours', 'brn-webhooks' ),
						'days'    => __( 'Days', 'brn-webhooks' ),
					)
				),
			),
			array(
				'key'         => 'until',
				'type'        => 'text',
				'template'    => true,
				'label'       => __( 'Date / time', 'brn-webhooks' ),
				'placeholder' => '2026-12-31 09:00  /  tomorrow 09:00  /  {{appointment_date}}',
				'help'        => __( 'Site time zone. Accepts a date, a field, or words like "tomorrow 09:00" or "next sunday 10:00".', 'brn-webhooks' ),
				'show_if'     => array( 'mode' => 'until' ),
			),
			array(
				'key'  => '_info',
				'type' => 'info',
				'html' => esc_html__( 'Up to 30 days. Test runs skip the wait. Delays inside a loop are skipped.', 'brn-webhooks' ),
			),
		);
	}

	/**
	 * Resume timestamp (UTC) for a config, or null when the time is invalid.
	 *
	 * @param array                $config Config.
	 * @param \BRN\Webhooks\Context $ctx    Context.
	 * @return int|null
	 */
	public static function resume_at( array $config, $ctx ) {
		$now = time();
		if ( isset( $config['mode'] ) && 'until' === $config['mode'] ) {
			$raw = trim( $ctx->render( isset( $config['until'] ) ? $config['until'] : '' ) );
			if ( '' === $raw ) {
				return null;
			}
			try {
				$ts = is_numeric( $raw ) ? (int) $raw : ( new \DateTimeImmutable( $raw, wp_timezone() ) )->getTimestamp();
			} catch ( \Exception $e ) {
				return null;
			}
		} else {
			$amount = isset( $config['amount'] ) ? max( 0, (float) $config['amount'] ) : 0;
			$unit   = isset( $config['unit'] ) ? $config['unit'] : 'minutes';
			$mult   = 'days' === $unit ? DAY_IN_SECONDS : ( 'hours' === $unit ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS );
			$ts     = $now + (int) round( $amount * $mult );
		}
		return min( $ts, $now + self::MAX_SECONDS );
	}
}
