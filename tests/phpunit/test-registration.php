<?php
/**
 * Registration smoke tests (shortcode, block, settings, AJAX hooks).
 *
 * @package cloudflare-stream
 */

/**
 * @group registration
 */
class Test_CFStream_Registration extends WP_UnitTestCase {

	/**
	 * Shortcode, block, settings, AJAX, dist, and src checks.
	 */
	public function test_registration_surface() {
		if ( function_exists( 'cfstream_test_clear_http_attempts' ) ) {
			cfstream_test_clear_http_attempts();
		}

		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_BOOTSTRAP );
		$smoke->s3_shortcode_registered();
		$smoke->s4_block_registered();
		$smoke->s6_default_options();
		$smoke->s7_settings_submenu();
		$smoke->s8_settings_registered();
		$smoke->s9_ajax_actions_registered();
		$smoke->s12_admin_post_actions();
		$smoke->s13_dist_assets();
		$smoke->s17_no_wp_editor_usage();
		$smoke->s18_block_script_deps();

		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Setting group and option name constants match production strings.
	 */
	public function test_setting_group_constant() {
		$this->assertSame( 'cloudflare_stream', Cloudflare_Stream_Settings::SETTING_GROUP );
		$this->assertSame( 'cloudflare-stream', Cloudflare_Stream_Settings::SETTING_PAGE );
		$this->assertSame( 'cloudflare_stream_api_account', Cloudflare_Stream_Settings::OPTION_API_ACCOUNT );
		$this->assertSame( 'cloudflare_stream_api_token', Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		$this->assertSame( 'cloudflare_stream_signed_urls', Cloudflare_Stream_Settings::OPTION_SIGNED_URLS );
		$this->assertSame( 'cloudflare_stream_signed_urls_duration', Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION );
		$this->assertSame( 'cloudflare_stream_media_domain', Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN );
		$this->assertSame( 'cloudflare_stream_poster_time', Cloudflare_Stream_Settings::OPTION_POSTER_TIME );
	}
}
