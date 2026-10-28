<?php
/**
 * Notification mail copy.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Notifications;

use Sit_Cwm\Workflow\PermissionManager;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the plain-text subject and body of a notification mail, in the
 * recipient's language (E13, E14).
 *
 * @since 1.1.0
 * @internal
 */
final class MessageBuilder {

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
	 * @param PermissionManager $permissions Workflow authorization.
	 */
	public function __construct( PermissionManager $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Builds a mail for one recipient.
	 *
	 * The comment text of `comment_added` is included only when the
	 * recipient may read the post's activity history; otherwise the mail
	 * only says that a comment was added.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event        Event key from `NotificationEvents::all()`.
	 * @param int    $post_id      Post id.
	 * @param int    $recipient_id Recipient user id; their locale is used.
	 * @param int    $actor_id     User who caused the event; `0` for the system.
	 * @param array  $args         For `comment_added`: `message`, the comment HTML.
	 * @return array{subject: string, body: string}
	 */
	public function build( string $event, int $post_id, int $recipient_id, int $actor_id, array $args = array() ): array {
		$switched = switch_to_user_locale( $recipient_id );

		try {
			$message = $this->compose( $event, $post_id, $recipient_id, $actor_id, $args );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

		/**
		 * Filters a notification mail before it is sent.
		 *
		 * The result is used only when it is an array with string `subject`
		 * and `body`; anything else keeps the original. The body is sent as
		 * plain text.
		 *
		 * @since 1.1.0
		 *
		 * @param array{subject: string, body: string} $message      Subject and body.
		 * @param string                               $event        Event key.
		 * @param int                                  $post_id      Post id.
		 * @param int                                  $recipient_id Recipient user id.
		 */
		$filtered = apply_filters( 'sit_cwm_notification_message', $message, $event, $post_id, $recipient_id );

		if ( is_array( $filtered ) && isset( $filtered['subject'], $filtered['body'] ) && is_string( $filtered['subject'] ) && is_string( $filtered['body'] ) ) {
			return array(
				'subject' => $filtered['subject'],
				'body'    => $filtered['body'],
			);
		}

		return $message;
	}

	/**
	 * Subject and body in the current locale.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event        Event key.
	 * @param int    $post_id      Post id.
	 * @param int    $recipient_id Recipient user id.
	 * @param int    $actor_id     Acting user id.
	 * @param array  $args         Event arguments.
	 * @return array{subject: string, body: string}
	 */
	private function compose( string $event, int $post_id, int $recipient_id, int $actor_id, array $args ): array {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES );

		if ( '' === trim( $title ) ) {
			$title = __( '(no title)', 'sapphireit-editorial-workflow' );
		}

		$actor = $this->actor_name( $actor_id );

		switch ( $event ) {
			case NotificationEvents::REVIEWER_ASSIGNED:
				/* translators: 1: site name, 2: post title */
				$subject = sprintf( __( '[%1$s] Review assigned: %2$s', 'sapphireit-editorial-workflow' ), $site, $title );
				/* translators: 1: name of the person who assigned the review, 2: post title */
				$lead = sprintf( __( '%1$s asked you to review “%2$s”.', 'sapphireit-editorial-workflow' ), $actor, $title );
				break;
			case NotificationEvents::REVIEW_REQUESTED:
				/* translators: 1: site name, 2: post title */
				$subject = sprintf( __( '[%1$s] Ready for review: %2$s', 'sapphireit-editorial-workflow' ), $site, $title );
				/* translators: 1: name of the person who submitted the content, 2: post title */
				$lead = sprintf( __( '%1$s submitted “%2$s” for review.', 'sapphireit-editorial-workflow' ), $actor, $title );
				break;
			case NotificationEvents::CHANGES_REQUESTED:
				/* translators: 1: site name, 2: post title */
				$subject = sprintf( __( '[%1$s] Changes requested: %2$s', 'sapphireit-editorial-workflow' ), $site, $title );
				/* translators: 1: name of the person who asked for changes, 2: post title */
				$lead = sprintf( __( '%1$s asked for changes to “%2$s”.', 'sapphireit-editorial-workflow' ), $actor, $title );
				break;
			case NotificationEvents::APPROVED:
				/* translators: 1: site name, 2: post title */
				$subject = sprintf( __( '[%1$s] Approved: %2$s', 'sapphireit-editorial-workflow' ), $site, $title );
				/* translators: 1: name of the person who approved the content, 2: post title */
				$lead = sprintf( __( '%1$s approved “%2$s”.', 'sapphireit-editorial-workflow' ), $actor, $title );
				break;
			case NotificationEvents::COMMENT_ADDED:
				/* translators: 1: site name, 2: post title */
				$subject = sprintf( __( '[%1$s] New comment: %2$s', 'sapphireit-editorial-workflow' ), $site, $title );

				if ( $this->permissions->can_view_activity( $post_id, $recipient_id ) ) {
					$comment = wp_strip_all_tags( wp_specialchars_decode( (string) ( $args['message'] ?? '' ), ENT_QUOTES ) );
					/* translators: 1: name of the commenter, 2: post title. The comment text follows on the next lines. */
					$lead = sprintf( __( '%1$s commented on “%2$s”:', 'sapphireit-editorial-workflow' ), $actor, $title ) . "\n\n" . $comment;
				} else {
					/* translators: 1: name of the commenter, 2: post title */
					$lead = sprintf( __( '%1$s added a workflow comment to “%2$s”.', 'sapphireit-editorial-workflow' ), $actor, $title );
				}
				break;
			default:
				$subject = $site;
				$lead    = $title;
		}

		$body = $lead . "\n\n"
			/* translators: %s: URL of the post's edit screen */
			. sprintf( __( 'Open it: %s', 'sapphireit-editorial-workflow' ), $this->edit_link( $post_id ) ) . "\n\n"
			/* translators: %s: site name */
			. sprintf( __( 'You are receiving this because email notifications are turned on for %s.', 'sapphireit-editorial-workflow' ), $site );

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Edit-screen URL of the post.
	 *
	 * `get_edit_post_link()` checks the *current* user, who is the actor, or
	 * nobody when the system acts (cron). The recipient may still open the
	 * post, so the URL is built from the post type when core returns nothing.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return string URL; empty when the post type has no edit screen.
	 */
	private function edit_link( int $post_id ): string {
		$link = get_edit_post_link( $post_id, 'raw' );

		if ( is_string( $link ) && '' !== $link ) {
			return $link;
		}

		$type = get_post_type_object( (string) get_post_type( $post_id ) );

		if ( null === $type || empty( $type->_edit_link ) ) {
			return '';
		}

		return admin_url( sprintf( $type->_edit_link . '&action=edit', $post_id ) );
	}

	/**
	 * Display name of the actor, matching the activity timeline's wording.
	 *
	 * @since 1.1.0
	 *
	 * @param int $actor_id User id; `0` for the system.
	 * @return string Name, `System` for 0, `Someone` for a deleted user.
	 */
	private function actor_name( int $actor_id ): string {
		if ( 0 === $actor_id ) {
			return __( 'System', 'sapphireit-editorial-workflow' );
		}

		$user = get_userdata( $actor_id );

		if ( $user instanceof WP_User ) {
			return $user->display_name;
		}

		return __( 'Someone', 'sapphireit-editorial-workflow' );
	}
}
