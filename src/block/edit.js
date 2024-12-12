/**
 * Edit
 *
 * @package
 */

/* Necessary to use TUS protocol for uploads */
import * as tus from 'tus-js-client';

/* Common logic to generate stream URL */
import { streamIframeSource } from './lib';

/* global ajaxurl */
/* global cloudflareStream */

/**
 * WordPress dependencies
 */
const { sprintf, __ } = wp.i18n; // Import __() from wp.i18n.
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
const { BlockControls, InspectorControls } = wp.editor;
const { MediaUpload } = wp.blockEditor;
const { Fragment, Component, createRef } = wp.element;

class CloudflareStreamEdit extends Component {
	constructor( instanceId ) {
		super( ...arguments );

		this.state = {
			editing: ! this.props.attributes.uid,
			uploading: false,
			encoding:
				this.props.attributes.uid && ! this.props.attributes.thumbnail,
			resume: true,
		};

		this.instanceId = instanceId.clientId;
		this.controller = this;
		this.streamPlayer = createRef();
		this.toggleAttribute = this.toggleAttribute.bind( this );
		this.open = this.open.bind( this );
		this.select = this.select.bind( this );
		this.mediaFrame = new cloudflareStream.media.view.MediaFrame(
			this.select
		);
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

			if (
				attributes.autoplay &&
				typeof streamPlayer.play === 'function'
			) {
				streamPlayer.play();
			} else if ( typeof streamPlayer.pause === 'function' ) {
				streamPlayer.pause();
			}
		}

		if ( false !== attributes.uid ) {
			jQuery(
				'#block-' +
					this.instanceId +
					' .editor-media-placeholder__cancel-button'
			).show();
			this.reload();
		}
	}

	toggleAttribute( attribute ) {
		const { setAttributes } = this.props;
		return ( newValue ) => {
			setAttributes( {
				[ attribute ]: newValue,
			} );
		};
	}

	open() {
		const block = this;

		this.mediaFrame.open();

		this.mediaFrame.on( 'delete', function ( attachment ) {
			block.delete( attachment );
		} );
		this.mediaFrame.on( 'select', function () {
			block.select();
		} );
	}

	select( attachment ) {
		const { setAttributes } = this.props;
		setAttributes( {
			uid: attachment.uid,
			thumbnail: attachment.thumb.src,
		} );
		this.setState( {
			editing: false,
			uploading: false,
			encoding: false,
		} );
		this.reload();
	}

	delete( attachment ) {
		jQuery.ajax( {
			url: ajaxurl + '?action=cloudflare-stream-delete',
			data: {
				nonce: cloudflareStream.nonce,
				uid: attachment.uid,
			},
			success() {
				jQuery( 'li[data-id="' + attachment.id + '"]' ).hide();
			},
			error( jqXHR, textStatus ) {
				console.error( 'Error: ' + textStatus );
			},
		} );
	}

	update( attachment ) {
		jQuery( '.settings-save-status .media-frame .spinner' ).css(
			'visibility',
			'visible'
		);
		jQuery.ajax( {
			url: ajaxurl + '?action=cloudflare-stream-update',
			method: 'POST',
			data: {
				nonce: cloudflareStream.nonce,
				uid: attachment.uid,
				title: attachment.title,
			},
			success() {
				jQuery( 'li[data-id="' + attachment.id + '"]' ).hide();
			},
			error( jqXHR, textStatus ) {
				console.error( 'Error: ' + textStatus );
			},
		} );
	}

	reload() {
		const { attributes } = this.props;
		const link =
			'https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=' +
			attributes.uid;

		jQuery.getScript( link ).fail( function ( jqxhr, settings, exception ) {
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

		const baseUrl =
			'https://api.cloudflare.com/client/v4/accounts/' +
			cloudflareStream.api.account +
			'/media';

		const upload = new tus.Upload( file, {
			removeFingerprintOnSuccess: true,
			endpoint: baseUrl,
			retryDelays: [ 0, 1000, 3000, 5000 ],
			headers: {
				Authorization: 'Bearer ' + cloudflareStream.api.token,
			},
			metadata: {
				name: file.name,
				type: file.type,
			},
			onError( error ) {
				console.error( 'Error: ' + error );
				progressBar.hide();
				jQuery(
					'.editor-media-placeholder .components-placeholder__instructions'
				).html(
					__(
						'Upload Error: See the console for details.',
						'cloudflare-stream'
					)
				);
				jQuery( '.editor-media-placeholder__retry-button' ).show();
			},
			onProgress( bytesUploaded, bytesTotal ) {
				const percentage = parseInt(
					( bytesUploaded / bytesTotal ) * 100
				);

				progressLabel.text( percentage + '%' );
				progressBar.progressbar( 'option', 'value', percentage );
			},
			onSuccess() {
				const urlArray = upload.url.split( '/' );
				const mediaId =
					urlArray[ urlArray.length - 1 ].split( '?' )[ 0 ];

				setAttributes( {
					uid: mediaId,
					fingerprint: upload.options.fingerprint(
						upload.file,
						upload.options
					),
				} );
				block.switchToEncoding();
			},
		} );

		// Start the upload.
		upload.start();
	}

	switchToEncoding() {
		const block = this;
		block.setState(
			{
				editing: true,
				uploading: false,
				encoding: true,
			},
			() => {
				const progressBar = jQuery( '#progressbar-' + this.instanceId );
				const progressLabel = jQuery(
					'.progress-label-' + this.instanceId
				);
				jQuery(
					'.editor-media-placeholder .components-placeholder__instructions'
				).html(
					__(
						'Upload Complete. Processing video.',
						'cloudflare-stream'
					)
				);
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
			success( data ) {
				if ( ! data.success ) {
					console.error( 'Error: ' + data.data );
					if ( block.state.resume === true ) {
						block.setState( {
							resume: false,
						} );
						jQuery(
							'.editor-media-placeholder .components-placeholder__instructions'
						).html(
							__(
								'Uploading your video.',
								'cloudflare-stream'
							)
						);
						block.uploadFromFiles( file );
					} else {
						progressBar.hide();
						jQuery(
							'.editor-media-placeholder .components-placeholder__instructions'
						).html(
							sprintf(
								__(
									'Processing Error: %s',
									'cloudflare-stream'
								),
								data.data
							)
						);
						jQuery(
							'.editor-media-placeholder__retry-button'
						).show();
					}
				} else if ( typeof data.data !== 'undefined' ) {
					if (
						data.data.readyToStream === true &&
						typeof data.data.thumbnail !== 'undefined'
					) {
						clearTimeout( block.encodingPoller );
						setAttributes( {
							thumbnail: data.data.thumbnail,
						} );
						block.setState( {
							editing: false,
							uploading: false,
							encoding: false,
						} );
					} else {
						// Poll at a 5 second interval.
						block.encodingPoller = setTimeout( function () {
							block.encode();
						}, 5000 );
					}
					if ( data.data.status.state === 'queued' ) {
						progressLabel.text( '' );
						progressBar.progressbar( {
							value: false,
						} );
					} else if ( data.data.status.state === 'inprogress' ) {
						const progress = Math.round(
							data.data.status.pctComplete
						);
						progressLabel.text( progress + '%' );

						progressBar.progressbar( {
							value: progress,
						} );
					}
					block.reload();
				}
			},
			error( jqXHR, textStatus ) {
				console.error( 'Error: ' + textStatus );
			},
		} );
	}

	render() {
		const { uid, autoplay, controls, loop, muted } = this.props.attributes;
		const { className } = this.props;
		const { editing, uploading, encoding } = this.state;

		const switchToEditing = () => {
			this.setState( {
				editing: true,
			} );
			this.setState( {
				uploading: false,
			} );
			this.setState( {
				encoding: false,
			} );
		};

		const switchFromEditing = () => {
			this.setState( {
				editing: false,
			} );
			this.setState( {
				uploading: false,
			} );
			this.setState( {
				encoding: false,
			} );
		};

		const switchToUploading = () => {
			const { setAttributes } = this.props;

			jQuery(
				'.editor-media-placeholder .components-placeholder__instructions'
			).html(
				__( 'Processing your video', 'cloudflare-stream' )
			);

			const file = jQuery(
				".components-form-file-upload :input[type='file']"
			)[ 0 ].files[ 0 ];
			setAttributes( {
				file,
			} );

			const block = this;
			block.setState(
				{
					editing: true,
					uploading: true,
					encoding: false,
				},
				() => {
					const progressBar = jQuery(
						'#progressbar-' + this.instanceId
					);

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
					// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore
					<Placeholder
						icon={ cloudflareStream.icon }
						label={ __(
							'Cloudflare Stream',
							'cloudflare-stream'
						) }
						instructions={ __(
							'Uploading your video.',
							'cloudflare-stream'
						) }
						className="editor-media-placeholder"
					>
						<div
							id={ 'progressbar-' + this.instanceId }
							style={ progressBarStyle }
						>
							<div
								className={
									'progress-label progress-label-' +
									this.instanceId
								}
							>
								Connecting...
							</div>
						</div>
						<Button
							isSecondary
							icon="update"
							label={ __( 'Retry', 'cloudflare-stream' ) }
							onClick={ switchToEditing }
							style={ {
								display: 'none',
							} }
							className="editor-media-placeholder__retry-button"
						>
							{ __( 'Retry', 'cloudflare-stream' ) }
						</Button>
					</Placeholder>
					// phpcs:enable
				);
			} else if ( encoding ) {
				const progressBarStyle = {
					width: '100%',
				};
				return (
					// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore
					<Placeholder
						icon={ cloudflareStream.icon }
						label={ __(
							'Cloudflare Stream',
							'cloudflare-stream'
						) }
						instructions={ __(
							'Processing your video.',
							'cloudflare-stream'
						) }
						className="editor-media-placeholder"
					>
						<div
							id={ 'progressbar-' + this.instanceId }
							style={ progressBarStyle }
						>
							<div
								className={
									'progress-label progress-label-' +
									this.instanceId
								}
							>
								Connecting...
							</div>
						</div>
						<Button
							isSecondary
							icon="update"
							label={ __(
								'Retry',
								'cloudflare-stream'
							) }
							onClick={ switchToEditing }
							style={ {
								display: 'none',
							} }
							className="editor-media-placeholder__retry-button"
						>
							{ __( 'Retry', 'cloudflare-stream' ) }
						</Button>
					</Placeholder>
					// phpcs:enable
				);
			}

			if (
				! cloudflareStream.api.token ||
				'' === cloudflareStream.api.token
			) {
				return (
					// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore
					<Placeholder
						icon={ cloudflareStream.icon }
						label={ __(
							'Cloudflare Stream',
							'cloudflare-stream'
						) }
						instructions={ __(
							'Select a file from your library.',
							'cloudflare-stream'
						) }
					>
						<MediaUpload
							type="video"
							className={ className }
							value={ this.props.attributes }
							render={ () => (
								<Button
									label={ __(
										'Stream Library',
										'cloudflare-stream'
									) }
									onClick={ this.open }
									className="editor-media-placeholder__browse-button"
								>
									{ ' ' }
									{ __(
										'Stream Library',
										'cloudflare-stream'
									) }
								</Button>
							) }
						/>
						<Button
							isSecondary
							icon="cancel"
							label={ __( 'Cancel' ) }
							onClick={ switchFromEditing }
							style={ {
								display: 'none',
							} }
							className="editor-media-placeholder__cancel-button"
						>
							{ ' ' }
							{ __( 'Cancel', 'cloudflare-stream' ) }
						</Button>
					</Placeholder>
					// phpcs:enable
				);
			}
			return (
				// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore
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
						{ __( 'Upload', 'cloudflare-stream' ) }
					</FormFileUpload>
					<MediaUpload
						type="video"
						className={ className }
						value={ this.props.attributes }
						render={ () => (
							<Button
								label={ __(
									'Stream Library',
									'cloudflare-stream'
								) }
								onClick={ this.open }
								className="editor-media-placeholder__browse-button"
							>
								{ ' ' }
								{ __(
									'Stream Library',
									'cloudflare-stream'
								) }
							</Button>
						) }
					/>
					<Button
						isSecondary
						icon="cancel"
						label={ __( 'Cancel', 'cloudflare-stream' ) }
						onClick={ switchFromEditing }
						style={ {
							display: 'none',
						} }
						className="editor-media-placeholder__cancel-button"
					>
						{ ' ' }
						{ __( 'Cancel', 'cloudflare-stream' ) }
					</Button>
				</Placeholder>
				// phpcs:enable
			);
		}
		return (
			// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore
			<Fragment>
				<BlockControls>
					<Toolbar>
						<Button
							className="components-icon-button components-toolbar__control"
							label={ __(
								'Edit video',
								'cloudflare-stream'
							) }
							onClick={ switchToEditing }
							icon="edit"
						/>
					</Toolbar>
				</BlockControls>
				<InspectorControls>
					<PanelBody
						title={ __(
							'Video Settings',
							'cloudflare-stream'
						) }
					>
						<ToggleControl
							label={ __(
								'Autoplay',
								'cloudflare-stream'
							) }
							onChange={ this.toggleAttribute( 'autoplay' ) }
							checked={ autoplay }
						/>
						<ToggleControl
							label={ __(
								'Loop',
								'cloudflare-stream'
							) }
							onChange={ this.toggleAttribute( 'loop' ) }
							checked={ loop }
						/>
						<ToggleControl
							label={ __(
								'Muted',
								'cloudflare-stream'
							) }
							onChange={ this.toggleAttribute( 'muted' ) }
							checked={ muted }
						/>
						<ToggleControl
							label={ __(
								'Playback Controls',
								'cloudflare-stream'
							) }
							onChange={ this.toggleAttribute( 'controls' ) }
							checked={ controls }
						/>
					</PanelBody>
				</InspectorControls>
				<figure className={ className }>
					<Disabled className="player-edit-wrapper">
						{ ' ' }
						{
							<iframe
								src={ streamIframeSource(
									this.props.attributes
								) }
							></iframe>
						}
					</Disabled>
				</figure>
			</Fragment>
			// phpcs:enable
		);
	}
}
export default withNotices( CloudflareStreamEdit );
