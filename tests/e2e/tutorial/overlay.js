/**
 * On-screen overlay for the tutorial videos: a cursor dot, a click ring and a
 * caption banner.
 *
 * Headless Chromium records no mouse pointer, and a viewer cannot follow a
 * click they cannot see, so the overlay draws one. It is installed with
 * `addInitScript`, which re-creates it on every document, so it survives
 * `page.goto()`, reloads and the logins of `loginAs()`. The current caption
 * and the last mouse position live in sessionStorage, so a new document picks
 * up where the previous one left off instead of blanking the caption or
 * restarting the dot in the top-left corner.
 */

/**
 * sessionStorage key of the current caption.
 *
 * @type {string}
 */
const CAPTION_KEY = 'sit-cwm-tutorial-caption';

/**
 * sessionStorage key of the last mouse position, `{ x, y }` as JSON.
 *
 * @type {string}
 */
const MOUSE_KEY = 'sit-cwm-tutorial-mouse';

/**
 * Milliseconds a viewer needs per word of caption. About 240 words a minute,
 * a comfortable reading pace for on-screen text.
 *
 * @type {number}
 */
const MS_PER_WORD = 250;

/**
 * The shortest time any caption stays on screen before the next action.
 *
 * @type {number}
 */
const MIN_HOLD = 2500;

/**
 * Runs inside every document of the page. Kept free of closures: Playwright
 * serialises it and runs it in the browser.
 *
 * @param {{captionKey: string, mouseKey: string}} keys sessionStorage keys.
 */
function overlayScript( { captionKey, mouseKey } ) {
	// A frame inside the page (the block editor's canvas is one) cannot draw
	// on top of the page, so it hands its mouse moves up, in page coordinates.
	if ( window !== window.top ) {
		window.addEventListener(
			'mousemove',
			( event ) => {
				try {
					let x = event.clientX;
					let y = event.clientY;
					let frame = window;

					while ( frame !== window.top && frame.frameElement ) {
						const rect = frame.frameElement.getBoundingClientRect();

						x += rect.left;
						y += rect.top;
						frame = frame.parent;
					}

					window.top.sitCwmTutorialMoveCursor?.( x, y );
				} catch {
					// A cross-origin frame: the page keeps its last position.
				}
			},
			true
		);
		return;
	}

	const readMouse = () => {
		try {
			return JSON.parse( window.sessionStorage.getItem( mouseKey ) );
		} catch {
			return null;
		}
	};

	let cursor;
	let banner;

	const moveCursor = ( x, y ) => {
		try {
			window.sessionStorage.setItem(
				mouseKey,
				JSON.stringify( { x, y } )
			);
		} catch {
			// Storage blocked: the dot still moves, it just restarts after a reload.
		}

		if ( cursor ) {
			cursor.style.transform = `translate(${ x - 10 }px, ${ y - 10 }px)`;
		}
	};

	const showCaption = ( text ) => {
		if ( banner ) {
			banner.textContent = text || '';
			banner.style.display = text ? 'block' : 'none';
		}
	};

	window.sitCwmTutorialMoveCursor = moveCursor;
	window.sitCwmTutorialShowCaption = showCaption;

	const build = () => {
		if ( document.getElementById( 'sit-cwm-tutorial-cursor' ) ) {
			return;
		}

		const style = document.createElement( 'style' );

		style.textContent = `
			#sit-cwm-tutorial-cursor {
				position: fixed; left: 0; top: 0; width: 20px; height: 20px;
				border-radius: 50%; background: rgba(220, 38, 38, 0.55);
				border: 2px solid rgba(255, 255, 255, 0.9);
				box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.35);
				box-sizing: border-box; pointer-events: none;
				z-index: 2147483647; transition: transform 250ms ease-out;
			}
			.sit-cwm-tutorial-ring {
				position: fixed; width: 44px; height: 44px; border-radius: 50%;
				border: 3px solid rgba(220, 38, 38, 0.8); box-sizing: border-box;
				pointer-events: none; z-index: 2147483647;
				animation: sit-cwm-tutorial-ring 400ms ease-out forwards;
			}
			@keyframes sit-cwm-tutorial-ring {
				from { transform: scale(0.3); opacity: 1; }
				to { transform: scale(1.4); opacity: 0; }
			}
			#sit-cwm-tutorial-caption {
				position: fixed; left: 50%; bottom: 28px;
				transform: translateX(-50%); max-width: 82%;
				padding: 12px 24px; border-radius: 999px;
				background: rgba(17, 24, 39, 0.88); color: #fff;
				font: 500 22px/1.35 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
				text-align: center; pointer-events: none; z-index: 2147483647;
			}
			html.sit-cwm-tutorial-hidden #sit-cwm-tutorial-cursor,
			html.sit-cwm-tutorial-hidden #sit-cwm-tutorial-caption,
			html.sit-cwm-tutorial-hidden .sit-cwm-tutorial-ring {
				display: none !important;
			}
		`;
		document.head.appendChild( style );

		cursor = document.createElement( 'div' );
		cursor.id = 'sit-cwm-tutorial-cursor';
		cursor.setAttribute( 'aria-hidden', 'true' );

		banner = document.createElement( 'div' );
		banner.id = 'sit-cwm-tutorial-caption';
		banner.setAttribute( 'aria-hidden', 'true' );

		document.body.appendChild( cursor );
		document.body.appendChild( banner );

		// Start where the previous document left the mouse, without animating
		// in from the corner.
		const last = readMouse();

		if ( last ) {
			cursor.style.transition = 'none';
			moveCursor( last.x, last.y );
			// Force the jump to apply before the transition comes back.
			cursor.getBoundingClientRect();
			cursor.style.transition = '';
		}

		let text = '';

		try {
			text = window.sessionStorage.getItem( captionKey ) || '';
		} catch {
			text = '';
		}

		showCaption( text );
	};

	window.addEventListener(
		'mousemove',
		( event ) => moveCursor( event.clientX, event.clientY ),
		true
	);

	window.addEventListener(
		'mousedown',
		( event ) => {
			if ( ! document.body ) {
				return;
			}

			const ring = document.createElement( 'div' );

			ring.className = 'sit-cwm-tutorial-ring';
			ring.setAttribute( 'aria-hidden', 'true' );
			ring.style.left = `${ event.clientX - 22 }px`;
			ring.style.top = `${ event.clientY - 22 }px`;
			document.body.appendChild( ring );
			window.setTimeout( () => ring.remove(), 450 );
		},
		true
	);

	if ( document.body ) {
		build();
	} else {
		document.addEventListener( 'DOMContentLoaded', build );
	}
}

/**
 * Installs the overlay on every document the page loads from now on.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
export async function installOverlay( page ) {
	await page.addInitScript( overlayScript, {
		captionKey: CAPTION_KEY,
		mouseKey: MOUSE_KEY,
	} );
}

/**
 * Shows a caption and holds it long enough to read.
 *
 * The caption stays on screen until the next `caption()` or `clearCaption()`,
 * across navigations.
 *
 * @param {Object} page           Playwright page.
 * @param {string} text           Caption text.
 * @param {Object} [options]      Options.
 * @param {number} [options.hold] Milliseconds to wait; default by word count.
 * @return {Promise<void>}
 */
export async function caption( page, text, { hold } = {} ) {
	await page.evaluate(
		( [ key, value ] ) => {
			try {
				window.sessionStorage.setItem( key, value );
			} catch {
				// Storage blocked: the caption shows until the next navigation.
			}
			window.sitCwmTutorialShowCaption?.( value );
		},
		[ CAPTION_KEY, text ]
	);

	const words = text.split( /\s+/ ).filter( Boolean ).length;

	// eslint-disable-next-line playwright/no-wait-for-timeout -- The hold is the point: the viewer reads the caption.
	await page.waitForTimeout(
		hold ?? Math.max( MIN_HOLD, MS_PER_WORD * words )
	);
}

/**
 * Removes the caption.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
export async function clearCaption( page ) {
	await page.evaluate( ( key ) => {
		try {
			window.sessionStorage.removeItem( key );
		} catch {
			// Nothing stored.
		}
		window.sitCwmTutorialShowCaption?.( '' );
	}, CAPTION_KEY );
}

/**
 * Moves the mouse to the centre of an element in small steps, so the cursor
 * dot travels there instead of jumping.
 *
 * Playwright remembers the mouse position per page, so each glide starts from
 * where the last one ended.
 *
 * @param {Object} page            Playwright page.
 * @param {Object} locator         Target locator.
 * @param {Object} [options]       Options.
 * @param {number} [options.steps] Intermediate mouse moves; default 25.
 * @return {Promise<void>}
 */
export async function glide( page, locator, { steps } = {} ) {
	await locator.scrollIntoViewIfNeeded();

	const box = await locator.boundingBox();

	if ( ! box ) {
		return;
	}

	const x = box.x + box.width / 2;
	const y = box.y + box.height / 2;

	await page.mouse.move( x, y, { steps: steps ?? 25 } );

	// Moves over a frame (the editor canvas) may not reach the page's own
	// listener; put the dot where the mouse ended either way.
	await page.evaluate(
		( [ left, top ] ) => window.sitCwmTutorialMoveCursor?.( left, top ),
		[ x, y ]
	);
}

/**
 * Glides to an element, pauses so the viewer sees where the click lands, and
 * clicks it. Every on-camera click in the tutorial goes through this.
 *
 * @param {Object} page    Playwright page.
 * @param {Object} locator Target locator.
 * @return {Promise<void>}
 */
export async function clickVisibly( page, locator ) {
	await glide( page, locator );
	// eslint-disable-next-line playwright/no-wait-for-timeout -- A visible pause before the click, for the viewer.
	await page.waitForTimeout( 150 );
	await locator.click();
}
