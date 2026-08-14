/**
 * Client-side capability helpers from PHP-localised Stream config.
 *
 * @package cloudflare-stream
 */

/* global cloudflareStream */

/**
 * Whether the current user may manage Stream library items and uploads.
 *
 * Reads the canManage flag localised from PHP; does not invent client-side caps.
 *
 * @return {boolean} True when PHP localised canManage is set.
 */
export function userCanManageStream() {
	return Boolean(
		typeof cloudflareStream !== 'undefined' &&
			( cloudflareStream.canManage === true ||
				cloudflareStream.canManage === 1 ||
				cloudflareStream.canManage === '1' )
	);
}
