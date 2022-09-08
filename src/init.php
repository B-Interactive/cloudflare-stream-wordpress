<?php
/**
 * Blocks Initializer
 *
 * Enqueue CSS/JS of all the blocks.
 *
 * @since   1.0.0
 * @package cloudflare-stream
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue Cloudflare Stream block assets for both frontend + backend.
 *
 * `wp-blocks`: includes block type registration and related functions.
 *
 * @since 1.0.0
 */
function cloudflare_stream_block_assets() {
	// Styles.
	wp_enqueue_style(
		'cloudflare-stream-block-style-css',
		// Handle.
		plugins_url( 'dist/style-blocks.css', dirname( __FILE__ ) ),
		// Block style CSS.
		array( 'wp-block-library' ),
		// Dependency to include the CSS after it.
		filemtime( plugin_dir_path( __DIR__ ) . 'dist/style-blocks.css' )
		// Version: filemtime — Gets file modification time.
	);
} // End function cloudflare_stream_block_assets().

// Hook: Frontend assets.
add_action( 'enqueue_block_assets', 'cloudflare_stream_block_assets' );

/**
 * Enqueue Cloudflare Stream block assets for backend editor.
 *
 * `wp-blocks`: includes block type registration and related functions.
 * `wp-element`: includes the WordPress Element abstraction for describing the structure of your blocks.
 * `wp-i18n`: To internationalize the block's text.
 *
 * @since 1.0.0
 */
function cloudflare_stream_block_editor_assets() {
	// Don't load the block assets if the API keys are not configured.
	if ( ! Cloudflare_Stream_Settings::is_configured() ) {
		return;
	}

	$current_user = wp_get_current_user();

	// Scripts.
	wp_enqueue_script(
		'cloudflare-stream-block-js',
		// Handle.
		plugins_url( '/dist/blocks.build.js', dirname( __FILE__ ) ),
		// Block.build.js: We register the block here. Built with Webpack.
		array( 'wp-blocks', 'wp-i18n', 'wp-element' ),
		// Dependencies, defined above.
		filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.build.js' ),
		// Version: filemtime — Gets file modification time.
		true
		// Enqueue the script in the footer.
	);

	// Don't load the API Credentials if the user cannot edit the post.
	$api_key = current_user_can( 'administrator' ) ? get_option( Cloudflare_Stream_Settings::OPTION_API_KEY ) : '';
	$api = Cloudflare_Stream_API::instance();
	wp_localize_script(
		'cloudflare-stream-block-js',
		'cloudflareStream',
		array(
			'nonce'   => wp_create_nonce( Cloudflare_Stream_Settings::NONCE ),
			'api'     => array(
				'email'          => get_option( Cloudflare_Stream_Settings::OPTION_API_EMAIL ),
				'key'            => $api_key,
				'account'        => get_option( Cloudflare_Stream_Settings::OPTION_API_ACCOUNT ),
				'posts_per_page' => $api->api_limit,
				'uid'            => md5( $current_user->user_login ),
			),
			'media'   => array(
				'view'  => array(),
				'model' => array(),
			),
		)
	);

	// The jQuery UI progress bar.
	wp_enqueue_script( 'jquery-ui-progressbar' );

	// Styles.
	wp_enqueue_style(
		'cloudflare-stream-block-editor-css',
		// Handle.
		plugins_url( 'dist/blocks.css', dirname( __FILE__ ) ),
		// Block editor CSS.
		array( 'wp-edit-blocks' ),
		// Dependency to include the CSS after it.
		filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.css' )
		// Version: filemtime — Gets file modification time.
	);
	wp_enqueue_style(
		'progressbar',
		'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.css',
		array(),
		'1.12.1'
	);
} // End function cloudflare_stream_editor_assets().

// Hook: Editor assets.
add_action( 'enqueue_block_editor_assets', 'cloudflare_stream_block_editor_assets' );

/**
 * Load the JavaScript on admin
 *
 * @since 1.0.0
 */
function cloudflare_stream_admin_enqueue_scripts() {
	// Registering the block.
	register_block_type(
		'cloudflare-stream/block-video',
		array(
			'render_callback' => 'cloudflare_stream_render_block',
		)
	);
}
add_action( 'admin_enqueue_scripts', 'cloudflare_stream_admin_enqueue_scripts' );

/**
 * Adds 'upload-php' class to the <body> tag.
 *
 * @param array $classes Array of CSS classes.
 * @since 1.0.0
 */
function cloudflare_stream_admin_body_class( $classes ) {
	return "$classes upload-php cloudflare-stream";
}
add_filter( 'admin_body_class', 'cloudflare_stream_admin_body_class' );

/**
 * AJAX method for retrieving a collection of Stream videos.
 *
 * @since 1.0.0
 */
function action_wp_ajax_query_cloudflare_stream_attachments() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	$api            = Cloudflare_Stream_API::instance();
	$args['query']  = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
	$args['query'] .= '&limit=' . $api->api_limit;

	$data   = array();
	$videos = $api->get_videos( $args );

	foreach ( $videos->result as $video ) {
		$datetime = new DateTime( $video->created );
		// phpcs:ignore
		$embedcode = '<stream src="' . $video->uid . '" controls></stream><script data-cfasync="false" defer type="text/javascript" src="https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=' . $video->uid . '"></script>';
		$shortcode = '[cloudflare_stream uid="' . $video->uid . '"]';
		$data[]    = array(
			'uid'                   => $video->uid,
			'id'                    => $video->uid,
			'title'                 => $video->meta->name,
			'filename'              => $video->meta->name,
			'url'                   => 'https://watch.cloudflarestream.com/' . $video->uid,
			'link'                  => 'https://watch.cloudflarestream.com/' . $video->uid,
			'description'           => $embedcode,
			'caption'               => $shortcode,
			'status'                => 'inherit',
			'uploadedTo'            => 0,
			'date'                  => $video->created,
			'modified'              => $video->created,
			'menuOrder'             => 0,
			'mime'                  => 'video/mp4',
			'type'                  => 'video',
			'subtype'               => 'mp4',
			'icon'                  => $video->thumbnail,
			'dateFormatted'         => $datetime->format( 'F j, Y' ),
			'nonces'                =>
			array(
				'delete' => Cloudflare_Stream_Settings::NONCE,
			),
			'filesizeInBytes'       => $video->size,
			'filesizeHumanReadable' => size_format( $video->size ),
			'image'                 => array(
				'src'    => $video->thumbnail,
				'width'  => 64,
				'height' => 48,
			),
			'thumb'                 => array(
				'src'    => $video->thumbnail,
				'width'  => 64,
				'height' => 48,
			),
			'compat'                => array(
				'item' => '',
				'meta' => '',
			),
			'cloudflare'            => $video,
		);
	}//end foreach

	$response = array( 'success' => true );

	if ( isset( $data ) ) {
		$response['args']    = $args;
		$response['data']    = $data;
		$response['success'] = true;
	};
	wp_send_json( $response, 200 );
}
add_action( 'wp_ajax_query-cloudflare-stream-attachments', 'action_wp_ajax_query_cloudflare_stream_attachments' );

/**
 * AJAX method for checking the status of a video upload.
 *
 * @since 1.0.0
 */
function action_wp_ajax_refresh_check_upload() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	$uid  = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';
	$data = array();

	$api  = Cloudflare_Stream_API::instance();
	$data = $api->get_video_details( $uid );

	if ( isset( $data->success ) && $data->success ) {
		wp_send_json_success( $data->result );
	} else {
		wp_send_json_error( $data->errors[0]->code . ' - ' . $data->errors[0]->message );
	}
}
add_action( 'wp_ajax_cloudflare-stream-check-upload', 'action_wp_ajax_refresh_check_upload' );

/**
 * AJAX method for initializing a video upload.
 *
 * @since 1.0.0
 */
function action_wp_ajax_query_cloudflare_stream_upload() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	$api  = Cloudflare_Stream_API::instance();
	$data = $api->init_video();
	wp_send_json_success( $data );
}
add_action( 'wp_ajax_query-cloudflare-stream-upload', 'action_wp_ajax_query_cloudflare_stream_upload' );

/**
 * AJAX method for deleting a video.
 *
 * @since 1.0.0
 */
function action_wp_ajax_cloudflare_stream_delete() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	$uid  = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';
	$data = array();
	$api  = Cloudflare_Stream_API::instance();
	$data = $api->delete_video( $uid );
	wp_send_json_success( $data );
}
add_action( 'wp_ajax_cloudflare-stream-delete', 'action_wp_ajax_cloudflare_stream_delete' );

/**
 * AJAX method for updating a video.
 *
 * @since 1.0.0
 */
function action_wp_ajax_cloudflare_stream_update() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	$uid    = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';
	$title  = isset( $_REQUEST['title'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['title'] ) ) : '';
	$upload = isset( $_REQUEST['upload'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['upload'] ) ) : '';
	$data   = array();
	$args   = array(
		'uid'  => $uid,
		'meta' => array(
			'name'   => $title,
			'upload' => $upload,
		),
	);
	$api    = Cloudflare_Stream_API::instance();
	$data   = $api->update_video_details( 'b9105cd434ea071e32690336969991cd', $args );
	wp_send_json_success( $data );
}
add_action( 'wp_ajax_cloudflare-stream-update', 'action_wp_ajax_cloudflare_stream_update' );
