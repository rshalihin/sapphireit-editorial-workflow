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
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	caption,
	clickVisibly,
	glide,
	installOverlay,
} from './tutorial/overlay';
import {
	moveToVisibly,
	pickDueDate,
	saveChapterVideo,
	still,
} from './tutorial/recorder';
import {
	PERSONAS,
	TUTORIAL_CONTENT,
	TUTORIAL_TITLE,
	resetSettings,
	seedTutorialSite,
} from './tutorial/seed';
import { loginAs, openWorkflowSidebar, statusBadge } from './utils';

/**
 * Waits until everything the workflow panel fetches has landed, so a still
 * never shows loading skeletons or a spinning reviewer field.
 *
 * @param {Object} panel Sidebar panel locator.
 * @return {Promise<void>}
 */
async function settled( panel ) {
	await expect( panel.locator( '.sit-cwm-activity' ) ).toHaveAttribute(
		'aria-busy',
		'false'
	);
	await expect(
		panel.locator( '.sit-cwm-reviewer .components-spinner' )
	).toHaveCount( 0 );
	await expect( panel.locator( '.components-spinner' ) ).toHaveCount( 0 );
}

/**
 * Opens a settings tab with a visible click.
 *
 * @param {Object} page Playwright page.
 * @param {string} name Tab name.
 * @return {Promise<Object>} Locator of the tab's panel.
 */
async function openSettingsTab( page, name ) {
	await clickVisibly( page, page.getByRole( 'tab', { name } ) );
	await expect(
		page.getByRole( 'tab', { name, selected: true } )
	).toBeVisible();

	return page.getByRole( 'tabpanel' );
}

/**
 * Ticks a checkbox with a visible click, unless it is ticked already.
 *
 * @param {Object} page     Playwright page.
 * @param {Object} checkbox Checkbox locator.
 * @return {Promise<void>}
 */
async function tickVisibly( page, checkbox ) {
	if ( ! ( await checkbox.isChecked() ) ) {
		await clickVisibly( page, checkbox );
	}
	await expect( checkbox ).toBeChecked();
}

/**
 * Turns off and closes the block editor's welcome guide, which a user who
 * never opened the editor would otherwise see.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
async function closeWelcomeGuide( page ) {
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false )
	);

	const guide = page.locator( '.components-guide' );

	if ( await guide.isVisible() ) {
		await page.keyboard.press( 'Escape' );
	}
	await expect( guide ).toHaveCount( 0 );
}

/**
 * Clicks the settings page's Save button and waits for the saved notice.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
async function saveSettings( page ) {
	await clickVisibly(
		page,
		page.getByRole( 'button', { name: 'Save changes' } )
	);
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
}

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

	/**
	 * Personas, keyed `sam`, `dana`, `alex`, from the seed.
	 *
	 * @type {Object<string, {id: number, username: string, name: string}>}
	 */
	// eslint-disable-next-line no-unused-vars -- TEMP: read by chapter 3.
	let users;

	/**
	 * Seeded background post ids by title.
	 *
	 * @type {Object<string, number>}
	 */
	// eslint-disable-next-line no-unused-vars -- TEMP: read by chapters 4 and 5.
	let ids;

	/**
	 * Id of the post chapter 2 creates, for chapter 3. Unset when chapter 2
	 * did not run in this invocation.
	 *
	 * @type {number|undefined}
	 */
	// eslint-disable-next-line no-unused-vars -- TEMP: read by chapter 3.
	let tutorialPostId;

	test.beforeAll( async ( { requestUtils } ) => {
		( { users, ids } = await seedTutorialSite( requestUtils ) );
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

	test( '01-setup: set up the workflow', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=sit-cwm-getting-started'
		);
		await expect(
			page.locator( '.sit-cwm-getting-started' )
		).toBeVisible();
		await caption(
			page,
			'SapphireIT Editorial Workflow adds an approval step before anything goes live.'
		);
		await still( page, '01-getting-started' );

		// Each caption goes up before the clicks it describes, so it narrates
		// them; the still follows once the change is on screen.

		// Workflow: which content types use it, and self-approval.
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );

		let tab = await openSettingsTab( page, 'Workflow' );
		const selfApproval = tab.getByRole( 'checkbox', {
			name: 'Prevent people from approving their own content',
		} );

		await caption(
			page,
			'Choose which content types use the workflow, and stop people approving their own work.'
		);
		await glide(
			page,
			tab.getByRole( 'checkbox', { name: 'Posts', exact: true } )
		);
		await clickVisibly( page, selfApproval );
		await expect( selfApproval ).toBeChecked();
		await still( page, '02-settings-workflow' );

		// Publish gate: Posts on Enforce.
		tab = await openSettingsTab( page, 'Publish gate' );

		const gate = tab.getByRole( 'combobox', {
			name: 'Posts',
			exact: true,
		} );

		await caption(
			page,
			'The publish gate: on Enforce, nothing is published until it is approved.'
		);
		await clickVisibly( page, gate );
		await gate.selectOption( { label: 'Enforce' } );
		await expect( gate ).toHaveValue( 'enforce' );
		await still( page, '03-settings-publish-gate' );
		await saveSettings( page );

		// Email notifications: every event on.
		tab = await openSettingsTab( page, 'Email notifications' );

		const boxes = tab.getByRole( 'checkbox' );

		await caption( page, 'Email the right person at each step.' );

		for ( let i = 0; i < ( await boxes.count() ); i++ ) {
			await tickVisibly( page, boxes.nth( i ) );
		}

		await still( page, '04-settings-notifications' );
		await saveSettings( page );
	} );

	test( '02-write-and-submit: write and submit for review', async ( {
		editor,
		page,
	} ) => {
		// Sam, an author, writes the post.
		await loginAs( page, PERSONAS.sam.username );
		await page.goto( '/wp-admin/post-new.php' );
		await page.waitForFunction(
			() =>
				!! window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
		);
		await closeWelcomeGuide( page );

		await caption(
			page,
			'Sam Rivera, an author, writes a new post in the block editor.'
		);
		await clickVisibly(
			page,
			editor.canvas.getByRole( 'textbox', { name: 'Add title' } )
		);
		await page.keyboard.type( TUTORIAL_TITLE, { delay: 35 } );
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( TUTORIAL_CONTENT, { delay: 35 } );

		await clickVisibly(
			page,
			page
				.getByRole( 'region', { name: 'Editor top bar' } )
				.getByRole( 'button', { name: 'Save draft' } )
		);
		await page.waitForFunction( () => {
			const store = window.wp.data.select( 'core/editor' );

			return (
				! store.isSavingPost() &&
				! store.isEditedPostDirty() &&
				'draft' === store.getCurrentPost().status
			);
		} );

		const id = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPostId()
		);

		let panel = await openWorkflowSidebar( page, id );

		await expect( statusBadge( panel ) ).toHaveText( 'Draft' );
		await settled( panel );
		await caption(
			page,
			'The Editorial Workflow panel shows where the post stands. Sam starts writing.'
		);
		await still( page, '05-sidebar-draft', panel );
		await moveToVisibly( page, panel, 'Writing' );

		// Alex, the managing editor, assigns a reviewer and a due date. The
		// caption goes up before the switch, so it announces who signs in.
		await caption(
			page,
			'Alex Chen, the managing editor, assigns a reviewer and a due date.'
		);
		await loginAs( page, PERSONAS.alex.username );
		panel = await openWorkflowSidebar( page, id );
		await settled( panel );

		const reviewer = panel.getByRole( 'combobox', { name: 'Reviewer' } );

		await clickVisibly( page, reviewer );
		await reviewer.fill( 'Dana' );
		await clickVisibly(
			page,
			page.getByRole( 'option', { name: PERSONAS.dana.name } )
		);
		await expect( reviewer ).toHaveValue( PERSONAS.dana.name );

		const due = await pickDueDate( page, panel, 7 );

		await expect(
			panel.getByRole( 'button', { name: /^Change due date:/ } )
		).toHaveAccessibleName( `Change due date: ${ due }` );
		await settled( panel );
		await still( page, '06-reviewer-and-due-date', panel );

		// Sam sends it for review.
		await caption(
			page,
			'When the draft is ready, Sam sends it for review.'
		);
		await loginAs( page, PERSONAS.sam.username );
		panel = await openWorkflowSidebar( page, id );
		await settled( panel );
		await moveToVisibly( page, panel, 'Review' );
		await settled( panel );
		await caption(
			page,
			'Dana Okafor, the reviewer, now has it to review, and the due date is tracked.'
		);
		await still( page, '07-submitted-for-review', panel );

		tutorialPostId = id;
	} );
} );
