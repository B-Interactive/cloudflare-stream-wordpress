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
	const SETTING_SECTION_PLAYER      = 'cloudflare_stream_settings_player';
	const SETTING_SECTION_REPORTING   = 'cloudflare_stream_settings_reporting';
	const OPTION_API_TOKEN            = 'cloudflare_stream_api_token';
	const OPTION_API_ZONE_ID          = 'cloudflare_stream_api_zone_id'; // Deprecated.
	const OPTION_API_KEY              = 'cloudflare_stream_api_key';
	const OPTION_API_EMAIL            = 'cloudflare_stream_api_email';
	const OPTION_API_ACCOUNT          = 'cloudflare_stream_api_account';
	const OPTION_SIGNED_URLS          = 'cloudflare_stream_signed_urls';
	const OPTION_SIGNED_URLS_DURATION = 'cloudflare_stream_signed_urls_duration';
	const OPTION_MEDIA_DOMAIN         = 'cloudflare_stream_media_domain';
	const OPTION_POSTER_TIME          = 'cloudflare_stream_poster_time';
	const STANDARD_MEDIA_DOMAINS      = array( 'cloudflarestream.com', 'videodelivery.net' );

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
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( $this, 'action_admin_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'action_admin_init' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'cloudflare_stream_admin_enqueue_styles' ) );
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
	}

	/** API CONFIGURATION CALLBACKS **/

	/**
	 * Callback for rendering the API Account ID settings field
	 */
	public function api_account_cb() {
		$api_account = get_option( self::OPTION_API_ACCOUNT );
		if ( empty( $api_account ) ) {
			$api_account = self::get_account_id();
		}
		echo '<input type="text" class="regular-text" name="cloudflare_stream_api_account" id="cloudflare_stream_api_account" value="' . esc_attr( $api_account ) . '" autocomplete="on"> '
		. '<small class="form-text text-muted">' . esc_html__( 'Cloudflare > [domain] > Overview > [scroll down to API section on the right and copy the Account ID].', 'cloudflare-stream' ) . '</small>';
	}

	/**
	 * Callback for rendering the API Token settings field
	 */
	public function api_token_cb() {
		$api_token = get_option( self::OPTION_API_TOKEN );
		echo '<input type="password" class="regular-text" name="cloudflare_stream_api_token" id="cloudflare_stream_api_token" value="' . esc_attr( $api_token ) . '" autocomplete="off">'
		. '<small class="form-text text-muted">'
		. esc_html__( 'Cloudflare > My Profile > API Tokens > API Tokens > [Create Token]', 'cloudflare-stream' ) . '</small>'
		. '<small class="form-text text-muted">' . esc_html__( 'Must have permission for: Account - Stream:Edit', 'cloudflare-stream' ) . '</small>';
	}

	/**
	 * Callback for rendering the use signed URLs field
	 */
	public function api_signed_urls_cb() {
		$signed_urls = get_option( self::OPTION_SIGNED_URLS );
		echo '<label><input type="checkbox" class="regular-text" name="cloudflare_stream_signed_urls" id="cloudflare_stream_signed_urls" value="1"' . checked( $signed_urls, true, false ) . '>' . esc_html__( 'Protects video links from being copied, by creating a unique temporary URL.', 'cloudflare-stream' ) . '</label>'
		. '<small class="form-text text-muted">' . esc_html__( 'For best protection, also set each video to only be accessible via signed URLs in your Cloudflare Stream dashboard.', 'cloudflare-stream' ) . '</small>';
	}

	/**
	 * Callback for rendering the signed URLs duration field
	 */
	public function api_signed_urls_duration_cb() {
		$signed_urls_duration = get_option( self::OPTION_SIGNED_URLS_DURATION );
		echo '<label for="cloudflare_stream_signed_urls_duration"><input type="number" class="regular-text" name="cloudflare_stream_signed_urls_duration" id="cloudflare_stream_signed_urls_duration" value="' . esc_attr( intval( $signed_urls_duration ) ) . '" autocomplete="off"> minutes</label>'
		. '<small class="form-text text-muted">' . esc_html__( 'Sets how long the unique signed URL/token remains accessible for, in minutes.', 'cloudflare-stream' ) . '</small>';
	}

	/**
	 * Callback for rendering the preferred media domain field
	 */
	public function media_domain_cb() {
		$media_domain           = get_option( self::OPTION_MEDIA_DOMAIN );
		$num_domains            = count( self::STANDARD_MEDIA_DOMAINS );
		$existing_custom_domain = true; // Placeholder value, but will be confirmed below.

		for ( $i = 0; $i < $num_domains; $i++ ) {
			$default_text = 0 === $i ? esc_html__( ' (default)', 'cloudflare-stream' ) : '';
			echo '<label for="cloudflare_stream_media_domain_' . esc_attr( $i ) . '">'
			. '<input type="radio" class="radio-option" name="cloudflare_stream_media_domain" id="cloudflare_stream_media_domain_' . esc_attr( $i ) . '" value="' . esc_html( self::STANDARD_MEDIA_DOMAINS[ $i ] ) . '" ' . checked( self::STANDARD_MEDIA_DOMAINS[ $i ], $media_domain, false ) . ' >'
			. esc_html( self::STANDARD_MEDIA_DOMAINS[ $i ] ) . esc_html( $default_text ) . '</label>';

			if ( self::STANDARD_MEDIA_DOMAINS[ $i ] === $media_domain ) {
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

		echo '<small class="form-text text-muted">' . esc_html__( 'Set which Cloudflare domain is used by your users, to access video content. Changing this may require an update to your sites Content Security Policy.', 'cloudflare-stream' ) . '</small>';
	}

	/** PLAYER SETTINGS CALLBACKS **/

	/**
	 * Callback for rendering the poster time field
	 */
	public function poster_time_cb() {
		$poster_time = get_option( self::OPTION_POSTER_TIME );
		echo '<label for="cloudflare_stream_poster_time"><input type="number" class="regular-text" name="cloudflare_stream_poster_time" id="cloudflare_stream_poster_time" value="' . esc_attr( intval( $poster_time ) ) . '" autocomplete="off"> seconds</label>'
		. '<small class="form-text text-muted">' . esc_html__( 'A default time in seconds, of where to reference the video thumbnail from in any given video. Can be overridden by shortcode argument postertime.  eg: postertime="10s".', 'cloudflare-stream' ) . '</small>';
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

		// Defaults.
		add_option( self::OPTION_SIGNED_URLS, true );
		add_option( self::OPTION_SIGNED_URLS_DURATION, 60 );
		add_option( self::OPTION_MEDIA_DOMAIN, self::STANDARD_MEDIA_DOMAINS[0] );
		add_option( self::OPTION_POSTER_TIME, 0 );

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
					<p>
					<?php
						printf(
							wp_kses(
								/* translators: %s: search term */
								__( 'Cloudflare Stream API details are incorrect. Visit the <a href="%s"/>settings page</a> to get started.', 'cloudflare-stream' ),
								array( 'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) )
						);
					?>
					</p>
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
				<p>
				<?php
					printf(
						wp_kses(
							/* translators: %s: search term */
							__( 'Cloudflare Stream is not configured. Visit the <a href="%s"/>settings page</a> to get started.', 'cloudflare-stream' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) )
					);
				?>
				</p>
			</div>
			<?php
		}
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
		$api_token   = get_option( self::OPTION_API_TOKEN );
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
			<h1><?php esc_html_e( 'Cloudflare Stream Settings', 'cloudflare-stream' ); ?></h1>
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
		printf(
			wp_kses(
				/* translators: %s: search term */
				__( 'To use the Cloudflare Stream for WordPress plugin, enter your Cloudflare account information below. If you need help getting started, <a target="_blank" href="%s" title="Cloudflare Stream for WordPress README">click here.</a>', 'cloudflare-stream' ),
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
	}

	/**
	 * Render Player Settings Section
	 *
	 * @since 1.9.4
	 */
	public function settings_section_player() {
		echo '<p>';
		echo esc_html__( 'Global settings for the player.  Some of these can be overridden on a per video basis with shortcode arguments.', 'cloudflare-stream' );
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
