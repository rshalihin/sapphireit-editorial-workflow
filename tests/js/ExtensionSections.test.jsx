/**
 * WordPress dependencies
 */
import { addFilter, removeAllFilters } from '@wordpress/hooks';

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ExtensionSections from '../../src/components/ExtensionSections';

const HOOK = 'sitCwm.workflowPanel.sections';

describe( 'ExtensionSections', () => {
	afterEach( () => removeAllFilters( HOOK ) );

	it( 'renders nothing with no filter registered', () => {
		const { container } = render(
			<ExtensionSections postId={ 1 } workflow={ {} } />
		);

		expect( container.textContent ).toBe( '' );
		expect( container.children ).toHaveLength( 0 );
	} );

	it( 'renders filtered sections sorted by order with the context', () => {
		addFilter( HOOK, 'test', ( sections ) => [
			...sections,
			{
				name: 'b',
				order: 20,
				render: ( { postId } ) => <p>B { postId }</p>,
			},
			{ name: 'a', order: 10, render: () => <p>A</p> },
		] );

		render(
			<ExtensionSections
				postId={ 7 }
				isClassic={ false }
				workflow={ { status: 'review' } }
			/>
		);

		const items = screen.getAllByText( /^(A|B 7)$/ );

		expect( items.map( ( node ) => node.textContent ) ).toEqual( [
			'A',
			'B 7',
		] );
	} );

	it( 'breaks an order tie by name', () => {
		addFilter( HOOK, 'test', () => [
			{ name: 'z', order: 5, render: () => <p>Z</p> },
			{ name: 'c', order: 5, render: () => <p>C</p> },
		] );

		render( <ExtensionSections postId={ 1 } workflow={ {} } /> );

		expect(
			screen.getAllByText( /^(Z|C)$/ ).map( ( node ) => node.textContent )
		).toEqual( [ 'C', 'Z' ] );
	} );

	it( 'passes the filter and the section the same context', () => {
		const filter = jest.fn( () => [
			{
				name: 'a',
				order: 1,
				render: ( { isClassic, workflow } ) => (
					<p>{ `${ isClassic } ${ workflow.status }` }</p>
				),
			},
		] );
		const workflow = { status: 'review' };

		addFilter( HOOK, 'test', filter );
		render(
			<ExtensionSections postId={ 9 } isClassic workflow={ workflow } />
		);

		expect( filter ).toHaveBeenCalledWith( [], {
			postId: 9,
			isClassic: true,
			workflow,
		} );
		expect( screen.getByText( 'true review' ) ).toBeTruthy();
	} );

	it( 'a filter callback that throws does not break the panel', () => {
		addFilter( HOOK, 'test', () => {
			throw new Error( 'boom' );
		} );

		const { container } = render(
			<ExtensionSections postId={ 1 } workflow={ {} } />
		);

		expect( container.textContent ).toBe( '' );
		expect( console ).toHaveErrored();
	} );

	it( 'ignores a non-array result', () => {
		addFilter( HOOK, 'test', () => 'nope' );

		const { container } = render(
			<ExtensionSections postId={ 1 } workflow={ {} } />
		);

		expect( container.textContent ).toBe( '' );
		expect( container.children ).toHaveLength( 0 );
	} );

	it( 'ignores malformed entries and keeps the good ones', () => {
		addFilter( HOOK, 'test', () => [
			null,
			'text',
			{ name: 1, order: 1, render: () => <p>Bad name</p> },
			{ name: 'x', order: '1', render: () => <p>Bad order</p> },
			{ name: 'y', order: 1, render: 'nope' },
			{ name: 'ok', order: 1, render: () => <p>Good</p> },
		] );

		const { container } = render(
			<ExtensionSections postId={ 1 } workflow={ {} } />
		);

		expect( container.textContent ).toBe( 'Good' );
	} );

	it( 'a section that throws does not break the panel or its neighbours', () => {
		addFilter( HOOK, 'test', () => [
			{
				name: 'x',
				order: 1,
				render: () => {
					throw new Error( 'boom' );
				},
			},
			{ name: 'y', order: 2, render: () => <p>Still here</p> },
		] );

		expect( () =>
			render( <ExtensionSections postId={ 1 } workflow={ {} } /> )
		).not.toThrow();
		expect( screen.getByText( 'Still here' ) ).toBeTruthy();
		expect( console ).toHaveErrored();
	} );
} );
