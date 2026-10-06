/**
 * Runs the Playwright suite against an already-running local WordPress
 * (Laragon, MAMP, a plain vhost) instead of wp-env, so end-to-end tests need no
 * Docker.
 *
 * Setting WP_BASE_URL is what tells playwright.config.js not to start wp-env;
 * doing it here keeps the command identical on PowerShell, cmd and bash.
 *
 * Usage, from the plugin root:
 *
 *     npm run test:e2e:local
 *     npm run test:e2e:local -- --base-url=http://cwm-e2e.test --user=admin --pass=password
 *     npm run test:e2e:local -- --headed tests/e2e/dashboard.spec.js
 *
 * Unrecognised arguments are passed through to `wp-scripts test-playwright`.
 * Prepare the site once with `php bin/setup-e2e-site.php`.
 */
const path = require( 'path' );
const { spawn } = require( 'child_process' );

const OPTIONS = {
	'base-url': 'WP_BASE_URL',
	user: 'WP_USERNAME',
	pass: 'WP_PASSWORD',
};

/**
 * Laragon serves G:/laragon/www/<site> as http://<site>.test, and this plugin
 * lives in that site's wp-content/plugins. That is only a default: --base-url
 * and WP_BASE_URL both win over it.
 *
 * @return {string} Base URL, with a trailing slash.
 */
function guessBaseUrl() {
	const site = path.basename( path.resolve( __dirname, '../../../..' ) );

	return `http://${ site }.test/`;
}

const env = { ...process.env };
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
	env.WP_BASE_URL = guessBaseUrl();
}

// e2e-test-utils-playwright resolves paths against this, so it needs the slash.
if ( ! env.WP_BASE_URL.endsWith( '/' ) ) {
	env.WP_BASE_URL += '/';
}

env.WP_USERNAME = env.WP_USERNAME || 'admin';
env.WP_PASSWORD = env.WP_PASSWORD || 'password';

// eslint-disable-next-line no-console
console.log(
	`Running end-to-end tests against ${ env.WP_BASE_URL } as ${ env.WP_USERNAME }\n`
);

const wpScripts = require.resolve( '@wordpress/scripts/bin/wp-scripts.js' );

const child = spawn(
	process.execPath,
	[ wpScripts, 'test-playwright', ...passthrough ],
	{ stdio: 'inherit', env }
);

child.on( 'exit', ( code ) => process.exit( code === null ? 1 : code ) );
