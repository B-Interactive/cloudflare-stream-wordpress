/**
 * Edit
 *
 * @package cloudflare-stream
 */

/* Common logic to generate stream URL */
import { streamIframeSource } from './lib';

/* Upload transport: direct-upload URL, TUS, encoding status */
import {
	fetchDirectUpload,
	tusUploadFile,
	checkUploadStatus,
} from './stream-upload';

/* global ajaxurl */
/* global cloudflareStream */

/**
 * WordPress dependencies
 */
import { sprintf, __ } from '@wordpress/i18n';
import {
	Disabled,
	Button,
	PanelBody,
	ToolbarGroup,
	ToggleControl,
	withNotices,
	Placeholder,
	FormFileUpload,
	ProgressBar,
} from '@wordpress/components';
import {
	BlockControls,
	InspectorControls,
	MediaUpload,
} from '@wordpress/block-editor';
import { Fragment, Component, createRef } from '@wordpress/element';

/**
 * Resolve the initial UI status from block attributes.
 *
 * @param {Object} attributes Block attributes.
 * @return {'idle'|'encoding'|'ready'} Initial status.
 */
function statusFromAttributes( attributes ) {
	if ( ! attributes.uid ) {
		return 'idle';
	}
	if ( ! attributes.thumbnail ) {
		return 'encoding';
	}
	return 'ready';
}

class CloudflareStreamEdit extends Component {
	constructor( props ) {
		super( props );

		this.state = {
			// status: 'idle' | 'uploading' | 'encoding' | 'ready' | 'error'
			status: statusFromAttributes( props.attributes ),
			progress: null,
			errorMessage: '',
		};

		this.instanceId = props.clientId;
		this.controller = this;
		this.streamPlayer = createRef();
		this.encodingPoller = null;
		this.upload = null;
		this.selectedFile = null;
		this.retried = false;

		this.toggleAttribute = this.toggleAttribute.bind( this );
		this.open = this.open.bind( this );
		this.select = this.select.bind( this );
		this.delete = this.delete.bind( this );
		this.cancel = this.cancel.bind( this );
		this.retry = this.retry.bind( this );
		this.onFileChange = this.onFileChange.bind( this );
		this.switchToEditing = this.switchToEditing.bind( this );
	}

	componentDidMount() {
		const { attributes } = this.props;

		if ( attributes.uid && ! attributes.thumbnail ) {
			this.switchToEncoding();
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
	}

	componentWillUnmount() {
		this.clearEncodingPoller();
		this.abortUpload();
	}

	/**
	 * Stop the encoding status poller if it is running.
	 */
	clearEncodingPoller() {
		if ( this.encodingPoller ) {
			clearTimeout( this.encodingPoller );
			this.encodingPoller = null;
		}
	}

	/**
	 * Abort an in-flight TUS upload, if any.
	 */
	abortUpload() {
		if ( this.upload && typeof this.upload.abort === 'function' ) {
			try {
				this.upload.abort();
			} catch ( error ) {
				// Upload may already be finished or torn down.
			}
		}
		this.upload = null;
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

		this.mediaFrame = this.mediaFrame || new cloudflareStream.media.view.MediaFrame(
			this.select
		);
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
		this.clearEncodingPoller();
		this.abortUpload();
		this.selectedFile = null;
		this.retried = false;
		this.setState( {
			status: 'ready',
			progress: null,
			errorMessage: '',
		} );
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

	/**
	 * User-facing instruction text for the current status.
	 *
	 * @return {string} Placeholder instructions.
	 */
	getInstructions() {
		const { status, errorMessage } = this.state;
		const instructions = {
			idle: __( 'Select a file from your library.', 'cloudflare-stream' ),
			uploading: __( 'Uploading your video.', 'cloudflare-stream' ),
			encoding: __(
				'Upload complete. Processing video.',
				'cloudflare-stream'
			),
			error: errorMessage,
		};

		return instructions[ status ] || instructions.idle;
	}

	/**
	 * Surface a failed upload or processing message via editor notices.
	 *
	 * @param {string} message User-facing error text.
	 * @param {*}      [cause] Optional raw error for the console.
	 */
	showUploadError( message, cause ) {
		const { noticeOperations } = this.props;
		const text =
			message ||
			__(
				'Upload Error: See the console for details.',
				'cloudflare-stream'
			);

		if ( cause !== undefined ) {
			console.error( cause );
		} else {
			console.error( text );
		}

		if ( noticeOperations ) {
			noticeOperations.removeAllNotices();
			noticeOperations.createErrorNotice( text );
		}

		this.setState( {
			status: 'error',
			progress: null,
			errorMessage: text,
		} );
	}

	/**
	 * Return to the empty/edit placeholder so the user can pick another file.
	 */
	retry() {
		const { noticeOperations } = this.props;

		this.clearEncodingPoller();
		this.abortUpload();
		this.retried = false;

		if ( noticeOperations ) {
			noticeOperations.removeAllNotices();
		}

		this.setState( {
			status: 'idle',
			progress: null,
			errorMessage: '',
		} );
	}

	/**
	 * Stop upload/encoding work and leave the edit UI.
	 */
	cancel() {
		const { attributes } = this.props;
		const { noticeOperations } = this.props;

		this.clearEncodingPoller();
		this.abortUpload();

		if ( noticeOperations ) {
			noticeOperations.removeAllNotices();
		}

		const nextStatus =
			attributes.uid && attributes.thumbnail ? 'ready' : 'idle';

		this.setState( {
			status: nextStatus,
			progress: null,
			errorMessage: '',
		} );
	}

	/**
	 * Open the edit placeholder (replace video).
	 */
	switchToEditing() {
		const { noticeOperations } = this.props;

		if ( noticeOperations ) {
			noticeOperations.removeAllNotices();
		}

		this.setState( {
			status: 'idle',
			progress: null,
			errorMessage: '',
		} );
	}

	/**
	 * Handle a file chosen via the upload control.
	 *
	 * @param {Event} event Change event from the file input.
	 */
	onFileChange( event ) {
		const input = event.currentTarget;
		const file =
			input && input.files && input.files.length ? input.files[ 0 ] : null;

		if ( ! file ) {
			return;
		}

		this.selectedFile = file;
		this.retried = false;
		this.startUpload( file );
	}

	/**
	 * Begin a TUS upload for the given file.
	 *
	 * @param {File} file Browser file object.
	 */
	startUpload( file ) {
		const { noticeOperations } = this.props;

		if ( noticeOperations ) {
			noticeOperations.removeAllNotices();
		}

		this.clearEncodingPoller();
		this.abortUpload();

		this.setState(
			{
				status: 'uploading',
				progress: null,
				errorMessage: '',
			},
			() => {
				this.uploadFromFiles( file );
			}
		);
	}

	/**
	 * Request a direct-upload URL and transfer the file with TUS.
	 *
	 * @param {File} file Browser file object.
	 */
	uploadFromFiles( file ) {
		const block = this;
		const { setAttributes } = this.props;

		if ( ! file ) {
			block.showUploadError(
				__(
					'Upload Error: See the console for details.',
					'cloudflare-stream'
				)
			);
			return;
		}

		fetchDirectUpload( file )
			.then( ( { uploadURL, uid } ) => {
				block.upload = tusUploadFile( file, uploadURL, {
					onError( error ) {
						block.upload = null;
						block.showUploadError(
							__(
								'Upload Error: See the console for details.',
								'cloudflare-stream'
							),
							error
						);
					},
					onProgress( bytesUploaded, bytesTotal ) {
						const percentage = parseInt(
							( bytesUploaded / bytesTotal ) * 100,
							10
						);
						block.setState( { progress: percentage } );
					},
					onSuccess( upload ) {
						const fingerprint =
							upload.options &&
							typeof upload.options.fingerprint === 'function'
								? upload.options.fingerprint(
										upload.file,
										upload.options
								  )
								: undefined;

						block.upload = null;
						setAttributes( {
							uid,
							fingerprint,
						} );
						block.switchToEncoding();
					},
				} );
			} )
			.catch( ( error ) => {
				block.showUploadError(
					error && error.message ? error.message : String( error ),
					error
				);
			} );
	}

	/**
	 * Move into the encoding/processing state and start polling.
	 */
	switchToEncoding() {
		this.setState(
			{
				status: 'encoding',
				progress: null,
				errorMessage: '',
			},
			() => {
				this.encode();
			}
		);
	}

	/**
	 * Poll Stream until the video is ready to play.
	 */
	encode() {
		const { attributes, setAttributes } = this.props;
		const block = this;

		checkUploadStatus( attributes.uid )
			.then( ( data ) => {
				if ( ! data.success ) {
					console.error( 'Error: ' + data.data );
					if (
						block.retried === false &&
						block.selectedFile
					) {
						block.retried = true;
						block.setState(
							{
								status: 'uploading',
								progress: null,
								errorMessage: '',
							},
							() => {
								block.uploadFromFiles( block.selectedFile );
							}
						);
					} else {
						block.showUploadError(
							sprintf(
								/* translators: %s: error detail from the API */
								__(
									'Processing Error: %s',
									'cloudflare-stream'
								),
								data.data
							)
						);
					}
					return;
				}

				if ( typeof data.data === 'undefined' ) {
					return;
				}

				if (
					data.data.readyToStream === true &&
					typeof data.data.thumbnail !== 'undefined'
				) {
					block.clearEncodingPoller();
					setAttributes( {
						thumbnail: data.data.thumbnail,
					} );
					block.setState( {
						status: 'ready',
						progress: null,
						errorMessage: '',
					} );
					return;
				}

				// Poll at a 5 second interval.
				block.encodingPoller = setTimeout( function () {
					block.encode();
				}, 5000 );

				if (
					data.data.status &&
					data.data.status.state === 'queued'
				) {
					block.setState( { progress: null } );
				} else if (
					data.data.status &&
					data.data.status.state === 'inprogress'
				) {
					const progress = Math.round(
						data.data.status.pctComplete
					);
					block.setState( { progress } );
				}
			} )
			.catch( ( error ) => {
				console.error(
					error && error.message ? error.message : String( error )
				);
			} );
	}

	/**
	 * Whether the cancel control should be shown.
	 *
	 * @return {boolean} True when cancel is available.
	 */
	canCancel() {
		const { attributes } = this.props;
		const { status } = this.state;

		if ( status === 'uploading' || status === 'encoding' ) {
			return true;
		}

		return Boolean( attributes.uid );
	}

	render() {
		const { autoplay, controls, loop, muted } = this.props.attributes;
		const { className, noticeUI } = this.props;
		const { status, progress } = this.state;
		const instructions = this.getInstructions();

		if ( status !== 'ready' ) {
			const showProgress =
				status === 'uploading' || status === 'encoding';
			const showRetry = status === 'error';
			const showUploadControls = status === 'idle';
			const canManage = Boolean( cloudflareStream.nonce );

			// Nonce is only localised for users who can manage Stream.
			if ( showUploadControls && ! canManage ) {
				return (
					// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore,Generic.Formatting.MultipleStatementAlignment,Generic.WhiteSpace.ScopeIndent.IncorrectExact
					<Placeholder
						icon={ cloudflareStream.icon }
						label={ __(
							'Cloudflare Stream',
							'cloudflare-stream'
						) }
						instructions={ instructions }
						className="editor-media-placeholder"
					>
						{ noticeUI }
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
						{ this.canCancel() && (
							<Button
								variant="secondary"
								icon="cancel"
								label={ __( 'Cancel', 'cloudflare-stream' ) }
								onClick={ this.cancel }
								className="editor-media-placeholder__cancel-button"
							>
								{ ' ' }
								{ __( 'Cancel', 'cloudflare-stream' ) }
							</Button>
						) }
					</Placeholder>
					// phpcs:enable
				);
			}

			return (
				// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore,Generic.Formatting.MultipleStatementAlignment,Generic.WhiteSpace.ScopeIndent.IncorrectExact
				<Placeholder
					icon={ cloudflareStream.icon }
					label={ __(
						'Cloudflare Stream',
						'cloudflare-stream'
					) }
					instructions={ instructions }
					className="editor-media-placeholder"
				>
					{ noticeUI }
					{ showProgress && (
						<div className="cloudflare-stream-progress-wrap">
							<ProgressBar
								value={ progress }
								className="cloudflare-stream-progress"
							/>
							{ null !== progress && (
								<p className="cloudflare-stream-progress__label">
									{ progress }%
								</p>
							) }
						</div>
					) }
					{ showUploadControls && canManage && (
						<FormFileUpload
							multiple
							className="editor-media-placeholder__upload-button"
							onChange={ this.onFileChange }
							accept="video/*"
						>
							{ __( 'Upload', 'cloudflare-stream' ) }
						</FormFileUpload>
					) }
					{ showUploadControls && (
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
					) }
					{ showRetry && (
						<Button
							variant="secondary"
							icon="update"
							label={ __( 'Retry', 'cloudflare-stream' ) }
							onClick={ this.retry }
							className="editor-media-placeholder__retry-button"
						>
							{ __( 'Retry', 'cloudflare-stream' ) }
						</Button>
					) }
					{ this.canCancel() && (
						<Button
							variant="secondary"
							icon="cancel"
							label={ __( 'Cancel', 'cloudflare-stream' ) }
							onClick={ this.cancel }
							className="editor-media-placeholder__cancel-button"
						>
							{ ' ' }
							{ __( 'Cancel', 'cloudflare-stream' ) }
						</Button>
					) }
				</Placeholder>
				// phpcs:enable
			);
		}

		return (
			// phpcs:disable WordPress.WhiteSpace.OperatorSpacing.NoSpaceAfter,WordPress.WhiteSpace.OperatorSpacing.NoSpaceBefore,Generic.Formatting.MultipleStatementAlignment,Generic.WhiteSpace.ScopeIndent.IncorrectExact
			<Fragment>
				<BlockControls>
					<ToolbarGroup>
						<Button
							className="components-icon-button components-toolbar__control"
							label={ __(
								'Edit video',
								'cloudflare-stream'
							) }
							onClick={ this.switchToEditing }
							icon="edit"
						/>
					</ToolbarGroup>
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
								ref={ this.streamPlayer }
								src={ streamIframeSource(
									this.props.attributes
								) }
								title={ __(
									'Cloudflare Stream video',
									'cloudflare-stream'
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
