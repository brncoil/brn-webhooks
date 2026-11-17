<?php
/**
 * Bootstrap.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component together.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Booted flag.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot the plugin.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );

		// Integrations register after every other plugin is loaded so class_exists() checks are reliable.
		add_action( 'plugins_loaded', array( Registry::class, 'boot' ), 20 );

		Engine::hooks();
		( new Rest() )->hooks();
		( new Incoming() )->hooks();
		( new Updater() )->hooks();
		Cron::hooks();

		if ( is_admin() ) {
			( new Admin() )->hooks();
		}
	}

	/**
	 * Load translations from the plugin's languages folder.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'brn-webhooks', false, dirname( BRNWH_BASENAME ) . '/languages' );
	}

	/**
	 * Messages of the current admin locale, used to translate the JS app.
	 *
	 * @return array<string,string>
	 */
	public static function js_translations() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$file   = BRNWH_DIR . 'languages/brn-webhooks-' . $locale . '.l10n.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = include $file;
		return ( is_array( $data ) && isset( $data['messages'] ) && is_array( $data['messages'] ) ) ? $data['messages'] : array();
	}
}
