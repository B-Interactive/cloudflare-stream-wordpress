<?php
/**
 * Cloudflare Stream signing health / degradation diagnostics.
 *
 * Tracks local JWT mint failures, API token fallback, circuit breakers,
 * durable breadcrumbs for Settings + Site Health, and editor-only comments.
 *
 * @package cloudflare-stream
 * @since   1.1.5
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare_Stream_Signing_Health
 */
class Cloudflare_Stream_Signing_Health {

	/**
	 * Singleton instance.
	 *
	 * @var self|false
	 */
	private static $instance = false;

	/**
	 * In-request state cache.
	 *
	 * @var array|null
	 */
	private $state_cache = null;

	/**
	 * Whether the option was written this request.
	 *
	 * @var bool
	 */
	private $wrote_this_request = false;

	/**
	 * Whether the legacy hot transient was scrubbed this request.
	 *
	 * @var bool
	 */
	private static $legacy_transient_scrubbed = false;

	const OPTION_HEALTH         = 'cloudflare_stream_signing_health';
	const ADMIN_ACTION_DISMISS  = 'cloudflare_stream_dismiss_signing_health';
	const USER_META_DISMISS_KEY = 'cfstream_sign_health_dismiss';
	const LOCAL_BREAKER_TTL     = 900;
	const API_BREAKER_TTL       = 300;
	const API_BREAKER_THRESHOLD = 3;
	const NEGATIVE_CACHE_TTL    = 60;
	const RECOVERY_INFO_WINDOW  = 86400;
	const WRITE_THROTTLE        = 300;

	/**
	 * Known reason codes (stable identifiers).
	 *
	 * @var string[]
	 */
	const REASON_CODES = array(
		'local_openssl_missing',
		'local_key_missing_at_sign',
		'local_key_unreadable',
		'local_exp_invalid',
		'local_json_encode',
		'local_openssl_sign',
		'local_exception',
		'local_unknown',
		'api_credentials_missing',
		'api_token_unreadable',
		'api_http_error',
		'api_transport_error',
		'api_rate_limited',
		'api_token_failed',
		'api_breaker_open',
	);

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}

	/**
	 * Prevent external construction.
	 *
	 * @return void
	 */
	private function __construct() { }

	/**
	 * Prevent cloning.
	 *
	 * @return void
	 */
	private function __clone() { }

	/**
	 * Register hooks for Site Health and dismiss handling.
	 *
	 * @return void
	 */
	private function setup() {
		add_filter( 'site_status_tests', array( $this, 'register_site_health_test' ) );
		add_action( 'admin_post_' . self::ADMIN_ACTION_DISMISS, array( $this, 'handle_dismiss_notice' ) );
	}

	/**
	 * Default durable health state payload.
	 *
	 * @return array
	 */
	private function default_state() {
		return array(
			'state'             => 'healthy',
			'reason'            => '',
			'api_reason'        => '',
			'uid_hash'          => '',
			'last_seen'         => 0,
			'local_failures'    => 0,
			'breaker_until'     => 0,
			'api_breaker_until' => 0,
			'api_fail_count'    => 0,
			'api_fail_start'    => 0,
			'recovered_at'      => 0,
			'logged_reason'     => '',
			'logged_at'         => 0,
			'saved_at'          => 0,
		);
	}

	/**
	 * Normalise a raw option value into a complete state array.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array
	 */
	private function normalize_state( $raw ) {
		$state = $this->default_state();
		if ( ! is_array( $raw ) ) {
			return $state;
		}

		foreach ( $state as $key => $default ) {
			if ( ! array_key_exists( $key, $raw ) ) {
				continue;
			}
			$state[ $key ] = is_int( $default )
				? intval( $raw[ $key ] )
				: ( is_string( $raw[ $key ] ) ? $raw[ $key ] : (string) $raw[ $key ] );
		}

		if ( ! in_array( $state['state'], array( 'healthy', 'degraded_api_fallback', 'total_failure' ), true ) ) {
			$state['state'] = 'healthy';
		}
		if ( '' !== $state['reason'] ) {
			$state['reason'] = $this->sanitize_reason( $state['reason'], 'local' );
		}
		if ( '' !== $state['api_reason'] ) {
			$state['api_reason'] = $this->sanitize_reason( $state['api_reason'], 'api' );
		}
		$state['uid_hash'] = substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $state['uid_hash'] ) ), 0, 12 );

		return $state;
	}

	/**
	 * HMAC short hash of a video UID (never store raw uid).
	 *
	 * @param string $uid Video UID.
	 * @return string
	 */
	public function uid_hash( $uid ) {
		$uid = is_string( $uid ) ? strtolower( $uid ) : '';
		return '' === $uid ? '' : substr( hash_hmac( 'sha256', $uid, wp_salt( 'nonce' ) ), 0, 12 );
	}

	/**
	 * Delete the legacy hot transient once per request.
	 *
	 * @return void
	 */
	private function maybe_scrub_legacy_transient() {
		if ( self::$legacy_transient_scrubbed ) {
			return;
		}
		self::$legacy_transient_scrubbed = true;
		delete_transient( 'cfstream_sign_state' );
	}

	/**
	 * Read health state (single option, request-cached).
	 *
	 * @return array
	 */
	public function get_state() {
		if ( null !== $this->state_cache ) {
			return $this->state_cache;
		}
		$this->maybe_scrub_legacy_transient();
		$this->state_cache = $this->normalize_state( get_option( self::OPTION_HEALTH, null ) );
		return $this->state_cache;
	}

	/**
	 * Persist state (throttled) and refresh request cache.
	 *
	 * @param array $state State payload.
	 * @param bool  $force Force write ignoring throttle.
	 * @return void
	 */
	private function save( array $state, $force = false ) {
		$state             = $this->normalize_state( $state );
		$this->state_cache = $state;

		$previous = $this->normalize_state( get_option( self::OPTION_HEALTH, null ) );
		$now      = time();
		// Material signature: state/reasons plus breakers/counters that must
		// survive WRITE_THROTTLE and same-request follow-up saves.
		$changed = ( $previous['state'] !== $state['state'] )
			|| ( $previous['reason'] !== $state['reason'] )
			|| ( $previous['api_reason'] !== $state['api_reason'] )
			|| ( intval( $previous['breaker_until'] ) !== intval( $state['breaker_until'] ) )
			|| ( intval( $previous['api_breaker_until'] ) !== intval( $state['api_breaker_until'] ) )
			|| ( intval( $previous['api_fail_count'] ) !== intval( $state['api_fail_count'] ) )
			|| ( intval( $previous['api_fail_start'] ) !== intval( $state['api_fail_start'] ) )
			|| ( intval( $previous['local_failures'] ) !== intval( $state['local_failures'] ) );
		$stale   = ( 0 === intval( $previous['saved_at'] ) )
			|| ( ( $now - intval( $previous['saved_at'] ) ) >= self::WRITE_THROTTLE );

		// $force and material changes always persist. Throttle and the
		// per-request guard only collapse writes when nothing material changed.
		if ( ! $force && ! $changed ) {
			if ( $this->wrote_this_request || ! $stale ) {
				return;
			}
		}

		$state['saved_at'] = $now;
		$this->state_cache = $state;
		update_option( self::OPTION_HEALTH, $state, false );
		$this->wrote_this_request = true;
	}

	/**
	 * Whether the local signing circuit breaker is open.
	 *
	 * @param array|null $state Optional state snapshot.
	 * @return bool
	 */
	public function is_local_breaker_open( $state = null ) {
		$state = null === $state ? $this->get_state() : $state;
		return intval( $state['breaker_until'] ) > time();
	}

	/**
	 * Whether the API token circuit breaker is open.
	 *
	 * @param array|null $state Optional state snapshot.
	 * @return bool
	 */
	public function is_api_breaker_open( $state = null ) {
		$state = null === $state ? $this->get_state() : $state;
		return intval( $state['api_breaker_until'] ) > time();
	}

	/**
	 * Zero breakers and API fail counters; keep durable breadcrumb.
	 *
	 * @return void
	 */
	public function clear_breakers() {
		$this->maybe_scrub_legacy_transient();
		$this->state_cache        = null;
		$this->wrote_this_request = false;

		$durable = get_option( self::OPTION_HEALTH, null );
		if ( ! is_array( $durable ) ) {
			return;
		}

		$durable                      = $this->normalize_state( $durable );
		$durable['breaker_until']     = 0;
		$durable['api_breaker_until'] = 0;
		$durable['api_fail_count']    = 0;
		$durable['api_fail_start']    = 0;
		$durable['saved_at']          = time();
		update_option( self::OPTION_HEALTH, $durable, false );
		$this->state_cache        = $durable;
		$this->wrote_this_request = true;
	}

	/**
	 * Alias for settings hooks / older call sites.
	 *
	 * @return void
	 */
	public function clear_hot_state() {
		$this->clear_breakers();
	}

	/**
	 * Record successful local sign (clears degradation). Happy path: zero writes.
	 *
	 * @return void
	 */
	public function record_local_success() {
		$state     = $this->get_state();
		$was_bad   = in_array( $state['state'], array( 'degraded_api_fallback', 'total_failure' ), true );
		$had_break = $this->is_local_breaker_open( $state ) || $this->is_api_breaker_open( $state );

		if ( ! $was_bad && ! $had_break && 'healthy' === $state['state'] && 0 === intval( $state['local_failures'] ) ) {
			return;
		}

		$now = time();
		if ( $was_bad ) {
			$this->maybe_log( $state, 'recovered', 'Cloudflare Stream: local signed token minting recovered.' );
		}

		$state['state']             = 'healthy';
		$state['reason']            = '';
		$state['api_reason']        = '';
		$state['breaker_until']     = 0;
		$state['api_breaker_until'] = 0;
		$state['api_fail_count']    = 0;
		$state['api_fail_start']    = 0;
		$state['last_seen']         = $now;
		if ( $was_bad ) {
			$state['recovered_at'] = $now;
		}
		$this->save( $state, $was_bad );
	}

	/**
	 * Record local signing failure and open local breaker (before API attempt).
	 *
	 * @param string $uid    Video UID.
	 * @param string $reason Local reason code.
	 * @return void
	 */
	public function record_local_failure( $uid, $reason ) {
		$state                   = $this->get_state();
		$now                     = time();
		$state['last_seen']      = $now;
		$state['reason']         = $this->sanitize_reason( $reason, 'local' );
		$state['uid_hash']       = $this->uid_hash( $uid );
		$state['local_failures'] = intval( $state['local_failures'] ) + 1;
		$state['breaker_until']  = $now + self::LOCAL_BREAKER_TTL;
		$this->save( $state, true );
	}

	/**
	 * Record outcome after local failure + API attempt.
	 * Empty $api_reason => degraded fallback success; non-empty => total failure.
	 *
	 * @param string $uid          Video UID.
	 * @param string $local_reason Local reason that triggered fallback.
	 * @param string $api_reason   API reason code, or '' on successful fallback.
	 * @return void
	 */
	public function record_outcome( $uid, $local_reason, $api_reason = '' ) {
		$state              = $this->get_state();
		$now                = time();
		$local_reason       = $this->sanitize_reason( $local_reason, 'local' );
		$state['reason']    = $local_reason ? $local_reason : ( $state['reason'] ? $state['reason'] : 'local_unknown' );
		$state['uid_hash']  = $this->uid_hash( $uid );
		$state['last_seen'] = $now;

		if ( '' === $api_reason ) {
			$state['state']             = 'degraded_api_fallback';
			$state['api_reason']        = '';
			$state['api_breaker_until'] = 0;
			$state['api_fail_count']    = 0;
			$state['api_fail_start']    = 0;
			$this->maybe_log(
				$state,
				'local_fail_fallback:' . $state['reason'],
				sprintf(
					'Cloudflare Stream: local signed token minting failed (reason: %s); falling back to the Cloudflare API for signed playback.',
					$state['reason']
				)
			);
			// Force so degraded breadcrumb wins over an earlier same-request write
			// (e.g. record_local_failure) and is not lost to WRITE_THROTTLE.
			$this->save( $state, true );
			return;
		}

		$state['state']      = 'total_failure';
		$state['api_reason'] = $this->sanitize_reason( $api_reason, 'api' );
		$this->maybe_log(
			$state,
			'total_failure:' . $state['reason'] . ':' . $state['api_reason'],
			sprintf(
				'Cloudflare Stream: signed token minting failed locally and via the API (local: %s, api: %s); embed suppressed.',
				$state['reason'],
				$state['api_reason']
			)
		);
		$this->save( $state, true );
	}

	/**
	 * API mint failure for breaker accounting (mint helper).
	 *
	 * @param string $api_reason Reason code.
	 * @return void
	 */
	public function record_api_failure_only( $api_reason ) {
		$api_reason = $this->sanitize_reason( $api_reason, 'api' );
		if ( 'api_breaker_open' === $api_reason ) {
			return;
		}

		$state        = $this->get_state();
		$now          = time();
		$window_start = intval( $state['api_fail_start'] );

		if ( 0 === $window_start || ( $now - $window_start ) > self::API_BREAKER_TTL ) {
			$state['api_fail_start'] = $now;
			$state['api_fail_count'] = 1;
		} else {
			$state['api_fail_count'] = intval( $state['api_fail_count'] ) + 1;
		}

		if ( intval( $state['api_fail_count'] ) >= self::API_BREAKER_THRESHOLD ) {
			$state['api_breaker_until'] = $now + self::API_BREAKER_TTL;
		}

		$state['api_reason'] = $api_reason;
		$state['last_seen']  = $now;
		$this->save( $state );
	}

	/**
	 * Log an operational message when the throttle window allows.
	 *
	 * @param array  $state   State (by ref for logged_* updates).
	 * @param string $log_key Stable log key for throttle.
	 * @param string $message Log message (no secrets).
	 * @return void
	 */
	private function maybe_log( array &$state, $log_key, $message ) {
		$now = time();
		if (
			isset( $state['logged_reason'] )
			&& $state['logged_reason'] === $log_key
			&& ( $now - intval( $state['logged_at'] ) ) < self::WRITE_THROTTLE
		) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional operational logging.
		error_log( $message );
		$state['logged_reason'] = $log_key;
		$state['logged_at']     = $now;
	}

	/**
	 * Restrict a reason code to the known set for its family.
	 *
	 * @param string $code Candidate.
	 * @param string $kind 'local' or 'api' fallback family.
	 * @return string
	 */
	private function sanitize_reason( $code, $kind = 'local' ) {
		$code = is_string( $code ) ? $code : '';
		if ( in_array( $code, self::REASON_CODES, true ) ) {
			return $code;
		}
		return ( 'api' === $kind ) ? 'api_token_failed' : 'local_unknown';
	}

	/**
	 * Label + tip for a reason code.
	 *
	 * @param string $code Reason code.
	 * @return array{label:string,tip:string}
	 */
	private function reason_meta( $code ) {
		static $map = null;
		if ( null === $map ) {
			$map = array(
				'local_openssl_missing'     => array(
					'label' => __( 'OpenSSL signing is not available on this server', 'cloudflare-stream' ),
					'tip'   => __( 'Enable the PHP OpenSSL extension so the plugin can sign playback tokens locally.', 'cloudflare-stream' ),
				),
				'local_key_missing_at_sign' => array(
					'label' => __( 'Signing key was missing at sign time', 'cloudflare-stream' ),
					'tip'   => __( 'Re-save or regenerate the signing key on the Cloudflare Stream settings page.', 'cloudflare-stream' ),
				),
				'local_key_unreadable'      => array(
					'label' => __( 'Stored signing key cannot be decrypted', 'cloudflare-stream' ),
					'tip'   => __( 'The signing key in the database cannot be read with the current encryption material. Generate a new signing key on the settings page (the key id stays readable so the old key can be revoked). Prefer defining CLOUDFLARE_STREAM_ENCRYPTION_KEY in wp-config.php before rotating WordPress salts, or store the signing key as PHP constants instead.', 'cloudflare-stream' ),
				),
				'local_exp_invalid'         => array(
					'label' => __( 'Signed URL expiry was invalid', 'cloudflare-stream' ),
					'tip'   => __( 'Check the Signed URL Expiration setting (1–1440 minutes).', 'cloudflare-stream' ),
				),
				'local_json_encode'         => array(
					'label' => __( 'Could not encode the local JWT payload', 'cloudflare-stream' ),
					'tip'   => __( 'Confirm the signing key PEM is complete and matches the key id from Cloudflare.', 'cloudflare-stream' ),
				),
				'local_openssl_sign'        => array(
					'label' => __( 'OpenSSL could not sign with the configured key', 'cloudflare-stream' ),
					'tip'   => __( 'Confirm the signing key PEM is complete and matches the key id from Cloudflare.', 'cloudflare-stream' ),
				),
				'local_exception'           => array(
					'label' => __( 'An unexpected error occurred during local signing', 'cloudflare-stream' ),
					'tip'   => __( 'Confirm the signing key PEM is complete and matches the key id from Cloudflare.', 'cloudflare-stream' ),
				),
				'local_unknown'             => array(
					'label' => __( 'Local signing failed for an unknown reason', 'cloudflare-stream' ),
					'tip'   => __( 'Verify the signing key configuration, then reload a page that embeds a Stream video.', 'cloudflare-stream' ),
				),
				'api_credentials_missing'   => array(
					'label' => __( 'Cloudflare API credentials are missing', 'cloudflare-stream' ),
					'tip'   => __( 'Add a valid API Account ID and API Token under Settings → Cloudflare Stream.', 'cloudflare-stream' ),
				),
				'api_token_unreadable'      => array(
					'label' => __( 'Stored API token cannot be decrypted', 'cloudflare-stream' ),
					'tip'   => __( 'The API token in the database cannot be read with the current encryption material. Save a new API token on the settings page, or define CLOUDFLARE_STREAM_API_TOKEN in wp-config.php. Prefer defining CLOUDFLARE_STREAM_ENCRYPTION_KEY before rotating WordPress salts.', 'cloudflare-stream' ),
				),
				'api_http_error'            => array(
					'label' => __( 'Cloudflare API request failed', 'cloudflare-stream' ),
					'tip'   => __( 'Confirm the API token can access Stream and that api.cloudflare.com is reachable from this server.', 'cloudflare-stream' ),
				),
				'api_transport_error'       => array(
					'label' => __( 'Cloudflare API could not be reached', 'cloudflare-stream' ),
					'tip'   => __( 'Confirm that api.cloudflare.com is reachable from this server, then try again.', 'cloudflare-stream' ),
				),
				'api_rate_limited'          => array(
					'label' => __( 'Cloudflare API rate limited token minting', 'cloudflare-stream' ),
					'tip'   => __( 'Wait for the rate limit window to end, or add a local signing key so playback does not call /token on every miss.', 'cloudflare-stream' ),
				),
				'api_token_failed'          => array(
					'label' => __( 'Cloudflare API did not return a playback token', 'cloudflare-stream' ),
					'tip'   => __( 'Confirm the API token can access Stream and that api.cloudflare.com is reachable from this server.', 'cloudflare-stream' ),
				),
				'api_breaker_open'          => array(
					'label' => __( 'Cloudflare API token minting is temporarily paused after repeated failures', 'cloudflare-stream' ),
					'tip'   => __( 'Wait a few minutes for the API circuit breaker to close, then try again.', 'cloudflare-stream' ),
				),
			);
		}
		return isset( $map[ $code ] ) ? $map[ $code ] : array(
			'label' => __( 'Unknown', 'cloudflare-stream' ),
			'tip'   => __( 'Verify the signing key configuration, then reload a page that embeds a Stream video.', 'cloudflare-stream' ),
		);
	}

	/**
	 * Human-readable label for a reason code.
	 *
	 * @param string $code Reason code.
	 * @return string
	 */
	private function reason_label( $code ) {
		return $this->reason_meta( $code )['label'];
	}

	/**
	 * Resolution tips for local and optional API failure reasons.
	 *
	 * @param string $local_reason Local code.
	 * @param string $api_reason   API code (optional).
	 * @return string[]
	 */
	private function resolution_tips( $local_reason, $api_reason = '' ) {
		$tips   = array();
		$local  = $local_reason ? $local_reason : 'local_unknown';
		$tips[] = $this->reason_meta( $local )['tip'];

		if ( in_array( $local, array( 'local_openssl_sign', 'local_exception', 'local_json_encode', 'local_key_unreadable' ), true ) ) {
			$tips[] = __( 'Try removing the signing key and generating a new one.', 'cloudflare-stream' );
		}
		if ( $api_reason ) {
			$tips[] = $this->reason_meta( $api_reason )['tip'];
		}
		$tips[] = __( 'Signed playback never falls back to an unsigned video id while Use Signed URLs is enabled.', 'cloudflare-stream' );

		return array_values( array_unique( $tips ) );
	}

	/**
	 * Shared issue descriptor for settings, admin notice, and Site Health.
	 *
	 * @param array|null $state Optional state snapshot.
	 * @return array|null { severity, code, title, body, detail, tips }
	 */
	public function get_issue( $state = null ) {
		$state = null === $state ? $this->get_state() : $state;
		$api   = Cloudflare_Stream_API::instance();
		$now   = time();

		// Unreadable ciphertext is reported before the generic "no key" path.
		if ( $api->db_signing_key_pem_unreadable() ) {
			$reason = 'local_key_unreadable';
			return array(
				'severity' => 'error',
				'code'     => 'secret_unreadable',
				'title'    => __( 'Stored signing key cannot be decrypted', 'cloudflare-stream' ),
				'body'     => __( 'A signing key is present in the database but cannot be read with the current encryption material. Local signing is unavailable until you generate a new key or restore the previous encryption key.', 'cloudflare-stream' ),
				'detail'   => sprintf(
					/* translators: %s: reason code */
					__( 'Reason: %s.', 'cloudflare-stream' ),
					$reason
				),
				'tips'     => $this->resolution_tips( $reason, $api->db_api_token_unreadable() ? 'api_token_unreadable' : '' ),
				'label'    => $this->reason_label( $reason ),
			);
		}

		if ( $api->db_api_token_unreadable() ) {
			$reason = 'api_token_unreadable';
			$signed = (bool) get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, Cloudflare_Stream_Settings::DEFAULT_SIGNED_URLS );
			// Surface whenever signed mode needs the API, or when no local key is available.
			if ( $signed && ! $api->has_signing_key() ) {
				return array(
					'severity' => 'error',
					'code'     => 'secret_unreadable',
					'title'    => __( 'Stored API token cannot be decrypted', 'cloudflare-stream' ),
					'body'     => __( 'An API token is present in the database but cannot be read with the current encryption material. Signed playback via the Cloudflare API cannot run until you save a new token or restore the previous encryption key.', 'cloudflare-stream' ),
					'detail'   => sprintf(
						/* translators: %s: reason code */
						__( 'Reason: %s.', 'cloudflare-stream' ),
						$reason
					),
					'tips'     => $this->resolution_tips( 'local_key_missing_at_sign', $reason ),
					'label'    => $this->reason_label( $reason ),
				);
			}
		}

		if ( ! $api->has_signing_key() ) {
			if ( ! get_option( Cloudflare_Stream_Settings::OPTION_SIGNED_URLS, Cloudflare_Stream_Settings::DEFAULT_SIGNED_URLS ) ) {
				return null;
			}
			return array(
				'severity' => 'info',
				'code'     => 'api_mode',
				'title'    => __( 'Using Cloudflare API for signed playback', 'cloudflare-stream' ),
				'body'     => __( 'No local signing key is configured, so when Use Signed URLs is on, a signed playback token will be fetched via Cloudflare API.', 'cloudflare-stream' ),
				'detail'   => '',
				'tips'     => array(),
			);
		}

		if ( 'degraded_api_fallback' === $state['state'] ) {
			$reason = $state['reason'] ? $state['reason'] : 'local_unknown';
			$when   = ! empty( $state['last_seen'] ) ? human_time_diff( intval( $state['last_seen'] ), $now ) : '';
			$count  = max( 1, intval( $state['local_failures'] ) );
			return array(
				'severity' => 'warning',
				'code'     => 'degraded',
				'title'    => __( 'Signed playback is working, but not signing locally', 'cloudflare-stream' ),
				'body'     => __( 'Local JWT minting failed. The plugin is falling back to the Cloudflare API so playback tokens stay signed. Unsigned embeds are never used.', 'cloudflare-stream' ),
				'detail'   => sprintf(
					/* translators: 1: human time diff, 2: reason code, 3: failure count */
					__( 'Last seen %1$s ago. Reason: %2$s. Local failures recorded: %3$d.', 'cloudflare-stream' ),
					$when ? $when : __( 'recently', 'cloudflare-stream' ),
					$reason,
					$count
				),
				'tips'     => $this->resolution_tips( $reason, '' ),
				'label'    => $this->reason_label( $reason ),
			);
		}

		if ( 'total_failure' === $state['state'] ) {
			$local = $state['reason'] ? $state['reason'] : 'local_unknown';
			$api_r = $state['api_reason'] ? $state['api_reason'] : 'api_token_failed';
			$when  = ! empty( $state['last_seen'] ) ? human_time_diff( intval( $state['last_seen'] ), $now ) : '';
			return array(
				'severity' => 'error',
				'code'     => 'failed',
				'title'    => __( 'Signed playback is unavailable', 'cloudflare-stream' ),
				'body'     => __( 'Both local signing and the Cloudflare token API failed. Embeds are left empty on purpose so unsigned video IDs are never used while Use Signed URLs is enabled.', 'cloudflare-stream' ),
				'detail'   => sprintf(
					/* translators: 1: human time diff, 2: local reason code, 3: api reason code */
					__( 'Last seen %1$s ago. Local reason: %2$s. API reason: %3$s.', 'cloudflare-stream' ),
					$when ? $when : __( 'recently', 'cloudflare-stream' ),
					$local,
					$api_r
				),
				'tips'     => $this->resolution_tips( $local, $api_r ),
				'label'    => $this->reason_label( $local ),
			);
		}

		if ( ! empty( $state['recovered_at'] ) && ( $now - intval( $state['recovered_at'] ) ) < self::RECOVERY_INFO_WINDOW ) {
			return array(
				'severity' => 'info',
				'code'     => 'recovered',
				'title'    => __( 'Local signed token minting recovered', 'cloudflare-stream' ),
				'body'     => sprintf(
					/* translators: %s: human time diff */
					__( 'Local signed token minting recovered %s ago.', 'cloudflare-stream' ),
					human_time_diff( intval( $state['recovered_at'] ), $now )
				),
				'detail'   => '',
				'tips'     => array(),
			);
		}

		return null;
	}

	/**
	 * HTML comment for empty embeds on total failure (editors only).
	 *
	 * @return string
	 */
	public function get_editor_failure_comment() {
		// Internal reason codes are for administrators only.
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$state = $this->get_state();
		if ( 'total_failure' !== $state['state'] ) {
			return '';
		}
		return sprintf(
			'<!-- cloudflare-stream: signed embed unavailable (local: %s; api: %s; ref: %s) -->',
			$state['reason'] ? $state['reason'] : 'local_unknown',
			$state['api_reason'] ? $state['api_reason'] : 'api_token_failed',
			$state['uid_hash'] ? $state['uid_hash'] : 'none'
		);
	}

	/**
	 * Build the per-issue dismiss key from current health state.
	 *
	 * @param array|null $state State snapshot.
	 * @return string
	 */
	private function dismiss_key( $state = null ) {
		$state = null === $state ? $this->get_state() : $state;
		return $state['state'] . '|' . $state['reason'] . '|' . $state['api_reason'];
	}

	/**
	 * Whether the current user dismissed the notice for this issue.
	 *
	 * @param array|null $state State.
	 * @return bool
	 */
	public function is_notice_dismissed_for_user( $state = null ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$value = get_user_meta( $user_id, self::USER_META_DISMISS_KEY, true );
		return is_string( $value ) && $value === $this->dismiss_key( $state );
	}

	/**
	 * Persist per-user dismiss (does not clear health state).
	 *
	 * @return void
	 */
	public function handle_dismiss_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'cloudflare-stream' ), 403 );
		}
		check_admin_referer( self::ADMIN_ACTION_DISMISS, Cloudflare_Stream_Settings::NONCE );
		update_user_meta( get_current_user_id(), self::USER_META_DISMISS_KEY, $this->dismiss_key() );
		$redirect = wp_get_referer();
		wp_safe_redirect( $redirect ? $redirect : admin_url( 'options-general.php?page=cloudflare-stream' ) );
		exit;
	}

	/**
	 * Nonced admin-post URL that dismisses the signing health notice.
	 *
	 * @return string
	 */
	public function get_dismiss_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ADMIN_ACTION_DISMISS ),
			self::ADMIN_ACTION_DISMISS,
			Cloudflare_Stream_Settings::NONCE
		);
	}

	/**
	 * Register Site Health direct tests for signing and signed-embed caches.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public function register_site_health_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			$tests = array();
		}
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}
		$tests['direct']['cloudflare_stream_signing']      = array(
			'label' => __( 'Cloudflare Stream signed playback', 'cloudflare-stream' ),
			'test'  => array( $this, 'site_health_test' ),
		);
		$tests['direct']['cloudflare_stream_signed_cache'] = array(
			'label' => __( 'Cloudflare Stream signed embeds and page cache', 'cloudflare-stream' ),
			'test'  => array( $this, 'site_health_signed_cache_test' ),
		);
		return $tests;
	}

	/**
	 * Whether a full-page cache is likely active on this site.
	 *
	 * Best-effort signals only: WP_CACHE, common cache plugin constants, or a
	 * known active plugin slug. External reverse proxies cannot be detected here.
	 *
	 * @return bool
	 */
	public function is_page_cache_likely_active() {
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			return true;
		}

		$constants = array(
			'WPSC_VERSION',
			'W3TC',
			'LSCWP_V',
			'WP_ROCKET_VERSION',
			'AUTOMATTIC_CACHE_PATH',
		);
		foreach ( $constants as $name ) {
			if ( defined( $name ) ) {
				return true;
			}
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_path = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( is_readable( $plugin_path ) ) {
				require_once $plugin_path;
			}
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			$plugins = array(
				'wp-super-cache/wp-cache.php',
				'w3-total-cache/w3-total-cache.php',
				'litespeed-cache/litespeed-cache.php',
				'wp-rocket/wp-rocket.php',
				'cache-enabler/cache-enabler.php',
				'sg-cachepress/sg-cachepress.php',
				'comet-cache/comet-cache.php',
			);
			foreach ( $plugins as $plugin ) {
				if ( is_plugin_active( $plugin ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Site Health direct test callback for signing health.
	 *
	 * @return array
	 */
	public function site_health_test() {
		$result = array(
			'label'       => __( 'Cloudflare Stream signed playback is healthy', 'cloudflare-stream' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Cloudflare Stream', 'cloudflare-stream' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'Local signing is working, or signed tokens are minted normally via the Cloudflare API when no local key is configured.', 'cloudflare-stream' )
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'options-general.php?page=cloudflare-stream' ) ),
				esc_html__( 'Cloudflare Stream settings', 'cloudflare-stream' )
			),
			'test'        => 'cloudflare_stream_signing',
		);

		$issue = $this->get_issue();
		if ( null === $issue || 'info' === $issue['severity'] ) {
			return $result;
		}

		$result['status']      = ( 'error' === $issue['severity'] ) ? 'critical' : 'recommended';
		$result['label']       = ( 'error' === $issue['severity'] )
			? __( 'Cloudflare Stream signed playback is unavailable', 'cloudflare-stream' )
			: $issue['title'];
		$result['description'] = sprintf(
			'<p>%s</p>%s',
			esc_html( $issue['body'] ),
			$issue['detail'] ? '<p>' . esc_html( $issue['detail'] ) . '</p>' : ''
		);
		return $result;
	}

	/**
	 * Site Health test: signed embeds plus an external page cache.
	 *
	 * The plugin marks signed HTML uncacheable and sends no-cache headers, but
	 * reverse proxies and host caches can still store the page after the token
	 * inside it has expired.
	 *
	 * @return array
	 */
	public function site_health_signed_cache_test() {
		$settings_url = admin_url( 'options-general.php?page=cloudflare-stream' );
		$result       = array(
			'label'       => __( 'Signed Stream embeds are not exposed to a detected page cache', 'cloudflare-stream' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Cloudflare Stream', 'cloudflare-stream' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html__( 'When signed URLs are on, each page render writes short-lived tokens into the HTML. The plugin asks WordPress and common page caches not to store that HTML. No full-page cache signal was detected on this site.', 'cloudflare-stream' )
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $settings_url ),
				esc_html__( 'Cloudflare Stream settings', 'cloudflare-stream' )
			),
			'test'        => 'cloudflare_stream_signed_cache',
		);

		$api = Cloudflare_Stream_API::instance();
		if ( ! $api->is_signed_playback_enabled() ) {
			$result['label']       = __( 'Signed Stream URLs are off', 'cloudflare-stream' );
			$result['description'] = sprintf(
				'<p>%s</p>',
				esc_html__( 'Signed playback is not enabled, so page caches do not retain short-lived Stream tokens from this plugin.', 'cloudflare-stream' )
			);
			return $result;
		}

		if ( ! $this->is_page_cache_likely_active() ) {
			return $result;
		}

		$result['status']      = 'recommended';
		$result['label']       = __( 'Page cache may keep expired signed Stream embeds', 'cloudflare-stream' );
		$result['description'] = sprintf(
			'<p>%s</p><p>%s</p>',
			esc_html__( 'Signed URLs are on and a page cache appears to be active. The plugin sends no-cache headers and asks common WordPress page caches to skip pages that include signed Stream embeds, but a reverse proxy, CDN, or host-level cache can still store the HTML after the token inside it has expired. Visitors may then see a player that fails until the cache entry is replaced.', 'cloudflare-stream' ),
			esc_html__( 'Exclude pages that embed Cloudflare Stream videos from any external full-page cache, or keep those caches short-lived relative to the signed URL lifetime on the settings page.', 'cloudflare-stream' )
		);

		return $result;
	}
}
Cloudflare_Stream_Signing_Health::instance();
