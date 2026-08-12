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
		$skips = $smoke->get_skips();
		if ( ! empty( $skips ) ) {
			$this->markTestSkipped( implode( "\n", $skips ) );
		}
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

		$skips = $smoke->get_skips();
		if ( ! empty( $skips ) ) {
			$this->markTestSkipped( implode( "\n", $skips ) );
		}

		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Signed playback with no signing key and blocked HTTP yields an empty embed.
	 *
	 * The block render callback substitutes the editor diagnostic comment when
	 * the current user can edit posts and signing health is in total failure.
	 */
	public function test_signed_embed_fail_closed_without_key() {
		cfstream_test_clear_http_attempts();

		$uid = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

		$this->reset_signing_runtime_state();

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION, 60 );
		update_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN, 'cloudflarestream.com' );
		update_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, 0 );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$api = Cloudflare_Stream_API::instance();
		$this->assertFalse( $api->has_signing_key(), 'test must run without a local signing key' );

		$embed = $api->get_video_embed( $uid, array( 'controls' => true ) );
		$this->assertSame( '', $embed, 'signed embed must be empty when minting fails' );

		// Content editors must not receive internal reason codes.
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		// Total failure is the state that unlocks the admin-only comment.
		$health = Cloudflare_Stream_Signing_Health::instance();
		$health->record_outcome( $uid, 'local_key_missing_at_sign', 'api_http_error' );
		$state = $health->get_state();
		$this->assertSame( 'total_failure', $state['state'] );

		$comment_editor = $health->get_editor_failure_comment();
		$this->assertSame( '', $comment_editor, 'editors must not see signing reason codes' );

		$block_atts = array(
			'uid'      => $uid,
			'controls' => true,
		);
		$rendered_editor = $this->with_block_render_context(
			$block_atts,
			static function () use ( $block_atts ) {
				return cloudflare_stream_render_block( $block_atts, '' );
			}
		);
		$this->assertStringNotContainsString( 'cloudflare-stream: signed embed unavailable', $rendered_editor );
		$this->assertStringContainsString( '<figure', $rendered_editor );

		// Administrators still receive the diagnostic comment.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$comment = $health->get_editor_failure_comment();
		$this->assertNotSame( '', $comment );
		$this->assertStringContainsString( 'cloudflare-stream: signed embed unavailable', $comment );

		$rendered = $this->with_block_render_context(
			$block_atts,
			static function () use ( $block_atts ) {
				return cloudflare_stream_render_block( $block_atts, '' );
			}
		);
		$this->assertStringContainsString( $comment, $rendered );
		$this->assertStringContainsString( '<figure', $rendered );

		// A canned token response must produce a non-empty embed. If this stays
		// empty while the filter returns a token, fail-closed is over-reaching.
		$filter = static function ( $pre, $url ) {
			unset( $pre );
			if ( false === strpos( (string) $url, '/token' ) ) {
				return null;
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'success' => true,
						'result'  => array(
							'token' => 'canned-signed-token-for-fail-closed-test',
						),
					)
				),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		$this->reset_signing_runtime_state();
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );

		$api2   = Cloudflare_Stream_API::instance();
		$embed2 = $api2->get_video_embed( $uid, array( 'controls' => true ) );
		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertNotSame( '', $embed2, 'canned token must produce a non-empty embed' );
		$this->assertStringContainsString( 'canned-signed-token-for-fail-closed-test', $embed2 );

		cfstream_test_clear_http_attempts();
	}

	/**
	 * Embed HTML for shortcode and block attribute combinations.
	 *
	 * Covers controls, autoplay, muted, loop, and preload. Signed playback is
	 * off so the bare UID appears in the iframe src. Autoplay always implies
	 * muted in the emitted query string.
	 */
	public function test_embed_attribute_matrix_snapshots() {
		cfstream_test_clear_http_attempts();

		$uid = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, false );
		update_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN, 'cloudflarestream.com' );
		update_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, 0 );

		$flags = array( 'controls', 'autoplay', 'muted', 'loop', 'preload' );

		// Defaults, all-on, controls off, and each non-default flag alone.
		$cases                 = array();
		$cases['defaults']     = array(
			'controls' => true,
			'autoplay' => false,
			'muted'    => false,
			'loop'     => false,
			'preload'  => false,
		);
		$cases['all_on']       = array(
			'controls' => true,
			'autoplay' => true,
			'muted'    => true,
			'loop'     => true,
			'preload'  => true,
		);
		$cases['controls_off'] = array(
			'controls' => false,
			'autoplay' => false,
			'muted'    => false,
			'loop'     => false,
			'preload'  => false,
		);
		foreach ( array( 'autoplay', 'muted', 'loop', 'preload' ) as $only ) {
			$row                    = $cases['defaults'];
			$row[ $only ]           = true;
			$cases[ $only . '_on' ] = $row;
		}

		// Autoplay without muted still emits muted=true in the query string.
		$cases['autoplay_forces_muted'] = array(
			'controls' => true,
			'autoplay' => true,
			'muted'    => false,
			'loop'     => false,
			'preload'  => false,
		);

		$shortcode = Cloudflare_Stream_Shortcode::instance();
		$api       = Cloudflare_Stream_API::instance();

		foreach ( $cases as $label => $bools ) {
			// Shortcode attributes arrive as strings; the handler normalises them.
			$sc_atts = array( 'uid' => $uid );
			foreach ( $flags as $flag ) {
				$sc_atts[ $flag ] = ! empty( $bools[ $flag ] ) ? 'true' : 'false';
			}
			$sc_html = $shortcode->video_shortcode_handler( $sc_atts );
			$this->assertNotSame( '', $sc_html, "shortcode embed empty for {$label}" );
			$this->assert_embed_flags( $sc_html, $bools, 'shortcode:' . $label );

			// Block attributes are real booleans (same types the render callback passes).
			$block_atts = array_merge( array( 'uid' => $uid ), $bools );
			// Call get_video_embed with block-typed attrs so the iframe string is
			// comparable without requiring a full block supports render context.
			$block_html = $api->get_video_embed( $uid, $block_atts );
			$this->assertNotSame( '', $block_html, "block embed empty for {$label}" );
			$this->assert_embed_flags( $block_html, $bools, 'block:' . $label );

			// Also exercise the render callback wrapper once per matrix run shape.
			$wrapped = $this->with_block_render_context(
				$block_atts,
				static function () use ( $block_atts ) {
					return cloudflare_stream_render_block( $block_atts, '' );
				}
			);
			$this->assertStringContainsString( '<figure', $wrapped, "block wrapper missing for {$label}" );
			$this->assert_embed_flags( $wrapped, $bools, 'block-render:' . $label );
		}

		// Template expects real booleans from its callers.
		$direct = $api->get_video_embed_template(
			$uid,
			array(
				'controls' => false,
				'autoplay' => false,
				'muted'    => false,
				'loop'     => false,
				'preload'  => false,
			)
		);
		$this->assertStringContainsString( 'controls=false', $direct, 'template must honour controls=false' );

		// Shortcode string "true"/"false" values normalise before the template.
		$this->assertTrue( Cloudflare_Stream_API::normalize_bool( 'true' ) );
		$this->assertFalse( Cloudflare_Stream_API::normalize_bool( 'false' ) );
		$this->assertTrue( Cloudflare_Stream_API::normalize_bool( true ) );
		$this->assertFalse( Cloudflare_Stream_API::normalize_bool( false ) );
	}

	/**
	 * Assert playback query flags on an embed HTML string.
	 *
	 * @param string $html  Embed HTML.
	 * @param array  $bools Requested flag values (autoplay implies muted in output).
	 * @param string $label Case label for messages.
	 */
	private function assert_embed_flags( $html, array $bools, $label ) {
		$this->assertStringContainsString( 'iframe.', $html, "{$label}: iframe host missing" );
		$this->assertStringContainsString( 'poster=', $html, "{$label}: poster missing" );

		if ( ! empty( $bools['autoplay'] ) ) {
			$this->assertStringContainsString( 'autoplay=true', $html, "{$label}: expected autoplay=true" );
		} else {
			$this->assertStringNotContainsString( 'autoplay=true', $html, "{$label}: unexpected autoplay=true" );
		}

		// Autoplay always forces muted in the emitted query string.
		$expect_muted = ! empty( $bools['muted'] ) || ! empty( $bools['autoplay'] );
		if ( $expect_muted ) {
			$this->assertStringContainsString( 'muted=true', $html, "{$label}: expected muted=true" );
		} else {
			$this->assertStringNotContainsString( 'muted=true', $html, "{$label}: unexpected muted=true" );
		}

		if ( ! empty( $bools['loop'] ) ) {
			$this->assertStringContainsString( 'loop=true', $html, "{$label}: expected loop=true" );
		} else {
			$this->assertStringNotContainsString( 'loop=true', $html, "{$label}: unexpected loop=true" );
		}

		if ( ! empty( $bools['preload'] ) ) {
			$this->assertStringContainsString( 'preload=auto', $html, "{$label}: expected preload=auto" );
		} else {
			$this->assertStringNotContainsString( 'preload=auto', $html, "{$label}: unexpected preload=auto" );
		}

		// controls=false is only written when controls is explicitly off.
		if ( empty( $bools['controls'] ) ) {
			$this->assertStringContainsString( 'controls=false', $html, "{$label}: expected controls=false" );
		} else {
			$this->assertStringNotContainsString( 'controls=false', $html, "{$label}: unexpected controls=false" );
		}
	}

	/**
	 * Library listing under signed mode without a local key mints only a budgeted set of thumbnails.
	 */
	public function test_admin_mint_budget_limits_library_thumbnails() {
		cfstream_test_clear_http_attempts();

		$this->reset_signing_runtime_state();

		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION, 60 );
		update_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN, 'cloudflarestream.com' );
		update_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, 0 );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID );
		delete_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		$limit  = 40;
		$budget = (int) Cloudflare_Stream_API::ADMIN_MINT_BUDGET;
		$this->assertSame( 12, $budget );
		$this->assertSame( 40, (int) Cloudflare_Stream_API::instance()->api_limit );

		// Reset again after the api_limit probe created a singleton.
		$this->reset_signing_runtime_state();
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, true );
		update_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION, 60 );
		update_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN, 'cloudflarestream.com' );
		update_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, 0 );

		$videos = array();
		for ( $i = 0; $i < $limit; $i++ ) {
			$uid      = sprintf( 'c%031x', $i );
			$videos[] = (object) array(
				'uid'      => $uid,
				'created'  => '2024-01-01T00:00:00Z',
				'size'     => 1000,
				'duration' => 10,
				'meta'     => (object) array(
					'name' => 'Video ' . $i,
				),
			);
		}

		$filter = static function ( $pre, $url ) use ( $videos ) {
			unset( $pre );
			$url  = (string) $url;
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			if ( false !== strpos( $url, '/stream?' ) || preg_match( '#/stream/?$#', $path ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success' => true,
							'result'  => $videos,
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

			if ( false !== strpos( $url, '/token' ) ) {
				$token = 'minted-' . md5( $url );
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success' => true,
							'result'  => array(
								'token' => $token,
							),
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

			return null;
		};
		add_filter( 'cfstream_test_pre_http_response', $filter, 10, 2 );

		$api = Cloudflare_Stream_API::instance();
		$this->assertFalse( $api->has_signing_key() );
		$this->assertTrue( $api->is_signed_playback_enabled() );

		// Mirror the library AJAX path: list videos, then budgeted playback ids.
		$listed = $api->get_videos(
			array(
				'query' => 'limit=' . $limit,
			)
		);
		$this->assertIsObject( $listed );
		$this->assertNotEmpty( $listed->result );
		$this->assertCount( $limit, $listed->result );

		$poster_time = absint( get_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME ) ) . 's';
		$with_thumb  = 0;
		$rows        = array();

		foreach ( $listed->result as $video ) {
			$playback_id  = $api->get_playback_id( $video->uid, true );
			$signed_thumb = ( false !== $playback_id )
				? $api->get_poster_url( $playback_id, $poster_time )
				: '';
			$rows[]       = array(
				'uid'   => $video->uid,
				'thumb' => $signed_thumb,
			);
			if ( '' !== $signed_thumb ) {
				++$with_thumb;
			}
		}

		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$this->assertCount( $limit, $rows );
		$this->assertSame(
			$budget,
			$with_thumb,
			'signed library listing should attach thumbnails for ' . $budget . ' of ' . $limit . ' rows'
		);

		cfstream_test_clear_http_attempts();
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

		// Reset singletons. setAccessible is only required below PHP 8.1.
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
	 * Provide enough block context for get_block_wrapper_attributes().
	 *
	 * @param array    $attrs    Block attributes.
	 * @param callable $callback Render callback.
	 * @return mixed
	 */
	private function with_block_render_context( array $attrs, $callback ) {
		$previous = null;
		if ( class_exists( 'WP_Block_Supports', false ) ) {
			$previous = WP_Block_Supports::$block_to_render;
			WP_Block_Supports::$block_to_render = array(
				'blockName' => 'cloudflare-stream/block-video',
				'attrs'     => $attrs,
			);
		}
		try {
			return $callback();
		} finally {
			if ( class_exists( 'WP_Block_Supports', false ) ) {
				WP_Block_Supports::$block_to_render = $previous;
			}
		}
	}
}
