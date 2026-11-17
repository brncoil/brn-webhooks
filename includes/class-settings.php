<?php
/**
 * Plugin settings.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the brnwh_settings option.
 */
class Settings {

	const OPTION = 'brnwh_settings';

	/**
	 * Cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'log_retention_days'   => 30,
			'log_level'            => 'all',     // all | errors | none.
			'log_filtered'         => true,
			'mask_sensitive'       => true,
			'allow_internal_urls'  => false,
			'trust_proxy'          => false,     // Trust X-Forwarded-For / CF-Connecting-IP for IP allowlists.
			'rate_limit'           => 120,       // Incoming requests per minute per flow.
			'max_payload_kb'       => 1024,
			'default_retries'      => 3,
			'notify_failures'      => true,
			'notify_email'         => '',
			'meta_api_version'     => 'v23.0',
			'auto_update'          => true,
			'github_token'         => '',        // Stored encrypted.
			'delete_on_uninstall'  => false,
		);
	}

	/**
	 * All settings (github_token stays encrypted).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Single value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Decrypted GitHub token (constant wins).
	 *
	 * @return string
	 */
	public static function github_token() {
		if ( defined( 'BRNWH_GITHUB_TOKEN' ) && BRNWH_GITHUB_TOKEN ) {
			return (string) BRNWH_GITHUB_TOKEN;
		}
		$enc = (string) self::get( 'github_token' );
		return '' === $enc ? '' : (string) Crypto::decrypt( $enc );
	}

	/**
	 * Values for the admin UI (no secrets).
	 *
	 * @return array
	 */
	public static function for_ui() {
		$all = self::all();
		$all['github_token']          = '' !== (string) $all['github_token'] ? Connections::MASK : '';
		$all['github_token_constant'] = defined( 'BRNWH_GITHUB_TOKEN' ) && BRNWH_GITHUB_TOKEN;
		$all['encryption_constant']   = defined( 'BRNWH_ENCRYPTION_KEY' ) && BRNWH_ENCRYPTION_KEY;
		$all['notify_email_default']  = get_option( 'admin_email' );
		return $all;
	}

	/**
	 * Validate and save from the admin UI.
	 *
	 * @param array $input Raw input.
	 * @return array Saved settings for UI.
	 */
	public static function update( array $input ) {
		$current = self::all();
		$d       = self::defaults();
		$out     = $current;

		$ints = array(
			'log_retention_days' => array( 1, 3650 ),
			'rate_limit'         => array( 1, 100000 ),
			'max_payload_kb'     => array( 16, 20480 ),
			'default_retries'    => array( 0, 10 ),
		);
		foreach ( $ints as $k => $range ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = max( $range[0], min( $range[1], (int) $input[ $k ] ) );
			}
		}
		foreach ( array( 'log_filtered', 'mask_sensitive', 'allow_internal_urls', 'trust_proxy', 'notify_failures', 'auto_update', 'delete_on_uninstall' ) as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = (bool) $input[ $k ];
			}
		}
		if ( isset( $input['log_level'] ) && in_array( $input['log_level'], array( 'all', 'errors', 'none' ), true ) ) {
			$out['log_level'] = $input['log_level'];
		}
		if ( isset( $input['notify_email'] ) ) {
			$out['notify_email'] = sanitize_email( $input['notify_email'] );
		}
		if ( isset( $input['meta_api_version'] ) && preg_match( '/^v\d{1,2}\.\d$/', (string) $input['meta_api_version'] ) ) {
			$out['meta_api_version'] = (string) $input['meta_api_version'];
		}
		if ( isset( $input['github_token'] ) && Connections::MASK !== $input['github_token'] ) {
			$token               = trim( sanitize_text_field( $input['github_token'] ) );
			$out['github_token'] = '' === $token ? '' : Crypto::encrypt( $token );
			delete_site_transient( Updater::CACHE_KEY );
		}

		$out = array_intersect_key( $out, $d );
		update_option( self::OPTION, $out, false );
		self::$cache = null;
		return self::for_ui();
	}
}
