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

		$skips = $smoke->get_skips();
		if ( ! empty( $skips ) ) {
			$this->markTestSkipped( implode( "\n", $skips ) );
		}

		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Option defaults after admin_menu for a manage_options user who is not a super admin.
	 */
	public function test_option_defaults_for_manage_options_user() {
		$this->assertTrue(
			function_exists( 'cfstream_test_create_manage_options_user' ),
			'cfstream_test_create_manage_options_user() helper must be available'
		);

		// Remove any values left by other tests so seeding is observable.
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION );
		delete_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN );
		delete_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME );

		$user_id = cfstream_test_create_manage_options_user();
		$this->assertNotSame( 0, $user_id, 'manage_options test user could not be created' );

		wp_set_current_user( $user_id );

		$this->assertTrue( user_can( $user_id, 'manage_options' ) );
		$this->assertFalse(
			is_super_admin( $user_id ),
			'test user must not be a super admin'
		);

		do_action( 'admin_menu' );

		$signed = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, null );
		$this->assertTrue(
			true === $signed || 1 === $signed || '1' === $signed,
			'signed_urls default should be true for a manage_options user'
		);

		$duration = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION, null );
		$this->assertSame( 60, (int) $duration, 'signed_urls_duration default should be 60' );

		$domain = get_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN, null );
		$this->assertSame( 'cloudflarestream.com', $domain, 'media_domain default should be cloudflarestream.com' );

		$poster = get_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, null );
		$this->assertSame( 0, (int) $poster, 'poster_time default should be 0' );
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

	/**
	 * Block metadata registration exposes attributes and supports server-side.
	 */
	public function test_block_metadata_attributes_and_supports() {
		if ( ! did_action( 'init' ) ) {
			do_action( 'init' );
		}

		$registry = WP_Block_Type_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'cloudflare-stream/block-video' ) );

		$block = $registry->get_registered( 'cloudflare-stream/block-video' );
		$this->assertSame( 'cloudflare_stream_render_block', $block->render_callback );
		$this->assertNotEmpty( $block->attributes );
		$this->assertArrayHasKey( 'uid', $block->attributes );
		$this->assertSame( 'string', $block->attributes['uid']['type'] );
		$this->assertSame( '', $block->attributes['uid']['default'] );
		$this->assertArrayHasKey( 'fingerprint', $block->attributes );
		$this->assertSame( '', $block->attributes['fingerprint']['default'] );
		$this->assertArrayHasKey( 'thumbnail', $block->attributes );
		$this->assertSame( '', $block->attributes['thumbnail']['default'] );
		$this->assertArrayNotHasKey( 'alignment', $block->attributes );
		$this->assertArrayNotHasKey( 'transform', $block->attributes );
		$this->assertTrue( ! empty( $block->supports['align'] ) );
	}

	/**
	 * Front-end block style is registered but not force-enqueued by block assets.
	 *
	 * WordPress may still enqueue the style when the block is rendered; this only
	 * asserts cloudflare_stream_block_assets() does not globally enqueue on the
	 * front end, while admin still receives editor and canvas styles.
	 */
	public function test_block_style_enqueue_admin_only_from_block_assets() {
		if ( ! did_action( 'init' ) ) {
			do_action( 'init' );
		}

		if ( function_exists( 'cloudflare_stream_register_block_styles' ) ) {
			cloudflare_stream_register_block_styles();
		}

		$style_handle  = 'cloudflare-stream-block-style-css';
		$editor_handle = 'cloudflare-stream-block-editor-css';

		if ( ! wp_style_is( $style_handle, 'registered' ) ) {
			$this->markTestSkipped( 'Front-end block style handle is not registered (dist CSS missing).' );
		}

		$this->assertTrue(
			wp_style_is( $style_handle, 'registered' ),
			'block.json front-end style handle must be registered after init'
		);

		// Isolate queue state from other tests.
		wp_dequeue_style( $style_handle );
		wp_dequeue_style( $editor_handle );
		wp_styles()->queue = array_values(
			array_diff(
				(array) wp_styles()->queue,
				array( $style_handle, $editor_handle )
			)
		);

		// Simulated front-end request: no current admin screen.
		unset( $GLOBALS['current_screen'] );
		$this->assertFalse( is_admin(), 'front-end simulation must not report is_admin()' );

		cloudflare_stream_block_assets();

		$this->assertFalse(
			wp_style_is( $style_handle, 'enqueued' ),
			'cloudflare_stream_block_assets must not globally enqueue front-end block style'
		);
		$this->assertFalse(
			wp_style_is( $editor_handle, 'enqueued' ),
			'editor style must not be enqueued on the front end by block assets'
		);

		// Admin / editor canvas path still enqueues registered styles.
		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin(), 'edit-post screen must report is_admin()' );

		cloudflare_stream_block_assets();

		$this->assertTrue(
			wp_style_is( $style_handle, 'enqueued' ),
			'admin block assets should enqueue the canvas front-end style handle'
		);
		if ( wp_style_is( $editor_handle, 'registered' ) ) {
			$this->assertTrue(
				wp_style_is( $editor_handle, 'enqueued' ),
				'admin block assets should enqueue the editor style handle'
			);
		}

		// Leave the suite without an admin screen sticky for later tests.
		unset( $GLOBALS['current_screen'] );
		wp_dequeue_style( $style_handle );
		wp_dequeue_style( $editor_handle );
	}
}
