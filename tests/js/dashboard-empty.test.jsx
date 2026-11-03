/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import EmptyState from '../../src/dashboard/EmptyState';

describe( 'Dashboard empty state', () => {
	it( 'keeps its two strings and links to Getting Started', () => {
		window.sitCwm.gettingStartedUrl =
			'http://example.org/wp-admin/admin.php?page=sit-cwm-getting-started';

		render( <EmptyState /> );

		expect(
			screen.getByText( 'No content is in the workflow yet.' )
		).toBeTruthy();
		expect(
			screen.getByText(
				'Content of the workflow-enabled post types appears here as soon as it is created.'
			)
		).toBeTruthy();
		expect(
			screen
				.getByRole( 'link', { name: 'Open Getting Started' } )
				.getAttribute( 'href' )
		).toBe(
			'http://example.org/wp-admin/admin.php?page=sit-cwm-getting-started'
		);
	} );

	it( 'hides the link when the bootstrap has no URL', () => {
		render( <EmptyState /> );

		expect(
			screen.queryByRole( 'link', { name: 'Open Getting Started' } )
		).toBeNull();
	} );

	it( 'still offers to add content of the first enabled type', () => {
		window.sitCwm.postTypes = [
			{ slug: 'page', label: 'Pages', singularLabel: 'Page' },
		];

		render( <EmptyState /> );

		expect(
			screen
				.getByRole( 'link', { name: 'Add new Page' } )
				.getAttribute( 'href' )
		).toBe( 'http://example.org/wp-admin/post-new.php?post_type=page' );
	} );
} );
