<?php
/**
 * Cloudflare Stream Shortcode class
 *
 * Legacy support for WordPress shortcodes.
 *
 * @package cloudflare-stream
 * @since   1.0.0
 */

/**
 * Cloudflare_Stream_Shortcode
 *
 * @usage [cloudflare_stream uid="86432b92bb2d5c02ea57d9d78639f059"]
 */
class Cloudflare_Stream_Shortcode {
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
		add_shortcode( 'cloudflare_stream', array( $this, 'video_shortcode_handler' ) );
	}

	/**
	 * Cloudflare Stream Shortcode Handler
	 *
	 * @param array $atts Video attributes.
	 * @since 1.0.0
	 */
	public function video_shortcode_handler( $atts ) {
		$attributes = shortcode_atts(
			array(
				'uid' => '',
			),
			$atts
		);
		$stream_api = Cloudflare_Stream_API::instance();

		return $stream_api->get_video_embed( $attributes['uid'] );
	}
}
Cloudflare_Stream_Shortcode::instance();
