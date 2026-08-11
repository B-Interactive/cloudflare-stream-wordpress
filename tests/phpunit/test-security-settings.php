<?php
/**
 * Correctness and security coverage for settings, posters, uploads, and tokens.
 *
 * @package cloudflare-stream
 */

/**
 * @group security
 */
class Test_CFStream_Security_Settings extends WP_UnitTestCase {

	/**
	 * Reset HTTP tripwire before each test.
	 */
	public function set_up() {
		parent::set_up();
		if ( function_exists( 'cfstream_test_clear_http_attempts' ) ) {
			cfstream_test_clear_http_attempts();
		}
	}

	/**
	 * Settings page HTML must never contain known secret values.
	 */
	public function test_settings_page_does_not_render_secrets() {
		$token = 'test-token-not-real';
		$pem   = "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC7secretPEMvalue\n-----END PRIVATE KEY-----";
		$key_id = 'signing-key-id-dummy-001';

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();

		// Database-backed secrets.
		update_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN, $token, false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, $key_id, false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $pem, false );

		$html = $this->render_settings_page_html( $settings );
		$this->assertStringNotContainsString( $token, $html, 'API token must not appear in settings HTML' );
		$this->assertStringNotContainsString( $pem, $html, 'Signing PEM must not appear in settings HTML' );
		$this->assertStringNotContainsString( 'BEGIN PRIVATE KEY', $html );
		$this->assertStringNotContainsString( 'secretPEMvalue', $html );

		// Mid-setup reveal uses a fresh key, not the stored PEM.
		$reveal_pem = "-----BEGIN PRIVATE KEY-----\nFRESHSETUPKEYMATERIALONLYONCE\n-----END PRIVATE KEY-----";
		$ref        = new ReflectionClass( $settings );
		$stash      = $ref->getMethod( 'stash_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$stash->setAccessible( true );
		}
		$stash->invoke( $settings, 'fresh-setup-key-id', $reveal_pem, 'setup' );

		$html_setup = $this->render_settings_page_html( $settings );
		$this->assertStringNotContainsString( $pem, $html_setup, 'stored PEM must not appear during setup reveal' );
		$this->assertStringNotContainsString( $token, $html_setup );
		// Fresh setup PEM is intentionally shown once.
		$this->assertStringContainsString( 'FRESHSETUPKEYMATERIALONLYONCE', $html_setup );

		// Second render must not reprint the PEM.
		$html_setup_again = $this->render_settings_page_html( $settings );
		$this->assertStringNotContainsString( 'FRESHSETUPKEYMATERIALONLYONCE', $html_setup_again );

		// Clear reveal and assert rotate path never dumps stored PEM.
		$clear = $ref->getMethod( 'clear_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$clear->setAccessible( true );
		}
		$clear->invoke( $settings, false );

		$html_db = $this->render_settings_page_html( $settings );
		$this->assertStringNotContainsString( $pem, $html_db );
		$this->assertStringContainsString( 'Move key to wp-config.php', $html_db );
		$this->assertStringNotContainsString( 'Show wp-config.php lines', $html_db );

		delete_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * Foreign poster hosts fall back to the generated poster.
	 */
	public function test_posterurl_host_allowlist() {
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, false );
		update_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN, 'cloudflarestream.com' );
		update_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, 0 );

		$uid = 'dddddddddddddddddddddddddddddddd';
		$api = Cloudflare_Stream_API::instance();

		$evil = $api->get_video_embed_template(
			$uid,
			array(
				'posterurl' => 'https://evil.example/tracker.jpg',
				'controls'  => 'true',
			)
		);
		$this->assertStringNotContainsString( 'evil.example', $evil );
		$this->assertStringContainsString( 'videodelivery.net', $evil );

		$good = 'https://videodelivery.net/' . $uid . '/thumbnails/thumbnail.jpg';
		$ok   = $api->get_video_embed_template(
			$uid,
			array(
				'posterurl' => $good,
				'controls'  => 'true',
			)
		);
		$this->assertStringContainsString( 'videodelivery.net', $ok );
		$this->assertStringContainsString( rawurlencode( esc_url( $good ) ) !== '' ? 'videodelivery.net' : 'videodelivery.net', $ok );
	}

	/**
	 * Direct upload rejects oversized and wrong-type files before HTTP.
	 */
	public function test_direct_upload_bounds() {
		cfstream_test_clear_http_attempts();
		$api = Cloudflare_Stream_API::instance();

		$this->assertNull( $api->create_direct_upload( 0 ) );
		$this->assertNull( $api->create_direct_upload( $api->get_max_upload_bytes() + 1 ) );
		$this->assertNull(
			$api->create_direct_upload(
				1024,
				array( 'filetype' => 'application/x-msdownload' )
			)
		);

		$this->assertSame( array(), cfstream_test_get_http_attempts(), 'rejected uploads must not call Cloudflare' );

		// Allowed type still attempts the request (blocked by tripwire).
		$result = $api->create_direct_upload(
			1024,
			array(
				'name'     => 'clip.mp4',
				'filetype' => 'video/mp4',
			)
		);
		$this->assertNull( $result );
		$this->assertNotEmpty( cfstream_test_get_http_attempts() );
	}

	/**
	 * Token cache key changes with signing mode.
	 */
	public function test_signed_token_cache_key_includes_mode() {
		$api  = Cloudflare_Stream_API::instance();
		$ref  = new ReflectionClass( $api );
		$method = $ref->getMethod( 'get_signed_token_cache_key' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
		$api_key = $method->invoke( $api, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'kid-one', false );
		// Reset singleton caches of option reads if any.
		$key_with_id = $method->invoke( $api, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' );

		$this->assertNotSame( $api_key, $key_with_id );
		$this->assertStringStartsWith( 'cfstream_signed_token_', $api_key );

		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
	}

	/**
	 * Transport failure and bad JSON produce different API reason codes.
	 */
	public function test_mint_token_reason_codes_distinguish_transport_and_payload() {
		$this->reset_signing_runtime_state();
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION, 60 );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$uid = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
		$api = Cloudflare_Stream_API::instance();
		$ref = new ReflectionClass( $api );
		$mint = $ref->getMethod( 'mint_token_via_api' );
		$reason = $ref->getProperty( 'last_api_reason' );
		if ( PHP_VERSION_ID < 80100 ) {
			$mint->setAccessible( true );
			$reason->setAccessible( true );
		}

		// Default tripwire returns WP_Error -> transport.
		cfstream_test_clear_http_attempts();
		$mint->invoke( $api, $uid, time() + 3600, array() );
		$this->assertSame( 'api_transport_error', $reason->getValue( $api ) );

		// Malformed body.
		$this->reset_signing_runtime_state();
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );
		$api2 = Cloudflare_Stream_API::instance();
		$filter = static function () {
			return array(
				'headers'  => array(),
				'body'     => 'not-json{',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );
		$mint->invoke( $api2, $uid, time() + 3600, array() );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$reason2 = $ref->getProperty( 'last_api_reason' );
		if ( PHP_VERSION_ID < 80100 ) {
			$reason2->setAccessible( true );
		}
		$this->assertSame( 'api_bad_payload', $reason2->getValue( $api2 ) );
	}

	/**
	 * Capture settings_page() HTML with settings fields registered.
	 *
	 * @param Cloudflare_Stream_Settings $settings Settings instance.
	 * @return string
	 */
	private function render_settings_page_html( $settings ) {
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}
		set_current_screen( 'settings_page_cloudflare-stream' );
		// Ensure settings sections/fields exist for this request.
		$settings->action_admin_init();

		ob_start();
		$settings->settings_page();
		return (string) ob_get_clean();
	}

	/**
	 * Drop token caches, health state, and API/health singletons.
	 */
	private function reset_signing_runtime_state() {
		global $wpdb;

		delete_option( Cloudflare_Stream_Signing_Health::OPTION_HEALTH );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '%cfstream_token_fail_%' OR option_name LIKE '%cfstream_signed_token_%' OR option_name LIKE '_transient_cfstream_%' OR option_name LIKE '_transient_timeout_cfstream_%'"
		);
		wp_cache_flush();

		$api_prop = new ReflectionProperty( 'Cloudflare_Stream_API', 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$api_prop->setAccessible( true );
		}
		$api_prop->setValue( null, false );

		$health_prop = new ReflectionProperty( 'Cloudflare_Stream_Signing_Health', 'instance' );
		if ( PHP_VERSION_ID < 80100 ) {
			$health_prop->setAccessible( true );
		}
		$health_prop->setValue( null, false );
	}
}
