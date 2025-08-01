<?php
/**
 * Cloudflare Stream API class
 *
 * Handles API requests, retrieving credentials securely from constants or encrypted storage.
 *
 * @package cloudflare-stream
 * @since   1.0.0
 */

require_once __DIR__ . '/class-cloudflare-stream-security.php';

class Cloudflare_Stream_API {

	// ... (existing properties and constants)

	private static $instance = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}

	private function __construct() { }

	private function setup() {}

	/**
	 * Get API credentials securely (constants or encrypted DB)
	 */
	private function get_api_credentials() {
		return Cloudflare_Stream_Security::get_api_credentials();
	}

	/**
	 * Make the request to the API using secure credentials.
	 */
	public function request( $endpoint, $args = array(), $return_headers = false, $api_type = 'accounts' ) {
		$creds = $this->get_api_credentials();
		if ( ! $creds ) {
			return new WP_Error( 'cloudflare_stream_no_creds', __( 'Cloudflare Stream credentials not configured.', 'cloudflare-stream' ) );
		}
		$this->api_token = $creds['api_token'];
		$this->api_id    = $creds['account_id'];

		$base_url        = 'https://api.cloudflare.com/client/v4/' . $api_type . '/' . $this->api_id . '/';
		$args['headers'] = array(
			'Authorization' => 'Bearer ' . $this->api_token,
			'Content-Type'  => 'application/json',
		);
		$query_string    = isset( $args['query'] ) ? '?' . $args['query'] : '';
		$endpoint       .= $query_string;
		$route           = $base_url . $endpoint;

		$response = wp_remote_request( $route, $args );

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		} elseif ( 'headers' === $return_headers ) {
			return wp_remote_retrieve_headers( $response );
		}
		return wp_remote_retrieve_body( $response );
	}

	// ... (rest of your existing API methods remain unchanged, except credentials retrieval now secure)
}
Cloudflare_Stream_API::instance();
