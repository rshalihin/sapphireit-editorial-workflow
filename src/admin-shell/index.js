/**
 * Admin shell entry: the styles of every plugin screen (header, tabs, tokens,
 * Getting Started, Settings) and the Settings screen's tabs.
 *
 * The markup is printed by PHP (Sit_Cwm\Admin\AdminShell, GettingStartedPage
 * and Settings).
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';

/**
 * Internal dependencies
 */
import initSettingsTabs from './settings-tabs';
import './tokens.scss';
import './shell.scss';
import './getting-started.scss';
import './settings.scss';

domReady( () => initSettingsTabs( document ) );
