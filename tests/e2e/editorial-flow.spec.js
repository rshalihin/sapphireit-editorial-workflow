/**
 * End-to-end: one post through the whole editorial workflow (step 19.3).
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	ADMIN,
	createWorkflowUser,
	loginAs,
	moveTo,
	openWorkflowSidebar,
	statusBadge,
} from './utils';

const TITLE = 'E2E editorial flow';
const COMMENT = 'Please tighten the introduction.';

test.describe( 'Editorial workflow', () => {
	const users = {};

	/*
	 * One post through five users: nine editor loads and four logins, each a
	 * full page load. That does not fit the default per-test budget outside a
	 * warm CI container.
	 */
	test.slow();

	test.beforeAll( async ( { requestUtils } ) => {
		// Clean slate first, so two consecutive runs behave the same.
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		users.author = await createWorkflowUser(
			requestUtils,
			'cwm_author',
			'author'
		);
		users.reviewer = await createWorkflowUser(
			requestUtils,
			'cwm_reviewer',
			'editor'
		);
		users.editor = await createWorkflowUser(
			requestUtils,
			'cwm_editor',
			'editor'
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'moves a post from Draft to Published', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: TITLE, status: 'draft', author: users.author.id },
		} );

		// 1. The admin opens the sidebar: the post starts in Draft.
		let panel = await openWorkflowSidebar( page, post.id );
		await expect( statusBadge( panel ) ).toHaveText( 'Draft' );

		// 2. The author starts writing.
		await loginAs( page, 'cwm_author' );
		panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Writing' );

		// 3. Reviewer and due date. Authors do not hold
		// sit_cwm_assign_reviewer by default (D5), so an editor assigns them.
		await loginAs( page, 'cwm_editor' );
		panel = await openWorkflowSidebar( page, post.id );
		await panel
			.getByRole( 'combobox', { name: 'Reviewer' } )
			.fill( 'cwm_reviewer' );
		await page.getByRole( 'option', { name: 'cwm_reviewer' } ).click();
		await expect(
			panel.getByRole( 'combobox', { name: 'Reviewer' } )
		).toHaveValue( 'cwm_reviewer' );

		// The date picker's day grid is not a stable selector surface, so the
		// date is set with the same authenticated request the control sends.
		await page.evaluate(
			( id ) =>
				window.wp.apiFetch( {
					path: `/sit-cwm/v1/posts/${ id }/workflow`,
					method: 'POST',
					data: { due_date: '2030-01-15' },
				} ),
			post.id
		);

		// 4. The author submits for review.
		await loginAs( page, 'cwm_author' );
		panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Review' );

		// 5. The reviewer sends it back with a comment.
		await loginAs( page, 'cwm_reviewer' );
		panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Needs Changes', { confirm: true } );
		await panel
			.getByRole( 'textbox', { name: 'Add a workflow comment' } )
			.fill( COMMENT );
		await panel.getByRole( 'button', { name: 'Add comment' } ).click();
		await expect( panel.getByText( COMMENT ) ).toBeVisible();

		// 6. The author reworks it and resubmits.
		await loginAs( page, 'cwm_author' );
		panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Writing' );
		await moveTo( page, panel, 'Review' );

		// 7. The reviewer approves.
		await loginAs( page, 'cwm_reviewer' );
		panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Approved', { confirm: true } );

		// 8. The editor marks it published.
		await loginAs( page, 'cwm_editor' );
		panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Published', { confirm: true } );

		// 9. The history holds every event in order, with the right actors.
		const activity = await requestUtils.rest( {
			path: `/sit-cwm/v1/posts/${ post.id }/activity`,
			params: { per_page: 50 },
		} );

		expect(
			activity
				.map( ( entry ) => [
					entry.action === 'status_changed'
						? `status_changed:${ entry.new_value }`
						: entry.action,
					entry.user_id,
				] )
				.reverse()
		).toEqual( [
			[ 'status_changed:writing', users.author.id ],
			[ 'reviewer_assigned', users.editor.id ],
			[ 'due_date_set', users.editor.id ],
			[ 'status_changed:review', users.author.id ],
			[ 'status_changed:needs_changes', users.reviewer.id ],
			[ 'comment_added', users.reviewer.id ],
			[ 'status_changed:writing', users.author.id ],
			[ 'status_changed:review', users.author.id ],
			[ 'status_changed:approved', users.reviewer.id ],
			[ 'status_changed:published', users.editor.id ],
		] );

		// 10. The dashboard lists the post with its final status and reviewer.
		await loginAs( page, ADMIN.username, ADMIN.password );
		await page.goto(
			`/wp-admin/admin.php?page=sit-cwm-dashboard&search=${ encodeURIComponent(
				TITLE
			) }`
		);

		const row = page.getByRole( 'row', { name: new RegExp( TITLE ) } );

		await expect( row ).toContainText( 'Published' );
		await expect( row ).toContainText( 'cwm_reviewer' );
	} );
} );
