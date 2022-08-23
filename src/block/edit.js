/* Necessary to use TUS protocol for uploads */
import * as tus from 'tus-js-client';

/* global ajaxurl */
/* global cloudflareStream */

/**
 * WordPress dependencies
 */
const { __ } = wp.i18n; // Import __() from wp.i18n
const {
	Disabled,
	Button,
	PanelBody,
	Toolbar,
	ToggleControl,
	withNotices,
	Placeholder,
	FormFileUpload,
} = wp.components;
const {
	BlockControls,
	InspectorControls,
} = wp.editor;
const {
    MediaUpload,
} = wp.blockEditor;
const { Fragment, Component, createRef } = wp.element;

class CloudflareStreamEdit extends Component {
	constructor( instanceId ) {
		super( ...arguments );

		this.state = {
			editing: ! this.props.attributes.uid,
			uploading: false,
			encoding: this.props.attributes.uid && ! this.props.attributes.thumbnail,
			resume: true,
		};

		this.instanceId = instanceId.clientId;
		this.controller = this;
		this.streamPlayer = createRef();
		this.toggleAttribute = this.toggleAttribute.bind( this );
		this.open = this.open.bind( this );
		this.select = this.select.bind( this );
		this.mediaFrame = new cloudflareStream.media.view.MediaFrame( this.select );
		this.encodingPoller = false;
	}

	componentDidMount() {
		const { attributes } = this.props;

		if ( attributes.uid !== false && attributes.thumbnail === false ) {
			this.switchToEncoding();
		} else {
			this.reload();
		}
	}

	componentDidUpdate() {
		const { attributes } = this.props;
		const streamPlayer = this.streamPlayer.current;

		if ( streamPlayer !== null && streamPlayer.play !== null ) {
			streamPlayer.autoPlay = attributes.autoplay;
			streamPlayer.controls = attributes.controls;
			streamPlayer.mute = attributes.mute;
			streamPlayer.loop = attributes.loop;
			streamPlayer.controls = attributes.controls;

			if ( attributes.autoplay && typeof streamPlayer.play === 'function' ) {
				streamPlayer.play();
			} else if ( typeof streamPlayer.pause === 'function' ) {
				streamPlayer.pause();
			}
		}

		if ( false !== attributes.uid ) {
			jQuery( '#block-' + this.instanceId + ' .editor-media-placeholder__cancel-button' ).show();
			this.reload();
		}
	}

	toggleAttribute( attribute ) {
		const { setAttributes } = this.props;
		return ( newValue ) => {
			setAttributes( { [ attribute ]: newValue } );
		};
	}

	open() {
		const block = this;

		this.mediaFrame.open();

		this.mediaFrame.on( 'delete', function( attachment ) {
			block.delete( attachment );
		} );
		this.mediaFrame.on( 'select', function() {
			block.select();
		} );
	}

	select( attachment ) {
		const { setAttributes } = this.props;
		setAttributes( { uid: attachment.uid, thumbnail: attachment.thumb.src } );
		this.setState( { editing: false, uploading: false, encoding: false } );
		this.reload();
	}

	delete( attachment ) {
		jQuery.ajax( {
			url: ajaxurl + '?action=cloudflare-stream-delete',
			data: {
				nonce: cloudflareStream.nonce,
				uid: attachment.uid,
			},
			success: function() {
				jQuery( 'li[data-id="' + attachment.id + '"]' ).hide();
			},
			error: function( jqXHR, textStatus ) {
				console.error( 'Error: ' + textStatus );
			},
		} );
	}

	update( attachment ) {
		jQuery( '.settings-save-status .media-frame .spinner' ).css( 'visibility', 'visible' );
		jQuery.ajax( {
			url: ajaxurl + '?action=cloudflare-stream-update',
			method: 'POST',
			data: {
				nonce: cloudflareStream.nonce,
				uid: attachment.uid,
				title: attachment.title,
			},
			success: function() {
				jQuery( 'li[data-id="' + attachment.id + '"]' ).hide();
			},
			error: function( jqXHR, textStatus ) {
				console.error( 'Error: ' + textStatus );
			},
		} );
	}

	reload() {
		const { attributes } = this.props;
		const link = 'https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=' + attributes.uid;

		jQuery.getScript( link ).fail( function( jqxhr, settings, exception ) {
			console.error( 'Exception:' + exception );
		} );
	}

	uploadFromFiles( file ) {
		const block = this;
		const { setAttributes } = this.props;

		const progressBar = jQuery( '#progressbar-' + this.instanceId );
		const progressLabel = jQuery( '.progress-label-' + this.instanceId );
		const val = progressBar.progressbar( 'value' ) || 0;

		progressBar.progressbar( 'value', val );

		const baseUrl = 'https://api.cloudflare.com/client/v4/accounts/' + cloudflareStream.api.account + '/media';

		const upload = new tus.Upload( file, {
			resume: block.state.resume,
			removeFingerprintOnSuccess: true,
			endpoint: baseUrl,
			retryDelays: [ 0, 1000, 3000, 5000 ],
			headers: {
				'X-Auth-Email': cloudflareStream.api.email,
				'X-Auth-Key': cloudflareStream.api.key,
			},
			metadata: {
				name: file.name,
				type: file.type,
			},
			onError: function( error ) {
				console.error( 'Error: ' + error );
				progressBar.hide();
				jQuery( '.editor-media-placeholder .components-placeholder__instructions' ).html( 'Upload Error: See the console for details.' );
				jQuery( '.editor-media-placeholder__retry-button' ).show();
			},
			onProgress: function( bytesUploaded, bytesTotal ) {
				const percentage = parseInt( bytesUploaded / bytesTotal * 100 );

				progressLabel.text( percentage + '%' );
				progressBar.progressbar( 'option', 'value', percentage );
			},
			onSuccess: function() {
				const urlArray = upload.url.split( '/' );
				const mediaId = urlArray[ urlArray.length - 1 ];

				setAttributes( { uid: mediaId, fingerprint: upload.options.fingerprint( upload.file, upload.options ) } );
				block.switchToEncoding();
			},
		} );
		//Start the upload
		upload.start();
	}

	switchToEncoding() {
		const block = this;
		block.setState(
			{ editing: true, uploading: false, encoding: true },
			() => {
				const progressBar = jQuery( '#progressbar-' + this.instanceId );
				const progressLabel = jQuery( '.progress-label-' + this.instanceId );
				jQuery( '.editor-media-placeholder .components-placeholder__instructions' ).html( 'Upload Complete. Processing video.' );

				progressLabel.text( '' );
				progressBar.progressbar( {
					value: false,
				} );
				block.encode();
			}
		);
	}

	encode() {
		const { attributes, setAttributes } = this.props;
		const block = this;
		const progressBar = jQuery( '#progressbar-' + this.instanceId );
		const progressLabel = jQuery( '.progress-label-' + this.instanceId );

		const { file } = this.props.attributes;

		jQuery.ajax( {
			url: ajaxurl + '?action=cloudflare-stream-check-upload',
			data: {
				nonce: cloudflareStream.nonce,
				uid: attributes.uid,
			},
			success: function( data ) {
				if ( ! data.success ) {
					console.error( 'Error: ' + data.data );
					if ( block.state.resume === true ) {
						block.setState(
							{ resume: false },
						);
						jQuery( '.editor-media-placeholder .components-placeholder__instructions' ).html( 'Uploading your video.' );
						block.uploadFromFiles( file );
					} else {
						progressBar.hide();
						jQuery( '.editor-media-placeholder .components-placeholder__instructions' ).html( 'Processing Error: ' + data.data );
						jQuery( '.editor-media-placeholder__retry-button' ).show();
					}
				} else if ( typeof data.data !== 'undefined' ) {
					if ( data.data.readyToStream === true && typeof data.data.thumbnail !== 'undefined' ) {
						clearTimeout( block.encodingPoller );
						setAttributes( { thumbnail: data.data.thumbnail } );
						block.setState( { editing: false, uploading: false, encoding: false } );
					} else {
						// Poll at a 5 second interval
						block.encodingPoller = setTimeout( function() {
							block.encode();
						}, 5000 );
					}
					if ( data.data.status.state === 'queued' ) {
						progressLabel.text( '' );
						progressBar.progressbar( {
							value: false,
						} );
					} else if ( data.data.status.state === 'inprogress' ) {
						const progress = Math.round( data.data.status.pctComplete );
						progressLabel.text( progress + '%' );

						progressBar.progressbar( {
							value: progress,
						} );
					}
					block.reload();
				}
			},
			error: function( jqXHR, textStatus ) {
				console.error( 'Error: ' + textStatus );
			},
		} );
	}

	render() {
		const {
			uid,
			autoplay,
			controls,
			loop,
			muted,
		} = this.props.attributes;
		const { className } = this.props;
		const { editing, uploading, encoding } = this.state;

		const switchToEditing = () => {
			this.setState( { editing: true } );
			this.setState( { uploading: false } );
			this.setState( { encoding: false } );
		};

		const switchFromEditing = () => {
			this.setState( { editing: false } );
			this.setState( { uploading: false } );
			this.setState( { encoding: false } );
		};

		const switchToUploading = () => {
			let { setAttributes } = this.props;

			jQuery( '.editor-media-placeholder .components-placeholder__instructions' ).html( 'Processing your video' );

			const file = jQuery( '.components-form-file-upload :input[type=\'file\']' )[ 0 ].files[ 0 ];
			setAttributes( { file: file } );

			const block = this;
			block.setState(
				{ editing: true, uploading: true, encoding: false },
				() => {
					const progressBar = jQuery( '#progressbar-' + this.instanceId );

					progressBar.progressbar( {
						value: false,
					} );
					block.uploadFromFiles( file );
				}
			);
		};

		if ( editing ) {
			if ( uploading ) {
				const progressBarStyle = {
					width: '100%',
				};
				return (
					<Placeholder
						icon={ cloudflareStream.icon }
						label="Cloudflare Stream"
						instructions="Uploading your video."
						className="editor-media-placeholder"
					>
						<div id={ 'progressbar-' + this.instanceId } style={ progressBarStyle }>
							<div className={ 'progress-label progress-label-' + this.instanceId }>Connecting...</div>
						</div>
						<Button
							isSecondary
							icon="update"
							label={ __( 'Retry' ) }
							onClick={ switchToEditing }
							style={ { display: 'none' } }
							className="editor-media-placeholder__retry-button"
						>
							{ __( 'Retry' ) }
						</Button>
					</Placeholder>
				);
			} else if ( encoding ) {
				const progressBarStyle = {
					width: '100%',
				};
				return (
					<Placeholder
						icon={ cloudflareStream.icon }
						label="Cloudflare Stream"
						instructions="Processing your video."
						className="editor-media-placeholder"
					>
						<div id={ 'progressbar-' + this.instanceId } style={ progressBarStyle }>
							<div className={ 'progress-label progress-label-' + this.instanceId }>Connecting...</div>
						</div>
						<Button
							isSecondary
							icon="update"
							label={ __( 'Retry' ) }
							onClick={ switchToEditing }
							style={ { display: 'none' } }
							className="editor-media-placeholder__retry-button"
						>
							{ __( 'Retry' ) }
						</Button>
					</Placeholder>
				);
			}

			if ( ! cloudflareStream.api.key || '' === cloudflareStream.api.key ) {
				return (
					<Placeholder
						icon={ cloudflareStream.icon }
						label="Cloudflare Stream"
						instructions="Select a file from your library."
					>
						<MediaUpload
							type="video"
							className={ className }
							value={ this.props.attributes }
							render={ () => (
								<Button
									label={ __( 'Stream Library' ) }
									onClick={ this.open }
									className="editor-media-placeholder__browse-button"
								>
									{ __( 'Stream Library' ) }
								</Button>
							) }
						/>
						<Button
							isSecondary
							icon="cancel"
							label={ __( 'Cancel' ) }
							onClick={ switchFromEditing }
							style={ { display: 'none' } }
							className="editor-media-placeholder__cancel-button"
						>
							{ __( 'Cancel' ) }
						</Button>
					</Placeholder>
				);
			}

			return (
				<Placeholder
					icon={ cloudflareStream.icon }
					label="Cloudflare Stream"
					instructions="Select a file from your library."
				>
					<FormFileUpload
						multiple
						className="editor-media-placeholder__upload-button"
						onChange={ switchToUploading }
						accept="video/*"
					>
						{ __( 'Upload' ) }
					</FormFileUpload>
					<MediaUpload
						type="video"
						className={ className }
						value={ this.props.attributes }
						render={ () => (
							<Button
								label={ __( 'Stream Library' ) }
								onClick={ this.open }
								className="editor-media-placeholder__browse-button"
							>
								{ __( 'Stream Library' ) }
							</Button>
						) }
					/>
					<Button
						isSecondary
						icon="cancel"
						label={ __( 'Cancel' ) }
						onClick={ switchFromEditing }
						style={ { display: 'none' } }
						className="editor-media-placeholder__cancel-button"
					>
						{ __( 'Cancel' ) }
					</Button>
				</Placeholder>
			);
		}

		/* eslint-disable jsx-a11y/no-static-element-interactions, jsx-a11y/onclick-has-role, jsx-a11y/click-events-have-key-events */
		return (
			<Fragment>
				<BlockControls>
					<Toolbar>
						<Button
							className="components-icon-button components-toolbar__control"
							label={ __( 'Edit video' ) }
							onClick={ switchToEditing }
							icon="edit"
						/>
					</Toolbar>
				</BlockControls>
				<InspectorControls>
					<PanelBody title={ __( 'Video Settings' ) }>
						<ToggleControl
							label={ __( 'Autoplay' ) }
							onChange={ this.toggleAttribute( 'autoplay' ) }
							checked={ autoplay }
						/>
						<ToggleControl
							label={ __( 'Loop' ) }
							onChange={ this.toggleAttribute( 'loop' ) }
							checked={ loop }
						/>
						<ToggleControl
							label={ __( 'Muted' ) }
							onChange={ this.toggleAttribute( 'muted' ) }
							checked={ muted }
						/>
						<ToggleControl
							label={ __( 'Playback Controls' ) }
							onChange={ this.toggleAttribute( 'controls' ) }
							checked={ controls }
						/>
					</PanelBody>
				</InspectorControls>
				<figure className={ className }>
					<Disabled>
						{ <stream
							src={ uid }
							controls={ controls }
							autoPlay={ autoplay }
							loop={ loop }
							muted={ muted }
							ref={ this.streamPlayer }
						></stream> }
						{ /*<img src={ thumbnail } alt="Cloudflare Stream Video" /> */ }
					</Disabled>
				</figure>
			</Fragment>
		);
		/* eslint-enable jsx-a11y/no-static-element-interactions, jsx-a11y/onclick-has-role, jsx-a11y/click-events-have-key-events */
	}
}
export default withNotices( CloudflareStreamEdit );
