/**
 * Records the product tutorial: one captioned video per chapter in
 * docs/tutorial/video/ and the stills for docs/tutorial/GUIDE.md in
 * docs/tutorial/images/.
 *
 * Skipped unless CWM_TUTORIAL is set, so it never runs in the CI end-to-end
 * job: it is a generator, not a test, and it asserts only enough to be sure it
 * is filming the right thing.
 *
 * Run it with `npm run tutorial` (see bin/record-tutorial.js), which points it
 * at the scratch site and sets the flag. It is destructive — the seed deletes
 * every post — so never point it at a site whose content you care about.
 */

/**
 * WordPress dependencies
 */
import { test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { installOverlay } from './tutorial/overlay';
import { saveChapterVideo } from './tutorial/recorder';
import { resetSettings, seedTutorialSite } from './tutorial/seed';

// 16:9 at a size whose text stays readable on YouTube and on a phone. Video
// and stills share it, so the guide matches the video frame for frame.
const SIZE = { width: 1280, height: 720 };

// `launchOptions` is worker-scoped, so it has to be set at the top level.
test.use( {
	viewport: SIZE,
	video: { mode: 'on', size: SIZE },
	launchOptions: { slowMo: 120 },
} );

/**
 * The chapter slug a test title starts with, e.g. `01-setup` from
 * `01-setup: set up the workflow`.
 *
 * @param {string} title Test title.
 * @return {string} Slug.
 */
const slugFromTitle = ( title ) => title.split( ':' )[ 0 ].trim();

test.describe( 'Tutorial', () => {
	// eslint-disable-next-line playwright/no-skipped-test -- Deliberate: this file records the tutorial, so CI skips it.
	test.skip(
		! process.env.CWM_TUTORIAL,
		'Set CWM_TUTORIAL=1 (npm run tutorial) to record the tutorial.'
	);

	// Chapters hand state to the next one, so they run in order, one worker.
	test.describe.configure( { mode: 'serial' } );
	test.setTimeout( 10 * 60 * 1000 );

	test.beforeAll( async ( { requestUtils } ) => {
		await seedTutorialSite( requestUtils );
	} );

	test.beforeEach( async ( { page } ) => {
		await installOverlay( page );
	} );

	test.afterEach( async ( { page }, testInfo ) => {
		await saveChapterVideo( page, slugFromTitle( testInfo.title ) );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await resetSettings( requestUtils );
	} );
} );
