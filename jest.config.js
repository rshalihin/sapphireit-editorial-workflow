/**
 * Jest config: the @wordpress/scripts unit preset, scoped to tests/js/.
 */
const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...defaultConfig,
	rootDir: __dirname,
	roots: [ '<rootDir>/tests/js' ],
	setupFilesAfterEnv: [
		...( defaultConfig.setupFilesAfterEnv || [] ),
		'<rootDir>/tests/js/setup.js',
	],
	passWithNoTests: true,
	// Some dependencies of @wordpress/components ship ESM only (`uuid`, and
	// `.mjs` builds such as @wordpress/theme); let Babel compile those.
	transform: {
		'\\.m?[jt]sx?$':
			require.resolve( '@wordpress/scripts/config/babel-transform' ),
	},
	transformIgnorePatterns: [ '/node_modules/(?!uuid/)(?!.*\\.mjs$)' ],
};
