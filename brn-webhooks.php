<?php
/**
 * Plugin Name:       BRN Webhooks
 * Plugin URI:        https://github.com/brncoil/brn-webhooks
 * Description:       Automations without Zapier or Make. Connect site forms (Elementor, Contact Form 7, WPForms, Gravity Forms, Fluent Forms), incoming webhooks, Facebook Lead Ads, WooCommerce and WordPress events to Google Sheets, Microsoft 365 Excel, Meta Conversions API, Slack, Telegram, email and any HTTP API — with conditions, logs and retries.
 * Version:           1.0.2
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            BRN
 * Author URI:        https://brn.co.il
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brn-webhooks
 * Domain Path:       /languages
 * Update URI:        https://github.com/brncoil/brn-webhooks
 *
 * @package BRN\Webhooks
 */

defined( 'ABSPATH' ) || exit;

// Already loaded in this request (e.g. WordPress re-activating the plugin right after an
// automatic update includes the new file while the old one is in memory): do nothing, so the
// activation check sees no "already defined" warnings and keeps the plugin active.
if ( defined( 'BRNWH_VERSION' ) ) {
	return;
}

define( 'BRNWH_VERSION', '1.0.2' );
define( 'BRNWH_DB_VERSION', '1' );
define( 'BRNWH_FILE', __FILE__ );
define( 'BRNWH_DIR', plugin_dir_path( __FILE__ ) );
define( 'BRNWH_URL', plugin_dir_url( __FILE__ ) );
define( 'BRNWH_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'BRNWH_GITHUB_REPO' ) ) {
	define( 'BRNWH_GITHUB_REPO', 'brncoil/brn-webhooks' );
}

/**
 * PSR-4-ish autoloader for the BRN\Webhooks namespace.
 *
 * BRN\Webhooks\Google_Auth          -> includes/class-google-auth.php
 * BRN\Webhooks\Triggers\Elementor   -> includes/triggers/class-elementor.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'BRN\\Webhooks\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$name     = array_pop( $parts );
		$dir      = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$file     = BRNWH_DIR . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'BRN\\Webhooks\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BRN\\Webhooks\\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		BRN\Webhooks\Plugin::instance()->boot();
	},
	5
);
