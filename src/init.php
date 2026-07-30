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
		plugins_url( 'dist/style-blocks.css', __DIR__ ),
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
	// Don't load the block assets if the API credentials are not configured.
	if ( ! Cloudflare_Stream_Settings::is_configured() ) {
		return;
	}

	$current_user = wp_get_current_user();

	// Scripts.
	wp_enqueue_script(
		'cloudflare-stream-block-js',
		// Handle.
		plugins_url( '/dist/blocks.build.js', __DIR__ ),
		// Block.build.js: We register the block here. Built with Webpack.
		array( 'wp-blocks', 'wp-i18n', 'wp-element' ),
		// Dependencies, defined above.
		filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.build.js' ),
		// Version: filemtime — Gets file modification time.
		true
		// Enqueue the script in the footer.
	);

	// Only localise privileged Stream data for users who can manage options.
	// Never send the API token to the browser; uploads use a server-made direct upload URL.
	$can_manage_stream = current_user_can( 'manage_options' );
	$api_account       = $can_manage_stream ? Cloudflare_Stream_Settings::get_api_account() : '';
	$api_nonce         = $can_manage_stream ? wp_create_nonce( Cloudflare_Stream_Settings::NONCE ) : '';
	$api               = Cloudflare_Stream_API::instance();
	wp_localize_script(
		'cloudflare-stream-block-js',
		'cloudflareStream',
		array(
			'nonce' => $api_nonce,
			'api'   => array(
				'account'        => $api_account,
				'posts_per_page' => $api->api_limit,
				'uid'            => md5( $current_user->user_login ),
			),
			// Playback hosts for editor preview iframes (mirrors PHP helpers).
			'mediaDomain'     => $api->get_media_domain(),
			'mediaAssetHost'  => $api->get_media_asset_host(),
			'standardDomains' => Cloudflare_Stream_Settings::STANDARD_MEDIA_DOMAINS,
			'media'           => array(
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
		plugins_url( 'dist/blocks.css', __DIR__ ),
		// Block editor CSS.
		array( 'wp-edit-blocks' ),
		// Dependency to include the CSS after it.
		filemtime( plugin_dir_path( __DIR__ ) . 'dist/blocks.css' )
		// Version: filemtime — Gets file modification time.
	);
	wp_enqueue_style(
		'progressbar',
		plugins_url( 'src/css/jquery-ui.min.css', __DIR__ ),
		array(),
		'1.13.3'
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
add_action( 'init', 'cloudflare_stream_admin_enqueue_scripts' );


/**
 * Render the video block.
 *
 * @param array  $block_attributes  The attributes stored in the block.
 * @param string $content          The static markup of the block.
 * @since 1.0.9
 */
function cloudflare_stream_render_block( $block_attributes, $content ) {

	// Only proceed if we have a UID.
	if ( ! isset( $block_attributes['uid'] ) || empty( $block_attributes['uid'] ) ) {
		return $content;
	}

	// Apply default attributes.
	$defaults = array(
		'controls' => true,
		'autoplay' => false,
		'loop'     => false,
		'preload'  => false,
		'muted'    => false,
	);

	$attributes = wp_parse_args( $block_attributes, $defaults );

	$api   = Cloudflare_Stream_API::instance();
	$embed = $api->get_video_embed( $attributes['uid'], $attributes );

	return '<figure class="wp-block-cloudflare-stream-block-video">' . $embed . '</figure>';
}

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
 * Verify nonce and manage_options for Stream AJAX handlers.
 *
 * @since 1.0.0
 */
function cloudflare_stream_verify_ajax_request() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}
}

/**
 * AJAX method for retrieving a collection of Stream videos.
 *
 * @since 1.0.0
 */
function action_wp_ajax_query_cloudflare_stream_attachments() {
	cloudflare_stream_verify_ajax_request();
	$api            = Cloudflare_Stream_API::instance();
	$args['query']  = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['query'] ) ) : '';
	$args['query'] .= '&limit=' . $api->api_limit;

	$data   = array();
	$videos = $api->get_videos( $args );

	if ( empty( $videos ) || ! is_object( $videos ) || empty( $videos->result ) || ! is_array( $videos->result ) ) {
		wp_send_json(
			array(
				'success' => true,
				'data'    => array(),
				'args'    => $args,
			),
			200
		);
		return;
	}

	foreach ( $videos->result as $video ) {
		$datetime = new DateTime( $video->created );
		// phpcs:ignore
		$embedcode = '<stream src="' . $video->uid . '" controls></stream><script data-cfasync="false" defer type="text/javascript" src="https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=' . $video->uid . '"></script>';
		$shortcode = '[cloudflare_stream uid="' . $video->uid . '"]';
		$data[]    = array(
			'uid'                     => $video->uid,
			'id'                      => $video->uid,
			'title'                   => $video->meta->name,
			'filename'                => $video->meta->name,
			'url'                     => 'https://watch.cloudflarestream.com/' . $video->uid,
			'link'                    => 'https://watch.cloudflarestream.com/' . $video->uid,
			'description'             => $embedcode,
			'caption'                 => $shortcode,
			'status'                  => 'inherit',
			'uploadedTo'              => 0,
			'date'                    => $video->created,
			'modified'                => $video->created,
			'menuOrder'               => 0,
			'mime'                    => 'video/mp4',
			'type'                    => 'video',
			'subtype'                 => 'mp4',
			'icon'                    => $video->thumbnail,
			'dateFormatted'           => $datetime->format( 'F j, Y' ),
			'nonces'                  =>
			array(
				'delete' => Cloudflare_Stream_Settings::NONCE,
			),
			'filesizeInBytes'         => $video->size,
			'filesizeHumanReadable'   => size_format( $video->size ),
			'image'                   => array(
				'src'    => $video->thumbnail,
				'width'  => 64,
				'height' => 48,
			),
			'fileLength'              => gmdate( 'H:i:s', round( $video->duration ) ),
			'fileLengthHumanReadable' => human_readable_duration( gmdate( 'H:i:s', round( $video->duration ) ) ),
			'thumb'                   => array(
				'src'    => $video->thumbnail,
				'width'  => 64,
				'height' => 48,
			),
			'compat'                  => array(
				'item' => '',
				'meta' => '',
			),
			'cloudflare'              => $video,
		);
	}//end foreach

	$response = array( 'success' => true );

	if ( isset( $data ) ) {
		$response['args']    = $args;
		$response['data']    = $data;
		$response['success'] = true;
	}
	wp_send_json( $response, 200 );
}
add_action( 'wp_ajax_query-cloudflare-stream-attachments', 'action_wp_ajax_query_cloudflare_stream_attachments' );

/**
 * AJAX method for checking the status of a video upload.
 *
 * @since 1.0.0
 */
function action_wp_ajax_refresh_check_upload() {
	cloudflare_stream_verify_ajax_request();
	$uid = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';

	$api  = Cloudflare_Stream_API::instance();
	$data = $api->get_video_details( $uid );

	if ( is_object( $data ) && ! empty( $data->success ) ) {
		wp_send_json_success( $data->result );
	}

	$message = __( 'Could not load video details.', 'cloudflare-stream' );
	if ( is_object( $data ) && ! empty( $data->errors[0]->message ) ) {
		$code    = isset( $data->errors[0]->code ) ? $data->errors[0]->code . ' - ' : '';
		$message = sanitize_text_field( $code . $data->errors[0]->message );
	}
	wp_send_json_error( $message );
}
add_action( 'wp_ajax_cloudflare-stream-check-upload', 'action_wp_ajax_refresh_check_upload' );

/**
 * AJAX method for initializing a video upload.
 *
 * Returns a one-time Cloudflare direct upload URL for the browser.
 * The API token stays on the server.
 *
 * @since 1.0.0
 */
function action_wp_ajax_query_cloudflare_stream_upload() {
	cloudflare_stream_verify_ajax_request();

	$api  = Cloudflare_Stream_API::instance();
	$data = $api->create_direct_upload();

	if ( empty( $data ) || ! is_object( $data ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Could not create upload URL.', 'cloudflare-stream' ),
			)
		);
	}

	if ( empty( $data->success ) || empty( $data->result->uploadURL ) || empty( $data->result->uid ) ) {
		$message = __( 'Could not create upload URL.', 'cloudflare-stream' );

		if ( ! empty( $data->errors[0]->message ) ) {
			$message = sanitize_text_field( $data->errors[0]->message );
		}

		wp_send_json_error(
			array(
				'message' => $message,
			)
		);
	}

	wp_send_json_success(
		array(
			'uploadURL' => esc_url_raw( $data->result->uploadURL ),
			'uid'       => sanitize_text_field( $data->result->uid ),
		)
	);
}
add_action( 'wp_ajax_query-cloudflare-stream-upload', 'action_wp_ajax_query_cloudflare_stream_upload' );

/**
 * AJAX method for deleting a video.
 *
 * @since 1.0.0
 */
function action_wp_ajax_cloudflare_stream_delete() {
	cloudflare_stream_verify_ajax_request();
	$uid  = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';
	$api  = Cloudflare_Stream_API::instance();
	$data = $api->delete_video( $uid );

	if ( ! is_object( $data ) || empty( $data->success ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not delete video.', 'cloudflare-stream' ) ) );
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_cloudflare-stream-delete', 'action_wp_ajax_cloudflare_stream_delete' );

/**
 * AJAX method for updating a video.
 *
 * @since 1.0.0
 */
function action_wp_ajax_cloudflare_stream_update() {
	cloudflare_stream_verify_ajax_request();
	$uid    = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';
	$title  = isset( $_REQUEST['title'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['title'] ) ) : '';
	$upload = isset( $_REQUEST['upload'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['upload'] ) ) : '';
	$args   = array(
		'uid'  => $uid,
		'meta' => array(
			'name'   => $title,
			'upload' => $upload,
		),
	);
	$api  = Cloudflare_Stream_API::instance();
	$data = $api->update_video_details( $uid, $args );

	if ( ! is_object( $data ) || empty( $data->success ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not update video.', 'cloudflare-stream' ) ) );
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_cloudflare-stream-update', 'action_wp_ajax_cloudflare_stream_update' );
