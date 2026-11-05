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
	applySetupSettings,
	ensureChapterThreeStart,
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
	// A loaded machine can take several seconds to answer the panel's
	// requests, so wait longer than the 5 s default.
	const timeout = 15000;

	await expect( panel.locator( '.sit-cwm-activity' ) ).toHaveAttribute(
		'aria-busy',
		'false',
		{ timeout }
	);
	await expect(
		panel.locator( '.sit-cwm-reviewer .components-spinner' )
	).toHaveCount( 0, { timeout } );
	await expect( panel.locator( '.components-spinner' ) ).toHaveCount( 0, {
		timeout,
	} );
}

/**
 * Scrolls an element's top to just below the sidebar's sticky header, so a
 * still of it starts at its heading rather than wherever the last click left
 * it.
 *
 * @param {Object} locator Element locator.
 * @return {Promise<void>}
 */
async function toTop( locator ) {
	await locator.evaluate( ( el ) => {
		// Margins and the header height are in CSS pixels, like scroll offsets.
		const header = el
			.closest( '.interface-complementary-area' )
			?.querySelector( '.interface-complementary-area-header' );

		el.style.scrollMarginTop = header ? `${ header.offsetHeight }px` : '';
		el.scrollIntoView( { block: 'start' } );
		el.style.scrollMarginTop = '';
	} );
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
 * The comment Dana leaves when she asks for changes.
 *
 * @type {string}
 */
const REVIEW_COMMENT = 'Please add a source for the Q2 numbers.';

/**
 * Publishes the post open in the editor with visible clicks: the top bar's
 * Publish, then the pre-publish panel's Publish, then closes the panel.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
async function publishVisibly( page ) {
	await clickVisibly(
		page,
		page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Publish', exact: true } )
	);

	const publishPanel = page.getByRole( 'region', { name: 'Editor publish' } );

	await clickVisibly(
		page,
		publishPanel.getByRole( 'button', { name: 'Publish', exact: true } )
	);
	await expect( publishPanel.getByText( 'is now live' ) ).toBeVisible( {
		timeout: 20000,
	} );
	await clickVisibly(
		page,
		publishPanel.getByRole( 'button', { name: 'Close panel' } )
	);
}

/**
 * Shows the Editorial Workflow sidebar in the editor already open, with a
 * visible click on its toolbar button when another panel has replaced it.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<Object>} Locator of the sidebar panel.
 */
async function showWorkflowSidebar( page ) {
	const toggle = page
		.getByRole( 'region', { name: 'Editor top bar' } )
		.getByRole( 'button', { name: 'Editorial Workflow', exact: true } );

	if ( 'true' !== ( await toggle.getAttribute( 'aria-pressed' ) ) ) {
		await clickVisibly( page, toggle );
	}

	const panel = page.locator( '.sit-cwm-sidebar' );

	await expect( panel ).toBeVisible();

	return panel;
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
	let users;

	/**
	 * Seeded background post ids by title.
	 *
	 * @type {Object<string, number>}
	 */
	let ids;

	/**
	 * Id of the post chapter 2 creates, for chapter 3. Unset when chapter 2
	 * did not run in this invocation.
	 *
	 * @type {number|undefined}
	 */
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
		await toTop( panel );
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
		await toTop( panel );
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
		await toTop( panel );
		await still( page, '07-submitted-for-review', panel );

		tutorialPostId = id;
	} );

	test( '03-review-and-publish: review, approve and publish', async ( {
		page,
		requestUtils,
	} ) => {
		// Chapter 1's end state, off camera: a run that skipped chapter 1
		// would otherwise film an ungated post.
		await applySetupSettings( requestUtils );
		tutorialPostId ??= await ensureChapterThreeStart( requestUtils, users );

		const id = tutorialPostId;

		// Dana reviews and sends it back.
		await loginAs( page, PERSONAS.dana.username );

		let panel = await openWorkflowSidebar( page, id );

		await expect( statusBadge( panel ) ).toHaveText( 'Review' );
		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toContainText(
			'Publishing is locked until this content is approved.'
		);
		await settled( panel );
		await caption(
			page,
			'Dana Okafor reviews the post. Publishing stays locked until it is approved.'
		);
		await toTop( panel );
		await still( page, '08-locked-notice', panel );

		await caption(
			page,
			'Dana asks for changes, and says what to change in a workflow comment.'
		);
		await moveToVisibly( page, panel, 'Needs Changes', { confirm: true } );

		const comment = panel.getByRole( 'textbox', {
			name: 'Add a workflow comment',
		} );

		await clickVisibly( page, comment );
		await page.keyboard.type( REVIEW_COMMENT, { delay: 35 } );
		await clickVisibly(
			page,
			panel.getByRole( 'button', { name: 'Add comment' } )
		);
		// Posted once the box empties and the comment is in the history.
		await expect( comment ).toHaveValue( '' );

		const posted = panel
			.locator( '.sit-cwm-activity' )
			.getByText( REVIEW_COMMENT );

		await expect( posted ).toBeVisible();
		await settled( panel );
		await posted.evaluate( ( el ) =>
			el.scrollIntoView( { block: 'center' } )
		);
		await still( page, '09-needs-changes-comment', panel );

		// Sam reworks it and resubmits.
		await caption(
			page,
			'Sam adds the source, then sends the post back for review.'
		);
		await loginAs( page, PERSONAS.sam.username );
		panel = await openWorkflowSidebar( page, id );
		await settled( panel );
		await moveToVisibly( page, panel, 'Writing' );
		await moveToVisibly( page, panel, 'Review' );

		// Dana approves.
		await caption( page, 'Dana is happy with it now, and approves it.' );
		await loginAs( page, PERSONAS.dana.username );
		panel = await openWorkflowSidebar( page, id );
		await settled( panel );
		await moveToVisibly( page, panel, 'Approved', { confirm: true } );
		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toHaveCount(
			0
		);
		await settled( panel );
		await toTop( panel );
		await still( page, '10-approved', panel );

		// Alex publishes, then closes the workflow.
		await caption(
			page,
			'Alex Chen publishes it. The gate lets it through because it is approved.'
		);
		await loginAs( page, PERSONAS.alex.username );
		panel = await openWorkflowSidebar( page, id );
		await settled( panel );
		await publishVisibly( page );

		await caption(
			page,
			'Publishing the post and closing the workflow are separate steps: WordPress status and workflow status are tracked independently.'
		);
		panel = await showWorkflowSidebar( page );
		await moveToVisibly( page, panel, 'Published', { confirm: true } );

		// The timeline loads its entries once, so reopen the editor to show
		// the whole story, publication included.
		panel = await openWorkflowSidebar( page, id );

		const timeline = panel.locator( '.sit-cwm-activity' );

		await settled( panel );
		await expect( timeline ).toContainText( REVIEW_COMMENT );
		await toTop( timeline );
		await caption(
			page,
			'Every step is in the activity history: who did what, and when.'
		);
		await still( page, '11-published-timeline', timeline );
	} );

	test( '04-dashboard-and-report: track work on the dashboard', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const rows = page.locator( '.dataviews-view-table tbody tr' );

		for ( const title of Object.keys( ids ) ) {
			await expect(
				page.getByText( title, { exact: true } )
			).toBeVisible( { timeout: 20000 } );
		}

		await caption(
			page,
			'The dashboard lists everything in the workflow: its status, its reviewer and when it is due.'
		);

		// 1. Sort by due date: the soonest comes first.
		await caption(
			page,
			'Sort by due date to see what needs attention first.'
		);
		await clickVisibly(
			page,
			page.getByRole( 'button', { name: 'Due', exact: true } )
		);
		await clickVisibly(
			page,
			page.getByRole( 'menuitemradio', { name: 'Sort ascending' } )
		);
		// A radio item keeps the menu open; close it like a person would.
		await page.keyboard.press( 'Escape' );
		await expect( rows.first() ).toContainText(
			'Accessibility audit findings'
		);
		await still( page, '12-dashboard' );

		// 2. Filter to Review: two rows.
		await caption(
			page,
			'Filter by status to see only what is waiting for review.'
		);
		await clickVisibly(
			page,
			page.getByRole( 'button', { name: 'Add filter' } )
		);
		await clickVisibly(
			page,
			page.getByRole( 'menuitem', { name: 'Status', exact: true } )
		);
		await clickVisibly(
			page,
			page.getByRole( 'option', { name: 'Review', exact: true } )
		);
		// Close the filter popover with a click on empty page space. Escape
		// would hand focus back to the filter button, whose tooltip would
		// then sit in the still.
		await clickVisibly(
			page,
			page.getByText(
				'Everything moving through the editorial workflow.'
			)
		);
		await expect( page.getByRole( 'tooltip' ) ).toHaveCount( 0 );
		await expect( rows ).toHaveCount( 2 );
		await expect(
			rows.getByText( 'Q3 launch announcement' )
		).toBeVisible();
		await expect(
			rows.getByText( 'Migrating to block themes' )
		).toBeVisible();
		await still( page, '13-dashboard-filtered' );

		// 3. Search: one row, then open it.
		await caption(
			page,
			'Search finds a post by its title. Open it to work on it.'
		);

		const search = page.getByRole( 'searchbox' );

		await clickVisibly( page, search );
		await page.keyboard.type( 'launch', { delay: 60 } );
		await expect( rows ).toHaveCount( 1 );
		await clickVisibly(
			page,
			rows.getByRole( 'link', { name: 'Q3 launch announcement' } )
		);
		await page.waitForURL( '**/post.php?post=*' );
		await page.waitForFunction(
			() =>
				!! window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
		);
		await closeWelcomeGuide( page );

		// The governance report.
		await caption(
			page,
			'The governance report shows how content was published: approved first, or not.'
		);
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-report' );
		await expect(
			page.locator( '.sit-cwm-report__headline-value' )
		).not.toHaveText( '—' );
		await still( page, '14-governance-report' );
	} );

	test( '05-ai-use-and-attestation: declare AI use and confirm review', async ( {
		admin,
		page,
	} ) => {
		// The administrator turns on review attestation.
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );

		const tab = await openSettingsTab( page, 'Review attestation' );

		await caption(
			page,
			'Optionally, approvers must confirm they reviewed the content before approving it.'
		);
		await tickVisibly(
			page,
			tab.getByRole( 'checkbox', {
				name: 'Require approvers to confirm they reviewed the content',
			} )
		);
		await saveSettings( page );

		// Dana declares AI use, confirms the review and approves.
		await caption(
			page,
			'Dana Okafor records how the post was made, then confirms the review and approves it.'
		);
		await loginAs( page, PERSONAS.dana.username );

		const panel = await openWorkflowSidebar(
			page,
			ids[ 'Migrating to block themes' ]
		);

		await settled( panel );

		const aiUse = panel.getByRole( 'combobox', { name: 'AI use' } );

		await clickVisibly( page, aiUse );
		await aiUse.selectOption( { label: 'AI-assisted' } );
		await expect( aiUse ).toHaveValue( 'ai_assisted' );
		await settled( panel );
		await toTop( panel );
		await still( page, '15-ai-use', panel );

		const confirmation = panel.getByRole( 'checkbox', {
			name: 'I confirm I have reviewed this content and approve it for publication.',
		} );

		await tickVisibly( page, confirmation );
		await confirmation.evaluate( ( el ) =>
			el.scrollIntoView( { block: 'center' } )
		);
		await still( page, '16-attestation', panel );
		await moveToVisibly( page, panel, 'Approved', { confirm: true } );
	} );
} );
