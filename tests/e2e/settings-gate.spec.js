/**
 * End-to-end: the Publish gate and Email notifications sections of the
 * settings screen.
 *
 * Needs the test-only mu-plugin for `setSettings()`.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	allNotifications,
	createWorkflowUser,
	loginAs,
	setSettings,
} from './utils';

const SETTINGS = '/wp-admin/admin.php?page=sit-cwm-settings';
const APPROVED_EVENT = 'Content is approved (sent to the author)';
const SELF_APPROVAL = 'Prevent people from approving their own content';

test.describe( 'Settings: publish gate and notifications', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await createWorkflowUser(
			requestUtils,
			'cwm_settings_editor',
			'editor'
		);
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			post_types: [ 'post', 'page' ],
			publish_gate: {},
			notifications: allNotifications( false ),
			prevent_self_approval: false,
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			publish_gate: {},
			notifications: allNotifications( false ),
			prevent_self_approval: false,
		} );
		await requestUtils.deleteAllUsers();
	} );

	test( 'saves gate modes, a notification and self-approval', async ( {
		page,
	} ) => {
		await page.goto( SETTINGS );

		await page
			.getByRole( 'combobox', { name: 'Posts', exact: true } )
			.selectOption( { label: 'Enforce' } );
		await page
			.getByRole( 'combobox', { name: 'Pages', exact: true } )
			.selectOption( { label: 'Log only' } );
		await page.getByRole( 'checkbox', { name: APPROVED_EVENT } ).check();
		await page.getByRole( 'checkbox', { name: SELF_APPROVAL } ).check();
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		await page.reload();

		await expect(
			page.getByRole( 'combobox', { name: 'Posts', exact: true } )
		).toHaveValue( 'enforce' );
		await expect(
			page.getByRole( 'combobox', { name: 'Pages', exact: true } )
		).toHaveValue( 'log_only' );
		await expect(
			page.getByRole( 'checkbox', { name: APPROVED_EVENT } )
		).toBeChecked();
		await expect(
			page.getByRole( 'checkbox', { name: SELF_APPROVAL } )
		).toBeChecked();
	} );

	test( 'an editor gets no menu item and a 403 on the direct URL', async ( {
		page,
	} ) => {
		await loginAs( page, 'cwm_settings_editor' );

		await expect(
			page.locator( '#adminmenu a[href$="page=sit-cwm-settings"]' )
		).toHaveCount( 0 );

		const response = await page.goto( SETTINGS );

		expect( response.status() ).toBe( 403 );
	} );
} );
