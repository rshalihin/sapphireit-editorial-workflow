<?php
/**
 * Email notification event keys and labels.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The five site-level notification events (E12) and their labels (E14).
 *
 * Each key doubles as the toggle key under `notifications` in the
 * `sit_cwm_settings` option.
 *
 * @since 1.1.0
 * @internal
 */
final class NotificationEvents {

	/**
	 * A reviewer is assigned (sent to the reviewer).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REVIEWER_ASSIGNED = 'reviewer_assigned';

	/**
	 * Content is submitted for review (sent to the assigned reviewer).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REVIEW_REQUESTED = 'review_requested';

	/**
	 * Changes are requested (sent to the author).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const CHANGES_REQUESTED = 'changes_requested';

	/**
	 * Content is approved (sent to the author).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const APPROVED = 'approved';

	/**
	 * A workflow comment is added (sent to the author and the reviewer).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const COMMENT_ADDED = 'comment_added';

	/**
	 * All event keys, in settings-screen order.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::REVIEWER_ASSIGNED,
			self::REVIEW_REQUESTED,
			self::CHANGES_REQUESTED,
			self::APPROVED,
			self::COMMENT_ADDED,
		);
	}

	/**
	 * Translated label for every event, keyed by event, in `all()` order.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::REVIEWER_ASSIGNED => __( 'A reviewer is assigned (sent to the reviewer)', 'sapphireit-editorial-workflow' ),
			self::REVIEW_REQUESTED  => __( 'Content is submitted for review (sent to the assigned reviewer)', 'sapphireit-editorial-workflow' ),
			self::CHANGES_REQUESTED => __( 'Changes are requested (sent to the author)', 'sapphireit-editorial-workflow' ),
			self::APPROVED          => __( 'Content is approved (sent to the author)', 'sapphireit-editorial-workflow' ),
			self::COMMENT_ADDED     => __( 'A workflow comment is added (sent to the author and the reviewer)', 'sapphireit-editorial-workflow' ),
		);
	}

	/**
	 * The event a workflow status change notifies about, if any.
	 *
	 * @since 1.1.0
	 *
	 * @param string $to Target workflow status slug.
	 * @return string|null Event key, or null when the target does not notify.
	 */
	public static function for_status( string $to ): ?string {
		switch ( $to ) {
			case 'review':
				return self::REVIEW_REQUESTED;
			case 'needs_changes':
				return self::CHANGES_REQUESTED;
			case 'approved':
				return self::APPROVED;
			default:
				return null;
		}
	}
}
