/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import domReady from '@wordpress/dom-ready';

/**
 * External dependencies
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import ClassicPanel, { stopEnterSubmit } from '../../src/classic/ClassicPanel';
import { mount, ROOT_ID } from '../../src/classic/index';
import ReviewerControl from '../../src/sidebar/components/ReviewerControl';

jest.mock( '@wordpress/api-fetch' );

// Importing the entry point must not mount by itself; tests call `mount()`.
jest.mock( '@wordpress/dom-ready', () => jest.fn() );

// The real panel loads the workflow over REST. The stub records the props the
// classic mount passes and offers one control of each kind for the Enter guard.
jest.mock( '../../src/components/WorkflowPanel', () => {
	const actual = jest.requireActual( '../../src/components/WorkflowPanel' );

	return {
		__esModule: true,
		...actual,
		default: ( { postId, isClassic, className } ) => (
			<div
				data-testid="workflow-panel"
				data-post-id={ postId }
				data-classic={ String( isClassic ) }
				className={ className }
			>
				<label htmlFor="stub-due-date">Due date</label>
				<input id="stub-due-date" type="text" />
				<label htmlFor="stub-comment">Comment</label>
				<textarea id="stub-comment" />
				<button type="button">Approve</button>
			</div>
		),
	};
} );

const enablePost = () => {
	window.sitCwm.postTypes = [ { slug: 'post', label: 'Posts' } ];
};

beforeAll( () => {
	// jsdom has no layout; `ComboboxControl` scrolls the highlighted option.
	window.HTMLElement.prototype.scrollIntoView = () => {};
} );

afterEach( () => {
	apiFetch.mockReset();
	document.body.innerHTML = '';
} );

describe( 'ClassicPanel', () => {
	it( 'renders the hint, the panel and the snackbars for an enabled type', () => {
		enablePost();

		const { container } = render(
			<ClassicPanel postId={ 42 } postType="post" />
		);

		expect(
			screen.getByText(
				'Workflow changes are saved immediately. You don’t need to click Update.'
			)
		).toBeTruthy();

		const panel = screen.getByTestId( 'workflow-panel' );
		expect( panel.dataset.postId ).toBe( '42' );
		expect( panel.dataset.classic ).toBe( 'true' );
		expect( panel.className ).toBe( 'sit-cwm-workflow-panel--classic' );

		expect(
			container.querySelector(
				'.sit-cwm-snackbars.sit-cwm-snackbars--classic'
			)
		).not.toBeNull();
	} );

	it( 'renders Unavailable for a post type without workflow', () => {
		enablePost();

		render( <ClassicPanel postId={ 42 } postType="product" /> );

		expect(
			screen.getByText( /Workflow is not available for this post type/ )
		).toBeTruthy();
		expect( screen.queryByTestId( 'workflow-panel' ) ).toBeNull();
	} );

	it( 'prevents Enter in an input from submitting the post form', () => {
		enablePost();
		render( <ClassicPanel postId={ 42 } postType="post" /> );

		const event = fireEvent.keyDown(
			screen.getByRole( 'textbox', { name: 'Due date' } ),
			{ key: 'Enter' }
		);

		// fireEvent returns false when the default action was prevented.
		expect( event ).toBe( false );
	} );

	it( 'leaves Enter alone in a textarea and on a button', () => {
		enablePost();
		render( <ClassicPanel postId={ 42 } postType="post" /> );

		expect(
			fireEvent.keyDown(
				screen.getByRole( 'textbox', { name: 'Comment' } ),
				{ key: 'Enter' }
			)
		).toBe( true );
		expect(
			fireEvent.keyDown(
				screen.getByRole( 'button', { name: 'Approve' } ),
				{
					key: 'Enter',
				}
			)
		).toBe( true );
	} );

	it( 'leaves other keys in an input alone', () => {
		enablePost();
		render( <ClassicPanel postId={ 42 } postType="post" /> );

		expect(
			fireEvent.keyDown(
				screen.getByRole( 'textbox', { name: 'Due date' } ),
				{ key: 'a' }
			)
		).toBe( true );
	} );
} );

describe( 'stopEnterSubmit with the reviewer combobox', () => {
	it( 'still lets Enter pick the highlighted reviewer', async () => {
		apiFetch.mockResolvedValue( [ { id: 7, name: 'Omar', avatar: '' } ] );
		const onChange = jest.fn();

		render(
			// eslint-disable-next-line jsx-a11y/no-static-element-interactions
			<div onKeyDown={ stopEnterSubmit }>
				<ReviewerControl
					postId={ 42 }
					reviewer={ null }
					onChange={ onChange }
					isSaving={ false }
				/>
			</div>
		);

		const input = screen.getByRole( 'combobox', { name: 'Reviewer' } );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'Om' } } );
		await screen.findByRole( 'option', { name: 'Omar' } );

		// `ComboboxControl` reads `code`; the guard reads `key`. Browsers send both.
		fireEvent.keyDown( input, { key: 'ArrowDown', code: 'ArrowDown' } );
		let event;
		await act( async () => {
			event = fireEvent.keyDown( input, { key: 'Enter', code: 'Enter' } );
		} );

		// The combobox handled Enter first (the guard only runs on bubbling),
		// and the default action is still prevented, so `#post` is not submitted.
		expect( onChange ).toHaveBeenCalledWith( 7 );
		expect( event ).toBe( false );
	} );
} );

describe( 'classic entry point', () => {
	const addRoot = ( dataset = {} ) => {
		const root = document.createElement( 'div' );
		root.id = ROOT_ID;
		Object.assign( root.dataset, dataset );
		document.body.appendChild( root );

		return root;
	};

	it( 'registers mount() for DOM ready', () => {
		expect( domReady ).toHaveBeenCalledWith( mount );
	} );

	it( 'does nothing without the root element', () => {
		expect( mount() ).toBe( false );
		expect( document.body.innerHTML ).toBe( '' );
	} );

	it( 'does nothing when the root has no post id', () => {
		const root = addRoot( { postType: 'post' } );
		root.innerHTML = '<p class="hide-if-js">No JS</p>';

		expect( mount() ).toBe( false );
		expect( root.innerHTML ).toBe( '<p class="hide-if-js">No JS</p>' );
	} );

	it( 'mounts the panel with the post id and type from the root', async () => {
		enablePost();
		const root = addRoot( { postId: '42', postType: 'post' } );

		let mounted;
		await act( async () => {
			mounted = mount();
		} );

		expect( mounted ).toBe( true );
		expect( root.querySelector( '.sit-cwm-classic' ) ).not.toBeNull();
		expect( screen.getByTestId( 'workflow-panel' ).dataset.postId ).toBe(
			'42'
		);
	} );
} );
