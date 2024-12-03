/**
 * External Dependencies
 */
const path = require( 'path' );

const { resolve } = require( 'path' );
/**
 * WordPress Dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config.js' );

module.exports = ( env ) => {
	return {
		...defaultConfig,
		...{
			mode: env.dev ? 'development' : 'production',
			entry: {
				blocks: './src/blocks.js',
			},
			output: {
				filename: '[name].build.js',
				path: resolve( process.cwd(), 'dist' ),
			},
		},
	};
};
