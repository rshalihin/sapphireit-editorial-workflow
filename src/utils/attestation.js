/**
 * Review attestation helpers (F10).
 */

/**
 * Internal dependencies
 */
import { getBootstrap } from './format';

/**
 * The only target status that can need an attestation.
 *
 * @type {string}
 */
export const ATTESTED_STATUS = 'approved';

/**
 * The site-wide attestation setting, for screens without a per-post payload.
 *
 * The server re-checks per post, so a filter that differs for one post shows
 * up as that post's failure.
 *
 * @return {{required: boolean, statement: string}} Setting and statement.
 */
export function getSiteAttestation() {
	const { requireAttestation, attestationStatement } = getBootstrap();

	return {
		required: true === requireAttestation,
		statement:
			typeof attestationStatement === 'string'
				? attestationStatement
				: '',
	};
}
