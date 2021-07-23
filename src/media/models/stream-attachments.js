/**
 * wp.media.model.StreamAttachments
 *
 * A collection of Cloudflare Stream attachments.
 *
 * This collection has no persistence with the server without supplying
 * 'options.props.query = true', which will mirror the collection
 * to an Stream Attachments Query collection - @see wp.media.model.Attachments.mirror().
 *
 */
wp.media.model.StreamAttachments = wp.media.model.Attachments.extend( {
	initialize: function() {
		wp.media.model.Attachments.prototype.initialize.apply( this, arguments );
	},

	/**
	 * If the collection is a query, create and mirror an StreamAttachments StreamQuery collection.
	 *
	 * @access private
	 * @param {bool} refresh Whether or not we should refresh the results.
	 */
	_requery: function( refresh ) {
		let props;
		if ( this.props.get( 'query' ) ) {
			props = this.props.toJSON();
			props.cache = ( true !== refresh );
			this.mirror( wp.media.model.StreamQuery.get( this.props.toJSON() ) );
		}
	},
} );
