<?php
/**
 * Workflow authorization.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Workflow;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use WP_Post;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single answer to every workflow authorization question (D5).
 *
 * Nothing else in the plugin calls `current_user_can()` for workflow
 * decisions. Every method takes an explicit `$user_id` (default: the current
 * user) and checks with `user_can()`, so it is testable with arbitrary users
 * and safe inside bulk loops.
 *
 * This class answers "may this user reach this status on this post", never
 * "is this a legal edge" — that is TransitionManager, and WorkflowManager
 * combines the two.
 *
 * Two gates are hard and never filterable: the user must exist, and the post
 * must exist with a workflow-enabled type. Everything past them is
 * capability-based, and for status changes the outcome passes through
 * `sit_cwm_can_transition` so extensions can tighten or deliberately widen it.
 *
 * @since 1.0.0
 */
final class PermissionManager {

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.0.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Plugin settings.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param PostRepository $posts    Workflow meta persistence.
	 * @param Settings       $settings Plugin settings.
	 */
	public function __construct( PostRepository $posts, Settings $settings ) {
		$this->posts    = $posts;
		$this->settings = $settings;
	}

	/**
	 * Whether a user may manage the plugin (settings, bulk actions).
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_manage( ?int $user_id = null ): bool {
		$user = $this->resolve_user( $user_id );

		return null !== $user && user_can( $user, Capabilities::MANAGE_WORKFLOWS );
	}

	/**
	 * Whether a user may submit a bulk workflow request at all.
	 *
	 * Only the gate to *attempt* a batch: every post in it is authorized again,
	 * individually, by WorkflowManager.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_attempt_batch( ?int $user_id = null ): bool {
		$user = $this->resolve_user( $user_id );

		return null !== $user && ( user_can( $user, Capabilities::MANAGE_WORKFLOWS ) || user_can( $user, 'edit_posts' ) );
	}

	/**
	 * Whether a user may be assigned as a reviewer: they exist and hold
	 * `sit_cwm_review_content`.
	 *
	 * @since 1.0.0
	 *
	 * @param int $reviewer_id Candidate reviewer user id.
	 * @return bool
	 */
	public function can_be_reviewer( int $reviewer_id ): bool {
		$user = $reviewer_id > 0 ? $this->resolve_user( $reviewer_id ) : null;

		return null !== $user && user_can( $user, Capabilities::REVIEW_CONTENT );
	}

	/**
	 * Which plugin capabilities a user holds, for UI hints.
	 *
	 * Scripts use this to hide controls a user cannot use; it never grants
	 * anything, since every action is re-checked server-side.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return array<string, bool> Capability name => held. All false for
	 *                             logged-out or unknown users.
	 */
	public function capability_flags( ?int $user_id = null ): array {
		$user  = $this->resolve_user( $user_id );
		$flags = array();

		foreach ( Capabilities::all() as $capability ) {
			$flags[ $capability ] = null !== $user && user_can( $user, $capability );
		}

		return $flags;
	}

	/**
	 * Whether a user may read the status registry and transition map.
	 *
	 * Workflow metadata is editorial information, so it is limited to users
	 * who manage the plugin or can edit content.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_view_statuses( ?int $user_id = null ): bool {
		$user = $this->resolve_user( $user_id );

		return null !== $user && ( user_can( $user, Capabilities::MANAGE_WORKFLOWS ) || user_can( $user, 'edit_posts' ) );
	}

	/**
	 * Workflow-enabled post types whose content a user may edit.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return string[] Post type slugs; empty for logged-out users.
	 */
	public function dashboard_post_types( ?int $user_id = null ): array {
		$user = $this->resolve_user( $user_id );

		if ( null === $user ) {
			return array();
		}

		$types = array();

		foreach ( $this->settings->enabled_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null !== $object && user_can( $user, $object->cap->edit_posts ) ) {
				$types[] = $post_type;
			}
		}

		return $types;
	}

	/**
	 * Whether a user may list workflow content (the dashboard collection):
	 * they can edit content of at least one workflow-enabled post type.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_view_dashboard( ?int $user_id = null ): bool {
		return array() !== $this->dashboard_post_types( $user_id );
	}

	/**
	 * Whether a user may open the admin dashboard screen: they hold
	 * `sit_cwm_view_activity` and can list workflow content.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_access_dashboard( ?int $user_id = null ): bool {
		$user = $this->resolve_user( $user_id );

		return null !== $user && user_can( $user, Capabilities::VIEW_ACTIVITY ) && $this->can_view_dashboard( $user->ID );
	}

	/**
	 * Whether a user may see every post of the given types in workflow
	 * listings, rather than only posts they author or review.
	 *
	 * Requires `edit_others_posts` on every type, so a mixed query is scoped
	 * as strictly as its most restricted type.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $post_types Post type slugs.
	 * @param int|null $user_id    User id; null for the current user.
	 * @return bool False for an empty list or an unknown type.
	 */
	public function can_see_all_posts( array $post_types, ?int $user_id = null ): bool {
		$user = $this->resolve_user( $user_id );

		if ( null === $user || array() === $post_types ) {
			return false;
		}

		foreach ( $post_types as $post_type ) {
			$object = is_string( $post_type ) ? get_post_type_object( $post_type ) : null;

			if ( null === $object || ! user_can( $user, $object->cap->edit_others_posts ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a user may list assignable reviewers.
	 *
	 * For a post: whoever may assign that post's reviewer. Without a post:
	 * users who hold the reviewer-assignment capability or manage the plugin
	 * (the dashboard's reviewer filter). Anyone else must not be able to
	 * enumerate users.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id; `0` for no specific post.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_list_reviewers( int $post_id = 0, ?int $user_id = null ): bool {
		if ( $post_id > 0 ) {
			return $this->can_assign_reviewer( $post_id, $user_id );
		}

		$user = $this->resolve_user( $user_id );

		return null !== $user && ( user_can( $user, Capabilities::ASSIGN_REVIEWER ) || user_can( $user, Capabilities::MANAGE_WORKFLOWS ) );
	}

	/**
	 * Primitive capabilities a user needs to be offered as a reviewer.
	 *
	 * Always `sit_cwm_review_content`. For a managed post, also the primitive
	 * capabilities `edit_post` maps to for a non-author (`edit_others_posts`,
	 * plus `edit_published_posts` / `edit_private_posts` by post status), so
	 * the list holds only users who can actually open that post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id; `0` for no specific post.
	 * @return string[] Capabilities that must all be held.
	 */
	public function reviewer_capabilities( int $post_id = 0 ): array {
		$caps = array( Capabilities::REVIEW_CONTENT );
		$post = $this->resolve_post( $post_id );
		$type = null === $post ? null : get_post_type_object( $post->post_type );

		if ( null === $type ) {
			return $caps;
		}

		$caps[] = $type->cap->edit_others_posts;

		if ( in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			$caps[] = $type->cap->edit_published_posts;
		} elseif ( 'private' === $post->post_status ) {
			$caps[] = $type->cap->edit_private_posts;
		}

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Whether a user may read a post's activity history and comments.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_view_activity( int $post_id, ?int $user_id = null ): bool {
		return $this->can_on_post( $post_id, $user_id, Capabilities::VIEW_ACTIVITY );
	}

	/**
	 * Whether a user may read a managed post.
	 *
	 * The visibility gate: callers answer "not found" (404) when this is false,
	 * so a 403 never confirms that a hidden post exists.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_read_post( int $post_id, ?int $user_id = null ): bool {
		$user = $this->resolve_user( $user_id );

		if ( null === $user ) {
			return false;
		}

		$post = $this->resolve_post( $post_id );

		return null !== $post && user_can( $user, 'read_post', $post->ID );
	}

	/**
	 * Whether a user may work on a managed post at all (e.g. see the sidebar).
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_edit_post( int $post_id, ?int $user_id = null ): bool {
		return $this->can_on_post( $post_id, $user_id );
	}

	/**
	 * Whether a user may set or clear a post's reviewer.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_assign_reviewer( int $post_id, ?int $user_id = null ): bool {
		return $this->can_on_post( $post_id, $user_id, Capabilities::ASSIGN_REVIEWER );
	}

	/**
	 * Whether a user may set or clear a post's due date.
	 *
	 * Same capability as reviewer assignment (D5).
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_set_due_date( int $post_id, ?int $user_id = null ): bool {
		return $this->can_on_post( $post_id, $user_id, Capabilities::ASSIGN_REVIEWER );
	}

	/**
	 * Whether a user may add a workflow comment to a post.
	 *
	 * Comments live in the activity history, so posting one requires being
	 * able to read that history.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_comment( int $post_id, ?int $user_id = null ): bool {
		return $this->can_on_post( $post_id, $user_id, Capabilities::VIEW_ACTIVITY );
	}

	/**
	 * Whether a user may move a post to a workflow status.
	 *
	 * Checks, cheapest first: the user exists; the post exists and its type is
	 * workflow-enabled; `edit_post` on this post; the capability mapped to the
	 * target status; and for `published`, `publish_post` on this post. The
	 * first two gates are final. The capability outcome then passes through
	 * `sit_cwm_can_transition`.
	 *
	 * Does not check whether the move is a legal edge from the current status.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param string   $to      Target status slug.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_change_status( int $post_id, string $to, ?int $user_id = null ): bool {
		$user = $this->resolve_user( $user_id );

		if ( null === $user ) {
			return false;
		}

		$post = $this->resolve_post( $post_id );

		if ( null === $post ) {
			return false;
		}

		$allowed = user_can( $user, 'edit_post', $post->ID )
			&& user_can( $user, $this->capability_for_status( $to ) )
			&& ( 'published' !== $to || user_can( $user, 'publish_post', $post->ID ) );

		/**
		 * Filters whether a user may move a post to a workflow status.
		 *
		 * Runs only for an existing user on a managed post, after the
		 * capability checks. Return false to veto; returning true grants the
		 * move even when capabilities would deny it, so widen deliberately.
		 * Structural validity of `$from → $to` is enforced separately by
		 * WorkflowManager and cannot be bypassed here. Anything other than
		 * boolean true denies.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $allowed Result of the capability checks.
		 * @param int    $post_id Post id.
		 * @param string $from    Current workflow status.
		 * @param string $to      Target workflow status.
		 * @param int    $user_id Acting user id.
		 */
		$filtered = apply_filters( 'sit_cwm_can_transition', $allowed, $post->ID, $this->current_status( $post->ID ), $to, $user->ID );

		return true === $filtered;
	}

	/**
	 * Capability required to move content to a status (D5).
	 *
	 * Unknown statuses, or statuses the filtered map leaves without a valid
	 * capability, require `sit_cwm_manage_workflows` (deny-by-default for
	 * anyone but an administrator). `published` additionally needs
	 * `publish_post`, which `can_change_status()` checks on the post.
	 *
	 * @since 1.0.0
	 *
	 * @param string $to Target status slug.
	 * @return string Capability name.
	 */
	public function capability_for_status( string $to ): string {
		$core = array(
			'draft'         => Capabilities::CHANGE_WORKFLOW,
			'writing'       => Capabilities::CHANGE_WORKFLOW,
			'review'        => Capabilities::CHANGE_WORKFLOW,
			'needs_changes' => Capabilities::REVIEW_CONTENT,
			'approved'      => Capabilities::APPROVE_CONTENT,
			'published'     => Capabilities::APPROVE_CONTENT,
		);

		/**
		 * Filters the capability required to reach each workflow status.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $map Status slug => capability name.
		 */
		$map = apply_filters( 'sit_cwm_status_capability_map', $core );

		if ( ! is_array( $map ) ) {
			$map = $core;
		}

		if ( isset( $map[ $to ] ) && is_string( $map[ $to ] ) && '' !== $map[ $to ] ) {
			return $map[ $to ];
		}

		return Capabilities::MANAGE_WORKFLOWS;
	}

	/**
	 * Shared per-post check: existing user, managed post, `edit_post` on it,
	 * and optionally a plugin capability.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $post_id    Post id.
	 * @param int|null    $user_id    User id; null for the current user.
	 * @param string|null $capability Plugin capability also required, if any.
	 * @return bool
	 */
	private function can_on_post( int $post_id, ?int $user_id, ?string $capability = null ): bool {
		$user = $this->resolve_user( $user_id );

		if ( null === $user ) {
			return false;
		}

		$post = $this->resolve_post( $post_id );

		if ( null === $post || ! user_can( $user, 'edit_post', $post->ID ) ) {
			return false;
		}

		return null === $capability || user_can( $user, $capability );
	}

	/**
	 * Resolves a user id to an existing user.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return WP_User|null Null for logged-out (`0`) or unknown users.
	 */
	private function resolve_user( ?int $user_id ): ?WP_User {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Resolves a post id to a post whose type is workflow-enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return WP_Post|null Null when missing or not managed.
	 */
	private function resolve_post( int $post_id ): ?WP_Post {
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->settings->is_post_type_enabled( $post->post_type ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Current workflow status of a post, for filter context.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return string A registered status slug.
	 */
	private function current_status( int $post_id ): string {
		return $this->posts->get_status( $post_id );
	}
}
