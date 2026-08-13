<?php
/**
 * Static checks for block editor source contracts.
 *
 * @package cloudflare-stream
 */

/**
 * @group registration
 */
class Test_CFStream_Block_Editor_Source extends WP_UnitTestCase {

	/**
	 * Absolute path to the plugin root.
	 *
	 * @var string
	 */
	private $plugin_root;

	/**
	 * Locate the plugin root once per test.
	 */
	public function set_up() {
		parent::set_up();
		$this->plugin_root = dirname( __DIR__, 2 );
	}

	/**
	 * Read a source file relative to the plugin root.
	 *
	 * @param string $relative Relative path under the plugin root.
	 * @return string
	 */
	private function read_src( $relative ) {
		$path = $this->plugin_root . '/' . ltrim( $relative, '/' );
		$this->assertFileExists( $path, $relative . ' must exist' );
		$contents = file_get_contents( $path );
		$this->assertNotFalse( $contents, $relative . ' must be readable' );
		return $contents;
	}

	/**
	 * Current block registration uses apiVersion 3 and a null save.
	 */
	public function test_current_block_api_version_and_save() {
		$block = $this->read_src( 'src/block/block.js' );

		$this->assertMatchesRegularExpression(
			'/\bapiVersion\s*:\s*3\b/',
			$block,
			'current block must declare apiVersion 3'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\bapiVersion\s*:\s*[12]\b/',
			$block,
			'current block must not declare apiVersion 1 or 2'
		);
		$this->assertStringContainsString(
			'deprecated: [ deprecated_iframe, deprecated_108 ]',
			$block,
			'deprecation order must keep iframe posts ahead of stream-tag posts'
		);
		$this->assertMatchesRegularExpression(
			'/save\s*\(\s*\)\s*\{\s*return null\s*;\s*\}/s',
			$block,
			'current block save must remain null for dynamic rendering'
		);
	}

	/**
	 * Upload selection accepts browser files without a parent-window File constructor check.
	 */
	public function test_on_select_media_uses_cross_realm_file_shape() {
		$edit = $this->read_src( 'src/block/edit.js' );

		$this->assertStringNotContainsString(
			'instanceof window.File',
			$edit,
			'edit.js must not use instanceof window.File'
		);
		$this->assertStringContainsString(
			'typeof candidate.name === \'string\'',
			$edit,
			'edit.js must duck-type file name'
		);
		$this->assertStringContainsString(
			'typeof candidate.size === \'number\'',
			$edit,
			'edit.js must duck-type file size'
		);
		$this->assertStringContainsString(
			'typeof candidate.type === \'string\'',
			$edit,
			'edit.js must duck-type file type'
		);
	}

	/**
	 * Block canvas sources avoid bare document access; media frame stays on Backbone el.
	 */
	public function test_no_bare_document_access_under_src() {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->plugin_root . '/src', FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() || 'js' !== $file->getExtension() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $this->plugin_root ) + 1 );
			$contents = file_get_contents( $file->getPathname() );
			$this->assertNotFalse( $contents, $relative . ' must be readable' );

			$this->assertDoesNotMatchRegularExpression(
				'/(?<![\w.])document\./',
				$contents,
				$relative . ' must not use bare document.'
			);
			$this->assertStringNotContainsString(
				'window.document',
				$contents,
				$relative . ' must not use window.document'
			);
		}
	}

	/**
	 * Deprecated iframe save shape stays stable for migration matching.
	 */
	public function test_deprecated_iframe_save_shape() {
		$source = $this->read_src( 'src/block/deprecated_iframe.js' );

		$this->assertStringContainsString( "className: 'player-wrapper'", $source );
		$this->assertStringContainsString( "className: 'player-frame'", $source );
		$this->assertStringContainsString( 'streamIframeSource( props.attributes )', $source );
		$this->assertStringContainsString(
			"allow: 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;'",
			$source
		);
		$this->assertStringContainsString( "allowfullscreen: 'true'", $source );
		$this->assertStringContainsString( 'export const deprecated_iframe', $source );
		$this->assertStringNotContainsString( 'apiVersion', $source );
	}

	/**
	 * Deprecated stream-tag save shape stays stable for migration matching.
	 */
	public function test_deprecated_108_save_shape() {
		$source = $this->read_src( 'src/block/deprecated_108.js' );

		$this->assertStringContainsString( "'stream'", $source );
		$this->assertStringContainsString( "className: 'target'", $source );
		$this->assertStringContainsString(
			'https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=',
			$source
		);
		$this->assertStringContainsString( 'export const deprecated_108', $source );
		$this->assertStringContainsString( "selector: 'stream'", $source );
		$this->assertStringNotContainsString( 'apiVersion', $source );
	}
}
