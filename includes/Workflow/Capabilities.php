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
 * The `sit_cwm_*` capabilities and their default role grants (D5, E9).
 *
 * The constants may be read anywhere, but `add_caps()` and `remove_caps()`
 * run only from activation and uninstall: runtime code checks capabilities
 * (through PermissionManager), it never manages them. The one runtime write
 * is `maybe_upgrade()`, which applies a later revision's grants once to sites
 * updated without reactivation. This is also the only place in the plugin
 * where role names appear.
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
	 * Publish managed content that is not approved, while a publish gate is
	 * on. Every use is recorded.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const BYPASS_PUBLISH_GATE = 'sit_cwm_bypass_publish_gate';

	/**
	 * Current revision of the default grants. 1.0.0 is revision 1.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const REVISION = 2;

	/**
	 * Option holding the grant revision a site has received (not autoloaded).
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REVISION_OPTION = 'sit_cwm_caps_revision';

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
			self::BYPASS_PUBLISH_GATE,
		);
	}

	/**
	 * Grants each revision added on top of the previous one (E9): revision =>
	 * role slug => capabilities.
	 *
	 * Revision 1 (1.0.0) is the original `role_map()` and is never listed: a
	 * site at revision 1 already received it at activation.
	 *
	 * @since 1.1.0
	 *
	 * @return array<int, array<string, string[]>>
	 */
	public static function revision_grants(): array {
		return array(
			2 => array(
				'administrator' => array( self::BYPASS_PUBLISH_GATE ),
			),
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

	/**
	 * Applies the grants of every revision newer than the site's, once.
	 *
	 * Hooked on `plugins_loaded`, so a site updated from 1.0.0 without
	 * reactivation receives new capabilities on its next request. Only the
	 * listed grants are applied, never `add_caps()`, so capabilities a site
	 * owner removed are not restored. A site without the option is at
	 * revision 1, because 1.0.0 never wrote it.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = (int) get_option( self::REVISION_OPTION, 1 );

		if ( $stored >= self::REVISION ) {
			return;
		}

		$grants = self::revision_grants();
		ksort( $grants );

		foreach ( $grants as $revision => $role_grants ) {
			if ( $revision <= $stored || $revision > self::REVISION ) {
				continue;
			}

			foreach ( $role_grants as $role_name => $capabilities ) {
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

		self::mark_current();
	}

	/**
	 * Records that the site holds the current revision's grants.
	 *
	 * Called right after `add_caps()` at activation, which already grants
	 * every revision.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function mark_current(): void {
		update_option( self::REVISION_OPTION, self::REVISION, false );
	}
}
