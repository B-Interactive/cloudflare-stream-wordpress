#!/usr/bin/env php
<?php
/**
 * Check version and requirement headers match across metadata files.
 *
 * Optional required version (release gating):
 *   php bin/check-metadata.php --version=1.1.7
 *   RELEASE_VERSION=1.1.7 php bin/check-metadata.php
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
	unset( $label );
	if ( ! preg_match( $pattern, $body, $m ) ) {
		return null;
	}
	return trim( $m[1] );
}

/**
 * Strip a leading v from a version or tag string.
 *
 * @param string $version Version or tag.
 * @return string
 */
function cfstream_meta_normalise_version( $version ) {
	$version = trim( (string) $version );
	if ( '' === $version ) {
		return '';
	}
	if ( preg_match( '/^v(.+)$/i', $version, $matches ) ) {
		return trim( $matches[1] );
	}
	return $version;
}

/**
 * Parse optional required version from CLI args or RELEASE_VERSION env.
 *
 * @param array $argv CLI arguments.
 * @return string Empty when not required.
 */
function cfstream_meta_required_version( array $argv ) {
	$required = '';

	foreach ( $argv as $index => $arg ) {
		if ( 0 === $index ) {
			continue;
		}
		if ( preg_match( '/^--version=(.+)$/', $arg, $matches ) ) {
			$required = trim( $matches[1] );
			break;
		}
		if ( '--version' === $arg && isset( $argv[ $index + 1 ] ) ) {
			$required = trim( (string) $argv[ $index + 1 ] );
			break;
		}
	}

	if ( '' === $required ) {
		$env = getenv( 'RELEASE_VERSION' );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			$required = trim( $env );
		}
	}

	return cfstream_meta_normalise_version( $required );
}

/**
 * Extract a PHP class constant string value from source.
 *
 * @param string $body          File contents.
 * @param string $constant_name Constant name without class prefix.
 * @return string|null
 */
function cfstream_meta_class_const_string( $body, $constant_name ) {
	$pattern = '/const\s+' . preg_quote( $constant_name, '/' ) . '\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/';
	if ( ! preg_match( $pattern, $body, $matches ) ) {
		return null;
	}
	return trim( $matches[1] );
}

$required_version = cfstream_meta_required_version( $argv );

$readme_path  = $root . '/readme.txt';
$plugin_path  = $root . '/cloudflare-stream.php';
$pkg_path     = $root . '/package.json';
$lock_path    = $root . '/package-lock.json';
$updater_path = $root . '/src/inc/class-cloudflare-stream-updater.php';

$readme  = cfstream_meta_read( $readme_path );
$plugin  = cfstream_meta_read( $plugin_path );
$pkg_raw = cfstream_meta_read( $pkg_path );
$lock_raw = cfstream_meta_read( $lock_path );
$updater = cfstream_meta_read( $updater_path );

$pkg = json_decode( $pkg_raw, true );
if ( ! is_array( $pkg ) ) {
	fwrite( STDERR, "package.json is not valid JSON\n" );
	exit( 1 );
}

$lock = json_decode( $lock_raw, true );
if ( ! is_array( $lock ) ) {
	fwrite( STDERR, "package-lock.json is not valid JSON\n" );
	exit( 1 );
}

$lock_root_version = isset( $lock['version'] ) ? (string) $lock['version'] : null;
$lock_pkg_version  = null;
if ( isset( $lock['packages'] ) && is_array( $lock['packages'] )
	&& isset( $lock['packages'][''] ) && is_array( $lock['packages'][''] )
	&& isset( $lock['packages']['']['version'] ) ) {
	$lock_pkg_version = (string) $lock['packages']['']['version'];
}

$updater_update_uri = cfstream_meta_class_const_string( $updater, 'UPDATE_URI' );

$values = array(
	'readme.Version'                 => cfstream_meta_match( $readme, 'Version', '/^Version:\s*(.+)$/mi' ),
	'readme.Stable tag'              => cfstream_meta_match( $readme, 'Stable tag', '/^Stable tag:\s*(.+)$/mi' ),
	'readme.Requires PHP'            => cfstream_meta_match( $readme, 'Requires PHP', '/^Requires PHP:\s*(.+)$/mi' ),
	'readme.Requires at least'       => cfstream_meta_match( $readme, 'Requires at least', '/^Requires at least:\s*(.+)$/mi' ),
	'plugin.Version'                 => cfstream_meta_match( $plugin, 'Version', '/^\s*\*\s*Version:\s*(.+)$/mi' ),
	'plugin.Requires PHP'            => cfstream_meta_match( $plugin, 'Requires PHP', '/^\s*\*\s*Requires PHP:\s*(.+)$/mi' ),
	'plugin.Requires at least'       => cfstream_meta_match( $plugin, 'Requires at least', '/^\s*\*\s*Requires at least:\s*(.+)$/mi' ),
	'plugin.Update URI'              => cfstream_meta_match( $plugin, 'Update URI', '/^\s*\*\s*Update URI:\s*(.+)$/mi' ),
	'package.json.version'           => isset( $pkg['version'] ) ? (string) $pkg['version'] : null,
	'package-lock.json.version'      => $lock_root_version,
	'package-lock.json.packages[""]' => $lock_pkg_version,
	'updater.UPDATE_URI'              => $updater_update_uri,
);

$errors = array();

foreach ( $values as $key => $val ) {
	if ( null === $val || '' === $val ) {
		$errors[] = "Missing value: {$key}";
	}
}

// Version must match across readme, plugin header, package.json, and lockfile.
$version_keys = array(
	'readme.Version',
	'readme.Stable tag',
	'plugin.Version',
	'package.json.version',
	'package-lock.json.version',
	'package-lock.json.packages[""]',
);
$version_set = array();
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

// Update URI must be a github.com owner/repo URL and match the updater constant.
$update_uri = $values['plugin.Update URI'];
if ( null === $update_uri || '' === $update_uri ) {
	$errors[] = 'Missing value: plugin.Update URI';
} else {
	$normalised_header = rtrim( $update_uri, '/' );
	if ( ! preg_match( '#^https://github\.com/([^/]+)/([^/]+)$#', $normalised_header, $uri_parts ) ) {
		$errors[] = sprintf(
			'Update URI must be https://github.com/{owner}/{repo}: plugin=%s',
			$update_uri
		);
	}

	if ( null !== $values['updater.UPDATE_URI'] && '' !== $values['updater.UPDATE_URI'] ) {
		$normalised_const = rtrim( $values['updater.UPDATE_URI'], '/' );
		if ( $normalised_header !== $normalised_const ) {
			$errors[] = sprintf(
				'Update URI mismatch: plugin=%s updater.UPDATE_URI=%s',
				$update_uri,
				$values['updater.UPDATE_URI']
			);
		}
	}
}

// When a required version is supplied (release tags), every version field must match it.
if ( '' !== $required_version ) {
	foreach ( $version_keys as $key ) {
		if ( null === $values[ $key ] || '' === $values[ $key ] ) {
			continue;
		}
		$actual = cfstream_meta_normalise_version( $values[ $key ] );
		if ( $actual !== $required_version ) {
			$errors[] = sprintf(
				'Required version mismatch: %s=%s required=%s',
				$key,
				$values[ $key ],
				$required_version
			);
		}
	}
}

echo "Metadata check\n";
echo str_repeat( '-', 40 ) . "\n";
foreach ( $values as $key => $val ) {
	$display = null === $val ? '(missing)' : $val;
	echo sprintf( "%-32s %s\n", $key, $display );
}
if ( '' !== $required_version ) {
	echo sprintf( "%-32s %s\n", 'required.version', $required_version );
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
