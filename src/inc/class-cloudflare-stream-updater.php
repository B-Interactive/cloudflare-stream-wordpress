<?php
/**
 * Cloudflare Stream GitHub updater class
 *
 * Supplies in-dashboard plugin updates from public GitHub Releases.
 *
 * @package cloudflare-stream
 * @since   1.1.7
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare_Stream_Updater
 */
class Cloudflare_Stream_Updater {

	/**
	 * Singleton instance.
	 *
	 * @var self|false
	 */
	private static $instance = false;

	/**
	 * Canonical Update URI (single source for GitHub owner/repo).
	 *
	 * Must match the main plugin file Update URI header. Owner and repository
	 * path segments are derived from this URL for API calls.
	 *
	 * @var string
	 */
	const UPDATE_URI = 'https://github.com/B-Interactive/cloudflare-stream-wordpress';

	/**
	 * Release asset file name expected on each GitHub Release.
	 *
	 * @var string
	 */
	const RELEASE_ASSET = 'cloudflare-stream.zip';

	/**
	 * WordPress plugin slug (directory / text domain).
	 *
	 * @var string
	 */
	const PLUGIN_SLUG = 'cloudflare-stream';

	/**
	 * Site transient key for the latest release payload.
	 *
	 * @var string
	 */
	const TRANSIENT_RELEASE = 'cloudflare_stream_github_latest';

	/**
	 * How long a successful release lookup is cached.
	 *
	 * @var int
	 */
	const CACHE_TTL_SUCCESS = 43200;

	/**
	 * How long a failed release lookup is cached.
	 *
	 * @var int
	 */
	const CACHE_TTL_FAILURE = 900;

	/**
	 * HTTP timeout for the GitHub API request, in seconds.
	 *
	 * @var int
	 */
	const HTTP_TIMEOUT = 8;

	/**
	 * Return the singleton instance.
	 *
	 * @since 1.1.7
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}

	/**
	 * Prevent external construction.
	 *
	 * @since 1.1.7
	 */
	private function __construct() { }

	/**
	 * Prevent cloning.
	 *
	 * @since 1.1.7
	 */
	private function __clone() { }

	/**
	 * Register update hooks.
	 *
	 * @since 1.1.7
	 * @return void
	 */
	private function setup() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update_plugins' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'filter_plugins_api' ), 10, 3 );
	}

	/**
	 * Basename of this plugin's main file (for example cloudflare-stream/cloudflare-stream.php).
	 *
	 * @since 1.1.7
	 * @return string
	 */
	public function get_plugin_basename() {
		return plugin_basename( dirname( __DIR__, 2 ) . '/cloudflare-stream.php' );
	}

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @since 1.1.7
	 * @return string
	 */
	private function get_plugin_file_path() {
		return dirname( __DIR__, 2 ) . '/cloudflare-stream.php';
	}

	/**
	 * Parse owner and repository name from the canonical Update URI.
	 *
	 * @since 1.1.7
	 * @return array{owner:string,repo:string}|false
	 */
	public function get_github_repository() {
		$uri = untrailingslashit( self::UPDATE_URI );
		if ( ! preg_match( '#^https://github\.com/([^/]+)/([^/]+)$#', $uri, $matches ) ) {
			return false;
		}

		$owner = rawurldecode( $matches[1] );
		$repo  = rawurldecode( $matches[2] );
		if ( '' === $owner || '' === $repo ) {
			return false;
		}

		return array(
			'owner' => $owner,
			'repo'  => $repo,
		);
	}

	/**
	 * Provide update data for this plugin when WordPress checks github.com Update URIs.
	 *
	 * Always returns a payload when a usable release is known. Core places it in
	 * response or no_update after comparing versions.
	 *
	 * @since 1.1.7
	 *
	 * @param array|false $update      Existing update data, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @param string[]    $locales     Installed locales.
	 * @return array|false
	 */
	public function filter_update_plugins( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $locales );

		if ( $this->get_plugin_basename() !== $plugin_file ) {
			return $update;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return false;
		}

		$headers = is_array( $plugin_data ) ? $plugin_data : array();
		$payload = $this->build_update_payload( $release, $headers );

		return $payload ? $payload : false;
	}

	/**
	 * Supply plugin information for the WordPress plugin details modal.
	 *
	 * @since 1.1.7
	 *
	 * @param false|object|array $result Unused default result.
	 * @param string             $action API action name.
	 * @param object             $args   Request arguments.
	 * @return false|object
	 */
	public function filter_plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! is_object( $args ) || empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		// Only claim this slug when this file is the GitHub-distributed package.
		if ( ! $this->is_this_plugin_package() ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$headers = $this->get_installed_plugin_headers();
		$info    = $this->build_plugin_information( $release, $headers );

		return $info ? (object) $info : $result;
	}

	/**
	 * Whether this loaded package is the GitHub-distributed Cloudflare Stream plugin.
	 *
	 * Reads Update URI from this plugin's main file so the check works even when
	 * the code is loaded from outside wp-content/plugins (for example in tests).
	 *
	 * @since 1.1.7
	 * @return bool
	 */
	private function is_this_plugin_package() {
		$headers = $this->get_installed_plugin_headers();
		if ( empty( $headers ) ) {
			return false;
		}

		$update_uri = $this->header_value( $headers, array( 'UpdateURI', 'Update URI' ) );
		if ( '' === $update_uri ) {
			return false;
		}

		return ( untrailingslashit( self::UPDATE_URI ) === untrailingslashit( $update_uri ) );
	}

	/**
	 * Read headers from the installed main plugin file.
	 *
	 * @since 1.1.7
	 * @return array
	 */
	private function get_installed_plugin_headers() {
		$path = $this->get_plugin_file_path();
		if ( ! is_readable( $path ) ) {
			return array();
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $path, false, false );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build the update_plugins_* payload from a release and plugin headers.
	 *
	 * @since 1.1.7
	 *
	 * @param array $release Parsed release data.
	 * @param array $headers Plugin headers.
	 * @return array|false
	 */
	private function build_update_payload( $release, $headers ) {
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return false;
		}

		$version = (string) $release['version'];

		$payload = array(
			'id'           => self::UPDATE_URI,
			'slug'         => self::PLUGIN_SLUG,
			'plugin'       => $this->get_plugin_basename(),
			'version'      => $version,
			'new_version'  => $version,
			'url'          => ! empty( $release['html_url'] ) ? (string) $release['html_url'] : self::UPDATE_URI,
			'package'      => (string) $release['package'],
			'icons'        => array(),
			'banners'      => array(),
			'banners_rtl'  => array(),
			'translations' => array(),
		);

		$requires = $this->header_value( $headers, array( 'RequiresWP', 'Requires WP' ) );
		if ( '' === $requires ) {
			$requires = $this->header_value( $headers, array( 'Requires at least' ) );
		}
		if ( '' !== $requires ) {
			$payload['requires'] = $requires;
		}

		$requires_php = $this->header_value( $headers, array( 'RequiresPHP', 'Requires PHP' ) );
		if ( '' !== $requires_php ) {
			$payload['requires_php'] = $requires_php;
		}

		$tested = $this->get_tested_up_to();
		if ( '' !== $tested ) {
			$payload['tested'] = $tested;
		}

		return $payload;
	}

	/**
	 * Build a plugins_api plugin_information response.
	 *
	 * @since 1.1.7
	 *
	 * @param array $release Parsed release data.
	 * @param array $headers Plugin headers.
	 * @return array|false
	 */
	private function build_plugin_information( $release, $headers ) {
		if ( empty( $release['version'] ) || empty( $release['package'] ) ) {
			return false;
		}

		$name = $this->header_value( $headers, array( 'Name' ) );
		if ( '' === $name ) {
			$name = 'Cloudflare Stream';
		}

		$author = $this->header_value( $headers, array( 'Author' ) );
		if ( '' === $author ) {
			$author = 'B-Interactive';
		}

		$homepage = $this->header_value( $headers, array( 'PluginURI', 'Plugin URI' ) );
		if ( '' === $homepage ) {
			$homepage = self::UPDATE_URI;
		}

		$description = $this->header_value( $headers, array( 'Description' ) );
		$changelog   = ! empty( $release['body'] ) ? (string) $release['body'] : '';

		$info = array(
			'name'           => $name,
			'slug'           => self::PLUGIN_SLUG,
			'version'        => (string) $release['version'],
			'author'         => $author,
			'author_profile' => $homepage,
			'homepage'       => $homepage,
			'download_link'  => (string) $release['package'],
			'trunk'          => (string) $release['package'],
			'last_updated'   => ! empty( $release['published_at'] ) ? (string) $release['published_at'] : '',
			'external'       => true,
			'sections'       => array(
				'description' => $description ? $description : $name,
				'changelog'   => $changelog ? $this->format_changelog( $changelog ) : '',
			),
		);

		$requires = $this->header_value( $headers, array( 'RequiresWP', 'Requires WP' ) );
		if ( '' === $requires ) {
			$requires = $this->header_value( $headers, array( 'Requires at least' ) );
		}
		if ( '' !== $requires ) {
			$info['requires'] = $requires;
		}

		$requires_php = $this->header_value( $headers, array( 'RequiresPHP', 'Requires PHP' ) );
		if ( '' !== $requires_php ) {
			$info['requires_php'] = $requires_php;
		}

		$tested = $this->get_tested_up_to();
		if ( '' !== $tested ) {
			$info['tested'] = $tested;
		}

		return $info;
	}

	/**
	 * Prefer plain text changelog content safe for the details modal.
	 *
	 * @since 1.1.7
	 *
	 * @param string $body Release body markdown or text.
	 * @return string
	 */
	private function format_changelog( $body ) {
		$body = trim( wp_strip_all_tags( $body ) );
		if ( '' === $body ) {
			return '';
		}

		return nl2br( esc_html( $body ), false );
	}

	/**
	 * Read Tested up to from readme.txt when present.
	 *
	 * @since 1.1.7
	 * @return string
	 */
	private function get_tested_up_to() {
		$readme = dirname( __DIR__, 2 ) . '/readme.txt';
		if ( ! is_readable( $readme ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$contents = file_get_contents( $readme );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return '';
		}

		if ( preg_match( '/^Tested up to:\s*(.+)$/mi', $contents, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Return the first non-empty header value for the given keys.
	 *
	 * @since 1.1.7
	 *
	 * @param array    $headers Plugin headers.
	 * @param string[] $keys    Candidate header keys.
	 * @return string
	 */
	private function header_value( $headers, $keys ) {
		if ( ! is_array( $headers ) ) {
			return '';
		}

		foreach ( $keys as $key ) {
			if ( ! empty( $headers[ $key ] ) && is_scalar( $headers[ $key ] ) ) {
				return trim( (string) $headers[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Fetch and cache the latest usable GitHub release for this plugin.
	 *
	 * @since 1.1.7
	 * @return array|false {
	 *     @type string $version      Normalised version without a leading v.
	 *     @type string $package      browser_download_url for the zip asset.
	 *     @type string $html_url     Release page URL.
	 *     @type string $body         Release notes body.
	 *     @type string $published_at Publish timestamp when present.
	 * }
	 */
	public function get_latest_release() {
		$cached = get_site_transient( self::TRANSIENT_RELEASE );
		if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
			if ( empty( $cached['ok'] ) ) {
				return false;
			}
			if ( ! empty( $cached['release'] ) && is_array( $cached['release'] ) ) {
				return $cached['release'];
			}
			return false;
		}

		$release = $this->fetch_latest_release_from_github();
		if ( ! $release ) {
			set_site_transient(
				self::TRANSIENT_RELEASE,
				array(
					'ok'      => 0,
					'release' => null,
				),
				self::CACHE_TTL_FAILURE
			);
			return false;
		}

		set_site_transient(
			self::TRANSIENT_RELEASE,
			array(
				'ok'      => 1,
				'release' => $release,
			),
			self::CACHE_TTL_SUCCESS
		);

		return $release;
	}

	/**
	 * Call the GitHub Releases API and normalise the latest stable asset.
	 *
	 * @since 1.1.7
	 * @return array|false
	 */
	private function fetch_latest_release_from_github() {
		$repository = $this->get_github_repository();
		if ( ! $repository ) {
			return false;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $repository['owner'] ),
			rawurlencode( $repository['repo'] )
		);

		$headers = $this->get_installed_plugin_headers();
		$version = $this->header_value( $headers, array( 'Version' ) );
		if ( '' === $version ) {
			$version = '0.0.0';
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => self::HTTP_TIMEOUT,
				'user-agent' => 'Cloudflare-Stream-WordPress/' . $version . '; ' . self::UPDATE_URI,
				'headers'    => array(
					'Accept' => 'application/vnd.github+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return false;
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return false;
		}

		return $this->parse_release_response( $data );
	}

	/**
	 * Validate a GitHub release JSON object and extract update fields.
	 *
	 * @since 1.1.7
	 *
	 * @param array $data Decoded release object.
	 * @return array|false
	 */
	public function parse_release_response( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return false;
		}

		$tag = isset( $data['tag_name'] ) ? trim( (string) $data['tag_name'] ) : '';
		if ( '' === $tag ) {
			return false;
		}

		$version = $this->normalise_version( $tag );
		if ( '' === $version ) {
			return false;
		}

		$package = $this->find_release_asset_url( $data );
		if ( '' === $package ) {
			return false;
		}

		$html_url = isset( $data['html_url'] ) ? esc_url_raw( (string) $data['html_url'] ) : self::UPDATE_URI;
		if ( '' === $html_url ) {
			$html_url = self::UPDATE_URI;
		}

		$body = isset( $data['body'] ) ? (string) $data['body'] : '';

		$published = '';
		if ( ! empty( $data['published_at'] ) && is_string( $data['published_at'] ) ) {
			$published = $data['published_at'];
		}

		return array(
			'version'      => $version,
			'package'      => $package,
			'html_url'     => $html_url,
			'body'         => $body,
			'published_at' => $published,
		);
	}

	/**
	 * Strip a leading v from a git tag for version_compare.
	 *
	 * @since 1.1.7
	 *
	 * @param string $tag Release tag name.
	 * @return string
	 */
	public function normalise_version( $tag ) {
		$tag = trim( (string) $tag );
		if ( '' === $tag ) {
			return '';
		}

		if ( preg_match( '/^v(.+)$/i', $tag, $matches ) ) {
			return trim( $matches[1] );
		}

		return $tag;
	}

	/**
	 * Locate the browser_download_url for the expected zip asset.
	 *
	 * @since 1.1.7
	 *
	 * @param array $data Decoded release object.
	 * @return string
	 */
	private function find_release_asset_url( $data ) {
		if ( empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return '';
		}

		foreach ( $data['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( self::RELEASE_ASSET !== $name ) {
				continue;
			}
			$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			$url = esc_url_raw( $url );
			if ( $url ) {
				return $url;
			}
		}

		return '';
	}
}

Cloudflare_Stream_Updater::instance();
