/**
 * Stream upload transport
 *
 * Direct-upload URL request, TUS transfer, and encoding status checks.
 * Keeps upload plumbing out of the block edit UI.
 *
 * @package cloudflare-stream
 */

import * as tus from 'tus-js-client';
import { streamAjax } from '../lib/ajax';

/**
 * 50 MiB — multiple of 256 KiB, within Cloudflare's recommended chunk range.
 */
const CHUNK_SIZE = 52428800;

/**
 * Ask WordPress for a one-time Stream direct-upload URL.
 * The API token stays on the server.
 *
 * @return {Promise<{ uploadURL: string, uid: string }>} Upload target and video id.
 */
export function fetchDirectUpload() {
	return streamAjax( 'query-cloudflare-stream-upload' ).then( ( response ) => {
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
			throw new Error( message );
		}

		return {
			uploadURL: response.data.uploadURL,
			uid: response.data.uid,
		};
	} );
}

/**
 * Upload a file to a pre-created Stream direct-upload URL via TUS.
 *
 * The caller owns the returned Upload instance and may call abort() to cancel.
 * With uploadUrl set, tus resumes against that same URL; Cloudflare direct
 * upload URLs are single-use and time-boxed, so a long pause can make resume fail.
 *
 * @param {File}     file                Browser file object.
 * @param {string}   uploadURL           One-time direct upload URL.
 * @param {Object}   handlers            Progress and completion handlers.
 * @param {Function} handlers.onError    Called with the error value.
 * @param {Function} handlers.onProgress Called with bytesUploaded, bytesTotal.
 * @param {Function} handlers.onSuccess  Called with the tus Upload instance.
 * @return {Object} tus Upload instance. Caller owns the handle.
 */
export function tusUploadFile( file, uploadURL, handlers ) {
	const upload = new tus.Upload(
		file,
		{
			removeFingerprintOnSuccess: true,
			// Pre-created direct upload URL; no account API token needed.
			uploadUrl: uploadURL,
			chunkSize: CHUNK_SIZE,
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
 * Returns the raw { success, data } envelope from WordPress so callers can
 * branch on both success and data.data.status / readyToStream.
 *
 * @param {string} uid Stream video id.
 * @return {Promise<Object>} AJAX response body from WordPress.
 */
export function checkUploadStatus( uid ) {
	return streamAjax( 'cloudflare-stream-check-upload', { uid } );
}
