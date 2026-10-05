/**
 * End-to-end: the editorial flow with the keyboard only (step 22).
 *
 * Every action is performed with keys (Tab, Enter, Space, Escape, typing and
 * arrow keys). `locator.focus()` only places the starting point of each step,
 * standing in for the Tab presses through the editor chrome that lead there,
 * which belong to core rather than to this plugin.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { createWorkflowUser, openWorkflowSidebar, statusBadge } from './utils';

const TITLE = 'E2E keyboard post';
const REVIEWER = 'cwm_kb_reviewer';
const BULK_TITLE = 'E2E keyboard bulk';

/**
 * Asserts that keyboard focus is on an element.
 *
 * @param {Object} locator Playwright locator.
 * @return {Promise<void>}
 */
const expectFocused = ( locator ) => expect( locator ).toBeFocused();

/**
 * Presses Tab until an element has focus, at most `limit` times.
 *
 * @param {Object} page    Playwright page.
 * @param {Object} locator Element to reach.
 * @param {number} [limit] Maximum presses.
 * @return {Promise<void>}
 */
async function tabTo( page, locator, limit = 6 ) {
	for ( let presses = 0; presses < limit; presses++ ) {
		if ( await locator.evaluate( ( el ) => el.matches( ':focus' ) ) ) {
			return;
		}
		await page.keyboard.press( 'Tab' );
	}
}

test.describe( 'Keyboard-only workflow', () => {
	let post;

	test.slow();

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		await createWorkflowUser( requestUtils, REVIEWER, 'editor' );

		post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: TITLE, status: 'draft' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'moves, assigns, schedules and approves from the sidebar', async ( {
		page,
	} ) => {
		const panel = await openWorkflowSidebar( page, post.id );
		const keyboard = page.keyboard;

		// Move to Writing: Enter on the button.
		await panel.getByRole( 'button', { name: 'Move to Writing' } ).focus();
		await keyboard.press( 'Enter' );
		await expect( statusBadge( panel ) ).toHaveText( 'Writing' );

		// The clicked button is replaced; focus stays in the actions group
		// instead of falling back to <body>.
		await expect(
			panel.getByRole( 'group', { name: 'Workflow actions' } )
		).toContainText( 'Move to Review' );
		await expect(
			panel.locator( '.sit-cwm-actions:focus, .sit-cwm-actions :focus' )
		).toHaveCount( 1 );

		// Reviewer: type to search, arrow to the match, Enter to pick.
		const reviewer = panel.getByRole( 'combobox', { name: 'Reviewer' } );

		await reviewer.focus();
		await keyboard.type( 'cwm_kb' );
		await expect(
			page.getByRole( 'option', { name: REVIEWER } )
		).toBeVisible();
		await keyboard.press( 'ArrowDown' );
		await keyboard.press( 'Enter' );
		await expect(
			panel.getByRole( 'combobox', { name: 'Reviewer' } )
		).toHaveValue( REVIEWER );

		// Due date: Enter opens the calendar, arrows move, Enter picks.
		const dueToggle = panel.getByRole( 'button', {
			name: 'Change due date: No due date',
		} );

		await dueToggle.focus();
		await keyboard.press( 'Enter' );

		const calendar = page.locator( '.sit-cwm-due-date-picker' );

		await expect( calendar ).toBeVisible();

		// Escape closes the calendar and returns focus to its toggle.
		await keyboard.press( 'Escape' );
		await expect( calendar ).toBeHidden();
		await expectFocused( dueToggle );

		await keyboard.press( 'Enter' );
		await expect( calendar ).toBeVisible();

		// Tab past the month buttons to the one day in the tab order (today),
		// then ArrowRight to tomorrow and Enter to pick it.
		const today = calendar
			.getByRole( 'application', { name: 'Calendar' } )
			.locator( 'button[tabindex="0"]' );

		await expect( today ).toHaveCount( 1 );
		await tabTo( page, today );
		await expectFocused( today );
		await keyboard.press( 'ArrowRight' );
		await keyboard.press( 'Enter' );
		await expect( calendar ).toBeHidden();
		await expect(
			panel.getByRole( 'button', { name: /^Change due date: (?!No)/ } )
		).toBeVisible();

		// Review, then Approve through the confirmation dialog.
		await panel.getByRole( 'button', { name: 'Move to Review' } ).focus();
		await keyboard.press( 'Enter' );
		await expect( statusBadge( panel ) ).toHaveText( 'Review' );

		const approve = panel.getByRole( 'button', {
			name: 'Move to Approved',
		} );
		const dialog = page.getByRole( 'dialog', { name: 'Approve content?' } );

		await approve.focus();
		await keyboard.press( 'Enter' );
		await expect( dialog ).toBeVisible();

		// Cancel is focused first, so a stray Enter never approves.
		await expectFocused( dialog.getByRole( 'button', { name: 'Cancel' } ) );

		// Escape cancels and hands focus back to the trigger.
		await keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expectFocused( approve );
		await expect( statusBadge( panel ) ).toHaveText( 'Review' );

		// Enter again, Tab to Approve, Space to confirm.
		await keyboard.press( 'Enter' );
		await expect( dialog ).toBeVisible();
		await keyboard.press( 'Tab' );
		await expectFocused(
			dialog.getByRole( 'button', { name: 'Approve', exact: true } )
		);
		await keyboard.press( 'Space' );
		await expect( dialog ).toBeHidden();
		await expect( statusBadge( panel ) ).toHaveText( 'Approved' );
	} );

	test( 'runs a dashboard bulk action from the keyboard', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const second = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: BULK_TITLE, status: 'draft' },
		} );

		await admin.visitAdminPage(
			'admin.php',
			`page=sit-cwm-dashboard&search=${ encodeURIComponent(
				BULK_TITLE
			) }&status=draft`
		);

		const rows = page.locator( '.dataviews-view-table tbody tr' );
		const keyboard = page.keyboard;

		await expect( rows ).toHaveCount( 1 );

		// Space ticks the row checkbox.
		await page.getByRole( 'checkbox', { name: BULK_TITLE } ).focus();
		await keyboard.press( 'Space' );

		const trigger = page.getByRole( 'button', { name: 'Change status' } );
		const dialog = page.getByRole( 'dialog', {
			name: 'Change workflow status',
		} );

		await trigger.focus();
		await keyboard.press( 'Enter' );
		await expect( dialog ).toBeVisible();

		// Escape closes the modal without changing anything.
		await keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();

		await trigger.focus();
		await keyboard.press( 'Enter' );
		await expect( dialog ).toBeVisible();

		// Type-ahead picks "Writing" in the native select.
		await dialog.getByRole( 'combobox', { name: 'New status' } ).focus();
		await keyboard.type( 'W' );
		await expect(
			dialog.getByRole( 'combobox', { name: 'New status' } )
		).toHaveValue( 'writing' );

		await dialog.getByRole( 'button', { name: 'Apply' } ).focus();
		await keyboard.press( 'Enter' );
		await expect(
			dialog.getByText( 'Move 1 item to Writing?' )
		).toBeVisible();

		await dialog
			.getByRole( 'button', { name: 'Yes, update 1 item' } )
			.focus();
		await keyboard.press( 'Enter' );

		await expect( page.locator( '.sit-cwm-bulk-result' ) ).toContainText(
			'1 post updated.'
		);

		const state = await requestUtils.rest( {
			path: `/sit-cwm/v1/posts/${ second.id }/workflow`,
		} );

		expect( state.status ).toBe( 'writing' );
	} );
} );
