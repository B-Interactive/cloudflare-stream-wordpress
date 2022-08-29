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
	 * API Account ID
	 *
	 * @var string $api_email Cloudflare API account ID.
	 */
	private $api_account = '';

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
		$this->api_account = get_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT );

		// Supports transition back to Accounts API by auto-fetching Account ID if already using Zones.
		// Since 1.0.9
		if ( empty( $this->api_account ) ) {
			$this->api_account = $this->get_account_id( true );
		}

		if ( isset( $args['method'] ) ) {
			$method = $args['method'];
		} else {
			$method = 'GET';
		}

		$base_url = 'https://api.cloudflare.com/client/v4/accounts/' . $this->api_account . '/';
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
	 * Make request to the Zones API.
	 * This method is being deprecated and remains to support transition to the more applicable
	 * Accounts API.
	 *
	 * @param string $endpoint API Endpoint.
	 * @param array  $args Additional API arguments.
	 * @param bool   $return_headers Return the response headers intead of the response body.
	 * @since 1.0.9
	 */
	public function zone_request( $endpoint, $args = array(), $return_headers = false ) {
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
		$signed_urls = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS );
		if ( $signed_urls ) {
			$uid = $this->get_signed_video_token($uid)->result->token;
		}
		$video_embed = '<div style="position: relative; padding-top: 56.25%"><iframe'
			. ' src="https://iframe.videodelivery.net/' . $uid . '?'
			. 'muted='    . $args['muted'] . '&'
			. 'preload='  . $args['preload'] . '&'
			. 'loop='     . $args['loop'] . '&'
			. 'autoplay=' . $args['autoplay'] . '&'
			. 'controls=' . $args['controls'] . '" '
			. 'style="border: none; position: absolute; top: 0; height: 100%; width: 100%" '
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
		if ( $signed_urls_duration != false ) {
			$body = [
				'exp' => ( time() + ( intval($signed_urls_duration) * 60 ) ),
			];
			$body           = wp_json_encode( $body );
			$args['body'] = $body;
		}

		$args['method'] = 'POST';
        $response_text  = $this->request( 'media/' . $uid . '/token', $args, $return_headers );
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
        $response_text = $this->request( 'media/' . $uid . '/preview', $args, $return_headers );
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

	/**
	 * Retrieve Cloudflare Account ID using the Zones API.
	 *
	 * @param bool   $save If true, saves retrieved Account ID to database, but only if the option does not already exist.
	 * @since 1.0.9
	 */
	public function get_account_id( $save = false ) {
        $response_text = json_decode( $this->zone_request( '', array(), false ) );
		if ( $response_text->success )
			$api_account = $response_text->result->account->id;
			if ( strlen( $api_account ) == 32 ) {
				if ( $save ) {
					add_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT, $api_account );
				}
				return $api_account;
		}
		return false;
	}

}
Cloudflare_Stream_API::instance();
