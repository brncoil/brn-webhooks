<?php
/**
 * Connection type base.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Credentials for a third-party service.
 */
abstract class Connection_Type extends Integration {

	/**
	 * Validate credentials before saving.
	 *
	 * @param array $creds Credentials.
	 * @return true|\WP_Error
	 */
	public function validate( array $creds ) {
		foreach ( $this->fields() as $f ) {
			if ( ! empty( $f['required'] ) && ! empty( $f['key'] ) && '' === trim( (string) ( isset( $creds[ $f['key'] ] ) ? $creds[ $f['key'] ] : '' ) ) ) {
				/* translators: %s: field label */
				return new \WP_Error( 'brnwh_required', sprintf( __( '"%s" is required.', 'brn-webhooks' ), $f['label'] ), array( 'status' => 400 ) );
			}
		}
		return true;
	}

	/**
	 * Test credentials. Return a success message or throw.
	 *
	 * @param array $creds Credentials.
	 * @return string
	 * @throws \Exception On failure.
	 */
	public function test( array $creds ) {
		return __( 'Saved.', 'brn-webhooks' );
	}

	/**
	 * Non-secret metadata stored next to the credentials.
	 *
	 * @param array $creds Credentials.
	 * @return array
	 */
	public function meta( array $creds ) {
		return array();
	}

	/**
	 * One-line summary for the UI (never secrets).
	 *
	 * @param array $conn Connection.
	 * @return string
	 */
	public function summary( array $conn ) {
		return '';
	}

	/**
	 * Setup instructions (trusted HTML).
	 *
	 * @return string
	 */
	public function help() {
		return '';
	}

	/**
	 * For the admin app.
	 *
	 * @return array
	 */
	public function to_array() {
		$a         = parent::to_array();
		$a['help'] = $this->help();
		return $a;
	}
}
