<?php
/**
 * Notification deduplication.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stops the same mail going to the same person twice in a short window (E13),
 * e.g. when a double save fires the same workflow action twice.
 *
 * Claims are transients, so they are not atomic across parallel requests:
 * two requests racing within milliseconds can both claim. That is good
 * enough for the double-save case this targets.
 *
 * @since 1.1.0
 * @internal
 */
final class Deduplicator {

	/**
	 * Seconds a claim blocks the same mail.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const WINDOW = 120;

	/**
	 * Transient name prefix.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const PREFIX = 'sit_cwm_mail_';

	/**
	 * Claims a mail. Returns false when the same mail was claimed within
	 * `WINDOW` seconds, in which case nothing should be sent.
	 *
	 * @since 1.1.0
	 *
	 * @param string $event        Event key.
	 * @param int    $post_id      Post id.
	 * @param int    $recipient_id Recipient user id.
	 * @param string $fingerprint  What makes this occurrence distinct (E13):
	 *                             the target status, the reviewer id or the
	 *                             comment hash.
	 * @return bool True when the caller may send.
	 */
	public function claim( string $event, int $post_id, int $recipient_id, string $fingerprint ): bool {
		$key = self::PREFIX . md5( "$event|$post_id|$recipient_id|$fingerprint" );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, self::WINDOW );

		return true;
	}
}
