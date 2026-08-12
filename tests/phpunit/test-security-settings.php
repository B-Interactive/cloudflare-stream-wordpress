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
	 * Reset HTTP tripwire and in-request reveal cache before each test.
	 */
	public function set_up() {
		parent::set_up();
		if ( function_exists( 'cfstream_test_clear_http_attempts' ) ) {
			cfstream_test_clear_http_attempts();
		}

		if ( class_exists( 'Cloudflare_Stream_Secret_Store' ) ) {
			Cloudflare_Stream_Secret_Store::set_crypto_available_override( null );
			Cloudflare_Stream_Secret_Store::clear_last_storage_error();
		}

		// Settings is a process-wide singleton; drop any in-request reveal cache.
		if ( class_exists( 'Cloudflare_Stream_Settings' ) ) {
			$settings = Cloudflare_Stream_Settings::instance();
			$prop     = new ReflectionProperty( $settings, 'signing_key_reveal_for_request' );
			if ( PHP_VERSION_ID < 80100 ) {
				$prop->setAccessible( true );
			}
			$prop->setValue( $settings, false );
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
	 * Foreign poster hosts fall back to the generated poster; allowed hosts keep the URL.
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
		$this->assertStringContainsString( 'poster=' . rawurlencode( esc_url( $api->get_poster_url( $uid, '0s' ) ) ), $evil );

		$good = 'https://videodelivery.net/' . $uid . '/thumbnails/thumbnail.jpg?time=0s';
		$ok   = $api->get_video_embed_template(
			$uid,
			array(
				'posterurl' => $good,
				'controls'  => 'true',
			)
		);
		$this->assertStringNotContainsString( 'evil.example', $ok );
		$this->assertStringContainsString( 'poster=' . rawurlencode( esc_url( $good ) ), $ok );
	}

	/**
	 * tus Upload-Metadata keeps Cloudflare's maxDurationSeconds spelling.
	 */
	public function test_tus_upload_metadata_max_duration_key_casing() {
		$api    = Cloudflare_Stream_API::instance();
		$ref    = new ReflectionClass( $api );
		$method = $ref->getMethod( 'build_tus_upload_metadata' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		$header = $method->invoke(
			$api,
			array(
				'maxDurationSeconds' => '3600',
				'requiresignedurls'  => null,
				'expiry'             => '2024-02-27T07:20:50Z',
			)
		);

		$this->assertStringContainsString( 'maxDurationSeconds ', $header );
		$this->assertStringNotContainsString( 'maxdurationseconds ', $header );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- assert tus value encoding.
		$this->assertStringContainsString( 'maxDurationSeconds ' . base64_encode( '3600' ), $header );
		$this->assertStringContainsString( 'requiresignedurls', $header );
	}

	/**
	 * Failed constants confirm keeps actions available without reprinting the PEM.
	 */
	public function test_failed_confirm_does_not_rearm_pem_reveal() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings   = Cloudflare_Stream_Settings::instance();
		$reveal_pem = "-----BEGIN PRIVATE KEY-----\nFAILEDCONFIRMNOREPRINT\n-----END PRIVATE KEY-----";
		$ref        = new ReflectionClass( $settings );
		$stash      = $ref->getMethod( 'stash_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$stash->setAccessible( true );
		}
		$stash->invoke( $settings, 'pending-setup-key', $reveal_pem, 'setup' );

		// First display consumes the one-time PEM print.
		$html_first = $this->render_settings_page_html( $settings );
		$this->assertStringContainsString( 'FAILEDCONFIRMNOREPRINT', $html_first );

		$this->run_signing_key_action( $settings, 'confirm_constants' );

		$html_after = $this->render_settings_page_html( $settings );
		$this->assertStringNotContainsString( 'FAILEDCONFIRMNOREPRINT', $html_after );
		$this->assertStringContainsString( 'already shown once', $html_after );
		$this->assertStringContainsString( 'name="signing_key_do" value="confirm_constants"', $html_after );
	}

	/**
	 * store_db validates PEM before writing options.
	 */
	public function test_store_db_validates_before_write() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$ref   = new ReflectionClass( $settings );
		$stash = $ref->getMethod( 'stash_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$stash->setAccessible( true );
		}
		$stash->invoke(
			$settings,
			'bad-key-id',
			"-----BEGIN PRIVATE KEY-----\nNOTAVALIDKEY\n-----END PRIVATE KEY-----",
			'setup'
		);

		$this->run_signing_key_action( $settings, 'store_db' );

		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, false ) );
	}

	/**
	 * store_db writes a usable key after validation.
	 */
	public function test_store_db_writes_valid_key() {
		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$ref   = new ReflectionClass( $settings );
		$stash = $ref->getMethod( 'stash_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$stash->setAccessible( true );
		}
		$stash->invoke( $settings, 'valid-store-key-id', $pem, 'setup' );

		$this->run_signing_key_action( $settings, 'store_db' );

		$this->assertSame( 'valid-store-key-id', get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID ) );
		$stored_pem = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
		$this->assertIsString( $stored_pem );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $stored_pem );
		$this->assertStringNotContainsString( $pem, $stored_pem );
		$this->assertSame( $pem, Cloudflare_Stream_API::instance()->get_signing_key_pem() );

		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * Dismiss abandons setup/rotate and revokes the unused new key id.
	 */
	public function test_dismiss_revokes_pending_new_key() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		$old_id   = 'old-db-key-keep-me';
		$old_pem  = $this->generate_test_rsa_pem();
		if ( '' === $old_pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, $old_id, false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $old_pem, false );

		$ref   = new ReflectionClass( $settings );
		$stash = $ref->getMethod( 'stash_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$stash->setAccessible( true );
		}
		$stash->invoke( $settings, 'pending-new-key-to-revoke', $old_pem, 'rotate' );

		$deleted = array();
		$filter  = function ( $response, $url ) use ( &$deleted ) {
			if ( false !== strpos( (string) $url, 'stream/keys/' ) ) {
				$deleted[] = (string) $url;
				return $this->canned_cloudflare_success_response();
			}
			return $response;
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		cfstream_test_clear_http_attempts();
		$this->run_signing_key_action( $settings, 'dismiss' );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertSame( $old_id, get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID ) );
		$this->assertNotEmpty( $deleted );
		$this->assertStringContainsString( 'pending-new-key-to-revoke', $deleted[0] );

		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * clear without constants revokes the database key id.
	 */
	public function test_clear_without_constants_revokes_db_key() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		$pem      = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'db-key-to-revoke', false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $pem, false );

		$deleted = array();
		$filter  = function ( $response, $url ) use ( &$deleted ) {
			if ( false !== strpos( (string) $url, 'stream/keys/' ) ) {
				$deleted[] = (string) $url;
				return $this->canned_cloudflare_success_response();
			}
			return $response;
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		$this->run_signing_key_action( $settings, 'clear' );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, false ) );
		$this->assertNotEmpty( $deleted );
		$this->assertStringContainsString( 'db-key-to-revoke', $deleted[0] );
	}

	/**
	 * rotate create failure leaves the database key untouched.
	 */
	public function test_rotate_create_failure_leaves_options_unchanged() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		$pem      = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'stable-db-key', false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $pem, false );

		$pem_before = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		// Default HTTP tripwire fails create_signing_key.
		cfstream_test_clear_http_attempts();
		$this->run_signing_key_action( $settings, 'rotate' );

		$this->assertSame( 'stable-db-key', get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID ) );
		$this->assertSame( $pem_before, get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ) );
		$this->assertSame( $pem, Cloudflare_Stream_API::instance()->get_signing_key_pem() );
		$this->assertNotEmpty( cfstream_test_get_http_attempts() );

		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * confirm_moved with working constants clears DB options and revokes the old id.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_confirm_moved_clears_db_and_revokes_old_key() {
		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'constants-live-key-id' );
		}
		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM', $pem );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'old-db-key-id', false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $pem, false );

		$ref   = new ReflectionClass( $settings );
		$stash = $ref->getMethod( 'stash_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$stash->setAccessible( true );
		}
		$stash->invoke( $settings, 'constants-live-key-id', $pem, 'rotate' );

		$deleted = array();
		$filter  = function ( $response, $url ) use ( &$deleted ) {
			if ( false !== strpos( (string) $url, 'stream/keys/' ) ) {
				$deleted[] = (string) $url;
				return $this->canned_cloudflare_success_response();
			}
			return $response;
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		$this->run_signing_key_action( $settings, 'confirm_moved' );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, false ) );
		$this->assertNotEmpty( $deleted );
		$this->assertStringContainsString( 'old-db-key-id', $deleted[0] );
		$this->assertStringNotContainsString( 'constants-live-key-id', implode( "\n", $deleted ) );
	}

	/**
	 * clear with constants present drops unused DB copy and does not revoke the live key.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clear_with_constants_does_not_revoke_live_key() {
		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'constants-live-key-id' );
		}
		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM', $pem );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		// Unused DB row under constant override (same id as live constants).
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'constants-live-key-id', false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $pem, false );

		$deleted = array();
		$filter  = function ( $response, $url ) use ( &$deleted ) {
			if ( false !== strpos( (string) $url, 'stream/keys/' ) ) {
				$deleted[] = (string) $url;
				return $this->canned_cloudflare_success_response();
			}
			return $response;
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		$this->run_signing_key_action( $settings, 'clear' );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, false ) );
		$this->assertSame( array(), $deleted, 'live constant key id must not be revoked on clear' );
	}

	/**
	 * API token and signing PEM are stored as envelopes; reads return plaintext.
	 */
	public function test_secret_store_encrypts_and_decrypts_options() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$token = 'db-only-api-token-not-real';
		$pem   = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		delete_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );

		$this->assertTrue( Cloudflare_Stream_Secret_Store::update_secret( Cloudflare_Stream_Settings::OPTION_API_TOKEN, $token ) );
		$this->assertTrue( Cloudflare_Stream_Secret_Store::update_secret( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $pem ) );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'enc-test-key-id', false );

		$raw_token = get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		$raw_pem   = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $raw_token );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $raw_pem );
		$this->assertStringNotContainsString( $token, $raw_token );
		$this->assertStringNotContainsString( 'BEGIN PRIVATE KEY', $raw_pem );
		$this->assertStringNotContainsString( $pem, $raw_pem );

		$this->assertSame( $token, Cloudflare_Stream_Secret_Store::get_secret( Cloudflare_Stream_Settings::OPTION_API_TOKEN ) );
		$this->assertSame( $pem, Cloudflare_Stream_API::instance()->get_signing_key_pem() );
		$this->assertTrue( Cloudflare_Stream_Settings::db_has_api_token_option() );
		$this->assertTrue( Cloudflare_Stream_API::instance()->has_signing_key() );

		// Constants still win for the live API token accessor.
		$this->assertSame( 'test-token-not-real', Cloudflare_Stream_Settings::get_api_token() );

		delete_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
	}

	/**
	 * Settings page auto-clean drops encrypted DB secrets when constants supply them.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_auto_clean_purges_encrypted_db_secrets_when_constants_ready() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		// API token constant is defined by the test MU helper; signing constants are local.
		$this->assertTrue( Cloudflare_Stream_Settings::api_token_from_constant() );

		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'auto-clean-const-key-id' );
		}
		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM', $pem );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$this->assertTrue(
			Cloudflare_Stream_Secret_Store::update_secret(
				Cloudflare_Stream_Settings::OPTION_API_TOKEN,
				'leftover-db-token-not-real'
			)
		);
		$this->assertTrue(
			Cloudflare_Stream_Secret_Store::update_secret(
				Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM,
				$pem
			)
		);
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'leftover-db-key-id', false );

		$raw_token = get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		$raw_pem   = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $raw_token );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $raw_pem );
		$this->assertTrue( Cloudflare_Stream_Settings::db_has_api_token_option() );

		$settings = Cloudflare_Stream_Settings::instance();
		$ref      = new ReflectionClass( $settings );
		$db_has   = $ref->getMethod( 'db_has_signing_key_options' );
		if ( PHP_VERSION_ID < 80100 ) {
			$db_has->setAccessible( true );
		}
		$this->assertTrue( $db_has->invoke( $settings ) );
		$this->assertTrue( Cloudflare_Stream_API::instance()->has_signing_key_from_constants() );

		$settings->maybe_auto_clean_constant_secrets();

		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, false ) );
		$this->assertFalse( Cloudflare_Stream_Settings::db_has_api_token_option() );
		$this->assertFalse( $db_has->invoke( $settings ) );

		// Notice payload lists removed labels (not secret values).
		$notice_name = Cloudflare_Stream_Settings::TRANSIENT_SECRETS_AUTO_CLEAN . get_current_user_id();
		$removed     = get_transient( $notice_name );
		$this->assertIsArray( $removed );
		$this->assertNotEmpty( $removed );
		$this->assertStringNotContainsString( 'leftover-db-token-not-real', wp_json_encode( $removed ) );
		$this->assertStringNotContainsString( 'BEGIN PRIVATE KEY', wp_json_encode( $removed ) );
	}

	/**
	 * Auto-clean removes unreadable envelopes by option key without needing decrypt.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_auto_clean_purges_unreadable_envelopes_without_decrypt() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'auto-clean-unreadable-const-id' );
		}
		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM', $pem );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Bogus envelope that cannot decrypt under the current key material.
		$other_key = hash( 'sha256', 'auto-clean-unreadable-material', true );
		$iv        = random_bytes( 12 );
		$tag       = '';
		$cipher    = openssl_encrypt( 'not-a-real-secret', 'aes-256-gcm', $other_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$bogus_token = Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX . base64_encode( $iv . $tag . $cipher );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$bogus_pem = Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX . base64_encode( $iv . $tag . $cipher );

		update_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN, $bogus_token, false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'orphan-unreadable-id', false );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $bogus_pem, false );

		$this->assertTrue( Cloudflare_Stream_Settings::db_has_api_token_option() );
		$this->assertTrue( Cloudflare_Stream_Secret_Store::has_stored_value( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ) );

		$settings = Cloudflare_Stream_Settings::instance();
		$settings->maybe_auto_clean_constant_secrets();

		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, false ) );
		$this->assertFalse( Cloudflare_Stream_Settings::db_has_api_token_option() );
		$this->assertFalse( Cloudflare_Stream_Secret_Store::has_stored_value( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID ) );
		$this->assertFalse( Cloudflare_Stream_Secret_Store::has_stored_value( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ) );
	}

	/**
	 * Half-configured signing constants must not auto-purge DB signing options.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_auto_clean_skips_signing_when_constants_not_ready() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		// Only the id constant: options are ignored for live reads, but not purged.
		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'half-configured-key-id' );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertTrue(
			Cloudflare_Stream_Secret_Store::update_secret(
				Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM,
				$pem
			)
		);
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'db-key-kept', false );

		$settings = Cloudflare_Stream_Settings::instance();
		$this->assertTrue( Cloudflare_Stream_API::instance()->signing_key_constants_present() );
		$this->assertFalse( Cloudflare_Stream_API::instance()->has_signing_key_from_constants() );

		$settings->maybe_auto_clean_constant_secrets();

		$this->assertSame( 'db-key-kept', get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID ) );
		$this->assertNotEmpty( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ) );

		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * confirm_moved deletes encrypted PEM/id rows after constants verify.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_confirm_moved_clears_encrypted_db_signing_options() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID', 'confirm-enc-live-key-id' );
		}
		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) ) {
			define( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM', $pem );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'old-enc-db-key-id', false );
		$this->assertTrue(
			Cloudflare_Stream_Secret_Store::update_secret(
				Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM,
				$pem
			)
		);
		$this->assertStringStartsWith(
			Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX,
			get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM )
		);

		$ref   = new ReflectionClass( $settings );
		$stash = $ref->getMethod( 'stash_signing_key_reveal' );
		if ( PHP_VERSION_ID < 80100 ) {
			$stash->setAccessible( true );
		}
		$stash->invoke( $settings, 'confirm-enc-live-key-id', $pem, 'rotate' );

		$deleted = array();
		$filter  = function ( $response, $url ) use ( &$deleted ) {
			if ( false !== strpos( (string) $url, 'stream/keys/' ) ) {
				$deleted[] = (string) $url;
				return $this->canned_cloudflare_success_response();
			}
			return $response;
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		$this->run_signing_key_action( $settings, 'confirm_moved' );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, false ) );
		$this->assertFalse( get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, false ) );
		$this->assertNotEmpty( $deleted );
		$this->assertStringContainsString( 'old-enc-db-key-id', $deleted[0] );
	}

	/**
	 * Legacy plaintext rows are returned and rewritten as ciphertext on read.
	 */
	public function test_secret_store_migrates_legacy_plaintext_on_read() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$token = 'legacy-plaintext-token';
		$pem   = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		delete_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		// Drop encrypt filters so we can plant legacy plaintext rows.
		$settings = Cloudflare_Stream_Settings::instance();
		remove_filter( 'pre_update_option_' . Cloudflare_Stream_Settings::OPTION_API_TOKEN, array( $settings, 'pre_update_encrypt_secret' ), 20 );
		remove_filter( 'pre_update_option_' . Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, array( $settings, 'pre_update_encrypt_secret' ), 20 );
		remove_filter( 'pre_add_option_' . Cloudflare_Stream_Settings::OPTION_API_TOKEN, array( $settings, 'pre_add_encrypt_secret' ), 20 );
		remove_filter( 'pre_add_option_' . Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, array( $settings, 'pre_add_encrypt_secret' ), 20 );

		add_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN, $token, '', 'no' );
		add_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $pem, '', 'no' );

		$this->assertSame( $token, get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN ) );
		$this->assertSame( $pem, get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ) );
		$this->assertFalse( Cloudflare_Stream_Secret_Store::is_envelope( get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN ) ) );

		// Re-attach encrypt filters so migration rewrite stores envelopes.
		add_filter( 'pre_update_option_' . Cloudflare_Stream_Settings::OPTION_API_TOKEN, array( $settings, 'pre_update_encrypt_secret' ), 20, 2 );
		add_filter( 'pre_update_option_' . Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, array( $settings, 'pre_update_encrypt_secret' ), 20, 2 );
		add_filter( 'pre_add_option_' . Cloudflare_Stream_Settings::OPTION_API_TOKEN, array( $settings, 'pre_add_encrypt_secret' ), 20, 2 );
		add_filter( 'pre_add_option_' . Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, array( $settings, 'pre_add_encrypt_secret' ), 20, 2 );

		$read_token = Cloudflare_Stream_Secret_Store::get_secret( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		$read_pem   = Cloudflare_Stream_Secret_Store::get_secret( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$this->assertSame( $token, $read_token );
		$this->assertSame( $pem, $read_pem );

		$raw_token = get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		$raw_pem   = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $raw_token );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $raw_pem );
		$this->assertSame( $token, Cloudflare_Stream_Secret_Store::get_secret( Cloudflare_Stream_Settings::OPTION_API_TOKEN ) );
		$this->assertSame( $pem, Cloudflare_Stream_Secret_Store::get_secret( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ) );

		delete_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * Wrong encryption material surfaces a clear health diagnosis.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_unreadable_signing_pem_health_diagnosis() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$pem = $this->generate_test_rsa_pem();
		if ( '' === $pem ) {
			$this->markTestSkipped( 'OpenSSL could not export a test RSA key.' );
		}

		if ( ! defined( 'CLOUDFLARE_STREAM_ENCRYPTION_KEY' ) ) {
			define( 'CLOUDFLARE_STREAM_ENCRYPTION_KEY', str_repeat( 'a', 64 ) );
		}

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, 'unreadable-key-id', false );
		$this->assertTrue(
			Cloudflare_Stream_Secret_Store::update_secret(
				Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM,
				$pem
			)
		);

		// Replace ciphertext with an envelope built under different key material.
		$other_key = hash( 'sha256', 'different-encryption-material-for-test', true );
		$iv        = random_bytes( 12 );
		$tag       = '';
		$cipher    = openssl_encrypt( $pem, 'aes-256-gcm', $other_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$bogus     = Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX . base64_encode( $iv . $tag . $cipher );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, $bogus, false );

		$this->assertTrue( Cloudflare_Stream_API::instance()->db_signing_key_pem_unreadable() );
		$this->assertSame( '', Cloudflare_Stream_API::instance()->get_signing_key_pem() );
		$this->assertFalse( Cloudflare_Stream_API::instance()->has_signing_key() );

		// db_has still true for ciphertext presence.
		$settings = Cloudflare_Stream_Settings::instance();
		$ref      = new ReflectionClass( $settings );
		$has      = $ref->getMethod( 'db_has_signing_key_options' );
		if ( PHP_VERSION_ID < 80100 ) {
			$has->setAccessible( true );
		}
		$this->assertTrue( $has->invoke( $settings ) );

		$issue = Cloudflare_Stream_Signing_Health::instance()->get_issue();
		$this->assertIsArray( $issue );
		$this->assertSame( 'secret_unreadable', $issue['code'] );
		$this->assertSame( 'error', $issue['severity'] );
		$this->assertStringContainsString( 'local_key_unreadable', $issue['detail'] );

		// Local sign path records the specific reason.
		$api = Cloudflare_Stream_API::instance();
		$api->create_signed_token_local( 'ffffffffffffffffffffffffffffffff', time() + 3600 );
		$reason = new ReflectionProperty( $api, 'last_local_reason' );
		if ( PHP_VERSION_ID < 80100 ) {
			$reason->setAccessible( true );
		}
		$this->assertSame( 'local_key_unreadable', $reason->getValue( $api ) );

		// Key id remains plaintext for revoke.
		$this->assertSame( 'unreadable-key-id', get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID ) );

		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
	}

	/**
	 * pre_update path encrypts a fresh API token value.
	 */
	public function test_pre_update_encrypts_api_token() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$settings = Cloudflare_Stream_Settings::instance();
		$settings->action_admin_init();

		$plain  = 'fresh-token-via-settings-save';
		$option = Cloudflare_Stream_Settings::OPTION_API_TOKEN;
		// Simulate option write after sanitize (constant filter may block live token option
		// when CLOUDFLARE_STREAM_API_TOKEN is defined; exercise prepare_for_storage directly).
		$stored = Cloudflare_Stream_Secret_Store::prepare_for_storage( $plain, '', $option );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $stored );
		$this->assertStringNotContainsString( $plain, $stored );
		$this->assertSame( $plain, Cloudflare_Stream_Secret_Store::decrypt( $stored, $option ) );
	}

	/**
	 * Ciphertext bound to one option name must not decrypt under another.
	 */
	public function test_secret_store_aad_binds_option_name() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$token_opt = Cloudflare_Stream_Settings::OPTION_API_TOKEN;
		$pem_opt   = Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM;
		$secret    = 'aad-bound-secret-value-not-real';

		$envelope = Cloudflare_Stream_Secret_Store::encrypt( $secret, $token_opt );
		$this->assertIsString( $envelope );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $envelope );

		$this->assertSame( $secret, Cloudflare_Stream_Secret_Store::decrypt( $envelope, $token_opt ) );
		$this->assertFalse( Cloudflare_Stream_Secret_Store::decrypt( $envelope, $pem_opt ) );
		$this->assertFalse( Cloudflare_Stream_Secret_Store::last_decrypt_used_empty_aad() );

		$pem_envelope = Cloudflare_Stream_Secret_Store::encrypt( $secret, $pem_opt );
		$this->assertIsString( $pem_envelope );
		$this->assertSame( $secret, Cloudflare_Stream_Secret_Store::decrypt( $pem_envelope, $pem_opt ) );
		$this->assertFalse( Cloudflare_Stream_Secret_Store::decrypt( $pem_envelope, $token_opt ) );
	}

	/**
	 * Empty-AAD envelopes remain readable and are re-sealed with option AAD on get_secret.
	 */
	public function test_secret_store_migrates_empty_aad_envelope_on_read() {
		if ( ! Cloudflare_Stream_Secret_Store::is_crypto_available() ) {
			$this->markTestSkipped( 'AES-256-GCM is not available.' );
		}

		$option = Cloudflare_Stream_Settings::OPTION_API_TOKEN;
		$secret = 'legacy-empty-aad-token-not-real';

		$key = Cloudflare_Stream_Secret_Store::get_encryption_key();
		$this->assertNotFalse( $key );

		$iv  = random_bytes( Cloudflare_Stream_Secret_Store::IV_LENGTH );
		$tag = '';
		// Empty AAD matches envelopes written before option-name binding.
		$cipher = openssl_encrypt(
			$secret,
			Cloudflare_Stream_Secret_Store::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			Cloudflare_Stream_Secret_Store::TAG_LENGTH
		);
		$this->assertIsString( $cipher );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$legacy = Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX . base64_encode( $iv . $tag . $cipher );

		delete_option( $option );
		update_option( $option, $legacy, false );

		$read = Cloudflare_Stream_Secret_Store::get_secret( $option );
		$this->assertSame( $secret, $read );

		$raw_after = get_option( $option );
		$this->assertIsString( $raw_after );
		$this->assertStringStartsWith( Cloudflare_Stream_Secret_Store::ENVELOPE_PREFIX, $raw_after );
		$this->assertNotSame( $legacy, $raw_after, 'empty-AAD envelope should be rewritten with option AAD' );
		$this->assertSame( $secret, Cloudflare_Stream_Secret_Store::decrypt( $raw_after, $option ) );
		$this->assertFalse(
			Cloudflare_Stream_Secret_Store::decrypt( $raw_after, Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ),
			're-sealed envelope must not decrypt under a different option name'
		);

		delete_option( $option );
	}

	/**
	 * When crypto is unavailable, prepare_for_storage keeps the old value and records the failure.
	 */
	public function test_prepare_for_storage_records_crypto_unavailable() {
		Cloudflare_Stream_Secret_Store::clear_last_storage_error();
		Cloudflare_Stream_Secret_Store::set_crypto_available_override( false );

		$option   = Cloudflare_Stream_Settings::OPTION_API_TOKEN;
		$old      = 'cfstream_enc_v1:existing-envelope-placeholder';
		$proposed = 'new-plaintext-token-must-not-store';

		$result = Cloudflare_Stream_Secret_Store::prepare_for_storage( $proposed, $old, $option );
		$this->assertSame( $old, $result );

		$error = Cloudflare_Stream_Secret_Store::get_last_storage_error();
		$this->assertIsArray( $error );
		$this->assertSame( 'crypto_unavailable', $error['reason'] );
		$this->assertSame( $option, $error['option'] );
		$this->assertSame( 'keep', $error['mode'] );

		// First insert with no prior value.
		Cloudflare_Stream_Secret_Store::clear_last_storage_error();
		$result_add = Cloudflare_Stream_Secret_Store::prepare_for_storage( $proposed, '', $option );
		$this->assertSame( '', $result_add );
		$error_add = Cloudflare_Stream_Secret_Store::get_last_storage_error();
		$this->assertIsArray( $error_add );
		$this->assertSame( 'add', $error_add['mode'] );

		Cloudflare_Stream_Secret_Store::set_crypto_available_override( null );
		Cloudflare_Stream_Secret_Store::clear_last_storage_error();
	}

	/**
	 * Settings encrypt hooks queue a translated admin notice when crypto is unavailable.
	 */
	public function test_secret_storage_notice_queued_when_crypto_unavailable() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$settings = Cloudflare_Stream_Settings::instance();
		Cloudflare_Stream_Secret_Store::clear_last_storage_error();
		Cloudflare_Stream_Secret_Store::set_crypto_available_override( false );

		$option = Cloudflare_Stream_Settings::OPTION_API_TOKEN;
		$old    = 'cfstream_enc_v1:kept-value';
		$out    = $settings->pre_update_encrypt_secret( 'fresh-token-blocked', $old, $option );
		$this->assertSame( $old, $out );

		$notice_name = Cloudflare_Stream_Settings::TRANSIENT_SECRET_STORAGE_ERROR . get_current_user_id();
		$payload     = get_transient( $notice_name );
		$this->assertIsArray( $payload );
		$this->assertSame( 'crypto_unavailable', $payload['reason'] );
		$this->assertSame( $option, $payload['option'] );
		$this->assertSame( 'keep', $payload['mode'] );

		// Notice copy is translated and never includes the proposed secret.
		$ref = new ReflectionClass( $settings );
		$fmt = $ref->getMethod( 'format_secret_storage_notice_text' );
		if ( PHP_VERSION_ID < 80100 ) {
			$fmt->setAccessible( true );
		}
		$text = $fmt->invoke( $settings, $option, 'keep' );
		$this->assertStringContainsString( 'encryption is unavailable', $text );
		$this->assertStringContainsString( 'previous stored value was kept', $text );
		$this->assertStringNotContainsString( 'fresh-token-blocked', $text );

		ob_start();
		$settings->secret_storage_admin_notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'encryption is unavailable', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'previous stored value was kept', $html );
		$this->assertStringNotContainsString( 'fresh-token-blocked', $html );
		$this->assertFalse( get_transient( $notice_name ) );

		Cloudflare_Stream_Secret_Store::set_crypto_available_override( null );
		Cloudflare_Stream_Secret_Store::clear_last_storage_error();
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

	/**
	 * Invoke a signing-key admin-post action and catch the redirect exit.
	 *
	 * @param Cloudflare_Stream_Settings $settings Settings instance.
	 * @param string                     $do       signing_key_do value.
	 * @return string Redirect location when captured.
	 */
	private function run_signing_key_action( $settings, $do ) {
		$_POST['signing_key_do'] = $do;
		$_REQUEST['signing_key_do'] = $do;
		$_POST[ Cloudflare_Stream_Settings::NONCE ] = wp_create_nonce( Cloudflare_Stream_Settings::ADMIN_ACTION_SIGNING_KEY );
		$_REQUEST[ Cloudflare_Stream_Settings::NONCE ] = $_POST[ Cloudflare_Stream_Settings::NONCE ];

		$redirected = '';
		$filter     = static function ( $location ) use ( &$redirected ) {
			$redirected = (string) $location;
			throw new Exception( 'cfstream_test_redirect' );
		};
		add_filter( 'wp_redirect', $filter, 999, 1 );

		try {
			$settings->handle_signing_key_action();
			$this->fail( 'signing key action should redirect' );
		} catch ( Exception $e ) {
			$this->assertSame( 'cfstream_test_redirect', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $filter, 999 );
			unset( $_POST['signing_key_do'], $_REQUEST['signing_key_do'], $_POST[ Cloudflare_Stream_Settings::NONCE ], $_REQUEST[ Cloudflare_Stream_Settings::NONCE ] );
		}

		return $redirected;
	}

	/**
	 * Canned Cloudflare JSON success body for key create/delete.
	 *
	 * @param array $result Optional result object fields.
	 * @return array
	 */
	private function canned_cloudflare_success_response( $result = array() ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'success' => true,
					'errors'  => array(),
					'messages'=> array(),
					'result'  => empty( $result ) ? new stdClass() : $result,
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Build a disposable RSA private key PEM for local validation tests.
	 *
	 * @return string PEM or empty string on failure.
	 */
	private function generate_test_rsa_pem() {
		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_export' ) ) {
			return '';
		}

		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		if ( false === $key ) {
			return '';
		}

		$pem = '';
		if ( ! openssl_pkey_export( $key, $pem ) || ! is_string( $pem ) || '' === $pem ) {
			return '';
		}

		return $pem;
	}
}
