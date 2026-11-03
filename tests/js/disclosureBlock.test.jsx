/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import Edit from '../../src/blocks/review-disclosure/edit';

// Outside a block editor, InspectorControls renders into a slot nobody fills;
// render it in place so the toggles can be found.
jest.mock( '@wordpress/block-editor', () => ( {
	InspectorControls: ( { children } ) => (
		<div data-testid="inspector">{ children }</div>
	),
	useBlockProps: ( props = {} ) => props,
} ) );

const attributes = { showReviewer: true, showAiOrigin: true };

// Placeholder also announces its instructions through a11y.speak.
const INSTRUCTIONS = { selector: '.components-placeholder__instructions' };

describe( 'Review disclosure block Edit', () => {
	beforeAll( () => {
		// Placeholder measures itself; jsdom has no ResizeObserver.
		window.ResizeObserver = class {
			observe() {}
			unobserve() {}
			disconnect() {}
		};
	} );

	afterAll( () => {
		delete window.ResizeObserver;
	} );

	afterEach( () => {
		delete window.sitCwmDisclosure;
	} );

	it( 'shows the placeholder text', () => {
		render(
			<Edit attributes={ attributes } setAttributes={ jest.fn() } />
		);

		expect(
			screen.getByText(
				'The review disclosure appears here once this content is approved and published.',
				INSTRUCTIONS
			)
		).toBeTruthy();
	} );

	it( 'says when disclosures are turned off', () => {
		window.sitCwmDisclosure = { enabled: false };

		render(
			<Edit attributes={ attributes } setAttributes={ jest.fn() } />
		);

		expect(
			screen.getByText(
				'Review disclosures are turned off in Workflow settings.',
				INSTRUCTIONS
			)
		).toBeTruthy();
	} );

	it( 'puts both toggles in the inspector', () => {
		render(
			<Edit attributes={ attributes } setAttributes={ jest.fn() } />
		);

		const inspector = within( screen.getByTestId( 'inspector' ) );

		expect(
			inspector.getByRole( 'checkbox', {
				name: 'Show reviewer and date',
			} ).checked
		).toBe( true );
		expect(
			inspector.getByRole( 'checkbox', { name: 'Show AI use' } ).checked
		).toBe( true );
	} );

	it( 'calls setAttributes when a toggle changes', async () => {
		const setAttributes = jest.fn();

		render(
			<Edit attributes={ attributes } setAttributes={ setAttributes } />
		);

		await userEvent.click(
			screen.getByRole( 'checkbox', { name: 'Show reviewer and date' } )
		);

		expect( setAttributes ).toHaveBeenCalledWith( { showReviewer: false } );
	} );
} );
