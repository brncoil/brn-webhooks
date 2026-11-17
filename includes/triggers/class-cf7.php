<?php
/**
 * Contact Form 7 trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Trigger;
use BRN\Webhooks\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Contact Form 7 submission (after validation and spam checks passed).
 */
class Cf7 extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'cf7';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Contact Form 7 submitted', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs when a Contact Form 7 form passes validation and spam checks.', 'brn-webhooks' );
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
		return defined( 'WPCF7_VERSION' );
	}

	/**
	 * Reason.
	 *
	 * @return string
	 */
	public function unavailable_reason() {
		return __( 'Requires Contact Form 7.', 'brn-webhooks' );
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
						'label' => __( 'Any Contact Form 7 form', 'brn-webhooks' ),
					),
				),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'wpcf7_submit', array( $this, 'on_submit' ), 20, 2 );
	}

	/**
	 * Handler.
	 *
	 * @param object $form   WPCF7_ContactForm.
	 * @param array  $result Result.
	 */
	public function on_submit( $form, $result ) {
		$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : '';
		if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) ) {
			return;
		}
		if ( ! class_exists( '\WPCF7_Submission' ) ) {
			return;
		}
		$submission = \WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$data = array();
		foreach ( (array) $submission->get_posted_data() as $k => $v ) {
			if ( 0 === strpos( (string) $k, '_wpcf7' ) || 'g-recaptcha-response' === $k ) {
				continue;
			}
			// Single-choice fields arrive as one-item arrays.
			$data[ $k ] = ( is_array( $v ) && 1 === count( $v ) ) ? reset( $v ) : $v;
		}
		$files = array();
		foreach ( (array) $submission->uploaded_files() as $name => $paths ) {
			$files[ $name ] = array_map( 'wp_basename', (array) $paths );
		}
		if ( $files ) {
			$data['_files'] = $files;
		}
		$data['_meta'] = Util::request_meta(
			array(
				'form_id'   => (string) $form->id(),
				'form_name' => (string) $form->title(),
				'page_url'  => (string) $submission->get_meta( 'url' ),
			)
		);
		$this->fire( $data, array( 'form_id' => (string) $form->id() ) );
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
		if ( 'forms' !== $source || ! class_exists( '\WPCF7_ContactForm' ) ) {
			return array();
		}
		$out = array(
			array(
				'value' => '',
				'label' => __( 'Any Contact Form 7 form', 'brn-webhooks' ),
			),
		);
		foreach ( \WPCF7_ContactForm::find( array( 'posts_per_page' => 200 ) ) as $form ) {
			$out[] = array(
				'value' => (string) $form->id(),
				'label' => $form->title(),
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
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			return $out;
		}
		$forms = ! empty( $config['form_id'] ) ? array( \WPCF7_ContactForm::get_instance( (int) $config['form_id'] ) ) : \WPCF7_ContactForm::find( array( 'posts_per_page' => 50 ) );
		foreach ( array_filter( $forms ) as $form ) {
			foreach ( $form->scan_form_tags() as $tag ) {
				if ( ! empty( $tag->name ) ) {
					$out[ $tag->name ] = $tag->name;
				}
			}
		}
		$out['_meta.form_name'] = __( 'Form name', 'brn-webhooks' );
		$out['_meta.page_url']  = __( 'Page URL', 'brn-webhooks' );
		return $out;
	}
}
