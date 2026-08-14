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
 * @param {Object} [options] Optional request options.
 * @param {AbortSignal} [options.signal] AbortSignal to cancel the request.
 * @return {Promise<Object>} Parsed wp_send_json_* payload.
 */
export function streamAjax( action, data = {}, options = {} ) {
	const body = new window.URLSearchParams( {
		action,
		nonce: cloudflareStream.nonce,
		...data,
	} );

	const request = {
		url: ajaxurl,
		method: 'POST',
		body,
		parse: true,
	};

	if ( options && options.signal ) {
		request.signal = options.signal;
	}

	return apiFetch( request );
}
