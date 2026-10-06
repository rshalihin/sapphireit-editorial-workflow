/**
 * Playwright config: the @wordpress/scripts preset, with specs in tests/e2e/.
 *
 * Two ways to run:
 *
 * - wp-env (Docker), the CI path. With no WP_BASE_URL set the suite targets
 *   http://localhost:8889 and starts wp-env itself.
 * - Any local WordPress (Laragon, MAMP, a plain vhost). Set WP_BASE_URL — and
 *   WP_USERNAME / WP_PASSWORD if they are not admin/password — and no web
 *   server is started, because the site is already running. `npm run
 *   test:e2e:local` does that for you; prepare the site once with
 *   `php bin/setup-e2e-site.php`.
 */
const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

// An explicit base URL means "an already-running site", so wp-env stays out.
const usesWpEnv = ! process.env.WP_BASE_URL;

module.exports = {
	...baseConfig,
	testDir: path.join( __dirname, 'tests/e2e' ),
	testMatch: '**/*.spec.js',
	webServer: usesWpEnv
		? {
				...baseConfig.webServer,
				command: 'npm run env:start',
			}
		: undefined,
};
