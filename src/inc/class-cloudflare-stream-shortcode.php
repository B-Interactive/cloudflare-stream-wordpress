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
				'uid'        => '',
				'controls'   => 'true',
				'autoplay'   => 'false',
				'loop'       => 'false',
				'preload'    => 'false',
				'muted'      => 'false',
				'postertime' => '',
				'posterurl'  => '',
			),
			$atts
		);

		// Validate and sanitize inputs.
		foreach ( $attributes as $attr ) {
			if ( $attr === $attributes['uid'] ) {
				$attr = sanitize_text_field( $attr );
				continue; }
			if ( $attr === $attributes['postertime'] && '' !== $attr ) {
				$attr = absint( $attr );
				continue; }
			if ( $attr === $attributes['posterurl'] && '' !== $attr ) {
				$attr = esc_url_raw( $attr );
				continue; }
			$attr = ( filter_var( $attr, FILTER_VALIDATE_BOOLEAN ) ) ? 'true' : 'false'; // Filter to string based boolean.
		}

		$stream_api    = Cloudflare_Stream_API::instance();
		$response_text = $stream_api->get_video_embed( $attributes['uid'], $attributes );

		return $response_text;
	}
}
Cloudflare_Stream_Shortcode::instance();
