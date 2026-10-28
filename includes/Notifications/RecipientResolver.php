<?php
/**
 * Notification recipient resolution.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Notifications;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Post;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "who should get mail for this event on this post" (E12), applying
 * every recipient safety rule (E13). Sends nothing itself.
 *
 * @since 1.1.0
 * @internal
 */
final class RecipientResolver {

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.1.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Workflow authorization.
	 *
	 * @since 1.1.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param PostRepository    $posts       Workflow meta persistence.
	 * @param PermissionManager $permissions Workflow authorization.
	 */
	public function __construct( PostRepository $posts, PermissionManager $permissions ) {
		$this->posts       = $posts;
		$this->permissions = $permissions;
	}

	/**
	 * Recipients of an event on a post.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event       Event key from `NotificationEvents::all()`.
	 * @param int    $post_id     Post id.
	 * @param int    $actor_id    User who caused the event; never a recipient.
	 * @param int    $reviewer_id For `reviewer_assigned` only: the newly
	 *                            assigned reviewer (`0` when cleared). Other
	 *                            events read the stored reviewer.
	 * @return int[] User ids, author before reviewer, each at most once.
	 */
	public function resolve( string $event, int $post_id, int $actor_id, int $reviewer_id = 0 ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! in_array( $event, NotificationEvents::all(), true ) ) {
			return array();
		}

		$base = $this->base_recipients( $event, $post, $reviewer_id );

		/**
		 * Filters who is notified about a workflow event, before the safety
		 * rules run.
		 *
		 * Extensions can add or remove recipients here, but the result is then
		 * cast to positive user ids and de-duplicated, and the actor, users
		 * that do not exist or have no valid email address, and users who may
		 * not read the post are removed. So this filter can never add someone
		 * who may not see the post.
		 *
		 * @since 1.1.0
		 *
		 * @param int[]  $ids      Recipient user ids from the E12 routing.
		 * @param string $event    Event key.
		 * @param int    $post_id  Post id.
		 * @param int    $actor_id User who caused the event.
		 */
		$ids = apply_filters( 'sit_cwm_notification_recipients', $base, $event, $post_id, $actor_id );

		if ( ! is_array( $ids ) ) {
			$ids = $base;
		}

		$recipients = array();

		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) && ! ( is_string( $id ) && is_numeric( $id ) ) ) {
				continue;
			}

			$id = (int) $id;

			if ( $id <= 0 || $id === $actor_id || in_array( $id, $recipients, true ) ) {
				continue;
			}

			$user = get_userdata( $id );

			if ( ! $user instanceof WP_User || ! is_email( $user->user_email ) ) {
				continue;
			}

			if ( ! $this->permissions->can_read_post( $post_id, $id ) ) {
				continue;
			}

			$recipients[] = $id;
		}

		return $recipients;
	}

	/**
	 * Recipients from the E12 routing table, before any filtering.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $event       Event key.
	 * @param WP_Post $post        Post.
	 * @param int     $reviewer_id Newly assigned reviewer, for `reviewer_assigned`.
	 * @return int[] User ids; may contain `0` or duplicates.
	 */
	private function base_recipients( string $event, WP_Post $post, int $reviewer_id ): array {
		switch ( $event ) {
			case NotificationEvents::REVIEWER_ASSIGNED:
				return array( $reviewer_id );
			case NotificationEvents::REVIEW_REQUESTED:
				return array( $this->posts->get_reviewer_id( $post->ID ) );
			case NotificationEvents::CHANGES_REQUESTED:
			case NotificationEvents::APPROVED:
				return array( (int) $post->post_author );
			case NotificationEvents::COMMENT_ADDED:
				return array( (int) $post->post_author, $this->posts->get_reviewer_id( $post->ID ) );
			default:
				return array();
		}
	}
}
