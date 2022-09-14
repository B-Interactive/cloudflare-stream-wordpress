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
};

const supports = {
	align: true,
};

const save = function ( props ) {
	const { uid, controls, autoplay, loop, muted, className } =
		props.attributes;
	if ( uid !== false ) {
		// Create block UI using WordPress createElement
		return wp.element.createElement(
			'figure',
			{
				className,
				key: uid,
			},
			[
				wp.element.createElement( 'stream', {
					src: uid,
					controls,
					autoplay,
					loop,
					muted,
				} ),
				wp.element.createElement( 'div', {
					className: 'target',
				} ),
				wp.element.createElement( 'script', {
					'data-cfasync': false,
					defer: true,
					type: 'text/javascript',
					src:
						'https://embed.videodelivery.net/embed/r4xu.fla9.latest.js?video=' +
						uid,
				} ),
			]
		);
	}

	return wp.element.createElement( 'figure', {
		className,
	} );
};

export const deprecated_108 = {
	attributes,
	supports,
	save,
};
