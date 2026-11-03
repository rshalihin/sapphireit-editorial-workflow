/**
 * Governance report entry point.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './App';
import './report.scss';

const ROOT_ID = 'sit-cwm-report';

domReady( () => {
	const container = document.getElementById( ROOT_ID );

	if ( container ) {
		createRoot( container ).render( <App /> );
	}
} );
