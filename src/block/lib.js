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
 * Whether this site serves Stream playback through signed URLs.
 *
 * @return {boolean} True when playback URLs need a server-minted token.
 */
export const usesSignedUrls = function () {
	return (
		typeof cloudflareStream !== 'undefined' &&
		Boolean( cloudflareStream.signedUrls )
	);
};

/**
 * Build the playback option query string shared by signed and unsigned URLs.
 *
 * @param {Object} attributes Block attributes.
 * @param {string} extra      Params already present on the base URL.
 * @param {string} poster     Poster URL to use, if any.
 * @return {string} Query string including the leading '?', or an empty string.
 */
const playbackQueryString = function ( attributes, extra, poster ) {
	const { controls, autoplay, loop, muted } = attributes;
	const queryElements = [];

	if ( extra ) {
		queryElements.push( extra );
	}

	if ( poster ) {
		queryElements.push( 'poster=' + encodeURIComponent( poster ) );
	}

	// Browsers block autoplay with sound; the Stream player docs match that rule.
	const effectiveMuted = Boolean( muted ) || Boolean( autoplay );

	// Add other boolean parameters if they are set.
	const params = { controls, autoplay, loop, muted: effectiveMuted };
	for ( const param in params ) {
		if ( typeof params[ param ] !== 'undefined' && params[ param ] ) {
			queryElements.push( param + '=true' );
		}
	}

	return queryElements.length > 0 ? '?' + queryElements.join( '&' ) : '';
};

/**
 * Build the Stream iframe src for editor preview.
 *
 * Unsigned playback only. When signed URLs are on the base URL must come from
 * the server (it carries a playback token), so use signedIframeSource instead.
 *
 * @param {Object} attributes Block attributes.
 * @return {string} Iframe URL.
 */
export const streamIframeSource = function ( attributes ) {
	const { uid, thumbnail } = attributes;

	// Get any querystring params included in the UID (not clear why this sometimes happens).
	const uidParts = String( uid || '' ).split( '?' );
	const idPath = uidParts[ 0 ];

	const queryString = playbackQueryString(
		attributes,
		uidParts[ 1 ],
		thumbnail
	);
	const domain = getMediaDomain();
	const isStandard = getStandardDomains().indexOf( domain ) !== -1;

	if ( isStandard ) {
		return 'https://iframe.' + domain + '/' + idPath + queryString;
	}

	return 'https://' + domain + '/' + idPath + '/iframe' + queryString;
};

/**
 * Apply playback options to a server-minted signed iframe URL.
 *
 * The token lives in the base URL path, so it is never rebuilt here.
 *
 * @param {Object} attributes Block attributes.
 * @param {Object} urls       Server response with iframeUrl and posterUrl.
 * @return {string} Iframe URL, or an empty string when not yet resolved.
 */
export const signedIframeSource = function ( attributes, urls ) {
	if ( ! urls || ! urls.iframeUrl ) {
		return '';
	}

	return (
		urls.iframeUrl + playbackQueryString( attributes, '', urls.posterUrl )
	);
};
