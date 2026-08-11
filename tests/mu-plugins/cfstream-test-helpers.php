<?php
/**
 * Test MU-plugin: dummy API constants and blocked outbound HTTP.
 *
 * @package cloudflare-stream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Safe if mapped as MU-plugin and also required from the plugin tests path.
if ( ! defined( 'CFSTREAM_TEST_HELPERS_LOADED' ) ) {
	define( 'CFSTREAM_TEST_HELPERS_LOADED', true );

	if ( ! defined( 'CLOUDFLARE_STREAM_API_TOKEN' ) ) {
		define( 'CLOUDFLARE_STREAM_API_TOKEN', 'test-token-not-real' );
	}

	if ( ! defined( 'CLOUDFLARE_STREAM_API_ACCOUNT' ) ) {
		define( 'CLOUDFLARE_STREAM_API_ACCOUNT', 'test-account-not-real' );
	}

	/**
	 * Recorded HTTP attempt URLs for the current request lifecycle.
	 *
	 * @var string[]
	 */
	$GLOBALS['cfstream_test_http_attempts'] = array();

	/**
	 * Return recorded outbound HTTP URLs.
	 *
	 * @return string[]
	 */
	function cfstream_test_get_http_attempts() {
		if ( empty( $GLOBALS['cfstream_test_http_attempts'] ) || ! is_array( $GLOBALS['cfstream_test_http_attempts'] ) ) {
			return array();
		}
		return array_values( $GLOBALS['cfstream_test_http_attempts'] );
	}

	/**
	 * Clear recorded outbound HTTP URLs.
	 */
	function cfstream_test_clear_http_attempts() {
		$GLOBALS['cfstream_test_http_attempts'] = array();
	}

	/**
	 * Block real HTTP; record the URL. Tests may return a canned response via
	 * `cfstream_test_pre_http_response` (null keeps the block).
	 *
	 * @param false|array|WP_Error $preempt Short-circuit value.
	 * @param array                $args    Request arguments.
	 * @param string               $url     Request URL.
	 * @return array|WP_Error
	 */
	function cfstream_test_pre_http_request( $preempt, $args, $url ) {
		unset( $preempt, $args );

		if ( ! isset( $GLOBALS['cfstream_test_http_attempts'] ) || ! is_array( $GLOBALS['cfstream_test_http_attempts'] ) ) {
			$GLOBALS['cfstream_test_http_attempts'] = array();
		}
		$GLOBALS['cfstream_test_http_attempts'][] = (string) $url;

		/**
		 * Canned HTTP response for a test. Null keeps the default block.
		 *
		 * @param null|array|WP_Error $response Default null.
		 * @param string              $url      Request URL.
		 */
		$canned = apply_filters( 'cfstream_test_pre_http_response', null, $url );
		if ( null !== $canned ) {
			return $canned;
		}

		return new WP_Error(
			'cfstream_http_blocked',
			sprintf( 'Outbound HTTP blocked in tests: %s', $url )
		);
	}

	add_filter( 'pre_http_request', 'cfstream_test_pre_http_request', 1, 3 );

	/**
	 * Create a user with manage_options who is not a super admin.
	 *
	 * On single site, is_super_admin() is true for anyone with delete_users.
	 * A dedicated role grants manage_options without delete_users so the two
	 * gates can be tested apart.
	 *
	 * @return int User ID, or 0 on failure.
	 */
	function cfstream_test_create_manage_options_user() {
		if ( ! function_exists( 'wp_insert_user' ) ) {
			return 0;
		}

		$role = 'cfstream_options_manager';
		if ( ! get_role( $role ) ) {
			add_role(
				$role,
				'Cloudflare Stream Options Manager',
				array(
					'read'           => true,
					'manage_options' => true,
				)
			);
		}

		$login = 'cfstream_manage_options_' . wp_generate_password( 6, false, false );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16, true, true ),
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);

		if ( is_wp_error( $id ) ) {
			return 0;
		}

		if ( is_multisite() && function_exists( 'revoke_super_admin' ) ) {
			revoke_super_admin( (int) $id );
		}

		return (int) $id;
	}
}
