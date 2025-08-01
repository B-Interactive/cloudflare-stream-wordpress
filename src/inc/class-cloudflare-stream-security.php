<?php
/**
 * Cloudflare Stream Security
 *
 * Handles secure storage of API credentials with optional support for defining them in wp-config.php.
 * Provides migration from legacy plaintext storage to encrypted storage.
 *
 * @package cloudflare-stream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cloudflare_Stream_Security {

	/**
	 * Encrypt a string for secure storage.
	 *
	 * @param string $plain_string Plaintext string.
	 * @return string Encrypted string.
	 */
	public static function encrypt_string( $plain_string ) {
		if ( empty( $plain_string ) ) {
			return '';
		}
		$key            = wp_salt( 'auth' );
		$ivlen          = openssl_cipher_iv_length( 'AES-256-CBC' );
		$iv             = openssl_random_pseudo_bytes( $ivlen );
		$ciphertext_raw = openssl_encrypt( $plain_string, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		$hmac           = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
		return base64_encode( $iv . $hmac . $ciphertext_raw );
	}

	/**
	 * Decrypt a string from secure storage.
	 *
	 * @param string $encrypted_string Encrypted string.
	 * @return string|false Plaintext string or false on failure.
	 */
	public static function decrypt_string( $encrypted_string ) {
		if ( empty( $encrypted_string ) ) {
			return false;
		}
		$key                = wp_salt( 'auth' );
		$c                  = base64_decode( $encrypted_string );
		$ivlen              = openssl_cipher_iv_length( 'AES-256-CBC' );
		$iv                 = substr( $c, 0, $ivlen );
		$hmac               = substr( $c, $ivlen, 32 );
		$ciphertext_raw     = substr( $c, $ivlen + 32 );
		$original_plaintext = openssl_decrypt( $ciphertext_raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		$calcmac            = hash_hmac( 'sha256', $ciphertext_raw, $key, true );
		if ( hash_equals( $hmac, $calcmac ) ) {
			return $original_plaintext;
		}
		return false;
	}

	/**
	 * Save credentials securely (encrypts them).
	 *
	 * @param string $account_id Account ID.
	 * @param string $api_token  API Token.
	 */
	public static function save_credentials( $account_id, $api_token ) {
		update_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT, self::encrypt_string( $account_id ) );
		update_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN, self::encrypt_string( $api_token ) );
	}

	/**
	 * Retrieve credentials from the database and decrypt.
	 *
	 * @return array|false Array with 'account_id' and 'api_token', or false if not set.
	 */
	public static function get_credentials_from_db() {
		$encrypted_account_id = get_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT );
		$encrypted_api_token  = get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		if ( $encrypted_account_id && $encrypted_api_token ) {
			$account_id = self::decrypt_string( $encrypted_account_id );
			$api_token  = self::decrypt_string( $encrypted_api_token );
			if ( $account_id && $api_token ) {
				return array(
					'account_id' => $account_id,
					'api_token'  => $api_token,
				);
			}
		}
		return false;
	}

	/**
	 * Retrieve credentials from constants (wp-config.php) if set.
	 *
	 * @return array|false Array with 'account_id' and 'api_token', or false if not set as constants.
	 */
	public static function get_credentials_from_constants() {
		if ( defined( 'CLOUDFLARE_STREAM_ACCOUNT_ID' ) && defined( 'CLOUDFLARE_STREAM_API_TOKEN' ) ) {
			return array(
				'account_id' => CLOUDFLARE_STREAM_ACCOUNT_ID,
				'api_token'  => CLOUDFLARE_STREAM_API_TOKEN,
			);
		}
		return false;
	}

	/**
	 * Retrieve credentials from constants or fallback to the database.
	 *
	 * @return array|false Array with 'account_id' and 'api_token', or false if not set.
	 */
	public static function get_api_credentials() {
		$credentials = self::get_credentials_from_constants();
		if ( ! $credentials ) {
			$credentials = self::get_credentials_from_db();
		}
		return $credentials;
	}

	/**
	 * Migrate legacy plaintext credentials to encrypted storage if detected.
	 */
	public static function maybe_migrate_credentials() {
		$current = get_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT );
		// Check for legacy (plaintext) value (not base64, not encrypted).
		if ( $current && base64_encode( base64_decode( $current, true ) ) !== $current ) {
			$account_id = $current;
			$api_token  = get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
			// Only migrate if both exist and are plaintext.
			if ( $account_id && $api_token && base64_encode( base64_decode( $api_token, true ) ) !== $api_token ) {
				self::save_credentials( $account_id, $api_token );
			}
		}
	}
}
