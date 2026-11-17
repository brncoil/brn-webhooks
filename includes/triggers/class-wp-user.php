<?php
/**
 * New user trigger.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks\Triggers;

use BRN\Webhooks\Flows;
use BRN\Webhooks\Trigger;
use BRN\Webhooks\Util;

defined( 'ABSPATH' ) || exit;

/**
 * A new WordPress user registered.
 */
class Wp_User extends Trigger {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wp_user';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'New user registered', 'brn-webhooks' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function description() {
		return __( 'Runs when a new WordPress user account is created.', 'brn-webhooks' );
	}

	/**
	 * Group.
	 *
	 * @return string
	 */
	public function group() {
		return 'WordPress';
	}

	/**
	 * Icon.
	 *
	 * @return string
	 */
	public function icon() {
		return 'wordpress';
	}

	/**
	 * Fields.
	 *
	 * @return array
	 */
	public function fields() {
		$roles = array( '' => __( 'Any role', 'brn-webhooks' ) );
		if ( function_exists( 'wp_roles' ) ) {
			foreach ( wp_roles()->get_names() as $k => $l ) {
				$roles[ $k ] = translate_user_role( $l );
			}
		}
		return array(
			array(
				'key'     => 'role',
				'type'    => 'select',
				'label'   => __( 'Role', 'brn-webhooks' ),
				'options' => self::options( $roles ),
			),
		);
	}

	/**
	 * Hook.
	 */
	public function register() {
		add_action( 'user_register', array( $this, 'on_register' ), 20, 1 );
	}

	/**
	 * Matches role.
	 *
	 * @param array $config Config.
	 * @param array $event  Event.
	 * @return bool
	 */
	public function matches( array $config, array $event ) {
		return empty( $config['role'] ) || ( isset( $event['roles'] ) && in_array( $config['role'], $event['roles'], true ) );
	}

	/**
	 * Handler.
	 *
	 * @param int $user_id User id.
	 */
	public function on_register( $user_id ) {
		if ( ! Flows::has_active( $this->id() ) && ! Flows::listening_ids() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$data = array(
			'user_id'      => $user->ID,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'display_name' => $user->display_name,
			'roles'        => array_values( (array) $user->roles ),
			'registered'   => $user->user_registered,
			'_meta'        => Util::request_meta(),
		);
		$this->fire( $data, array( 'roles' => array_values( (array) $user->roles ) ) );
	}
}
