/**
 * Stream library attachment details sidebar.
 *
 * @package cloudflare-stream
 */

import { streamAjax } from '../../lib/ajax';
import { userCanManageStream } from '../../lib/capabilities';

/* global cloudflareStream */

const AttachmentDetails = wp.media.view.Attachment.Details;
const l10n = wp.media.view.l10n;

/**
 * Details sidebar for a Stream video in the library frame.
 *
 * Renders only the fields Stream supports and routes rename/delete through
 * the plugin AJAX handlers instead of WordPress attachment posts.
 *
 * @class
 * @augments wp.media.view.Attachment.Details
 */
cloudflareStream.media.view.AttachmentDetails = AttachmentDetails.extend( {
	className: 'attachment-details cloudflare-stream-attachment-details',

	template: wp.template( 'cloudflare-stream-attachment-details' ),

	events: {
		'change [data-setting="title"] input': 'updateTitle',
		'click .delete-attachment': 'deleteAttachment',
		keydown: 'toggleSelectionHandler',
	},

	/**
	 * @return {void}
	 */
	initialize() {
		this.canManage = userCanManageStream();
		this.options = _.defaults( this.options, {
			rerenderOnModelChange: false,
		} );

		// Skip core clipboard wiring; this template has no copy-url control.
		wp.media.view.Attachment.prototype.initialize.apply( this, arguments );
	},

	/**
	 * Build template data for the Stream details view.
	 *
	 * @return {Object} Template data.
	 */
	prepare() {
		const data = _.defaults( this.model.toJSON(), {
			orientation: 'landscape',
			uploading: false,
			type: 'video',
			subtype: 'mp4',
			icon: '',
			filename: '',
			title: '',
			dateFormatted: '',
			filesizeHumanReadable: '',
			fileLength: '',
			fileLengthHumanReadable: '',
			image: null,
			previewUnavailable: false,
			previewMessage: '',
			playbackReason: '',
		} );

		data.can = {
			remove: this.canManage && ! data.uploading,
			save: this.canManage && ! data.uploading,
		};
		data.allowLocalEdits = this.canManage && ! data.uploading;
		data.maybeReadOnly = data.can.save || data.allowLocalEdits ? '' : 'readonly';

		return data;
	},

	/**
	 * Render the Stream details template.
	 *
	 * Core Attachment.render builds media-post fields; this view replaces that
	 * path so only Stream-supported controls are shown.
	 *
	 * @return {wp.media.View} This view.
	 */
	render() {
		const options = this.prepare();

		this.views.detach();
		this.$el.html( this.template( options ) );
		this.$el.toggleClass( 'uploading', !! options.uploading );
		this.updateSelect();
		this.updateSave();
		this.views.render();

		return this;
	},

	/**
	 * Persist a title change to Cloudflare and the grid model.
	 *
	 * @param {Event} event Change event.
	 * @return {void}
	 */
	updateTitle( event ) {
		if ( ! this.canManage ) {
			return;
		}

		const target = event && event.target ? event.target : null;
		const setting = target
			? target.closest( '[data-setting="title"]' )
			: null;
		if ( ! setting ) {
			return;
		}

		const input =
			target && 'value' in target
				? target
				: setting.querySelector( 'input' );
		const newTitle =
			input && typeof input.value === 'string' ? input.value.trim() : '';
		const currentTitle = ( this.model.get( 'title' ) || '' ).toString();

		if ( ! newTitle || newTitle === currentTitle ) {
			if ( input && ! newTitle ) {
				input.value = currentTitle;
			}
			return;
		}

		const uid = this.model.get( 'uid' ) || this.model.get( 'id' );
		const cloudflare = this.model.get( 'cloudflare' ) || {};
		const upload =
			cloudflare.meta && cloudflare.meta.upload
				? cloudflare.meta.upload
				: '';

		this.updateSave( 'waiting' );

		streamAjax( 'cloudflare-stream-update', {
			uid,
			title: newTitle,
			upload,
		} )
			.then( () => {
				this.model.set( {
					title: newTitle,
					filename: newTitle,
				} );
				this.updateSave( 'complete' );
				if ( wp.a11y && typeof wp.a11y.speak === 'function' ) {
					wp.a11y.speak(
						wp.i18n.__( 'Saved.', 'cloudflare-stream' )
					);
				}
				window.setTimeout( () => {
					this.updateSave( 'ready' );
				}, 2000 );
			} )
			.catch( ( err ) => {
				// eslint-disable-next-line no-console
				console.error(
					'Error: ',
					err && err.message ? err.message : err
				);
				if ( input ) {
					input.value = currentTitle;
				}
				this.updateSave( 'error' );
				if ( wp.a11y && typeof wp.a11y.speak === 'function' ) {
					wp.a11y.speak(
						wp.i18n.__(
							'Could not update video.',
							'cloudflare-stream'
						)
					);
				}
				window.setTimeout( () => {
					this.updateSave( 'ready' );
				}, 2000 );
			} );
	},

	/**
	 * Confirm and delete the selected Stream video via the frame.
	 *
	 * @param {Event} event Click event.
	 * @return {void}
	 */
	deleteAttachment( event ) {
		event.preventDefault();
		event.stopPropagation();

		if ( ! this.canManage ) {
			return;
		}

		this.getFocusableElements();

		/* eslint-disable no-alert */
		if ( ! window.confirm( l10n.warnDelete ) ) {
			return;
		}
		/* eslint-enable no-alert */

		const attachment = this.model.toJSON();
		attachment._selectionModel = this.model;

		// Frame owns the Cloudflare delete request and selection cleanup.
		this.controller.trigger( 'delete', attachment );
		this.moveFocus();
	},
} );
