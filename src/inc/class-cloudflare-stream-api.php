<?php
/**
 * Cloudflare Stream API class
 *
 * Methods for interacting with the Cloudflare Stream API.
 *
 * @package cloudflare-stream
 * @since      1.0.0
 */

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
	 * Whether this request already marked the response uncacheable for signed embeds.
	 *
	 * @var bool
	 */
	private static $signed_embed_nocache = false;

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
		$this->api_token = get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		$this->api_id    = $this->get_api_id( $api_type );

		$base_url        = 'https://api.cloudflare.com/client/v4/' . $api_type . '/' . $this->api_id . '/';
		$args['headers'] = array(
			'Authorization' => 'Bearer ' . $this->api_token,
			'Content-Type'  => 'application/json',
		);

		// Keep outbound API calls from hanging the page render.
		if ( empty( $args['timeout'] ) ) {
			$args['timeout'] = 15;
		}

		$query_string = isset( $args['query'] ) ? '?' . $args['query'] : '';
		$endpoint    .= $query_string;
		$route        = $base_url . $endpoint;

		// Get remote HTML file.
		$response = wp_remote_request( $route, $args );

		// Check for error.
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		} elseif ( 'headers' === $return_headers ) {
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
			$api_id = get_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT );

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
		return json_decode( $response_text );
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
		$response_text = $this->request( 'stream/' . $uid, $args, $return_headers );
		return json_decode( $response_text );
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
		$response_text = $this->post( 'stream/' . $uid, $args, $return_headers );
		return json_decode( $response_text );
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
	 * Signed URL lifetime in minutes, clamped to Cloudflare's practical bounds.
	 *
	 * @return int Minutes between 1 and 1440.
	 */
	private function get_signed_url_duration_minutes() {
		$minutes = intval( get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION, 60 ) );

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
			define( 'DONOTCACHEPAGE', true );
		}

		if ( ! defined( 'LSCACHE_NO_CACHE' ) ) {
			define( 'LSCACHE_NO_CACHE', true );
		}

		// LiteSpeed Cache listens for this when deciding whether to store the HTML.
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
		$signed_urls = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS );

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
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 *
	 * @since 1.0.9.4
	 */
	public function get_video_embed_template( $uid = 'UID', $args ) {
		$media_domain      = get_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN );
		$standard_domain   = 'https://iframe.' . $media_domain . '/' . $uid . '?';
		$account_subdomain = 'https://' . $media_domain . '/' . $uid . '/iframe?';
		$src_uri           = ( in_array( $media_domain, Cloudflare_Stream_Settings::STANDARD_MEDIA_DOMAINS, true ) ) ? $standard_domain : $account_subdomain;
		$poster_time = empty( $args['postertime'] ) ? get_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME ) : $args['postertime'];
		$poster_time = $poster_time . 's';
		$poster_url  = empty( $args['posterurl'] )
			? 'https://' . $media_domain . '/' . $uid . '/thumbnails/thumbnail.jpg?time=' . $poster_time
			: $args['posterurl'];
		// Escape the poster URL, then encode it as a query value (same idea as encodeURIComponent in JS).
		$poster_url = esc_url( $poster_url );

		$video_embed = '<div class="cloudflare-stream" style="position: relative; padding-top: 56.25%"><iframe'
			. ' src="' . esc_url( $src_uri )
			. ( filter_var( $args['muted'], FILTER_VALIDATE_BOOLEAN ) ? 'muted=true&' : '' )
			. ( filter_var( $args['loop'], FILTER_VALIDATE_BOOLEAN ) ? 'loop=true&' : '' )
			. ( filter_var( $args['autoplay'], FILTER_VALIDATE_BOOLEAN ) ? 'autoplay=true&' : '' )
			. ( filter_var( $args['preload'], FILTER_VALIDATE_BOOLEAN ) ? 'preload=auto&' : '' )
			. ( filter_var( $args['controls'], FILTER_VALIDATE_BOOLEAN ) || ! isset( $args['controls'] ) || strlen( trim( $args['controls'] ) ) === 0 ? '' : 'controls=false&' )
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
	 * Signing key id from constant, then stored option.
	 *
	 * @return string
	 */
	public function get_signing_key_id() {
		if ( defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) ) {
			$id = CLOUDFLARE_STREAM_SIGNING_KEY_ID;
			if ( is_string( $id ) && '' !== $id ) {
				return sanitize_text_field( $id );
			}
		}

		$id = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_ID, '' );

		return is_string( $id ) ? sanitize_text_field( $id ) : '';
	}

	/**
	 * Signing key PEM from constant, then stored option.
	 *
	 * Constants may hold decoded PEM text or the base64 form Cloudflare returns once.
	 *
	 * @return string Decoded PEM text, or empty string.
	 */
	public function get_signing_key_pem() {
		$raw = '';

		if ( defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) ) {
			$constant = CLOUDFLARE_STREAM_SIGNING_KEY_PEM;
			if ( is_string( $constant ) && '' !== $constant ) {
				$raw = $constant;
			}
		}

		if ( '' === $raw ) {
			$option = get_option( Cloudflare_Stream_Settings::OPTION_SIGNING_KEY_PEM, '' );
			if ( is_string( $option ) && '' !== $option ) {
				$raw = $option;
			}
		}

		if ( '' === $raw ) {
			return '';
		}

		$pem = $this->decode_signing_key_material( $raw );

		return $this->is_valid_signing_key_pem( $pem ) ? $pem : '';
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
	 * Whether PHP constants alone provide a usable signing key (ignores options).
	 *
	 * @return bool
	 */
	public function has_signing_key_from_constants() {
		if ( ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_ID' ) || ! defined( 'CLOUDFLARE_STREAM_SIGNING_KEY_PEM' ) ) {
			return false;
		}

		$id  = CLOUDFLARE_STREAM_SIGNING_KEY_ID;
		$raw = CLOUDFLARE_STREAM_SIGNING_KEY_PEM;

		if ( ! is_string( $id ) || '' === $id || ! is_string( $raw ) || '' === $raw ) {
			return false;
		}

		$pem = $this->decode_signing_key_material( $raw );

		return $this->is_valid_signing_key_pem( $pem );
	}

	/**
	 * Create a Stream signing key via the Cloudflare API.
	 *
	 * Response includes id and pem (base64) once; caller decides storage.
	 *
	 * @return object|false Decoded API result object on success, false on failure.
	 */
	public function create_signing_key() {
		$response_text = $this->post( 'stream/keys', array() );

		if ( ! is_string( $response_text ) || '' === $response_text ) {
			error_log( 'Cloudflare Stream: create signing key failed with empty response.' );
			return false;
		}

		$data = json_decode( $response_text );

		if (
			! is_object( $data )
			|| empty( $data->success )
			|| ! isset( $data->result )
			|| ! is_object( $data->result )
			|| empty( $data->result->id )
			|| empty( $data->result->pem )
		) {
			error_log( 'Cloudflare Stream: create signing key response was invalid or unsuccessful.' );
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

		$response_text = $this->delete( 'stream/keys/' . rawurlencode( $key_id ), array() );

		if ( ! is_string( $response_text ) || '' === $response_text ) {
			error_log( 'Cloudflare Stream: delete signing key failed with empty response.' );
			return false;
		}

		$data = json_decode( $response_text );

		return is_object( $data ) && ! empty( $data->success );
	}

	/**
	 * Build a signed playback JWT locally with the signing key (RS256).
	 *
	 * @param string $uid Video UID.
	 * @param int    $exp Unix expiry timestamp.
	 * @return string|false
	 */
	public function create_signed_token_local( $uid, $exp ) {
		if ( ! $this->is_valid_video_uid( $uid ) ) {
			return false;
		}

		$key_id = $this->get_signing_key_id();
		$pem    = $this->get_signing_key_pem();

		if ( '' === $key_id || '' === $pem ) {
			return false;
		}

		$exp = intval( $exp );
		if ( $exp <= time() ) {
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

		$header_b64  = $this->base64url_encode( wp_json_encode( $header ) );
		$payload_b64 = $this->base64url_encode( wp_json_encode( $payload ) );
		$signing_input = $header_b64 . '.' . $payload_b64;

		$signature = '';
		$ok        = openssl_sign( $signing_input, $signature, $pem, OPENSSL_ALGO_SHA256 );

		if ( ! $ok || '' === $signature ) {
			error_log( 'Cloudflare Stream: local RS256 signing failed.' );
			return false;
		}

		return $signing_input . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * Mint a signed playback token for a video.
	 *
	 * Prefers a local RS256 JWT when a signing key is configured; otherwise
	 * falls back to POST stream/{uid}/token and short-lived transient cache.
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Unused; kept for backward compatibility.
	 * @since 1.0.5
	 * @return string|false Token string on success, false on failure.
	 */
	public function get_signed_video_token( $uid, $args = array(), $return_headers = false ) {
		unset( $return_headers );

		if ( ! $this->is_valid_video_uid( $uid ) ) {
			error_log( 'Cloudflare Stream: refused signed token request for invalid video uid.' );
			return false;
		}

		$duration_minutes = $this->get_signed_url_duration_minutes();
		$exp              = time() + ( $duration_minutes * MINUTE_IN_SECONDS );

		// Sign locally when a key is on file (no per-render API call).
		if ( $this->has_signing_key() ) {
			$token = $this->create_signed_token_local( $uid, $exp );
			if ( is_string( $token ) && '' !== $token ) {
				return $token;
			}
			error_log( 'Cloudflare Stream: local signing failed; not falling back to API token mint while a key is configured.' );
			return false;
		}

		$cache_key    = 'cfstream_signed_token_' . md5( strtolower( $uid ) . '|' . $duration_minutes );
		$cached_token = get_transient( $cache_key );

		if ( is_string( $cached_token ) && '' !== $cached_token ) {
			return $cached_token;
		}

		$args['body'] = wp_json_encode(
			array(
				'exp' => $exp,
			)
		);

		$response_text = $this->post( 'stream/' . $uid . '/token', $args, false );

		if ( ! is_string( $response_text ) || '' === $response_text ) {
			error_log( 'Cloudflare Stream: signed token request failed with empty response.' );
			return false;
		}

		$data = json_decode( $response_text );

		if (
			! is_object( $data )
			|| empty( $data->success )
			|| ! isset( $data->result )
			|| ! is_object( $data->result )
			|| empty( $data->result->token )
			|| ! is_string( $data->result->token )
		) {
			error_log( 'Cloudflare Stream: signed token response was invalid or unsuccessful.' );
			return false;
		}

		$token = $data->result->token;

		// Cache for half the token life so renders reuse a still-valid token.
		$cache_ttl = (int) max( 30, floor( ( $duration_minutes * MINUTE_IN_SECONDS ) / 2 ) );
		set_transient( $cache_key, $token, $cache_ttl );

		return $token;
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
		$response_text = $this->request( 'stream/' . $uid . '/preview', $args, $return_headers );
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
	 * Create a one-time direct upload URL for browser TUS uploads.
	 *
	 * @param array $args Optional body fields for the Cloudflare direct_upload endpoint.
	 * @since 1.0.0
	 * @return object|null Decoded API response, or null on transport failure.
	 */
	public function create_direct_upload( $args = array() ) {
		$defaults = array(
			'maxDurationSeconds' => 21600,
			'requireSignedURLs'  => (bool) get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS ),
		);

		$body = wp_parse_args( $args, $defaults );

		$request_args = array(
			'body' => wp_json_encode( $body ),
		);

		$response_text = $this->post( 'stream/direct_upload', $request_args );

		if ( empty( $response_text ) || ! is_string( $response_text ) ) {
			return null;
		}

		return json_decode( $response_text );
	}

	/**
	 * Delete video.
	 *
	 * @param array $uid Unique Video ID.
	 * @param array $args Additional API arguments.
	 * @param bool  $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function delete_video( $uid, $args = array(), $return_headers = false ) {
		$response_text = $this->delete( 'stream/' . $uid, $args, $return_headers );
		return json_decode( $response_text );
	}

	/**
	 * Retrieve unique Cloudflare account subdomain.
	 *
	 * @param array $args Additional API arguments.
	 * @param bool  $return_headers Return the response headers intead of the response body.
	 * @since 1.0.9
	 */
	public function get_account_subdomain( $args = array(), $return_headers = false ) {
		$response_text = json_decode( $this->request( 'stream/', $args, $return_headers ) );
		if ( $response_text->success ) {
			if ( count( $response_text->result ) > 0 ) {
				$text_array = explode( '/', $response_text->result[0]->thumbnail );
				return $text_array[2];
			}
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
		$response_text = json_decode( $this->request( '', array(), false, self::ZONES_API ) );
		if ( $response_text->success ) {
			$api_id = $response_text->result->account->id;
			if ( strlen( $api_id ) === 32 ) {
				if ( $save ) {
					add_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT, $api_id );
				}
				return $api_id;
			}
		}
		return false;
	}
}
Cloudflare_Stream_API::instance();
