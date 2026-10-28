<?php
/**
 * Integration tests for the publish gate refusal notices.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Publishing;

use Sit_Cwm\Publishing\RefusalNotices;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A refused save tells the person who made it, once, on their next admin
 * screen (E5, E14).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\RefusalNotices
 */
final class RefusalNoticesTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var RefusalNotices
	 */
	private $notices;

	/**
	 * Builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->notices = new RefusalNotices();
	}

	/**
	 * The notice names the post (escaped) and the status it was saved as,
	 * then the queue is cleared.
	 *
	 * @return void
	 */
	public function test_queue_and_render_prints_escaped_title_and_status_label_then_clears() {
		$user = self::fixture_user( 'author' );
		$post = self::factory()->post->create(
			array(
				'post_title'  => '<em>Hot</em> take',
				'post_author' => $user,
				'post_status' => 'draft',
			)
		);
		$this->notices->queue( $user, $post );
		wp_set_current_user( $user );

		$html = $this->render();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'sit-cwm-gate-notice', $html );
		$this->assertStringContainsString( '“Hot take” was not published', $html );
		$this->assertStringNotContainsString( '<em>', $html );
		$this->assertStringContainsString( 'It was saved as Draft.', $html );
		$this->assertSame( array(), $this->notices->pending( $user ) );
		$this->assertSame( '', $this->render() );
	}

	/**
	 * The status label is read at render time, from the stored status.
	 *
	 * @return void
	 */
	public function test_status_label_is_the_stored_status_at_render_time() {
		$user = self::fixture_user( 'author' );
		$post = self::factory()->post->create(
			array(
				'post_author' => $user,
				'post_status' => 'draft',
			)
		);
		$this->notices->queue( $user, $post );
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'pending',
			)
		);
		wp_set_current_user( $user );

		$this->assertStringContainsString( 'It was saved as Pending.', $this->render() );
	}

	/**
	 * Queued ids are unique and kept in queue order.
	 *
	 * @return void
	 */
	public function test_pending_is_unique_in_queue_order() {
		$user = self::fixture_user( 'author' );
		$this->notices->queue( $user, 12 );
		$this->notices->queue( $user, 5 );
		$this->notices->queue( $user, 12 );

		$this->assertSame( array( 12, 5 ), $this->notices->pending( $user ) );
		$this->assertSame( array(), $this->notices->pending( self::fixture_user( 'editor' ) ) );
	}

	/**
	 * Cron and CLI without a user have nobody to tell.
	 *
	 * @return void
	 */
	public function test_queue_for_user_zero_is_ignored() {
		$this->notices->queue( 0, 12 );

		$this->assertSame( array(), $this->notices->pending( 0 ) );
		$this->assertFalse( get_transient( RefusalNotices::TRANSIENT_PREFIX . '0' ) );
	}

	/**
	 * A post that is gone, or that the user may no longer edit, is skipped.
	 *
	 * @return void
	 */
	public function test_render_skips_posts_the_user_can_no_longer_edit() {
		$user    = self::fixture_user( 'author' );
		$deleted = self::factory()->post->create( array( 'post_author' => $user ) );
		$others  = self::factory()->post->create(
			array(
				'post_author' => self::fixture_user( 'editor' ),
				'post_status' => 'pending',
			)
		);
		wp_delete_post( $deleted, true );
		$this->notices->queue( $user, $deleted );
		$this->notices->queue( $user, $others );
		wp_set_current_user( $user );

		$this->assertSame( '', $this->render() );
		$this->assertSame( array(), $this->notices->pending( $user ) );
	}

	/**
	 * Rendering is hooked on `admin_notices`.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_notices() {
		$this->notices->register();
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->notices, 'render' ) ) );

		$this->notices->unregister();
		$this->assertFalse( has_action( 'admin_notices', array( $this->notices, 'render' ) ) );
	}

	/**
	 * Captures the notices output for the current user.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->notices->render();

		return (string) ob_get_clean();
	}
}
