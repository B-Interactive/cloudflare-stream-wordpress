/**
 * Media Frame
 *
 * @package cloudflare-stream
 */

import './attachment-details.js';
import { userCanManageStream } from '../../lib/capabilities';

/* global cloudflareStream */

const Post = wp.media.view.MediaFrame.Post,
	Library = wp.media.controller.Library;

/**
 * Register the Stream attachment details template once.
 */
function ensureAttachmentDetailsTemplate() {
	if ( document.getElementById( 'tmpl-cloudflare-stream-attachment-details' ) ) {
		return;
	}

	const script = document.createElement( 'script' );
	script.type = 'text/html';
	script.id = 'tmpl-cloudflare-stream-attachment-details';
	script.textContent = `
		<h2>
			${ wp.i18n.__( 'Attachment Details', 'cloudflare-stream' ) }
			<span class="settings-save-status" role="status">
				<span class="spinner"></span>
				<span class="saved">${ wp.i18n.__( 'Saved.', 'cloudflare-stream' ) }</span>
			</span>
		</h2>
		<div class="attachment-info">
			<# if ( data.image && data.image.src ) { #>
				<div class="thumbnail thumbnail-video">
					<img src="{{ data.image.src }}" class="icon" draggable="false" alt="" />
				</div>
			<# } else { #>
				<div class="thumbnail thumbnail-video">
					<img src="{{ data.icon }}" class="icon" draggable="false" alt="" />
				</div>
			<# } #>
			<div class="details">
				<div class="filename">{{ data.filename }}</div>
				<div class="uploaded">{{ data.dateFormatted }}</div>
				<# if ( data.filesizeHumanReadable ) { #>
					<div class="file-size">{{ data.filesizeHumanReadable }}</div>
				<# } #>
				<# if ( data.fileLength && data.fileLengthHumanReadable ) { #>
					<div class="file-length">${ wp.i18n.__( 'Length:', 'cloudflare-stream' ) }
						<span aria-hidden="true">{{ data.fileLengthHumanReadable }}</span>
						<span class="screen-reader-text">{{ data.fileLengthHumanReadable }}</span>
					</div>
				<# } #>
				<# if ( data.can && data.can.remove ) { #>
					<button type="button" class="button-link delete-attachment">${ wp.i18n.__( 'Delete permanently', 'cloudflare-stream' ) }</button>
				<# } #>
			</div>
		</div>
		<span class="setting" data-setting="title">
			<label for="cloudflare-stream-attachment-details-title" class="name">${ wp.i18n.__( 'Title', 'cloudflare-stream' ) }</label>
			<input type="text" id="cloudflare-stream-attachment-details-title" value="{{ data.title }}" {{ data.maybeReadOnly }} <# if ( ! data.can || ! data.can.save ) { #>disabled="disabled"<# } #> />
		</span>
	`;
	document.body.appendChild( script );
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

			ensureAttachmentDetailsTemplate();

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
					searchable: true,
					date: false,
					// Attachments with query:true mirror a Stream Query so search
					// and pagination hit admin-ajax instead of client filters only.
					library: new cloudflareStream.media.model.Attachments(
						null,
						{
							props: {
								query: true,
								type: 'video',
								orderby: 'date',
								order: 'DESC',
							},
						}
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
				} ),
			] );
		},

		bindHandlers() {
			let handlers, checkCounts;

			Post.prototype.bindHandlers.apply( this, arguments );

			this.on( 'activate', this.activate, this );
			this.on( 'content:render:browse', this.patchBrowserDetails, this );

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
		 * Use the Stream details view in the library browser sidebar.
		 *
		 * The browse render event supplies the browser view before it is
		 * attached to the frame, so the handler uses that argument.
		 *
		 * @param {wp.media.view.AttachmentsBrowser} browser Browser view.
		 * @return {void}
		 */
		patchBrowserDetails( browser ) {
			const content =
				browser ||
				( this.content && this.content.get
					? this.content.get()
					: null );
			if (
				! content ||
				typeof content.createSingle !== 'function' ||
				content._cfstreamDetailsPatched
			) {
				return;
			}

			content._cfstreamDetailsPatched = true;

			const frame = this;

			content.createSingle = function patchedCreateSingle() {
				const selection = this.options.selection;
				const single =
					selection && selection.single ? selection.single() : null;

				if ( ! single || ! this.sidebar ) {
					return;
				}

				this.sidebar.set(
					'details',
					new cloudflareStream.media.view.AttachmentDetails( {
						controller: this.controller,
						model: single,
						priority: 80,
					} )
				);

				// Stream attachments have no WordPress compat fields or display settings.
				this.sidebar.unset( 'compat' );
				this.sidebar.unset( 'display' );

				if ( this.model && this.model.id === 'insert' ) {
					this.sidebar.$el.addClass( 'visible' );
				}

				frame.trigger( 'selection:toggle' );
			};
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
