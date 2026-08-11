<?php
/**
 * Encrypt and decrypt plugin secrets stored in WordPress options.
 *
 * Protects database-backed API tokens and signing key PEMs at rest. Values are
 * wrapped in a versioned envelope so plaintext can be told apart from ciphertext.
 * The encryption key comes from CLOUDFLARE_STREAM_ENCRYPTION_KEY when set, otherwise
 * from WordPress salts. Constants for the secrets themselves remain the stronger option.
 *
 * @package cloudflare-stream
 * @since   1.1.8
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare_Stream_Secret_Store
 */
class Cloudflare_Stream_Secret_Store {

	/**
	 * Versioned prefix for ciphertext stored in options.
	 *
	 * @var string
	 */
	const ENVELOPE_PREFIX = 'cfstream_enc_v1:';

	/**
	 * AES-256-GCM cipher name.
	 *
	 * @var string
	 */
	const CIPHER = 'aes-256-gcm';

	/**
	 * GCM IV length in bytes.
	 *
	 * @var int
	 */
	const IV_LENGTH = 12;

	/**
	 * GCM auth tag length in bytes.
	 *
	 * @var int
	 */
	const TAG_LENGTH = 16;

	/**
	 * Options currently being rewritten from legacy plaintext (re-entrancy guard).
	 *
	 * @var array<string,bool>
	 */
	private static $migrating = array();

	/**
	 * Last read outcome per option name: ok, missing, legacy, unreadable, unavailable.
	 *
	 * @var array<string,string>
	 */
	private static $last_status = array();

	/**
	 * Whether a stored string is a recognised ciphertext envelope.
	 *
	 * @param mixed $value Option value.
	 * @return bool
	 */
	public static function is_envelope( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::ENVELOPE_PREFIX );
	}

	/**
	 * Derive the 32-byte AES key.
	 *
	 * Prefers CLOUDFLARE_STREAM_ENCRYPTION_KEY. Accepts 64-character hex or any
	 * other string (hashed to 32 bytes). Falls back to a stable hash of wp_salt().
	 *
	 * @return string|false Raw 32-byte key, or false when material is unusable.
	 */
	public static function get_encryption_key() {
		$material = '';

		if ( defined( 'CLOUDFLARE_STREAM_ENCRYPTION_KEY' ) ) {
			$constant = constant( 'CLOUDFLARE_STREAM_ENCRYPTION_KEY' );
			if ( is_string( $constant ) ) {
				$material = trim( $constant );
			}
		}

		if ( '' === $material ) {
			if ( ! function_exists( 'wp_salt' ) ) {
				return false;
			}
			// Stable site-specific material outside wp_options.
			$material = wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|cloudflare-stream-secrets';
		}

		// Raw 32-byte key supplied as hex.
		if ( 64 === strlen( $material ) && ctype_xdigit( $material ) ) {
			$raw = @hex2bin( $material ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid hex falls through to hash.
			if ( is_string( $raw ) && 32 === strlen( $raw ) ) {
				return $raw;
			}
		}

		return hash( 'sha256', $material, true );
	}

	/**
	 * Whether OpenSSL can perform AES-256-GCM for this store.
	 *
	 * @return bool
	 */
	public static function is_crypto_available() {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		if ( ! function_exists( 'openssl_cipher_iv_length' ) ) {
			return false;
		}

		$methods = function_exists( 'openssl_get_cipher_methods' ) ? openssl_get_cipher_methods() : array();
		if ( is_array( $methods ) && ! empty( $methods ) && ! in_array( self::CIPHER, $methods, true ) ) {
			return false;
		}

		return false !== self::get_encryption_key();
	}

	/**
	 * Encrypt plaintext into a versioned envelope string.
	 *
	 * @param string $plaintext Secret to store.
	 * @return string|false Envelope string, or false on failure.
	 */
	public static function encrypt( $plaintext ) {
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return false;
		}

		if ( ! self::is_crypto_available() ) {
			return false;
		}

		$key = self::get_encryption_key();
		if ( false === $key ) {
			return false;
		}

		$iv = random_bytes( self::IV_LENGTH );
		if ( ! is_string( $iv ) || self::IV_LENGTH !== strlen( $iv ) ) {
			return false;
		}

		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );

		if ( ! is_string( $ciphertext ) || '' === $ciphertext || ! is_string( $tag ) || self::TAG_LENGTH !== strlen( $tag ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary envelope packing for option storage.
		return self::ENVELOPE_PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt an envelope to plaintext.
	 *
	 * @param string $envelope Stored envelope.
	 * @return string|false Plaintext, or false when decryption fails.
	 */
	public static function decrypt( $envelope ) {
		if ( ! self::is_envelope( $envelope ) ) {
			return false;
		}

		if ( ! self::is_crypto_available() ) {
			return false;
		}

		$key = self::get_encryption_key();
		if ( false === $key ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary envelope packing for option storage.
		$binary = base64_decode( substr( $envelope, strlen( self::ENVELOPE_PREFIX ) ), true );
		if ( ! is_string( $binary ) ) {
			return false;
		}

		$min = self::IV_LENGTH + self::TAG_LENGTH + 1;
		if ( strlen( $binary ) < $min ) {
			return false;
		}

		$iv         = substr( $binary, 0, self::IV_LENGTH );
		$tag        = substr( $binary, self::IV_LENGTH, self::TAG_LENGTH );
		$ciphertext = substr( $binary, self::IV_LENGTH + self::TAG_LENGTH );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( ! is_string( $plaintext ) ) {
			return false;
		}

		return $plaintext;
	}

	/**
	 * Last read status for an option after get_secret() or probe().
	 *
	 * @param string $option_name Option name.
	 * @return string ok|missing|legacy|unreadable|unavailable|unknown
	 */
	public static function get_last_status( $option_name ) {
		$option_name = (string) $option_name;
		return isset( self::$last_status[ $option_name ] ) ? self::$last_status[ $option_name ] : 'unknown';
	}

	/**
	 * Inspect a stored option without migrating it.
	 *
	 * @param string $option_name Option name.
	 * @return string missing|ok|legacy|unreadable|unavailable
	 */
	public static function probe( $option_name ) {
		$raw = get_option( $option_name, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			self::$last_status[ $option_name ] = 'missing';
			return 'missing';
		}

		if ( ! self::is_envelope( $raw ) ) {
			self::$last_status[ $option_name ] = 'legacy';
			return 'legacy';
		}

		if ( ! self::is_crypto_available() ) {
			self::$last_status[ $option_name ] = 'unavailable';
			return 'unavailable';
		}

		$plain = self::decrypt( $raw );
		if ( false === $plain ) {
			self::$last_status[ $option_name ] = 'unreadable';
			return 'unreadable';
		}

		self::$last_status[ $option_name ] = 'ok';
		return 'ok';
	}

	/**
	 * Whether the options table holds a non-empty value (ciphertext counts).
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	public static function has_stored_value( $option_name ) {
		$raw = get_option( $option_name, '' );
		return is_string( $raw ) && '' !== trim( $raw );
	}

	/**
	 * Read a secret option: decrypt envelopes, migrate legacy plaintext once.
	 *
	 * @param string $option_name Option name.
	 * @return string Plaintext secret, or empty string when missing or unreadable.
	 */
	public static function get_secret( $option_name ) {
		$option_name = (string) $option_name;
		$raw         = get_option( $option_name, '' );

		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			self::$last_status[ $option_name ] = 'missing';
			return '';
		}

		if ( self::is_envelope( $raw ) ) {
			if ( ! self::is_crypto_available() ) {
				self::$last_status[ $option_name ] = 'unavailable';
				return '';
			}

			$plain = self::decrypt( $raw );
			if ( false === $plain ) {
				self::$last_status[ $option_name ] = 'unreadable';
				return '';
			}

			self::$last_status[ $option_name ] = 'ok';
			return $plain;
		}

		// Legacy plaintext row: return it and rewrite as ciphertext when possible.
		$plain                             = $raw;
		self::$last_status[ $option_name ] = 'legacy';

		if ( ! isset( self::$migrating[ $option_name ] ) && self::is_crypto_available() ) {
			self::$migrating[ $option_name ] = true;
			self::update_secret( $option_name, $plain );
			unset( self::$migrating[ $option_name ] );
			// Status stays legacy for this read; next probe sees ok.
		}

		return $plain;
	}

	/**
	 * Encrypt and store a secret option. Empty plaintext deletes the option.
	 *
	 * @param string $option_name Option name.
	 * @param string $plaintext   Secret plaintext.
	 * @return bool True when the option write (or delete) succeeded.
	 */
	public static function update_secret( $option_name, $plaintext ) {
		$option_name = (string) $option_name;
		$plaintext   = is_string( $plaintext ) ? $plaintext : '';

		if ( '' === $plaintext ) {
			return delete_option( $option_name );
		}

		// Already an envelope (e.g. sanitize passed the stored value through).
		if ( self::is_envelope( $plaintext ) ) {
			return update_option( $option_name, $plaintext, false );
		}

		if ( ! self::is_crypto_available() ) {
			return false;
		}

		$envelope = self::encrypt( $plaintext );
		if ( false === $envelope ) {
			return false;
		}

		return update_option( $option_name, $envelope, false );
	}

	/**
	 * Ensure a value about to be written is stored as an envelope.
	 *
	 * Used from pre_update_option filters. Leaves envelopes unchanged. Refuses
	 * to persist fresh plaintext when encryption is unavailable.
	 *
	 * @param mixed $value     Proposed option value.
	 * @param mixed $old_value Existing option value.
	 * @return mixed Value to store.
	 */
	public static function prepare_for_storage( $value, $old_value ) {
		if ( ! is_string( $value ) ) {
			return $old_value;
		}

		if ( '' === trim( $value ) ) {
			return $value;
		}

		if ( self::is_envelope( $value ) ) {
			return $value;
		}

		if ( ! self::is_crypto_available() ) {
			// Fail closed: keep the previous stored value rather than writing plaintext.
			return $old_value;
		}

		$envelope = self::encrypt( $value );
		return false !== $envelope ? $envelope : $old_value;
	}
}
