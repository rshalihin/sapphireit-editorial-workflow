<?php
/**
 * Custom capability definitions and role grants.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Workflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The six `sit_cwm_*` capabilities and their default role grants (D5).
 *
 * The constants may be read anywhere, but `add_caps()` and `remove_caps()`
 * run only from activation and uninstall: runtime code checks capabilities
 * (through PermissionManager), it never manages them. This is also the only
 * place in the plugin where role names appear.
 *
 * @since 1.0.0
 */
final class Capabilities {

	/**
	 * Settings page and bulk actions across posts.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const MANAGE_WORKFLOWS = 'sit_cwm_manage_workflows';

	/**
	 * Move content through the author-side statuses.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CHANGE_WORKFLOW = 'sit_cwm_change_workflow';

	/**
	 * Set or clear the reviewer and due date.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ASSIGN_REVIEWER = 'sit_cwm_assign_reviewer';

	/**
	 * Send content back for changes.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const REVIEW_CONTENT = 'sit_cwm_review_content';

	/**
	 * Approve (and mark published) content.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const APPROVE_CONTENT = 'sit_cwm_approve_content';

	/**
	 * Read activity history and workflow comments.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const VIEW_ACTIVITY = 'sit_cwm_view_activity';

	/**
	 * All plugin capabilities.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::MANAGE_WORKFLOWS,
			self::CHANGE_WORKFLOW,
			self::ASSIGN_REVIEWER,
			self::REVIEW_CONTENT,
			self::APPROVE_CONTENT,
			self::VIEW_ACTIVITY,
		);
	}

	/**
	 * Default role grants (D5): role slug => capabilities granted at activation.
	 *
	 * Roles not listed (e.g. subscriber) receive nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string[]>
	 */
	public static function role_map(): array {
		return array(
			'administrator' => self::all(),
			'editor'        => array(
				self::CHANGE_WORKFLOW,
				self::ASSIGN_REVIEWER,
				self::REVIEW_CONTENT,
				self::APPROVE_CONTENT,
				self::VIEW_ACTIVITY,
			),
			'author'        => array(
				self::CHANGE_WORKFLOW,
				self::VIEW_ACTIVITY,
			),
			'contributor'   => array(
				self::CHANGE_WORKFLOW,
				self::VIEW_ACTIVITY,
			),
		);
	}

	/**
	 * Grants the default capabilities to their roles on the current site.
	 *
	 * Idempotent: capabilities a role already has are skipped, so reactivation
	 * writes nothing. Missing roles (removed by another plugin) are skipped.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function add_caps(): void {
		foreach ( self::role_map() as $role_name => $capabilities ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				if ( ! $role->has_cap( $capability ) ) {
					$role->add_cap( $capability );
				}
			}
		}
	}

	/**
	 * Removes every plugin capability from every role on the current site.
	 *
	 * Walks all roles, not just `role_map()`, so grants a site owner added to
	 * custom roles are cleaned up too. Called from `uninstall.php` only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function remove_caps(): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( self::all() as $capability ) {
				if ( $role->has_cap( $capability ) ) {
					$role->remove_cap( $capability );
				}
			}
		}
	}
}
