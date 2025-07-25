/**
 * Lib
 *
 * @package cloudflare-stream
 */

export const streamIframeSource = function ( attributes ) {
	const { uid, controls, autoplay, loop, muted, thumbnail } = attributes;

	// Build a query string for Stream URL options.
	const queryElements = [];

	// Get any querystring params included in the UID (not clear why this sometimes happens).
	if ( uid.split( '?' )[ 1 ] ) {
		queryElements.push( uid.split( '?' )[ 1 ] );
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

	const queryString = '?' + queryElements.join( '&' );

	return 'https://iframe.cloudflarestream.com/' + uid + queryString;
};
