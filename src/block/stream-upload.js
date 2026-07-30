/**
 * Stream upload transport
 *
 * Direct-upload URL request, TUS transfer, and encoding status checks.
 * Keeps upload plumbing out of the block edit UI.
 *
 * @package cloudflare-stream
 */

import * as tus from 'tus-js-client';

/* global ajaxurl */
/* global cloudflareStream */

/**
 * Ask WordPress for a one-time Stream direct-upload URL.
 * The API token stays on the server.
 *
 * @return {Promise<{ uploadURL: string, uid: string }>} Upload target and video id.
 */
export function fetchDirectUpload() {
	return new Promise( ( resolve, reject ) => {
		jQuery.ajax(
			{
				url: ajaxurl + '?action=query-cloudflare-stream-upload',
				method: 'POST',
				data: {
					nonce: cloudflareStream.nonce,
				},
				success( response ) {
					if (
						! response.success ||
						! response.data ||
						! response.data.uploadURL ||
						! response.data.uid
					) {
						const message =
							response.data && response.data.message
								? response.data.message
								: 'Could not start upload.';
						reject( new Error( message ) );
						return;
					}

					resolve(
						{
							uploadURL: response.data.uploadURL,
							uid: response.data.uid,
						}
					);
				},
				error( jqXHR, textStatus ) {
					reject( new Error( textStatus ) );
				},
			}
		);
	} );
}

/**
 * Upload a file to a pre-created Stream direct-upload URL via TUS.
 *
 * @param {File}     file               Browser file object.
 * @param {string}   uploadURL          One-time direct upload URL.
 * @param {Object}   handlers           Progress and completion handlers.
 * @param {Function} handlers.onError   Called with the error value.
 * @param {Function} handlers.onProgress Called with bytesUploaded, bytesTotal.
 * @param {Function} handlers.onSuccess Called with the tus Upload instance.
 * @return {Object} tus Upload instance.
 */
export function tusUploadFile( file, uploadURL, handlers ) {
	const upload = new tus.Upload(
		file,
		{
			removeFingerprintOnSuccess: true,
			// Pre-created direct upload URL; no account API token needed.
			uploadUrl: uploadURL,
			retryDelays: [ 0, 1000, 3000, 5000 ],
			metadata: {
				name: file.name,
				type: file.type,
			},
			onError( error ) {
				if ( handlers && handlers.onError ) {
					handlers.onError( error );
				}
			},
			onProgress( bytesUploaded, bytesTotal ) {
				if ( handlers && handlers.onProgress ) {
					handlers.onProgress( bytesUploaded, bytesTotal );
				}
			},
			onSuccess() {
				if ( handlers && handlers.onSuccess ) {
					handlers.onSuccess( upload );
				}
			},
		}
	);

	upload.start();
	return upload;
}

/**
 * Poll Stream processing status for a video id.
 *
 * @param {string} uid Stream video id.
 * @return {Promise<Object>} AJAX response body from WordPress.
 */
export function checkUploadStatus( uid ) {
	return new Promise( ( resolve, reject ) => {
		jQuery.ajax(
			{
				url: ajaxurl + '?action=cloudflare-stream-check-upload',
				data: {
					nonce: cloudflareStream.nonce,
					uid,
				},
				success( data ) {
					resolve( data );
				},
				error( jqXHR, textStatus ) {
					reject( new Error( textStatus ) );
				},
			}
		);
	} );
}
