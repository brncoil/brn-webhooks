<?php
/**
 * Gravity Forms trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Trigger;
use BRN\Webhooks\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Gravity Forms submission.
 */
class Gravity extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'gravity';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Gravity Forms submitted', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs after a Gravity Forms entry is saved.', 'brn-webhooks' );
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
		return class_exists( '\GFAPI' );
	}

	/**
	 * Reason.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Requires Gravity Forms.', 'brn-webhooks' );
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
						'label' => __( 'Any Gravity form', 'brn-webhooks' ),
					),
				),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'gform_after_submission', array( $this, 'on_submit' ), 20, 2 );
	}

	/**
	 * Handler.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 */
	public function on_submit( $entry, $form ) {
		$data = array();
		foreach ( isset( $form['fields'] ) ? (array) $form['fields'] : array() as $field ) {
			$id    = is_object( $field ) ? $field->id : ( isset( $field['id'] ) ? $field['id'] : '' );
			$label = trim( (string) ( is_object( $field ) ? $field->label : ( isset( $field['label'] ) ? $field['label'] : '' ) ) );
			$inputs = is_object( $field ) ? $field->inputs : ( isset( $field['inputs'] ) ? $field['inputs'] : null );
			if ( is_array( $inputs ) && $inputs ) {
				$parts = array();
				foreach ( $inputs as $input ) {
					$iid = (string) $input['id'];
					$val = isset( $entry[ $iid ] ) ? $entry[ $iid ] : '';
					if ( '' === $val ) {
						continue;
					}
					$data[ 'field_' . str_replace( '.', '_', $iid ) ] = $val;
					$parts[]                                          = $val;
				}
				$value = implode( ' ', $parts );
			} else {
				$value = isset( $entry[ (string) $id ] ) ? $entry[ (string) $id ] : '';
			}
			$data[ 'field_' . $id ] = $value;
			if ( '' !== $label && ! isset( $data[ $label ] ) ) {
				$data[ $label ] = $value;
			}
		}
		$form_id       = isset( $form['id'] ) ? (string) $form['id'] : '';
		$data['_meta'] = Util::request_meta(
			array(
				'form_id'   => $form_id,
				'form_name' => isset( $form['title'] ) ? (string) $form['title'] : '',
				'entry_id'  => isset( $entry['id'] ) ? (int) $entry['id'] : 0,
				'page_url'  => isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '',
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
		if ( 'forms' !== $source || ! class_exists( '\GFAPI' ) ) {
			return array();
		}
		$out = array(
			array(
				'value' => '',
				'label' => __( 'Any Gravity form', 'brn-webhooks' ),
			),
		);
		foreach ( (array) \GFAPI::get_forms() as $form ) {
			$out[] = array(
				'value' => (string) $form['id'],
				'label' => $form['title'],
			);
		}
		return $out;
	}

	/**
	 * Known fields.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	public function known_fields( array $config ) {
		$out = array();
		if ( empty( $config['form_id'] ) || ! class_exists( '\GFAPI' ) ) {
			return $out;
		}
		$form = \GFAPI::get_form( (int) $config['form_id'] );
		foreach ( $form && isset( $form['fields'] ) ? $form['fields'] : array() as $field ) {
			$label         = is_object( $field ) ? $field->label : $field['label'];
			$out[ $label ] = $label;
		}
		return $out;
	}
}
