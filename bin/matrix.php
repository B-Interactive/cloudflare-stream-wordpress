#!/usr/bin/env php
<?php
/**
 * Emit version matrix JSON from readme.txt headers.
 *
 * @package cloudflare-stream
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "bin/matrix.php must be run from the CLI.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$readme_path = $root . '/readme.txt';

if ( ! is_readable( $readme_path ) ) {
	fwrite( STDERR, "Cannot read readme.txt at {$readme_path}\n" );
	exit( 1 );
}

$readme = file_get_contents( $readme_path );
if ( false === $readme ) {
	fwrite( STDERR, "Failed to read readme.txt\n" );
	exit( 1 );
}

/**
 * Pull a single header value from readme.txt body text.
 *
 * @param string $body Full file contents.
 * @param string $label Header label without trailing colon.
 * @return string|null
 */
function cfstream_matrix_header( $body, $label ) {
	$pattern = '/^' . preg_quote( $label, '/' ) . ':\s*(.+)$/mi';
	if ( ! preg_match( $pattern, $body, $m ) ) {
		return null;
	}
	return trim( $m[1] );
}

/**
 * Normalise a dotted version to major.minor (strip patch).
 *
 * @param string $version Raw version string.
 * @return string|null
 */
function cfstream_matrix_major_minor( $version ) {
	if ( ! preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
		return null;
	}
	return $m[1] . '.' . $m[2];
}

$php_min_raw = cfstream_matrix_header( $readme, 'Requires PHP' );
$wp_min_raw  = cfstream_matrix_header( $readme, 'Requires at least' );
$wp_max_raw  = cfstream_matrix_header( $readme, 'Tested up to' );

$missing = array();
if ( null === $php_min_raw ) {
	$missing[] = 'Requires PHP';
}
if ( null === $wp_min_raw ) {
	$missing[] = 'Requires at least';
}
if ( null === $wp_max_raw ) {
	$missing[] = 'Tested up to';
}
if ( $missing ) {
	fwrite( STDERR, 'Missing or unparseable readme.txt headers: ' . implode( ', ', $missing ) . "\n" );
	exit( 1 );
}

$php_min = cfstream_matrix_major_minor( $php_min_raw );
$wp_min  = cfstream_matrix_major_minor( $wp_min_raw );
$wp_max  = cfstream_matrix_major_minor( $wp_max_raw );

if ( null === $php_min || null === $wp_min || null === $wp_max ) {
	fwrite( STDERR, "Could not normalise version headers from readme.txt\n" );
	fwrite( STDERR, "  Requires PHP: {$php_min_raw}\n" );
	fwrite( STDERR, "  Requires at least: {$wp_min_raw}\n" );
	fwrite( STDERR, "  Tested up to: {$wp_max_raw}\n" );
	exit( 1 );
}

// PHP versions at or above php_min.
$php_candidates = array( '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );
$php_versions   = array();
foreach ( $php_candidates as $ver ) {
	if ( version_compare( $ver, $php_min, '>=' ) ) {
		$php_versions[] = $ver;
	}
}

if ( ! $php_versions ) {
	fwrite( STDERR, "No PHP matrix versions remain at or above php_min {$php_min}\n" );
	exit( 1 );
}

$php_max = $php_versions[ count( $php_versions ) - 1 ];

// Newest line may lag rulesets; mark continue-on-error in CI.
$php_continue_on_error = array();
if ( in_array( '8.5', $php_versions, true ) ) {
	$php_continue_on_error[] = '8.5';
}

$php_include = array();
foreach ( $php_versions as $ver ) {
	$php_include[] = array(
		'php'               => $ver,
		'continue_on_error' => in_array( $ver, $php_continue_on_error, true ),
	);
}

// One minor below php_min for fixture expect-fail.
$below_parts = array_map( 'intval', explode( '.', $php_min ) );
if ( $below_parts[1] > 0 ) {
	$php_below_floor = $below_parts[0] . '.' . ( $below_parts[1] - 1 );
} else {
	$php_below_floor = ( $below_parts[0] - 1 ) . '.0';
}

// WP × PHP pairs for PHPUnit.
$wp_php_matrix = array(
	'include' => array(
		array(
			'wp'                => $wp_min,
			'php'               => $php_min,
			'continue_on_error' => false,
		),
		array(
			'wp'                => $wp_min,
			'php'               => '8.3',
			'continue_on_error' => false,
		),
		array(
			'wp'                => $wp_max,
			'php'               => '8.3',
			'continue_on_error' => false,
		),
		array(
			'wp'                => $wp_max,
			'php'               => $php_min,
			'continue_on_error' => false,
		),
		array(
			'wp'                => 'trunk',
			'php'               => '8.4',
			'continue_on_error' => true,
		),
	),
);

$out = array(
	'php_min'               => $php_min,
	'php_max'               => $php_max,
	'wp_min'                => $wp_min,
	'wp_max'                => $wp_max,
	'php_versions'          => $php_versions,
	'php_continue_on_error' => $php_continue_on_error,
	'php_below_floor'       => $php_below_floor,
	'php_matrix'            => array(
		'include' => $php_include,
	),
	'wp_php_matrix'         => $wp_php_matrix,
);

$json = json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
if ( false === $json ) {
	fwrite( STDERR, "Failed to encode matrix JSON\n" );
	exit( 1 );
}

echo $json . "\n";
exit( 0 );
