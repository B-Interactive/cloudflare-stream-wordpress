<?php
/**
 * AJAX capability gates.
 *
 * @package cloudflare-stream
 */

/**
 * @group ajax
 */
class Test_CFStream_Ajax_Capabilities extends WP_Ajax_UnitTestCase {

	/**
	 * Actions under test.
	 *
	 * @var string[]
	 */
	private $actions = array(
		'query-cloudflare-stream-attachments',
		'cloudflare-stream-check-upload',
		'query-cloudflare-stream-upload',
		'cloudflare-stream-delete',
		'cloudflare-stream-update',
		'cloudflare-stream-playback-urls',
		'cloudflare-stream-preview-bridge',
	);

	/**
	 * Subscriber with a valid nonce must be rejected by each handler.
	 */
	public function test_subscriber_forbidden_with_valid_nonce() {
		$this->assertCount( 7, $this->actions, 'capability suite must cover seven AJAX actions' );
		$this->assertContains( 'cloudflare-stream-playback-urls', $this->actions );
		$this->assertContains( 'cloudflare-stream-preview-bridge', $this->actions );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		foreach ( $this->actions as $action ) {
			$_REQUEST['action'] = $action;
			$_REQUEST['nonce']  = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
			$_GET['nonce']      = $_REQUEST['nonce'];
			$_POST['nonce']     = $_REQUEST['nonce'];
			$this->_last_response = '';

			$rejected = false;
			try {
				$this->_handleAjax( $action );
			} catch ( WPAjaxDieStopException $e ) {
				$rejected = true;
				$code     = (int) $e->getCode();
				if ( $code > 0 ) {
					$this->assertSame( 403, $code, $action . ' stop code' );
				}
			} catch ( WPAjaxDieContinueException $e ) {
				$rejected = true;
				unset( $e );
			}

			$this->assertTrue( $rejected, "{$action} should die for subscriber" );

			if ( is_string( $this->_last_response ) && '' !== $this->_last_response ) {
				$decoded = json_decode( $this->_last_response, true );
				if ( is_array( $decoded ) && array_key_exists( 'success', $decoded ) ) {
					$this->assertFalse( $decoded['success'], "{$action} JSON success must be false" );
				}
			}
		}
	}

	/**
	 * Shared capability helper rejects subscribers.
	 */
	public function test_verify_ajax_capability_gate() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// Direct call may send headers and leave buffers open outside _handleAjax.
		$level        = ob_get_level();
		$rejected     = false;
		$prev_handler = null;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- test harness only.
		$prev_handler = set_error_handler(
			static function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$prev_handler ) {
				if ( 0 === error_reporting() ) {
					return false;
				}
				if ( false !== strpos( $errstr, 'Cannot modify header information' ) ) {
					return true;
				}
				if ( is_callable( $prev_handler ) ) {
					return (bool) call_user_func( $prev_handler, $errno, $errstr, $errfile, $errline );
				}
				return false;
			}
		);
		try {
			ob_start();
			cloudflare_stream_verify_ajax_capability();
		} catch ( WPAjaxDieStopException $e ) {
			$rejected = true;
			$code     = (int) $e->getCode();
			if ( $code > 0 ) {
				$this->assertSame( 403, $code );
			}
		} catch ( WPAjaxDieContinueException $e ) {
			$rejected = true;
			unset( $e );
		} finally {
			restore_error_handler();
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}

		$this->assertTrue( $rejected, 'cloudflare_stream_verify_ajax_capability must stop subscribers' );
	}

	/**
	 * Editor without manage_options is refused on mutating actions only.
	 */
	public function test_editor_split_capabilities() {
		$mutate = array(
			'query-cloudflare-stream-upload',
			'cloudflare-stream-check-upload',
			'cloudflare-stream-delete',
			'cloudflare-stream-update',
		);
		$read   = array(
			'query-cloudflare-stream-attachments',
			'cloudflare-stream-playback-urls',
			'cloudflare-stream-preview-bridge',
		);

		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );
		$this->assertTrue( current_user_can( 'edit_posts' ) );
		$this->assertFalse( current_user_can( 'manage_options' ) );

		foreach ( $mutate as $action ) {
			$this->assert_ajax_forbidden( $action );
			$this->clear_ajax_request_globals();
		}

		// Read actions must not die on the capability gate alone.
		foreach ( $read as $action ) {
			$this->clear_ajax_request_globals();
			$_REQUEST['action']   = $action;
			$_REQUEST['nonce']    = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
			$_GET['nonce']        = $_REQUEST['nonce'];
			$_POST['nonce']       = $_REQUEST['nonce'];
			$_REQUEST['uid']      = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
			$_REQUEST['query']    = '';
			$this->_last_response = '';

			$cap_denied = false;
			try {
				$this->_handleAjax( $action );
			} catch ( WPAjaxDieStopException $e ) {
				$code = (int) $e->getCode();
				if ( 403 === $code ) {
					$cap_denied = true;
				}
			} catch ( WPAjaxDieContinueException $e ) {
				unset( $e );
				if ( is_string( $this->_last_response ) && '' !== $this->_last_response ) {
					$decoded = json_decode( $this->_last_response, true );
					if ( is_array( $decoded ) && ! empty( $decoded['data']['message'] ) && 'Forbidden' === $decoded['data']['message'] ) {
						$cap_denied = true;
					}
				}
			}

			$this->assertFalse( $cap_denied, "{$action} must not refuse an editor on capability grounds" );
			$this->clear_ajax_request_globals();
		}
	}

	/**
	 * manage_options user is not refused by the capability gate on mutating actions.
	 */
	public function test_manage_options_not_cap_denied_on_mutations() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->assertTrue( current_user_can( 'manage_options' ) );

		$mutate = array(
			'query-cloudflare-stream-upload',
			'cloudflare-stream-check-upload',
			'cloudflare-stream-delete',
			'cloudflare-stream-update',
		);

		foreach ( $mutate as $action ) {
			$_REQUEST['action']       = $action;
			$_REQUEST['nonce']        = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
			$_GET['nonce']            = $_REQUEST['nonce'];
			$_POST['nonce']           = $_REQUEST['nonce'];
			$_REQUEST['uid']          = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
			$_REQUEST['uploadLength'] = '1';
			$_REQUEST['title']        = 't';
			$this->_last_response     = '';

			$cap_denied = false;
			try {
				$this->_handleAjax( $action );
			} catch ( WPAjaxDieStopException $e ) {
				if ( 403 === (int) $e->getCode() ) {
					$cap_denied = true;
				}
			} catch ( WPAjaxDieContinueException $e ) {
				unset( $e );
				if ( is_string( $this->_last_response ) && '' !== $this->_last_response ) {
					$decoded = json_decode( $this->_last_response, true );
					if ( is_array( $decoded ) && isset( $decoded['data']['message'] ) && 'Forbidden' === $decoded['data']['message'] ) {
						$cap_denied = true;
					}
				}
			}

			$this->assertFalse( $cap_denied, "{$action} must not 403 a manage_options user on capability" );
		}
	}

	/**
	 * AJAX handlers are registered.
	 */
	public function test_ajax_actions_registered() {
		$smoke = new CFStream_Smoke_Assertions( CFStream_Smoke_Assertions::PROFILE_BOOTSTRAP );
		$smoke->s9_ajax_actions_registered();
		$failures = $smoke->get_failures();
		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Drop AJAX request globals left between iterations.
	 *
	 * @return void
	 */
	private function clear_ajax_request_globals() {
		foreach ( array( 'action', 'nonce', 'uid', 'query' ) as $key ) {
			unset( $_REQUEST[ $key ], $_GET[ $key ], $_POST[ $key ] );
		}
		$this->_last_response = '';
	}

	/**
	 * Assert an AJAX action dies with a capability refusal.
	 *
	 * @param string $action Action name.
	 */
	private function assert_ajax_forbidden( $action ) {
		$this->clear_ajax_request_globals();
		$_REQUEST['action']   = $action;
		$_REQUEST['nonce']    = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
		$_GET['nonce']        = $_REQUEST['nonce'];
		$_POST['nonce']       = $_REQUEST['nonce'];
		$this->_last_response = '';

		$rejected = false;
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieStopException $e ) {
			$rejected = true;
			$code     = (int) $e->getCode();
			if ( $code > 0 ) {
				$this->assertSame( 403, $code, $action . ' stop code' );
			}
		} catch ( WPAjaxDieContinueException $e ) {
			$rejected = true;
			unset( $e );
		}

		$this->assertTrue( $rejected, "{$action} should die for this user" );
	}
}
