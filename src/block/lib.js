/**
 * Lib
 *
 * @package cloudflare-stream
 */

/* global cloudflareStream */

/**
 * Standard iframe domains from PHP, with a safe fallback.
 *
 * @return {Array} Domain list.
 */
const getStandardDomains = function () {
	if (
		typeof cloudflareStream !== 'undefined' &&
		Array.isArray( cloudflareStream.standardDomains )
	) {
		return cloudflareStream.standardDomains;
	}

	return [ 'cloudflarestream.com', 'videodelivery.net' ];
};

/**
 * Configured media domain (iframe preference).
 *
 * @return {string} Domain host.
 */
const getMediaDomain = function () {
	if (
		typeof cloudflareStream !== 'undefined' &&
		cloudflareStream.mediaDomain
	) {
		return cloudflareStream.mediaDomain;
	}

	return 'cloudflarestream.com';
};

/**
 * Host for media assets such as thumbnails.
 *
 * Standard iframe domains use videodelivery.net for assets.
 *
 * @return {string} Asset host.
 */
const getMediaAssetHost = function () {
	if (
		typeof cloudflareStream !== 'undefined' &&
		cloudflareStream.mediaAssetHost
	) {
		return cloudflareStream.mediaAssetHost;
	}

	const domain = getMediaDomain();
	if ( getStandardDomains().indexOf( domain ) !== -1 ) {
		return 'videodelivery.net';
	}

	return domain;
};

/**
 * Build the Stream iframe src for editor preview.
 *
 * @param {Object} attributes Block attributes.
 * @return {string} Iframe URL.
 */
export const streamIframeSource = function ( attributes ) {
	const { uid, controls, autoplay, loop, muted, thumbnail } = attributes;

	// Build a query string for Stream URL options.
	const queryElements = [];

	// Get any querystring params included in the UID (not clear why this sometimes happens).
	const uidParts = String( uid || '' ).split( '?' );
	const idPath = uidParts[ 0 ];
	if ( uidParts[ 1 ] ) {
		queryElements.push( uidParts[ 1 ] );
	}

	// Add the thumbnail if it exists.
	if ( thumbnail ) {
		queryElements.push( 'poster=' + encodeURIComponent( thumbnail ) );
	}

	// Add other boolean parameters if they are set.
	const params = { controls, autoplay, loop, muted };
	for ( const param in params ) {
		if ( typeof params[ param ] !== 'undefined' && params[ param ] ) {
			queryElements.push( param + '=true' );
		}
	}

	const queryString =
		queryElements.length > 0 ? '?' + queryElements.join( '&' ) : '';
	const domain = getMediaDomain();
	const isStandard = getStandardDomains().indexOf( domain ) !== -1;

	if ( isStandard ) {
		return 'https://iframe.' + domain + '/' + idPath + queryString;
	}

	return 'https://' + domain + '/' + idPath + '/iframe' + queryString;
};

/**
 * Build a thumbnail URL for a video id or signed token.
 *
 * @param {string} id   Video UID or token.
 * @param {string} time Optional time with unit, e.g. "0s".
 * @return {string} Poster URL.
 */
export const streamPosterUrl = function ( id, time ) {
	const host = getMediaAssetHost();
	const cleanId = String( id || '' ).split( '?' )[ 0 ];
	let url =
		'https://' + host + '/' + cleanId + '/thumbnails/thumbnail.jpg';

	if ( time ) {
		url +=
			( url.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'time=' +
			encodeURIComponent( time );
	}

	return url;
};
