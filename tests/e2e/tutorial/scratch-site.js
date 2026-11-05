/**
 * Keeps the tutorial off real sites.
 *
 * The tutorial deletes every post and the whole activity log on its target, so
 * it runs only against the scratch site, or against a site the developer names
 * twice: once as the base URL and once with `--destroy-site=<url>`
 * (`CWM_TUTORIAL_SITE`). An inherited `WP_BASE_URL` or a mistyped
 * `--base-url` is refused before anything is touched.
 *
 * CommonJS, because both bin/record-tutorial.js and the spec load it.
 */

/**
 * The scratch site the tutorial records on by default.
 *
 * @type {string}
 */
const SCRATCH_URL = 'http://127.0.0.1/cwm-e2e/';

/**
 * Normalises a URL for comparison: trimmed, with one trailing slash.
 *
 * @param {string} url URL.
 * @return {string} Normalised URL.
 */
function withSlash( url ) {
	return String( url || '' )
		.trim()
		.replace( /\/*$/, '/' );
}

/**
 * Throws unless the tutorial may destroy the content of this site.
 *
 * @param {string} baseUrl   Site the run targets.
 * @param {string} [allowed] Site named with `--destroy-site`.
 * @return {string} The normalised target URL.
 */
function assertScratchSite( baseUrl, allowed = process.env.CWM_TUTORIAL_SITE ) {
	const target = withSlash( baseUrl );

	if (
		target === SCRATCH_URL ||
		( allowed && target === withSlash( allowed ) )
	) {
		return target;
	}

	throw new Error(
		`Refusing to record the tutorial on ${ target }: the run deletes every ` +
			`post and the activity log there. Use the scratch site ` +
			`${ SCRATCH_URL }, or confirm this site is disposable with ` +
			`--destroy-site=${ target }`
	);
}

module.exports = { SCRATCH_URL, assertScratchSite };
