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
                'controls' => 'true',
                'autoplay' => 'false',
                'loop' => 'false',
                'preload' => 'false',
                'muted' => 'false',
			),
			$atts
		);
		$stream_api = Cloudflare_Stream_API::instance();
        $signed_video_token = $stream_api->get_signed_video_token($attributes['uid']);

		return $this->generate_video_embed( $signed_video_token->result->token, $attributes );
	}

	/**
	 * Cloudflare Stream Embed Generator
	 *
	 * @param string $suid Signed token.
	 * @param array $atts Video attributes.
	 * @since 1.0.5
	 */
	private function generate_video_embed( $suid, $atts ) {
        $embed = '<div class="cloudflare-stream-video" style="position: relative; height: 0px; width: 100%; padding-top: 56.25%;"><iframe src="https://iframe.videodelivery.net/'
        . $suid . '?'
        . 'muted=' . $atts['muted'] . '&'
        . 'preload=' . $atts['preload'] . '&'
        . 'loop=' . $atts['loop'] . '&'
        . 'autoplay=' . $atts['autoplay'] . '&'
        . 'controls=' . $atts['controls'] . '" '
        . 'style="border: none; position: absolute; top: 0; height: 100%; width: 100%;" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;" allowfullscreen="true"></iframe></div>';

        return $embed;
    }
}
Cloudflare_Stream_Shortcode::instance();
