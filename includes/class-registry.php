<?php
/**
 * Integration registry.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Holds triggers, actions and connection types. Third parties can add their own:
 *
 *   add_action( 'brnwh_register', function () {
 *       \BRN\Webhooks\Registry::add_action( new My_Action() );
 *   } );
 */
class Registry {

	/**
	 * Triggers.
	 *
	 * @var Trigger[]
	 */
	private static $triggers = array();

	/**
	 * Actions.
	 *
	 * @var Action[]
	 */
	private static $actions = array();

	/**
	 * Connection types.
	 *
	 * @var Connection_Type[]
	 */
	private static $connections = array();

	/**
	 * Booted.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Register built-ins, let others extend, then hook triggers.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		foreach ( array(
			new Connections\Google(),
			new Connections\Microsoft(),
			new Connections\Meta(),
			new Connections\Http_Auth(),
			new Connections\Slack(),
			new Connections\Telegram(),
		) as $c ) {
			self::add_connection_type( $c );
		}

		foreach ( array(
			new Triggers\Webhook(),
			new Triggers\Elementor(),
			new Triggers\Cf7(),
			new Triggers\Wpforms(),
			new Triggers\Gravity(),
			new Triggers\Fluent_Forms(),
			new Triggers\Meta_Leads(),
			new Triggers\Woo_Order(),
			new Triggers\Wp_User(),
			new Triggers\Wp_Post(),
		) as $t ) {
			self::add_trigger( $t );
		}

		foreach ( array(
			new Actions\Filter(),
			new Actions\Condition(),
			new Actions\Router(),
			new Actions\Delay(),
			new Actions\Loop(),
			new Actions\Formatter(),
			new Actions\Webhook(),
			new Actions\Google_Sheets(),
			new Actions\Excel(),
			new Actions\Meta_Capi(),
			new Actions\Email(),
			new Actions\Slack(),
			new Actions\Telegram(),
		) as $a ) {
			self::add_action( $a );
		}

		do_action( 'brnwh_register' );

		foreach ( self::$triggers as $trigger ) {
			if ( $trigger->is_available() ) {
				$trigger->register();
			}
		}
	}

	/**
	 * Make sure the registry is loaded (e.g. when called very early).
	 */
	private static function ensure() {
		if ( ! self::$booted ) {
			self::boot();
		}
	}

	/**
	 * Add trigger.
	 *
	 * @param Trigger $t Trigger.
	 */
	public static function add_trigger( Trigger $t ) {
		self::$triggers[ $t->id() ] = $t;
	}

	/**
	 * Add action.
	 *
	 * @param Action $a Action.
	 */
	public static function add_action( Action $a ) {
		self::$actions[ $a->id() ] = $a;
	}

	/**
	 * Add connection type.
	 *
	 * @param Connection_Type $c Type.
	 */
	public static function add_connection_type( Connection_Type $c ) {
		self::$connections[ $c->id() ] = $c;
	}

	/**
	 * Get trigger.
	 *
	 * @param string $id Id.
	 * @return Trigger|null
	 */
	public static function trigger( $id ) {
		self::ensure();
		return isset( self::$triggers[ $id ] ) ? self::$triggers[ $id ] : null;
	}

	/**
	 * Get action.
	 *
	 * @param string $id Id.
	 * @return Action|null
	 */
	public static function action( $id ) {
		self::ensure();
		return isset( self::$actions[ $id ] ) ? self::$actions[ $id ] : null;
	}

	/**
	 * Get connection type.
	 *
	 * @param string $id Id.
	 * @return Connection_Type|null
	 */
	public static function connection_type( $id ) {
		self::ensure();
		return isset( self::$connections[ $id ] ) ? self::$connections[ $id ] : null;
	}

	/**
	 * Everything for the admin app.
	 *
	 * @return array
	 */
	public static function for_ui() {
		self::ensure();
		$map = static function ( $items ) {
			$out = array();
			foreach ( $items as $i ) {
				$out[] = $i->to_array();
			}
			return $out;
		};
		return array(
			'triggers'    => $map( self::$triggers ),
			'actions'     => $map( self::$actions ),
			'connections' => $map( self::$connections ),
		);
	}
}
