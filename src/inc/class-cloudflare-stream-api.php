<?php
/**
 * Cloudflare Stream API class
 *
 * Methods for interacting with the Cloudflare Stream API.
 *
 * @package cloudflare-stream
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare_Stream_API
 */
class Cloudflare_Stream_API {

	/**
	 * API Token
	 *
	 * @var string $api_token Cloudflare API token.
	 */
	private $api_token = '';

	/**
	 * API ID
	 *
	 * @var string $api_id Cloudflare API ID.
	 */
	private $api_id = '';

	/**
	 * Use signed URLs
	 *
	 * @var bool $signed_urls Use signed URLs.
	 */
	private $signed_urls = '';

	/**
	 * Duration of signed URLs
	 *
	 * @var int $signed_urls_duration Duration of signed URLs.
	 */
	private $signed_urls_duration = '';

	/**
	 * Last video seen when retrieving paginated results.
	 *
	 * @var string $last_seen Timestamp of the last returned result.
	 */
	public $last_seen = false;

	/**
	 * REST API limit
	 *
	 * @var string $api_limit Number of results to return from the API by default.
	 */
	public $api_limit = 40;

	/**
	 * Maximum uncached API token mints allowed in a single admin request.
	 *
	 * Only applies when no local signing key is configured, where each mint is a
	 * sequential HTTP round trip. Local RS256 signing is cheap and unbudgeted.
	 *
	 * @var int
	 */
	const ADMIN_MINT_BUDGET = 12;

	/**
	 * Uncached API token mints used by the current admin request.
	 *
	 * @var int
	 */
	private $admin_mints_used = 0;

	/**
	 * Whether this request already marked the response uncacheable for signed embeds.
	 *
	 * @var bool
	 */
	private static $signed_embed_nocache = false;

	/**
	 * Last local signing failure reason code for the current request.
	 *
	 * @var string
	 */
	private $last_local_reason = '';

	/**
	 * Last API token mint failure reason code for the current request.
	 *
	 * @var string
	 */
	private $last_api_reason = '';

	/**
	 * The accounts API.
	 */
	const ACCOUNTS_API = 'accounts';

	/**
	 * The zones API.
	 *
	 * @deprecated
	 */
	const ZONES_API = 'zones';

	/**
	 * Define and register singleton
	 *
	 * @var $instance The singleton instance of the class.
	 */
	private static $instance = false;

	/**
	 * Singleton
	 *
	 * @since 1.0.0
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}
	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() { }

	/**
	 * Clone
	 *
	 * @since 1.0.0
	 */
	private function __clone() { }

	/**
	 * Add actions and filters
	 *
	 * @uses add_action, add_filter
	 * @since 1.0.0
	 */
	private function setup() {
		return false;
	}

	/**
	 * Make the request to the API
	 *
	 * @param string $endpoint API Endpoint.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @param string $api_type Which API to make the request to. Defaults to 'accounts'.
	 * @since 1.0.0
	 */
	public function request( $endpoint, $args = array(), $return_headers = false, $api_type = 'accounts' ) {
		$this->api_token = Cloudflare_Stream_Settings::get_api_token();
		$this->api_id    = $this->get_api_id( $api_type );

		$base_url = 'https://api.cloudflare.com/client/v4/' . $api_type . '/' . $this->api_id . '/';

		$custom_headers = array();
		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$custom_headers = $args['headers'];
		}

		$headers = array_merge(
			array(
				'Authorization' => 'Bearer ' . $this->api_token,
			),
			$custom_headers
		);

		// JSON is the default for non-empty bodies; TUS create sends headers only.
		if ( ! isset( $headers['Content-Type'] ) && ! empty( $args['body'] ) ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args['headers'] = $headers;

		// Keep outbound API calls from hanging the page render.
		if ( empty( $args['timeout'] ) ) {
			$args['timeout'] = 15;
		}

		$query_string = isset( $args['query'] ) ? '?' . $args['query'] : '';
		$endpoint    .= $query_string;
		$route        = $base_url . $endpoint;

		$response = wp_remote_request( $route, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 'response' === $return_headers ) {
			return array(
				'code'    => wp_remote_retrieve_response_code( $response ),
				'headers' => wp_remote_retrieve_headers( $response ),
				'body'    => wp_remote_retrieve_body( $response ),
			);
		}

		if ( true === $return_headers || 'headers' === $return_headers ) {
			return wp_remote_retrieve_headers( $response );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Get API ID based on API type.
	 *
	 * @deprecated The zones API is no longer used by the plugin.
	 * @param string $api_type The API type, defaulting to 'accounts'.
	 * @return string API ID.
	 * @since 1.0.9
	 */
	public function get_api_id( $api_type = null ) {
		$api_id = '';
		if ( self::ZONES_API === $api_type ) {
			$api_id = get_option( Cloudflare_Stream_Settings::OPTION_API_ZONE_ID );
		} else {
			$api_id = Cloudflare_Stream_Settings::get_api_account();

			// If Account ID missing, try to use Zone ID to fetch it.
			if ( empty( $api_id ) ) {
				$api_id = $this->get_account_id( true );
			}
		}
		return $api_id;
	}

	/**
	 * Make a POST request
	 *
	 * @param string $endpoint API Endpoint.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function post( $endpoint, $args = array(), $return_headers = false ) {
		$args['method'] = 'POST';
		$response_text  = $this->request( $endpoint, $args, $return_headers );
		return ( $response_text );
	}

	/**
	 * Make a DELETE request
	 *
	 * @param string $endpoint API Endpoint.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 *
	 * @returns object $response HTTP response object.
	 */
	public function delete( $endpoint, $args = array(), $return_headers = false ) {
		$args['method'] = 'DELETE';
		$response_text  = $this->request( $endpoint, $args, $return_headers );
		return ( $response_text );
	}

	/**
	 * Wrapper for listing videos
	 *
	 * @param array $args Additional API arguments.
	 * @param bool  $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function get_videos( $args = array(), $return_headers = 'false' ) {
		$response_text = $this->request( 'stream', $args, $return_headers );
		return $this->decode_api_response( $response_text );
	}

	/**
	 * API wrapper for requesting a specific video's details
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function get_video_details( $uid, $args = array(), $return_headers = false ) {
		if ( ! $this->is_valid_video_uid( $uid ) ) {
			return null;
		}

		$response_text = $this->request( 'stream/' . rawurlencode( $uid ), $args, $return_headers );
		return $this->decode_api_response( $response_text );
	}

	/**
	 * API wrapper for updating a specific video's details
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function update_video_details( $uid, $args = array(), $return_headers = false ) {
		if ( ! $this->is_valid_video_uid( $uid ) ) {
			return null;
		}

		$response_text = $this->post( 'stream/' . rawurlencode( $uid ), $args, $return_headers );
		return $this->decode_api_response( $response_text );
	}

	/**
	 * Whether a value looks like a Cloudflare Stream video UID.
	 *
	 * @param string $uid Candidate video id.
	 * @return bool
	 */
	private function is_valid_video_uid( $uid ) {
		return is_string( $uid ) && (bool) preg_match( '/^[a-f0-9]{32}$/i', $uid );
	}

	/**
	 * Configured playback / media domain from settings.
	 *
	 * @return string
	 */
	public function get_media_domain() {
		$domain = get_option(
			Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN,
			Cloudflare_Stream_Settings::STANDARD_MEDIA_DOMAINS[0]
		);

		if ( ! is_string( $domain ) || '' === $domain ) {
			return Cloudflare_Stream_Settings::STANDARD_MEDIA_DOMAINS[0];
		}

		return $domain;
	}

	/**
	 * Whether the domain is a Cloudflare standard host (not a customer subdomain).
	 *
	 * @param string|null $domain Domain to check; defaults to the configured option.
	 * @return bool
	 */
	public function is_standard_media_domain( $domain = null ) {
		if ( null === $domain ) {
			$domain = $this->get_media_domain();
		}

		return in_array( $domain, Cloudflare_Stream_Settings::STANDARD_MEDIA_DOMAINS, true );
	}

	/**
	 * Host used for media assets (thumbnails, manifests), not the iframe player.
	 *
	 * Standard iframe hosts are iframe.cloudflarestream.com / iframe.videodelivery.net.
	 * Asset URLs use videodelivery.net, or the customer-*.cloudflarestream.com host.
	 *
	 * @param string|null $domain Configured media domain; defaults to the option.
	 * @return string
	 */
	public function get_media_asset_host( $domain = null ) {
		if ( null === $domain ) {
			$domain = $this->get_media_domain();
		}

		if ( $this->is_standard_media_domain( $domain ) ) {
			// cloudflarestream.com is valid for iframes only; assets live on videodelivery.net.
			return 'videodelivery.net';
		}

		return $domain;
	}

	/**
	 * Iframe player URL for a video UID or signed token (no query string).
	 *
	 * @param string $id Video UID or signed playback token.
	 * @return string
	 */
	public function get_iframe_url( $id ) {
		$domain = $this->get_media_domain();
		$id     = ltrim( (string) $id, '/' );

		if ( $this->is_standard_media_domain( $domain ) ) {
			return 'https://iframe.' . $domain . '/' . $id;
		}

		return 'https://' . $domain . '/' . $id . '/iframe';
	}

	/**
	 * Thumbnail / poster URL for a video UID or signed token.
	 *
	 * @param string $id   Video UID or signed playback token.
	 * @param string $time Optional poster time with unit, e.g. "0s" or "10s".
	 * @return string
	 */
	public function get_poster_url( $id, $time = '' ) {
		$host = $this->get_media_asset_host();
		$id   = ltrim( (string) $id, '/' );
		$url  = 'https://' . $host . '/' . $id . '/thumbnails/thumbnail.jpg';

		if ( is_string( $time ) && '' !== $time ) {
			$url = add_query_arg( 'time', $time, $url );
		}

		return $url;
	}

	/**
	 * Whether signed playback is enabled for this site.
	 *
	 * Mirrors the front-end rule in get_video_embed(): the site option alone
	 * decides. Signing a video that does not require it is harmless, and keeping
	 * one rule stops the admin and the front end drifting apart.
	 *
	 * @since 1.1.7
	 * @return bool
	 */
	public function is_signed_playback_enabled() {
		return (bool) get_option(
			Cloudflare_Stream_Settings::OPTION_SIGNED_URLS,
			Cloudflare_Stream_Settings::DEFAULT_SIGNED_URLS
		);
	}

	/**
	 * Transient key holding a minted API token for a uid at the current duration.
	 *
	 * Includes a signing-mode discriminator so API-minted tokens are not reused
	 * after a local key is added or the key id changes.
	 *
	 * @param string $uid Video UID.
	 * @return string
	 */
	private function get_signed_token_cache_key( $uid ) {
		$key_id = $this->get_signing_key_id();
		$mode   = '' !== $key_id ? $key_id : 'api';

		return 'cfstream_signed_token_' . md5(
			strtolower( (string) $uid ) . '|' . $this->get_signed_url_duration_minutes() . '|' . $mode
		);
	}

	/**
	 * Whether a usable minted token is already cached for this uid.
	 *
	 * @param string $uid Video UID.
	 * @return bool
	 */
	private function has_cached_signed_token( $uid ) {
		$cached = get_transient( $this->get_signed_token_cache_key( $uid ) );

		return is_string( $cached ) && '' !== $cached;
	}

	/**
	 * Path segment to use when building playback URLs for a video.
	 *
	 * Returns a signed token when signed playback is on, otherwise the bare uid.
	 * This is the single place admin surfaces should ask, so library thumbnails
	 * and editor previews match what the front end renders.
	 *
	 * @since 1.1.7
	 * @param string $uid      Video UID.
	 * @param bool   $budgeted Apply the per-request mint budget (listing screens).
	 * @return string|false Playback id, or false when signed playback is required
	 *                      but no token could be minted.
	 */
	public function get_playback_id( $uid, $budgeted = false ) {
		if ( ! $this->is_valid_video_uid( $uid ) ) {
			return false;
		}

		if ( ! $this->is_signed_playback_enabled() ) {
			return $uid;
		}

		// Without a local key each miss is an HTTP round trip; cap them per request.
		if ( $budgeted && ! $this->has_signing_key() && ! $this->has_cached_signed_token( $uid ) ) {
			if ( $this->admin_mints_used >= self::ADMIN_MINT_BUDGET ) {
				return false;
			}
			++$this->admin_mints_used;
		}

		$token = $this->get_signed_video_token( $uid );

		return ( is_string( $token ) && '' !== $token ) ? $token : false;
	}

	/**
	 * Decode a JSON API body into an object, or null on failure.
	 *
	 * @param mixed $response_text Body from request(), WP_Error, or other failure value.
	 * @return object|null
	 */
	private function decode_api_response( $response_text ) {
		if ( is_wp_error( $response_text ) ) {
			return null;
		}

		if ( ! is_string( $response_text ) || '' === $response_text ) {
			return null;
		}

		$data = json_decode( $response_text );

		return is_object( $data ) ? $data : null;
	}

	/**
	 * Signed URL lifetime in minutes, clamped to Cloudflare's practical bounds.
	 *
	 * @return int Minutes between 1 and 1440.
	 */
	private function get_signed_url_duration_minutes() {
		$minutes = intval(
			get_option(
				Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION,
				Cloudflare_Stream_Settings::DEFAULT_SIGNED_URLS_DURATION
			)
		);

		if ( $minutes < 1 ) {
			return 1;
		}

		if ( $minutes > 1440 ) {
			return 1440;
		}

		return $minutes;
	}

	/**
	 * Whether the current request is a front-end HTML page view.
	 *
	 * @return bool
	 */
	private function is_frontend_page_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		return true;
	}

	/**
	 * Send no-cache HTTP headers for a response that includes signed embeds.
	 *
	 * @return void
	 */
	public function send_signed_embed_nocache_headers() {
		if ( headers_sent() ) {
			return;
		}

		nocache_headers();
	}

	/**
	 * Mark the front-end response so full-page caches skip signed embed HTML.
	 *
	 * Signed tokens are written into the HTML at render time. A long-lived page
	 * cache would reuse one token for every visitor and may keep serving it after
	 * the token has expired.
	 *
	 * @return void
	 */
	public function mark_signed_embed_uncacheable() {
		if ( self::$signed_embed_nocache || ! $this->is_frontend_page_request() ) {
			return;
		}

		self::$signed_embed_nocache = true;

		// Common markers checked by WP Super Cache, LiteSpeed, and similar plugins.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party cache plugin constant (WP Super Cache et al).
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'LSCACHE_NO_CACHE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Third-party LiteSpeed Cache constant.
			define( 'LSCACHE_NO_CACHE', true );
		}

		// LiteSpeed Cache listens for this when deciding whether to store the HTML.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache hook.
		do_action( 'litespeed_control_set_nocache', 'cloudflare stream signed embed' );

		// Send headers once even when the page has several Stream embeds.
		if ( did_action( 'send_headers' ) ) {
			$this->send_signed_embed_nocache_headers();
		} else {
			add_action( 'send_headers', array( $this, 'send_signed_embed_nocache_headers' ), 0 );
		}
	}

	/**
	 * Get the embed code
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @since 1.0.0
	 * @return string Embed HTML, or empty string when signed playback cannot be minted.
	 */
	public function get_video_embed( $uid, $args = array() ) {
		$signed_urls = get_option(
			Cloudflare_Stream_Settings::OPTION_SIGNED_URLS,
			Cloudflare_Stream_Settings::DEFAULT_SIGNED_URLS
		);

		if ( $signed_urls ) {
			$token = $this->get_signed_video_token( $uid );
			if ( ! is_string( $token ) || '' === $token ) {
				// Fail closed: do not fall back to a public UID embed.
				return '';
			}
			$uid = $token;
			$this->mark_signed_embed_uncacheable();
		} elseif ( ! $this->is_valid_video_uid( $uid ) ) {
			return '';
		}

		return $this->get_video_embed_template( $uid, $args );
	}

	/**
	 * Get the video embed with placeholder UID
	 *
	 * @param string $uid  Unique Video ID or signed token.
	 * @param array  $args Additional API arguments.
	 *
	 * @since 1.0.9.4
	 */
	public function get_video_embed_template( $uid, $args = array() ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$src_uri = $this->get_iframe_url( $uid ) . '?';

		$poster_time = empty( $args['postertime'] )
			? get_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, Cloudflare_Stream_Settings::DEFAULT_POSTER_TIME )
			: $args['postertime'];
		$poster_time = $poster_time . 's';
		$poster_url  = empty( $args['posterurl'] )
			? $this->get_poster_url( $uid, $poster_time )
			: $this->sanitize_poster_url( $args['posterurl'], $uid, $poster_time );
		// Escape the poster URL, then encode it as a query value (same idea as encodeURIComponent in JS).
		$poster_url = esc_url( $poster_url );

		$video_embed = '<div class="cloudflare-stream" style="position: relative; padding-top: 56.25%"><iframe'
			. ' src="' . esc_url( $src_uri )
			. ( filter_var( $args['muted'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 'muted=true&' : '' )
			. ( filter_var( $args['loop'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 'loop=true&' : '' )
			. ( filter_var( $args['autoplay'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 'autoplay=true&' : '' )
			. ( filter_var( $args['preload'] ?? false, FILTER_VALIDATE_BOOLEAN ) ? 'preload=auto&' : '' )
			. ( filter_var( $args['controls'] ?? true, FILTER_VALIDATE_BOOLEAN ) || ! isset( $args['controls'] ) || strlen( trim( (string) $args['controls'] ) ) === 0 ? '' : 'controls=false&' )
			. 'poster=' . rawurlencode( $poster_url ) . '"'
			. ' style="border: none; position: absolute; top: 0; height: 100%; width: 100%" '
			. 'allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" '
			. 'allowfullscreen="true" '
			. 'id="stream-player"'
			. '></iframe></div>';

		return $video_embed;
	}

	/**
	 * Base64url encode without padding (JWT style).
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT-style base64url encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Decode PEM/JWK material that Cloudflare returns base64-encoded.
	 *
	 * Accepts already-decoded PEM text as well.
	 *
	 * @param string $value Base64 blob or PEM text.
	 * @return string
	 */
	private function decode_signing_key_material( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, 'BEGIN' ) ) {
			return $value;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Cloudflare returns PEM signing material base64-encoded.
		$decoded = base64_decode( $value, true );

		if ( false !== $decoded && '' !== $decoded && false !== strpos( $decoded, 'BEGIN' ) ) {
			return $decoded;
		}

		return $value;
	}

	/**
	 * Whether a string looks like a usable RSA private key PEM.
	 *
	 * @param string $pem Candidate PEM.
	 * @return bool
	 */
	private function is_valid_signing_key_pem( $pem ) {
		if ( ! is_string( $pem ) || '' === $pem ) {
			return false;
		}

		if ( false === strpos( $pem, 'PRIVATE KEY' ) ) {
			return false;
		}

		$key = openssl_pkey_get_private( $pem );

		return false !== $key;
	}

	/**
	 * Trimmed string value of a PHP constant, or empty string when unusable.
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	private function constant_string( $name ) {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Whether either signing key constant is set, so wp-config.php is the source.
	 *
	 * A partly set or unreadable pair still hides any key stored in options.
	 *
	 * @return bool
	 */
	public function signing_key_constants_present() {
		return '' !== $this->constant_string( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' )
			|| '' !== $this->constant_string( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' );
	}

	/**
	 * Signing key id from constant, then stored option.
	 *
	 * Constants and options are never mixed, so a half-set pair cannot sign with the wrong id.
	 * The database id is stored in plaintext so it remains usable for revoke when the PEM cannot be read.
	 *
	 * @return string
	 */
	public function get_signing_key_id() {
		if ( $this->signing_key_constants_present() ) {
			return sanitize_text_field( $this->constant_string( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) );
		}

		$id = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, '' );

		return is_string( $id ) ? sanitize_text_field( $id ) : '';
	}

	/**
	 * Signing key PEM from constant, then stored option (decrypted when needed).
	 *
	 * Constants may hold decoded PEM text or the base64 form Cloudflare returns once.
	 *
	 * @return string Decoded PEM text, or empty string.
	 */
	public function get_signing_key_pem() {
		if ( $this->signing_key_constants_present() ) {
			$raw = $this->constant_string( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' );
		} else {
			$raw = Cloudflare_Stream_Secret_Store::get_secret( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );
		}

		if ( '' === $raw ) {
			return '';
		}

		$pem = $this->decode_signing_key_material( $raw );

		return $this->is_valid_signing_key_pem( $pem ) ? $pem : '';
	}

	/**
	 * Whether the database holds a signing PEM envelope that cannot be decrypted.
	 *
	 * Ignores constants: only the options-backed PEM is considered.
	 *
	 * @return bool
	 */
	public function db_signing_key_pem_unreadable() {
		if ( $this->signing_key_constants_present() ) {
			return false;
		}

		if ( ! Cloudflare_Stream_Secret_Store::has_stored_value( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM ) ) {
			return false;
		}

		$status = Cloudflare_Stream_Secret_Store::probe( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM );

		return in_array( $status, array( 'unreadable', 'unavailable' ), true );
	}

	/**
	 * Whether the database holds an API token envelope that cannot be decrypted.
	 *
	 * Ignores the API token constant.
	 *
	 * @return bool
	 */
	public function db_api_token_unreadable() {
		if ( Cloudflare_Stream_Settings::api_token_from_constant() ) {
			return false;
		}

		if ( ! Cloudflare_Stream_Secret_Store::has_stored_value( Cloudflare_Stream_Settings::OPTION_API_TOKEN ) ) {
			return false;
		}

		$status = Cloudflare_Stream_Secret_Store::probe( Cloudflare_Stream_Settings::OPTION_API_TOKEN );

		return in_array( $status, array( 'unreadable', 'unavailable' ), true );
	}

	/**
	 * Whether a local signing key is available (constants or options).
	 *
	 * @return bool
	 */
	public function has_signing_key() {
		return '' !== $this->get_signing_key_id() && '' !== $this->get_signing_key_pem();
	}

	/**
	 * Whether candidate id + PEM material can be used for local signing.
	 *
	 * Accepts decoded PEM text or the base64 form Cloudflare returns once.
	 *
	 * @param string $key_id Signing key id.
	 * @param string $pem    PEM text or base64-encoded PEM.
	 * @return bool
	 */
	public function signing_key_material_is_usable( $key_id, $pem ) {
		$key_id = sanitize_text_field( (string) $key_id );
		if ( '' === $key_id ) {
			return false;
		}

		$decoded = $this->decode_signing_key_material( is_string( $pem ) ? $pem : '' );

		return $this->is_valid_signing_key_pem( $decoded );
	}

	/**
	 * Whether PHP constants alone provide a usable signing key (ignores options).
	 *
	 * @return bool
	 */
	public function has_signing_key_from_constants() {
		$id  = $this->constant_string( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' );
		$raw = $this->constant_string( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' );

		if ( '' === $id || '' === $raw ) {
			return false;
		}

		return $this->is_valid_signing_key_pem( $this->decode_signing_key_material( $raw ) );
	}

	/**
	 * Create a Stream signing key via the Cloudflare API.
	 *
	 * Response includes id and pem (base64) once; caller decides storage.
	 *
	 * @return object|false Decoded API result object on success, false on failure.
	 */
	public function create_signing_key() {
		$data = $this->decode_api_response( $this->post( 'stream/keys', array() ) );

		if (
			null === $data
			|| empty( $data->success )
			|| ! isset( $data->result )
			|| ! is_object( $data->result )
			|| empty( $data->result->id )
			|| empty( $data->result->pem )
		) {
			return false;
		}

		return $data->result;
	}

	/**
	 * Revoke a Stream signing key via the Cloudflare API.
	 *
	 * @param string $key_id Signing key id.
	 * @return bool
	 */
	public function delete_signing_key( $key_id ) {
		$key_id = sanitize_text_field( (string) $key_id );

		if ( '' === $key_id ) {
			return false;
		}

		$data = $this->decode_api_response( $this->delete( 'stream/keys/' . rawurlencode( $key_id ), array() ) );

		if ( null === $data ) {
			return false;
		}

		return ! empty( $data->success );
	}

	/**
	 * Build a signed playback JWT locally with the signing key (RS256).
	 *
	 * Sets $this->last_local_reason on failure. Caller logs throttled via health.
	 *
	 * @param string $uid Video UID.
	 * @param int    $exp Unix expiry timestamp.
	 * @return string|false
	 */
	public function create_signed_token_local( $uid, $exp ) {
		$this->last_local_reason = '';

		if ( ! $this->is_valid_video_uid( $uid ) ) {
			// Content bug - not a local-signing degradation reason.
			return false;
		}

		try {
			if ( ! function_exists( 'openssl_sign' ) ) {
				$this->last_local_reason = 'local_openssl_missing';
				return false;
			}

			$key_id = $this->get_signing_key_id();
			$pem    = $this->get_signing_key_pem();

			if ( '' === $key_id || '' === $pem ) {
				$this->last_local_reason = $this->db_signing_key_pem_unreadable()
					? 'local_key_unreadable'
					: 'local_key_missing_at_sign';
				return false;
			}

			$exp = intval( $exp );
			if ( $exp <= time() ) {
				$this->last_local_reason = 'local_exp_invalid';
				return false;
			}

			// Cloudflare caps signed token life at 24 hours from signing.
			$max_exp = time() + DAY_IN_SECONDS;
			if ( $exp > $max_exp ) {
				$exp = $max_exp;
			}

			$header = array(
				'alg' => 'RS256',
				'kid' => $key_id,
			);

			$payload = array(
				'sub' => strtolower( $uid ),
				'kid' => $key_id,
				'exp' => $exp,
			);

			$header_json  = wp_json_encode( $header );
			$payload_json = wp_json_encode( $payload );

			if ( false === $header_json || false === $payload_json ) {
				$this->last_local_reason = 'local_json_encode';
				return false;
			}

			$header_b64    = $this->base64url_encode( $header_json );
			$payload_b64   = $this->base64url_encode( $payload_json );
			$signing_input = $header_b64 . '.' . $payload_b64;

			$signature = '';
			$ok        = openssl_sign( $signing_input, $signature, $pem, OPENSSL_ALGO_SHA256 );

			if ( ! $ok || '' === $signature ) {
				$this->last_local_reason = 'local_openssl_sign';
				return false;
			}

			return $signing_input . '.' . $this->base64url_encode( $signature );
		} catch ( Throwable $e ) {
			$this->last_local_reason = 'local_exception';
			return false;
		}
	}

	/**
	 * Record a negative API mint result (reason + per-uid fail cache + breaker).
	 *
	 * @param Cloudflare_Stream_Signing_Health $health   Health tracker.
	 * @param string                           $fail_key Negative cache key.
	 * @param string                           $reason   API reason code.
	 * @return false
	 */
	private function note_api_fail( $health, $fail_key, $reason ) {
		$this->last_api_reason = $reason;
		set_transient( $fail_key, 1, Cloudflare_Stream_Signing_Health::NEGATIVE_CACHE_TTL );
		$health->record_api_failure_only( $reason );
		return false;
	}

	/**
	 * Mint a signed playback token via Cloudflare POST stream/{uid}/token.
	 *
	 * Uses positive token cache, per-uid negative cache, and the global API breaker.
	 * Sets $this->last_api_reason on failure. Does not write local-signing degradation
	 * state (caller decides when a key is configured).
	 *
	 * @param string $uid  Unique Video ID.
	 * @param int    $exp  Unix expiry timestamp.
	 * @param array  $args Additional API arguments.
	 * @return string|false Token string on success, false on failure.
	 */
	private function mint_token_via_api( $uid, $exp, $args = array() ) {
		$this->last_api_reason = '';

		$health = Cloudflare_Stream_Signing_Health::instance();

		if ( $health->is_api_breaker_open() ) {
			$this->last_api_reason = 'api_breaker_open';
			return false;
		}

		$token_api = Cloudflare_Stream_Settings::get_api_token();
		$account   = Cloudflare_Stream_Settings::get_api_account();
		if ( '' === $token_api || '' === $account ) {
			$this->last_api_reason = ( '' === $token_api && $this->db_api_token_unreadable() )
				? 'api_token_unreadable'
				: 'api_credentials_missing';
			return false;
		}

		$duration_minutes = $this->get_signed_url_duration_minutes();
		$cache_key        = $this->get_signed_token_cache_key( $uid );
		$fail_key         = 'cfstream_token_fail_' . md5( strtolower( $uid ) . '|' . $duration_minutes );

		$cached_token = get_transient( $cache_key );
		if ( is_string( $cached_token ) && '' !== $cached_token ) {
			return $cached_token;
		}

		if ( false !== get_transient( $fail_key ) ) {
			$this->last_api_reason = 'api_token_failed';
			return false;
		}

		$args['body'] = wp_json_encode(
			array(
				'exp' => intval( $exp ),
			)
		);

		$response_text = $this->post( 'stream/' . rawurlencode( $uid ) . '/token', $args, false );

		// Transport failure keeps the WP_Error from request().
		if ( is_wp_error( $response_text ) ) {
			return $this->note_api_fail( $health, $fail_key, 'api_transport_error' );
		}

		if ( ! is_string( $response_text ) || '' === $response_text ) {
			return $this->note_api_fail( $health, $fail_key, 'api_http_error' );
		}

		$data = $this->decode_api_response( $response_text );
		if ( null === $data ) {
			return $this->note_api_fail( $health, $fail_key, 'api_bad_payload' );
		}

		if (
			empty( $data->success )
			|| ! isset( $data->result )
			|| ! is_object( $data->result )
			|| empty( $data->result->token )
			|| ! is_string( $data->result->token )
		) {
			return $this->note_api_fail( $health, $fail_key, 'api_token_failed' );
		}

		$token = $data->result->token;

		// Cache for half the token life so renders reuse a still-valid token.
		$cache_ttl = (int) max( 30, floor( ( $duration_minutes * MINUTE_IN_SECONDS ) / 2 ) );
		set_transient( $cache_key, $token, $cache_ttl );
		delete_transient( $fail_key );

		return $token;
	}

	/**
	 * Mint a signed playback token for a video.
	 *
	 * Prefers a local RS256 JWT when a signing key is configured. On local failure,
	 * falls back to POST stream/{uid}/token (still signed). Without a key, uses the
	 * API path as normal (not degraded).
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Unused; kept for backward compatibility.
	 * @since 1.0.5
	 * @return string|false Token string on success, false on failure.
	 */
	public function get_signed_video_token( $uid, $args = array(), $return_headers = false ) {
		unset( $return_headers );

		$this->last_local_reason = '';
		$this->last_api_reason   = '';

		if ( ! $this->is_valid_video_uid( $uid ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Cloudflare Stream: refused signed token request for invalid video uid.' );
			return false;
		}

		$duration_minutes = $this->get_signed_url_duration_minutes();
		$exp              = time() + ( $duration_minutes * MINUTE_IN_SECONDS );
		$health           = Cloudflare_Stream_Signing_Health::instance();
		$state            = $health->get_state();

		// Sign locally when a key is on file (no per-render API call on success).
		if ( $this->has_signing_key() ) {
			$local_reason = '';

			if ( ! $health->is_local_breaker_open( $state ) ) {
				$token = $this->create_signed_token_local( $uid, $exp );
				if ( is_string( $token ) && '' !== $token ) {
					$health->record_local_success();
					return $token;
				}
				$local_reason = $this->last_local_reason ? $this->last_local_reason : 'local_unknown';
				$health->record_local_failure( $uid, $local_reason );
			} else {
				$local_reason            = ! empty( $state['reason'] ) ? $state['reason'] : 'local_unknown';
				$this->last_local_reason = $local_reason;
			}

			$token = $this->mint_token_via_api( $uid, $exp, $args );
			if ( is_string( $token ) && '' !== $token ) {
				$health->record_outcome( $uid, $local_reason );
				return $token;
			}

			$api_reason = $this->last_api_reason ? $this->last_api_reason : 'api_token_failed';
			$health->record_outcome( $uid, $local_reason, $api_reason );
			return false;
		}

		// No local key - normal API mode, no degradation writes.
		$token = $this->mint_token_via_api( $uid, $exp, $args );
		return ( is_string( $token ) && '' !== $token ) ? $token : false;
	}

	/**
	 * Get a specific video's hotlink.
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function get_video_link( $uid, $args = array(), $return_headers = false ) {
		if ( ! $this->is_valid_video_uid( $uid ) ) {
			return '';
		}

		$response_text = $this->request( 'stream/' . rawurlencode( $uid ) . '/preview', $args, $return_headers );
		return $response_text;
	}

	/**
	 * Setup video.
	 *
	 * @param array $args Additional API arguments.
	 * @param bool  $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function init_video( $args = array(), $return_headers = true ) {
		$response_text = $this->post( 'stream', $args, $return_headers );
		return $response_text;
	}

	/**
	 * Default requireSignedURLs / allowedOrigins for new videos.
	 *
	 * Only when Use Signed URLs is on: require signed playback and allow this
	 * site host (from home_url). When off, returns empty so public uploads
	 * are left alone.
	 *
	 * @return array Fragment to merge into create/update bodies.
	 */
	public function get_default_video_security_args() {
		if ( ! get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, Cloudflare_Stream_Settings::DEFAULT_SIGNED_URLS ) ) {
			return array();
		}

		$args = array(
			'requireSignedURLs' => true,
		);

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			$args['allowedOrigins'] = array( $host );
		}

		return $args;
	}

	/**
	 * Read a single HTTP header value from a wp_remote_* header set.
	 *
	 * @param mixed  $headers Header array or case-insensitive object.
	 * @param string $name    Header name.
	 * @return string Header value, or empty string when missing.
	 */
	private function get_response_header( $headers, $name ) {
		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				if ( 0 === strcasecmp( (string) $key, $name ) ) {
					return is_array( $value ) ? (string) reset( $value ) : (string) $value;
				}
			}
			return '';
		}

		if ( is_object( $headers ) && isset( $headers[ $name ] ) ) {
			$value = $headers[ $name ];
			return is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return '';
	}

	/**
	 * Build a tus Upload-Metadata header value (comma-separated key/value pairs).
	 *
	 * Keys are plain text with Cloudflare's expected spelling preserved. Values are
	 * base64. Flag keys omit the value.
	 *
	 * @param array $pairs Map of metadata key => string value, or null for flags.
	 * @return string Upload-Metadata header value.
	 */
	private function build_tus_upload_metadata( $pairs ) {
		$parts = array();

		// Cloudflare documents these spellings in Upload-Metadata (case-sensitive).
		$canonical_keys = array(
			'maxdurationseconds'  => 'maxDurationSeconds',
			'requiresignedurls'  => 'requiresignedurls',
			'allowedorigins'     => 'allowedOrigins',
			'expiry'             => 'expiry',
			'name'               => 'name',
			'filetype'           => 'filetype',
		);

		foreach ( $pairs as $key => $value ) {
			$raw_key = preg_replace( '/[^a-zA-Z0-9]/', '', (string) $key );
			if ( ! is_string( $raw_key ) || '' === $raw_key ) {
				continue;
			}

			$lookup = strtolower( $raw_key );
			$key    = isset( $canonical_keys[ $lookup ] ) ? $canonical_keys[ $lookup ] : $lookup;

			if ( null === $value ) {
				$parts[] = $key;
				continue;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- tus Upload-Metadata values are base64 by protocol.
			$parts[] = $key . ' ' . base64_encode( (string) $value );
		}

		return implode( ',', $parts );
	}

	/**
	 * Cloudflare maximum upload size in bytes (30 GB).
	 *
	 * @return int
	 */
	public function get_max_upload_bytes() {
		return 30 * 1024 * 1024 * 1024;
	}

	/**
	 * Allowed video container MIME types for direct upload.
	 *
	 * @return string[]
	 */
	public function get_allowed_upload_mime_types() {
		$types = array(
			'video/mp4',
			'video/webm',
			'video/ogg',
			'video/quicktime',
			'video/x-msvideo',
			'video/x-matroska',
			'video/mpeg',
			'video/x-m4v',
			'application/mxf',
		);

		/**
		 * Filter the allowlist of MIME types accepted for Stream direct upload.
		 *
		 * @param string[] $types MIME types.
		 */
		$filtered = apply_filters( 'cloudflare_stream_allowed_upload_mime_types', $types );

		if ( ! is_array( $filtered ) ) {
			return $types;
		}

		$out = array();
		foreach ( $filtered as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$type = strtolower( trim( $type ) );
			if ( '' !== $type ) {
				$out[] = $type;
			}
		}

		return ! empty( $out ) ? array_values( array_unique( $out ) ) : $types;
	}

	/**
	 * Maximum encoded duration for a direct upload, in seconds.
	 *
	 * Default one hour; Cloudflare allows up to six hours. Filterable.
	 *
	 * @return int
	 */
	public function get_upload_max_duration_seconds() {
		$default = 3600;

		/**
		 * Filter the maxDurationSeconds value sent with direct uploads.
		 *
		 * @param int $seconds Duration ceiling between 1 and 21600.
		 */
		$seconds = apply_filters( 'cloudflare_stream_upload_max_duration_seconds', $default );
		$seconds = absint( $seconds );

		if ( $seconds < 1 ) {
			$seconds = $default;
		}
		if ( $seconds > 21600 ) {
			$seconds = 21600;
		}

		return $seconds;
	}

	/**
	 * Whether a poster URL host is allowed for embeds.
	 *
	 * Allows the configured media asset host and the standard Cloudflare hosts.
	 *
	 * @param string $url Candidate poster URL.
	 * @return bool
	 */
	public function is_allowed_poster_host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}

		$host = strtolower( $host );

		$allowed = array(
			strtolower( $this->get_media_asset_host() ),
			'videodelivery.net',
			'cloudflarestream.com',
		);

		$configured = strtolower( $this->get_media_domain() );
		if ( '' !== $configured ) {
			$allowed[] = $configured;
		}

		$allowed = array_values( array_unique( array_filter( $allowed ) ) );

		return in_array( $host, $allowed, true );
	}

	/**
	 * Return a poster URL only when its host is known; otherwise the generated poster.
	 *
	 * @param string $candidate Candidate poster URL.
	 * @param string $uid       Video UID or signed token for the fallback poster.
	 * @param string $time      Poster time suffix, e.g. "0s".
	 * @return string
	 */
	public function sanitize_poster_url( $candidate, $uid, $time = '' ) {
		$candidate = is_string( $candidate ) ? esc_url_raw( $candidate ) : '';

		if ( '' !== $candidate && $this->is_allowed_poster_host( $candidate ) ) {
			return $candidate;
		}

		return $this->get_poster_url( $uid, $time );
	}

	/**
	 * Create a one-time TUS direct-upload URL for browser uploads.
	 *
	 * Uses POST /stream?direct_user=true so the API token stays on the server.
	 * Cloudflare returns the upload URL in Location and the video id in
	 * stream-media-id. When Use Signed URLs is on, requiresignedurls and
	 * allowedorigins are set via Upload-Metadata at creation time.
	 *
	 * @param int   $upload_length Byte length of the file (required by tus).
	 * @param array $file_meta     Optional keys: name, filetype.
	 * @since 1.0.0
	 * @return object|null Object with uploadURL and uid, or null on failure.
	 */
	public function create_direct_upload( $upload_length, $file_meta = array() ) {
		$upload_length = absint( $upload_length );
		if ( $upload_length < 1 || $upload_length > $this->get_max_upload_bytes() ) {
			return null;
		}

		if ( ! empty( $file_meta['filetype'] ) && is_string( $file_meta['filetype'] ) ) {
			$filetype = strtolower( trim( $file_meta['filetype'] ) );
			if ( ! in_array( $filetype, $this->get_allowed_upload_mime_types(), true ) ) {
				return null;
			}
		} else {
			$filetype = '';
		}

		// Short-lived direct upload URL; the browser starts the transfer immediately.
		$expiry = gmdate( 'Y-m-d\TH:i:s\Z', time() + ( 30 * MINUTE_IN_SECONDS ) );

		$metadata = array(
			'maxDurationSeconds' => (string) $this->get_upload_max_duration_seconds(),
			'expiry'             => $expiry,
		);

		if ( ! empty( $file_meta['name'] ) && is_string( $file_meta['name'] ) ) {
			$metadata['name'] = $file_meta['name'];
		}

		if ( '' !== $filetype ) {
			$metadata['filetype'] = $filetype;
		}

		$security = $this->get_default_video_security_args();
		if ( ! empty( $security['requireSignedURLs'] ) ) {
			$metadata['requiresignedurls'] = null;
		}
		if ( ! empty( $security['allowedOrigins'] ) && is_array( $security['allowedOrigins'] ) ) {
			$origins = array_values(
				array_filter(
					array_map( 'strval', $security['allowedOrigins'] )
				)
			);
			if ( ! empty( $origins ) ) {
				$metadata['allowedOrigins'] = implode( ',', $origins );
			}
		}

		$request_args = array(
			'headers' => array(
				'Tus-Resumable'   => '1.0.0',
				'Upload-Length'   => (string) $upload_length,
				'Upload-Metadata' => $this->build_tus_upload_metadata( $metadata ),
			),
			'query'   => 'direct_user=true',
			'body'    => '',
			'timeout' => 30,
		);

		$response = $this->post( 'stream', $request_args, 'response' );

		if ( ! is_array( $response ) ) {
			return null;
		}

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$headers    = isset( $response['headers'] ) ? $response['headers'] : array();
		$upload_url = $this->get_response_header( $headers, 'Location' );
		$uid        = $this->get_response_header( $headers, 'stream-media-id' );

		if ( '' === $upload_url || '' === $uid ) {
			return null;
		}

		// uploadURL matches the Cloudflare / browser contract (camelCase).
		$result = new stdClass();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$result->uploadURL = $upload_url;
		$result->uid       = $uid;

		return $result;
	}

	/**
	 * Delete video.
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function delete_video( $uid, $args = array(), $return_headers = false ) {
		if ( ! $this->is_valid_video_uid( $uid ) ) {
			return null;
		}

		$response_text = $this->delete( 'stream/' . rawurlencode( $uid ), $args, $return_headers );
		return $this->decode_api_response( $response_text );
	}

	/**
	 * Retrieve unique Cloudflare account subdomain.
	 *
	 * @param array $args Additional API arguments.
	 * @param bool  $return_headers Return the response headers intead of the response body.
	 * @since 1.0.9
	 */
	public function get_account_subdomain( $args = array(), $return_headers = false ) {
		$response_text = $this->decode_api_response( $this->request( 'stream/', $args, $return_headers ) );

		if ( null === $response_text || empty( $response_text->success ) ) {
			return false;
		}

		if ( ! empty( $response_text->result ) && is_array( $response_text->result ) && count( $response_text->result ) > 0 ) {
			$thumbnail = isset( $response_text->result[0]->thumbnail ) ? $response_text->result[0]->thumbnail : '';
			if ( ! is_string( $thumbnail ) || '' === $thumbnail ) {
				return false;
			}
			$text_array = explode( '/', $thumbnail );
			return isset( $text_array[2] ) ? $text_array[2] : false;
		}

		return false;
	}

	/**
	 * Retrieve Cloudflare Account ID using the Zones API.
	 *
	 * @deprecated The zones API is no longer used by the plugin.
	 * @param bool $save If true, saves retrieved Account ID to database, but only if the option does not already exist.
	 * @since 1.0.9
	 */
	public function get_account_id( $save = false ) {
		$response_text = $this->decode_api_response( $this->request( '', array(), false, self::ZONES_API ) );

		if ( null === $response_text || empty( $response_text->success ) || empty( $response_text->result->account->id ) ) {
			return false;
		}

		$api_id = $response_text->result->account->id;
		if ( strlen( $api_id ) === 32 ) {
			if ( $save ) {
				add_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT, $api_id );
			}
			return $api_id;
		}

		return false;
	}
}
Cloudflare_Stream_API::instance();
