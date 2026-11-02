<?php
/**
 * Review attestation.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Governance;

use Sit_Cwm\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether approving a post needs an explicit "I reviewed this", and
 * supplies the statement the approver confirms (F10, F16).
 *
 * `WorkflowManager` enforces the requirement on every route to `approved`
 * and records the statement with the approval's activity row.
 *
 * @since 1.2.0
 * @api
 */
final class Attestation {

	/**
	 * Plugin settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether approving a post requires attestation.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_required( int $post_id ): bool {
		/**
		 * Filters whether approving a post requires the approver to confirm
		 * they reviewed it. Only boolean `true` requires; any other value,
		 * including truthy strings, does not.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $required Whether the site setting requires attestation.
		 * @param int  $post_id  Post id.
		 */
		return true === apply_filters( 'sit_cwm_require_attestation', $this->settings->requires_attestation(), $post_id );
	}

	/**
	 * The statement an approver confirms, in the current locale. Not escaped;
	 * escape at output.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return string
	 */
	public function statement(): string {
		return __( 'I confirm I have reviewed this content and approve it for publication.', 'sapphireit-editorial-workflow' );
	}
}
