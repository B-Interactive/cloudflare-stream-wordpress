/**
 * Block edit interface for the Cloudflare Stream Video block.
 *
 * @package cloudflare-stream
 */

import { streamIframeSource } from './lib';
import {
	fetchDirectUpload,
	tusUploadFile,
	checkUploadStatus,
} from './stream-upload';
import { streamAjax } from '../lib/ajax';

/* global cloudflareStream */

/**
 * WordPress dependencies
 */
import { sprintf, __ } from '@wordpress/i18n';
import {
	Disabled,
	Button,
	PanelBody,
	ToolbarButton,
	ToggleControl,
	withNotices,
	Placeholder,
	ProgressBar,
} from '@wordpress/components';
import {
	BlockControls,
	InspectorControls,
	MediaPlaceholder,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';

/** Transient poll failures before surfacing an error. */
const ENCODE_POLL_MAX_ATTEMPTS = 3;

/**
 * Resolve the UI status from block attributes.
 *
 * @param {Object} attributes Block attributes.
 * @return {'idle'|'encoding'|'ready'} Derived status.
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
 * HTTP status from a thrown apiFetch / WP error payload, if present.
 *
 * @param {*} value Raw error value.
 * @return {number|null} Status code or null.
 */
function errorHttpStatus( value ) {
	if ( ! value || typeof value !== 'object' ) {
		return null;
	}
	if ( typeof value.status === 'number' ) {
		return value.status;
	}
	if ( typeof value.statusCode === 'number' ) {
		return value.statusCode;
	}
	if ( value.data && typeof value.data.status === 'number' ) {
		return value.data.status;
	}
	return null;
}

/**
 * Machine-readable error code from a WP-style payload, if present.
 *
 * @param {*} value Raw error value.
 * @return {string} Error code or empty string.
 */
function errorCode( value ) {
	if ( ! value || typeof value !== 'object' ) {
		return '';
	}
	if ( typeof value.code === 'string' ) {
		return value.code;
	}
	if ( value.data && typeof value.data.code === 'string' ) {
		return value.data.code;
	}
	return '';
}

/**
 * Whether a failure is a hard auth/permission problem (no auto-retry).
 *
 * @param {*} value Raw error value or message.
 * @return {boolean} True when retrying the same request is pointless.
 */
function isNonRetryableFailure( value ) {
	const status = errorHttpStatus( value );
	if ( status === 401 || status === 403 ) {
		return true;
	}

	const code = errorCode( value ).toLowerCase();
	if (
		code === 'rest_forbidden' ||
		code === 'rest_cookie_invalid_nonce' ||
		code.indexOf( 'forbidden' ) !== -1 ||
		code.indexOf( 'nonce' ) !== -1 ||
		code.indexOf( 'unauthorized' ) !== -1
	) {
		return true;
	}

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

/**
 * Block edit UI for Cloudflare Stream videos.
 *
 * @param {Object}    props                  Block edit props.
 * @param {Object}    props.attributes       Block attributes.
 * @param {Function}  props.setAttributes    Attribute updater.
 * @param {WPElement} props.noticeUI         Notice list from withNotices.
 * @param {Object}    props.noticeOperations Notice helpers from withNotices.
 * @return {WPElement} Edit interface.
 */
function CloudflareStreamEdit( {
	attributes,
	setAttributes,
	noticeUI,
	noticeOperations,
} ) {
	const { autoplay, controls, loop, muted } = attributes;
	const blockProps = useBlockProps();
	const canManage = Boolean(
		typeof cloudflareStream !== 'undefined' && cloudflareStream.nonce
	);

	const [ status, setStatus ] = useState( () =>
		statusFromAttributes( attributes )
	);
	const [ progress, setProgress ] = useState( null );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	const encodingPollerRef = useRef( null );
	const uploadRef = useRef( null );
	const selectedFileRef = useRef( null );
	const retriedRef = useRef( false );
	const uploadGenerationRef = useRef( 0 );
	const encodePollAttemptsRef = useRef( 0 );
	const readySnapshotRef = useRef( snapshotReadyAttributes( attributes ) );
	const mediaFrameRef = useRef( null );

	// Async upload/encode callbacks read the latest props via refs.
	const attributesRef = useRef( attributes );
	const setAttributesRef = useRef( setAttributes );
	const noticeOperationsRef = useRef( noticeOperations );
	const selectAttachmentRef = useRef( () => {} );
	const deleteAttachmentRef = useRef( () => {} );
	const encodeRef = useRef( () => {} );
	const uploadFromFilesRef = useRef( () => {} );

	const invalidateUploadGeneration = useCallback( () => {
		uploadGenerationRef.current += 1;
	}, [] );

	const clearEncodingPoller = useCallback( () => {
		if ( encodingPollerRef.current ) {
			clearTimeout( encodingPollerRef.current );
			encodingPollerRef.current = null;
		}
	}, [] );

	const abortUpload = useCallback( () => {
		if ( uploadRef.current && typeof uploadRef.current.abort === 'function' ) {
			// tus-js-client v4 may return a Promise from abort().
			Promise.resolve( uploadRef.current.abort() ).catch( () => {} );
		}
		uploadRef.current = null;
	}, [] );

	const isCurrentGeneration = useCallback( ( generation ) => {
		return generation === uploadGenerationRef.current;
	}, [] );

	const clearNotices = useCallback( () => {
		if ( noticeOperationsRef.current ) {
			noticeOperationsRef.current.removeAllNotices();
		}
	}, [] );

	/**
	 * Surface a failed upload or processing message via editor notices.
	 *
	 * @param {string} message User-facing error text.
	 * @param {*}      [cause] Optional raw error for the console.
	 */
	const showUploadError = useCallback( ( message, cause ) => {
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

		if ( noticeOperationsRef.current ) {
			noticeOperationsRef.current.removeAllNotices();
			noticeOperationsRef.current.createErrorNotice( text );
		}

		setStatus( 'error' );
		setProgress( null );
		setErrorMessage( text );
	}, [] );

	const toggleAttribute = useCallback(
		( attribute ) => ( newValue ) => {
			setAttributes( {
				[ attribute ]: newValue,
			} );
		},
		[ setAttributes ]
	);

	/**
	 * Remove a video from the Stream library.
	 *
	 * @param {Object} attachment Selected library attachment.
	 */
	const deleteAttachment = useCallback(
		( attachment ) => {
			const deletedUid = attachment && attachment.uid;

			streamAjax( 'cloudflare-stream-delete', { uid: deletedUid } ).catch(
				( error ) => {
					console.error( error );
				}
			);

			// Drop block binding when the embedded asset is removed.
			const current = attributesRef.current;
			if ( ! deletedUid || ! current.uid || deletedUid !== current.uid ) {
				return;
			}

			invalidateUploadGeneration();
			clearEncodingPoller();
			abortUpload();
			selectedFileRef.current = null;
			retriedRef.current = false;
			encodePollAttemptsRef.current = 0;
			readySnapshotRef.current = null;
			clearNotices();

			setAttributes( {
				uid: false,
				fingerprint: false,
				thumbnail: false,
			} );
			setStatus( 'idle' );
			setProgress( null );
			setErrorMessage( '' );
		},
		[
			abortUpload,
			clearEncodingPoller,
			clearNotices,
			invalidateUploadGeneration,
			setAttributes,
		]
	);

	/**
	 * Apply a library selection to the block.
	 *
	 * @param {Object} attachment Selected library attachment.
	 */
	const selectAttachment = useCallback(
		( attachment ) => {
			invalidateUploadGeneration();
			clearEncodingPoller();
			abortUpload();
			selectedFileRef.current = null;
			retriedRef.current = false;
			encodePollAttemptsRef.current = 0;
			clearNotices();

			setAttributes( {
				uid: attachment.uid,
				fingerprint: false,
				thumbnail: attachment.thumb.src,
			} );

			readySnapshotRef.current = {
				uid: attachment.uid,
				fingerprint: false,
				thumbnail: attachment.thumb.src,
			};

			setStatus( 'ready' );
			setProgress( null );
			setErrorMessage( '' );
		},
		[
			abortUpload,
			clearEncodingPoller,
			clearNotices,
			invalidateUploadGeneration,
			setAttributes,
		]
	);

	/**
	 * Open the Stream library frame (created once per block instance).
	 */
	const open = useCallback( () => {
		if ( ! mediaFrameRef.current ) {
			mediaFrameRef.current = new cloudflareStream.media.view.MediaFrame(
				( attachment ) => {
					selectAttachmentRef.current( attachment );
				}
			);
			mediaFrameRef.current.on( 'delete', ( attachment ) => {
				deleteAttachmentRef.current( attachment );
			} );
		}
		mediaFrameRef.current.open();
	}, [] );

	const instructions =
		status === 'error'
			? errorMessage
			: {
					idle: __(
						'Select a file from your library.',
						'cloudflare-stream'
					),
					uploading: __(
						'Uploading your video.',
						'cloudflare-stream'
					),
					encoding: __(
						'Upload complete. Processing video.',
						'cloudflare-stream'
					),
			  }[ status ] ||
			  __( 'Select a file from your library.', 'cloudflare-stream' );

	/**
	 * Return to the empty/edit placeholder so the user can pick another file.
	 */
	const retry = useCallback( () => {
		invalidateUploadGeneration();
		clearEncodingPoller();
		abortUpload();
		selectedFileRef.current = null;
		retriedRef.current = false;
		encodePollAttemptsRef.current = 0;
		clearNotices();

		// Drop failed in-flight attrs; keep a replace snapshot if present.
		if ( readySnapshotRef.current ) {
			setAttributes( {
				uid: readySnapshotRef.current.uid,
				fingerprint: readySnapshotRef.current.fingerprint,
				thumbnail: readySnapshotRef.current.thumbnail,
			} );
		} else {
			setAttributes( {
				uid: false,
				fingerprint: false,
				thumbnail: false,
			} );
		}

		setStatus( 'idle' );
		setProgress( null );
		setErrorMessage( '' );
	}, [
		abortUpload,
		clearEncodingPoller,
		clearNotices,
		invalidateUploadGeneration,
		setAttributes,
	] );

	/**
	 * Stop upload/encoding work and restore a ready video or a clean idle state.
	 */
	const cancel = useCallback( () => {
		invalidateUploadGeneration();
		clearEncodingPoller();
		abortUpload();
		selectedFileRef.current = null;
		retriedRef.current = false;
		encodePollAttemptsRef.current = 0;
		clearNotices();

		if ( readySnapshotRef.current ) {
			setAttributes( {
				uid: readySnapshotRef.current.uid,
				fingerprint: readySnapshotRef.current.fingerprint,
				thumbnail: readySnapshotRef.current.thumbnail,
			} );
			setStatus( 'ready' );
			setProgress( null );
			setErrorMessage( '' );
			return;
		}

		// Abandoned new upload/encoding — clear so reload does not resume.
		setAttributes( {
			uid: false,
			fingerprint: false,
			thumbnail: false,
		} );
		setStatus( 'idle' );
		setProgress( null );
		setErrorMessage( '' );
	}, [
		abortUpload,
		clearEncodingPoller,
		clearNotices,
		invalidateUploadGeneration,
		setAttributes,
	] );

	/**
	 * Open the edit placeholder (replace video).
	 */
	const switchToEditing = useCallback( () => {
		readySnapshotRef.current = snapshotReadyAttributes(
			attributesRef.current
		);
		clearNotices();
		setStatus( 'idle' );
		setProgress( null );
		setErrorMessage( '' );
	}, [ clearNotices ] );

	/**
	 * Schedule the next encoding status poll.
	 *
	 * @param {number} generation Upload generation for this encode run.
	 * @param {number} delayMs    Delay before the next poll.
	 */
	const scheduleEncodePoll = useCallback(
		( generation, delayMs ) => {
			clearEncodingPoller();
			encodingPollerRef.current = setTimeout( () => {
				encodeRef.current( generation );
			}, delayMs );
		},
		[ clearEncodingPoller ]
	);

	/**
	 * Retry a transient encode poll a few times, then surface an error.
	 *
	 * @param {number} generation Upload generation for this encode run.
	 * @param {string} message    User-facing failure text.
	 * @param {*}      [cause]    Optional raw error for the console.
	 */
	const handleEncodePollFailure = useCallback(
		( generation, message, cause ) => {
			if ( ! isCurrentGeneration( generation ) ) {
				return;
			}

			encodePollAttemptsRef.current += 1;

			if ( encodePollAttemptsRef.current < ENCODE_POLL_MAX_ATTEMPTS ) {
				// Simple backoff: 5s, 10s, …
				const delayMs = 5000 * encodePollAttemptsRef.current;
				scheduleEncodePoll( generation, delayMs );
				return;
			}

			clearEncodingPoller();
			showUploadError(
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
		},
		[
			clearEncodingPoller,
			isCurrentGeneration,
			scheduleEncodePoll,
			showUploadError,
		]
	);

	/**
	 * Poll Stream until the video is ready to play.
	 *
	 * @param {number} [generation] Upload generation for this encode run.
	 */
	const encode = useCallback(
		( generation ) => {
			const activeGeneration =
				typeof generation === 'number'
					? generation
					: uploadGenerationRef.current;

			if ( ! isCurrentGeneration( activeGeneration ) ) {
				return;
			}

			const currentAttributes = attributesRef.current;

			if ( ! currentAttributes.uid ) {
				showUploadError(
					__(
						'Processing Error: Missing video id.',
						'cloudflare-stream'
					)
				);
				return;
			}

			checkUploadStatus( currentAttributes.uid )
				.then( ( data ) => {
					if ( ! isCurrentGeneration( activeGeneration ) ) {
						return;
					}

					if ( ! data || typeof data !== 'object' ) {
						handleEncodePollFailure(
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
							showUploadError(
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
							retriedRef.current === false &&
							selectedFileRef.current &&
							! isNonRetryableFailure( detail )
						) {
							retriedRef.current = true;
							setStatus( 'uploading' );
							setProgress( null );
							setErrorMessage( '' );
							uploadFromFilesRef.current( selectedFileRef.current );
							return;
						}

						showUploadError(
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

					if (
						typeof data.data === 'undefined' ||
						data.data === null
					) {
						handleEncodePollFailure(
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
						clearEncodingPoller();
						encodePollAttemptsRef.current = 0;
						setAttributesRef.current( {
							thumbnail: data.data.thumbnail,
						} );
						readySnapshotRef.current = {
							uid: currentAttributes.uid,
							fingerprint: currentAttributes.fingerprint,
							thumbnail: data.data.thumbnail,
						};
						setStatus( 'ready' );
						setProgress( null );
						setErrorMessage( '' );
						return;
					}

					// Successful poll with progress still running — reset transient failures.
					encodePollAttemptsRef.current = 0;
					scheduleEncodePoll( activeGeneration, 5000 );

					if (
						data.data.status &&
						data.data.status.state === 'queued'
					) {
						setProgress( null );
					} else if (
						data.data.status &&
						data.data.status.state === 'inprogress'
					) {
						const pct = Number( data.data.status.pctComplete );
						setProgress(
							Number.isFinite( pct ) ? Math.round( pct ) : null
						);
					}
				} )
				.catch( ( error ) => {
					if ( ! isCurrentGeneration( activeGeneration ) ) {
						return;
					}

					const detail = normaliseErrorMessage( error );
					console.error( detail || error );

					if ( isNonRetryableFailure( error ) ) {
						showUploadError(
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

					handleEncodePollFailure(
						activeGeneration,
						detail,
						error
					);
				} );
		},
		[
			clearEncodingPoller,
			handleEncodePollFailure,
			isCurrentGeneration,
			scheduleEncodePoll,
			showUploadError,
		]
	);

	/**
	 * Move into the encoding/processing state and start polling.
	 *
	 * @param {number} [generation] Upload generation that owns this encode run.
	 */
	const switchToEncoding = useCallback(
		( generation ) => {
			const activeGeneration =
				typeof generation === 'number'
					? generation
					: uploadGenerationRef.current;

			encodePollAttemptsRef.current = 0;
			setStatus( 'encoding' );
			setProgress( null );
			setErrorMessage( '' );
			encode( activeGeneration );
		},
		[ encode ]
	);

	/**
	 * Request a direct-upload URL and transfer the file with TUS.
	 *
	 * @param {File} file Browser file object.
	 */
	const uploadFromFiles = useCallback(
		( file ) => {
			if ( ! file ) {
				showUploadError(
					__(
						'Upload Error: See the console for details.',
						'cloudflare-stream'
					)
				);
				return;
			}

			// Own this attempt; cancel/select/unmount bumps generation past it.
			uploadGenerationRef.current += 1;
			const generation = uploadGenerationRef.current;

			fetchDirectUpload( file )
				.then( ( { uploadURL, uid } ) => {
					if ( ! isCurrentGeneration( generation ) ) {
						return;
					}

					uploadRef.current = tusUploadFile( file, uploadURL, {
						onError( error ) {
							if ( ! isCurrentGeneration( generation ) ) {
								return;
							}
							uploadRef.current = null;
							showUploadError(
								__(
									'Upload Error: See the console for details.',
									'cloudflare-stream'
								),
								error
							);
						},
						onProgress( bytesUploaded, bytesTotal ) {
							if ( ! isCurrentGeneration( generation ) ) {
								return;
							}
							if ( ! bytesTotal ) {
								setProgress( null );
								return;
							}
							setProgress(
								Math.round(
									( bytesUploaded / bytesTotal ) * 100
								)
							);
						},
						onSuccess( upload ) {
							if ( ! isCurrentGeneration( generation ) ) {
								return;
							}

							const fingerprint =
								upload.options &&
								typeof upload.options.fingerprint ===
									'function'
									? upload.options.fingerprint(
											upload.file,
											upload.options
									  )
									: undefined;

							uploadRef.current = null;
							setAttributesRef.current( {
								uid,
								fingerprint,
								thumbnail: false,
							} );
							switchToEncoding( generation );
						},
					} );
				} )
				.catch( ( error ) => {
					if ( ! isCurrentGeneration( generation ) ) {
						return;
					}
					showUploadError(
						error && error.message
							? error.message
							: String( error ),
						error
					);
				} );
		},
		[ isCurrentGeneration, showUploadError, switchToEncoding ]
	);

	/**
	 * Begin a TUS upload for the given file.
	 *
	 * @param {File} file Browser file object.
	 */
	const startUpload = useCallback(
		( file ) => {
			clearNotices();
			invalidateUploadGeneration();
			clearEncodingPoller();
			abortUpload();
			encodePollAttemptsRef.current = 0;

			setStatus( 'uploading' );
			setProgress( null );
			setErrorMessage( '' );
			uploadFromFiles( file );
		},
		[
			abortUpload,
			clearEncodingPoller,
			clearNotices,
			invalidateUploadGeneration,
			uploadFromFiles,
		]
	);

	/**
	 * Handle files from MediaPlaceholder (upload or drop).
	 * With handleUpload false, onSelect receives a FileList, not WP attachments.
	 *
	 * @param {FileList|Array|Object} input Selected files or media object.
	 */
	const onSelectMedia = useCallback(
		( input ) => {
			const candidate = Array.isArray( input )
				? input[ 0 ]
				: input && typeof input === 'object' && 'length' in input
					? input[ 0 ]
					: input;

			if ( ! ( candidate instanceof window.File ) ) {
				return;
			}

			selectedFileRef.current = candidate;
			retriedRef.current = false;
			startUpload( candidate );
		},
		[ startUpload ]
	);

	/**
	 * Surface MediaPlaceholder validation failures as block notices.
	 *
	 * @param {string} message Error text from the placeholder.
	 */
	const onPlaceholderError = useCallback(
		( message ) => {
			showUploadError( message );
		},
		[ showUploadError ]
	);

	const showCancel =
		status === 'uploading' ||
		status === 'encoding' ||
		status === 'error' ||
		( status === 'idle' && Boolean( readySnapshotRef.current ) );

	// Keep "latest" refs aligned before paint so async callbacks stay current.
	useLayoutEffect( () => {
		attributesRef.current = attributes;
		setAttributesRef.current = setAttributes;
		noticeOperationsRef.current = noticeOperations;
		selectAttachmentRef.current = selectAttachment;
		deleteAttachmentRef.current = deleteAttachment;
		encodeRef.current = encode;
		uploadFromFilesRef.current = uploadFromFiles;
	} );

	// Resume encoding for blocks that already have a uid but no thumbnail.
	useEffect( () => {
		if ( attributes.uid && ! attributes.thumbnail ) {
			switchToEncoding();
		}

		return () => {
			invalidateUploadGeneration();
			clearEncodingPoller();
			abortUpload();

			if ( mediaFrameRef.current ) {
				if ( typeof mediaFrameRef.current.off === 'function' ) {
					mediaFrameRef.current.off( 'delete' );
				}
				if ( typeof mediaFrameRef.current.remove === 'function' ) {
					mediaFrameRef.current.remove();
				}
				mediaFrameRef.current = null;
			}
		};
		// Mount/unmount only — encoding resume uses the initial attributes.
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentional mount lifecycle
	}, [] );

	// Undo/redo: re-derive idle/ready/encoding from attributes while not in flight.
	useEffect( () => {
		// Do not clobber upload, active encode polls, or error recovery UI.
		if (
			status === 'uploading' ||
			status === 'encoding' ||
			status === 'error'
		) {
			return;
		}

		const snap = readySnapshotRef.current;

		// Replace flow: switchToEditing keeps ready attrs and a snapshot.
		if ( status === 'idle' && snap ) {
			if (
				attributes.uid === snap.uid &&
				attributes.thumbnail === snap.thumbnail
			) {
				return;
			}
			// Attributes moved under us (undo/redo) — drop stale replace snapshot.
			readySnapshotRef.current = snapshotReadyAttributes( attributes );
		}

		if ( ! attributes.uid ) {
			readySnapshotRef.current = null;
			if ( status !== 'idle' ) {
				setStatus( 'idle' );
				setProgress( null );
				setErrorMessage( '' );
			}
			return;
		}

		if ( attributes.thumbnail ) {
			readySnapshotRef.current = snapshotReadyAttributes( attributes );
			if ( status !== 'ready' ) {
				setStatus( 'ready' );
				setProgress( null );
				setErrorMessage( '' );
			}
			return;
		}

		// uid without thumbnail — resume processing with a fresh generation.
		readySnapshotRef.current = null;
		switchToEncoding();
	}, [
		attributes.fingerprint,
		attributes.thumbnail,
		attributes.uid,
		status,
		switchToEncoding,
	] );

	if ( status !== 'ready' ) {
		const showProgress =
			status === 'uploading' || status === 'encoding';
		const showRetry = status === 'error';
		// MediaPlaceholder is idle/error only — progress keeps a plain Placeholder.
		const useMediaPlaceholder =
			( status === 'idle' || status === 'error' ) && canManage;

		const progressAndActions = (
			<>
				{ showProgress && (
					<div className="cloudflare-stream-progress">
						<ProgressBar value={ progress } />
						{ null !== progress && <p>{ progress }%</p> }
					</div>
				) }
				{ showRetry && (
					<Button
						variant="secondary"
						icon="update"
						label={ __( 'Retry', 'cloudflare-stream' ) }
						onClick={ retry }
						className="editor-media-placeholder__retry-button"
					>
						{ __( 'Retry', 'cloudflare-stream' ) }
					</Button>
				) }
				{ showCancel && (
					<Button
						variant="secondary"
						icon="cancel"
						label={ __( 'Cancel', 'cloudflare-stream' ) }
						onClick={ cancel }
						className="editor-media-placeholder__cancel-button"
					>
						{ __( 'Cancel', 'cloudflare-stream' ) }
					</Button>
				) }
			</>
		);

		if ( useMediaPlaceholder ) {
			return (
				<div { ...blockProps }>
					<MediaPlaceholder
						icon={ cloudflareStream.icon }
						labels={ {
							title: __(
								'Cloudflare Stream',
								'cloudflare-stream'
							),
							instructions,
						} }
						accept="video/*"
						allowedTypes={ [ 'video' ] }
						handleUpload={ false }
						multiple={ false }
						notices={ noticeUI }
						onError={ onPlaceholderError }
						onSelect={ onSelectMedia }
					>
						<Button variant="secondary" onClick={ open }>
							{ __(
								'Stream Library',
								'cloudflare-stream'
							) }
						</Button>
						{ progressAndActions }
					</MediaPlaceholder>
				</div>
			);
		}

		return (
			<div { ...blockProps }>
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
					{ progressAndActions }
				</Placeholder>
			</div>
		);
	}

	return (
		<>
			<BlockControls group="other">
				<ToolbarButton
					icon="edit"
					label={ __( 'Replace video', 'cloudflare-stream' ) }
					onClick={ switchToEditing }
				/>
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
						onChange={ toggleAttribute( 'autoplay' ) }
						checked={ autoplay }
					/>
					<ToggleControl
						label={ __(
							'Loop',
							'cloudflare-stream'
						) }
						onChange={ toggleAttribute( 'loop' ) }
						checked={ loop }
					/>
					<ToggleControl
						label={ __(
							'Muted',
							'cloudflare-stream'
						) }
						onChange={ toggleAttribute( 'muted' ) }
						checked={ muted }
					/>
					<ToggleControl
						label={ __(
							'Playback Controls',
							'cloudflare-stream'
						) }
						onChange={ toggleAttribute( 'controls' ) }
						checked={ controls }
					/>
				</PanelBody>
			</InspectorControls>
			<figure { ...blockProps }>
				<Disabled className="player-edit-wrapper">
					<iframe
						src={ streamIframeSource( attributes ) }
						title={ __(
							'Cloudflare Stream video',
							'cloudflare-stream'
						) }
					></iframe>
				</Disabled>
			</figure>
		</>
	);
}

export default withNotices( CloudflareStreamEdit );
