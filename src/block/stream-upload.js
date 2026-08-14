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
 * Cloudflare TUS chunk rules: minimum 5_242_880 bytes unless the whole file is
 * smaller; maximum 209_715_200; non-final chunks must be multiples of 256 KiB.
 */
const MIN_CHUNK_SIZE = 5242880;
const PREFERRED_CHUNK_SIZE = 52428800;
const CHUNK_ALIGN = 262144;
const MAX_CHUNK_SIZE = 209715200;

/**
 * Pick a Cloudflare-safe TUS chunk size for a file.
 *
 * @param {number} fileSize File size in bytes.
 * @return {number} Chunk size for tus-js-client.
 */
export function cloudflareChunkSize( fileSize ) {
	const size = Number( fileSize );

	if ( ! Number.isFinite( size ) || size <= 0 ) {
		return MIN_CHUNK_SIZE;
	}

	// Whole file fits under the minimum: one request, final-chunk exemption.
	if ( size < MIN_CHUNK_SIZE ) {
		return size;
	}

	let chunk = Math.min( PREFERRED_CHUNK_SIZE, MAX_CHUNK_SIZE, size );

	if ( chunk < MIN_CHUNK_SIZE ) {
		chunk = MIN_CHUNK_SIZE;
	}

	chunk = Math.floor( chunk / CHUNK_ALIGN ) * CHUNK_ALIGN;

	if ( chunk < MIN_CHUNK_SIZE ) {
		chunk = MIN_CHUNK_SIZE;
	}

	return chunk;
}

/**
 * Ask WordPress for a one-time Stream TUS direct-upload URL.
 * The API token stays on the server. Upload-Length is required at create time.
 *
 * @param {File} file Browser file object (size, name, type).
 * @return {Promise<{ uploadURL: string, uid: string }>} Upload target and video id.
 */
export function fetchDirectUpload( file ) {
	const payload = {
		uploadLength: String( file && file.size ? file.size : 0 ),
	};

	if ( file && file.name ) {
		payload.name = file.name;
	}

	if ( file && file.type ) {
		payload.filetype = file.type;
	}

	return streamAjax( 'query-cloudflare-stream-upload', payload ).then(
		( response ) => {
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
		}
	);
}

/**
 * Upload a file to a pre-created Stream TUS direct-upload URL.
 *
 * The caller owns the returned Upload instance and may call abort() to cancel.
 * Fingerprint resume is off: each direct URL is one-shot and time-boxed, so a
 * stored URL from a previous attempt must not be reused.
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
	const metadata = {
		name: file.name,
		filetype: file.type || 'application/octet-stream',
	};

	const upload = new tus.Upload( file, {
		// Pre-created direct upload URL; no account API token needed.
		uploadUrl: uploadURL,
		chunkSize: cloudflareChunkSize( file.size ),
		storeFingerprintForResuming: false,
		removeFingerprintOnSuccess: true,
		retryDelays: [ 0, 1000, 3000, 5000 ],
		metadata,
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
	} );

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
 * @param {Object} [options] Optional request options.
 * @param {AbortSignal} [options.signal] AbortSignal to cancel the request.
 * @return {Promise<Object>} AJAX response body from WordPress.
 */
export function checkUploadStatus( uid, options = {} ) {
	return streamAjax( 'cloudflare-stream-check-upload', { uid }, options );
}
