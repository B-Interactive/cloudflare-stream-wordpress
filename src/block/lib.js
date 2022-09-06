export const streamIframeSource = function ( attributes ) {

	const { uid, controls, autoplay, loop, muted, thumbnail } = attributes;

	// build a query string for Stream URL options
	let queryElements = [];

	// get any querystring params included in the UID (not clear why this sometimes happens)
	if ( uid.split('?')[1] ) 				queryElements.push( uid.split('?')[1] );

	// add the thumbnail if it exists
	if ( thumbnail ) 						queryElements.push( 'poster=' + encodeURIComponent( thumbnail ) );
	
	// add other boolean parameters if they are set
	const params = { controls, autoplay, loop, muted };
	for ( const param in params ) {
		if ( typeof params[param] != 'undefined' && params[param] ) queryElements.push( param + '=true' );
	}

	let queryString = '?' + queryElements.join('&');

	return 'https://iframe.cloudflarestream.com/' + uid + queryString;
}