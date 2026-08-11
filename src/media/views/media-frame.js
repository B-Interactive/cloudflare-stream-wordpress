/**
 * Media Frame
 *
 * @package cloudflare-stream
 */

import { streamAjax } from '../../lib/ajax';

/* global cloudflareStream */

const Post = wp.media.view.MediaFrame.Post,
	Library = wp.media.controller.Library,
	l10n = wp.media.view.l10n;

/**
 * Whether the current user may rename or delete Stream library items.
 *
 * @return {boolean} True when PHP localised canManage is set.
 */
function userCanManageStream() {
	return Boolean(
		typeof cloudflareStream !== 'undefined' &&
			( cloudflareStream.canManage === true ||
				cloudflareStream.canManage === 1 ||
				cloudflareStream.canManage === '1' )
	);
}

/**
 * The frame for manipulating media on the Edit Post page.
 *
 * @memberOf wp.media.view.MediaFrame
 *
 * @class
 * @augments wp.media.view.MediaFrame.Post
 */
cloudflareStream.media.view.MediaFrame = Post.extend(
	/**
	 * Media Frame
	 *
	 * @lends cloudflareStream.media.view.MediaFrame.prototype
	 */
	{
		initialize( options ) {
			this.select = options;
			this.canManage = userCanManageStream();
			this._sidebarBound = false;
			this._onSidebarClick = null;
			this._onSidebarChange = null;

			_.defaults( this.options, {
				id: 'cloudflare-stream',
				className: this.canManage
					? 'cloudflare-stream-media-frame'
					: 'cloudflare-stream-media-frame cloudflare-stream-media-frame--view-only',
				title: 'Cloudflare Stream Library',
				multiple: false,
				editing: false,
				state: 'insert',
				metadata: {},
			} );

			// Call 'initialize' directly on the parent class.
			Post.prototype.initialize.apply( this, arguments );
		},

		/**
		 * Create the default states.
		 */
		createStates() {
			const options = this.options;

			this.states.add( [
				new Library( {
					id: 'insert',
					title: options.title,
					priority: 20,
					toolbar: 'main-insert',
					menu: false,
					filterable: false,
					searchable: false,
					date: false,
					library: new cloudflareStream.media.model.Query(
						null,
						_.defaults(
							{},
							{
								type: 'video',
							}
						)
					),
					multiple: options.multiple ? 'reset' : false,
					// Title edits and delete stay with manage capability.
					editable: this.canManage,

					// If the user isn't allowed to edit fields,
					// can they still edit it locally?
					allowLocalEdits: this.canManage,

					// Show the attachment display settings.
					displaySettings: false,
					// Update user settings when users adjust the
					// attachment display settings.
					displayUserSettings: false,

					//AttachmentView: wp.media.view.Attachments.EditSelection,
				} ),
			] );
		},

		bindHandlers() {
			let handlers, checkCounts;

			Post.prototype.bindHandlers.apply( this, arguments );

			this.on( 'activate', this.activate, this );

			// Only bother checking media type counts if one of the counts is zero.
			checkCounts = _.find( this.counts, function ( type ) {
				return type.count === 0;
			} );

			if ( typeof checkCounts !== 'undefined' ) {
				this.listenTo(
					wp.media.model.Attachments.all,
					'change:type',
					this.mediaTypeCounts
				);
			}

			this.on( 'toolbar:create:main-insert', this.createToolbar, this );

			if ( this.canManage ) {
				this.on( 'selection:toggle', this.bindSidebarItems, this );
			} else {
				// Keep delete/title controls out of reach for content editors.
				this.on( 'selection:toggle', this.lockSidebarForViewers, this );
				this.on( 'open', this.lockSidebarForViewers, this );
			}

			handlers = {
				toolbar: {
					'main-insert': 'mainInsertToolbar',
				},
			};

			_.each(
				handlers,
				function ( regionHandlers, region ) {
					_.each(
						regionHandlers,
						function ( callback, handler ) {
							this.on(
								region + ':render:' + handler,
								this[ callback ],
								this
							);
						},
						this
					);
				},
				this
			);
		},

		/**
		 * Hide delete and lock the title field when the user cannot manage Stream.
		 */
		lockSidebarForViewers() {
			if ( this.canManage || ! this.el ) {
				return;
			}

			window.requestAnimationFrame( () => {
				if ( ! this.el ) {
					return;
				}

				this.el
					.querySelectorAll( '.delete-attachment' )
					.forEach( ( node ) => {
						node.hidden = true;
						node.setAttribute( 'aria-hidden', 'true' );
						node.style.display = 'none';
					} );

				this.el
					.querySelectorAll(
						'label[data-setting="title"] input'
					)
					.forEach( ( input ) => {
						input.readOnly = true;
						input.disabled = true;
					} );
			} );
		},

		/**
		 * Bind sidebar delete/title handlers once on the frame root.
		 * Delegation survives sidebar re-renders on selection change.
		 */
		bindSidebarItems() {
			const el = this.el;
			if ( ! this.canManage || ! el || this._sidebarBound ) {
				return;
			}

			this._sidebarBound = true;

			this._onSidebarClick = ( event ) => {
				if ( event.target.closest( '.delete-attachment' ) ) {
					this.deleteAttachment( event );
				}
			};

			this._onSidebarChange = ( event ) => {
				if (
					event.target.closest( 'label[data-setting="title"] input' )
				) {
					this.updateAttachment( event );
				}
			};

			el.addEventListener( 'click', this._onSidebarClick );
			el.addEventListener( 'change', this._onSidebarChange );
		},

		/**
		 * Tear down delegated sidebar listeners with the frame.
		 */
		remove() {
			const el = this.el;

			if ( el && this._sidebarBound ) {
				if ( this._onSidebarClick ) {
					el.removeEventListener( 'click', this._onSidebarClick );
				}
				if ( this._onSidebarChange ) {
					el.removeEventListener( 'change', this._onSidebarChange );
				}
			}

			this._sidebarBound = false;
			this._onSidebarClick = null;
			this._onSidebarChange = null;

			return Post.prototype.remove.apply( this, arguments );
		},

		/**
		 * Delete Attachment
		 *
		 * @param {Object} event The Delete Event
		 */
		deleteAttachment( event ) {
			event.preventDefault();
			event.stopPropagation();

			if ( ! this.canManage ) {
				return;
			}

			/* eslint-disable */
			if ( window.confirm( l10n.warnDelete ) ) {
				/* eslint-enable */
				const state = this.state(),
					selection = state.get( 'selection' ),
					model = selection.first(),
					attachment = model ? model.toJSON() : null;

				if ( ! attachment ) {
					return;
				}

				// Keep the model so the block can drop selection after success.
				attachment._selectionModel = model;

				// Server delete is owned by the block edit handler; only fire
				// after the user confirms so the selection can drop on success.
				state.trigger( 'delete', attachment );
			}
		},

		/**
		 * Update Attachment
		 *
		 * @param {Object} event The Update Event
		 */
		updateAttachment( event ) {
			event.preventDefault();
			event.stopPropagation();

			if ( ! this.canManage ) {
				return;
			}

			const state = this.state(),
				selection = state.get( 'selection' ),
				attachment = selection.first().toJSON();

			const input = this.el.querySelector(
				'label[data-setting="title"] input'
			);
			const newTitle = input ? input.value : '';
			const spinner = this.el.querySelector( '.media-sidebar .spinner' );

			if ( spinner ) {
				spinner.style.visibility = 'visible';
			}

			streamAjax( 'cloudflare-stream-update', {
				uid: attachment.uid,
				title: newTitle,
				upload:
					attachment.cloudflare && attachment.cloudflare.meta
						? attachment.cloudflare.meta.upload
						: '',
			} )
				.then( () => {
					selection.models[ 0 ].set( 'filename', newTitle );
				} )
				.catch( ( err ) => {
					// eslint-disable-next-line no-console
					console.error(
						'Error: ',
						err && err.message ? err.message : err
					);
				} )
				.finally( () => {
					if ( spinner ) {
						spinner.style.visibility = 'hidden';
					}
				} );
		},

		/**
		 * Render callback for the router region in the `browse` mode.
		 *
		 * @param {wp.media.view.Router} routerView The Router view.
		 */
		browseRouter( routerView ) {
			routerView.set( {
				browse: {
					text: this.options.title,
					priority: 40,
				},
			} );
		},

		/**
		 * Insert Toolbar
		 *
		 * @param {wp.Backbone.View} view Backbone Toolbar view.
		 */
		mainInsertToolbar( view ) {
			const controller = this;

			view.set( 'insert', {
				style: 'primary',
				priority: 80,
				text: 'Select',
				requires: { selection: true },

				/**
				 * Click event
				 *
				 * @fires wp.media.controller.State#insert
				 */
				click() {
					const state = controller.state(),
						selection = state.get( 'selection' ),
						attachment = selection.first().toJSON();

					controller.select( attachment );
					controller.close();
					state.trigger( 'insert', selection ).reset();
				},
			} );
		},
	}
);
