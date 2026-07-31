<?php
/**
 * Render, settings page, HTTP tripwire, and dist checks.
 *
 * @package cloudflare-stream
 */

/**
 * @group render
 */
class Test_CFStream_Render extends WP_UnitTestCase {

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
	 * Render passthrough without uid.
	 */
	public function test_render_without_uid() {
		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_BOOTSTRAP );
		$smoke->s5_render_without_uid();
		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Dist assets present and non-empty.
	 */
	public function test_dist_assets_present() {
		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_BOOTSTRAP );
		$smoke->s13_dist_assets();
		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Settings page renders for an administrator.
	 */
	public function test_settings_page_renders() {
		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_BOOTSTRAP );
		$smoke->s14_settings_page_renders();
		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Blocked HTTP is recorded and returns an error.
	 */
	public function test_http_tripwire_blocks_and_records() {
		$this->assertTrue( function_exists( 'cfstream_test_get_http_attempts' ) );
		cfstream_test_clear_http_attempts();

		$response = wp_remote_get( 'https://example.com/cfstream-tripwire-test' );
		$this->assertWPError( $response );
		$this->assertSame( 'cfstream_http_blocked', $response->get_error_code() );

		$attempts = cfstream_test_get_http_attempts();
		$this->assertNotEmpty( $attempts );
		$this->assertStringContainsString( 'example.com/cfstream-tripwire-test', $attempts[0] );

		cfstream_test_clear_http_attempts();
		$this->assertSame( array(), cfstream_test_get_http_attempts() );
	}

	/**
	 * Opt-in canned response filter.
	 */
	public function test_http_canned_response_filter() {
		cfstream_test_clear_http_attempts();

		$filter = function ( $pre, $url ) {
			unset( $url );
			return array(
				'headers'  => array(),
				'body'     => '{"ok":true}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		$response = wp_remote_get( 'https://example.com/canned' );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertNotWPError( $response );
		$this->assertSame( 200, (int) wp_remote_retrieve_response_code( $response ) );
		$this->assertSame( '{"ok":true}', wp_remote_retrieve_body( $response ) );
		$this->assertNotEmpty( cfstream_test_get_http_attempts() );

		cfstream_test_clear_http_attempts();
	}

	/**
	 * Bootstrap profile batch (AJAX capabilities covered separately).
	 */
	public function test_bootstrap_profile_batch() {
		cfstream_test_clear_http_attempts();

		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_BOOTSTRAP );
		$smoke->s1_plugin_loaded();
		$smoke->s2_classes_exist();
		$smoke->s3_shortcode_registered();
		$smoke->s4_block_registered();
		$smoke->s5_render_without_uid();
		$smoke->s13_dist_assets();
		$smoke->s16_openssl_available();
		$smoke->s17_no_wp_editor_usage();

		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}
}
