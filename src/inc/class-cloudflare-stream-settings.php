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
	const OPTION_SIGNING_KEY_ID       = 'cloudflare_stream_signing_key_id';
	const OPTION_SIGNING_KEY_PEM      = 'cloudflare_stream_signing_key_pem';
	const OPTION_MEDIA_DOMAIN         = 'cloudflare_stream_media_domain';
	const OPTION_POSTER_TIME          = 'cloudflare_stream_poster_time';
	const STANDARD_MEDIA_DOMAINS      = array( 'cloudflarestream.com', 'videodelivery.net' );
	const ADMIN_ACTION_SIGNING_KEY    = 'cloudflare_stream_signing_key';
	const TRANSIENT_SIGNING_KEY_REVEAL = 'cfstream_sk_reveal_';

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
		add_action( 'admin_post_' . self::ADMIN_ACTION_SIGNING_KEY, array( $this, 'handle_signing_key_action' ) );
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
		register_setting(
			self::SETTING_GROUP,
			self::OPTION_SIGNED_URLS_DURATION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_signed_urls_duration' ),
			)
		);
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
				'signing_key',
				esc_html__( 'Signing Key', 'cloudflare-stream' ),
				array( $this, 'signing_key_cb' ),
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
		echo '<label for="cloudflare_stream_signed_urls_duration"><input type="number" class="regular-text" name="cloudflare_stream_signed_urls_duration" id="cloudflare_stream_signed_urls_duration" min="1" max="1440" value="' . esc_attr( intval( $signed_urls_duration ) ) . '" autocomplete="off"> minutes</label>'
		. '<small class="form-text text-muted">' . esc_html__( 'Sets how long the unique signed URL/token remains accessible for, in minutes (1 to 1440).', 'cloudflare-stream' ) . '</small>';
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
	 * Whether signing key id/pem come from PHP constants.
	 *
	 * @return bool
	 */
	private function signing_key_from_constants() {
		$id_set  = defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) && is_string( CLOUDFLARE_STREAM_SIGNING_KEY_ID ) && '' !== CLOUDFLARE_STREAM_SIGNING_KEY_ID;
		$pem_set = defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) && is_string( CLOUDFLARE_STREAM_SIGNING_KEY_PEM ) && '' !== CLOUDFLARE_STREAM_SIGNING_KEY_PEM;

		return $id_set && $pem_set;
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
	 * Stash key id + PEM for short-lived setup (user must choose how to keep it).
	 *
	 * @param string $key_id  Signing key id.
	 * @param string $pem     PEM as returned (base64 or PEM text).
	 * @param string $context setup|migrate
	 */
	private function stash_signing_key_reveal( $key_id, $pem, $context = 'setup' ) {
		$context = ( 'migrate' === $context ) ? 'migrate' : 'setup';

		set_transient(
			$this->signing_key_reveal_transient_name(),
			array(
				'id'      => (string) $key_id,
				'pem'     => (string) $pem,
				'context' => $context,
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Read a pending setup/migrate payload without deleting it.
	 *
	 * @return array{id:string,pem:string,context:string}|null
	 */
	private function get_signing_key_reveal() {
		$payload = get_transient( $this->signing_key_reveal_transient_name() );

		if ( ! is_array( $payload ) ) {
			return null;
		}

		$id  = isset( $payload['id'] ) ? (string) $payload['id'] : '';
		$pem = isset( $payload['pem'] ) ? (string) $payload['pem'] : '';

		if ( '' === $id || '' === $pem ) {
			return null;
		}

		$context = isset( $payload['context'] ) ? (string) $payload['context'] : 'setup';
		if ( 'migrate' !== $context ) {
			$context = 'setup';
		}

		return array(
			'id'      => $id,
			'pem'     => $pem,
			'context' => $context,
		);
	}

	/**
	 * Drop the user-specific setup/migrate transient.
	 */
	private function clear_signing_key_reveal() {
		delete_transient( $this->signing_key_reveal_transient_name() );
	}

	/**
	 * Whether wp_options holds a signing key id and PEM (even if constants override).
	 *
	 * @return bool
	 */
	private function db_has_signing_key_options() {
		$id  = get_option( self::OPTION_SIGNING_KEY_ID, '' );
		$pem = get_option( self::OPTION_SIGNING_KEY_PEM, '' );

		return is_string( $id ) && '' !== $id && is_string( $pem ) && '' !== $pem;
	}

	/**
	 * Remove signing key id/PEM from WordPress options.
	 */
	private function delete_db_signing_key_options() {
		delete_option( self::OPTION_SIGNING_KEY_ID );
		delete_option( self::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * Ready-to-paste wp-config.php defines for a signing key.
	 *
	 * @param string $key_id Signing key id.
	 * @param string $pem    PEM value as stored / usable in the constant.
	 * @return string
	 */
	private function build_signing_key_wpconfig_snippet( $key_id, $pem ) {
		return 'define( \'CLOUDFLARE_STREAM_SIGNING_KEY_ID\', ' . var_export( (string) $key_id, true ) . " );\n"
			. 'define( \'CLOUDFLARE_STREAM_SIGNING_KEY_PEM\', ' . var_export( (string) $pem, true ) . ' );';
	}

	/**
	 * One-time setup panel: snippet only, plus explicit keep choices.
	 *
	 * @param string $key_id  Signing key id.
	 * @param string $pem     PEM value.
	 * @param string $context setup|migrate
	 */
	private function render_signing_key_setup_panel( $key_id, $pem, $context = 'setup' ) {
		$context = ( 'migrate' === $context ) ? 'migrate' : 'setup';
		$snippet = $this->build_signing_key_wpconfig_snippet( $key_id, $pem );
		$action  = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="notice notice-warning cloudflare-stream-signing-key-reveal" style="padding:12px 16px;">';
		echo '<p><strong>' . esc_html__( 'Signing key setup', 'cloudflare-stream' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Copy the snippet below into wp-config.php (above the line that says "That\'s all, stop editing!"). This screen is only available for a short time, and the private key will not be shown again after you finish.', 'cloudflare-stream' ) . '</p>';

		echo '<p><label for="cloudflare_stream_reveal_snippet"><strong>' . esc_html__( 'Paste into wp-config.php', 'cloudflare-stream' ) . '</strong></label></p>';
		echo '<textarea class="large-text code" id="cloudflare_stream_reveal_snippet" rows="8" readonly="readonly" onclick="this.select();">' . esc_textarea( $snippet ) . '</textarea>';

		echo '<p style="margin-top:1em;"><strong>' . esc_html__( 'Choose how to keep this key', 'cloudflare-stream' ) . '</strong></p>';

		if ( 'setup' === $context ) {
			echo '<form method="post" action="' . $action . '" style="margin-bottom:0.75em;">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
			echo '<input type="hidden" name="signing_key_do" value="confirm_constants">';
			wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
			submit_button( __( 'I have pasted this into wp-config.php', 'cloudflare-stream' ), 'primary', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Most secure. We check that the constants work, remove any database copy, and discard this temporary copy.', 'cloudflare-stream' ) . '</p>';
			echo '</form>';

			echo '<form method="post" action="' . $action . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
			echo '<input type="hidden" name="signing_key_do" value="store_db">';
			wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
			submit_button( __( 'Store in the WordPress database', 'cloudflare-stream' ), 'secondary', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Less secure. Saves the key in WordPress options so signed playback works without constants. You can move it to wp-config.php later.', 'cloudflare-stream' ) . '</p>';
			echo '</form>';
		} else {
			echo '<form method="post" action="' . $action . '" style="margin-bottom:0.75em;">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
			echo '<input type="hidden" name="signing_key_do" value="confirm_moved">';
			wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
			submit_button( __( 'I moved it to wp-config.php', 'cloudflare-stream' ), 'primary', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Checks that the constants work, then removes the key from the database.', 'cloudflare-stream' ) . '</p>';
			echo '</form>';

			echo '<form method="post" action="' . $action . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
			echo '<input type="hidden" name="signing_key_do" value="dismiss">';
			wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
			submit_button( __( 'Keep in the database', 'cloudflare-stream' ), 'secondary', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Hides this snippet and leaves the key stored in WordPress options.', 'cloudflare-stream' ) . '</p>';
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Callback for signing key status (actions render outside the options form).
	 */
	public function signing_key_cb() {
		$api        = Cloudflare_Stream_API::instance();
		$has_key    = $api->has_signing_key();
		$from_const = $this->signing_key_from_constants();
		$db_has     = $this->db_has_signing_key_options();
		$key_id     = $api->get_signing_key_id();

		if ( $has_key ) {
			$source = $from_const
				? __( 'from PHP constants', 'cloudflare-stream' )
				: __( 'stored in WordPress options', 'cloudflare-stream' );
			echo '<p><strong>' . esc_html__( 'Key on file', 'cloudflare-stream' ) . '</strong> (' . esc_html( $source ) . ')</p>';
			if ( '' !== $key_id ) {
				echo '<p><code>' . esc_html( $key_id ) . '</code></p>';
			}
			echo '<p class="description">' . esc_html__( 'Playback tokens are signed locally with RS256. The private key stays on the server and is never sent to the browser.', 'cloudflare-stream' ) . '</p>';
		} else {
			echo '<p><strong>' . esc_html__( 'No signing key configured', 'cloudflare-stream' ) . '</strong></p>';
			echo '<p class="description">' . esc_html__( 'Without a key, signed playback still works via the Cloudflare /token API (one request per cache miss). For production, prefer constants CLOUDFLARE_STREAM_SIGNING_KEY_ID and CLOUDFLARE_STREAM_SIGNING_KEY_PEM in wp-config.php.', 'cloudflare-stream' ) . '</p>';
		}

		if ( $from_const && $db_has ) {
			echo '<p class="notice notice-warning inline" style="margin:0.5em 0;padding:8px 12px;"><strong>' . esc_html__( 'Database still has a signing key copy.', 'cloudflare-stream' ) . '</strong> '
				. esc_html__( 'Constants are active now, but if you remove them later the old database key will come back. Use Remove stored signing key below.', 'cloudflare-stream' ) . '</p>';
		} elseif ( $from_const ) {
			echo '<p class="description">' . esc_html__( 'Constants are set and override any database value. Change or remove them in wp-config.php to use a different key.', 'cloudflare-stream' ) . '</p>';
		} elseif ( $db_has ) {
			echo '<p class="description">' . esc_html__( 'Use the buttons below the Save Changes form to show a wp-config.php snippet or remove the stored key.', 'cloudflare-stream' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Use Generate signing key below the Save Changes form. You will copy a wp-config.php snippet, then choose constants (preferred) or database storage.', 'cloudflare-stream' ) . '</p>';
		}
	}

	/**
	 * Render generate/clear/reveal controls outside the main settings form (avoids nested forms).
	 */
	public function render_signing_key_actions() {
		// Setup panel owns the next step while a transient is pending.
		if ( null !== $this->get_signing_key_reveal() ) {
			return;
		}

		$from_const = $this->signing_key_from_constants();
		$db_has     = $this->db_has_signing_key_options();
		$api        = Cloudflare_Stream_API::instance();
		$has_key    = $api->has_signing_key();
		$action     = esc_url( admin_url( 'admin-post.php' ) );

		if ( $from_const && ! $db_has ) {
			return;
		}

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Signing key actions', 'cloudflare-stream' ) . '</h2>';

		if ( $from_const && $db_has ) {
			echo '<form method="post" action="' . $action . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
			echo '<input type="hidden" name="signing_key_do" value="clear">';
			wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
			submit_button( __( 'Remove stored signing key', 'cloudflare-stream' ), 'delete', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Deletes the leftover key from WordPress options only. Constants keep working. Revoke the key in Cloudflare if it should stop working entirely.', 'cloudflare-stream' ) . '</p>';
			echo '</form>';
			return;
		}

		if ( $has_key && $db_has ) {
			echo '<form method="post" action="' . $action . '" style="margin-bottom:1em;">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
			echo '<input type="hidden" name="signing_key_do" value="reveal">';
			wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
			submit_button( __( 'Show wp-config snippet', 'cloudflare-stream' ), 'secondary', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Shows a ready-to-paste wp-config.php snippet for a short time so you can move the key out of the database.', 'cloudflare-stream' ) . '</p>';
			echo '</form>';

			echo '<form method="post" action="' . $action . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
			echo '<input type="hidden" name="signing_key_do" value="clear">';
			wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
			submit_button( __( 'Remove stored signing key', 'cloudflare-stream' ), 'delete', 'submit', false );
			echo '<p class="description">' . esc_html__( 'Removes the key from WordPress options only. Revoke it in the Cloudflare dashboard if it should no longer work.', 'cloudflare-stream' ) . '</p>';
			echo '</form>';
			return;
		}

		echo '<form method="post" action="' . $action . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_ACTION_SIGNING_KEY ) . '">';
		echo '<input type="hidden" name="signing_key_do" value="generate">';
		wp_nonce_field( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );
		submit_button( __( 'Generate signing key', 'cloudflare-stream' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html__( 'Creates a key via the Cloudflare API. Nothing is saved until you paste constants or choose database storage on the next screen.', 'cloudflare-stream' ) . '</p>';
		echo '</form>';
	}

	/**
	 * Redirect helper with a short notice code for admin_notices.
	 *
	 * @param string $code Notice code.
	 */
	private function redirect_signing_key_notice( $code ) {
		$redirect = add_query_arg(
			array(
				'page'               => 'cloudflare-stream',
				'cfstream_sk_notice' => sanitize_key( $code ),
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
	 * Generate, finish setup, migrate, or clear signing key (manage_options + nonce).
	 */
	public function handle_signing_key_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'cloudflare-stream' ), 403 );
		}

		check_admin_referer( self::ADMIN_ACTION_SIGNING_KEY, self::NONCE );

		$do  = isset( $_POST['signing_key_do'] ) ? sanitize_text_field( wp_unslash( $_POST['signing_key_do'] ) ) : '';
		$api = Cloudflare_Stream_API::instance();

		if ( 'clear' === $do ) {
			$this->delete_db_signing_key_options();
			$this->clear_signing_key_reveal();
			$this->redirect_signing_key_notice( 'cleared' );
		}

		if ( 'dismiss' === $do ) {
			$this->clear_signing_key_reveal();
			$this->redirect_signing_key_notice( 'dismissed' );
		}

		if ( 'confirm_constants' === $do || 'confirm_moved' === $do ) {
			if ( ! $this->constants_signing_key_ready() ) {
				$this->redirect_signing_key_notice( 'constants_missing' );
			}

			$this->delete_db_signing_key_options();
			$this->clear_signing_key_reveal();
			$this->redirect_signing_key_notice( 'confirm_constants' === $do ? 'constants_ok' : 'moved' );
		}

		if ( 'store_db' === $do ) {
			$pending = $this->get_signing_key_reveal();
			if ( null === $pending || 'setup' !== $pending['context'] ) {
				$this->redirect_signing_key_notice( 'store_failed' );
			}

			update_option( self::OPTION_SIGNING_KEY_ID, $pending['id'], false );
			update_option( self::OPTION_SIGNING_KEY_PEM, $pending['pem'], false );

			if ( ! $api->has_signing_key() ) {
				$this->delete_db_signing_key_options();
				$this->redirect_signing_key_notice( 'invalid' );
			}

			$this->clear_signing_key_reveal();
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
			$pem_raw = trim( $result->pem );

			if ( '' === $key_id || '' === $pem_raw ) {
				$this->redirect_signing_key_notice( 'invalid' );
			}

			// Do not write options yet; user chooses constants or database next.
			$this->stash_signing_key_reveal( $key_id, $pem_raw, 'setup' );
			$this->redirect_signing_key_notice( 'generated' );
		}

		if ( 'reveal' === $do ) {
			if ( $this->signing_key_from_constants() || ! $this->db_has_signing_key_options() ) {
				$this->redirect_signing_key_notice( 'reveal_failed' );
			}

			$key_id = get_option( self::OPTION_SIGNING_KEY_ID, '' );
			$pem    = get_option( self::OPTION_SIGNING_KEY_PEM, '' );

			$this->stash_signing_key_reveal( $key_id, $pem, 'migrate' );
			$this->redirect_signing_key_notice( 'reveal' );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag after redirect.
		if ( empty( $_GET['page'] ) || 'cloudflare-stream' !== $_GET['page'] || empty( $_GET['cfstream_sk_notice'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = sanitize_key( wp_unslash( $_GET['cfstream_sk_notice'] ) );

		$messages = array(
			'generated'          => array(
				'type' => 'success',
				'text' => __( 'Signing key created. Copy the snippet below, then choose constants (preferred) or database storage. Nothing is saved in the database until you choose.', 'cloudflare-stream' ),
			),
			'reveal'             => array(
				'type' => 'success',
				'text' => __( 'wp-config.php snippet is shown below for a short time. After you paste it, confirm so the database copy can be removed.', 'cloudflare-stream' ),
			),
			'constants_ok'       => array(
				'type' => 'success',
				'text' => __( 'Constants look good. Any database copy of the signing key was removed.', 'cloudflare-stream' ),
			),
			'moved'              => array(
				'type' => 'success',
				'text' => __( 'Constants look good. The signing key was removed from WordPress options.', 'cloudflare-stream' ),
			),
			'stored'             => array(
				'type' => 'success',
				'text' => __( 'Signing key stored in WordPress options. Constants in wp-config.php are still preferred for production.', 'cloudflare-stream' ),
			),
			'cleared'            => array(
				'type' => 'success',
				'text' => __( 'Stored signing key removed from WordPress options.', 'cloudflare-stream' ),
			),
			'dismissed'          => array(
				'type' => 'success',
				'text' => __( 'Snippet hidden. The signing key remains stored in WordPress options.', 'cloudflare-stream' ),
			),
			'constants_missing'  => array(
				'type' => 'error',
				'text' => __( 'Could not detect usable signing key constants yet. Add the defines to wp-config.php, reload PHP if needed, then try again. The setup snippet is still available for a short time.', 'cloudflare-stream' ),
			),
			'generate_failed'    => array(
				'type' => 'error',
				'text' => __( 'Could not create a signing key. Check the API token and Account ID.', 'cloudflare-stream' ),
			),
			'invalid'            => array(
				'type' => 'error',
				'text' => __( 'That signing key could not be validated. Nothing was kept in the database.', 'cloudflare-stream' ),
			),
			'store_failed'       => array(
				'type' => 'error',
				'text' => __( 'No pending signing key to store. Generate a key again.', 'cloudflare-stream' ),
			),
			'reveal_failed'      => array(
				'type' => 'error',
				'text' => __( 'No signing key is stored in WordPress options to show.', 'cloudflare-stream' ),
			),
			'constants'          => array(
				'type' => 'error',
				'text' => __( 'Signing key constants are already defined, so that action was skipped.', 'cloudflare-stream' ),
			),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ]['type'] ),
			esc_html( $messages[ $code ]['text'] )
		);
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
		$reveal = null;
		if ( current_user_can( 'manage_options' ) ) {
			$reveal = $this->get_signing_key_reveal();
		}
		?>
		<div class="wrap">
		<div id="icon-options-cloudflare-stream" class="icon32"></div>
			<h1><?php esc_html_e( 'Cloudflare Stream Settings', 'cloudflare-stream' ); ?></h1>
			<?php
			if ( is_array( $reveal ) ) {
				$this->render_signing_key_setup_panel( $reveal['id'], $reveal['pem'], $reveal['context'] );
			}
			?>
			<form method="post" action="options.php">
			<?php
				settings_fields( self::SETTING_GROUP );
				wp_nonce_field( 'cloudflare-stream-save-settings', self::NONCE );
				do_settings_sections( 'cloudflare-stream' );
				submit_button();
			?>
			</form>
			<?php $this->render_signing_key_actions(); ?>
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
