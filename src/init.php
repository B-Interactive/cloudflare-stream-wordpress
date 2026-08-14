<?php
/**
 * Block registration, asset enqueues, and Stream AJAX handlers.
 *
 * @since   1.0.0
 * @package cloudflare-stream
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register block stylesheets referenced by block.json handles.
 *
 * Front-end `style` is registered only; WordPress loads it when the block is
 * present. Editor CSS is enqueued separately for the canvas iframe.
 *
 * @since 1.0.0
 */
function cloudflare_stream_register_block_styles() {
	static $registered = false;
	if ( $registered ) {
		return;
	}
	$registered = true;

	$style_path = plugin_dir_path( __DIR__ ) . 'dist/style-blocks.css';
	if ( file_exists( $style_path ) ) {
		wp_register_style(
			'cloudflare-stream-block-style-css',
			plugins_url( 'dist/style-blocks.css', __DIR__ ),
			array( 'wp-block-library' ),
			filemtime( $style_path )
		);
	}

	$editor_style_path = plugin_dir_path( __DIR__ ) . 'dist/blocks.css';
	if ( file_exists( $editor_style_path ) ) {
		wp_register_style(
			'cloudflare-stream-block-editor-css',
			plugins_url( 'dist/blocks.css', __DIR__ ),
			array( 'wp-edit-blocks' ),
			filemtime( $editor_style_path )
		);
	}
}
add_action( 'init', 'cloudflare_stream_register_block_styles', 5 );

/**
 * Enqueue editor block styles for the canvas iframe.
 *
 * Front-end block style is left to block.json registration so it loads only
 * when the block is present on the page.
 *
 * @since 1.0.0
 */
function cloudflare_stream_block_assets() {
	if ( ! is_admin() ) {
		return;
	}

	cloudflare_stream_register_block_styles();

	// Front-end style is also needed inside the editor canvas iframe.
	if ( wp_style_is( 'cloudflare-stream-block-style-css', 'registered' ) ) {
		wp_enqueue_style( 'cloudflare-stream-block-style-css' );
	}

	if ( wp_style_is( 'cloudflare-stream-block-editor-css', 'registered' ) ) {
		wp_enqueue_style( 'cloudflare-stream-block-editor-css' );
	}
} // End function cloudflare_stream_block_assets().

add_action( 'enqueue_block_assets', 'cloudflare_stream_block_assets' );

/**
 * Enqueue the block editor script and its localised Stream data.
 *
 * @since 1.0.0
 */
function cloudflare_stream_block_editor_assets() {
	// Don't load the block assets if the API credentials are not configured.
	if ( ! Cloudflare_Stream_Settings::is_configured() ) {
		return;
	}

	$script_path = plugin_dir_path( __DIR__ ) . 'dist/blocks.build.js';
	if ( file_exists( $script_path ) ) {
		$asset_path = plugin_dir_path( __DIR__ ) . 'dist/blocks.build.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => filemtime( $script_path ),
			);

		wp_enqueue_script(
			'cloudflare-stream-block-js',
			plugins_url( '/dist/blocks.build.js', __DIR__ ),
			// media-views is required by the Stream media frame (wp.media global).
			array_merge( $asset['dependencies'], array( 'media-views' ) ),
			$asset['version'],
			true
		);

		// Nonce is issued to content editors so library browse and signed preview
		// can run. Each AJAX handler still checks its own capability; mutating
		// actions require manage_options. The API token never reaches the browser.
		$can_edit_content = current_user_can( 'edit_posts' );
		$can_manage       = current_user_can( 'manage_options' );
		$api_nonce        = ( $can_edit_content || $can_manage )
			? wp_create_nonce( Cloudflare_Stream_Settings::NONCE )
			: '';
		$api              = Cloudflare_Stream_API::instance();

		wp_localize_script(
			'cloudflare-stream-block-js',
			'cloudflareStream',
			array(
				'nonce'           => $api_nonce,
				// Upload, rename and delete stay administrative.
				'canManage'       => $can_manage,
				'api'             => array(
					'posts_per_page' => $api->api_limit,
				),
				// Playback host for editor preview iframes (mirrors PHP helpers).
				'mediaDomain'     => $api->get_media_domain(),
				'standardDomains' => Cloudflare_Stream_Settings::STANDARD_MEDIA_DOMAINS,
				// When on, preview URLs must be fetched server-side so they carry
				// a signed token. Only ever a boolean; no key material is exposed.
				'signedUrls'      => $api->is_signed_playback_enabled(),
				// Namespaces the bundled media models and views attach to.
				'media'           => array(
					'view'  => array(),
					'model' => array(),
				),
			)
		);
	}
} // End function cloudflare_stream_block_editor_assets().

add_action( 'enqueue_block_editor_assets', 'cloudflare_stream_block_editor_assets' );

/**
 * Register the video block from block.json metadata plus the render callback.
 *
 * @since 1.0.0
 */
function cloudflare_stream_register_block() {
	register_block_type(
		__DIR__ . '/block',
		array(
			'render_callback' => 'cloudflare_stream_render_block',
		)
	);
}
add_action( 'init', 'cloudflare_stream_register_block' );


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

	// Defaults for flags the block always carries. Preload is left unset so the
	// embed template default applies unless the block attribute is present.
	$defaults = array(
		'controls' => true,
		'autoplay' => false,
		'loop'     => false,
		'muted'    => false,
	);

	$attributes = wp_parse_args( $block_attributes, $defaults );

	// Pass real booleans to the embed builder.
	foreach ( array( 'controls', 'autoplay', 'loop', 'preload', 'muted' ) as $flag ) {
		if ( array_key_exists( $flag, $attributes ) ) {
			$attributes[ $flag ] = Cloudflare_Stream_API::normalize_bool( $attributes[ $flag ] );
		}
	}

	$api   = Cloudflare_Stream_API::instance();
	$embed = $api->get_video_embed( $attributes['uid'], $attributes );

	// Editor-only diagnostic when signed embed is empty (total failure).
	if ( '' === $embed && class_exists( 'Cloudflare_Stream_Signing_Health' ) ) {
		$comment = Cloudflare_Stream_Signing_Health::instance()->get_editor_failure_comment();
		if ( '' !== $comment ) {
			$embed = $comment;
		}
	}

	// Wrapper carries supports.align classes to match the editor block props.
	return '<figure ' . get_block_wrapper_attributes() . '>' . $embed . '</figure>';
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
 * Require a capability for a Stream AJAX handler.
 *
 * Nonce checks stay in each handler so PHPCS can see them before $_REQUEST use.
 *
 * @param string $capability Capability slug. Default manage_options.
 * @since 1.0.0
 */
function cloudflare_stream_verify_ajax_capability( $capability = 'manage_options' ) {
	$capability = is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';

	if ( ! current_user_can( $capability ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}
}

/**
 * Capability for read-only Stream AJAX used while editing content.
 *
 * @return string
 */
function cloudflare_stream_ajax_edit_capability() {
	return 'edit_posts';
}

/**
 * Poster time suffix used for Stream thumbnails, e.g. "0s".
 *
 * @since 1.1.7
 * @return string
 */
function cloudflare_stream_poster_time() {
	return absint(
		get_option(
			Cloudflare_Stream_Settings::OPTION_POSTER_TIME,
			Cloudflare_Stream_Settings::DEFAULT_POSTER_TIME
		)
	) . 's';
}

/**
 * Whether a value looks like an RFC 3339 / ISO 8601 timestamp for Stream list filters.
 *
 * @param string $value Candidate timestamp.
 * @return bool
 */
function cloudflare_stream_is_stream_list_timestamp( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return false;
	}

	// Cloudflare accepts RFC 3339 style created bounds; keep the charset tight.
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value ) ) {
		return false;
	}

	$parsed = strtotime( $value );
	return false !== $parsed;
}

/**
 * Build named Cloudflare Stream list query arguments from a request-like array.
 *
 * Only known keys are forwarded. The client never supplies a raw query fragment.
 *
 * @param array $input Raw input (usually $_REQUEST).
 * @return array {
 *     @type array    $query        Query args for Cloudflare_Stream_API::get_videos().
 *     @type string[] $exclude_uids Video UIDs to drop from the result set.
 * }
 */
function cloudflare_stream_build_stream_list_query( $input ) {
	$api   = Cloudflare_Stream_API::instance();
	$input = is_array( $input ) ? $input : array();
	$query = array(
		'limit' => (int) $api->api_limit,
		'asc'   => 'false',
	);

	if ( isset( $input['limit'] ) ) {
		$limit = absint( $input['limit'] );
		if ( $limit > 0 ) {
			// Cloudflare caps list results at 1000; stay within the plugin page size.
			$query['limit'] = min( $limit, (int) $api->api_limit, 1000 );
		}
	}

	if ( isset( $input['asc'] ) ) {
		$asc = strtolower( sanitize_text_field( wp_unslash( $input['asc'] ) ) );
		if ( in_array( $asc, array( 'true', '1', 'false', '0' ), true ) ) {
			$query['asc'] = in_array( $asc, array( 'true', '1' ), true ) ? 'true' : 'false';
		}
	}

	if ( isset( $input['search'] ) ) {
		$search = sanitize_text_field( wp_unslash( $input['search'] ) );
		if ( '' !== $search ) {
			$query['search'] = $search;
		}
	}

	// Newest-first pages use before/end; oldest-first pages use after/start.
	foreach ( array( 'before', 'end', 'after', 'start' ) as $key ) {
		if ( ! isset( $input[ $key ] ) ) {
			continue;
		}
		$timestamp = sanitize_text_field( wp_unslash( $input[ $key ] ) );
		if ( cloudflare_stream_is_stream_list_timestamp( $timestamp ) ) {
			$query[ $key ] = $timestamp;
		}
	}

	$exclude_uids = array();
	if ( isset( $input['exclude_uids'] ) ) {
		$raw_exclude = wp_unslash( $input['exclude_uids'] );
		if ( is_array( $raw_exclude ) ) {
			$candidates = $raw_exclude;
		} else {
			$candidates = preg_split( '/[\s,]+/', (string) $raw_exclude );
		}
		if ( is_array( $candidates ) ) {
			foreach ( $candidates as $candidate ) {
				$uid = strtolower( sanitize_text_field( (string) $candidate ) );
				if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $uid ) ) {
					$exclude_uids[] = $uid;
				}
			}
		}
		$exclude_uids = array_values( array_unique( $exclude_uids ) );
	}

	return array(
		'query'        => $query,
		'exclude_uids' => $exclude_uids,
	);
}

/**
 * Map a Cloudflare Stream video object to a media-frame attachment payload.
 *
 * @param object                $video        Cloudflare video result row.
 * @param Cloudflare_Stream_API $api          API instance.
 * @param string                $poster_time  Poster time suffix, e.g. "0s".
 * @param string                $delete_nonce Real delete nonce, or empty.
 * @return array|null Attachment array, or null when the row is unusable.
 */
function cloudflare_stream_attachment_from_video( $video, $api, $poster_time, $delete_nonce = '' ) {
	if ( ! is_object( $video ) || empty( $video->uid ) ) {
		return null;
	}

	$datetime = new DateTime( $video->created );

	// Signed playback needs a token in the path; a bare uid returns 401.
	// Budgeted so a full page of videos cannot stall admin-ajax when tokens
	// are minted over HTTP (no local signing key configured).
	$playback_id = $api->get_playback_id( $video->uid, true );

	// Rendered in the grid. Empty when a token could not be minted, so the
	// item degrades to the generic video icon instead of a broken image.
	$signed_thumb = ( false !== $playback_id )
		? $api->get_poster_url( $playback_id, $poster_time )
		: '';

	// Stored on the block when selected, so it must never contain a token:
	// block attributes are saved into post content and tokens expire.
	$unsigned_thumb = $api->get_poster_url( $video->uid, $poster_time );

	$title = isset( $video->meta->name ) && is_string( $video->meta->name ) && '' !== $video->meta->name
		? $video->meta->name
		: $video->uid;

	// Player page for this video; signed when the site requires it.
	$player_url = ( false !== $playback_id ) ? $api->get_iframe_url( $playback_id ) : '';

	$nonces = array();
	if ( is_string( $delete_nonce ) && '' !== $delete_nonce ) {
		$nonces['delete'] = $delete_nonce;
	}

	return array(
		'uid'                     => $video->uid,
		'id'                      => $video->uid,
		'title'                   => $title,
		'filename'                => $title,
		'url'                     => $player_url,
		'link'                    => $player_url,
		// Caption and description stay empty; the Stream details view omits them.
		'description'             => '',
		'caption'                 => '',
		'status'                  => 'inherit',
		'uploadedTo'              => 0,
		'date'                    => $video->created,
		'modified'                => $video->created,
		'menuOrder'               => 0,
		'mime'                    => 'video/mp4',
		'type'                    => 'video',
		'subtype'                 => 'mp4',
		'icon'                    => '' !== $signed_thumb ? $signed_thumb : wp_mime_type_icon( 'video' ),
		'dateFormatted'           => $datetime->format( 'F j, Y' ),
		'nonces'                  => $nonces,
		'filesizeInBytes'         => $video->size,
		'filesizeHumanReadable'   => size_format( $video->size ),
		'image'                   => array(
			'src'    => $signed_thumb,
			'width'  => 64,
			'height' => 48,
		),
		'fileLength'              => gmdate( 'H:i:s', round( $video->duration ) ),
		'fileLengthHumanReadable' => human_readable_duration( gmdate( 'H:i:s', round( $video->duration ) ) ),
		'thumb'                   => array(
			'src'    => $signed_thumb,
			'width'  => 64,
			'height' => 48,
		),
		// Token-free poster for block attributes (see $unsigned_thumb above).
		'unsignedThumb'           => $unsigned_thumb,
		'compat'                  => array(
			'item' => '',
			'meta' => '',
		),
		'cloudflare'              => $video,
	);
}

/**
 * AJAX method for retrieving a collection of Stream videos.
 *
 * @since 1.0.0
 */
function cloudflare_stream_ajax_query_attachments() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	cloudflare_stream_verify_ajax_capability( cloudflare_stream_ajax_edit_capability() );

	$api   = Cloudflare_Stream_API::instance();
	$built = cloudflare_stream_build_stream_list_query( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
	$query = $built['query'];
	$args  = array(
		'query' => $query,
	);

	$exclude = array_fill_keys( $built['exclude_uids'], true );
	$limit   = isset( $query['limit'] ) ? (int) $query['limit'] : (int) $api->api_limit;
	if ( $limit < 1 ) {
		$limit = (int) $api->api_limit;
	}

	$poster_time = cloudflare_stream_poster_time();
	$can_manage  = current_user_can( 'manage_options' );
	// Only a real delete nonce is sent, and only when the user may delete.
	$delete_nonce = $can_manage ? wp_create_nonce( Cloudflare_Stream_Settings::NONCE ) : '';

	$data       = array();
	$seen       = array();
	$max_hops   = 5;
	$hop        = 0;
	$data_count = 0;

	// Refill when exclude_uids thins a page so the client still receives a
	// full page until Cloudflare has no further rows.
	while ( $data_count < $limit && $hop < $max_hops ) {
		++$hop;
		$args['query'] = $query;
		$videos        = $api->get_videos( $args );

		if ( empty( $videos ) || ! is_object( $videos ) || empty( $videos->result ) || ! is_array( $videos->result ) ) {
			break;
		}

		$page_count     = 0;
		$oldest_created = null;

		foreach ( $videos->result as $video ) {
			++$page_count;

			if ( empty( $video->uid ) ) {
				continue;
			}

			$uid_key = strtolower( (string) $video->uid );
			if ( isset( $exclude[ $uid_key ] ) || isset( $seen[ $uid_key ] ) ) {
				if ( ! empty( $video->created ) ) {
					$oldest_created = $video->created;
				}
				continue;
			}

			$attachment = cloudflare_stream_attachment_from_video( $video, $api, $poster_time, $delete_nonce );
			if ( null === $attachment ) {
				continue;
			}

			$seen[ $uid_key ]    = true;
			$exclude[ $uid_key ] = true;
			$data[]              = $attachment;
			++$data_count;
			$oldest_created = $video->created;

			if ( $data_count >= $limit ) {
				break 2;
			}
		}

		// A short page means Cloudflare has no further rows for this query.
		if ( $page_count < $limit ) {
			break;
		}

		// Advance the created bound from the oldest row on this Cloudflare page.
		if ( ! is_string( $oldest_created ) || '' === $oldest_created ) {
			break;
		}
		if ( ! cloudflare_stream_is_stream_list_timestamp( $oldest_created ) ) {
			break;
		}

		$parsed = strtotime( $oldest_created );
		if ( false === $parsed ) {
			break;
		}

		// Inclusive upper bound for the next hop; seen/exclude drop duplicates.
		$query['before'] = gmdate( 'Y-m-d\TH:i:s\Z', $parsed + 1 );
		unset( $query['end'] );
	}//end while

	wp_send_json(
		array(
			'success' => true,
			'args'    => $args,
			'data'    => $data,
		),
		200
	);
}
add_action( 'wp_ajax_query-cloudflare-stream-attachments', 'cloudflare_stream_ajax_query_attachments' );

/**
 * AJAX method for checking the status of a video upload.
 *
 * @since 1.0.0
 */
function cloudflare_stream_ajax_check_upload() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	cloudflare_stream_verify_ajax_capability( 'manage_options' );

	$uid = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';

	$api  = Cloudflare_Stream_API::instance();
	$data = $api->get_video_details( $uid );

	if ( is_object( $data ) && ! empty( $data->success ) ) {
		// The editor stores this on the block, so it stays token-free. Rebuilt
		// with our own helper so it honours the configured media domain.
		if ( is_object( $data->result ) && ! empty( $data->result->thumbnail ) ) {
			$data->result->thumbnail = $api->get_poster_url( $uid, cloudflare_stream_poster_time() );
		}

		wp_send_json_success( $data->result );
	}

	$message = __( 'Could not load video details.', 'cloudflare-stream' );
	if ( is_object( $data ) && ! empty( $data->errors[0]->message ) ) {
		$code    = isset( $data->errors[0]->code ) ? $data->errors[0]->code . ' - ' : '';
		$message = sanitize_text_field( $code . $data->errors[0]->message );
	}
	wp_send_json_error( $message );
}
add_action( 'wp_ajax_cloudflare-stream-check-upload', 'cloudflare_stream_ajax_check_upload' );

/**
 * AJAX method for resolving editor preview URLs for a video.
 *
 * Returns a full iframe src built on the server for both signed and unsigned
 * playback so the editor and front end share one URL builder. When signed
 * playback is on the path carries a short-lived token. Only the playback token
 * is ever returned; the Cloudflare API token stays on the server.
 *
 * @since 1.1.7
 */
function cloudflare_stream_ajax_playback_urls() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	cloudflare_stream_verify_ajax_capability( cloudflare_stream_ajax_edit_capability() );

	$uid = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';

	$api         = Cloudflare_Stream_API::instance();
	$playback_id = $api->get_playback_id( $uid );

	if ( false === $playback_id ) {
		// Fail closed, exactly as the front end does: no unsigned fallback.
		wp_send_json_error(
			array(
				'message' => __( 'Could not create a playback preview for this video.', 'cloudflare-stream' ),
			)
		);
	}

	$args = array();
	foreach ( array( 'controls', 'autoplay', 'loop', 'muted', 'preload' ) as $flag ) {
		if ( ! array_key_exists( $flag, $_REQUEST ) ) {
			continue;
		}
		$raw           = sanitize_text_field( wp_unslash( $_REQUEST[ $flag ] ) );
		$args[ $flag ] = Cloudflare_Stream_API::normalize_bool( $raw );
	}

	if ( isset( $_REQUEST['posterurl'] ) && is_string( $_REQUEST['posterurl'] ) ) {
		$args['posterurl'] = esc_url_raw( wp_unslash( $_REQUEST['posterurl'] ) );
	}

	// Prefer the token-free thumbnail stored on the block when present.
	if ( empty( $args['posterurl'] ) && isset( $_REQUEST['thumbnail'] ) && is_string( $_REQUEST['thumbnail'] ) ) {
		$args['posterurl'] = esc_url_raw( wp_unslash( $_REQUEST['thumbnail'] ) );
	}

	$iframe_src = $api->build_iframe_src( $playback_id, $args );
	$poster_url = empty( $args['posterurl'] )
		? $api->get_poster_url( $playback_id, cloudflare_stream_poster_time() )
		: $api->sanitize_poster_url( $args['posterurl'], $playback_id, cloudflare_stream_poster_time() );

	wp_send_json_success(
		array(
			// Full src including query; preferred by the current editor preview.
			'iframeSrc' => $iframe_src,
			// Base URL kept for older callers that append their own query.
			'iframeUrl' => $api->get_iframe_url( $playback_id ),
			'posterUrl' => $poster_url,
		)
	);
}
add_action( 'wp_ajax_cloudflare-stream-playback-urls', 'cloudflare_stream_ajax_playback_urls' );

/**
 * AJAX method for initializing a video upload.
 *
 * Returns a one-time Cloudflare direct upload URL for the browser.
 * The API token stays on the server.
 *
 * @since 1.0.0
 */
function cloudflare_stream_ajax_query_upload() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	cloudflare_stream_verify_ajax_capability( 'manage_options' );

	$upload_length = isset( $_REQUEST['uploadLength'] )
		? absint( wp_unslash( $_REQUEST['uploadLength'] ) )
		: 0;

	$api = Cloudflare_Stream_API::instance();

	if ( $upload_length < 1 || $upload_length > $api->get_max_upload_bytes() ) {
		wp_send_json_error(
			array(
				'message' => __( 'Could not create upload URL.', 'cloudflare-stream' ),
			)
		);
	}

	$file_meta = array();
	if ( isset( $_REQUEST['name'] ) ) {
		$file_meta['name'] = sanitize_text_field( wp_unslash( $_REQUEST['name'] ) );
	}
	if ( isset( $_REQUEST['filetype'] ) ) {
		$file_meta['filetype'] = sanitize_text_field( wp_unslash( $_REQUEST['filetype'] ) );
	}

	if ( ! empty( $file_meta['filetype'] ) ) {
		$filetype = strtolower( trim( $file_meta['filetype'] ) );
		if ( ! in_array( $filetype, $api->get_allowed_upload_mime_types(), true ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'That file type is not supported for Stream upload.', 'cloudflare-stream' ),
				)
			);
		}
	}

	$data = $api->create_direct_upload( $upload_length, $file_meta );

	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Cloudflare uploadURL field.
	if ( empty( $data ) || ! is_object( $data ) || empty( $data->uploadURL ) || empty( $data->uid ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Could not create upload URL.', 'cloudflare-stream' ),
			)
		);
	}

	wp_send_json_success(
		array(
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Cloudflare uploadURL field.
			'uploadURL' => esc_url_raw( $data->uploadURL ),
			'uid'       => sanitize_text_field( $data->uid ),
		)
	);
}
add_action( 'wp_ajax_query-cloudflare-stream-upload', 'cloudflare_stream_ajax_query_upload' );

/**
 * AJAX method for deleting a video.
 *
 * @since 1.0.0
 */
function cloudflare_stream_ajax_delete() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	cloudflare_stream_verify_ajax_capability( 'manage_options' );

	$uid  = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';
	$api  = Cloudflare_Stream_API::instance();
	$data = $api->delete_video( $uid );

	if ( ! is_object( $data ) || empty( $data->success ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not delete video.', 'cloudflare-stream' ) ) );
	}

	wp_send_json_success( $data );
}
add_action( 'wp_ajax_cloudflare-stream-delete', 'cloudflare_stream_ajax_delete' );

/**
 * AJAX method for updating a video.
 *
 * @since 1.0.0
 */
function cloudflare_stream_ajax_update() {
	check_ajax_referer( Cloudflare_Stream_Settings::NONCE, 'nonce' );
	cloudflare_stream_verify_ajax_capability( 'manage_options' );

	$uid    = isset( $_REQUEST['uid'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['uid'] ) ) : '';
	$title  = isset( $_REQUEST['title'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['title'] ) ) : '';
	$upload = isset( $_REQUEST['upload'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['upload'] ) ) : '';

	if ( '' === $title ) {
		wp_send_json_error(
			array(
				'message' => __( 'Title cannot be empty.', 'cloudflare-stream' ),
			)
		);
	}

	// Always pass upload through so a rename does not clear upload metadata.
	$args = array(
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
add_action( 'wp_ajax_cloudflare-stream-update', 'cloudflare_stream_ajax_update' );
