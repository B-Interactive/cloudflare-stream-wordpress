/**
 * Admin-ajax helpers for Stream editor requests.
 *
 * @package cloudflare-stream
 */

import apiFetch from '@wordpress/api-fetch';

/* global ajaxurl */
/* global cloudflareStream */

/**
 * POST to a Stream admin-ajax action.
 *
 * Sends application/x-www-form-urlencoded so PHP can read action, nonce and
 * fields from $_REQUEST.
 *
 * @param {string} action admin-ajax action name.
 * @param {Object} data   Extra body fields.
 * @return {Promise<Object>} Parsed wp_send_json_* payload.
 */
export function streamAjax( action, data = {} ) {
	const body = new window.URLSearchParams( {
		action,
		nonce: cloudflareStream.nonce,
		...data,
	} );

	return apiFetch( {
		url: ajaxurl,
		method: 'POST',
		body,
		parse: true,
	} );
}
