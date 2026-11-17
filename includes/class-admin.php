<?php
/**
 * Admin screen.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the menu and loads the single-page admin app.
 */
class Admin {

	/**
	 * Screen hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . BRNWH_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$icon       = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17c.01-.7.2-1.4.57-2"/><path d="m6 17 3.13-5.78c.53-.97.1-2.18-.5-3.1a4 4 0 1 1 6.89-4.06"/><path d="m12 6 3.13 5.73C15.66 12.7 16.9 13 18 13a4 4 0 0 1 0 8"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$cap        = Rest::capability();
		$this->hook = add_menu_page( 'BRN Webhooks', __( 'Webhooks', 'brn-webhooks' ), $cap, 'brn-webhooks', array( $this, 'render' ), $icon, 58 );
		$subs       = array(
			''             => __( 'Dashboard', 'brn-webhooks' ),
			'/flows'       => __( 'Flows', 'brn-webhooks' ),
			'/connections' => __( 'Connections', 'brn-webhooks' ),
			'/logs'        => __( 'Logs', 'brn-webhooks' ),
			'/settings'    => __( 'Settings', 'brn-webhooks' ),
		);
		foreach ( $subs as $route => $label ) {
			add_submenu_page( 'brn-webhooks', $label . ' — BRN Webhooks', $label, $cap, '' === $route ? 'brn-webhooks' : 'brn-webhooks#' . $route, '' === $route ? array( $this, 'render' ) : '__return_null' );
		}
		add_action( 'load-' . $this->hook, array( $this, 'on_load' ) );
	}

	/**
	 * Fix submenu links (hash routes) to point at the app page.
	 */
	public function on_load() {
		// Keep the screen clean: other plugins' notices don't belong inside the app.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		add_filter( 'admin_footer_text', '__return_empty_string', 99 );
		add_filter( 'update_footer', '__return_empty_string', 99 );
	}

	/**
	 * Settings link on the plugins list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=brn-webhooks' ) ) . '">' . esc_html__( 'Open', 'brn-webhooks' ) . '</a>' );
		return $links;
	}

	/**
	 * Enqueue the app.
	 *
	 * @param string $hook Hook.
	 */
	public function assets( $hook ) {
		// Submenu hash links need the page slug fix on every admin screen.
		wp_add_inline_script( 'common', self::submenu_fix_js() );
		if ( $hook !== $this->hook ) {
			return;
		}
		$v    = BRNWH_VERSION;
		$base = BRNWH_URL . 'assets/';
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$v .= '.' . time();
		}
		wp_enqueue_style( 'brnwh-admin', $base . 'css/admin.css', array(), $v );

		$scripts = array( 'lib', 'fields', 'dashboard', 'flows', 'editor', 'connections', 'logs', 'settings', 'app' );
		$prev    = array();
		foreach ( $scripts as $s ) {
			$path = in_array( $s, array( 'lib', 'fields', 'app' ), true ) ? 'js/' . $s . '.js' : 'js/views/' . $s . '.js';
			wp_enqueue_script( 'brnwh-' . $s, $base . $path, $prev, $v, true );
			$prev = array( 'brnwh-' . $s );
		}
		wp_localize_script(
			'brnwh-lib',
			'BRNWH_BOOT',
			array(
				'root'      => esc_url_raw( rest_url( Rest::NS . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'version'   => BRNWH_VERSION,
				'rtl'       => is_rtl(),
				'locale'    => determine_locale(),
				'adminUrl'  => admin_url(),
				'siteName'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'i18n'      => Plugin::js_translations(),
				'gmtOffset' => (float) get_option( 'gmt_offset' ),
				'user'      => wp_get_current_user()->display_name,
				'adminEmail' => get_option( 'admin_email' ),
			)
		);
	}

	/**
	 * Submenu items use "brn-webhooks#/route" slugs; turn them into proper links.
	 *
	 * @return string
	 */
	private static function submenu_fix_js() {
		return "document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('#adminmenu a[href*=\"page=brn-webhooks#\"], #adminmenu a[href*=\"brn-webhooks%23\"]').forEach(function(a){var h=a.getAttribute('href');a.setAttribute('href',h.replace('brn-webhooks%23','brn-webhooks#'));});});";
	}

	/**
	 * Page.
	 */
	public function render() {
		echo '<div id="brnwh-app" class="brnwh' . ( is_rtl() ? ' brnwh-rtl' : '' ) . '" dir="' . ( is_rtl() ? 'rtl' : 'ltr' ) . '"><div class="brnwh-boot"><div class="brnwh-spinner"></div></div></div>';
		echo '<noscript><p>' . esc_html__( 'BRN Webhooks needs JavaScript.', 'brn-webhooks' ) . '</p></noscript>';
	}
}
