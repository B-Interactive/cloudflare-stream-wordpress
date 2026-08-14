<?php
/**
 * Cloudflare Stream Settings class
 *
 * Methods for interacting with the WordPress Settings API.
 *
 * @package cloudflare-stream
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare_Stream_Settings
 */
class Cloudflare_Stream_Settings {

	/**
	 * Define and register singleton
	 *
	 * @var $instance The singleton instance of the class.
	 */
	private static $instance = false;

	/**
	 * Cached result of the API credential test for this request.
	 *
	 * @var bool|null
	 */
	private static $api_keys_work = null;

	/**
	 * In-request copy of a consumed signing-key reveal payload.
	 *
	 * The transient is single-use; this keeps the panel and external forms aligned
	 * for the rest of the same settings page render.
	 *
	 * @var array{id:string,pem:string,context:string}|null|false
	 */
	private $signing_key_reveal_for_request = false;

	const NONCE                       = 'cloudflare-stream';
	const SETTING_PAGE                = 'cloudflare-stream';
	const SETTING_GROUP               = 'cloudflare_stream';
	const SETTING_SECTION_GENERAL     = 'cloudflare_stream_settings_general';
	const SETTING_SECTION_PLAYER      = 'cloudflare_stream_settings_player';
	const SETTING_SECTION_REPORTING   = 'cloudflare_stream_settings_reporting';
	const OPTION_API_TOKEN            = 'cloudflare_stream_api_token';
	const OPTION_API_ZONE_ID          = 'cloudflare_stream_api_zone_id'; // Deprecated.
	const OPTION_API_KEY              = 'cloudflare_stream_api_key';
	const OPTION_API_EMAIL            = 'cloudflare_stream_api_email';
	const OPTION_API_ACCOUNT          = 'cloudflare_stream_api_account';
	const OPTION_SIGNED_URLS          = 'cloudflare_stream_signed_urls';
	const OPTION_SIGNED_URLS_DURATION = 'cloudflare_stream_signed_urls_duration';
	const OPTION_SIGNING_KEY_ID       = 'cloudflare_stream_signing_key_id';
	const OPTION_SIGNING_KEY_PEM      = 'cloudflare_stream_signing_key_pem';
	const OPTION_MEDIA_DOMAIN         = 'cloudflare_stream_media_domain';
	const OPTION_POSTER_TIME          = 'cloudflare_stream_poster_time';
	// Standard iframe hosts (iframe.{domain}). Asset URLs always use videodelivery.net for these.
	const STANDARD_MEDIA_DOMAINS         = array( 'cloudflarestream.com', 'videodelivery.net' );
	const ADMIN_ACTION_SIGNING_KEY       = 'cloudflare_stream_signing_key';
	const SIGNING_KEY_FORM_ID            = 'cloudflare-stream-signing-key-form';
	const TRANSIENT_SIGNING_KEY_REVEAL   = 'cfstream_sk_reveal_';
	const TRANSIENT_SIGNING_KEY_NOTICE   = 'cfstream_sk_notice_';
	const TRANSIENT_PENDING_NEW_KEY      = 'cfstream_sk_pending_';
	const TRANSIENT_SECRETS_AUTO_CLEAN   = 'cfstream_secrets_auto_clean_';
	const TRANSIENT_SECRET_STORAGE_ERROR = 'cfstream_secret_storage_';
	const DEFAULT_SIGNED_URLS            = true;
	const DEFAULT_SIGNED_URLS_DURATION   = 60;
	const DEFAULT_POSTER_TIME            = 0;
	// Reveal lifetime in seconds for a freshly issued private key (copy into wp-config.php).
	const SIGNING_KEY_REVEAL_TTL = 300;

	/**
	 * Singleton
	 *
	 * @since 1.0.0
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() { }

	/**
	 * Register stylesheets for admin area.
	 *
	 * @param array $hook Additional API arguments.
	 * @since 1.0.9
	 */
	public function cloudflare_stream_admin_enqueue_styles( $hook ): void {
		if ( 'settings_page_cloudflare-stream' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cloudflare-stream', plugin_dir_url( __DIR__ ) . 'css/cloudflare-stream-admin.css', array(), '1.0.9' );
	}

	/**
	 * Setup Hooks
	 *
	 * @since 1.0.0
	 */
	public function setup() {
		$this->maybe_seed_defaults();

		// Multisite registers under network admin (practical gate: manage_network).
		// Single site uses the normal settings menu (gate: manage_options).
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'action_admin_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'cloudflare_stream_admin_enqueue_styles' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION_SIGNING_KEY, array( $this, 'handle_signing_key_action' ) );
		// Drop leftover DB secrets when matching PHP constants are the live source.
		add_action( 'load-settings_page_cloudflare-stream', array( $this, 'maybe_auto_clean_constant_secrets' ) );
		// Clear signing breakers when signed URL options or key material change.
		$clear_opts = array(
			self::OPTION_SIGNED_URLS,
			self::OPTION_SIGNED_URLS_DURATION,
			self::OPTION_SIGNING_KEY_ID,
			self::OPTION_SIGNING_KEY_PEM,
		);
		foreach ( $clear_opts as $opt ) {
			foreach ( array( 'update_option_', 'add_option_', 'delete_option_' ) as $prefix ) {
				add_action( $prefix . $opt, array( $this, 'clear_signing_health_hot_state' ), 10, 0 );
			}
		}

		// Encrypt API token and signing PEM on every option write, not only in admin.
		add_filter( 'pre_update_option_' . self::OPTION_API_TOKEN, array( $this, 'pre_update_encrypt_secret' ), 20, 3 );
		add_filter( 'pre_update_option_' . self::OPTION_SIGNING_KEY_PEM, array( $this, 'pre_update_encrypt_secret' ), 20, 3 );
		add_filter( 'pre_add_option_' . self::OPTION_API_TOKEN, array( $this, 'pre_add_encrypt_secret' ), 20, 2 );
		add_filter( 'pre_add_option_' . self::OPTION_SIGNING_KEY_PEM, array( $this, 'pre_add_encrypt_secret' ), 20, 2 );
	}

	/**
	 * Seed option defaults without depending on the current user.
	 *
	 * The add_option() method only writes when the row is missing, so existing values stay put.
	 *
	 * @return void
	 */
	public function maybe_seed_defaults() {
		add_option( self::OPTION_SIGNED_URLS, self::DEFAULT_SIGNED_URLS );
		add_option( self::OPTION_SIGNED_URLS_DURATION, self::DEFAULT_SIGNED_URLS_DURATION );
		add_option( self::OPTION_MEDIA_DOMAIN, self::STANDARD_MEDIA_DOMAINS[0] );
		add_option( self::OPTION_POSTER_TIME, self::DEFAULT_POSTER_TIME );
	}

	/**
	 * Drop signing breakers and cached API tokens so a key change is picked up immediately.
	 *
	 * @return void
	 */
	public function clear_signing_health_hot_state() {
		if ( class_exists( 'Cloudflare_Stream_Signing_Health' ) ) {
			Cloudflare_Stream_Signing_Health::instance()->clear_breakers();
		}

		$this->clear_signed_token_transients();
	}

	/**
	 * Delete cached signed playback tokens from the options table.
	 *
	 * @return void
	 */
	private function clear_signed_token_transients() {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk transient cleanup by known prefix.
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cfstream_signed_token_%' OR option_name LIKE '_transient_timeout_cfstream_signed_token_%'"
		);

		if ( is_multisite() && ! empty( $wpdb->sitemeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_cfstream_signed_token_%' OR meta_key LIKE '_site_transient_timeout_cfstream_signed_token_%'"
			);
		}

		// Token rows are transient-named; flush so object-cache backends drop them after a key change.
		wp_cache_flush();
	}

	/**
	 * Setup the Admin.
	 *
	 * @uses register_setting, add_settings_section, add_settings_field
	 * @action admin_init
	 */
	public function action_admin_init() {

		// Register Settings.
		register_setting(
			self::SETTING_GROUP,
			self::OPTION_API_ACCOUNT,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_account' ),
			)
		);
		register_setting(
			self::SETTING_GROUP,
			self::OPTION_API_TOKEN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_api_token' ),
			)
		);
		register_setting(
			self::SETTING_GROUP,
			self::OPTION_SIGNED_URLS,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_signed_urls' ),
			)
		);
		register_setting(
			self::SETTING_GROUP,
			self::OPTION_SIGNED_URLS_DURATION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_signed_urls_duration' ),
			)
		);
		register_setting(
			self::SETTING_GROUP,
			self::OPTION_MEDIA_DOMAIN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_media_domain' ),
			)
		);
		register_setting(
			self::SETTING_GROUP,
			self::OPTION_POSTER_TIME,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			)
		);

		add_settings_section(
			self::SETTING_SECTION_GENERAL,
			esc_html__( 'API Configuration', 'cloudflare-stream' ),
			array( $this, 'settings_section_api_keys' ),
			self::SETTING_PAGE
		);

			add_settings_field(
				self::OPTION_API_ACCOUNT,
				esc_html__( 'API Account ID', 'cloudflare-stream' ),
				array( $this, 'api_account_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_API_TOKEN,
				esc_html__( 'API Token', 'cloudflare-stream' ),
				array( $this, 'api_token_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				'signing_key',
				esc_html__( 'Signing Key', 'cloudflare-stream' ),
				array( $this, 'signing_key_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_SIGNED_URLS,
				esc_html__( 'Use Signed URLs', 'cloudflare-stream' ),
				array( $this, 'api_signed_urls_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_SIGNED_URLS_DURATION,
				esc_html__( 'Signed URL Expiration', 'cloudflare-stream' ),
				array( $this, 'api_signed_urls_duration_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_MEDIA_DOMAIN,
				esc_html__( 'Preferred Media Domain', 'cloudflare-stream' ),
				array( $this, 'media_domain_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

		add_settings_section(
			self::SETTING_SECTION_PLAYER,
			esc_html__( 'Player Settings', 'cloudflare-stream' ),
			array( $this, 'settings_section_player' ),
			self::SETTING_PAGE
		);

			add_settings_field(
				self::OPTION_POSTER_TIME,
				esc_html__( 'Thumbnail Time', 'cloudflare-stream' ),
				array( $this, 'poster_time_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_PLAYER
			);

		add_action( 'admin_notices', array( $this, 'settings_errors_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'onboarding_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'signing_key_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'signing_health_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'secrets_auto_clean_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'secret_storage_admin_notices' ) );

		// When a constant is active, do not rewrite the matching option from the settings form.
		add_filter( 'pre_update_option_' . self::OPTION_API_TOKEN, array( $this, 'pre_update_api_token' ), 10, 2 );
		add_filter( 'pre_update_option_' . self::OPTION_API_ACCOUNT, array( $this, 'pre_update_api_account' ), 10, 2 );
	}

	/**
	 * User-specific transient for the one-time auto-clean notice.
	 *
	 * @return string
	 */
	private function secrets_auto_clean_transient_name() {
		return self::TRANSIENT_SECRETS_AUTO_CLEAN . get_current_user_id();
	}

	/**
	 * On settings page load, delete DB copies of secrets that PHP constants already supply.
	 *
	 * Deletes by option name only. Ciphertext envelopes are removed without decrypting.
	 */
	public function maybe_auto_clean_constant_secrets() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$removed = array();

		if ( self::api_token_from_constant() && self::db_has_api_token_option() ) {
			delete_option( self::OPTION_API_TOKEN );
			$removed[] = __( 'API token', 'cloudflare-stream' );
		}

		if ( self::api_account_from_constant() && self::db_has_api_account_option() ) {
			delete_option( self::OPTION_API_ACCOUNT );
			$removed[] = __( 'API account ID', 'cloudflare-stream' );
		}

		// Only when both signing constants are fully usable (same bar as live constant signing).
		// Drop any leftover id and/or PEM row, including unreadable envelopes.
		if ( $this->constants_signing_key_ready() && $this->db_has_any_signing_key_option() ) {
			$this->delete_db_signing_key_options();
			$removed[] = __( 'signing key', 'cloudflare-stream' );
		}

		if ( empty( $removed ) ) {
			return;
		}

		set_transient( $this->secrets_auto_clean_transient_name(), $removed, HOUR_IN_SECONDS );
	}

	/**
	 * One-time success notice after auto-clean removed leftover DB secrets.
	 */
	public function secrets_auto_clean_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- settings page screen check only.
		if ( empty( $_GET['page'] ) || 'cloudflare-stream' !== $_GET['page'] ) {
			return;
		}

		$removed = get_transient( $this->secrets_auto_clean_transient_name() );
		if ( ! is_array( $removed ) || empty( $removed ) ) {
			return;
		}

		delete_transient( $this->secrets_auto_clean_transient_name() );

		$labels = array();
		foreach ( $removed as $label ) {
			if ( is_string( $label ) && '' !== $label ) {
				$labels[] = $label;
			}
		}

		if ( empty( $labels ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of removed items (not secret values) */
					__( 'These are now read from PHP constants, so the database copy was removed: %s.', 'cloudflare-stream' ),
					implode( ', ', $labels )
				)
			)
		);
	}

	/** API CONFIGURATION CALLBACKS **/

	/**
	 * Trimmed string value of a PHP constant, or empty string when unusable.
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	private static function constant_string( $name ) {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Whether the API token comes from a PHP constant.
	 *
	 * @return bool
	 */
	public static function api_token_from_constant() {
		return '' !== self::constant_string( 'CLOUDFLARE_STREAM_API_TOKEN' );
	}

	/**
	 * Whether the API account id comes from a PHP constant.
	 *
	 * @return bool
	 */
	public static function api_account_from_constant() {
		return '' !== self::constant_string( 'CLOUDFLARE_STREAM_API_ACCOUNT' );
	}

	/**
	 * API token: constant first, then the stored option (decrypted when needed).
	 *
	 * @return string
	 */
	public static function get_api_token() {
		$constant = self::constant_string( 'CLOUDFLARE_STREAM_API_TOKEN' );

		if ( '' !== $constant ) {
			return $constant;
		}

		return trim( Cloudflare_Stream_Secret_Store::get_secret( self::OPTION_API_TOKEN ) );
	}

	/**
	 * API account id: constant first, then the stored option.
	 *
	 * @return string
	 */
	public static function get_api_account() {
		$constant = self::constant_string( 'CLOUDFLARE_STREAM_API_ACCOUNT' );

		if ( '' !== $constant ) {
			return sanitize_text_field( $constant );
		}

		$option = get_option( self::OPTION_API_ACCOUNT, '' );

		return is_string( $option ) ? sanitize_text_field( $option ) : '';
	}

	/**
	 * Whether wp_options holds a non-empty API token (even if the constant overrides).
	 *
	 * Ciphertext counts as present; this does not decrypt.
	 *
	 * @return bool
	 */
	public static function db_has_api_token_option() {
		return Cloudflare_Stream_Secret_Store::has_stored_value( self::OPTION_API_TOKEN );
	}

	/**
	 * Whether wp_options holds a non-empty API account id (even if the constant overrides).
	 *
	 * @return bool
	 */
	public static function db_has_api_account_option() {
		$option = get_option( self::OPTION_API_ACCOUNT, '' );

		return is_string( $option ) && '' !== trim( $option );
	}

	/**
	 * Keep the stored token when the password field is left blank; ignore form when a constant is set.
	 *
	 * Returns the existing envelope when keeping the stored value so a blank save
	 * does not force a decrypt/re-encrypt cycle. Fresh tokens are plaintext here;
	 * pre_update_encrypt_secret wraps them before they hit the database.
	 *
	 * @param mixed $value Submitted option value.
	 * @return string
	 */
	public function sanitize_api_token( $value ) {
		if ( self::api_token_from_constant() ) {
			$stored = get_option( self::OPTION_API_TOKEN, '' );
			return is_string( $stored ) ? $stored : '';
		}

		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			$stored = get_option( self::OPTION_API_TOKEN, '' );
			return is_string( $stored ) ? $stored : '';
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Encrypt secret option values before they are written to the database.
	 *
	 * @param mixed  $value     Proposed value.
	 * @param mixed  $old_value Existing value.
	 * @param string $option    Option name (AAD binding).
	 * @return mixed
	 */
	public function pre_update_encrypt_secret( $value, $old_value, $option = '' ) {
		$option = $this->resolve_secret_option_name( $option, 'pre_update_option_' );
		$result = Cloudflare_Stream_Secret_Store::prepare_for_storage( $value, $old_value, $option );
		$this->maybe_queue_secret_storage_notice();
		return $result;
	}

	/**
	 * Encrypt secret option values on first insert (add_option).
	 *
	 * @param mixed  $value  Proposed value.
	 * @param string $option Option name (AAD binding).
	 * @return mixed
	 */
	public function pre_add_encrypt_secret( $value, $option = '' ) {
		$option = $this->resolve_secret_option_name( $option, 'pre_add_option_' );
		$result = Cloudflare_Stream_Secret_Store::prepare_for_storage( $value, '', $option );
		$this->maybe_queue_secret_storage_notice();
		return $result;
	}

	/**
	 * Resolve the secret option name from a filter argument or the current filter.
	 *
	 * @param string $option        Option name from the filter callback.
	 * @param string $filter_prefix Expected current_filter() prefix.
	 * @return string
	 */
	private function resolve_secret_option_name( $option, $filter_prefix ) {
		if ( is_string( $option ) && '' !== $option ) {
			return $option;
		}

		$filter = function_exists( 'current_filter' ) ? (string) current_filter() : '';
		if ( '' !== $filter_prefix && 0 === strpos( $filter, $filter_prefix ) ) {
			return substr( $filter, strlen( $filter_prefix ) );
		}

		return '';
	}

	/**
	 * User-specific transient for a refused secret write notice.
	 *
	 * @return string
	 */
	private function secret_storage_notice_transient_name() {
		return self::TRANSIENT_SECRET_STORAGE_ERROR . get_current_user_id();
	}

	/**
	 * Human label for a secret option name (never the secret value).
	 *
	 * @param string $option_name Option name.
	 * @return string
	 */
	private function secret_option_label( $option_name ) {
		if ( self::OPTION_API_TOKEN === $option_name ) {
			return __( 'API token', 'cloudflare-stream' );
		}
		if ( self::OPTION_SIGNING_KEY_PEM === $option_name ) {
			return __( 'signing key', 'cloudflare-stream' );
		}
		return __( 'secret', 'cloudflare-stream' );
	}

	/**
	 * After a refused encrypt, stash an admin notice for the current user.
	 *
	 * @return void
	 */
	private function maybe_queue_secret_storage_notice() {
		$error = Cloudflare_Stream_Secret_Store::get_last_storage_error();
		if ( ! is_array( $error ) ) {
			return;
		}

		$reason = isset( $error['reason'] ) ? (string) $error['reason'] : '';
		if ( 'crypto_unavailable' !== $reason && 'encrypt_failed' !== $reason ) {
			return;
		}

		if ( ! function_exists( 'get_current_user_id' ) || get_current_user_id() < 1 ) {
			return;
		}

		$option = isset( $error['option'] ) ? (string) $error['option'] : '';
		$mode   = isset( $error['mode'] ) ? (string) $error['mode'] : 'keep';

		// User-specific transient only (same pattern as auto-clean). Avoids a double
		// print with the Settings API settings_errors flash after options.php redirects.
		set_transient(
			$this->secret_storage_notice_transient_name(),
			array(
				'option' => $option,
				'reason' => $reason,
				'mode'   => ( 'add' === $mode ) ? 'add' : 'keep',
			),
			HOUR_IN_SECONDS
		);

		Cloudflare_Stream_Secret_Store::clear_last_storage_error();
	}

	/**
	 * Admin-facing copy when a secret could not be encrypted for storage.
	 *
	 * @param string $option_name Option name.
	 * @param string $mode        keep|add.
	 * @return string
	 */
	private function format_secret_storage_notice_text( $option_name, $mode ) {
		$label = $this->secret_option_label( $option_name );

		if ( 'add' === $mode ) {
			return sprintf(
				/* translators: %s: secret field label (not a secret value) */
				__( 'The %s could not be saved because encryption is unavailable on this server. Nothing was stored.', 'cloudflare-stream' ),
				$label
			);
		}

		return sprintf(
			/* translators: %s: secret field label (not a secret value) */
			__( 'The %s could not be saved because encryption is unavailable on this server. The previous stored value was kept.', 'cloudflare-stream' ),
			$label
		);
	}

	/**
	 * One-shot notice when a secret write was refused because encryption failed.
	 *
	 * @return void
	 */
	public function secret_storage_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw = get_transient( $this->secret_storage_notice_transient_name() );
		if ( ! is_array( $raw ) ) {
			return;
		}

		delete_transient( $this->secret_storage_notice_transient_name() );

		$option = isset( $raw['option'] ) ? (string) $raw['option'] : '';
		$mode   = isset( $raw['mode'] ) && 'add' === $raw['mode'] ? 'add' : 'keep';
		$text   = $this->format_secret_storage_notice_text( $option, $mode );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $text )
		);
	}

	/**
	 * Ignore form account id when the constant is set.
	 *
	 * @param mixed $value Submitted option value.
	 * @return string
	 */
	public function sanitize_api_account( $value ) {
		if ( self::api_account_from_constant() ) {
			$stored = get_option( self::OPTION_API_ACCOUNT, '' );
			return is_string( $stored ) ? sanitize_text_field( $stored ) : '';
		}

		return sanitize_text_field( is_string( $value ) ? $value : '' );
	}

	/**
	 * Skip option writes for the API token while the PHP constant is active.
	 *
	 * @param mixed $value     Proposed value.
	 * @param mixed $old_value Existing value.
	 * @return mixed
	 */
	public function pre_update_api_token( $value, $old_value ) {
		if ( self::api_token_from_constant() ) {
			return $old_value;
		}

		return $value;
	}

	/**
	 * Skip option writes for the API account id while the PHP constant is active.
	 *
	 * @param mixed $value     Proposed value.
	 * @param mixed $old_value Existing value.
	 * @return mixed
	 */
	public function pre_update_api_account( $value, $old_value ) {
		if ( self::api_account_from_constant() ) {
			return $old_value;
		}

		return $value;
	}

	/**
	 * Plain words for where a value is kept.
	 *
	 * @param bool $from_const Value comes from PHP constant.
	 * @param bool $in_db      Value is stored in the database.
	 * @return string
	 */
	private function storage_status_text( $from_const, $in_db ) {
		if ( $from_const ) {
			return __( 'Stored as PHP constants', 'cloudflare-stream' );
		}

		if ( $in_db ) {
			return __( 'Stored in the database', 'cloudflare-stream' );
		}

		return __( 'Not set', 'cloudflare-stream' );
	}

	/**
	 * One-line status above a settings field.
	 *
	 * @param string $status Short status text.
	 */
	private function echo_field_status( $status ) {
		echo '<p class="cloudflare-stream-field-status"><strong>' . esc_html__( 'Status:', 'cloudflare-stream' ) . '</strong> '
			. esc_html( $status ) . '</p>';
	}

	/**
	 * Callback for rendering the API Account ID settings field
	 */
	public function api_account_cb() {
		$from_const = self::api_account_from_constant();

		$this->echo_field_status( $this->storage_status_text( $from_const, self::db_has_api_account_option() ) );

		if ( $from_const ) {
			echo '<input type="text" class="regular-text" value="' . esc_attr( self::get_api_account() ) . '" disabled="disabled" autocomplete="off">';
			echo '<p class="description">' . esc_html__( 'Set by CLOUDFLARE_STREAM_API_ACCOUNT in wp-config.php / PHP constants.', 'cloudflare-stream' ) . '</p>';
			return;
		}

		$api_account = self::get_api_account();

		if ( '' === $api_account ) {
			// Older installs can still resolve the account ID from a stored zone ID.
			$legacy      = $this->get_account_id();
			$api_account = is_string( $legacy ) ? $legacy : '';
		}

		echo '<input type="text" class="regular-text" name="cloudflare_stream_api_account" id="cloudflare_stream_api_account" value="' . esc_attr( $api_account ) . '" autocomplete="on">';
		echo '<p class="description">' . esc_html__( 'In Cloudflare, open your domain, go to Overview, then copy the Account ID from the API panel on the right.', 'cloudflare-stream' ) . '</p>';
	}

	/**
	 * Callback for rendering the API Token settings field
	 */
	public function api_token_cb() {
		$from_const = self::api_token_from_constant();
		$in_db      = self::db_has_api_token_option();

		$this->echo_field_status( $this->storage_status_text( $from_const, $in_db ) );

		if ( $from_const ) {
			echo '<input type="password" class="regular-text" value="********" disabled="disabled" autocomplete="off">';
			echo '<p class="description">' . esc_html__( 'Set by CLOUDFLARE_STREAM_API_TOKEN in wp-config.php / PHP constants.', 'cloudflare-stream' ) . '</p>';
			return;
		}

		// Blank on purpose: saving an empty field keeps the stored token (see sanitize_api_token).
		echo '<input type="password" class="regular-text" name="cloudflare_stream_api_token" id="cloudflare_stream_api_token" value="" autocomplete="off">';

		if ( $in_db ) {
			echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved token, or enter a new token to replace it.', 'cloudflare-stream' ) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'In Cloudflare, go to My Profile, API Tokens, then Create Token. It needs the Account - Stream:Edit permission.', 'cloudflare-stream' ) . '</p>';
	}

	/**
	 * Callback for rendering the use signed URLs field
	 */
	public function api_signed_urls_cb() {
		$signed_urls = get_option( self::OPTION_SIGNED_URLS, self::DEFAULT_SIGNED_URLS );

		$origin_hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$origin_hosts[] = $host;
			}
		}
		$origin_hosts = array_values( array_unique( $origin_hosts ) );
		$origins_label = ! empty( $origin_hosts ) ? implode( ', ', $origin_hosts ) : 'your-site-host';

		echo '<label><input type="checkbox" class="regular-text" name="cloudflare_stream_signed_urls" id="cloudflare_stream_signed_urls" value="1"' . checked( $signed_urls, true, false ) . '>' . esc_html__( 'Protects video links from being copied, by creating a unique temporary URL.', 'cloudflare-stream' ) . '</label>'
		. '<small class="form-text text-muted">' . esc_html__( 'New uploads will require signed URLs and allow this site as an embed origin', 'cloudflare-stream' ) . ' (' . esc_html( $origins_label ) . '). '
		. esc_html__( 'Existing videos are unchanged; update them in the Cloudflare Stream dashboard if needed.', 'cloudflare-stream' ) . '</small>';
	}

	/**
	 * Callback for rendering the signed URLs duration field
	 */
	public function api_signed_urls_duration_cb() {
		$signed_urls_duration = get_option( self::OPTION_SIGNED_URLS_DURATION, self::DEFAULT_SIGNED_URLS_DURATION );
		echo '<label for="cloudflare_stream_signed_urls_duration"><input type="number" class="regular-text" name="cloudflare_stream_signed_urls_duration" id="cloudflare_stream_signed_urls_duration" min="1" max="1440" value="' . esc_attr( intval( $signed_urls_duration ) ) . '" autocomplete="off"> minutes</label>'
		. '<small class="form-text text-muted">' . esc_html__( 'Sets how long the unique signed URL/token remains accessible for, in minutes (1 to 1440).', 'cloudflare-stream' ) . '</small>';
	}

	/**
	 * Normalize the signed URLs checkbox to a boolean on save.
	 *
	 * @param mixed $value Submitted option value.
	 * @return bool
	 */
	public function sanitize_signed_urls( $value ) {
		return (bool) $value;
	}

	/**
	 * Clamp signed URL duration to 1..1440 minutes on save.
	 *
	 * @param mixed $value Submitted option value.
	 * @return int
	 */
	public function sanitize_signed_urls_duration( $value ) {
		$minutes = intval( $value );

		if ( $minutes < 1 ) {
			return 1;
		}

		if ( $minutes > 1440 ) {
			return 1440;
		}

		return $minutes;
	}

	/**
	 * Sanitize preferred media domain (standard hosts or customer subdomain).
	 *
	 * @param mixed $value Submitted option value.
	 * @return string
	 */
	public function sanitize_media_domain( $value ) {
		$value = sanitize_text_field( is_string( $value ) ? $value : '' );

		if ( '' === $value ) {
			return self::STANDARD_MEDIA_DOMAINS[0];
		}

		if ( in_array( $value, self::STANDARD_MEDIA_DOMAINS, true ) ) {
			return $value;
		}

		// Customer subdomain option from the API (hostname only).
		$value = strtolower( $value );
		$value = preg_replace( '#^https?://#', '', $value );
		$value = preg_replace( '#/.*$#', '', $value );
		$value = sanitize_text_field( $value );

		if ( '' === $value || false !== strpos( $value, ' ' ) ) {
			return self::STANDARD_MEDIA_DOMAINS[0];
		}

		return $value;
	}

	/**
	 * Whether PHP constants are the source of the signing key.
	 *
	 * @return bool
	 */
	private function signing_key_from_constants() {
		return Cloudflare_Stream_API::instance()->signing_key_constants_present();
	}

	/**
	 * User-specific transient name for a one-time signing key reveal.
	 *
	 * @return string
	 */
	private function signing_key_reveal_transient_name() {
		return self::TRANSIENT_SIGNING_KEY_REVEAL . get_current_user_id();
	}

	/**
	 * User-specific transient holding a newly minted key id until setup finishes.
	 *
	 * Used to revoke an abandoned key that was never stored.
	 *
	 * @return string
	 */
	private function pending_new_signing_key_transient_name() {
		return self::TRANSIENT_PENDING_NEW_KEY . get_current_user_id();
	}

	/**
	 * Remember a freshly minted key id so abandon cleanup can revoke it.
	 *
	 * @param string $key_id Signing key id.
	 * @return void
	 */
	private function stash_pending_new_signing_key_id( $key_id ) {
		$key_id = sanitize_text_field( (string) $key_id );
		if ( '' === $key_id ) {
			return;
		}

		set_transient(
			$this->pending_new_signing_key_transient_name(),
			$key_id,
			self::SIGNING_KEY_REVEAL_TTL
		);
	}

	/**
	 * Drop the pending new-key id without revoking it (successful store or confirm).
	 *
	 * @return void
	 */
	private function clear_pending_new_signing_key_id() {
		delete_transient( $this->pending_new_signing_key_transient_name() );
	}

	/**
	 * Stash key id + PEM for short-lived setup (user must choose how to keep it).
	 *
	 * @param string $key_id  Signing key id.
	 * @param string $pem     PEM as returned (base64 or PEM text).
	 * @param string $context setup|rotate.
	 */
	private function stash_signing_key_reveal( $key_id, $pem, $context = 'setup' ) {
		$context = ( 'rotate' === $context ) ? 'rotate' : 'setup';

		set_transient(
			$this->signing_key_reveal_transient_name(),
			array(
				'id'      => (string) $key_id,
				'pem'     => (string) $pem,
				'context' => $context,
				'shown'   => false,
			),
			self::SIGNING_KEY_REVEAL_TTL
		);

		$this->signing_key_reveal_for_request = false;
		$this->stash_pending_new_signing_key_id( $key_id );
	}

	/**
	 * Normalise a reveal transient payload, or null when unusable.
	 *
	 * @param mixed $payload Transient value.
	 * @return array{id:string,pem:string,context:string,shown:bool}|null
	 */
	private function normalize_signing_key_reveal_payload( $payload ) {
		if ( ! is_array( $payload ) ) {
			return null;
		}

		$id  = isset( $payload['id'] ) ? (string) $payload['id'] : '';
		$pem = isset( $payload['pem'] ) ? (string) $payload['pem'] : '';

		if ( '' === $id || '' === $pem ) {
			return null;
		}

		$context = isset( $payload['context'] ) ? (string) $payload['context'] : 'setup';
		if ( 'rotate' !== $context && 'migrate' !== $context ) {
			$context = 'setup';
		}
		// Older migrate context is treated as rotate.
		if ( 'migrate' === $context ) {
			$context = 'rotate';
		}

		return array(
			'id'      => $id,
			'pem'     => $pem,
			'context' => $context,
			'shown'   => ! empty( $payload['shown'] ),
		);
	}

	/**
	 * Read pending setup/rotate material for handlers (does not mark the PEM as shown).
	 *
	 * @return array{id:string,pem:string,context:string,shown:bool}|null
	 */
	private function get_signing_key_reveal() {
		if ( false !== $this->signing_key_reveal_for_request ) {
			return $this->signing_key_reveal_for_request;
		}

		$payload = $this->normalize_signing_key_reveal_payload(
			get_transient( $this->signing_key_reveal_transient_name() )
		);

		$this->signing_key_reveal_for_request = $payload;

		return $payload;
	}

	/**
	 * Payload for on-screen reveal. Marks the transient shown after the first read
	 * so a later page view cannot print the private key again. Server-side material
	 * stays available for store/confirm until cleared or expired.
	 *
	 * @return array{id:string,pem:string,context:string}|null Null when nothing to show.
	 */
	private function get_signing_key_reveal_for_display() {
		$payload = $this->get_signing_key_reveal();
		if ( null === $payload ) {
			return null;
		}

		if ( ! empty( $payload['shown'] ) ) {
			// Already printed once; keep actions available without the PEM.
			return array(
				'id'      => $payload['id'],
				'pem'     => '',
				'context' => $payload['context'],
				'shown'   => true,
			);
		}

		$stored = array(
			'id'      => $payload['id'],
			'pem'     => $payload['pem'],
			'context' => $payload['context'],
			'shown'   => true,
		);
		set_transient(
			$this->signing_key_reveal_transient_name(),
			$stored,
			self::SIGNING_KEY_REVEAL_TTL
		);
		$this->signing_key_reveal_for_request = $stored;

		return array(
			'id'      => $payload['id'],
			'pem'     => $payload['pem'],
			'context' => $payload['context'],
			'shown'   => false,
		);
	}

	/**
	 * Drop the user-specific setup/rotate reveal transient.
	 *
	 * @param bool $revoke_pending When true, revoke an unused newly minted key at Cloudflare.
	 */
	private function clear_signing_key_reveal( $revoke_pending = false ) {
		delete_transient( $this->signing_key_reveal_transient_name() );
		$this->signing_key_reveal_for_request = null;

		if ( $revoke_pending ) {
			$this->revoke_pending_new_signing_key();
		} else {
			$this->clear_pending_new_signing_key_id();
		}
	}

	/**
	 * Revoke a newly minted key that was never stored (abandoned setup or rotate).
	 *
	 * @return void
	 */
	private function revoke_pending_new_signing_key() {
		$key_id = get_transient( $this->pending_new_signing_key_transient_name() );
		$this->clear_pending_new_signing_key_id();

		if ( ! is_string( $key_id ) || '' === $key_id ) {
			return;
		}

		// Best effort: an unused key left at Cloudflare is wasteful but not a leak of stored secrets.
		Cloudflare_Stream_API::instance()->delete_signing_key( $key_id );
	}

	/**
	 * Database signing key id only (ignores constants).
	 *
	 * Stored in plaintext on purpose: the id is not secret, and keeping it readable
	 * lets an admin revoke the key at Cloudflare when the PEM envelope cannot be decrypted.
	 *
	 * @return string
	 */
	private function get_db_signing_key_id() {
		$id = get_option( self::OPTION_SIGNING_KEY_ID, '' );

		return is_string( $id ) ? sanitize_text_field( $id ) : '';
	}

	/**
	 * Revoke a signing key at Cloudflare and report whether it succeeded.
	 *
	 * @param string $key_id Signing key id.
	 * @return bool True when Cloudflare accepted the revoke, false otherwise.
	 */
	private function revoke_signing_key_at_cloudflare( $key_id ) {
		$key_id = sanitize_text_field( (string) $key_id );
		if ( '' === $key_id ) {
			return false;
		}

		return (bool) Cloudflare_Stream_API::instance()->delete_signing_key( $key_id );
	}

	/**
	 * Whether wp_options holds a signing key id and PEM (even if constants override).
	 *
	 * Ciphertext counts as present; this does not decrypt the PEM.
	 *
	 * @return bool
	 */
	private function db_has_signing_key_options() {
		// Key id stays plaintext; PEM may be an envelope. Both must be non-empty rows.
		$id_ok  = Cloudflare_Stream_Secret_Store::has_stored_value( self::OPTION_SIGNING_KEY_ID );
		$pem_ok = Cloudflare_Stream_Secret_Store::has_stored_value( self::OPTION_SIGNING_KEY_PEM );

		return $id_ok && $pem_ok;
	}

	/**
	 * Whether either signing key option row is non-empty (ciphertext counts).
	 *
	 * Used when constants are authoritative so orphan id-only or PEM-only rows are purged too.
	 *
	 * @return bool
	 */
	private function db_has_any_signing_key_option() {
		return Cloudflare_Stream_Secret_Store::has_stored_value( self::OPTION_SIGNING_KEY_ID )
			|| Cloudflare_Stream_Secret_Store::has_stored_value( self::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * Remove signing key id/PEM from WordPress options by key (no decrypt).
	 */
	private function delete_db_signing_key_options() {
		delete_option( self::OPTION_SIGNING_KEY_ID );
		delete_option( self::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * Write signing key id (plaintext) and PEM (encrypted envelope) to options.
	 *
	 * @param string $key_id Signing key id.
	 * @param string $pem    PEM text or base64 PEM material.
	 * @return bool True when both rows are present after the write.
	 */
	private function store_db_signing_key( $key_id, $pem ) {
		$key_id = sanitize_text_field( (string) $key_id );
		// Same decode and PEM shape as get_signing_key_pem() / local signing.
		$pem = Cloudflare_Stream_API::instance()->normalize_signing_key_material( $pem );

		if ( '' === $key_id || '' === $pem ) {
			return false;
		}

		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			return false;
		}

		$envelope = Cloudflare_Stream_Secret_Store::encrypt( $pem, self::OPTION_SIGNING_KEY_PEM );
		if ( false === $envelope ) {
			return false;
		}

		// Id is not secret; left readable so a lost PEM can still be revoked by id.
		// update_option may return false when the value is unchanged; presence is what matters.
		update_option( self::OPTION_SIGNING_KEY_ID, $key_id, false );
		update_option( self::OPTION_SIGNING_KEY_PEM, $envelope, false );

		return Cloudflare_Stream_Secret_Store::has_stored_value( self::OPTION_SIGNING_KEY_ID )
			&& Cloudflare_Stream_Secret_Store::has_stored_value( self::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * Encode a value as a string literal for paste-ready snippets.
	 *
	 * @param string $value Raw string.
	 * @return string
	 */
	private function php_single_quoted_string( $value ) {
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
	}

	/**
	 * Ready-to-paste wp-config.php / PHP constant defines for a signing key.
	 *
	 * @param string $key_id Signing key id.
	 * @param string $pem    PEM value as stored / usable in the constant.
	 * @return string
	 */
	private function build_signing_key_wpconfig_snippet( $key_id, $pem ) {
		return 'define( \'CLOUDFLARE_STREAM_SIGNING_KEY_ID\', ' . $this->php_single_quoted_string( $key_id ) . " );\n"
			. 'define( \'CLOUDFLARE_STREAM_SIGNING_KEY_PEM\', ' . $this->php_single_quoted_string( $pem ) . ' );';
	}

	/**
	 * Stable form id for a signing key admin-post action.
	 *
	 * @param string $key_do signing_key_do value.
	 * @return string
	 */
	private function signing_key_form_id( $key_do ) {
		return self::SIGNING_KEY_FORM_ID . '-' . sanitize_key( $key_do );
	}

	/**
	 * Button that submits an external signing key form (no nested forms).
	 *
	 * @param string $key_do    signing_key_do value / form id suffix.
	 * @param string $label     Button label.
	 * @param string $type      WordPress button type (primary, secondary, delete).
	 */
	private function echo_signing_key_form_button( $key_do, $label, $type = 'secondary' ) {
		$classes = array( 'button' );
		if ( 'primary' === $type ) {
			$classes[] = 'button-primary';
		} elseif ( 'delete' === $type ) {
			$classes[] = 'button-secondary';
			$classes[] = 'button-delete';
		} else {
			$classes[] = 'button-secondary';
		}

		printf(
			'<p class="cloudflare-stream-signing-key-action"><button type="submit" class="%1$s" form="%2$s">%3$s</button></p>',
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $this->signing_key_form_id( $key_do ) ),
			esc_html( $label )
		);
	}

	/**
	 * Hidden admin-post form for one signing key action (lives outside options form).
	 *
	 * @param string $key_do signing_key_do value.
	 */
	private function echo_signing_key_external_form( $key_do ) {
		$key_do = sanitize_key( $key_do );
		printf(
			'<form method="post" action="%1$s" id="%2$s" class="cloudflare-stream-signing-key-external-form">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $this->signing_key_form_id( $key_do ) )
		);
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
		echo '<input type="hidden" name="signing_key_do" value="' . esc_attr( $key_do ) . '">';
		wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
		echo '</form>';
	}

	/**
	 * One-time setup panel UI inside the Signing Key field (buttons use form=).
	 *
	 * @param string $key_id  Signing key id.
	 * @param string $pem     PEM value.
	 * @param string $context setup|rotate.
	 */
	private function render_signing_key_setup_panel( $key_id, $pem, $context = 'setup' ) {
		$context = ( 'rotate' === $context ) ? 'rotate' : 'setup';
		$snippet = $this->build_signing_key_wpconfig_snippet( $key_id, $pem );

		echo '<div class="notice notice-warning inline cloudflare-stream-signing-key-reveal">';
		if ( 'rotate' === $context ) {
			echo '<p><strong>' . esc_html__( 'Move signing key to wp-config.php', 'cloudflare-stream' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'A new signing key was created. Copy the lines below into wp-config.php, above the line that says "That\'s all, stop editing!". They are only shown once. After you confirm, the database copy is removed and the previous key is revoked in Cloudflare.', 'cloudflare-stream' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'Signing key setup', 'cloudflare-stream' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Copy the lines below into wp-config.php, above the line that says "That\'s all, stop editing!". They are only shown once and the private key is not shown again.', 'cloudflare-stream' ) . '</p>';
		}
		echo '<textarea class="large-text code" id="cloudflare_stream_reveal_snippet" rows="8" readonly="readonly" onclick="this.select();">' . esc_textarea( $snippet ) . '</textarea>';

		if ( 'setup' === $context ) {
			$this->echo_signing_key_form_button( 'confirm_constants', __( 'I have added this to wp-config.php', 'cloudflare-stream' ), 'primary' );
			echo '<p class="description">' . esc_html__( 'Checks the lines work, then discards this temporary copy.', 'cloudflare-stream' ) . '</p>';

			$this->echo_signing_key_form_button( 'store_db', __( 'Save in the database instead', 'cloudflare-stream' ) );
			echo '<p class="description">' . esc_html__( 'Signed playback works either way. You can move the key to wp-config.php later.', 'cloudflare-stream' ) . '</p>';
		} else {
			$this->echo_signing_key_form_button( 'confirm_moved', __( 'I have added this to wp-config.php', 'cloudflare-stream' ), 'primary' );
			echo '<p class="description">' . esc_html__( 'Checks the lines work, then removes the database copy and revokes the previous key.', 'cloudflare-stream' ) . '</p>';

			$this->echo_signing_key_form_button( 'dismiss', __( 'Cancel and keep the database key', 'cloudflare-stream' ) );
			echo '<p class="description">' . esc_html__( 'Discards the new key and leaves the existing database key in place.', 'cloudflare-stream' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Callback for signing key status, setup panel, and action buttons (via form=).
	 */
	public function signing_key_cb() {
		$api          = Cloudflare_Stream_API::instance();
		$const_ready  = $this->constants_signing_key_ready();
		$const_broken = ! $const_ready && $this->signing_key_from_constants();
		$in_db        = $this->db_has_signing_key_options();
		$reveal       = $this->get_signing_key_reveal_for_display();

		if ( $const_broken ) {
			$this->echo_field_status( __( 'Set as a PHP constant, but not usable', 'cloudflare-stream' ) );
		} else {
			$this->echo_field_status( $this->storage_status_text( $const_ready, $in_db ) );
		}

		// Health panel after status; not shown for broken-constants (existing copy path).
		if ( ! $const_broken ) {
			$this->echo_signing_health_panel();
		}

		// Setup owns the next step while a short-lived copy of the key is pending.
		if ( is_array( $reveal ) ) {
			if ( '' !== $reveal['pem'] ) {
				$this->render_signing_key_setup_panel( $reveal['id'], $reveal['pem'], $reveal['context'] );
			} else {
				// PEM already shown once; keep confirm/store actions without reprinting secrets.
				echo '<div class="notice notice-warning inline cloudflare-stream-signing-key-reveal">';
				echo '<p><strong>' . esc_html__( 'Signing key setup', 'cloudflare-stream' ) . '</strong></p>';
				echo '<p>' . esc_html__( 'The private key was already shown once on this screen. Finish the step below, or cancel and start again if you need the lines reprinted.', 'cloudflare-stream' ) . '</p>';
				if ( 'rotate' === $reveal['context'] ) {
					$this->echo_signing_key_form_button( 'confirm_moved', __( 'I have added this to wp-config.php', 'cloudflare-stream' ), 'primary' );
					$this->echo_signing_key_form_button( 'dismiss', __( 'Cancel and keep the database key', 'cloudflare-stream' ) );
				} else {
					$this->echo_signing_key_form_button( 'confirm_constants', __( 'I have added this to wp-config.php', 'cloudflare-stream' ), 'primary' );
					$this->echo_signing_key_form_button( 'store_db', __( 'Save in the database instead', 'cloudflare-stream' ) );
				}
				echo '</div>';
			}
			return;
		}

		if ( $const_broken ) {
			echo '<p class="notice notice-warning inline">'
				. esc_html__( 'Both CLOUDFLARE_STREAM_SIGNING_KEY_ID and CLOUDFLARE_STREAM_SIGNING_KEY_PEM need to be set, with a private key that can be read. Until that is fixed, playback links are signed by Cloudflare instead.', 'cloudflare-stream' )
				. '</p>';

			if ( $in_db ) {
				$this->echo_signing_key_form_button( 'clear', __( 'Remove the unused database copy', 'cloudflare-stream' ), 'delete' );
			}

			return;
		}

		$key_id = $api->get_signing_key_id();
		if ( '' !== $key_id ) {
			echo '<input type="text" class="regular-text" value="' . esc_attr( $key_id ) . '" disabled="disabled" autocomplete="off">';
		}

		if ( $const_ready ) {
			echo '<p class="description">' . esc_html__( 'Set by CLOUDFLARE_STREAM_SIGNING_KEY_ID and CLOUDFLARE_STREAM_SIGNING_KEY_PEM in wp-config.php.', 'cloudflare-stream' ) . '</p>';
			return;
		}

		if ( $in_db ) {
			echo '<p class="description">' . esc_html__( 'Move the key to wp-config.php / PHP constants to keep it out of database backups. This creates a new key, then revokes the old one after the constants work.', 'cloudflare-stream' ) . '</p>';

			echo '<div class="cloudflare-stream-signing-key-actions">';
			$this->echo_signing_key_form_button( 'rotate', __( 'Move key to wp-config.php', 'cloudflare-stream' ) );
			$this->echo_signing_key_form_button( 'clear', __( 'Remove signing key', 'cloudflare-stream' ), 'delete' );
			echo '<p class="description">' . esc_html__( 'Removing the key also revokes it in Cloudflare when possible.', 'cloudflare-stream' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Optional. Improves signed playback without calling Cloudflare on every page view.', 'cloudflare-stream' ) . '</p>';

		echo '<div class="cloudflare-stream-signing-key-actions">';
		$this->echo_signing_key_form_button( 'generate', __( 'Generate signing key', 'cloudflare-stream' ) );
		echo '<p class="description">' . esc_html__( 'Creates a key in Cloudflare, then asks where to keep it.', 'cloudflare-stream' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Inline signing health / degradation panel under Signing Key status.
	 *
	 * @return void
	 */
	private function echo_signing_health_panel() {
		if ( ! class_exists( 'Cloudflare_Stream_Signing_Health' ) ) {
			return;
		}

		$health = Cloudflare_Stream_Signing_Health::instance();
		$issue  = $health->get_issue();
		if ( null === $issue ) {
			return;
		}

		$this->render_signing_health_notice( $issue, 'settings' );
	}

	/**
	 * Render a signing-health issue notice (settings panel or admin banner).
	 *
	 * @param array  $issue   Issue from get_issue().
	 * @param string $context 'settings' or 'admin'.
	 * @return void
	 */
	private function render_signing_health_notice( array $issue, $context = 'settings' ) {
		$severity = in_array( $issue['severity'], array( 'info', 'warning', 'error' ), true )
			? $issue['severity']
			: 'info';
		$inline   = ( 'settings' === $context ) ? ' inline' : '';

		if ( 'admin' === $context ) {
			$health       = Cloudflare_Stream_Signing_Health::instance();
			$settings_url = admin_url( 'options-general.php?page=cloudflare-stream' );
			$dismiss_url  = $health->get_dismiss_url();
			if ( 'error' === $severity ) {
				$body = __( 'Cloudflare Stream signed playback is unavailable (local signing and API token mint both failed). Embeds are empty on purpose. Review the Signing Key status on the settings page.', 'cloudflare-stream' );
			} else {
				$body = __( 'Cloudflare Stream is serving signed playback via the API because local signing failed. Playback still works; see Signing Key status for details.', 'cloudflare-stream' );
			}

			printf(
				'<div class="notice notice-%1$s"><p>%2$s <a href="%3$s">%4$s</a> | <a href="%5$s">%6$s</a></p></div>',
				esc_attr( $severity ),
				esc_html( $body ),
				esc_url( $settings_url ),
				esc_html__( 'Cloudflare Stream settings', 'cloudflare-stream' ),
				esc_url( $dismiss_url ),
				esc_html__( 'Dismiss', 'cloudflare-stream' )
			);
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $severity ) . esc_attr( $inline ) . '">';
		if ( ! empty( $issue['title'] ) && ! in_array( $issue['code'], array( 'recovered', 'api_mode' ), true ) ) {
			echo '<p><strong>' . esc_html( $issue['title'] ) . '</strong></p>';
		}
		if ( ! empty( $issue['body'] ) ) {
			echo '<p>' . esc_html( $issue['body'] ) . '</p>';
		}
		if ( ! empty( $issue['detail'] ) ) {
			echo '<p>' . esc_html( $issue['detail'] ) . '</p>';
		}
		if ( ! empty( $issue['label'] ) ) {
			echo '<p>' . esc_html( $issue['label'] ) . '</p>';
		}
		if ( ! empty( $issue['tips'] ) && is_array( $issue['tips'] ) ) {
			echo '<p><strong>' . esc_html__( 'What to try:', 'cloudflare-stream' ) . '</strong></p><ul>';
			foreach ( $issue['tips'] as $tip ) {
				echo '<li>' . esc_html( $tip ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Dismissible admin notice when local signing is degraded or failed.
	 *
	 * @return void
	 */
	public function signing_health_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! class_exists( 'Cloudflare_Stream_Signing_Health' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins', 'settings_page_cloudflare-stream' ), true ) ) {
			return;
		}

		$health = Cloudflare_Stream_Signing_Health::instance();
		$issue  = $health->get_issue();
		if ( null === $issue || ! in_array( $issue['severity'], array( 'warning', 'error' ), true ) ) {
			return;
		}

		if ( $health->is_notice_dismissed_for_user() ) {
			return;
		}

		$this->render_signing_health_notice( $issue, 'admin' );
	}

	/**
	 * Hidden admin-post forms for signing key buttons (outside the options form).
	 */
	public function render_signing_key_external_forms() {
		$reveal = $this->get_signing_key_reveal();

		if ( is_array( $reveal ) ) {
			if ( 'rotate' === $reveal['context'] ) {
				$this->echo_signing_key_external_form( 'confirm_moved' );
				$this->echo_signing_key_external_form( 'dismiss' );
			} else {
				$this->echo_signing_key_external_form( 'confirm_constants' );
				$this->echo_signing_key_external_form( 'store_db' );
			}
			return;
		}

		$in_db = $this->db_has_signing_key_options();

		// Constants win, so a leftover database copy is all that can be acted on.
		if ( $this->signing_key_from_constants() ) {
			if ( $in_db && ! $this->constants_signing_key_ready() ) {
				$this->echo_signing_key_external_form( 'clear' );
			}
			return;
		}

		if ( $in_db ) {
			$this->echo_signing_key_external_form( 'rotate' );
			$this->echo_signing_key_external_form( 'clear' );
			return;
		}

		$this->echo_signing_key_external_form( 'generate' );
	}

	/**
	 * User-specific transient for a one-shot signing key admin notice.
	 *
	 * @return string
	 */
	private function signing_key_notice_transient_name() {
		return self::TRANSIENT_SIGNING_KEY_NOTICE . get_current_user_id();
	}

	/**
	 * Stash a notice code, then redirect to settings without a sticky query arg.
	 *
	 * @param string $code Notice code.
	 * @param array  $data Optional extra fields (for example key_id).
	 */
	private function redirect_signing_key_notice( $code, $data = array() ) {
		$code = sanitize_key( $code );

		if ( '' !== $code && 'noop' !== $code ) {
			$payload = array( 'code' => $code );
			if ( is_array( $data ) ) {
				if ( ! empty( $data['key_id'] ) ) {
					$payload['key_id'] = sanitize_text_field( (string) $data['key_id'] );
				}
			}
			set_transient( $this->signing_key_notice_transient_name(), $payload, HOUR_IN_SECONDS );
		}

		$redirect = add_query_arg(
			array(
				'page' => 'cloudflare-stream',
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Whether constants alone provide a usable signing key.
	 *
	 * @return bool
	 */
	private function constants_signing_key_ready() {
		return Cloudflare_Stream_API::instance()->has_signing_key_from_constants();
	}

	/**
	 * Generate, finish setup, rotate, or clear signing key (manage_options + nonce).
	 */
	public function handle_signing_key_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'cloudflare-stream' ), 403 );
		}

		check_admin_referer( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );

		$do  = isset( $_POST['signing_key_do'] ) ? sanitize_text_field( wp_unslash( $_POST['signing_key_do'] ) ) : '';
		$api = Cloudflare_Stream_API::instance();

		if ( 'clear' === $do ) {
			// When constants are broken, only drop the unused DB copy; do not revoke
			// a key id the constants may still be trying to use.
			$revoke_id = '';
			if ( ! $this->signing_key_from_constants() && $this->db_has_signing_key_options() ) {
				$revoke_id = $this->get_db_signing_key_id();
			}

			$this->delete_db_signing_key_options();
			$this->clear_signing_key_reveal( true );
			$this->clear_signing_health_hot_state();

			if ( '' !== $revoke_id ) {
				if ( $this->revoke_signing_key_at_cloudflare( $revoke_id ) ) {
					$this->redirect_signing_key_notice( 'cleared_revoked' );
				}
				$this->redirect_signing_key_notice( 'cleared_revoke_failed', array( 'key_id' => $revoke_id ) );
			}

			$this->redirect_signing_key_notice( 'cleared' );
		}

		if ( 'dismiss' === $do ) {
			// Abandon rotate/setup: revoke the unused new key when one was minted.
			$this->clear_signing_key_reveal( true );
			$this->redirect_signing_key_notice( 'dismissed' );
		}

		if ( 'confirm_constants' === $do || 'confirm_moved' === $do ) {
			if ( ! $this->constants_signing_key_ready() ) {
				// Keep confirm/store actions available; do not reprint the private key.
				$this->redirect_signing_key_notice( 'constants_missing' );
			}

			$old_db_id = $this->get_db_signing_key_id();

			// Constants verified first; only then drop DB options and revoke the old key.
			$this->delete_db_signing_key_options();
			$this->clear_signing_key_reveal( false );
			$this->clear_signing_health_hot_state();

			if ( 'confirm_moved' === $do && '' !== $old_db_id ) {
				// Never revoke the key that constants now use.
				$live_id = $api->get_signing_key_id();
				if ( $old_db_id !== $live_id ) {
					if ( $this->revoke_signing_key_at_cloudflare( $old_db_id ) ) {
						$this->redirect_signing_key_notice( 'moved' );
					}
					$this->redirect_signing_key_notice( 'moved_revoke_failed', array( 'key_id' => $old_db_id ) );
				}
			}

			$this->redirect_signing_key_notice( 'confirm_constants' === $do ? 'constants_ok' : 'moved' );
		}

		if ( 'store_db' === $do ) {
			// Constants added since setup started win, so nothing is written.
			if ( $this->signing_key_from_constants() ) {
				$this->clear_signing_key_reveal( true );
				$this->redirect_signing_key_notice( 'constants' );
			}

			$pending = $this->get_signing_key_reveal();
			if ( null === $pending || 'setup' !== $pending['context'] || '' === $pending['pem'] || '' === $pending['id'] ) {
				$this->redirect_signing_key_notice( 'store_failed' );
			}

			// Validate before any option write so a bad key never lands in the database.
			if ( ! $api->signing_key_material_is_usable( $pending['id'], $pending['pem'] ) ) {
				$this->clear_signing_key_reveal( true );
				$this->redirect_signing_key_notice( 'invalid' );
			}

			if ( ! $this->store_db_signing_key( $pending['id'], $pending['pem'] ) ) {
				if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
					$this->redirect_signing_key_notice( 'store_crypto_unavailable' );
				}
				$this->redirect_signing_key_notice( 'store_failed' );
			}

			$this->clear_signing_key_reveal( false );
			$this->clear_signing_health_hot_state();
			$this->redirect_signing_key_notice( 'stored' );
		}

		if ( 'generate' === $do ) {
			if ( $this->signing_key_from_constants() ) {
				$this->redirect_signing_key_notice( 'constants' );
			}

			if ( $api->has_signing_key() || $this->db_has_signing_key_options() ) {
				$this->redirect_signing_key_notice( 'noop' );
			}

			$result = $api->create_signing_key();

			if ( ! is_object( $result ) || empty( $result->id ) || empty( $result->pem ) || ! is_string( $result->pem ) ) {
				$this->redirect_signing_key_notice( 'generate_failed' );
			}

			$key_id  = sanitize_text_field( (string) $result->id );
			$pem_raw = $api->normalize_signing_key_material( $result->pem );

			if ( '' === $key_id || '' === $pem_raw ) {
				$this->redirect_signing_key_notice( 'invalid' );
			}

			// Do not write options yet; user chooses constants or database next.
			$this->stash_signing_key_reveal( $key_id, $pem_raw, 'setup' );
			$this->redirect_signing_key_notice( 'generated' );
		}

		if ( 'rotate' === $do ) {
			if ( $this->signing_key_from_constants() || ! $this->db_has_signing_key_options() ) {
				$this->redirect_signing_key_notice( 'rotate_failed' );
			}

			// Mint a new key; the stored PEM is never read back to the screen.
			$result = $api->create_signing_key();

			if ( ! is_object( $result ) || empty( $result->id ) || empty( $result->pem ) || ! is_string( $result->pem ) ) {
				$this->redirect_signing_key_notice( 'rotate_create_failed' );
			}

			$key_id  = sanitize_text_field( (string) $result->id );
			$pem_raw = $api->normalize_signing_key_material( $result->pem );

			if ( '' === $key_id || '' === $pem_raw ) {
				$this->redirect_signing_key_notice( 'rotate_create_failed' );
			}

			$this->stash_signing_key_reveal( $key_id, $pem_raw, 'rotate' );
			$this->redirect_signing_key_notice( 'rotate_started' );
		}

		$this->redirect_signing_key_notice( 'noop' );
	}

	/**
	 * Show one-shot notices after signing key admin-post actions.
	 */
	public function signing_key_admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- settings page screen check only.
		if ( empty( $_GET['page'] ) || 'cloudflare-stream' !== $_GET['page'] ) {
			return;
		}

		$raw = get_transient( $this->signing_key_notice_transient_name() );
		if ( false === $raw || null === $raw || '' === $raw ) {
			return;
		}

		// Consume immediately so reload/dismiss cannot replay the notice.
		delete_transient( $this->signing_key_notice_transient_name() );

		$key_id = '';
		if ( is_array( $raw ) ) {
			$code   = isset( $raw['code'] ) ? sanitize_key( (string) $raw['code'] ) : '';
			$key_id = isset( $raw['key_id'] ) ? sanitize_text_field( (string) $raw['key_id'] ) : '';
		} else {
			$code = sanitize_key( (string) $raw );
		}

		if ( '' === $code ) {
			return;
		}

		// Drop state-dependent copy when it is no longer true.
		if ( in_array( $code, array( 'constants_ok', 'moved', 'moved_revoke_failed' ), true ) && ! $this->constants_signing_key_ready() ) {
			return;
		}
		if ( 'constants' === $code && ! $this->signing_key_from_constants() ) {
			return;
		}

		$messages = array(
			'generated'                => array(
				'type' => 'success',
				'text' => __( 'Signing key created. Choose below where to keep it. Nothing is saved yet.', 'cloudflare-stream' ),
			),
			'rotate_started'           => array(
				'type' => 'success',
				'text' => __( 'A new signing key was created. Add the lines below to wp-config.php, then confirm. The previous key stays valid until you confirm.', 'cloudflare-stream' ),
			),
			'constants_ok'             => array(
				'type' => 'success',
				'text' => __( 'Signing key is now read from PHP constants.', 'cloudflare-stream' ),
			),
			'moved'                    => array(
				'type' => 'success',
				'text' => __( 'Signing key is now read from PHP constants. The database copy was removed and the previous key was revoked in Cloudflare.', 'cloudflare-stream' ),
			),
			'moved_revoke_failed'      => array(
				'type' => 'warning',
				/* translators: %s: Cloudflare signing key id */
				'text' => __( 'Signing key is now read from PHP constants and the database copy was removed, but the previous key could not be revoked in Cloudflare. Revoke it in the Cloudflare dashboard. Key id: %s', 'cloudflare-stream' ),
			),
			'stored'                   => array(
				'type' => 'success',
				'text' => __( 'Signing key saved in the database.', 'cloudflare-stream' ),
			),
			'cleared'                  => array(
				'type' => 'success',
				'text' => __( 'Signing key removed from the database.', 'cloudflare-stream' ),
			),
			'cleared_revoked'          => array(
				'type' => 'success',
				'text' => __( 'Signing key removed from the database and revoked in Cloudflare.', 'cloudflare-stream' ),
			),
			'cleared_revoke_failed'    => array(
				'type' => 'warning',
				/* translators: %s: Cloudflare signing key id */
				'text' => __( 'Signing key removed from the database, but it could not be revoked in Cloudflare. Revoke it in the Cloudflare dashboard. Key id: %s', 'cloudflare-stream' ),
			),
			'dismissed'                => array(
				'type' => 'success',
				'text' => __( 'Cancelled. The existing database signing key is unchanged. Any unused new key was discarded.', 'cloudflare-stream' ),
			),
			'constants_missing'        => array(
				'type' => 'error',
				'text' => __( 'The PHP constants are not working yet. Check them, then try again. Confirm and save actions stay available for a short time. The private key is not shown again; cancel and start again if you need the lines reprinted.', 'cloudflare-stream' ),
			),
			'generate_failed'          => array(
				'type' => 'error',
				'text' => __( 'Could not create a signing key. Check the API token and account ID.', 'cloudflare-stream' ),
			),
			'rotate_create_failed'     => array(
				'type' => 'error',
				'text' => __( 'Could not create a new signing key, so nothing was changed. The database key is still in place.', 'cloudflare-stream' ),
			),
			'rotate_failed'            => array(
				'type' => 'error',
				'text' => __( 'There is no database signing key to move, or PHP constants already supply the key.', 'cloudflare-stream' ),
			),
			'invalid'                  => array(
				'type' => 'error',
				'text' => __( 'That signing key could not be used. Nothing was saved.', 'cloudflare-stream' ),
			),
			'store_failed'             => array(
				'type' => 'error',
				'text' => __( 'There is no new signing key to save. Generate one again.', 'cloudflare-stream' ),
			),
			'store_crypto_unavailable' => array(
				'type' => 'error',
				'text' => __( 'The signing key could not be saved because encryption is unavailable on this server. The previous stored value was kept, or nothing was stored if none existed.', 'cloudflare-stream' ),
			),
			'constants'                => array(
				'type' => 'error',
				'text' => __( 'The signing key is set as PHP constants, so that action was skipped.', 'cloudflare-stream' ),
			),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$text = $messages[ $code ]['text'];
		if ( false !== strpos( $text, '%s' ) ) {
			$text = sprintf( $text, '' !== $key_id ? $key_id : __( '(unknown)', 'cloudflare-stream' ) );
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ]['type'] ),
			esc_html( $text )
		);
	}

	/**
	 * Callback for rendering the preferred media domain field
	 */
	public function media_domain_cb() {
		$media_domain           = get_option( self::OPTION_MEDIA_DOMAIN, self::STANDARD_MEDIA_DOMAINS[0] );
		$num_domains            = count( self::STANDARD_MEDIA_DOMAINS );
		$existing_custom_domain = true; // Placeholder value, but will be confirmed below.

		for ( $i = 0; $i < $num_domains; $i++ ) {
			$domain       = self::STANDARD_MEDIA_DOMAINS[ $i ];
			$default_text = 0 === $i ? esc_html__( ' (default)', 'cloudflare-stream' ) : '';
			// Standard options control the iframe host; posters use videodelivery.net.
			$label = sprintf(
				/* translators: 1: domain name used for iframe embeds */
				__( '%1$s (iframe.%1$s)', 'cloudflare-stream' ),
				$domain
			);
			echo '<label for="cloudflare_stream_media_domain_' . esc_attr( $i ) . '">'
			. '<input type="radio" class="radio-option" name="cloudflare_stream_media_domain" id="cloudflare_stream_media_domain_' . esc_attr( $i ) . '" value="' . esc_attr( $domain ) . '" ' . checked( $domain, $media_domain, false ) . ' >'
			. esc_html( $label ) . esc_html( $default_text ) . '</label>';

			if ( $domain === $media_domain ) {
				$existing_custom_domain = false;
			}
		}

		// The account subdomain option is only presented if it was able to be retrieved from the API.
		$account_subdomain = self::get_account_subdomain();

		// In the event custom domain is in use, but API details are misconfigured, this retains that setting as default.
		if ( empty( $account_subdomain ) && ( $existing_custom_domain && ! self::test_api_keys() ) ) {
			$account_subdomain = $media_domain;
		}

		if ( $account_subdomain ) {
			echo '<label for="cloudflare_stream_media_domain_' . esc_attr( $num_domains ) . '"><input type="radio" class="radio-option" name="cloudflare_stream_media_domain" id="cloudflare_stream_media_domain_' . esc_attr( $num_domains ) . '" value="' . esc_html( $account_subdomain ) . '" ' . checked( $account_subdomain, $media_domain, false ) . ' >' . esc_html( $account_subdomain ) . ' (<a href="' . esc_url( 'https://community.cloudflare.com/t/upcoming-domain-change-to-ensure-delivery-of-your-video-content/405842' ) . '" target="_blank">' . esc_html__( 'more information', 'cloudflare-stream' ) . '</a>)</label>';
		}

		echo '<small class="form-text text-muted">' . esc_html__( 'Sets the iframe player host. Standard domains use videodelivery.net for thumbnails. A customer subdomain is used for both player and thumbnails. Changing this may require a Content Security Policy update.', 'cloudflare-stream' ) . '</small>';
	}

	/** PLAYER SETTINGS CALLBACKS **/

	/**
	 * Callback for rendering the poster time field
	 */
	public function poster_time_cb() {
		$poster_time = get_option( self::OPTION_POSTER_TIME, self::DEFAULT_POSTER_TIME );
		echo '<label for="cloudflare_stream_poster_time"><input type="number" class="regular-text" name="cloudflare_stream_poster_time" id="cloudflare_stream_poster_time" value="' . esc_attr( intval( $poster_time ) ) . '" autocomplete="off"> seconds</label>'
		. '<small class="form-text text-muted">' . esc_html__( 'A default time in seconds, of where to reference the video thumbnail from in any given video. Can be overridden by shortcode argument postertime. eg: postertime="10s".', 'cloudflare-stream' ) . '</small>';
	}

	/**
	 * Setup Admin Menu Options & Settings.
	 *
	 * Single site: manage_options. Multisite: page is under network admin, so the
	 * practical gate is reaching network admin (manage_network), not site manage_options.
	 *
	 * @uses current_user_can, add_options_page
	 * @action network_admin_menu, admin_menu
	 * @return void|false
	 */
	public function action_admin_menu() {
		// Defaults are independent of who can see the menu.
		$this->maybe_seed_defaults();

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Completely remove old less secure API credentials if they exist.
		if ( get_option( self::OPTION_API_KEY ) !== false ) {
			delete_option( self::OPTION_API_EMAIL );
			delete_option( self::OPTION_API_KEY );
		}

		add_options_page( __( 'Cloudflare Stream', 'cloudflare-stream' ), __( 'Cloudflare Stream', 'cloudflare-stream' ), 'manage_options', 'cloudflare-stream', array( $this, 'settings_page' ) );
	}

	/**
	 * Displays all messages registered to 'cloudflare-stream-settings'.
	 */
	public function settings_errors_admin_notices() {
		settings_errors( 'cloudflare-stream-settings' );
	}

	/**
	 * Notice on the plugins and settings screens when setup is incomplete or the credentials fail.
	 */
	public function onboarding_admin_notices() {
		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_cloudflare-stream' ), true ) ) {
			return;
		}

		$on_settings_page = ( 'settings_page_cloudflare-stream' === $screen->id );

		if ( ! self::is_configured() ) {
			if ( ! $on_settings_page ) {
				$this->echo_onboarding_notice(
					'warning',
					/* translators: %s: settings page URL */
					__( 'Cloudflare Stream is not configured. Visit the <a href="%s">settings page</a> to get started.', 'cloudflare-stream' )
				);
			}
			return;
		}

		if ( $on_settings_page && ! self::test_api_keys() ) {
			$this->echo_onboarding_notice(
				'error',
				__( 'Cloudflare Stream could not connect with these API details. Check the account ID and token below.', 'cloudflare-stream' )
			);
		}
	}

	/**
	 * Dismissible notice, with the settings page URL filled in for any %s placeholder.
	 *
	 * @param string $type    Notice type (warning, error).
	 * @param string $message Message, optionally with one %s for the settings page URL.
	 */
	private function echo_onboarding_notice( $type, $message ) {
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			sprintf(
				wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ),
				esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) )
			)
		);
	}

	/**
	 * Try to fetch the account subdomain.
	 *
	 * @since 1.0.9
	 */
	public function get_account_subdomain() {
		$api = Cloudflare_Stream_API::instance();
		return $api->get_account_subdomain();
	}

	/**
	 * Try to fetch and save the Cloudflare Account ID using Zone ID.
	 *
	 * @deprecated The zones API is no longer used by the plugin.
	 * @since      1.0.9
	 */
	public function get_account_id() {
		$api_token   = self::get_api_token();
		$api_zone_id = get_option( self::OPTION_API_ZONE_ID );

		if ( ! empty( $api_token ) && ! empty( $api_zone_id ) ) {
			$api = Cloudflare_Stream_API::instance();
			return $api->get_account_id( true );
		}
		return false;
	}

	/**
	 * Make a test call to an endpoint to test the API keys.
	 *
	 * The result is reused for the rest of the request.
	 *
	 * @since 1.0.0
	 */
	public function test_api_keys() {
		if ( null !== self::$api_keys_work ) {
			return self::$api_keys_work;
		}

		$api    = Cloudflare_Stream_API::instance();
		$videos = $api->get_videos();

		if ( ! is_object( $videos ) ) {
			self::$api_keys_work = false;
		} elseif ( isset( $videos->success ) ) {
			// Successful list responses omit errors or return an empty list.
			self::$api_keys_work = ! empty( $videos->success );
		} else {
			self::$api_keys_work = empty( $videos->errors );
		}

		return self::$api_keys_work;
	}
	/**
	 * Settings Page
	 *
	 * @since 1.0.0
	 */
	public function settings_page() {
		?>
		<div class="wrap">
		<div id="icon-options-cloudflare-stream" class="icon32"></div>
			<h1><?php esc_html_e( 'Cloudflare Stream Settings', 'cloudflare-stream' ); ?></h1>
			<form method="post" action="options.php">
			<?php
				settings_fields( self::SETTING_GROUP );
				wp_nonce_field( 'cloudflare-stream-save-settings', self::NONCE );
				do_settings_sections( 'cloudflare-stream' );
				submit_button();
			?>
			</form>
			<?php
			if ( current_user_can( 'manage_options' ) ) {
				$this->render_signing_key_external_forms();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render API Key Settings Section
	 *
	 * @since 1.0.0
	 */
	public function settings_section_api_keys() {
		echo '<p>';
		printf(
			wp_kses(
				/* translators: %s: link to the plugin README */
				__( 'Enter your Cloudflare account details below. If you need help getting started, <a target="_blank" href="%s" title="Cloudflare Stream for WordPress README">read the setup guide.</a>', 'cloudflare-stream' ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array( '_blank' ),
					),
				)
			),
			esc_url( 'https://github.com/B-Interactive/cloudflare-stream-wordpress#readme' )
		);
		echo '</p>';
		echo '<p>' . esc_html__( 'On production sites you can keep these secrets in wp-config.php / PHP constants instead of the database. Any value set there is used, the matching field becomes read only, and the database copy is removed for you. See the setup guide for the lines to add.', 'cloudflare-stream' ) . '</p>';
	}

	/**
	 * Render Player Settings Section
	 *
	 * @since 1.9.4
	 */
	public function settings_section_player() {
		echo '<p>';
		echo esc_html__( 'Global settings for the player. Some of these can be overridden on a per video basis with shortcode arguments.', 'cloudflare-stream' );
		echo '</p>';
	}

	/**
	 * Helper function for determining if the user has attempted to setup their API keys.
	 */
	public static function is_configured() {
		return ( self::get_api_token() && self::get_api_account() );
	}
}
Cloudflare_Stream_Settings::instance();
