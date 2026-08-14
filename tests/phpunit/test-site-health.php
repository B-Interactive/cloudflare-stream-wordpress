<?php
/**
 * Site Health and admin_post registration tests.
 *
 * @package cloudflare-stream
 */

/**
 * @group site-health
 */
class Test_CFStream_Site_Health extends WP_UnitTestCase {

	/**
	 * Site Health test and admin_post hooks are registered.
	 */
	public function test_site_health_and_admin_post() {
		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_BOOTSTRAP );
		$smoke->s11_site_health_test();
		$smoke->s12_admin_post_actions();

		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Signing health callback is callable.
	 */
	public function test_signing_health_callback_shape() {
		$tests = apply_filters(
			'site_status_tests',
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		$this->assertArrayHasKey( 'direct', $tests );
		$this->assertArrayHasKey( 'cloudflare_stream_signing', $tests['direct'] );
		$entry = $tests['direct']['cloudflare_stream_signing'];
		$this->assertArrayHasKey( 'label', $entry );
		$this->assertArrayHasKey( 'test', $entry );
		$this->assertTrue( is_callable( $entry['test'] ), 'site health test callback must be callable' );

		$this->assertArrayHasKey( 'cloudflare_stream_signed_cache', $tests['direct'] );
		$cache_entry = $tests['direct']['cloudflare_stream_signed_cache'];
		$this->assertArrayHasKey( 'label', $cache_entry );
		$this->assertArrayHasKey( 'test', $cache_entry );
		$this->assertTrue( is_callable( $cache_entry['test'] ), 'signed cache site health test must be callable' );
	}

	/**
	 * Signed-cache Site Health warns when signed URLs meet a page-cache signal.
	 */
	public function test_signed_cache_site_health_warns_when_cache_detected() {
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );

		$health = Cloudflare_Stream_Signing_Health::instance();
		$result = $health->site_health_signed_cache_test();
		$this->assertIsArray( $result );
		$this->assertSame( 'cloudflare_stream_signed_cache', $result['test'] );

		// Without a cache signal the check stays good while signed URLs are on.
		if ( ! $health->is_page_cache_likely_active() ) {
			$this->assertSame( 'good', $result['status'] );
		}

		// Force the detection path used when WP_CACHE is on.
		if ( ! defined( 'WP_CACHE' ) ) {
			define( 'WP_CACHE', true );
			$result_cached = $health->site_health_signed_cache_test();
			$this->assertSame( 'recommended', $result_cached['status'] );
			$this->assertStringContainsString( 'page cache', strtolower( $result_cached['label'] ) );
		}

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, false );
		$result_off = $health->site_health_signed_cache_test();
		$this->assertSame( 'good', $result_off['status'] );
	}

	/**
	 * Signed embed render marks the response uncacheable and hooks no-cache headers.
	 */
	public function test_signed_embed_marks_response_uncacheable() {
		$api = Cloudflare_Stream_API::instance();

		// Reset the per-request flag on the singleton.
		$flag = new ReflectionProperty( 'Cloudflare_Stream_API', 'signed_embed_nocache' );
		if ( PHP_VERSION_ID < 80100 ) {
			$flag->setAccessible( true );
		}
		$flag->setValue( null, false );

		// Simulate a front-end page request context as far as the helper can see.
		$GLOBALS['current_screen'] = null;

		// Directly invoke the public marker after forcing frontend detection via filter is hard;
		// call mark only when is_admin is false in this PHPUnit bootstrap (it usually is).
		if ( ! is_admin() ) {
			$api->mark_signed_embed_uncacheable();
			$this->assertTrue( (bool) $flag->getValue() );
			$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
			$this->assertTrue(
				has_action( 'send_headers', array( $api, 'send_signed_embed_nocache_headers' ) )
				|| did_action( 'send_headers' ),
				'no-cache headers must be sent or hooked for signed embeds'
			);
		} else {
			$this->markTestSkipped( 'Admin bootstrap cannot exercise front-end cache defeat.' );
		}
	}
}
