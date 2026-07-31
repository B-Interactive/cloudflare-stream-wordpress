<?php
/**
 * Bootstrap / load smoke tests.
 *
 * @package cloudflare-stream
 */

/**
 * @group bootstrap
 */
class Test_CFStream_Bootstrap extends WP_UnitTestCase {

	/**
	 * Plugin load surface and OpenSSL availability.
	 */
	public function test_plugin_bootstrap_surface() {
		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_FULL );
		$smoke->s1_plugin_loaded();
		$smoke->s2_classes_exist();
		$smoke->s16_openssl_available();

		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Dummy API constants are defined for tests.
	 */
	public function test_dummy_api_constants_defined() {
		$this->assertTrue( defined( 'CLOUDFLARE_STREAM_API_TOKEN' ) );
		$this->assertTrue( defined( 'CLOUDFLARE_STREAM_API_ACCOUNT' ) );
		$this->assertNotSame( '', (string) CLOUDFLARE_STREAM_API_TOKEN );
		$this->assertNotSame( '', (string) CLOUDFLARE_STREAM_API_ACCOUNT );
	}
}
