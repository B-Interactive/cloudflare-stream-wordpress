/**
 * A collection of Cloudflare Stream attachments.
 *
 * @package cloudflare-stream
 *
 * The collection only talks to the server when 'options.props.query' is true,
 * in which case it mirrors a Stream Query collection.
 */

cloudflareStream.media.model.Attachments = wp.media.model.Attachments.extend( {
	initialize() {
		wp.media.model.Attachments.prototype.initialize.apply(
			this,
			arguments
		);
	},

	/**
	 * Mirror a Stream Query collection when this collection is a query.
	 *
	 * @access private
	 */
	_requery() {
		if ( this.props.get( 'query' ) ) {
			this.mirror(
				cloudflareStream.media.model.Query.get( this.props.toJSON() )
			);
		}
	},
} );
