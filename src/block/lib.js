/**
 * Stream playback URL helpers.
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
