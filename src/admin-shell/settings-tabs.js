/**
 * Settings screen tabs (20b): turns the section links printed by
 * Sit_Cwm\Admin\Settings into ARIA tabs and shows one section at a time.
 *
 * Every panel stays inside the one form, so saving still submits every
 * setting. Without this script the links jump to sections that all show.
 */

/**
 * Selectors of the markup Settings::render() prints.
 *
 * @type {Object<string, string>}
 */
const SELECTORS = {
	tabs: '.sit-cwm-settings__tabs',
	tab: '.sit-cwm-settings__tab',
	panel: '.sit-cwm-settings__panel',
};

/**
 * Wires the tabs inside a settings form.
 *
 * @param {HTMLElement} form The `.sit-cwm-settings` form.
 */
export function initSettingsTabs( form ) {
	const nav = form.querySelector( SELECTORS.tabs );

	if ( ! nav ) {
		return;
	}

	const pairs = Array.from( nav.querySelectorAll( SELECTORS.tab ) )
		.map( ( tab ) => {
			const id = ( tab.getAttribute( 'href' ) || '' ).replace( /^#/, '' );
			const panel = id ? form.ownerDocument.getElementById( id ) : null;

			return panel && panel.matches( SELECTORS.panel )
				? { tab, panel, id }
				: null;
		} )
		.filter( Boolean );

	if ( ! pairs.length ) {
		return;
	}

	const list = nav.querySelector( 'ul' );

	if ( list ) {
		list.setAttribute( 'role', 'tablist' );
		list.setAttribute( 'aria-orientation', 'vertical' );
		Array.from( list.children ).forEach( ( item ) =>
			item.setAttribute( 'role', 'presentation' )
		);
	}

	pairs.forEach( ( { tab, panel } ) => {
		tab.setAttribute( 'role', 'tab' );
		tab.setAttribute( 'aria-controls', panel.id );
		panel.setAttribute( 'role', 'tabpanel' );
		panel.setAttribute( 'aria-labelledby', tab.id );
		panel.setAttribute( 'tabindex', '0' );
	} );

	form.classList.add( 'has-tabs' );

	const select = ( index, { focus = false, remember = true } = {} ) => {
		pairs.forEach( ( pair, i ) => {
			const isCurrent = i === index;

			pair.tab.setAttribute(
				'aria-selected',
				isCurrent ? 'true' : 'false'
			);
			pair.tab.setAttribute( 'tabindex', isCurrent ? '0' : '-1' );
			pair.tab.classList.toggle( 'is-current', isCurrent );
			pair.panel.hidden = ! isCurrent;
		} );

		if ( focus ) {
			pairs[ index ].tab.focus();
		}

		if ( remember ) {
			const { history, location } = form.ownerDocument.defaultView;
			const url = `${ location.pathname }${ location.search }#${ pairs[ index ].id }`;

			history.replaceState( history.state, '', url );
		}
	};

	const indexOfHash = () => {
		const hash = form.ownerDocument.defaultView.location.hash.replace(
			/^#/,
			''
		);
		const index = pairs.findIndex( ( pair ) => pair.id === hash );

		return index < 0 ? 0 : index;
	};

	pairs.forEach( ( { tab }, index ) => {
		tab.addEventListener( 'click', ( event ) => {
			event.preventDefault();
			select( index );
		} );

		tab.addEventListener( 'keydown', ( event ) => {
			const last = pairs.length - 1;
			const moves = {
				ArrowDown: index === last ? 0 : index + 1,
				ArrowRight: index === last ? 0 : index + 1,
				ArrowUp: index === 0 ? last : index - 1,
				ArrowLeft: index === 0 ? last : index - 1,
				Home: 0,
				End: last,
			};

			if ( event.key in moves ) {
				event.preventDefault();
				select( moves[ event.key ], { focus: true } );
			}
		} );
	} );

	// A settings error after saving names its field: open the tab holding it.
	form.addEventListener(
		'invalid',
		( event ) => {
			const index = pairs.findIndex( ( { panel } ) =>
				panel.contains( event.target )
			);

			if ( index >= 0 ) {
				select( index );
			}
		},
		true
	);

	select( indexOfHash(), { remember: false } );

	form.ownerDocument.defaultView.addEventListener( 'hashchange', () =>
		select( indexOfHash(), { remember: false } )
	);
}

/**
 * Wires every settings form on the page.
 *
 * @param {Document} doc Document.
 */
export default function initAll( doc ) {
	doc.querySelectorAll( '.sit-cwm-settings' ).forEach( initSettingsTabs );
}
