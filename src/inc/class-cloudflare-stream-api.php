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
	 * API Zone ID
	 *
	 * @var string $api_zone_id Cloudflare API zone ID.
	 */
	private $api_zone_id = '';

	/**
	 * API Email
	 *
	 * @var string $api_email Cloudflare API email address.
	 */
	private $api_email = '';

	/**
	 * Last video seen when retrieving paginated results.
	 *
	 * @var string $last_seen Timestamp of the last returned result.
	 */
	public $last_seen = false;

	/**
	 * API Key
	 *
	 * @var string $api_email Cloudflare API key.
	 */
	private $api_key = '';

	/**
	 * API Account ID
	 *
	 * @var string $api_email Cloudflare API account ID.
	 */
	private $api_account = '';

	/**
	 * REST API limit
	 *
	 * @var string $api_limit Number of results to return from the API by default.
	 */
	public $api_limit = 40;

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
	 * @since 1.0.0
	 */
	public function request( $endpoint, $args = array(), $return_headers = false ) {
		$this->api_token   = get_option( Cloudflare_Stream_Settings::OPTION_API_TOKEN );
		$this->api_zone_id = get_option( Cloudflare_Stream_Settings::OPTION_API_ZONE_ID );

		if ( isset( $args['method'] ) ) {
			$method = $args['method'];
		} else {
			$method = 'GET';
		}

		$base_url = 'https://api.cloudflare.com/client/v4/zones/' . $this->api_zone_id . '/';
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
		$response_text = $this->request( 'media', $args, $return_headers );
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
		$response_text = $this->request( 'media/' . $uid, $args, $return_headers );
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
		$response_text = $this->post( 'media/' . $uid, $args, $return_headers );
		return json_decode( $response_text );
	}

	/**
	 * Get the embed code
	 *
	 * @param string $uid Unique Video ID.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function get_video_embed( $uid, $args = array(), $return_headers = false ) {
		$response_text = $this->request( 'media/' . $uid . '/embed', $args, $return_headers );
		return $response_text;
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
        $response_text = $this->request( 'media/' . $uid . '/preview', $args, $return_headers );
		return $response_text;
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
		$video_token_duration = get_option( Cloudflare_Stream_Settings::OPTION_VIDEO_TOKEN_DURATION );

		// Determine token expiration time if custom video token duration is set.
		if ($video_token_duration !== false) {
			$body = [
				'exp' => ( time() + ( intval($video_token_duration) * 60 ) ),
			];
			$body           = wp_json_encode( $body );
			$args[ 'body' ] = $body;
		}

		$args['method'] = 'POST';
        $response_text  = $this->request( 'media/' . $uid . '/token', $args, $return_headers );
		return json_decode( $response_text );
	}

	/**
	 * Setup video.
	 *
	 * @param array $args Additional API arguments.
	 * @param bool  $return_headers Return the response headers intead of the response body.
	 * @since 1.0.0
	 */
	public function init_video( $args = array(), $return_headers = true ) {
		$response_text = $this->post( 'media', $args, $return_headers );
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
		$response_text = $this->delete( 'media/' . $uid, $args, $return_headers );
		return json_decode( $response_text );
	}
}
Cloudflare_Stream_API::instance();
