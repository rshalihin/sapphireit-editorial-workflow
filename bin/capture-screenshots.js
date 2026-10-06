/**
 * Regenerates the documentation screenshots in docs/screenshots/.
 *
 * Drives tests/e2e/screenshots.spec.js through Playwright against an
 * already-running local WordPress, the same way bin/e2e-local.js runs the test
 * suite. CWM_CAPTURE is what un-skips the spec, so the CI end-to-end job never
 * picks it up.
 *
 * Usage, from the plugin root:
 *
 *     npm run screenshots
 *     npm run screenshots -- --base-url=http://127.0.0.1/cwm-e2e --user=admin --pass=password
 *     npm run screenshots -- --headed
 *
 * The spec deletes every post on the target site before and after it runs, so
 * point it at a scratch site — see DEVELOPMENT.md. Prepare one once with
 * `php bin/setup-e2e-site.php --install`.
 */
const path = require( 'path' );
const fs = require( 'fs' );
const { spawn } = require( 'child_process' );

const OPTIONS = {
	'base-url': 'WP_BASE_URL',
	user: 'WP_USERNAME',
	pass: 'WP_PASSWORD',
};

const SPEC = 'tests/e2e/screenshots.spec.js';
const OUT = path.resolve( __dirname, '../docs/screenshots' );

const env = { ...process.env, CWM_CAPTURE: '1' };
const passthrough = [];

for ( const arg of process.argv.slice( 2 ) ) {
	const match = /^--([^=]+)=(.*)$/.exec( arg );

	if ( match && OPTIONS[ match[ 1 ] ] ) {
		env[ OPTIONS[ match[ 1 ] ] ] = match[ 2 ];
	} else {
		passthrough.push( arg );
	}
}

if ( ! env.WP_BASE_URL ) {
	// Laragon serves G:/laragon/www/<site> as http://<site>.test, and this
	// plugin lives in that site's wp-content/plugins.
	const site = path.basename( path.resolve( __dirname, '../../../..' ) );

	env.WP_BASE_URL = `http://${ site }.test/`;
}

if ( ! env.WP_BASE_URL.endsWith( '/' ) ) {
	env.WP_BASE_URL += '/';
}

env.WP_USERNAME = env.WP_USERNAME || 'admin';
env.WP_PASSWORD = env.WP_PASSWORD || 'password';

fs.mkdirSync( OUT, { recursive: true } );

/* eslint-disable no-console */
console.log( `Capturing screenshots from ${ env.WP_BASE_URL }` );
console.log( `Writing to ${ OUT }\n` );
/* eslint-enable no-console */

const wpScripts = require.resolve( '@wordpress/scripts/bin/wp-scripts.js' );

const child = spawn(
	process.execPath,
	[ wpScripts, 'test-playwright', SPEC, ...passthrough ],
	{ stdio: 'inherit', env }
);

child.on( 'exit', ( code ) => process.exit( code === null ? 1 : code ) );
