<?php
/**
 * Cloudflare Stream Settings class
 *
 * Methods for interacting with the WordPress Settings API.
 *
 * @package cloudflare-stream
 * @since      1.0.0
 */

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

	const NONCE                       = 'cloudflare-stream';
	const SETTING_PAGE                = 'cloudflare-stream';
	const SETTING_GROUP               = 'cloudflare_stream';
	const SETTING_SECTION_GENERAL     = 'cloudflare_stream_settings_general';
	const SETTING_SECTION_REPORTING   = 'cloudflare_stream_settings_reporting';
	const OPTION_API_TOKEN            = 'cloudflare_stream_api_token';
	const OPTION_API_ZONE_ID          = 'cloudflare_stream_api_zone_id';
	const OPTION_API_KEY              = 'cloudflare_stream_api_key';
	const OPTION_API_EMAIL            = 'cloudflare_stream_api_email';
	const OPTION_API_ACCOUNT          = 'cloudflare_stream_api_account';
	const OPTION_SIGNED_URLS          = 'cloudflare_stream_signed_urls';
	const OPTION_SIGNED_URLS_DURATION = 'cloudflare_stream_signed_urls_duration';
	const OPTION_MEDIA_DOMAIN         = 'cloudflare_stream_media_domain';

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
	 * Setup Hooks
	 *
	 * @since 1.0.0
	 */
	public function setup() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'action_admin_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
	}

	/**
	 * Setup the Admin.
	 *
	 * @uses register_setting, add_settings_section, add_settings_field
	 * @action admin_init
	 */
	public function action_admin_init() {

		// Register Settings.
		register_setting( self::SETTING_GROUP, self::OPTION_API_ACCOUNT );
		register_setting( self::SETTING_GROUP, self::OPTION_API_TOKEN );
		register_setting( self::SETTING_GROUP, self::OPTION_SIGNED_URLS );
		register_setting( self::SETTING_GROUP, self::OPTION_SIGNED_URLS_DURATION );
		register_setting( self::SETTING_GROUP, self::OPTION_MEDIA_DOMAIN );

		add_settings_section(
			self::SETTING_SECTION_GENERAL,
			esc_html__( 'API Configuration', 'cloudflare-stream-wordpress' ),
			array( $this, 'settings_section_api_keys' ),
			self::SETTING_PAGE
		);

			add_settings_field(
				self::OPTION_API_ACCOUNT,
				esc_html__( 'API Account ID', 'cloudflare-stream-wordpress' ),
				array( $this, 'api_account_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_API_TOKEN,
				esc_html__( 'API Token', 'cloudflare-stream-wordpress' ),
				array( $this, 'api_token_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_SIGNED_URLS,
				esc_html__( 'Use Signed URLs', 'cloudflare-stream-wordpress' ),
				array( $this, 'api_signed_urls_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_SIGNED_URLS_DURATION,
				esc_html__( 'Signed URL Expiration', 'cloudflare-stream-wordpress' ),
				array( $this, 'api_signed_urls_duration_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_MEDIA_DOMAIN,
				esc_html__( 'Preferred Media Domain', 'cloudflare-stream-wordpress' ),
				array( $this, 'media_domain_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

		add_action( 'admin_notices', array( $this, 'settings_errors_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'onboarding_admin_notices' ) );
	}

	/**
	 * Callback for rendering the API Account ID settings field
	 */
	public function api_account_cb() {
		$api_account = get_option( self::OPTION_API_ACCOUNT );
		if ( empty( $api_account ) ) {
			$api_account = self::get_account_id();
		}
		echo '<input type="text" class="regular-text" name="cloudflare_stream_api_account" id="cloudflare_stream_api_account" value="' . esc_attr( $api_account ) . '" autocomplete="on"> '
		   . '<small class="form-text text-muted">' . esc_html__( 'Cloudflare > [domain] > Overview > [scroll down to API section on the right and copy the Account ID].', 'cloudflare-stream-wordpress' ) . '</small>';
	}

	/**
	 * Callback for rendering the API Token settings field
	 */
	public function api_token_cb() {
		$api_token = get_option( self::OPTION_API_TOKEN );
		echo '<input type="password" class="regular-text" name="cloudflare_stream_api_token" id="cloudflare_stream_api_token" value="' . esc_attr( $api_token ) . '" autocomplete="off">'
		   . '<small class="form-text text-muted">'
		   . esc_html__( 'Cloudflare > My Profile > API Tokens > API Tokens > [Create Token]', 'cloudflare-stream-wordpress' ) . '</small>'
		   . '<small class="form-text text-muted">' . esc_html__( 'Must have permission for: Account - Stream:Edit', 'cloudflare-stream-wordpress' ) . '</small>';
	}

	/**
	 * Callback for rendering the use signed URLs field
	 */
	public function api_signed_urls_cb() {
		$signed_urls = get_option( self::OPTION_SIGNED_URLS );
		echo '<label><input type="checkbox" class="regular-text" name="cloudflare_stream_signed_urls" id="cloudflare_stream_signed_urls" value="1"' . checked( $signed_urls, true, false ) . '>' . esc_html__( 'Protects video links from being copied, by creating a unique temporary URL.', 'cloudflare-stream-wordpress' ) . '</label>'
		   . '<small class="form-text text-muted">' . esc_html__( 'For best protection, also set each video to only be accessible via signed URLs in your Cloudflare Stream dashboard.', 'cloudflare-stream-wordpress' ) . '</small>';
	}

	/**
	 * Callback for rendering the signed URLs duration field
	 */
	public function api_signed_urls_duration_cb() {
		$signed_urls_duration = get_option( self::OPTION_SIGNED_URLS_DURATION );
		echo '<input type="number" class="regular-text" name="cloudflare_stream_signed_urls_duration" id="cloudflare_stream_signed_urls_duration" value="' . esc_attr( intval($signed_urls_duration) ) . '" autocomplete="off"> '
		   . '<small class="form-text text-muted">' . esc_html__( 'Sets how long the unique signed URL/token remains accessible for, in minutes.', 'cloudflare-stream-wordpress' ) . '</small>';
	}

	/**
	 * Callback for rendering the preferred media domain field
	 */
	public function media_domain_cb() {
		$media_domain = get_option( self::OPTION_MEDIA_DOMAIN );
		echo '<label for="cloudflare_stream_media_domain_0"><input type="radio" class="radio-option" name="cloudflare_stream_media_domain" id="cloudflare_stream_media_domain_0" value="cloudflarestream.com" ' . checked( "cloudflarestream.com", $media_domain, false ) . ' >cloudflarestream.com (default)</label>'
		   . '<label for="cloudflare_stream_media_domain_"><input type="radio" class="radio-option" name="cloudflare_stream_media_domain" id="cloudflare_stream_media_domain_1" value="videodelivery.net" ' . checked( "videodelivery.net", $media_domain, false ) . ' >videodelivery.net</label>';

		// The account subdomain option is only presented if it was able to be retrieved from the API.
		$account_subdomain = self::get_account_subdomain();
		if ( $account_subdomain ) {
			echo '<label for="cloudflare_stream_media_domain_"><input type="radio" class="radio-option" name="cloudflare_stream_media_domain" id="cloudflare_stream_media_domain_2" value="' . $account_subdomain . '" ' . checked( $account_subdomain, $media_domain, false ) . ' >' . $account_subdomain . ' (<a href="https://community.cloudflare.com/t/upcoming-domain-change-to-ensure-delivery-of-your-video-content/405842" target="_blank">more information</a>)</label>';
		}

		echo '<small class="form-text text-muted">' . esc_html__( 'Set which Cloudflare domain is used by your users, to access video content. Changing this may require an update to your sites Content Security Policy.', 'cloudflare-stream-wordpress' ) . '</small>';
	}

	/**
	 * Setup Admin Menu Options & Settings.
	 *
	 * @uses is_super_admin, add_submenu_page
	 * @action network_admin_menu, admin_menu
	 * @return null
	 */
	public function action_admin_menu() {
		if ( ! is_super_admin() ) {
			return false;
		}

		// Defaults
		add_option( self::OPTION_SIGNED_URLS, true );
		add_option( self::OPTION_SIGNED_URLS_DURATION, 60 );
		add_option( self::OPTION_MEDIA_DOMAIN, 'cloudflarestream.com' );

		// Completely remove old less secure API credentials if they exist.
		if ( get_option( self::OPTION_API_KEY ) !== false ) {
			delete_option( self::OPTION_API_EMAIL );
			delete_option( self::OPTION_API_KEY );
		}

		add_options_page( __( 'Cloudflare Stream', 'cloudflare-stream-wordpress' ), __( 'Cloudflare Stream', 'cloudflare-stream-wordpress' ), 'manage_options', 'cloudflare-stream', array( $this, 'settings_page' ) );
	}

	/**
	 * Displays all messages registered to 'cloudflare-stream-settings'.
	 */
	public function settings_errors_admin_notices() {
		settings_errors( 'cloudflare-stream-settings' );
	}

	/**
	 * Displays all messages registered to 'cloudflare-stream-settings'.
	 */
	public function onboarding_admin_notices() {
		global $pagenow;

		$screen = get_current_screen();

		if ( ! in_array( $screen->id, array( 'plugins', 'settings_page_cloudflare-stream' ), true ) ) {
			return;
		}

		if ( self::is_configured() ) {
			if ( 'settings_page_cloudflare-stream' === $screen->id && false === self::test_api_keys() ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php 
						echo sprintf( 
							wp_kses( 
								__( 'Cloudflare Stream API details are incorrect. Visit the <a href="%s"/>settings page</a> to get started.', 'cloudflare-stream-wordpress' ),
								array(  'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) )
						);
					?></p>
				</div>
				<?php
				return;
			} else {
				return;
			}
			return;
		} elseif ( 'settings_page_cloudflare-stream' !== $screen->id ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p><?php 
						echo sprintf( 
							wp_kses( 
								__( 'Cloudflare Stream is not configured. Visit the <a href="%s"/>settings page</a> to get started.', 'cloudflare-stream-wordpress' ),
								array(  'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) )
						);
				?></p>
			</div>
			<?php
		}
	}

	/**
	 * Try to fetch and save the Cloudflare Account ID using Zone ID.
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
	 * @since 1.0.9
	 */
	public function get_account_id() {
		$api_token   = get_option( self::OPTION_API_TOKEN );
		$api_zone_id = get_option( self::OPTION_API_ZONE_ID );

		if ( !empty ( $api_token ) && !empty ( $api_zone_id )  ) {
			$api = Cloudflare_Stream_API::instance();
			return $api->get_account_id( true );
		}
		return false;
	}

	/**
	 * Make a test call to an endpoint to test the API keys.
	 *
	 * @since 1.0.0
	 */
	public function test_api_keys() {
		$api    = Cloudflare_Stream_API::instance();
		$videos = $api->get_videos();
		return ( count( $videos->errors ) <= 0 ) ? true : false;
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
			<h1><?php esc_html_e( 'Cloudflare Stream Settings', 'cloudflare-stream-wordpress' ); ?></h1>
			<form method="post" action="options.php">
			<?php
				settings_fields( self::SETTING_GROUP );
				wp_nonce_field( 'cloudflare-stream-save-settings', self::NONCE );
				do_settings_sections( 'cloudflare-stream' );
				submit_button();
			?>
			</form>
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
		echo sprintf( 
			wp_kses( 
				__( 'To use the Cloudflare Stream for WordPress plugin, enter your Cloudflare account information below. If you need help getting started, <a target="_blank" href="%s" title="Cloudflare Stream for WordPress README">click here.</a>', 'cloudflare-stream-wordpress' ),
				array(  'a' => array( 'href' => array(), 'target' => array( '_blank' ) ) )
			),
			esc_url( 'https://github.com/B-Interactive/cloudflare-stream-wordpress#readme' )
		);
		echo '</p>';
	}

	/**
	 * Helper function for determining if the user has attempted to setup their API keys.
	 */
	public static function is_configured() {
		$api_token   = get_option( self::OPTION_API_TOKEN );
		$api_account = get_option( self::OPTION_API_ACCOUNT );

		return ( $api_token && $api_account );
	}
}
Cloudflare_Stream_Settings::instance();
