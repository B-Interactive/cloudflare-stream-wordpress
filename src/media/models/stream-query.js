/**
 * Managing Stream Queries
 *
 * @package cloudflare-stream
 */

/**
 *
 * Shorthand for creating a new Attachments Query.
 *
 * @param {Object} props Block properties.
 * @return {wp.media.model.StreamAttachments} Stream attachments.
 */
wp.media.streamquery = function ( props ) {
	return new wp.media.model.StreamAttachments(
		null,
		{
			props: _.extend(
				_.defaults( props || {}, { orderby: 'date' } ),
				{
					query: true,
				}
			),
		}
	);
};

/**
 * A collection of Stream Attachments that match the supplied query arguments.
 */
wp.media.model.StreamQuery = wp.media.model.Query.extend(
	{
		initialize() {
			wp.media.model.Query.prototype.initialize.apply( this, arguments );
		},

		/**
		 * Fetch more Stream Attachments from the server for the collection.
		 *
		 * @return {Object} A collection of attachments.
		 */
		more() {
			this._more = wp.media.model.Query.prototype.more.apply(
				this,
				arguments
			);

			this._more.fail(
				function ( response ) {
					if ( 429 === response.statuscode ) {
							console.error(
								'Error: You have reached this service data rate limit on this IP address for this hour. Try again in a bit.'
							);
					}
					if ( response.msg ) {
						console.error(
							'Error: Could not retrieve remote library data:\n' +
							response.msg
						);
					}
				}
			);

			return this._more;
		},
		/**
		 * Default posts per page
		 *
		 * @readonly
		 */
		defaultArgs: {
			posts_per_page: 99,
		},
		/**
		 * Overrides Backbone.Collection.sync
		 * Overrides wp.media.model.Attachments.sync
		 *
		 * @param {string}         method       Method for sync
		 * @param {Backbone.Model} model        Backbone model
		 * @param {Object}         [options={}] Options
		 * @return {Promise} A Promise
		 */
		sync( method, model, options ) {
			// Overload the read method so Attachment.fetch() functions correctly.
			if ( 'read' === method ) {
				options         = options || {};
				options.context = this;
				options.data    = _.extend(
					options.data || {},
					{
						action: 'query-cloudflare-stream-attachments',
						post_id: wp.media.model.settings.post.id,
						nonce: cloudflareStream.nonce,

						//security: rmlQueryAttachmentsParams.nonce
					}
				);

				// Clone the args so manipulation is non-destructive.
				const args = _.clone( this.args );

				// Determine which page to query.
				if ( -1 !== args.posts_per_page ) {
					args.paged =
						Math.floor( this.length / args.posts_per_page ) + 1;
				}

				options.data.query = args;
				return wp.media.ajax( options );

				// Otherwise, fall back to Backbone.sync().
			}
			const fallback = wp.media.model.Attachments.prototype.sync
				? wp.media.model.Attachments.prototype
				: Backbone;
			return fallback.sync.apply( this, arguments );
		},
	},
	{
		get: ( function () {
			/**
			 * Caches query objects so queries can be easily reused.
			 *
			 * @static
			 * @type Array
			 */
			const queries = [];

			return function ( props, options ) {
				let args     = {},
					orderby  = wp.media.model.StreamQuery.orderby,
					defaults = wp.media.model.StreamQuery.defaultProps,
					query;

				// Remove the `query` property. This isn't linked to a query,
				// this *is* the query.
				delete props.query;

				// Remove the `remotefilters` property.
				delete props.remotefilters;

				// Remove the `uioptions` property.
				delete props.uioptions;

				// Fill default args.
				_.defaults( props, defaults );

				// Normalize the order.
				props.order = props.order.toUpperCase();
				if ( 'DESC' !== props.order && 'ASC' !== props.order ) {
					props.order = defaults.order.toUpperCase();
				}

				// Ensure we have a valid orderby value.
				if ( ! _.contains( orderby.allowed, props.orderby ) ) {
					props.orderby = defaults.orderby;
				}

				// Generate the query `args` object.
				// Correct any differing property names.
				_.each(
					props,
					function ( value, prop ) {
						if ( _.isNull( value ) ) {
							return;
						}

						args[ wp.media.model.StreamQuery.propmap[ prop ] || prop ] =
						value;
					}
				);

				// Fill any other default query args.
				_.defaults( args, wp.media.model.StreamQuery.defaultArgs );

				// `props.orderby` does not always map directly to `args.orderby`.
				// Substitute exceptions specified in orderby.keymap.
				args.orderby =
					orderby.valuemap[ props.orderby ] || props.orderby;

				// Search the query cache for matches.
				query = _.find(
					queries,
					function ( query ) {
						return _.isEqual( query.args, args );
					}
				);

				// Otherwise, create a new query and add it to the cache.
				if ( ! query ) {
					query = new wp.media.model.StreamQuery(
						[],
						_.extend(
							options || {},
							{
								props,
								args,
							}
						)
					);
					queries.push( query );
				}

				return query;
			};
		} )(),
	}
);
