/**
 * Records the product tutorial: a captioned video per chapter in
 * docs/tutorial/video/ and the stills for docs/tutorial/GUIDE.md in
 * docs/tutorial/images/.
 *
 * Drives tests/e2e/tutorial.spec.js through Playwright against an
 * already-running local WordPress, the same way bin/capture-screenshots.js
 * runs the screenshot spec. CWM_TUTORIAL is what un-skips the spec, so the CI
 * end-to-end job never picks it up.
 *
 * Usage, from the plugin root:
 *
 *     npm run tutorial
 *     npm run tutorial -- --grep 03-review
 *     npm run tutorial -- --headed
 *     npm run tutorial -- --base-url=http://127.0.0.1/cwm-e2e --user=admin --pass=password
 *
 * The spec deletes every post on the target site, so it defaults to the
 * scratch site http://127.0.0.1/cwm-e2e/ rather than the development site.
 * Prepare that site once with `php bin/setup-e2e-site.php --install` (see
 * docs/tutorial/README.md).
 */
const path = require( 'path' );
const fs = require( 'fs' );
const { spawn } = require( 'child_process' );

const OPTIONS = {
	'base-url': 'WP_BASE_URL',
	user: 'WP_USERNAME',
	pass: 'WP_PASSWORD',
};

const SPEC = 'tests/e2e/tutorial.spec.js';
const OUT = path.resolve( __dirname, '../docs/tutorial' );

const env = { ...process.env, CWM_TUTORIAL: '1' };
const passthrough = [];

for ( const arg of process.argv.slice( 2 ) ) {
	const match = /^--([^=]+)=(.*)$/.exec( arg );

	if ( match && OPTIONS[ match[ 1 ] ] ) {
		env[ OPTIONS[ match[ 1 ] ] ] = match[ 2 ];
	} else {
		passthrough.push( arg );
	}
}

// The scratch site, never the development site: the spec is destructive.
env.WP_BASE_URL = env.WP_BASE_URL || 'http://127.0.0.1/cwm-e2e/';

// e2e-test-utils-playwright resolves paths against this, so it needs the slash.
if ( ! env.WP_BASE_URL.endsWith( '/' ) ) {
	env.WP_BASE_URL += '/';
}

env.WP_USERNAME = env.WP_USERNAME || 'admin';
env.WP_PASSWORD = env.WP_PASSWORD || 'password';

fs.mkdirSync( path.join( OUT, 'video' ), { recursive: true } );
fs.mkdirSync( path.join( OUT, 'images' ), { recursive: true } );

/* eslint-disable no-console */
console.log( `Recording the tutorial from ${ env.WP_BASE_URL }` );
console.log( `Writing to ${ OUT }\n` );
/* eslint-enable no-console */

const wpScripts = require.resolve( '@wordpress/scripts/bin/wp-scripts.js' );

const child = spawn(
	process.execPath,
	[ wpScripts, 'test-playwright', SPEC, ...passthrough ],
	{ stdio: 'inherit', env }
);

child.on( 'exit', ( code ) => process.exit( code === null ? 1 : code ) );
