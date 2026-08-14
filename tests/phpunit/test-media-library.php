<?php
/**
 * Stream media library query builder, rename guard, and attachment payload.
 *
 * @package cloudflare-stream
 */

/**
 * @group ajax
 */
class Test_CFStream_Media_Library extends WP_Ajax_UnitTestCase {

	/**
	 * Administrator user id.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Set up each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		cfstream_test_clear_http_attempts();
	}

	/**
	 * Tear down each test.
	 */
	public function tear_down() {
		cfstream_test_clear_http_attempts();
		parent::tear_down();
	}

	/**
	 * Structured builder keeps only named fields and never a raw query fragment.
	 */
	public function test_build_stream_list_query_named_fields_only() {
		$built = cloudflare_stream_build_stream_list_query(
			array(
				'search'       => 'puppy demo',
				'before'       => '2024-01-15T12:00:00.000Z',
				'asc'          => 'false',
				'limit'        => 10,
				'exclude_uids' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa, not-a-uid, bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
				'query'        => 'asc=false&end=evil',
			)
		);

		$this->assertArrayHasKey( 'query', $built );
		$this->assertArrayHasKey( 'exclude_uids', $built );
		$this->assertSame( 'puppy demo', $built['query']['search'] );
		$this->assertSame( '2024-01-15T12:00:00.000Z', $built['query']['before'] );
		$this->assertSame( 'false', $built['query']['asc'] );
		$this->assertSame( 10, $built['query']['limit'] );
		$this->assertArrayNotHasKey( 'query', $built['query'] );
		$this->assertSame(
			array(
				'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
				'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
			),
			$built['exclude_uids']
		);
	}

	/**
	 * Invalid timestamps and empty search are dropped.
	 */
	public function test_build_stream_list_query_rejects_bad_values() {
		$built = cloudflare_stream_build_stream_list_query(
			array(
				'search' => '   ',
				'before' => 'not-a-date',
				'after'  => '2024-13-99T99:99:99Z',
				'asc'    => 'sideways',
			)
		);

		$this->assertArrayNotHasKey( 'search', $built['query'] );
		$this->assertArrayNotHasKey( 'before', $built['query'] );
		$this->assertArrayNotHasKey( 'after', $built['query'] );
		$this->assertSame( 'false', $built['query']['asc'] );
		$this->assertNotEmpty( $built['query']['limit'] );
	}

	/**
	 * get_videos encodes named query args rather than appending a client fragment.
	 */
	public function test_get_videos_encodes_named_query_args() {
		cfstream_test_clear_http_attempts();

		$filter = static function ( $response, $url ) {
			unset( $response );
			if ( false === strpos( $url, '/stream' ) ) {
				return null;
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'success' => true,
						'result'  => array(),
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

		$api = Cloudflare_Stream_API::instance();
		$api->get_videos(
			array(
				'query' => array(
					'limit'  => 5,
					'asc'    => 'false',
					'search' => 'a b&c',
					'before' => '2024-01-15T12:00:00.000Z',
				),
			)
		);

		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$attempts = cfstream_test_get_http_attempts();
		$this->assertNotEmpty( $attempts );
		$url = end( $attempts );
		$this->assertStringContainsString( '/stream?', $url );
		$this->assertStringContainsString( 'limit=5', $url );
		$this->assertStringContainsString( 'asc=false', $url );
		$this->assertMatchesRegularExpression( '/[?&]before=2024-01-15T12(%3A|:)00(%3A|:)00/', $url );
		// Space and ampersand must be encoded, not raw-appended.
		$this->assertStringContainsString( 'search=', $url );
		$this->assertStringNotContainsString( 'search=a b&c', $url );
		$this->assertStringNotContainsString( 'query=asc=false', $url );
		$parts = wp_parse_url( $url );
		$this->assertArrayHasKey( 'query', $parts );
		parse_str( $parts['query'], $query_args );
		$this->assertSame( '5', (string) $query_args['limit'] );
		$this->assertSame( 'a b&c', $query_args['search'] );
		$this->assertSame( '2024-01-15T12:00:00.000Z', $query_args['before'] );
	}

	/**
	 * Empty title is rejected and does not call Cloudflare.
	 */
	public function test_ajax_update_rejects_empty_title() {
		cfstream_test_clear_http_attempts();

		$_REQUEST['action'] = 'cloudflare-stream-update';
		$_REQUEST['nonce']  = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
		$_REQUEST['uid']    = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
		$_REQUEST['title']  = '   ';
		$_REQUEST['upload'] = 'keep-me';
		$_GET['nonce']      = $_REQUEST['nonce'];
		$_POST['nonce']     = $_REQUEST['nonce'];
		$this->_last_response = '';

		try {
			$this->_handleAjax( 'cloudflare-stream-update' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		$this->assertFalse( $decoded['success'] );
		$this->assertSame( array(), cfstream_test_get_http_attempts() );
	}

	/**
	 * Attachment payload delete nonce is a real nonce for managers, not the action name.
	 */
	public function test_query_attachments_delete_nonce_is_real() {
		cfstream_test_clear_http_attempts();

		$uid = 'cccccccccccccccccccccccccccccccc';
		$filter = static function ( $response, $url ) use ( $uid ) {
			unset( $response );
			if ( false === strpos( $url, '/stream' ) ) {
				return null;
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'success' => true,
						'result'  => array(
							array(
								'uid'      => $uid,
								'created'  => '2024-01-15T12:00:00.000000Z',
								'size'     => 1024,
								'duration' => 12,
								'meta'     => array(
									'name' => 'Demo',
								),
							),
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

		$_REQUEST['action'] = 'query-cloudflare-stream-attachments';
		$_REQUEST['nonce']  = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
		$_REQUEST['search'] = 'Demo';
		$_GET['nonce']      = $_REQUEST['nonce'];
		$_POST['nonce']     = $_REQUEST['nonce'];
		$this->_last_response = '';

		try {
			$this->_handleAjax( 'query-cloudflare-stream-attachments' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}

		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['success'] );
		$this->assertNotEmpty( $decoded['data'] );
		$row = $decoded['data'][0];
		$this->assertArrayHasKey( 'nonces', $row );
		$this->assertArrayHasKey( 'delete', $row['nonces'] );
		$this->assertNotSame( Cloudflare_Stream_Settings::NONCE, $row['nonces']['delete'] );
		$this->assertNotEmpty( $row['nonces']['delete'] );
		// wp_verify_nonce returns 1 or 2 when valid.
		$this->assertNotFalse(
			wp_verify_nonce( $row['nonces']['delete'], Cloudflare_Stream_Settings::NONCE )
		);

		// Search reached Cloudflare as a named query arg.
		$attempts = cfstream_test_get_http_attempts();
		$this->assertNotEmpty( $attempts );
		$stream_urls = array_values(
			array_filter(
				$attempts,
				static function ( $url ) {
					return false !== strpos( $url, '/stream' );
				}
			)
		);
		$this->assertNotEmpty( $stream_urls );
		$this->assertStringContainsString( 'search=Demo', $stream_urls[0] );
		$this->assertArrayHasKey( 'query', $decoded['args'] );
		$this->assertIsArray( $decoded['args']['query'] );
		$this->assertSame( 'Demo', $decoded['args']['query']['search'] );
	}

	/**
	 * Exclude UIDs are applied to the attachment list response.
	 */
	public function test_query_attachments_excludes_uids() {
		$keep = 'dddddddddddddddddddddddddddddddd';
		$drop = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

		$filter = static function ( $response, $url ) use ( $keep, $drop ) {
			unset( $response );
			if ( false === strpos( $url, '/stream' ) ) {
				return null;
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'success' => true,
						'result'  => array(
							array(
								'uid'      => $drop,
								'created'  => '2024-01-15T12:00:00.000000Z',
								'size'     => 1,
								'duration' => 1,
								'meta'     => array( 'name' => 'Drop' ),
							),
							array(
								'uid'      => $keep,
								'created'  => '2024-01-14T12:00:00.000000Z',
								'size'     => 1,
								'duration' => 1,
								'meta'     => array( 'name' => 'Keep' ),
							),
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

		$_REQUEST['action']       = 'query-cloudflare-stream-attachments';
		$_REQUEST['nonce']        = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
		$_REQUEST['exclude_uids'] = $drop;
		$_GET['nonce']            = $_REQUEST['nonce'];
		$_POST['nonce']           = $_REQUEST['nonce'];
		$this->_last_response     = '';

		try {
			$this->_handleAjax( 'query-cloudflare-stream-attachments' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		} catch ( WPAjaxDieStopException $e ) {
			unset( $e );
		}

		remove_filter( 'cfstream_test_pre_http_response', $filter, 10 );

		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded );
		$this->assertTrue( $decoded['success'] );
		$this->assertCount( 1, $decoded['data'] );
		$this->assertSame( $keep, $decoded['data'][0]['uid'] );
	}
}
