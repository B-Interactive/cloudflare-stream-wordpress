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

/** Transient poll failures before surfacing an error. */
const ENCODE_POLL_MAX_ATTEMPTS = 3;

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

/**
 * Turn API/ajax error payloads into a readable string.
 *
 * @param {*} value Raw error value.
 * @return {string} User-facing message text.
 */
function normaliseErrorMessage( value ) {
	if ( value == null || value === '' ) {
		return '';
	}
	if ( typeof value === 'string' ) {
		return value;
	}
	if ( typeof value === 'number' || typeof value === 'boolean' ) {
		return String( value );
	}
	if ( value instanceof Error && value.message ) {
		return value.message;
	}
	if ( typeof value === 'object' ) {
		if ( typeof value.message === 'string' && value.message ) {
			return value.message;
		}
		if ( typeof value.error === 'string' && value.error ) {
			return value.error;
		}
		if ( typeof value.code === 'string' && value.code ) {
			return value.code;
		}
	}
	return __(
		'Something went wrong. See the console for details.',
		'cloudflare-stream'
	);
}

/**
 * Whether a failure is a hard auth/permission problem (no auto-retry).
 *
 * @param {*} value Raw error value or message.
 * @return {boolean} True when retrying the same request is pointless.
 */
function isNonRetryableFailure( value ) {
	const text = normaliseErrorMessage( value ).toLowerCase();
	if ( ! text ) {
		return false;
	}
	return (
		text.indexOf( 'nonce' ) !== -1 ||
		text.indexOf( 'forbidden' ) !== -1 ||
		text.indexOf( 'permission' ) !== -1 ||
		text.indexOf( 'not allowed' ) !== -1 ||
		text.indexOf( 'unauthorized' ) !== -1 ||
		text.indexOf( 'log in' ) !== -1 ||
		text.indexOf( 'logged in' ) !== -1
	);
}

/**
 * Snapshot of ready-video attributes used when cancelling a replace.
 *
 * @param {Object} attributes Block attributes.
 * @return {Object|null} Snapshot, or null when there is no ready video.
 */
function snapshotReadyAttributes( attributes ) {
	if ( ! attributes.uid || ! attributes.thumbnail ) {
		return null;
	}
	return {
		uid: attributes.uid,
		fingerprint: attributes.fingerprint,
		thumbnail: attributes.thumbnail,
	};
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

		this.streamPlayer = createRef();
		this.encodingPoller = null;
		this.upload = null;
		this.selectedFile = null;
		this.retried = false;
		this.uploadGeneration = 0;
		this.encodePollAttempts = 0;
		// Ready video attrs before replace; restored if the replace is cancelled.
		this.readySnapshot = snapshotReadyAttributes( props.attributes );

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

	componentWillUnmount() {
		this.invalidateUploadGeneration();
		this.clearEncodingPoller();
		this.abortUpload();
	}

	/**
	 * Bump the upload generation so in-flight callbacks become no-ops.
	 */
	invalidateUploadGeneration() {
		this.uploadGeneration += 1;
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

	/**
	 * True when the given generation still owns the active upload/encode work.
	 *
	 * @param {number} generation Generation captured when work started.
	 * @return {boolean} Whether callbacks may update UI/attributes.
	 */
	isCurrentGeneration( generation ) {
		return generation === this.uploadGeneration;
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

		// Drops any pending direct-upload / TUS work for a library pick.
		this.invalidateUploadGeneration();
		this.clearEncodingPoller();
		this.abortUpload();
		this.selectedFile = null;
		this.retried = false;
		this.encodePollAttempts = 0;

		setAttributes( {
			uid: attachment.uid,
			fingerprint: false,
			thumbnail: attachment.thumb.src,
		} );

		this.readySnapshot = {
			uid: attachment.uid,
			fingerprint: false,
			thumbnail: attachment.thumb.src,
		};

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
			normaliseErrorMessage( message ) ||
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

		this.invalidateUploadGeneration();
		this.clearEncodingPoller();
		this.abortUpload();
		this.retried = false;
		this.encodePollAttempts = 0;

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
	 * Stop upload/encoding work and restore a ready video or a clean idle state.
	 */
	cancel() {
		const { setAttributes } = this.props;
		const { noticeOperations } = this.props;

		this.invalidateUploadGeneration();
		this.clearEncodingPoller();
		this.abortUpload();
		this.selectedFile = null;
		this.retried = false;
		this.encodePollAttempts = 0;

		if ( noticeOperations ) {
			noticeOperations.removeAllNotices();
		}

		if ( this.readySnapshot ) {
			setAttributes( {
				uid: this.readySnapshot.uid,
				fingerprint: this.readySnapshot.fingerprint,
				thumbnail: this.readySnapshot.thumbnail,
			} );
			this.setState( {
				status: 'ready',
				progress: null,
				errorMessage: '',
			} );
			return;
		}

		// Abandoned new upload/encoding — clear so reload does not resume.
		setAttributes( {
			uid: false,
			fingerprint: false,
			thumbnail: false,
		} );
		this.setState( {
			status: 'idle',
			progress: null,
			errorMessage: '',
		} );
	}

	/**
	 * Open the edit placeholder (replace video).
	 */
	switchToEditing() {
		const { attributes } = this.props;
		const { noticeOperations } = this.props;

		// Keep a restore point while the user picks a replacement.
		this.readySnapshot = snapshotReadyAttributes( attributes );

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

		this.invalidateUploadGeneration();
		this.clearEncodingPoller();
		this.abortUpload();
		this.encodePollAttempts = 0;

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

		// Own this attempt; cancel/select/unmount bumps generation past it.
		block.uploadGeneration += 1;
		const generation = block.uploadGeneration;

		fetchDirectUpload( file )
			.then( ( { uploadURL, uid } ) => {
				if ( ! block.isCurrentGeneration( generation ) ) {
					return;
				}

				block.upload = tusUploadFile( file, uploadURL, {
					onError( error ) {
						if ( ! block.isCurrentGeneration( generation ) ) {
							return;
						}
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
						if ( ! block.isCurrentGeneration( generation ) ) {
							return;
						}
						const percentage = parseInt(
							( bytesUploaded / bytesTotal ) * 100,
							10
						);
						block.setState( { progress: percentage } );
					},
					onSuccess( upload ) {
						if ( ! block.isCurrentGeneration( generation ) ) {
							return;
						}

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
							thumbnail: false,
						} );
						block.switchToEncoding( generation );
					},
				} );
			} )
			.catch( ( error ) => {
				if ( ! block.isCurrentGeneration( generation ) ) {
					return;
				}
				block.showUploadError(
					error && error.message ? error.message : String( error ),
					error
				);
			} );
	}

	/**
	 * Move into the encoding/processing state and start polling.
	 *
	 * @param {number} [generation] Upload generation that owns this encode run.
	 */
	switchToEncoding( generation ) {
		const activeGeneration =
			typeof generation === 'number'
				? generation
				: this.uploadGeneration;

		this.encodePollAttempts = 0;
		this.setState(
			{
				status: 'encoding',
				progress: null,
				errorMessage: '',
			},
			() => {
				this.encode( activeGeneration );
			}
		);
	}

	/**
	 * Schedule the next encoding status poll.
	 *
	 * @param {number} generation Upload generation for this encode run.
	 * @param {number} delayMs    Delay before the next poll.
	 */
	scheduleEncodePoll( generation, delayMs ) {
		const block = this;
		block.clearEncodingPoller();
		block.encodingPoller = setTimeout( function () {
			block.encode( generation );
		}, delayMs );
	}

	/**
	 * Poll Stream until the video is ready to play.
	 *
	 * @param {number} [generation] Upload generation for this encode run.
	 */
	encode( generation ) {
		const { attributes, setAttributes } = this.props;
		const block = this;
		const activeGeneration =
			typeof generation === 'number'
				? generation
				: block.uploadGeneration;

		if ( ! block.isCurrentGeneration( activeGeneration ) ) {
			return;
		}

		if ( ! attributes.uid ) {
			block.showUploadError(
				__(
					'Processing Error: Missing video id.',
					'cloudflare-stream'
				)
			);
			return;
		}

		checkUploadStatus( attributes.uid )
			.then( ( data ) => {
				if ( ! block.isCurrentGeneration( activeGeneration ) ) {
					return;
				}

				if ( ! data || typeof data !== 'object' ) {
					block.handleEncodePollFailure(
						activeGeneration,
						__(
							'Invalid processing status response.',
							'cloudflare-stream'
						)
					);
					return;
				}

				if ( ! data.success ) {
					const detail = normaliseErrorMessage( data.data );
					console.error( 'Error: ' + detail );

					if ( isNonRetryableFailure( data.data || detail ) ) {
						block.showUploadError(
							sprintf(
								/* translators: %s: error detail from the API */
								__(
									'Processing Error: %s',
									'cloudflare-stream'
								),
								detail ||
									__(
										'See the console for details.',
										'cloudflare-stream'
									)
							),
							data.data
						);
						return;
					}

					// One-shot re-upload only for ambiguous processing failures.
					if (
						block.retried === false &&
						block.selectedFile &&
						! isNonRetryableFailure( detail )
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
						return;
					}

					block.showUploadError(
						sprintf(
							/* translators: %s: error detail from the API */
							__(
								'Processing Error: %s',
								'cloudflare-stream'
							),
							detail ||
								__(
									'See the console for details.',
									'cloudflare-stream'
								)
						),
						data.data
					);
					return;
				}

				if ( typeof data.data === 'undefined' || data.data === null ) {
					block.handleEncodePollFailure(
						activeGeneration,
						__(
							'Empty processing status response.',
							'cloudflare-stream'
						)
					);
					return;
				}

				if (
					data.data.readyToStream === true &&
					typeof data.data.thumbnail !== 'undefined'
				) {
					block.clearEncodingPoller();
					block.encodePollAttempts = 0;
					setAttributes( {
						thumbnail: data.data.thumbnail,
					} );
					block.readySnapshot = {
						uid: attributes.uid,
						fingerprint: attributes.fingerprint,
						thumbnail: data.data.thumbnail,
					};
					block.setState( {
						status: 'ready',
						progress: null,
						errorMessage: '',
					} );
					return;
				}

				// Successful poll with progress still running — reset transient failures.
				block.encodePollAttempts = 0;
				block.scheduleEncodePoll( activeGeneration, 5000 );

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
				if ( ! block.isCurrentGeneration( activeGeneration ) ) {
					return;
				}

				const detail = normaliseErrorMessage( error );
				console.error( detail || error );

				if ( isNonRetryableFailure( error ) ) {
					block.showUploadError(
						sprintf(
							/* translators: %s: error detail from the API */
							__(
								'Processing Error: %s',
								'cloudflare-stream'
							),
							detail ||
								__(
									'See the console for details.',
									'cloudflare-stream'
								)
						),
						error
					);
					return;
				}

				block.handleEncodePollFailure( activeGeneration, detail, error );
			} );
	}

	/**
	 * Retry a transient encode poll a few times, then surface an error.
	 *
	 * @param {number} generation Upload generation for this encode run.
	 * @param {string} message    User-facing failure text.
	 * @param {*}      [cause]    Optional raw error for the console.
	 */
	handleEncodePollFailure( generation, message, cause ) {
		if ( ! this.isCurrentGeneration( generation ) ) {
			return;
		}

		this.encodePollAttempts += 1;

		if ( this.encodePollAttempts < ENCODE_POLL_MAX_ATTEMPTS ) {
			// Simple backoff: 5s, 10s, …
			const delayMs = 5000 * this.encodePollAttempts;
			this.scheduleEncodePoll( generation, delayMs );
			return;
		}

		this.clearEncodingPoller();
		this.showUploadError(
			sprintf(
				/* translators: %s: error detail from the API */
				__( 'Processing Error: %s', 'cloudflare-stream' ),
				message ||
					__(
						'See the console for details.',
						'cloudflare-stream'
					)
			),
			cause !== undefined ? cause : message
		);
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

		// Idle after "edit" still has a ready snapshot or current ready attrs.
		if ( status === 'idle' && this.readySnapshot ) {
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
