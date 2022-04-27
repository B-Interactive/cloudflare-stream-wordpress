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
	const OPTION_API_KEY            = 'cloudflare_stream_api_key';
	const OPTION_API_EMAIL          = 'cloudflare_stream_api_email';
	const OPTION_API_ACCOUNT        = 'cloudflare_stream_api_account';
	const OPTION_VIDEO_TOKEN_DURATION = 'cloudflare_stream_video_token_duration';

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
		register_setting( self::SETTING_GROUP, self::OPTION_API_TOKEN );
		register_setting( self::SETTING_GROUP, self::OPTION_API_ZONE_ID );

		add_settings_section(
			self::SETTING_SECTION_GENERAL,
			'API Configuration',
			array( $this, 'settings_section_api_keys' ),
			self::SETTING_PAGE
		);

			add_settings_field(
				self::OPTION_API_TOKEN,
				'API Token',
				array( $this, 'api_token_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_API_ZONE_ID,
				'API Zone ID',
				array( $this, 'api_zone_id_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

		add_action( 'admin_notices', array( $this, 'settings_errors_admin_notices' ) );
		add_action( 'admin_notices', array( $this, 'onboarding_admin_notices' ) );
	}

	/**
	 * Callback for rendering the API Token settings field
	 */
	public function api_token_cb() {
		$api_token = get_option( self::OPTION_API_TOKEN );
		echo '<input type="password" class="regular-text" name="cloudflare_stream_api_token" id="cloudflare_stream_api_token" value="' . esc_attr( $api_token ) . '" autocomplete="off"> ';
		echo '<br><small class="form-text text-muted">Cloudflare > My Profile > API Tokens > API Tokens > [Create Token]</small>';
		echo '<br><small class="form-text text-muted">Must have permission for: Account - Stream:Edit</small>';
	}

	/**
	 * Callback for rendering the API Zone ID settings field
	 */
	public function api_zone_id_cb() {
		$api_zone_id = get_option( self::OPTION_API_ZONE_ID );
		echo '<input type="text" class="regular-text" name="cloudflare_stream_api_zone_id" id="cloudflare_stream_api_zone_id" value="' . esc_attr( $api_zone_id ) . '" autocomplete="off"> ';
		echo '<br><small class="form-text text-muted">Cloudflare > [domain] > Overview > [scroll down to API section on the right and copy the Zone ID].</small>';
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

		// Completely remove old less secure API credentials if they exist.
		if ( get_option( self::OPTION_API_KEY ) !== false ) {
			delete_option( self::OPTION_API_EMAIL );
			delete_option( self::OPTION_API_KEY );
			delete_option( self::OPTION_API_ACCOUNT );
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
					<p>Cloudflare Stream API details are incorrect. Visit to  <a href="<?php echo esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) ); ?>"/>settings page</a> to get started.</p>
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
				<p>Cloudflare Stream is not configured. Visit to  <a href="<?php echo esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) ); ?>"/>settings page</a> to get started.</p>
			</div>
			<?php
		}
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
		echo '<p>To use the Cloudflare Stream for WordPress plugin, enter your Cloudflare account information below. If you need help getting started, <a target="_blank" href="' . esc_url( 'https://github.com/B-Interactive/cloudflare-stream-wordpress#readme' ) . '" title="Cloudflare Stream for WordPress README">click here.</a><p>';
	}

	/**
	 * Helper function for determining if the user has attempted to setup their API keys.
	 */
	public static function is_configured() {
		$api_token   = get_option( self::OPTION_API_TOKEN );
		$api_zone_id = get_option( self::OPTION_API_ZONE_ID );

		return ( $api_token && $api_zone_id );
	}
}
Cloudflare_Stream_Settings::instance();
