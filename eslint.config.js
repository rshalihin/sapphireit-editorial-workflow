/**
 * ESLint flat config.
 *
 * The `@wordpress/scripts` package ships ESLint 10, which no longer reads
 * .eslintrc.* files, so this extends the flat config bundled with it (the
 * `@wordpress/eslint-plugin` recommended config plus unit-test overrides). Its
 * global ignores already cover `build` (assets/build) and `vendor` folders.
 */
const wpPlugin = require( '@wordpress/eslint-plugin' );
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,

	// Generated output: coverage reports, Playwright artifacts, release zips.
	{ ignores: [ 'coverage/**', 'artifacts/**', 'dist/**' ] },

	// Jest globals and test rules for everything under tests/js/.
	...wpPlugin.configs[ 'test-unit' ].map( ( config ) => ( {
		...config,
		files: [ 'tests/js/**/*.{js,jsx}' ],
	} ) ),

	// Playwright rules for the end-to-end specs under tests/e2e/.
	...wpPlugin.configs[ 'test-playwright' ].map( ( config ) => ( {
		...config,
		files: [ 'tests/e2e/**/*.js' ],
	} ) ),
];
