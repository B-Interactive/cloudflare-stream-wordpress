<?php
/**
 * PHPUnit bootstrap for wp-env / wordpress-develop.
 *
 * @package cloudflare-stream
 */

$cfstream_root = dirname( __DIR__ );

// Composer autoload (plugin deps + PHPUnit Polyfills when installed via Composer).
$autoload = $cfstream_root . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

// WP core test bootstrap requires Yoast PHPUnit Polyfills (WP 5.9+ / 6.x / 7.x).
// Load explicitly so the path is correct even if only the package tree is present.
if ( ! class_exists( '\Yoast\PHPUnitPolyfills\Autoload', false ) ) {
	$polyfills_autoload = $cfstream_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
	if ( is_readable( $polyfills_autoload ) ) {
		require_once $polyfills_autoload;
	}
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$polyfills_root = $cfstream_root . '/vendor/yoast/phpunit-polyfills';
	if ( is_dir( $polyfills_root ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_root );
	}
}

/**
 * Locate the WordPress tests library.
 *
 * Order: WP_TESTS_DIR (wp-env sets this), WP_PHPUNIT__DIR, then common paths.
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

	// wp-env mounts the suite here in tests-cli / tests-wordpress.
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
		"Set WP_TESTS_DIR, or run via: npx wp-env run tests-cli ...\n"
	);
	exit( 1 );
}

if ( ! class_exists( '\Yoast\PHPUnitPolyfills\Autoload', false ) ) {
	fwrite(
		STDERR,
		"yoast/phpunit-polyfills is required for the WordPress PHPUnit suite.\n" .
		"Run: composer install\n"
	);
	exit( 1 );
}

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
putenv( 'WP_TESTS_DIR=' . $_tests_dir );
$_SERVER['WP_TESTS_DIR'] = $_tests_dir;

// Point WP core bootstrap at wp-env's generated config when present.
// Prefer WP_TESTS_CONFIG_FILE_PATH (core) over the non-standard WP_PHPUNIT__* name.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	$config_candidates = array(
		'/wordpress-phpunit/wp-tests-config.php',
		$_tests_dir . '/wp-tests-config.php',
		$cfstream_root . '/tests/wp-tests-config.php',
	);
	foreach ( $config_candidates as $config ) {
		if ( is_readable( $config ) ) {
			define( 'WP_TESTS_CONFIG_FILE_PATH', $config );
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
