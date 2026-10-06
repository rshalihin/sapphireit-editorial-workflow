/**
 * Admin dashboard entry point.
 */

/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './App';
import './dashboard.scss';

const ROOT_ID = 'sit-cwm-dashboard';

const container = document.getElementById( ROOT_ID );

if ( container ) {
	createRoot( container ).render( <App /> );
}
