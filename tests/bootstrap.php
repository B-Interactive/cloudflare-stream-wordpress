<?php
/**
 * PHPUnit bootstrap for wp-env / wordpress-develop.
 *
 * @package cloudflare-stream
 */

$cfstream_root = dirname( __DIR__ );

// Composer autoload.
$autoload = $cfstream_root . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

/**
 * Locate the WordPress tests library.
 *
 * Order: WP_TESTS_DIR, WP_PHPUNIT__DIR, then common wp-env paths.
 */
function cfstream_locate_wp_tests_dir() {
	$candidates = array();

	if ( getenv( 'WP_TESTS_DIR' ) ) {
		$candidates[] = getenv( 'WP_TESTS_DIR' );
	}
	if ( getenv( 'WP_PHPUNIT__DIR' ) ) {
		$candidates[] = getenv( 'WP_PHPUNIT__DIR' );
	}
	if ( defined( 'WP_PHPUNIT__DIR' ) ) {
		$candidates[] = WP_PHPUNIT__DIR;
	}

	$candidates[] = '/wordpress-phpunit';
	$candidates[] = '/var/www/html/wordpress-develop/tests/phpunit';
	$candidates[] = '/wordpress-develop/tests/phpunit';
	$candidates[] = '/tmp/wordpress-tests-lib';

	// Sibling wordpress-develop checkout.
	$candidates[] = dirname( dirname( __DIR__ ) ) . '/wordpress-develop/tests/phpunit';

	foreach ( $candidates as $dir ) {
		if ( ! is_string( $dir ) || '' === $dir ) {
			continue;
		}
		$dir = rtrim( $dir, '/\\' );
		if ( is_readable( $dir . '/includes/functions.php' ) ) {
			return $dir;
		}
	}

	return null;
}

$_tests_dir = cfstream_locate_wp_tests_dir();

if ( ! $_tests_dir ) {
	fwrite(
		STDERR,
		"Could not locate WordPress test library.\n" .
		"Set WP_TESTS_DIR, or run via: npx wp-env run tests-wordpress ...\n"
	);
	exit( 1 );
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
putenv( 'WP_TESTS_DIR=' . $_tests_dir );
$_SERVER['WP_TESTS_DIR'] = $_tests_dir;

// Prefer a readable wp-tests-config when unset.
if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	$config_candidates = array(
		$cfstream_root . '/tests/wp-tests-config.php',
		'/wordpress-phpunit/wp-tests-config.php',
		$_tests_dir . '/wp-tests-config.php',
	);
	foreach ( $config_candidates as $config ) {
		if ( is_readable( $config ) ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $config );
			break;
		}
	}
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load plugin and test MU helpers before full WP bootstrap.
 */
function cfstream_manually_load_plugin() {
	$root = dirname( __DIR__ );

	$mu = $root . '/tests/mu-plugins/cfstream-test-helpers.php';
	if ( is_readable( $mu ) ) {
		require $mu;
	}

	require $root . '/cloudflare-stream.php';
}
tests_add_filter( 'muplugins_loaded', 'cfstream_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

require_once $cfstream_root . '/tests/smoke/class-cfstream-smoke-assertions.php';
