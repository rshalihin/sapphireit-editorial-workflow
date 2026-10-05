<?php
/**
 * Integration tests for workflow changes on an auto-draft (post-new.php).
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Tests\TestCase;
use WP_Post;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The classic editor meta box works on `post-new.php` before the first save.
 *
 * On `post-new.php` core has already inserted an `auto-draft` with an id, and
 * the meta box writes to it through REST. The first "Save Draft" then promotes
 * that same row to `draft`, so the workflow state written before it must stay.
 *
 * @since 1.0.0
 *
 * @coversNothing
 */
final class AutoDraftTest extends TestCase {

	/**
	 * Loads `get_default_post_to_edit()` and `edit_post()`.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	/**
	 * Clears the simulated post form.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * GET on a fresh auto-draft answers 200 with the default status.
	 *
	 * @return void
	 */
	public function test_get_on_auto_draft_returns_default_status() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$post = $this->new_auto_draft();

		$response = $this->rest_request( 'GET', '/sit-cwm/v1/posts/' . $post->ID . '/workflow' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'draft', $response->get_data()['status'] );
		$this->assertSame( 'auto-draft', $response->get_data()['post_status'] );
	}

	/**
	 * Workflow changes on an auto-draft survive the first "Save Draft".
	 *
	 * @return void
	 */
	public function test_workflow_changes_survive_first_save() {
		$reviewer = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$post  = $this->new_auto_draft();
		$route = '/sit-cwm/v1/posts/' . $post->ID;

		$this->assert_ok(
			$this->rest_request(
				'POST',
				$route . '/workflow',
				array(
					'reviewer_id' => $reviewer,
					'due_date'    => '2026-12-01',
				)
			)
		);
		$this->assert_ok(
			$this->rest_request(
				'POST',
				$route . '/workflow',
				array(
					'from'   => 'draft',
					'status' => 'writing',
				)
			)
		);
		$this->assert_ok( $this->rest_request( 'POST', $route . '/comments', array( 'message' => 'First note' ) ), 201 );

		$activity_before = $this->activity->count_for_post( $post->ID );

		$this->save_draft( $post );

		$saved = get_post( $post->ID );

		$this->assertSame( 'draft', $saved->post_status, 'Save Draft promotes the same row.' );
		$this->assert_status( $post->ID, 'writing' );
		$this->assertSame( $reviewer, $this->posts->get_reviewer_id( $post->ID ) );
		$this->assertSame( '2026-12-01', $this->posts->get_due_date( $post->ID ) );
		$this->assert_activity_count( $post->ID, $activity_before, 'Saving the post logs no workflow activity.' );
		$this->assertGreaterThan( 0, $activity_before );
	}

	/**
	 * Creates the auto-draft `post-new.php` opens, authored by the current user.
	 *
	 * @return WP_Post
	 */
	private function new_auto_draft(): WP_Post {
		$post = get_default_post_to_edit( 'post', true );

		$this->assertSame( 'auto-draft', $post->post_status );
		$this->assertGreaterThan( 0, $post->ID );

		return $post;
	}

	/**
	 * Submits the classic editor form the way "Save Draft" does.
	 *
	 * @param WP_Post $post Auto-draft.
	 * @return void
	 */
	private function save_draft( WP_Post $post ): void {
		$_POST = array(
			'post_ID'              => $post->ID,
			'post_type'            => $post->post_type,
			'post_author'          => $post->post_author,
			'post_title'           => 'Classic draft',
			'content'              => 'Body',
			'action'               => 'editpost',
			'originalaction'       => 'editpost',
			'original_post_status' => 'auto-draft',
			'post_status'          => 'draft',
			'save'                 => 'Save Draft',
		);

		edit_post();
	}

	/**
	 * Asserts a success status, showing the response body otherwise.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param int              $status   Expected HTTP status.
	 * @return void
	 */
	private function assert_ok( WP_REST_Response $response, int $status = 200 ): void {
		$this->assertSame( $status, $response->get_status(), wp_json_encode( $response->get_data() ) );
	}
}
