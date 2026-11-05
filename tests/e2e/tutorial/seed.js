/**
 * Site state for the tutorial: the three personas, the background posts the
 * dashboard chapter shows, and the settings each chapter starts from.
 */

/**
 * Internal dependencies
 */
import {
	PASSWORD,
	allNotifications,
	clearMails,
	setSettings,
	walkWorkflow,
} from '../utils';

/**
 * The personas the captions and the guide name. Each logs in with `PASSWORD`.
 *
 * @type {Object<string, {username: string, name: string, role: string}>}
 */
export const PERSONAS = {
	sam: { username: 'sam', name: 'Sam Rivera', role: 'author' },
	dana: { username: 'dana', name: 'Dana Okafor', role: 'editor' },
	alex: { username: 'alex', name: 'Alex Chen', role: 'editor' },
};

/**
 * Title of the post the tutorial walks from Draft to Published.
 *
 * @type {string}
 */
export const TUTORIAL_TITLE = 'How we plan our editorial quarter';

/**
 * Its one paragraph of content.
 *
 * @type {string}
 */
export const TUTORIAL_CONTENT = 'This quarter we focus on three launches.';

/**
 * Background posts for the dashboard, in an order that does not match their
 * due dates, so sorting by due date visibly reorders the table.
 *
 * @type {Array<{title: string, status: string, due: number, reviewer?: boolean}>}
 */
const POSTS = [
	{ title: 'Q3 launch announcement', status: 'review', due: 4 },
	{
		title: 'Migrating to block themes',
		status: 'review',
		due: 6,
		reviewer: true,
	},
	{ title: 'Accessibility audit findings', status: 'needs_changes', due: 2 },
	{ title: 'Release notes 4.2', status: 'writing', due: 12 },
];

/**
 * The path from `draft` to each seeded status.
 *
 * @type {Object<string, string[]>}
 */
const ROUTES = {
	writing: [ 'writing' ],
	review: [ 'writing', 'review' ],
	needs_changes: [ 'writing', 'review', 'needs_changes' ],
};

/**
 * A date some days from today, as `Y-m-d`.
 *
 * @param {number} n Days from today.
 * @return {string} Date.
 */
export function daysAhead( n ) {
	const date = new Date();

	date.setDate( date.getDate() + n );

	const pad = ( value ) => String( value ).padStart( 2, '0' );

	return `${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad(
		date.getDate()
	) }`;
}

/**
 * Puts every setting the tutorial touches back to its default.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<void>}
 */
export async function resetSettings( requestUtils ) {
	await setSettings( requestUtils, {
		publish_gate: {},
		notifications: allNotifications( false ),
		require_attestation: false,
		prevent_self_approval: false,
	} );
}

/**
 * The settings chapter 1 ends with, applied off camera for a chapter
 * recorded without it.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<void>}
 */
export async function applySetupSettings( requestUtils ) {
	await setSettings( requestUtils, {
		publish_gate: { post: 'enforce' },
		notifications: allNotifications( true ),
		prevent_self_approval: true,
	} );
}

/**
 * Creates a persona, or brings an existing account of that name back to the
 * persona's display name, role and password (an earlier screenshot run may
 * have left one with a different password).
 *
 * @param {Object} requestUtils     Request utils fixture.
 * @param {Object} persona          Persona from `PERSONAS`.
 * @param {string} persona.username Username.
 * @param {string} persona.name     Display name.
 * @param {string} persona.role     Role slug.
 * @return {Promise<{id: number, username: string, name: string}>} The user.
 */
async function ensurePersona( requestUtils, { username, name, role } ) {
	const data = {
		name,
		email: `${ username }@example.org`,
		password: PASSWORD,
		roles: [ role ],
	};

	const user = await requestUtils
		.rest( {
			method: 'POST',
			path: '/wp/v2/users',
			data: { username, ...data },
		} )
		.catch( async () => {
			const found = await requestUtils.rest( {
				path: '/wp/v2/users',
				params: { search: username, context: 'edit' },
			} );
			const existing = found.find( ( u ) => u.username === username );

			if ( ! existing ) {
				throw new Error(
					`Could not create or find the user ${ username }.`
				);
			}

			return requestUtils.rest( {
				method: 'POST',
				path: `/wp/v2/users/${ existing.id }`,
				data,
			} );
		} );

	return { id: user.id, username, name };
}

/**
 * Seeds the scratch site for a recording.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<{users: Object<string, Object>, ids: Object<string, number>}>} Personas and post ids by title.
 */
export async function seedTutorialSite( requestUtils ) {
	// Chapter 1 turns every notification on. Without the capture mu-plugin
	// those mails would leave the machine, so stop before anything is sent.
	try {
		await clearMails( requestUtils );
	} catch {
		throw new Error(
			'Mail capture mu-plugin missing: run php bin/setup-e2e-site.php --path=G:/laragon/www/cwm-e2e'
		);
	}

	await requestUtils.deleteAllPosts();

	const users = {};

	for ( const [ key, persona ] of Object.entries( PERSONAS ) ) {
		users[ key ] = await ensurePersona( requestUtils, persona );
	}

	// An earlier run may have left the gate on Enforce or the confirmation on.
	await resetSettings( requestUtils );

	const ids = {};

	for ( const post of POSTS ) {
		const created = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: post.title, status: 'draft' },
		} );

		ids[ post.title ] = created.id;

		await requestUtils.rest( {
			method: 'POST',
			path: `/sit-cwm/v1/posts/${ created.id }/workflow`,
			data: {
				due_date: daysAhead( post.due ),
				...( post.reviewer ? { reviewer_id: users.dana.id } : {} ),
			},
		} );
		await walkWorkflow( requestUtils, created.id, ROUTES[ post.status ] );
	}

	return { users, ids };
}

/**
 * Builds chapter 3's starting point off camera, for a re-record of that
 * chapter without chapter 2: Sam's post at Review, with Dana as reviewer and
 * a due date a week out, under chapter 1's settings.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {Object} users        Personas from `seedTutorialSite()`.
 * @return {Promise<number>} Post id.
 */
export async function ensureChapterThreeStart( requestUtils, users ) {
	await applySetupSettings( requestUtils );

	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/posts',
		data: {
			title: TUTORIAL_TITLE,
			content: TUTORIAL_CONTENT,
			status: 'draft',
			author: users.sam.id,
		},
	} );

	await requestUtils.rest( {
		method: 'POST',
		path: `/sit-cwm/v1/posts/${ post.id }/workflow`,
		data: { reviewer_id: users.dana.id, due_date: daysAhead( 7 ) },
	} );
	await walkWorkflow( requestUtils, post.id, [ 'writing', 'review' ] );

	return post.id;
}
