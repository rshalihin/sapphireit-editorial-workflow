/**
 * External dependencies
 */
import { render } from '@testing-library/react';

/**
 * Internal dependencies
 */
import PublishGateNotice from '../../src/components/PublishGateNotice';

const LOCKED = 'Publishing is locked until this content is approved.';
const LOG_ONLY_BYPASS =
	'This content is not approved yet. Publishing it now will be recorded as a bypass of the review.';
const ENFORCE_BYPASS =
	'This content is not approved yet. You can still publish it, and that will be recorded as a bypass of the review.';

describe( 'PublishGateNotice', () => {
	it.each( [
		[
			'the gate is off',
			{ mode: 'off', outcome: 'not_applicable' },
			'draft',
		],
		[
			'publishing is allowed',
			{ mode: 'enforce', outcome: 'allowed' },
			'draft',
		],
		[
			'the post is live',
			{ mode: 'enforce', outcome: 'refused' },
			'publish',
		],
		[
			'the post is scheduled',
			{ mode: 'enforce', outcome: 'refused' },
			'future',
		],
		[ 'the server sends no gate state', undefined, 'draft' ],
	] )( 'renders nothing when %s', ( label, gate, postStatus ) => {
		const { container } = render(
			<PublishGateNotice gate={ gate } postStatus={ postStatus } />
		);

		expect( container.innerHTML ).toBe( '' );
	} );

	it.each( [
		[ { mode: 'enforce', outcome: 'refused' }, 'draft', 'is-info', LOCKED ],
		[
			{ mode: 'log_only', outcome: 'bypassed' },
			'draft',
			'is-warning',
			LOG_ONLY_BYPASS,
		],
		[
			{ mode: 'enforce', outcome: 'bypassed' },
			'pending',
			'is-warning',
			ENFORCE_BYPASS,
		],
	] )(
		'says one sentence for %o on a %s post',
		( gate, postStatus, statusClass, text ) => {
			const { container } = render(
				<PublishGateNotice gate={ gate } postStatus={ postStatus } />
			);
			const notices = container.querySelectorAll(
				'.sit-cwm-gate-notice'
			);

			expect( notices ).toHaveLength( 1 );
			expect( notices[ 0 ].classList.contains( statusClass ) ).toBe(
				true
			);
			expect( notices[ 0 ].textContent ).toContain( text );
			// Not dismissible: core's Notice renders no close button then.
			expect( notices[ 0 ].querySelector( 'button' ) ).toBeNull();
		}
	);
} );
