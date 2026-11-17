<?php
/**
 * Encryption of secrets at rest.
 *
 * @package BRN\Webhooks
 */

namespace BRN\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Authenticated encryption (libsodium secretbox, bundled with WordPress via sodium_compat).
 *
 * The key comes from BRNWH_ENCRYPTION_KEY when defined in wp-config.php, otherwise from the
 * site's auth salts. If salts are rotated, stored credentials must be re-entered.
 */
class Crypto {

	const PREFIX = 'brnwh1:';

	/**
	 * Derive a 32-byte key.
	 *
	 * @return string
	 */
	private static function key() {
		if ( defined( 'BRNWH_ENCRYPTION_KEY' ) && BRNWH_ENCRYPTION_KEY ) {
			$material = (string) BRNWH_ENCRYPTION_KEY;
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . wp_salt( 'auth' );
		}
		return hash( 'sha256', 'brn-webhooks|' . $material, true );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
			return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Decrypt a string. Returns false when it cannot be decrypted.
	 *
	 * @param string $value Encrypted value.
	 * @return string|false
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return false;
		}
		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		try {
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
			return false === $plain ? false : $plain;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Encrypt an array as JSON.
	 *
	 * @param array $data Data.
	 * @return string
	 */
	public static function encrypt_array( array $data ) {
		return self::encrypt( wp_json_encode( $data ) );
	}

	/**
	 * Decrypt JSON array. Returns null if unreadable.
	 *
	 * @param string $value Encrypted.
	 * @return array|null
	 */
	public static function decrypt_array( $value ) {
		$plain = self::decrypt( $value );
		if ( false === $plain ) {
			return null;
		}
		if ( '' === $plain ) {
			return array();
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Random token (hex).
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	public static function token( $bytes = 20 ) {
		try {
			return bin2hex( random_bytes( $bytes ) );
		} catch ( \Throwable $e ) {
			return wp_generate_password( $bytes * 2, false, false );
		}
	}
}
