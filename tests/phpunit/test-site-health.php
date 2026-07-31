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
	}
}
