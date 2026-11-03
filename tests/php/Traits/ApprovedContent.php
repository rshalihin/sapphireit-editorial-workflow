<?php
/**
 * Approved-and-published content fixture.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Arranges a managed post that went through Review to Approved, with its
 * approval row pinned to 2026-10-20 09:00:00 UTC, and was then published.
 *
 * The using test extends `TestCase` and uses `CreatesWorkflowPosts`, whose
 * editor ("Editor Fixture") approves by default.
 *
 * @since 1.2.0
 */
trait ApprovedContent {

	/**
	 * Creates an approved, published post.
	 *
	 * @param array $args {
	 *     Optional.
	 *
	 *     @type int $approver User logged as the approver; the fixture editor by default.
	 * }
	 * @return int Post id.
	 */
	protected function approved_and_published( array $args = array() ): int {
		$approver = isset( $args['approver'] ) ? (int) $args['approver'] : self::fixture_user( 'editor' );
		$post_id  = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		$this->posts->set_status( $post_id, 'review' );
		$this->activity->log(
			$post_id,
			'status_changed',
			array(
				'old_value'  => 'review',
				'new_value'  => 'approved',
				'user_id'    => $approver,
				'created_at' => '2026-10-20 09:00:00',
			)
		);
		$this->posts->set_status( $post_id, 'approved' );

		wp_publish_post( $post_id );

		return $post_id;
	}
}
