<?php
/**
 * Notification delivery.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Notifications;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Throwable;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns workflow actions into plain-text mail for the events the site turned
 * on (E12, E13).
 *
 * Mail goes out synchronously with `wp_mail()`; there is no queue. A bulk
 * action therefore sends one mail per affected post per recipient. Digests
 * and per-user preferences are Pro.
 *
 * Nothing that goes wrong here reaches the workflow action that triggered
 * it: every handler swallows its own errors.
 *
 * @since 1.1.0
 * @internal
 */
final class Notifier implements Bootable {

	/**
	 * Plugin settings.
	 *
	 * @since 1.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Recipient resolution.
	 *
	 * @since 1.1.0
	 * @var RecipientResolver
	 */
	private $recipients;

	/**
	 * Mail copy.
	 *
	 * @since 1.1.0
	 * @var MessageBuilder
	 */
	private $messages;

	/**
	 * Double-send protection.
	 *
	 * @since 1.1.0
	 * @var Deduplicator
	 */
	private $dedup;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param Settings          $settings   Plugin settings.
	 * @param RecipientResolver $recipients Recipient resolution.
	 * @param MessageBuilder    $messages   Mail copy.
	 * @param Deduplicator      $dedup      Double-send protection.
	 */
	public function __construct( Settings $settings, RecipientResolver $recipients, MessageBuilder $messages, Deduplicator $dedup ) {
		$this->settings   = $settings;
		$this->recipients = $recipients;
		$this->messages   = $messages;
		$this->dedup      = $dedup;
	}

	/**
	 * Hooks the 1.0 workflow actions at 20, after the core work at 10.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'sit_cwm_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		add_action( 'sit_cwm_reviewer_assigned', array( $this, 'on_reviewer_assigned' ), 20, 4 );
		add_action( 'sit_cwm_comment_added', array( $this, 'on_comment_added' ), 20, 4 );
	}

	/**
	 * Notifies about a workflow status change that has an event.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $post_id Post id.
	 * @param string $from    Previous status slug.
	 * @param string $to      New status slug.
	 * @param int    $user_id Acting user id.
	 * @return void
	 */
	public function on_status_changed( $post_id, $from, $to, $user_id ): void {
		$event = NotificationEvents::for_status( (string) $to );

		if ( null === $event ) {
			return;
		}

		$this->dispatch( $event, (int) $post_id, (int) $user_id, array( 'fingerprint' => (string) $to ) );
	}

	/**
	 * Notifies a newly assigned reviewer. Clearing the reviewer notifies nobody.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id     Post id.
	 * @param int $reviewer_id New reviewer id; `0` when cleared.
	 * @param int $previous_id Previous reviewer id.
	 * @param int $user_id     Acting user id.
	 * @return void
	 */
	public function on_reviewer_assigned( $post_id, $reviewer_id, $previous_id, $user_id ): void {
		if ( (int) $reviewer_id <= 0 ) {
			return;
		}

		$this->dispatch(
			NotificationEvents::REVIEWER_ASSIGNED,
			(int) $post_id,
			(int) $user_id,
			array(
				'reviewer_id' => (int) $reviewer_id,
				'fingerprint' => (string) (int) $reviewer_id,
			)
		);
	}

	/**
	 * Notifies about a new workflow comment.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $post_id     Post id.
	 * @param int    $activity_id Activity id of the comment.
	 * @param string $message     Sanitized comment body.
	 * @param int    $user_id     Acting user id.
	 * @return void
	 */
	public function on_comment_added( $post_id, $activity_id, $message, $user_id ): void {
		$this->dispatch(
			NotificationEvents::COMMENT_ADDED,
			(int) $post_id,
			(int) $user_id,
			array(
				'message'     => (string) $message,
				'fingerprint' => md5( (string) $message ),
			)
		);
	}

	/**
	 * Sends one mail per recipient of an enabled event.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event    Event key.
	 * @param int    $post_id  Post id.
	 * @param int    $actor_id Acting user id.
	 * @param array  $args     `fingerprint` (required), plus `reviewer_id` or `message`.
	 * @return void
	 */
	private function dispatch( string $event, int $post_id, int $actor_id, array $args ): void {
		try {
			if ( ! $this->settings->is_notification_enabled( $event ) ) {
				return;
			}

			foreach ( $this->recipients->resolve( $event, $post_id, $actor_id, $args['reviewer_id'] ?? 0 ) as $recipient ) {
				$user = get_userdata( $recipient );

				if ( ! $user instanceof WP_User || ! $this->dedup->claim( $event, $post_id, $recipient, $args['fingerprint'] ) ) {
					continue;
				}

				$message = $this->messages->build( $event, $post_id, $recipient, $actor_id, $args );
				$sent    = wp_mail( $user->user_email, $message['subject'], $message['body'] );

				/**
				 * Fires after a notification mail was handed to `wp_mail()`.
				 *
				 * @since 1.1.0
				 *
				 * @param string $event        Event key.
				 * @param int    $post_id      Post id.
				 * @param int    $recipient_id Recipient user id.
				 * @param bool   $sent         What `wp_mail()` returned.
				 */
				do_action( 'sit_cwm_notification_sent', $event, $post_id, $recipient, (bool) $sent );
			}
		} catch ( Throwable $e ) {
			// Mail must never break a workflow action.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'sapphireit-editorial-workflow: notification failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics.
			}
		}
	}
}
