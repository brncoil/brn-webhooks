<?php
/**
 * Fluent Forms trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Trigger;
use BRN\Webhooks\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Fluent Forms submission.
 */
class Fluent_Forms extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'fluentforms';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Fluent Forms submitted', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs when a Fluent Forms submission is saved.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return __( 'Forms', 'brn-webhooks' );
	}

	/**
	 * Available?
	 *
	 * @return bool
	 */
	public function is_available() {
		return defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' );
	}

	/**
	 * Reason.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Requires Fluent Forms.', 'brn-webhooks' );
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		return array(
			array(
				'key'            => 'form_id',
				'type'           => 'select',
				'label'          => __( 'Form', 'brn-webhooks' ),
				'options_source' => 'forms',
				'options'        => array(
					array(
						'value' => '',
						'label' => __( 'Any Fluent form', 'brn-webhooks' ),
					),
				),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'fluentform/submission_inserted', array( $this, 'on_submit' ), 20, 3 );
	}

	/**
	 * Handler.
	 *
	 * @param int    $entry_id  Entry id.
	 * @param array  $form_data Data.
	 * @param object $form      Form.
	 */
	public function on_submit( $entry_id, $form_data, $form ) {
		$data = array();
		foreach ( (array) $form_data as $k => $v ) {
			if ( 0 === strpos( (string) $k, '_' ) || in_array( $k, array( '__fluent_form_embded_post_id', 'g-recaptcha-response', 'h-captcha-response' ), true ) ) {
				continue;
			}
			$data[ $k ] = $v;
		}
		$form_id       = is_object( $form ) && isset( $form->id ) ? (string) $form->id : '';
		$data['_meta'] = Util::request_meta(
			array(
				'form_id'   => $form_id,
				'form_name' => is_object( $form ) && isset( $form->title ) ? (string) $form->title : '',
				'entry_id'  => (int) $entry_id,
			)
		);
		$this->fire( $data, array( 'form_id' => $form_id ) );
	}

	/**
	 * Options.
	 *
	 * @param string     $source     Source.
	 * @param array      $config     Config.
	 * @param array|null $connection Connection.
	 * @return array
	 */
	public function get_options( $source, array $config, $connection = null ) {
		global $wpdb;
		if ( 'forms' !== $source ) {
			return array();
		}
		$out   = array(
			array(
				'value' => '',
				'label' => __( 'Any Fluent form', 'brn-webhooks' ),
			),
		);
		$table = $wpdb->prefix . 'fluentform_forms';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return $out;
		}
		foreach ( (array) $wpdb->get_results( "SELECT id, title FROM {$table} ORDER BY id DESC LIMIT 200" ) as $f ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[] = array(
				'value' => (string) $f->id,
				'label' => $f->title,
			);
		}
		return $out;
	}
}
