<?php
/**
 * Integration tests for logging edits made after review started.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Content;

use RuntimeException;
use Sit_Cwm\Activity\ActivityEntry;
use Sit_Cwm\Content\ContentChangeLogger;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An edit to the title, content or excerpt outside the writing statuses leaves
 * a `content_changed` row and fires an action. Nothing is refused.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Content\ContentChangeLogger
 */
final class ContentChangeLoggerTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Calls of `sit_cwm_content_changed`.
	 *
	 * @var array[]
	 */
	private $fired = array();

	/**
	 * Records the action.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->fired = array();

		add_action(
			'sit_cwm_content_changed',
			function ( ...$args ) {
				$this->fired[] = $args;
			},
			10,
			5
		);
	}

	/**
	 * An edit in review logs both hashes and fires the action with them.
	 *
	 * @return void
	 */
	public function test_edit_in_review_is_logged_and_fires_action() {
		$user = self::fixture_user( 'editor' );
		$post = $this->post_at( 'review' );
		$old  = $this->posts->content_fingerprint( $post );

		wp_set_current_user( $user );
		$this->edit( $post, 'Changed content.' );

		$new  = $this->posts->content_fingerprint( $post );
		$rows = $this->rows( $post );

		$this->assertNotSame( $old, $new );
		$this->assertCount( 1, $rows );
		$this->assertSame( $old, $rows[0]->get_old_value() );
		$this->assertSame( $new, $rows[0]->get_new_value() );
		$this->assertSame( $user, $rows[0]->get_user_id() );
		$this->assertSame( 'review', $rows[0]->get_context()['workflow_status'] );
		$this->assertArrayHasKey( 'source', $rows[0]->get_context() );
		$this->assertSame( array( array( $post, $old, $new, $user, 'review' ) ), $this->fired );
	}

	/**
	 * Approved and published content is past review too.
	 *
	 * @dataProvider past_review_statuses
	 *
	 * @param string $status Workflow status.
	 * @return void
	 */
	public function test_edit_while_approved_or_published_is_logged( string $status ) {
		$post = $this->post_at( $status );

		$this->edit( $post, 'Changed content.' );

		$this->assertCount( 1, $this->rows( $post ) );
		$this->assertCount( 1, $this->fired );
	}

	/**
	 * Statuses past the start of review.
	 *
	 * @return array<string, string[]>
	 */
	public function past_review_statuses(): array {
		return array(
			'approved'  => array( 'approved' ),
			'published' => array( 'published' ),
		);
	}

	/**
	 * The writing statuses are where edits belong.
	 *
	 * @dataProvider writing_statuses
	 *
	 * @param string $status Workflow status.
	 * @return void
	 */
	public function test_edit_while_writing_is_not_logged( string $status ) {
		$post = $this->post_at( $status );

		$this->edit( $post, 'Changed content.' );

		$this->assertSame( array(), $this->rows( $post ) );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * Statuses where the author is expected to edit.
	 *
	 * @return array<string, string[]>
	 */
	public function writing_statuses(): array {
		return array(
			'draft'         => array( 'draft' ),
			'writing'       => array( 'writing' ),
			'needs_changes' => array( 'needs_changes' ),
		);
	}

	/**
	 * The title and the excerpt count as much as the content.
	 *
	 * @return void
	 */
	public function test_title_and_excerpt_edits_are_logged() {
		$post = $this->post_at( 'review' );

		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'New title',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post,
				'post_excerpt' => 'New excerpt',
			)
		);

		$this->assertCount( 2, $this->fired );
	}

	/**
	 * An update that leaves the text alone is not an edit.
	 *
	 * @return void
	 */
	public function test_meta_only_or_status_only_update_is_ignored() {
		$post = $this->post_at( 'review' );

		update_post_meta( $post, 'some_key', 'some value' );
		wp_update_post(
			array(
				'ID'            => $post,
				'post_date'     => '2026-01-01 10:00:00',
				'post_date_gmt' => '2026-01-01 10:00:00',
			)
		);
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'pending',
			)
		);

		$this->assertSame( array(), $this->rows( $post ) );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * A revision is never an edit of its own.
	 *
	 * @return void
	 */
	public function test_revision_is_ignored() {
		$post     = $this->post_at( 'review' );
		$revision = _wp_put_post_revision( get_post( $post ) );
		$before   = get_post( $revision );
		$after    = clone $before;

		$after->post_content = 'Different.';

		sit_cwm_container()->get( 'content_change_logger' )->handle_post_updated( $revision, $after, $before );

		$this->assertSame( array(), $this->fired );
	}

	/**
	 * A post type outside the workflow is ignored.
	 *
	 * @return void
	 */
	public function test_unmanaged_type_is_ignored() {
		( new Settings() )->update( array( 'post_types' => array( 'post' ) ) );

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		update_post_meta( $page, '_sit_cwm_status', 'review' );
		$this->edit( $page, 'Changed content.' );

		$this->assertSame( array(), $this->rows( $page ) );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * Repeat saves by one user share a row but each fires the action; another
	 * user gets a row of their own.
	 *
	 * @return void
	 */
	public function test_repeat_edits_by_one_user_log_one_row_per_five_minutes_but_always_fire() {
		$post = $this->post_at( 'review' );

		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->edit( $post, 'First.' );
		$this->edit( $post, 'Second.' );

		$this->assertCount( 1, $this->rows( $post ) );
		$this->assertCount( 2, $this->fired );

		wp_set_current_user( self::fixture_user( 'author' ) );
		$this->edit( $post, 'Third.' );

		$this->assertCount( 2, $this->rows( $post ) );
		$this->assertCount( 3, $this->fired );
	}

	/**
	 * The edit right after an approval is the one that matters most, so a row
	 * from before the approval does not cover it.
	 *
	 * @return void
	 */
	public function test_edit_after_a_status_change_gets_its_own_row() {
		$post = $this->post_at( 'review' );

		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->edit( $post, 'Edited in review.' );

		$this->posts->set_status( $post, 'approved' );
		$this->edit( $post, 'Edited after approval.' );

		$rows = $this->rows( $post );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'approved', $rows[1]->get_context()['workflow_status'] );
	}

	/**
	 * A row that could not be written does not silence the next one.
	 *
	 * @return void
	 */
	public function test_failed_write_does_not_suppress_the_next_row() {
		$post   = $this->post_at( 'review' );
		$refuse = static function ( $actions ) {
			return array_values( array_diff( $actions, array( 'content_changed' ) ) );
		};

		add_filter( 'sit_cwm_activity_actions', $refuse );
		$this->edit( $post, 'First.' );
		remove_filter( 'sit_cwm_activity_actions', $refuse );

		$this->assertSame( array(), $this->rows( $post ) );

		$this->edit( $post, 'Second.' );

		$this->assertCount( 1, $this->rows( $post ) );
	}

	/**
	 * A logging failure must not cost add-ons the action: Pro clears sign-offs
	 * from it.
	 *
	 * @return void
	 */
	public function test_failing_activity_listener_does_not_cancel_the_action() {
		$post = $this->post_at( 'review' );

		add_action(
			'sit_cwm_activity_logged',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		$this->edit( $post, 'Changed content.' );

		$this->assertCount( 1, $this->fired );
		$this->assertSame( 'Changed content.', get_post( $post )->post_content );
	}

	/**
	 * A failing listener must not break the save.
	 *
	 * @return void
	 */
	public function test_failure_never_breaks_the_save() {
		$post = $this->post_at( 'review' );

		add_action(
			'sit_cwm_content_changed',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		$this->edit( $post, 'Changed content.' );

		$this->assertSame( 'Changed content.', get_post( $post )->post_content );
	}

	/**
	 * Nothing about the workflow moves because of an edit.
	 *
	 * @return void
	 */
	public function test_edit_changes_no_workflow_state() {
		$post = $this->post_at( 'approved' );

		$this->edit( $post, 'Changed content.' );

		$this->assertSame( 'approved', $this->posts->get_status( $post ) );
	}

	/**
	 * The service is wired on every request.
	 *
	 * @return void
	 */
	public function test_service_is_booted_on_post_updated() {
		$logger = sit_cwm_container()->get( 'content_change_logger' );

		$this->assertInstanceOf( ContentChangeLogger::class, $logger );
		$this->assertSame( 20, has_action( 'post_updated', array( $logger, 'handle_post_updated' ) ) );
	}

	/**
	 * A draft post at a workflow status.
	 *
	 * @param string $status Workflow status.
	 * @return int Post id.
	 */
	private function post_at( string $status ): int {
		return $this->create_managed_post(
			array(
				'post_status'     => 'draft',
				'post_content'    => 'Original content.',
				'workflow_status' => $status,
			)
		);
	}

	/**
	 * Changes a post's content.
	 *
	 * @param int    $post_id Post id.
	 * @param string $content New content.
	 * @return void
	 */
	private function edit( int $post_id, string $content ): void {
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);
	}

	/**
	 * A post's `content_changed` rows.
	 *
	 * @param int $post_id Post id.
	 * @return ActivityEntry[]
	 */
	private function rows( int $post_id ): array {
		return $this->activity->get_batch(
			array(
				'actions' => array( 'content_changed' ),
				'post_id' => $post_id,
			)
		);
	}
}
