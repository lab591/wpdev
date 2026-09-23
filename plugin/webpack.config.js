/**
 * wp-scripts defaults, with the asset metadata written as JSON (the plugin reads it with
 * json_decode instead of including a generated PHP file).
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter( ( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin' ),
		new DependencyExtractionWebpackPlugin( { outputFormat: 'json' } ),
	],
};
