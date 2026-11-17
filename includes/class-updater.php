<?php
/**
 * Updates from GitHub releases.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Hands new versions published as GitHub releases to the WordPress updater.
 *
 * - The plugin header declares "Update URI: https://github.com/brncoil/brn-webhooks", so
 *   WordPress asks us (update_plugins_github.com) instead of wordpress.org.
 * - The release must carry an asset named brn-webhooks.zip (built by .github/workflows/release.yml).
 * - Private repository: define BRNWH_GITHUB_TOKEN in wp-config.php (or save a token in Settings);
 *   the package is then downloaded through the API with the token.
 * - Automatic updates are on by default (Settings → Updates).
 */
class Updater {

	const CACHE_KEY = 'brnwh_github_release';
	const ASSET     = 'brn-webhooks.zip';

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( $this, 'auto_update' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'flush' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Request headers for the GitHub API.
	 *
	 * @param string $accept Accept header.
	 * @return array
	 */
	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h     = array(
			'Accept'               => $accept,
			'User-Agent'           => 'BRN-Webhooks-Updater/' . BRNWH_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token = Settings::github_token();
		if ( '' !== $token ) {
			$h['Authorization'] = 'Bearer ' . $token;
		}
		return $h;
	}

	/**
	 * Latest release info (cached 6 hours; errors cached 30 minutes).
	 *
	 * @param bool $force Skip cache.
	 * @return array|null {version, zip, api_zip, notes, url, published}
	 */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['version'] ) ? $cached : null;
			}
		}
		$res = wp_remote_get(
			'https://api.github.com/repos/' . BRNWH_GITHUB_REPO . '/releases/latest',
			array(
				'headers' => self::headers(),
				'timeout' => 15,
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$body = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient(
				self::CACHE_KEY,
				array(
					'error' => is_wp_error( $res ) ? $res->get_error_message() : ( 404 === $code ? 'not_found_or_private' : 'http_' . $code ),
					'at'    => time(),
				),
				30 * MINUTE_IN_SECONDS
			);
			return null;
		}
		$zip     = '';
		$api_zip = '';
		foreach ( isset( $body['assets'] ) ? (array) $body['assets'] : array() as $asset ) {
			if ( isset( $asset['name'] ) && self::ASSET === $asset['name'] ) {
				$zip     = isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';
				$api_zip = isset( $asset['url'] ) ? $asset['url'] : '';
			}
		}
		$info = array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'zip'       => $zip,
			'api_zip'   => $api_zip,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
			'at'        => time(),
		);
		set_site_transient( self::CACHE_KEY, $info, 6 * HOUR_IN_SECONDS );
		return $info;
	}

	/**
	 * Status for the settings screen.
	 *
	 * @return array
	 */
	public static function status() {
		$latest = self::latest();
		$cached = get_site_transient( self::CACHE_KEY );
		return array(
			'current'      => BRNWH_VERSION,
			'latest'       => $latest ? $latest['version'] : null,
			'available'    => $latest && version_compare( $latest['version'], BRNWH_VERSION, '>' ),
			'release_url'  => $latest ? $latest['url'] : '',
			'notes'        => $latest ? $latest['notes'] : '',
			'checked_at'   => is_array( $cached ) && isset( $cached['at'] ) ? gmdate( 'c', (int) $cached['at'] ) : null,
			'error'        => is_array( $cached ) && isset( $cached['error'] ) ? $cached['error'] : null,
			'repo'         => BRNWH_GITHUB_REPO,
			'has_token'    => '' !== Settings::github_token(),
			'auto_update'  => (bool) Settings::get( 'auto_update' ),
			'update_url'   => wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( BRNWH_BASENAME ) ), 'upgrade-plugin_' . BRNWH_BASENAME ),
		);
	}

	/**
	 * WordPress asks for an update for plugins whose Update URI host is github.com.
	 *
	 * @param array|false $update      Update.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file.
	 * @param string[]    $locales     Locales.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file, $locales ) {
		if ( BRNWH_BASENAME !== $plugin_file ) {
			return $update;
		}
		$latest = self::latest();
		if ( ! $latest || empty( $latest['zip'] ) ) {
			return $update;
		}
		return array(
			'id'           => 'https://github.com/' . BRNWH_GITHUB_REPO,
			'slug'         => 'brn-webhooks',
			'plugin'       => BRNWH_BASENAME,
			'version'      => $latest['version'],
			'new_version'  => $latest['version'],
			'url'          => 'https://github.com/' . BRNWH_GITHUB_REPO,
			'package'      => $latest['zip'],
			'requires'     => '6.5',
			'requires_php' => '7.4',
			'tested'       => get_bloginfo( 'version' ),
			'icons'        => array( 'svg' => BRNWH_URL . 'assets/img/icon.svg' ),
		);
	}

	/**
	 * Download the package; for private repos go through the API with the token.
	 *
	 * GitHub redirects asset downloads to a signed storage URL that rejects the Authorization
	 * header, so the redirect is resolved first and followed without credentials.
	 *
	 * @param bool|string|\WP_Error $reply    Reply.
	 * @param string                $package  Package URL.
	 * @param \WP_Upgrader          $upgrader Upgrader.
	 * @param array                 $hook     Hook extra.
	 * @return bool|string|\WP_Error
	 */
	public function download( $reply, $package, $upgrader = null, $hook = array() ) {
		if ( false !== $reply || ! is_string( $package ) || false === strpos( $package, 'github.com/' . BRNWH_GITHUB_REPO . '/releases/download/' ) ) {
			return $reply;
		}
		$token = Settings::github_token();
		if ( '' === $token ) {
			return $reply; // Public repository — WordPress downloads it normally.
		}
		$latest = self::latest();
		if ( ! $latest || empty( $latest['api_zip'] ) ) {
			return $reply;
		}
		$res = wp_remote_get(
			$latest['api_zip'],
			array(
				'headers'     => self::headers( 'application/octet-stream' ),
				'redirection' => 0,
				'timeout'     => 30,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$location = wp_remote_retrieve_header( $res, 'location' );
		if ( $location ) {
			return download_url( $location, 300 );
		}
		if ( 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$tmp = wp_tempnam( self::ASSET );
			if ( $tmp && false !== file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return $tmp;
			}
		}
		return new \WP_Error( 'brnwh_download', __( 'Could not download the update from GitHub. Check the GitHub token in BRN Webhooks → Settings.', 'brn-webhooks' ) );
	}

	/**
	 * Make sure the extracted folder is called brn-webhooks.
	 *
	 * @param string       $source        Source dir.
	 * @param string       $remote_source Remote source.
	 * @param \WP_Upgrader $upgrader      Upgrader.
	 * @param array        $hook_extra    Extra.
	 * @return string|\WP_Error
	 */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || BRNWH_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . 'brn-webhooks/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return $source;
	}

	/**
	 * Force automatic updates when enabled in settings.
	 *
	 * @param bool|null $update Update.
	 * @param object    $item   Item.
	 * @return bool|null
	 */
	public function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && BRNWH_BASENAME === $item->plugin && Settings::get( 'auto_update' ) ) {
			return true;
		}
		return $update;
	}

	/**
	 * "View details" modal.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'brn-webhooks' !== $args->slug ) {
			return $result;
		}
		$latest = self::latest();
		return (object) array(
			'name'          => 'BRN Webhooks',
			'slug'          => 'brn-webhooks',
			'version'       => $latest ? $latest['version'] : BRNWH_VERSION,
			'author'        => '<a href="https://brn.co.il">BRN</a>',
			'homepage'      => 'https://github.com/' . BRNWH_GITHUB_REPO,
			'requires'      => '6.5',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $latest ? $latest['published'] : '',
			'download_link' => $latest ? $latest['zip'] : '',
			'sections'      => array(
				'description' => esc_html__( 'Automations without Zapier or Make: forms, webhooks and WordPress events to Google Sheets, Excel 365, Meta, Slack, Telegram, email and any API — with conditions, logs and retries.', 'brn-webhooks' ),
				'changelog'   => $latest ? self::markdown( $latest['notes'] ) : '',
			),
			'banners'       => array(),
		);
	}

	/**
	 * Minimal markdown → HTML for release notes.
	 *
	 * @param string $md Markdown.
	 * @return string
	 */
	private static function markdown( $md ) {
		$html  = '';
		$in_ul = false;
		foreach ( preg_split( '/\r?\n/', esc_html( (string) $md ) ) as $line ) {
			$line = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line );
			$line = preg_replace( '/`(.+?)`/', '<code>$1</code>', $line );
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m ) ) {
				if ( ! $in_ul ) {
					$html .= '<ul>';
					$in_ul = true;
				}
				$html .= '<li>' . $m[1] . '</li>';
				continue;
			}
			if ( $in_ul ) {
				$html .= '</ul>';
				$in_ul = false;
			}
			if ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . $m[1] . '</h4>';
			} elseif ( '' !== trim( $line ) ) {
				$html .= '<p>' . $line . '</p>';
			}
		}
		return $html . ( $in_ul ? '</ul>' : '' );
	}

	/**
	 * Clear the cache after updating.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $extra    Extra.
	 */
	public function flush( $upgrader, $extra ) {
		if ( isset( $extra['type'] ) && 'plugin' === $extra['type'] ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Links on the plugins screen.
	 *
	 * @param array  $links Links.
	 * @param string $file  File.
	 * @return array
	 */
	public function row_meta( $links, $file ) {
		if ( BRNWH_BASENAME === $file ) {
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=brn-webhooks#/settings' ) ) . '">' . esc_html__( 'Settings & updates', 'brn-webhooks' ) . '</a>';
		}
		return $links;
	}
}
