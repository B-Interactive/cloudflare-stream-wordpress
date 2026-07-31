#!/usr/bin/env php
<?php
/**
 * Check version and requirement headers match across metadata files.
 *
 * @package cloudflare-stream
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "bin/check-metadata.php must be run from the CLI.\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );

/**
 * Read a file or fail.
 *
 * @param string $path Absolute path.
 * @return string
 */
function cfstream_meta_read( $path ) {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Cannot read {$path}\n" );
		exit( 1 );
	}
	$data = file_get_contents( $path );
	if ( false === $data ) {
		fwrite( STDERR, "Failed to read {$path}\n" );
		exit( 1 );
	}
	return $data;
}

/**
 * Match a header line value.
 *
 * @param string $body    File contents.
 * @param string $label   Header label.
 * @param string $pattern Full regex with one capture group.
 * @return string|null
 */
function cfstream_meta_match( $body, $label, $pattern ) {
	if ( ! preg_match( $pattern, $body, $m ) ) {
		return null;
	}
	return trim( $m[1] );
}

$readme_path = $root . '/readme.txt';
$plugin_path = $root . '/cloudflare-stream.php';
$pkg_path    = $root . '/package.json';

$readme = cfstream_meta_read( $readme_path );
$plugin = cfstream_meta_read( $plugin_path );
$pkg_raw = cfstream_meta_read( $pkg_path );

$pkg = json_decode( $pkg_raw, true );
if ( ! is_array( $pkg ) ) {
	fwrite( STDERR, "package.json is not valid JSON\n" );
	exit( 1 );
}

$values = array(
	'readme.Version'           => cfstream_meta_match( $readme, 'Version', '/^Version:\s*(.+)$/mi' ),
	'readme.Stable tag'        => cfstream_meta_match( $readme, 'Stable tag', '/^Stable tag:\s*(.+)$/mi' ),
	'readme.Requires PHP'      => cfstream_meta_match( $readme, 'Requires PHP', '/^Requires PHP:\s*(.+)$/mi' ),
	'readme.Requires at least' => cfstream_meta_match( $readme, 'Requires at least', '/^Requires at least:\s*(.+)$/mi' ),
	'plugin.Version'           => cfstream_meta_match( $plugin, 'Version', '/^\s*\*\s*Version:\s*(.+)$/mi' ),
	'plugin.Requires PHP'      => cfstream_meta_match( $plugin, 'Requires PHP', '/^\s*\*\s*Requires PHP:\s*(.+)$/mi' ),
	'plugin.Requires at least' => cfstream_meta_match( $plugin, 'Requires at least', '/^\s*\*\s*Requires at least:\s*(.+)$/mi' ),
	'package.json.version'     => isset( $pkg['version'] ) ? (string) $pkg['version'] : null,
);

$errors = array();

foreach ( $values as $key => $val ) {
	if ( null === $val || '' === $val ) {
		$errors[] = "Missing value: {$key}";
	}
}

// Version must match across readme, plugin header, package.json.
$version_keys = array( 'readme.Version', 'plugin.Version', 'package.json.version' );
$version_set  = array();
foreach ( $version_keys as $key ) {
	if ( null !== $values[ $key ] && '' !== $values[ $key ] ) {
		$version_set[ $values[ $key ] ][] = $key;
	}
}
if ( count( $version_set ) > 1 ) {
	$parts = array();
	foreach ( $version_set as $ver => $keys ) {
		$parts[] = $ver . ' (' . implode( ', ', $keys ) . ')';
	}
	$errors[] = 'Version mismatch: ' . implode( ' vs ', $parts );
}

// Stable tag should match Version in readme.
if ( null !== $values['readme.Stable tag'] && null !== $values['readme.Version']
	&& $values['readme.Stable tag'] !== $values['readme.Version'] ) {
	$errors[] = sprintf(
		'Stable tag (%s) does not match readme Version (%s)',
		$values['readme.Stable tag'],
		$values['readme.Version']
	);
}

if ( null !== $values['readme.Requires PHP'] && null !== $values['plugin.Requires PHP']
	&& $values['readme.Requires PHP'] !== $values['plugin.Requires PHP'] ) {
	$errors[] = sprintf(
		'Requires PHP mismatch: readme=%s plugin=%s',
		$values['readme.Requires PHP'],
		$values['plugin.Requires PHP']
	);
}

if ( null !== $values['readme.Requires at least'] && null !== $values['plugin.Requires at least']
	&& $values['readme.Requires at least'] !== $values['plugin.Requires at least'] ) {
	$errors[] = sprintf(
		'Requires at least mismatch: readme=%s plugin=%s',
		$values['readme.Requires at least'],
		$values['plugin.Requires at least']
	);
}

echo "Metadata check\n";
echo str_repeat( '-', 40 ) . "\n";
foreach ( $values as $key => $val ) {
	$display = null === $val ? '(missing)' : $val;
	echo sprintf( "%-28s %s\n", $key, $display );
}
echo str_repeat( '-', 40 ) . "\n";

if ( $errors ) {
	echo "FAIL\n";
	foreach ( $errors as $err ) {
		echo "  - {$err}\n";
	}
	exit( 1 );
}

echo "OK: headers aligned\n";
exit( 0 );
