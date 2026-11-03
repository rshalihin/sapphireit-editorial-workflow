/**
 * Webpack config: the @wordpress/scripts default with the plugin's entry
 * points (block editor sidebar, admin dashboard, classic editor meta box,
 * admin shell styles, governance report, Review disclosure block) and output in assets/build/. The default
 * DependencyExtractionWebpackPlugin stays in place and emits *.asset.php.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		sidebar: path.resolve( __dirname, 'src/sidebar/index.js' ),
		dashboard: path.resolve( __dirname, 'src/dashboard/index.js' ),
		classic: path.resolve( __dirname, 'src/classic/index.js' ),
		'admin-shell': path.resolve( __dirname, 'src/admin-shell/index.js' ),
		report: path.resolve( __dirname, 'src/report/index.js' ),
		'review-disclosure': path.resolve(
			__dirname,
			'src/blocks/review-disclosure/index.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
};
