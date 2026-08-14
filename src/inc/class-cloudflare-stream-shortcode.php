<?php
/**
 * Cloudflare Stream Shortcode class
 *
 * Legacy support for WordPress shortcodes.
 *
 * @package cloudflare-stream
 * @since   1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare_Stream_Shortcode
 *
 * @usage [cloudflare_stream uid="86432b92bb2d5c02ea57d9d78639f059"]
 */
class Cloudflare_Stream_Shortcode {
	/**
	 * Define and register singleton
	 *
	 * @var self|false
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
	 * @return string Embed HTML, or empty when the video cannot be rendered.
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

		// Sanitize each attribute by key.
		$attributes['uid']        = sanitize_text_field( $attributes['uid'] );
		$attributes['postertime'] = ( '' !== $attributes['postertime'] ) ? absint( $attributes['postertime'] ) : '';
		$attributes['posterurl']  = ( '' !== $attributes['posterurl'] ) ? esc_url_raw( $attributes['posterurl'] ) : '';

		// Hand real booleans to the embed builder (shortcode_atts leaves strings).
		foreach ( array( 'controls', 'autoplay', 'loop', 'preload', 'muted' ) as $flag ) {
			$attributes[ $flag ] = Cloudflare_Stream_API::normalize_bool( $attributes[ $flag ] );
		}

		$stream_api    = Cloudflare_Stream_API::instance();
		$response_text = $stream_api->get_video_embed( $attributes['uid'], $attributes );

		// Editors viewing the front end see a diagnostic comment when signed embed is empty.
		if ( ( ! is_string( $response_text ) || '' === $response_text ) && class_exists( 'Cloudflare_Stream_Signing_Health' ) ) {
			$comment = Cloudflare_Stream_Signing_Health::instance()->get_editor_failure_comment();
			if ( '' !== $comment ) {
				return $comment;
			}
		}

		return $response_text;
	}
}
Cloudflare_Stream_Shortcode::instance();
