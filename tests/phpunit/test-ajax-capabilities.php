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
	);

	/**
	 * Subscriber with a valid nonce must be rejected by each handler.
	 */
	public function test_subscriber_forbidden_with_valid_nonce() {
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

		$rejected = false;
		try {
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
		}

		$this->assertTrue( $rejected, 'cloudflare_stream_verify_ajax_capability must stop subscribers' );
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
}
