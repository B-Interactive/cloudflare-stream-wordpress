/* global ajaxurl */
/* global cloudflareStream */

class CloudflareStreamHeapAnalytics {
	constructor( ) {
		jQuery( '#submit' ).on( 'click', function() {
			cloudflareStream.analytics.logEvent( 'Stream WP Plugin - Settings Saved' );
		} );
	}

	logEvent( event ) {
		// No logging.
	}
}
cloudflareStream.analytics = new CloudflareStreamHeapAnalytics();
