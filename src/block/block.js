/* global cloudflareStream */

/**
 * BLOCK: cloudflare-stream
 *
 */

//  Import CSS.
import './style.scss';
import './editor.scss';

/**
 * Internal dependencies
 */
import edit from './edit';

const { __ } = wp.i18n; // Import __() from wp.i18n
const { registerBlockType } = wp.blocks; // Import registerBlockType() from wp.blocks;

/**
 * Cloudflare Stream SVG path icon
*/
cloudflareStream.icon = wp.element.createElement( 'svg', { width: 20, height: 20, viewBox: '0 0 68.66 49.14', className: 'cls-1 dashicon' },
	wp.element.createElement( 'path', { d: 'M61.05,42.28H1.75A.76.76,0,0,1,1,41.52V1.73A.75.75,0,0,1,1.75,1h59.3a.75.75,0,0,1,.76.75V41.52A.76.76,0,0,1,61.05,42.28ZM2.51,40.77H60.3V2.49H2.51Z' } ),
	wp.element.createElement( 'path', { d: 'M45.6,26.09,31.44,17.91a1.17,1.17,0,0,0-1.19-.09,1.19,1.19,0,0,0-.51,1.07V35.25a1.17,1.17,0,0,0,.51,1.06.91.91,0,0,0,.48.13,1.41,1.41,0,0,0,.71-.21L45.6,28.05a1.05,1.05,0,0,0,0-2ZM65.13,48.14H7.86a2.52,2.52,0,0,1-2.52-2.52V7.86A2.52,2.52,0,0,1,7.86,5.34H65.13a2.52,2.52,0,0,1,2.53,2.52V45.62A2.52,2.52,0,0,1,65.13,48.14Zm-56.77-3H64.63V8.36H8.36Z' } )
);

/**
 * Register: aa Gutenberg Block.
 *
 * Registers a new block provided a unique name and an object defining its
 * behavior. Once registered, the block is made editor as an option to any
 * editor interface where blocks are implemented.
 *
 * @link https://wordpress.org/gutenberg/handbook/block-api/
 * @param  {string}   name     Block name.
 * @param  {Object}   settings Block settings.
 * @return {?WPBlock}          The block, if it has been successfully
 *                             registered; otherwise `undefined`.
 */
registerBlockType( 'cloudflare-stream/block-video', {
	title: __( 'Cloudflare Stream Video' ),
	icon: cloudflareStream.icon,
	render_callback: 'cloudflare_stream_render_block',
	category: 'embed',
	keywords: [
		__( 'Cloudflare' ),
		__( 'Stream' ),
		__( 'video' ),
	],
	attributes: {
		alignment: {
			type: 'string',
		},
		uid: {
			type: 'string',
			default: false,
		},
		fingerprint: {
			type: 'string',
			default: false,
		},
		thumbnail: {
			type: 'string',
			default: false,
		},
		autoplay: {
			type: 'boolean',
			source: 'attribute',
			selector: 'stream',
			attribute: 'autoplay',
			default: false,
		},
		loop: {
			type: 'boolean',
			source: 'attribute',
			selector: 'stream',
			attribute: 'loop',
			default: false,
		},
		muted: {
			type: 'boolean',
			source: 'attribute',
			selector: 'stream',
			attribute: 'muted',
			default: false,
		},
		controls: {
			type: 'boolean',
			source: 'attribute',
			selector: 'stream',
			attribute: 'controls',
			default: true,
		},
		transform: {
			type: 'boolean',
			source: 'attribute',
			selector: 'stream',
			attribute: 'transform',
			default: false,
		},
	},

	supports: {
		align: true,
	},

	/**
	 * The edit function describes the structure of your block in the context of the editor.
	 * This represents what the editor will render when the block is used.
	 *
	 * The "edit" property must be a valid function.
	 *
	 * @link https://wordpress.org/gutenberg/handbook/block-api/block-edit-save/
	 */
	edit,

	/**
	 * The save function defines the way in which the different attributes should be combined
	 * into the final markup, which is then serialized by Gutenberg into post_content.
	 *
	 * The "save" property must be specified and must be a valid function.
	 *
	 * @link https://wordpress.org/gutenberg/handbook/block-api/block-edit-save/
 	 * @param {object} props Block Properties.
	 * @returns {object} A WordPress block.
	 */
	save: function( props ) {
		const { uid, controls, autoplay, loop, muted, className } = props.attributes;
		if ( uid !== false ) {
			// Create block UI using WordPress createElement
			return wp.element.createElement(
				'figure',
				{
					className: className,
					key: uid,
				},
				[
					wp.element.createElement(
						'stream',
						{
							src: uid,
							controls: controls,
							autoplay: autoplay,
							loop: loop,
							muted: muted,
						},
					),
					wp.element.createElement(
						'div',
						{
							className: 'target',
						}
					),
					wp.element.createElement(
						'script',
						{
							'data-cfasync': false,
							defer: true,
							type: 'text/javascript',
							src: 'https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=' + uid,
						},
					),
				]
			);
		}

		return wp.element.createElement(
			'figure',
			{
				className: className,
			},
		);
	},

} );
