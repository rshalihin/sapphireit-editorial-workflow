<?php
/**
 * Publish gate refusal notices.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing;

use Sit_Cwm\Core\Interfaces\Bootable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells a person, on their next admin screen, that a save of theirs was not
 * published because of the gate (E5, E14).
 *
 * The queue is a per-user transient of post ids: a one-shot UI message, not
 * state worth keeping in user meta. The "saved as" status is read when the
 * notice renders, by which time the refusing layer's downgrade has been
 * written, so no layer has to know the final status while it is filtering.
 *
 * @since 1.1.0
 * @internal
 */
final class RefusalNotices implements Bootable {

	/**
	 * Transient name prefix; the user id is appended.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'sit_cwm_gate_notices_';

	/**
	 * Queue lifetime, in seconds.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Hooks the notice output.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Unhooks the notice output; mirrors `register()`.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Queues a notice about a post for a user. A no-op for user 0 (cron, CLI
	 * without `--user`): there is nobody to tell.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User to tell.
	 * @param int $post_id Refused post.
	 * @return void
	 */
	public function queue( int $user_id, int $post_id ): void {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return;
		}

		$pending = $this->pending( $user_id );

		if ( in_array( $post_id, $pending, true ) ) {
			return;
		}

		$pending[] = $post_id;

		set_transient( self::TRANSIENT_PREFIX . $user_id, $pending, self::TTL );
	}

	/**
	 * Post ids queued for a user, unique, in queue order.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User id.
	 * @return int[]
	 */
	public function pending( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$stored = get_transient( self::TRANSIENT_PREFIX . $user_id );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $stored ) ) ) );
	}

	/**
	 * Prints and clears the current user's queued notices. Hooked on
	 * `admin_notices`.
	 *
	 * Posts that are gone, or that the user may no longer edit, are skipped.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render(): void {
		$user_id = get_current_user_id();
		$pending = $this->pending( $user_id );

		if ( array() === $pending ) {
			return;
		}

		delete_transient( self::TRANSIENT_PREFIX . $user_id );

		foreach ( $pending as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$status = get_post_status_object( (string) get_post_status( $post ) );
			$label  = $status ? $status->label : (string) get_post_status( $post );

			printf(
				'<div class="notice notice-warning is-dismissible sit-cwm-gate-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: post title, 2: post status label, e.g. "Draft". */
						__( '“%1$s” was not published: it has to be approved in the editorial workflow first. It was saved as %2$s.', 'sapphireit-editorial-workflow' ),
						wp_strip_all_tags( get_the_title( $post ) ),
						$label
					)
				)
			);
		}
	}
}
