<?php
/**
 * Elementor Pro forms trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Trigger;
use BRN\Webhooks\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor Pro form submission.
 */
class Elementor extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'elementor';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Elementor form submitted', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs when a visitor submits an Elementor Pro form.', 'brn-webhooks' );
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
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Reason.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Requires Elementor Pro.', 'brn-webhooks' );
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
						'label' => __( 'Any Elementor form', 'brn-webhooks' ),
					),
				),
				'help'           => __( 'Field IDs (set in each form field\'s Advanced tab) become the field names here.', 'brn-webhooks' ),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'elementor_pro/forms/new_record', array( $this, 'on_submit' ), 20, 2 );
	}

	/**
	 * Submission handler.
	 *
	 * @param object $record  Form record.
	 * @param object $handler Ajax handler.
	 */
	public function on_submit( $record, $handler ) {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}
		$fields = (array) $record->get( 'fields' );
		$data   = array();
		$labels = array();
		foreach ( $fields as $id => $f ) {
			$key = isset( $f['id'] ) ? (string) $f['id'] : (string) $id;
			$val = isset( $f['raw_value'] ) && is_array( $f['raw_value'] ) ? $f['raw_value'] : ( isset( $f['value'] ) ? $f['value'] : '' );
			$data[ $key ]   = $val;
			$labels[ $key ] = isset( $f['title'] ) && '' !== $f['title'] ? $f['title'] : $key;
		}
		$form_id   = (string) $record->get_form_settings( 'id' );
		$form_name = (string) $record->get_form_settings( 'form_name' );
		$post_id   = (int) $record->get_form_settings( 'form_post_id' );
		$meta      = Util::request_meta(
			array(
				'form_id'   => $form_id,
				'form_name' => $form_name,
				'post_id'   => $post_id,
			)
		);
		$data['_labels'] = $labels;
		$data['_meta']   = $meta;
		$this->fire( $data, array( 'form_id' => $form_id ) );
	}

	/**
	 * Dynamic options.
	 *
	 * @param string     $source     Source.
	 * @param array      $config     Config.
	 * @param array|null $connection Connection.
	 * @return array
	 */
	public function get_options( $source, array $config, $connection = null ) {
		if ( 'forms' !== $source ) {
			return array();
		}
		$out = array(
			array(
				'value' => '',
				'label' => __( 'Any Elementor form', 'brn-webhooks' ),
			),
		);
		foreach ( self::find_forms() as $form ) {
			$out[] = array(
				'value' => $form['id'],
				'label' => $form['name'] . ' — ' . $form['page'],
			);
		}
		return $out;
	}

	/**
	 * Known fields for the picker.
	 *
	 * @param array $config Config.
	 * @return array
	 */
	public function known_fields( array $config ) {
		$out = array();
		foreach ( self::find_forms() as $form ) {
			if ( ! empty( $config['form_id'] ) && $form['id'] !== $config['form_id'] ) {
				continue;
			}
			foreach ( $form['fields'] as $k => $label ) {
				$out[ $k ] = $label;
			}
		}
		$out['_meta.form_name'] = __( 'Form name', 'brn-webhooks' );
		$out['_meta.page_url']  = __( 'Page URL', 'brn-webhooks' );
		$out['_meta.ip']        = __( 'Visitor IP', 'brn-webhooks' );
		return $out;
	}

	/**
	 * Scan Elementor data for form widgets.
	 *
	 * @return array
	 */
	private static function find_forms() {
		global $wpdb;
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		$rows  = $wpdb->get_results( "SELECT p.ID, p.post_title, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND pm.meta_value LIKE '%\"widgetType\":\"form\"%' AND p.post_status IN ('publish','private','draft') AND p.post_type <> 'revision' LIMIT 300" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as $row ) {
			$tree = json_decode( $row->meta_value, true );
			if ( ! is_array( $tree ) ) {
				continue;
			}
			self::walk( $tree, $row, $cache );
		}
		return $cache;
	}

	/**
	 * Recursive walk.
	 *
	 * @param array  $elements Elements.
	 * @param object $row      Post row.
	 * @param array  $out      Output.
	 */
	private static function walk( array $elements, $row, array &$out ) {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['widgetType'] ) && 'form' === $el['widgetType'] ) {
				$settings = isset( $el['settings'] ) ? $el['settings'] : array();
				$fields   = array();
				foreach ( isset( $settings['form_fields'] ) ? (array) $settings['form_fields'] : array() as $f ) {
					$cid = isset( $f['custom_id'] ) ? $f['custom_id'] : ( isset( $f['_id'] ) ? $f['_id'] : '' );
					if ( '' === $cid ) {
						continue;
					}
					$fields[ $cid ] = isset( $f['field_label'] ) && '' !== $f['field_label'] ? $f['field_label'] : $cid;
				}
				$out[] = array(
					'id'     => (string) $el['id'],
					'name'   => isset( $settings['form_name'] ) && '' !== $settings['form_name'] ? $settings['form_name'] : __( 'Form', 'brn-webhooks' ),
					'page'   => '' !== $row->post_title ? $row->post_title : '#' . $row->ID,
					'fields' => $fields,
				);
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $row, $out );
			}
		}
	}
}
