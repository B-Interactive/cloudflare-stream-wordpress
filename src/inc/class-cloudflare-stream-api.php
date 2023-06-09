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
	 * Get the embed code
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @since 1.0.0
	 */
	public function get_video_embed( $uid, $args = array() ) {
		$signed_urls = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS );
		$uid         = ( $signed_urls ) ? $this->get_signed_video_token( $uid )->result->token : $uid;
		$video_embed = $this->get_video_embed_template( $uid, $args );

		return $video_embed;
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
		$poster_time       = ( '' === $args['postertime'] ) ? get_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME ) : $args['postertime'];
		$poster_time       = $poster_time . 's';
		$poster_url        = ( '' === $args['posterurl'] ) ? esc_url( 'https://' . $media_domain . '/' . $uid . '/thumbnails/thumbnail.jpg?time=' . $poster_time ) : $args['posterurl'];

		$video_embed = '<div class="cloudflare-stream" style="position: relative; padding-top: 56.25%"><iframe'
			. ' src="' . esc_url( $src_uri )
			. ( filter_var( $args['muted'], FILTER_VALIDATE_BOOLEAN ) ? 'muted=true&' : '' )
			. ( filter_var( $args['loop'], FILTER_VALIDATE_BOOLEAN ) ? 'loop=true&' : '' )
			. ( filter_var( $args['autoplay'], FILTER_VALIDATE_BOOLEAN ) ? 'autoplay=true&' : '' )
			. ( filter_var( $args['preload'], FILTER_VALIDATE_BOOLEAN ) ? 'preload=auto&' : '' )
			. ( filter_var( $args['controls'], FILTER_VALIDATE_BOOLEAN ) || ! isset( $args['controls'] ) || strlen( trim( $args['controls'] ) ) === 0 ? '' : 'controls=false&' )
			. 'poster=' . $poster_url . '"'
			. ' style="border: none; position: absolute; top: 0; height: 100%; width: 100%" '
			. 'allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" '
			. 'allowfullscreen="true" '
			. 'id="stream-player"'
			. '></iframe></div>';

		return $video_embed;
	}

	/**
	 * Get a specific video's signed id.
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.5
	 */
	public function get_signed_video_token( $uid, $args = array(), $return_headers = false ) {
		$signed_urls_duration = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION );

		// Determine token expiration time if custom signed urls duration is set.
		if ( false !== $signed_urls_duration ) {
			$body         = array(
				'exp' => ( time() + ( intval( $signed_urls_duration ) * 60 ) ),
			);
			$body         = wp_json_encode( $body );
			$args['body'] = $body;
		}

		$response_text = $this->post( 'stream/' . $uid . '/token', $args, $return_headers );
		return json_decode( $response_text );
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
