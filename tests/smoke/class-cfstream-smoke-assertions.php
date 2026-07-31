<?php
/**
 * Shared smoke assertions for PHPUnit and wp eval-file (no PHPUnit dependency).
 *
 * @package cloudflare-stream
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Stream smoke assertion runner.
 */
class CFStream_Smoke_Assertions {

	const PROFILE_FULL      = 'full';
	const PROFILE_BOOTSTRAP = 'bootstrap';

	const AJAX_ACTIONS = array(
		'query-cloudflare-stream-attachments',
		'cloudflare-stream-check-upload',
		'query-cloudflare-stream-upload',
		'cloudflare-stream-delete',
		'cloudflare-stream-update',
	);

	const BLOCK_NAME     = 'cloudflare-stream/block-video';
	const BLOCK_CALLBACK = 'cloudflare_stream_render_block';
	const SETTING_GROUP  = 'cloudflare_stream';
	const SETTINGS_SLUG  = 'cloudflare-stream';

	/**
	 * Profile name.
	 *
	 * @var string
	 */
	private $profile;

	/**
	 * Plugin root directory.
	 *
	 * @var string
	 */
	private $plugin_root;

	/**
	 * Collected failure messages.
	 *
	 * @var string[]
	 */
	private $failures = array();

	/**
	 * @param string      $profile     full|bootstrap.
	 * @param string|null $plugin_root Absolute plugin root; default parent of tests/.
	 */
	public function __construct( $profile = self::PROFILE_FULL, $plugin_root = null ) {
		$this->profile = ( self::PROFILE_BOOTSTRAP === $profile ) ? self::PROFILE_BOOTSTRAP : self::PROFILE_FULL;
		$this->plugin_root = $plugin_root ? rtrim( $plugin_root, '/\\' ) : dirname( __DIR__, 2 );
	}

	/**
	 * Run all assertions for the active profile.
	 *
	 * @return string[] Failure messages (empty on success).
	 */
	public function run() {
		$this->failures = array();

		$this->s1_plugin_loaded();
		$this->s2_classes_exist();
		$this->s3_shortcode_registered();
		$this->s4_block_registered();
		$this->s5_render_without_uid();
		$this->s6_default_options();
		$this->s7_settings_submenu();
		$this->s8_settings_registered();
		$this->s9_ajax_actions_registered();

		if ( self::PROFILE_FULL === $this->profile ) {
			$this->s10_ajax_capabilities();
		}

		$this->s11_site_health_test();
		$this->s12_admin_post_actions();
		$this->s13_dist_assets();
		$this->s14_settings_page_renders();
		$this->s15_no_outbound_http();
		$this->s16_openssl_available();
		$this->s17_no_wp_editor_usage();

		return $this->failures;
	}

	/**
	 * Run and throw on any failure.
	 *
	 * @throws RuntimeException When one or more assertions fail.
	 */
	public function assert_all() {
		$failures = $this->run();
		if ( $failures ) {
			throw new RuntimeException( "Smoke assertions failed:\n - " . implode( "\n - ", $failures ) );
		}
	}

	/**
	 * @return string[]
	 */
	public function get_failures() {
		return $this->failures;
	}

	/**
	 * @param string $message Failure text.
	 */
	private function fail( $message ) {
		$this->failures[] = $message;
	}

	/**
	 * Plugin bootstrap surface is loaded.
	 */
	public function s1_plugin_loaded() {
		if ( ! defined( 'ABSPATH' ) ) {
			$this->fail( 'S1: ABSPATH is not defined' );
			return;
		}
		$main = $this->plugin_root . '/cloudflare-stream.php';
		if ( ! is_readable( $main ) ) {
			$this->fail( 'S1: main plugin file missing' );
		}
		if ( ! function_exists( 'cloudflare_stream_render_block' ) ) {
			$this->fail( 'S1: cloudflare_stream_render_block() not loaded' );
		}
	}

	/**
	 * Core plugin classes exist.
	 */
	public function s2_classes_exist() {
		$classes = array(
			'Cloudflare_Stream_Settings',
			'Cloudflare_Stream_API',
			'Cloudflare_Stream_Shortcode',
			'Cloudflare_Stream_Signing_Health',
		);
		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				$this->fail( "S2: class {$class} does not exist" );
			}
		}
	}

	/**
	 * Shortcode is registered.
	 */
	public function s3_shortcode_registered() {
		if ( ! shortcode_exists( 'cloudflare_stream' ) ) {
			$this->fail( 'S3: shortcode cloudflare_stream is not registered' );
		}
	}

	/**
	 * Block is registered with the expected render callback.
	 */
	public function s4_block_registered() {
		if ( ! function_exists( 'WP_Block_Type_Registry' ) && ! class_exists( 'WP_Block_Type_Registry' ) ) {
			$this->fail( 'S4: WP_Block_Type_Registry unavailable' );
			return;
		}

		if ( ! did_action( 'init' ) ) {
			do_action( 'init' );
		}

		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( self::BLOCK_NAME ) ) {
			$this->fail( 'S4: block ' . self::BLOCK_NAME . ' is not registered' );
			return;
		}

		$block = $registry->get_registered( self::BLOCK_NAME );
		$cb    = isset( $block->render_callback ) ? $block->render_callback : null;
		$ok    = false;
		if ( is_string( $cb ) && self::BLOCK_CALLBACK === $cb ) {
			$ok = true;
		} elseif ( is_array( $cb ) && isset( $cb[1] ) && self::BLOCK_CALLBACK === $cb[1] ) {
			$ok = true;
		} elseif ( is_callable( $cb ) && is_string( $cb ) === false ) {
			$ok = ( self::BLOCK_CALLBACK === $cb );
		}
		if ( ! $ok && is_callable( self::BLOCK_CALLBACK ) && $cb === self::BLOCK_CALLBACK ) {
			$ok = true;
		}
		if ( ! $ok && $cb !== self::BLOCK_CALLBACK ) {
			$label = is_string( $cb ) ? $cb : ( is_array( $cb ) ? wp_json_encode( $cb ) : gettype( $cb ) );
			$this->fail( 'S4: render_callback is ' . $label . ', expected ' . self::BLOCK_CALLBACK );
		}
	}

	/**
	 * Render returns content when uid is absent.
	 */
	public function s5_render_without_uid() {
		if ( ! function_exists( 'cloudflare_stream_render_block' ) ) {
			$this->fail( 'S5: cloudflare_stream_render_block() missing' );
			return;
		}
		$out = cloudflare_stream_render_block( array(), 'x' );
		if ( 'x' !== $out ) {
			$this->fail( 'S5: expected passthrough content "x", got ' . var_export( $out, true ) );
		}
	}

	/**
	 * Default options after admin_menu as an administrator.
	 */
	public function s6_default_options() {
		if ( ! class_exists( 'Cloudflare_Stream_Settings' ) ) {
			$this->fail( 'S6: settings class missing' );
			return;
		}

		$this->with_admin_user(
			function () {
				do_action( 'admin_menu' );

				$signed = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, null );
				if ( true !== $signed && 1 !== $signed && '1' !== $signed ) {
					$this->fail( 'S6: signed_urls default is not true' );
				}

				$duration = get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS_DURATION, null );
				if ( 60 !== (int) $duration ) {
					$this->fail( 'S6: signed_urls_duration default is not 60' );
				}

				$domain = get_option( Cloudflare_Stream_Settings::OPTION_MEDIA_DOMAIN, null );
				if ( 'cloudflarestream.com' !== $domain ) {
					$this->fail( 'S6: media_domain default is not cloudflarestream.com' );
				}

				$poster = get_option( Cloudflare_Stream_Settings::OPTION_POSTER_TIME, null );
				if ( 0 !== (int) $poster ) {
					$this->fail( 'S6: poster_time default is not 0' );
				}
			}
		);
	}

	/**
	 * Settings page registered under options-general.php.
	 */
	public function s7_settings_submenu() {
		$this->with_admin_user(
			function () {
				global $submenu;
				do_action( 'admin_menu' );

				$found = false;
				if ( ! empty( $submenu['options-general.php'] ) && is_array( $submenu['options-general.php'] ) ) {
					foreach ( $submenu['options-general.php'] as $item ) {
						if ( isset( $item[2] ) && self::SETTINGS_SLUG === $item[2] ) {
							$found = true;
							break;
						}
					}
				}

				if ( ! $found && function_exists( 'get_plugin_page_hookname' ) ) {
					$hook = get_plugin_page_hookname( self::SETTINGS_SLUG, 'options-general.php' );
					if ( ! empty( $hook ) ) {
						$found = true;
					}
				}

				if ( ! $found ) {
					$this->fail( 'S7: settings submenu cloudflare-stream not found under options-general.php' );
				}
			}
		);
	}

	/**
	 * Exactly six settings in group cloudflare_stream after admin_init.
	 */
	public function s8_settings_registered() {
		$this->with_admin_user(
			function () {
				do_action( 'admin_init' );

				global $new_allowed_options, $whitelist_options;
				$group_opts = array();

				if ( isset( $new_allowed_options[ self::SETTING_GROUP ] ) && is_array( $new_allowed_options[ self::SETTING_GROUP ] ) ) {
					$group_opts = $new_allowed_options[ self::SETTING_GROUP ];
				} elseif ( isset( $whitelist_options[ self::SETTING_GROUP ] ) && is_array( $whitelist_options[ self::SETTING_GROUP ] ) ) {
					$group_opts = $whitelist_options[ self::SETTING_GROUP ];
				}

				$count = count( $group_opts );
				if ( 6 !== $count ) {
					$this->fail( 'S8: expected 6 settings in group ' . self::SETTING_GROUP . ", found {$count}" );
				}
			}
		);
	}

	/**
	 * All five AJAX actions have handlers.
	 */
	public function s9_ajax_actions_registered() {
		foreach ( self::AJAX_ACTIONS as $action ) {
			if ( ! has_action( 'wp_ajax_' . $action ) ) {
				$this->fail( "S9: missing wp_ajax_{$action} handler" );
			}
		}
	}

	/**
	 * Each AJAX handler rejects subscribers even with a valid nonce (full profile).
	 * Best-effort outside WP_Ajax_UnitTestCase.
	 */
	public function s10_ajax_capabilities() {
		if ( ! function_exists( 'wp_set_current_user' ) || ! function_exists( 'wp_create_nonce' ) ) {
			$this->fail( 'S10: user/nonce APIs unavailable' );
			return;
		}

		if ( ! class_exists( 'Cloudflare_Stream_Settings' ) ) {
			$this->fail( 'S10: settings class missing' );
			return;
		}

		if ( ! function_exists( 'cloudflare_stream_verify_ajax_capability' ) ) {
			$this->fail( 'S10: cloudflare_stream_verify_ajax_capability() missing' );
			return;
		}

		$subscriber_id = $this->ensure_user( 'subscriber' );
		if ( ! $subscriber_id ) {
			$this->fail( 'S10: could not create subscriber user' );
			return;
		}

		$previous = get_current_user_id();
		wp_set_current_user( $subscriber_id );

		foreach ( self::AJAX_ACTIONS as $action ) {
			$_REQUEST['nonce'] = wp_create_nonce( Cloudflare_Stream_Settings::NONCE );
			$_GET['nonce']     = $_REQUEST['nonce'];
			$_POST['nonce']    = $_REQUEST['nonce'];

			$status = $this->invoke_ajax_collect_status( $action );
			if ( 403 !== (int) $status ) {
				$this->fail( "S10: {$action} expected HTTP 403 for subscriber, got " . var_export( $status, true ) );
			}
		}

		wp_set_current_user( $previous );
		unset( $_REQUEST['nonce'], $_GET['nonce'], $_POST['nonce'] );
	}

	/**
	 * Site Health registers the signing test.
	 */
	public function s11_site_health_test() {
		$tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
		if ( empty( $tests['direct']['cloudflare_stream_signing'] ) ) {
			$this->fail( 'S11: site_status_tests missing cloudflare_stream_signing' );
		}
	}

	/**
	 * admin_post actions for signing key and dismiss.
	 */
	public function s12_admin_post_actions() {
		if ( ! has_action( 'admin_post_cloudflare_stream_signing_key' ) ) {
			$this->fail( 'S12: admin_post_cloudflare_stream_signing_key not registered' );
		}
		if ( ! has_action( 'admin_post_cloudflare_stream_dismiss_signing_health' ) ) {
			$this->fail( 'S12: admin_post_cloudflare_stream_dismiss_signing_health not registered' );
		}
	}

	/**
	 * Built dist assets exist and are non-empty.
	 */
	public function s13_dist_assets() {
		$files = array(
			'dist/blocks.build.js',
			'dist/blocks.css',
			'dist/style-blocks.css',
		);
		foreach ( $files as $rel ) {
			$path = $this->plugin_root . '/' . $rel;
			if ( ! is_readable( $path ) ) {
				$this->fail( "S13: missing {$rel}" );
				continue;
			}
			if ( filesize( $path ) <= 0 ) {
				$this->fail( "S13: empty {$rel}" );
			}
		}
	}

	/**
	 * Settings page renders for an administrator.
	 */
	public function s14_settings_page_renders() {
		if ( ! class_exists( 'Cloudflare_Stream_Settings' ) ) {
			$this->fail( 'S14: settings class missing' );
			return;
		}

		$this->with_admin_user(
			function () {
				do_action( 'admin_menu' );
				do_action( 'admin_init' );

				$had_error = false;
				set_error_handler(
					function () use ( &$had_error ) {
						$had_error = true;
						return true;
					}
				);

				ob_start();
				try {
					Cloudflare_Stream_Settings::instance()->settings_page();
					$out = ob_get_clean();
				} catch ( Throwable $e ) {
					ob_end_clean();
					restore_error_handler();
					$this->fail( 'S14: settings_page threw ' . $e->getMessage() );
					return;
				}
				restore_error_handler();

				if ( $had_error ) {
					$this->fail( 'S14: settings_page emitted a PHP error' );
				}
				if ( ! is_string( $out ) || '' === trim( $out ) ) {
					$this->fail( 'S14: settings_page produced no output' );
				} elseif ( false === stripos( $out, 'Cloudflare Stream' ) ) {
					$this->fail( 'S14: settings_page output missing expected heading text' );
				}
			}
		);
	}

	/**
	 * No outbound HTTP recorded.
	 */
	public function s15_no_outbound_http() {
		if ( ! function_exists( 'cfstream_test_get_http_attempts' ) ) {
			$this->fail( 'S15: cfstream_test_get_http_attempts() unavailable (mu-plugin not loaded)' );
			return;
		}
		$attempts = cfstream_test_get_http_attempts();
		if ( ! empty( $attempts ) ) {
			$this->fail( 'S15: outbound HTTP attempted: ' . implode( ', ', $attempts ) );
		}
	}

	/**
	 * openssl_sign is available.
	 */
	public function s16_openssl_available() {
		if ( ! function_exists( 'openssl_sign' ) ) {
			$this->fail( 'S16: openssl_sign() is not available' );
		}
	}

	/**
	 * No deprecated wp.editor usage under src/.
	 */
	public function s17_no_wp_editor_usage() {
		$src = $this->plugin_root . '/src';
		if ( ! is_dir( $src ) ) {
			$this->fail( 'S17: src/ directory missing' );
			return;
		}

		$hits = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			/** @var SplFileInfo $file */
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, array( 'js', 'jsx', 'ts', 'tsx' ), true ) ) {
				continue;
			}
			$contents = file_get_contents( $file->getPathname() );
			if ( false === $contents ) {
				continue;
			}
			if ( false !== strpos( $contents, 'wp.editor' ) ) {
				$hits[] = str_replace( $this->plugin_root . '/', '', $file->getPathname() );
			}
		}
		if ( $hits ) {
			$this->fail( 'S17: wp.editor reference found in ' . implode( ', ', $hits ) );
		}
	}

	/**
	 * Run a callback as an administrator.
	 *
	 * @param callable $cb Callback.
	 */
	private function with_admin_user( $cb ) {
		$previous = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$admin_id = $this->ensure_user( 'administrator' );
		if ( ! $admin_id ) {
			$this->fail( 'Could not create administrator user for smoke checks' );
			return;
		}
		wp_set_current_user( $admin_id );
		try {
			$cb();
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Ensure a user with the given role exists and return its ID.
	 *
	 * @param string $role Role slug.
	 * @return int User ID or 0.
	 */
	private function ensure_user( $role ) {
		$users = get_users(
			array(
				'role'   => $role,
				'number' => 1,
				'fields' => 'ID',
			)
		);
		if ( ! empty( $users[0] ) ) {
			return (int) $users[0];
		}

		if ( ! function_exists( 'wp_insert_user' ) ) {
			return 0;
		}

		$login = 'cfstream_' . $role . '_smoke';
		$existing = get_user_by( 'login', $login );
		if ( $existing ) {
			$user = new WP_User( $existing->ID );
			$user->set_role( $role );
			return (int) $existing->ID;
		}

		$id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 16, true, true ),
				'user_email' => $login . '@example.com',
				'role'       => $role,
			)
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		return (int) $id;
	}

	/**
	 * Invoke an AJAX action and return the HTTP status from wp_die / send_json.
	 *
	 * @param string $action Action slug without wp_ajax_ prefix.
	 * @return int|string Status code or error token.
	 */
	private function invoke_ajax_collect_status( $action ) {
		$status = 0;

		$die_handler = function ( $message, $title, $args ) use ( &$status ) {
			unset( $message, $title );
			if ( isset( $args['response'] ) ) {
				$status = (int) $args['response'];
			} else {
				$status = 500;
			}
			throw new CFStream_Smoke_Ajax_Exit( (string) $status );
		};

		add_filter(
			'wp_die_handler',
			function () use ( $die_handler ) {
				return $die_handler;
			},
			99999
		);

		try {
			do_action( 'wp_ajax_' . $action );
			if ( 0 === $status ) {
				$status = 200;
			}
		} catch ( CFStream_Smoke_Ajax_Exit $e ) {
			$status = (int) $e->getMessage();
		} catch ( WPDieException $e ) {
			$code = (int) $e->getCode();
			$status = $code > 0 ? $code : 403;
		} catch ( Exception $e ) {
			$msg = $e->getMessage();
			if ( is_numeric( $msg ) ) {
				$status = (int) $msg;
			} elseif ( false !== stripos( $msg, '403' ) || false !== stripos( $msg, 'Forbidden' ) ) {
				$status = 403;
			} else {
				$status = 'error:' . $msg;
			}
		} catch ( Throwable $e ) {
			$msg = $e->getMessage();
			if ( is_numeric( $msg ) ) {
				$status = (int) $msg;
			} else {
				$status = 'error:' . $msg;
			}
		}

		remove_all_filters( 'wp_die_handler', 99999 );

		return $status;
	}
}

/**
 * Internal exit signal for AJAX smoke invocations.
 */
class CFStream_Smoke_Ajax_Exit extends Exception {}
