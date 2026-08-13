<?php
/**
 * GitHub updater unit tests.
 *
 * @package cloudflare-stream
 */

/**
 * @group updater
 */
class Test_CFStream_Updater extends WP_UnitTestCase {

	/**
	 * Active canned HTTP response filter callback.
	 *
	 * @var callable|null
	 */
	private $http_filter = null;

	/**
	 * Reset HTTP tripwire and release cache before each test.
	 */
	public function set_up() {
		parent::set_up();
		if ( function_exists( 'cfstream_test_clear_http_attempts' ) ) {
			cfstream_test_clear_http_attempts();
		}
		$this->clear_release_cache();
		$this->remove_http_filter();
	}

	/**
	 * Drop canned HTTP filters after each test.
	 */
	public function tear_down() {
		$this->remove_http_filter();
		$this->clear_release_cache();
		if ( function_exists( 'cfstream_test_clear_http_attempts' ) ) {
			cfstream_test_clear_http_attempts();
		}
		parent::tear_down();
	}

	/**
	 * Delete the GitHub release site transient.
	 */
	private function clear_release_cache() {
		delete_site_transient( Cloudflare_Stream_Updater::TRANSIENT_RELEASE );
	}

	/**
	 * Remove any active canned HTTP filter.
	 */
	private function remove_http_filter() {
		if ( null !== $this->http_filter ) {
			remove_filter( 'cfstream_test_pre_http_response', $this->http_filter, 10 );
			$this->http_filter = null;
		}
	}

	/**
	 * Install a canned GitHub API response for the latest release endpoint.
	 *
	 * @param array|WP_Error $payload Response body array, or WP_Error to simulate failure.
	 * @param int            $code    HTTP status code when payload is an array.
	 */
	private function mock_github_latest( $payload, $code = 200 ) {
		$this->remove_http_filter();

		$this->http_filter = function ( $pre, $url ) use ( $payload, $code ) {
			unset( $pre );

			if ( false === strpos( (string) $url, 'api.github.com/repos/B-Interactive/cloudflare-stream-wordpress/releases/latest' ) ) {
				return null;
			}

			if ( is_wp_error( $payload ) ) {
				return $payload;
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $payload ),
				'response' => array(
					'code'    => (int) $code,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'cfstream_test_pre_http_response', $this->http_filter, 10, 2 );
	}

	/**
	 * Fixture release JSON matching a public GitHub latest release.
	 *
	 * @param string $tag_name Tag name, with or without a leading v.
	 * @param array  $args     Optional overrides (draft, prerelease, asset_name, body).
	 * @return array
	 */
	private function fixture_release( $tag_name = '1.2.0', $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'draft'      => false,
				'prerelease' => false,
				'asset_name' => 'cloudflare-stream.zip',
				'body'       => "### Changed\n- Example release notes",
			)
		);

		$version_path = ltrim( (string) $tag_name, 'vV' );

		return array(
			'tag_name'     => (string) $tag_name,
			'name'         => (string) $tag_name,
			'draft'        => (bool) $args['draft'],
			'prerelease'   => (bool) $args['prerelease'],
			'html_url'     => 'https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/tag/' . rawurlencode( (string) $tag_name ),
			'body'         => (string) $args['body'],
			'published_at' => '2026-01-15T12:00:00Z',
			'assets'       => array(
				array(
					'name'                 => (string) $args['asset_name'],
					'browser_download_url' => 'https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/download/' . rawurlencode( (string) $tag_name ) . '/' . rawurlencode( (string) $args['asset_name'] ),
				),
			),
			// Keep unused path noise out of assertions.
			'_fixture_version_path' => $version_path,
		);
	}

	/**
	 * Updater class is loaded and hooks are registered.
	 */
	public function test_updater_class_and_hooks() {
		$this->assertTrue( class_exists( 'Cloudflare_Stream_Updater' ) );
		$updater = Cloudflare_Stream_Updater::instance();
		$this->assertInstanceOf( 'Cloudflare_Stream_Updater', $updater );
		$this->assertNotFalse( has_filter( 'update_plugins_github.com', array( $updater, 'filter_update_plugins' ) ) );
		$this->assertNotFalse( has_filter( 'plugins_api', array( $updater, 'filter_plugins_api' ) ) );
	}

	/**
	 * Leading v is stripped from release tags.
	 */
	public function test_normalise_version_strips_leading_v() {
		$updater = Cloudflare_Stream_Updater::instance();
		$this->assertSame( '1.2.0', $updater->normalise_version( 'v1.2.0' ) );
		$this->assertSame( '1.2.0', $updater->normalise_version( '1.2.0' ) );
		$this->assertSame( '1.2.0-beta.1', $updater->normalise_version( 'v1.2.0-beta.1' ) );
	}

	/**
	 * GitHub owner and repo are derived from the canonical Update URI.
	 */
	public function test_github_repository_is_derived_from_update_uri() {
		$updater = Cloudflare_Stream_Updater::instance();
		$repo    = $updater->get_github_repository();

		$this->assertIsArray( $repo );
		$this->assertSame( 'B-Interactive', $repo['owner'] );
		$this->assertSame( 'cloudflare-stream-wordpress', $repo['repo'] );
		$this->assertSame(
			'https://github.com/B-Interactive/cloudflare-stream-wordpress',
			untrailingslashit( Cloudflare_Stream_Updater::UPDATE_URI )
		);
	}

	/**
	 * Draft and prerelease payloads are rejected; missing zip asset is rejected.
	 */
	public function test_parse_release_rejects_unusable_releases() {
		$updater = Cloudflare_Stream_Updater::instance();

		$this->assertFalse(
			$updater->parse_release_response(
				$this->fixture_release( '1.2.0', array( 'draft' => true ) )
			)
		);
		$this->assertFalse(
			$updater->parse_release_response(
				$this->fixture_release( '1.2.0', array( 'prerelease' => true ) )
			)
		);
		$this->assertFalse(
			$updater->parse_release_response(
				$this->fixture_release( '1.2.0', array( 'asset_name' => 'source.zip' ) )
			)
		);
	}

	/**
	 * A valid fixture yields version and package URL.
	 */
	public function test_parse_release_accepts_stable_asset() {
		$updater = Cloudflare_Stream_Updater::instance();
		$parsed  = $updater->parse_release_response( $this->fixture_release( 'v1.2.0' ) );

		$this->assertIsArray( $parsed );
		$this->assertSame( '1.2.0', $parsed['version'] );
		$this->assertSame(
			'https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/download/v1.2.0/cloudflare-stream.zip',
			$parsed['package']
		);
		$this->assertStringContainsString( 'releases/tag/', $parsed['html_url'] );
	}

	/**
	 * Other plugin files are left alone by the update filter.
	 */
	public function test_update_filter_ignores_other_plugins() {
		$updater = Cloudflare_Stream_Updater::instance();
		$other   = array(
			'new_version' => '9.9.9',
			'package'     => 'https://example.com/other.zip',
		);

		$result = $updater->filter_update_plugins(
			$other,
			array( 'Version' => '1.0.0' ),
			'akismet/akismet.php',
			array()
		);

		$this->assertSame( $other, $result );
		$this->assertSame( array(), cfstream_test_get_http_attempts() );
	}

	/**
	 * Fixture release JSON produces the expected update payload fields.
	 */
	public function test_update_filter_returns_payload_from_fixture() {
		$fixture = $this->fixture_release( '1.2.0' );
		$this->mock_github_latest( $fixture );

		$updater = Cloudflare_Stream_Updater::instance();
		$plugin  = $updater->get_plugin_basename();

		$result = $updater->filter_update_plugins(
			false,
			array(
				'Version'      => '1.1.7',
				'RequiresWP'   => '6.9',
				'RequiresPHP'  => '7.4',
				'Name'         => 'Cloudflare Stream',
			),
			$plugin,
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( '1.2.0', $result['new_version'] );
		$this->assertSame( '1.2.0', $result['version'] );
		$this->assertSame( 'cloudflare-stream', $result['slug'] );
		$this->assertSame( $plugin, $result['plugin'] );
		$this->assertSame(
			'https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/download/1.2.0/cloudflare-stream.zip',
			$result['package']
		);
		$this->assertSame( $fixture['html_url'], $result['url'] );
		$this->assertSame( '6.9', $result['requires'] );
		$this->assertSame( '7.4', $result['requires_php'] );
		$this->assertArrayNotHasKey( 'autoupdate', $result );

		$attempts = cfstream_test_get_http_attempts();
		$this->assertNotEmpty( $attempts );
		$this->assertStringContainsString( 'api.github.com/repos/B-Interactive/cloudflare-stream-wordpress/releases/latest', $attempts[0] );
	}

	/**
	 * When the remote version matches the installed version, a payload is still returned.
	 * Core decides response vs no_update via version_compare.
	 */
	public function test_update_filter_returns_payload_when_versions_match() {
		$this->mock_github_latest( $this->fixture_release( '1.1.7' ) );

		$updater = Cloudflare_Stream_Updater::instance();
		$result  = $updater->filter_update_plugins(
			false,
			array(
				'Version'     => '1.1.7',
				'RequiresWP'  => '6.9',
				'RequiresPHP' => '7.4',
			),
			$updater->get_plugin_basename(),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( '1.1.7', $result['new_version'] );
		$this->assertNotEmpty( $result['package'] );
	}

	/**
	 * Failed GitHub lookups fail closed without throwing.
	 */
	public function test_update_filter_fails_closed_on_http_error() {
		$this->mock_github_latest( new WP_Error( 'http_failed', 'network down' ) );

		$updater = Cloudflare_Stream_Updater::instance();
		$result  = $updater->filter_update_plugins(
			false,
			array( 'Version' => '1.1.7' ),
			$updater->get_plugin_basename(),
			array()
		);

		$this->assertFalse( $result );
	}

	/**
	 * Successful release lookups are cached in a site transient.
	 */
	public function test_release_response_is_cached() {
		$this->mock_github_latest( $this->fixture_release( '1.3.0' ) );

		$updater = Cloudflare_Stream_Updater::instance();
		$plugin  = $updater->get_plugin_basename();
		$headers = array( 'Version' => '1.1.7' );

		$first = $updater->filter_update_plugins( false, $headers, $plugin, array() );
		$this->assertSame( '1.3.0', $first['new_version'] );
		$this->assertCount( 1, cfstream_test_get_http_attempts() );

		$second = $updater->filter_update_plugins( false, $headers, $plugin, array() );
		$this->assertSame( '1.3.0', $second['new_version'] );
		$this->assertCount( 1, cfstream_test_get_http_attempts(), 'cached release must not hit the network again' );
	}

	/**
	 * plugins_api ignores other slugs.
	 */
	public function test_plugins_api_ignores_other_slugs() {
		$updater = Cloudflare_Stream_Updater::instance();
		$default = (object) array( 'name' => 'other' );

		$result = $updater->filter_plugins_api(
			$default,
			'plugin_information',
			(object) array( 'slug' => 'akismet' )
		);

		$this->assertSame( $default, $result );
		$this->assertSame( array(), cfstream_test_get_http_attempts() );
	}

	/**
	 * plugins_api returns external-friendly details for this plugin slug.
	 */
	public function test_plugins_api_returns_information_for_this_slug() {
		$fixture = $this->fixture_release( '1.4.0', array( 'body' => "Fixed a bug\nAdded a feature" ) );
		$this->mock_github_latest( $fixture );

		$updater = Cloudflare_Stream_Updater::instance();
		$result  = $updater->filter_plugins_api(
			false,
			'plugin_information',
			(object) array( 'slug' => 'cloudflare-stream' )
		);

		$this->assertIsObject( $result );
		$this->assertSame( 'cloudflare-stream', $result->slug );
		$this->assertSame( '1.4.0', $result->version );
		$this->assertSame(
			'https://github.com/B-Interactive/cloudflare-stream-wordpress/releases/download/1.4.0/cloudflare-stream.zip',
			$result->download_link
		);
		$this->assertTrue( ! empty( $result->external ) );
		$this->assertIsArray( $result->sections );
		$this->assertArrayHasKey( 'description', $result->sections );
		$this->assertArrayHasKey( 'changelog', $result->sections );
		$this->assertStringContainsString( 'Fixed a bug', $result->sections['changelog'] );
	}
}
