<?php
/**
 * Cloudflare Stream Settings class
 *
 * Handles plugin settings, including secure storage and display of Cloudflare Stream credentials.
 *
 * @package cloudflare-stream
 * @since   1.0.0
 */

require_once __DIR__ . '/class-cloudflare-stream-security.php';

class Cloudflare_Stream_Settings {

	private static $instance = false;

	// Option and settings constants (existing and unchanged)
	const NONCE                       = 'cloudflare-stream';
	const SETTING_PAGE                = 'cloudflare-stream';
	const SETTING_GROUP               = 'cloudflare_stream';
	const SETTING_SECTION_GENERAL     = 'cloudflare_stream_settings_general';
	const SETTING_SECTION_PLAYER      = 'cloudflare_stream_settings_player';
	const SETTING_SECTION_REPORTING   = 'cloudflare_stream_settings_reporting';
	const OPTION_API_TOKEN            = 'cloudflare_stream_api_token';
	const OPTION_API_ACCOUNT          = 'cloudflare_stream_api_account';
	const OPTION_SIGNED_URLS          = 'cloudflare_stream_signed_urls';
	const OPTION_SIGNED_URLS_DURATION = 'cloudflare_stream_signed_urls_duration';
	const OPTION_MEDIA_DOMAIN         = 'cloudflare_stream_media_domain';
	const OPTION_POSTER_TIME          = 'cloudflare_stream_poster_time';
	const STANDARD_MEDIA_DOMAINS      = array( 'cloudflarestream.com', 'videodelivery.net' );

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}

	private function __construct() { }

	public function cloudflare_stream_admin_enqueue_styles( $hook ): void {
		if ( 'settings_page_cloudflare-stream' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cloudflare-stream', plugin_dir_url( __DIR__ ) . 'css/cloudflare-stream-admin.css', array(), '1.0.9' );
	}

	public function setup() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'action_admin_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'cloudflare_stream_admin_enqueue_styles' ) );
		add_action( 'admin_init', array( $this, 'maybe_migrate_credentials' ) );
	}

	public function action_admin_init() {
		// Only register settings for non-constant credentials.
		if ( ! defined( 'CLOUDFLARE_STREAM_ACCOUNT_ID' ) || ! defined( 'CLOUDFLARE_STREAM_API_TOKEN' ) ) {
			register_setting( self::SETTING_GROUP, self::OPTION_API_ACCOUNT );
			register_setting( self::SETTING_GROUP, self::OPTION_API_TOKEN );
		}
		register_setting( self::SETTING_GROUP, self::OPTION_SIGNED_URLS );
		register_setting( self::SETTING_GROUP, self::OPTION_SIGNED_URLS_DURATION );
		register_setting( self::SETTING_GROUP, self::OPTION_MEDIA_DOMAIN );
		register_setting( self::SETTING_GROUP, self::OPTION_POSTER_TIME );

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

		// ... (other settings fields unchanged)
	}

	/**
	 * Only migrate credentials if needed.
	 * (This ensures existing installs update securely.)
	 */
	public function maybe_migrate_credentials() {
		Cloudflare_Stream_Security::maybe_migrate_credentials();
	}

	/**
	 * Callback for API Account ID settings field
	 * If credentials are defined as constants, show notice and disable input.
	 */
	public function api_account_cb() {
		$use_constants = defined( 'CLOUDFLARE_STREAM_ACCOUNT_ID' );
		$value         = '';
		if ( $use_constants ) {
			$value = CLOUDFLARE_STREAM_ACCOUNT_ID;
		} else {
			$creds = Cloudflare_Stream_Security::get_credentials_from_db();
			$value = $creds && isset( $creds['account_id'] ) ? $creds['account_id'] : '';
		}
		echo '<input type="text" class="regular-text" name="cloudflare_stream_api_account" id="cloudflare_stream_api_account" value="' . esc_attr( $value ) . '" ' . ( $use_constants ? 'readonly disabled' : '' ) . ' autocomplete="on"> ';
		if ( $use_constants ) {
			echo '<small class="form-text text-muted">' . esc_html__( 'Defined in wp-config.php', 'cloudflare-stream' ) . '</small>';
		} else {
			echo '<small class="form-text text-muted">' . esc_html__( 'Cloudflare > [domain] > Overview > [API section: Account ID].', 'cloudflare-stream' ) . '</small>';
		}
	}

	/**
	 * Callback for API Token settings field
	 * If credentials are defined as constants, show notice and disable input.
	 */
	public function api_token_cb() {
		$use_constants = defined( 'CLOUDFLARE_STREAM_API_TOKEN' );
		$value         = '';
		if ( $use_constants ) {
			$value = str_repeat( '*', 16 ); // Don't show actual token!
		} else {
			$creds = Cloudflare_Stream_Security::get_credentials_from_db();
			$value = $creds && isset( $creds['api_token'] ) ? $creds['api_token'] : '';
		}
		echo '<input type="password" class="regular-text" name="cloudflare_stream_api_token" id="cloudflare_stream_api_token" value="' . esc_attr( $value ) . '" ' . ( $use_constants ? 'readonly disabled' : '' ) . ' autocomplete="off">';
		if ( $use_constants ) {
			echo '<small class="form-text text-muted">' . esc_html__( 'Defined in wp-config.php', 'cloudflare-stream' ) . '</small>';
		} else {
			echo '<small class="form-text text-muted">' . esc_html__( 'Cloudflare > My Profile > API Tokens > [Create Token]', 'cloudflare-stream' ) . '</small>';
		}
	}

	// ... (rest of your settings class unchanged)
}
Cloudflare_Stream_Settings::instance();
