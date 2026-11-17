<?php
/**
 * WPForms trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Trigger;
use BRN\Webhooks\Util;

defined( 'ABSPATH' ) || exit;

/**
 * WPForms submission.
 */
class Wpforms extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wpforms';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WPForms submitted', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs when a WPForms form is submitted.', 'brn-webhooks' );
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
		return function_exists( 'wpforms' );
	}

	/**
	 * Reason.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Requires WPForms.', 'brn-webhooks' );
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
						'label' => __( 'Any WPForms form', 'brn-webhooks' ),
					),
				),
				'help'           => __( 'Fields are named by their label. Every field is also available as field_ID.', 'brn-webhooks' ),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'wpforms_process_complete', array( $this, 'on_submit' ), 20, 4 );
	}

	/**
	 * Handler.
	 *
	 * @param array $fields    Fields.
	 * @param array $entry     Entry.
	 * @param array $form_data Form data.
	 * @param int   $entry_id  Entry id.
	 */
	public function on_submit( $fields, $entry, $form_data, $entry_id ) {
		$data = array();
		foreach ( (array) $fields as $id => $f ) {
			$label = isset( $f['name'] ) ? trim( (string) $f['name'] ) : '';
			$value = isset( $f['value'] ) ? $f['value'] : '';
			$data[ 'field_' . $id ] = $value;
			if ( '' !== $label && ! isset( $data[ $label ] ) ) {
				$data[ $label ] = $value;
			}
			// Name / address sub-values.
			foreach ( array( 'first', 'middle', 'last', 'address1', 'address2', 'city', 'state', 'postal', 'country' ) as $sub ) {
				if ( isset( $f[ $sub ] ) && '' !== $f[ $sub ] ) {
					$data[ 'field_' . $id . '_' . $sub ] = $f[ $sub ];
				}
			}
		}
		$form_id       = isset( $form_data['id'] ) ? (string) $form_data['id'] : '';
		$data['_meta'] = Util::request_meta(
			array(
				'form_id'   => $form_id,
				'form_name' => isset( $form_data['settings']['form_title'] ) ? (string) $form_data['settings']['form_title'] : '',
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
		$out = array(
			array(
				'value' => '',
				'label' => __( 'Any WPForms form', 'brn-webhooks' ),
			),
		);
		if ( 'forms' !== $source ) {
			return array();
		}
		$forms = get_posts(
			array(
				'post_type'      => 'wpforms',
				'posts_per_page' => 200,
				'post_status'    => 'publish',
			)
		);
		foreach ( $forms as $p ) {
			$out[] = array(
				'value' => (string) $p->ID,
				'label' => $p->post_title,
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
		if ( empty( $config['form_id'] ) ) {
			return $out;
		}
		$post = get_post( (int) $config['form_id'] );
		$form = $post ? json_decode( $post->post_content, true ) : null;
		if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $id => $f ) {
				$label                  = isset( $f['label'] ) ? (string) $f['label'] : '';
				$out[ '' !== $label ? $label : 'field_' . $id ] = '' !== $label ? $label : 'field_' . $id;
			}
		}
		return $out;
	}
}
