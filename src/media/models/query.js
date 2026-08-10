/**
 * A collection of Stream attachments matching the supplied query arguments.
 *
 * @package cloudflare-stream
 */

cloudflareStream.media.model.Query = wp.media.model.Query.extend(
	{
		initialize( models, options ) {
			options                  = options || {};
			wp.media.model.Query.prototype.initialize.apply( this, arguments );
			this.args                = options.args || {};
			this.args.posts_per_page = cloudflareStream.api.posts_per_page;
		},

		/**
		 * Read Stream videos through admin-ajax; everything else falls back.
		 *
		 * @param {string}         method       Method for sync
		 * @param {Backbone.Model} model        Backbone model
		 * @param {Object}         [options={}] Options
		 * @return {Promise} A Promise
		 */
		sync( method, model, options ) {
			if ( 'read' === method ) {
				options         = options || {};
				options.context = this;
				options.data    = _.extend(
					options.data || {},
					{
						action: 'query-cloudflare-stream-attachments',
						post_id: wp.media.model.settings.post.id,
						nonce: cloudflareStream.nonce,
					}
				);

				let timestampOffset = '';
				if (
					cloudflareStream.media.model.Attachments.all.models.length >
					0
				) {
					timestampOffset =
						'&end=' +
						cloudflareStream.media.model.Attachments.all.models[
							cloudflareStream.media.model.Attachments.all.models
								.length - 1
						].attributes.modified.toISOString();
				}

				options.data.query = 'asc=false' + timestampOffset;
				return wp.media.ajax( options );
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
					orderby  = cloudflareStream.media.model.Query.orderby,
					defaults = cloudflareStream.media.model.Query.defaultProps,
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

						args[
						cloudflareStream.media.model.Query.propmap[ prop ] ||
							prop
						] = value;
					}
				);

				// Fill any other default query args.
				_.defaults(
					args,
					cloudflareStream.media.model.Query.defaultArgs
				);

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
					query = new cloudflareStream.media.model.Query(
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
