/**
 * Output helpers for the tutorial: guide stills, chapter videos, and the
 * on-camera versions of the sidebar actions.
 */

/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
import { expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { clickVisibly, glide } from './overlay';
import { moveTo } from '../utils';

/**
 * Where the guide's images and the chapter videos are written.
 *
 * @type {{images: string, video: string}}
 */
export const OUT = {
	images: path.join( __dirname, '../../../docs/tutorial/images' ),
	video: path.join( __dirname, '../../../docs/tutorial/video' ),
};

/**
 * Saves a still for the guide, without the cursor dot or the caption.
 *
 * With a locator, the still is that element clipped to what its scroll
 * container and the viewport show: an element screenshot of a panel that
 * runs past the viewport pads the overflow with blank space.
 *
 * @param {Object} page      Playwright page.
 * @param {string} name      File name without extension, e.g. `05-sidebar-draft`.
 * @param {Object} [locator] Element to capture; default the whole viewport.
 * @return {Promise<void>}
 */
export async function still( page, name, locator ) {
	const file = path.join( OUT.images, `${ name }.png` );
	const toggle = ( hidden ) =>
		page.evaluate(
			( on ) =>
				document.documentElement.classList.toggle(
					'sit-cwm-tutorial-hidden',
					on
				),
			hidden
		);

	await toggle( true );

	try {
		if ( locator ) {
			const box = await locator.boundingBox();
			const viewport = page.viewportSize();

			// The part of the element its scroll container shows: inside a
			// scrolled sidebar that keeps out the header and the top bar.
			const frame = await locator.evaluate( ( element ) => {
				let node = element.parentElement;

				while ( node && node !== document.body ) {
					const { overflowY } = window.getComputedStyle( node );

					if ( /(auto|scroll)/.test( overflowY ) ) {
						const rect = node.getBoundingClientRect();
						// A sidebar's sticky header paints over whatever
						// scrolls under it, so the still starts below it.
						const header = node.querySelector(
							'.interface-complementary-area-header'
						);

						return {
							top: header
								? header.getBoundingClientRect().bottom
								: rect.top,
							bottom: rect.bottom,
							left: rect.left,
							right: rect.right,
						};
					}
					node = node.parentElement;
				}

				return null;
			} );
			const top = Math.max( 0, box.y, frame ? frame.top : 0 );
			const left = Math.max( 0, box.x, frame ? frame.left : 0 );
			const bottom = Math.min(
				box.y + box.height,
				viewport.height,
				frame ? frame.bottom : Infinity
			);
			const right = Math.min(
				box.x + box.width,
				viewport.width,
				frame ? frame.right : Infinity
			);

			await page.screenshot( {
				path: file,
				clip: {
					x: left,
					y: top,
					width: right - left,
					height: bottom - top,
				},
			} );
		} else {
			await page.screenshot( { path: file } );
		}
	} finally {
		await toggle( false );
	}
}

/**
 * Closes the page and saves its recording as `docs/tutorial/video/<slug>.webm`.
 *
 * Playwright only finishes writing a video once its page is closed, so this
 * closes the page first; the test's own fixtures cope with an already-closed
 * page.
 *
 * @param {Object} page Playwright page.
 * @param {string} slug Chapter slug, e.g. `01-setup`.
 * @return {Promise<void>}
 */
export async function saveChapterVideo( page, slug ) {
	const video = page.video();

	if ( ! video ) {
		return;
	}

	await page.close();
	await video.saveAs( path.join( OUT.video, `${ slug }.webm` ) );
}

/**
 * Picks a due date some days from today with the sidebar's calendar, the way
 * a person would: open it, go to next month if the date is there, click the
 * day.
 *
 * The calendar opens on today's month when no date is set, and labels each
 * day with the site's date format, which is also what the toggle shows once
 * the date is saved.
 *
 * @param {Object} page      Playwright page.
 * @param {Object} panel     Sidebar panel locator.
 * @param {number} daysAhead Days from today.
 * @return {Promise<string>} The date as the toggle shows it, e.g. `November 12, 2026`.
 */
export async function pickDueDate( page, panel, daysAhead ) {
	const { label, nextMonth } = await page.evaluate( ( days ) => {
		const today = new Date();
		const target = new Date(
			today.getFullYear(),
			today.getMonth(),
			today.getDate() + days
		);
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		const ymd = `${ target.getFullYear() }-${ pad(
			target.getMonth() + 1
		) }-${ pad( target.getDate() ) }`;

		return {
			label: window.wp.date.dateI18n(
				window.wp.date.getSettings().formats.date,
				`${ ymd }T00:00:00Z`,
				'UTC'
			),
			nextMonth:
				target.getMonth() !== today.getMonth() ||
				target.getFullYear() !== today.getFullYear(),
		};
	}, daysAhead );

	await clickVisibly(
		page,
		panel.getByRole( 'button', { name: /^Change due date:/ } )
	);

	const calendar = page
		.locator( '.sit-cwm-due-date-picker' )
		.getByRole( 'application', { name: 'Calendar' } );

	await expect( calendar ).toBeVisible();

	if ( nextMonth ) {
		await clickVisibly(
			page,
			page
				.locator( '.sit-cwm-due-date-picker' )
				.getByRole( 'button', { name: 'View next month' } )
		);
	}

	// A day's label is its date, then ". Today" / ". Selected" when they apply.
	const escaped = label.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );

	await clickVisibly(
		page,
		calendar.getByRole( 'button', { name: new RegExp( `^${ escaped }` ) } )
	);

	return label;
}

/**
 * Glides to a sidebar transition button, then makes the move with `moveTo()`.
 *
 * The confirm-dialog click inside `moveTo()` is a plain click; the dot's CSS
 * transition turns that jump into movement.
 *
 * @param {Object}  page              Playwright page.
 * @param {Object}  panel             Sidebar panel locator.
 * @param {string}  label             Target status label, e.g. "Review".
 * @param {Object}  [options]         Options for `moveTo()`.
 * @param {boolean} [options.confirm] Whether the move opens a confirm dialog.
 * @return {Promise<void>}
 */
export async function moveToVisibly( page, panel, label, options = {} ) {
	await glide(
		page,
		panel.getByRole( 'button', { name: `Move to ${ label }` } )
	);
	// eslint-disable-next-line playwright/no-wait-for-timeout -- A visible pause before the click, for the viewer.
	await page.waitForTimeout( 150 );
	await moveTo( page, panel, label, options );
}
