/**
 * Deprecated iframe save
 *
 * Previous block version that wrote a public Stream iframe into post content.
 *
 * @package cloudflare-stream
 */

import { createElement } from '@wordpress/element';
import { streamIframeSource } from './lib';

const attributes = {
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
		default: false,
	},
	loop: {
		type: 'boolean',
		default: false,
	},
	muted: {
		type: 'boolean',
		default: false,
	},
	controls: {
		type: 'boolean',
		default: true,
	},
	transform: {
		type: 'boolean',
		default: false,
	},
};

const supports = {
	align: true,
};

/**
 * Old save output: figure + iframe with a bare Stream UID URL.
 *
 * @param {Object} props Block properties.
 * @return {Object} Saved block markup.
 */
const save = function ( props ) {
	const { uid, className } = props.attributes;
	if ( uid !== false ) {
		return createElement(
			'figure',
			{
				className,
				key: uid,
			},
			[
				createElement(
					'div',
					{
						className: 'player-wrapper',
					},
					[
						createElement(
							'iframe',
							{
								className: 'player-frame',
								src: streamIframeSource( props.attributes ),
								allow: 'accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture;',
								allowfullscreen: 'true',
							}
						),
					]
				),
			]
		);
	}

	return createElement(
		'figure',
		{
			className,
		}
	);
};

export const deprecated_iframe = {
	attributes,
	supports,
	save,
};
