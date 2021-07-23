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

	const NONCE                     = 'cloudflare-stream';
	const SETTING_PAGE              = 'cloudflare-stream';
	const SETTING_GROUP             = 'cloudflare_stream';
	const SETTING_SECTION_GENERAL   = 'cloudflare_stream_settings_general';
	const SETTING_SECTION_REPORTING = 'cloudflare_stream_settings_reporting';
	const OPTION_API_KEY            = 'cloudflare_stream_api_key';
	const OPTION_API_EMAIL          = 'cloudflare_stream_api_email';
	const OPTION_API_ACCOUNT        = 'cloudflare_stream_api_account';
	const OPTION_HEAP_ANALYTICS     = 'cloudflare_stream_reporting_opt_out';

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
		register_setting( self::SETTING_GROUP, self::OPTION_API_EMAIL );
		register_setting( self::SETTING_GROUP, self::OPTION_API_KEY );
		register_setting( self::SETTING_GROUP, self::OPTION_API_ACCOUNT );
		register_setting( self::SETTING_GROUP, self::OPTION_HEAP_ANALYTICS );

		add_settings_section(
			self::SETTING_SECTION_GENERAL,
			'API Configuration',
			array( $this, 'settings_section_api_keys' ),
			self::SETTING_PAGE
		);

			add_settings_field(
				self::OPTION_API_EMAIL,
				'API Email',
				array( $this, 'api_email_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_API_KEY,
				'API Key',
				array( $this, 'api_key_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

			add_settings_field(
				self::OPTION_API_ACCOUNT,
				'API Account ID',
				array( $this, 'api_account_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_GENERAL
			);

		add_settings_section(
			self::SETTING_SECTION_REPORTING,
			'Reporting',
			array( $this, 'settings_section_reporting' ),
			self::SETTING_PAGE
		);

			add_settings_field(
				self::OPTION_HEAP_ANALYTICS,
				'Opt out of Heap Analytics',
				array( $this, 'reporting_opt_out_cb' ),
				self::SETTING_PAGE,
				self::SETTING_SECTION_REPORTING
			);

		add_action( 'admin_notices', array( $this, 'settings_errors_admin_notices' ) );

		add_action( 'admin_notices', array( $this, 'onboarding_admin_notices' ) );

		add_action( 'admin_head', array( $this, 'admin_head' ) );
		add_action( 'admin_footer', array( $this, 'admin_footer' ), 1 );
	}

	/**
	 * Callback for rendering the API Email settings field
	 */
	public function api_email_cb() {
		$api_email = get_option( self::OPTION_API_EMAIL );
		echo '<input type="text" class="regular-text" name="cloudflare_stream_api_email" id="cloudflare_stream_api_email" value="' . esc_attr( $api_email ) . '" autocomplete="on"> ';
	}

	/**
	 * Callback for rendering the API Key settings field
	 */
	public function api_key_cb() {
		$api_key = get_option( self::OPTION_API_KEY );
		echo '<input type="password" class="regular-text" name="cloudflare_stream_api_key" id="cloudflare_stream_api_key" value="' . esc_attr( $api_key ) . '" autocomplete="off"> ';
	}

	/**
	 * Callback for rendering the API Account ID settings field
	 */
	public function api_account_cb() {
		$api_account = get_option( self::OPTION_API_ACCOUNT );
		echo '<input type="text" class="regular-text" name="cloudflare_stream_api_account" id="cloudflare_stream_api_account" value="' . esc_attr( $api_account ) . '" autocomplete="on"> ';
	}

	/**
	 * Callback for rendering the Reporting Opt Out settings field
	 */
	public function reporting_opt_out_cb() {
		$reporting_opt_out = get_option( self::OPTION_HEAP_ANALYTICS );
		echo '<input type="checkbox" class="regular-text" name="cloudflare_stream_reporting_opt_out" id="cloudflare_stream_reporting_opt_out" value="1"' . checked( $reporting_opt_out, true, false ) . '> ';
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
					<p>Cloudflare Stream API keys are incorrect. Visit to  <a href="<?php echo esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) ); ?>"/>settings page</a> to get started.</p>
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
		echo '<p>To use the Cloudflare Stream for WordPress plugin, enter your Cloudflare account information below. If you need help getting started, <a target="_blank" href="' . esc_url( 'https://support.cloudflare.com/hc/en-us/articles/360027744552' ) . '" title="Getting started with Cloudflare Stream">click here.</a><p>';
	}

	/**
	 * Render API Key Settings Section
	 *
	 * @since 1.0.0
	 */
	public function settings_section_reporting() {
		echo '<p>By choosing to share diagnostic and usage data, you help improve Cloudflare Stream for WordPress. You can opt out at any time by unchecking the box below.</p>';
	}

	/**
	 * Helper function for determining if the user has attempted to setup their API keys.
	 */
	public static function is_configured() {
		$api_email   = get_option( self::OPTION_API_EMAIL );
		$api_key     = get_option( self::OPTION_API_KEY );
		$api_account = get_option( self::OPTION_API_ACCOUNT );

		return ( $api_email && $api_key && $api_account );
	}

	/**
	 * Heap Analytics Tracking Script
	 */
	public function admin_head() {
		$screen = get_current_screen();

		if ( ! in_array( $screen->id, array( 'plugins', 'settings_page_cloudflare-stream' ), true ) ) {
			return;
		}

		$current_user = wp_get_current_user();

		$reporting_opt_out = get_option( self::OPTION_HEAP_ANALYTICS );
		if ( $reporting_opt_out ) {
			return;
		}

		wp_enqueue_script(
			'cloudflare-stream-heap-js',
			// Handle.
			plugins_url( 'heap.js', dirname( __FILE__ ) ),
			// Block.build.js: We register the block here. Built with Webpack.
			array( 'jquery' ),
			// Dependencies, defined above.
			filemtime( plugin_dir_path( __DIR__ ) . 'heap.js' ),
			// Version: filemtime — Gets file modification time.
			true
			// Enqueue the script in the footer.
		);

		wp_localize_script(
			'cloudflare-stream-heap-js',
			'cloudflareStream',
			array(
				'options' => array(
					'heap' => get_option( self::OPTION_HEAP_ANALYTICS ),
				),
				'nonce'   => wp_create_nonce( self::NONCE ),
			)
		);
	}

	/**
	 * Heap Analytics Tracking Script
	 */
	public function admin_footer() {
		$reporting_opt_out = get_option( self::OPTION_HEAP_ANALYTICS );
		if ( $reporting_opt_out ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! in_array( $screen->id, array( 'plugins', 'settings_page_cloudflare-stream' ), true ) ) {
			return;
		}
		?>
		<script type="text/javascript">
			jQuery( document ).ready( function() {
				cloudflareStream.analytics.logEvent( 'Stream WP Plugin - Settings Page Visit' );
			} );
		</script>
		<?php
	}
}
Cloudflare_Stream_Settings::instance();
