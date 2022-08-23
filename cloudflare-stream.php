<?php
/**
 * Plugin Name: Cloudflare Stream
 * Plugin URI: https://github.com/B-Interactive/cloudflare-stream-wordpress
 * Description: Cloudflare Stream Video is an easy-to-use, affordable, on-demand video streaming platform. Stream seamlessly integrates video storage, encoding, and a customizable player with Cloudflare’s fast, secure, and reliable global network, so that you can spend less time managing video delivery and more time building and promoting your product.
 * Author: Cloudflare, B-Interactive, davidpurdy
 * Author URI: https://github.com/B-Interactive/cloudflare-stream-wordpress
 * Version: 1.0.8
 * License: GPL2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * @package cloudflare-stream
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Stream Settings Page
 */
require_once plugin_dir_path( __FILE__ ) . 'src/inc/class-cloudflare-stream-settings.php';

/**
 * Cloudflare Stream API
 */
require_once plugin_dir_path( __FILE__ ) . 'src/inc/class-cloudflare-stream-api.php';

/**
 * Cloudflare Stream Shortcode
 */
require_once plugin_dir_path( __FILE__ ) . 'src/inc/class-cloudflare-stream-shortcode.php';

/**
 * Block Initializer.
 */
require_once plugin_dir_path( __FILE__ ) . 'src/init.php';
