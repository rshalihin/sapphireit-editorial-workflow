<?php
/**
 * Integration tests for the bulk workflow REST controller.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\REST\BatchController;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\BulkProcessor;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /sit-cwm/v1/posts/batch`, dispatched through the REST server.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\BatchController
 * @covers \Sit_Cwm\Workflow\BulkProcessor
 * @covers \Sit_Cwm\Workflow\PermissionManager::can_attempt_batch
 * @covers \Sit_Cwm\Workflow\PermissionManager::can_be_reviewer
 */
final class BatchControllerTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Route under test.
	 *
	 * @var string
	 */
	const ROUTE = '/sit-cwm/v1/posts/batch';

	/**
	 * Logged-out requests are 401 and change nothing.
	 *
	 * @return void
	 */
	public function test_logged_out_is_unauthorized() {
		$post = $this->create_managed_post( array( 'post_author' => self::fixture_user( 'author' ) ) );

		wp_set_current_user( 0 );

		$this->assert_error_response( $this->batch( array( $post ), 'change_status', array( 'status' => 'writing' ) ), 'rest_forbidden', 401 );
		$this->assert_status( $post, 'draft' );
	}

	/**
	 * A subscriber may not even attempt a batch.
	 *
	 * @return void
	 */
	public function test_subscriber_cannot_attempt_batch() {
		$post = $this->create_managed_post(
			array(
				'post_author'     => self::fixture_user( 'author' ),
				'post_status'     => 'publish',
				'workflow_status' => 'review',
			)
		);

		wp_set_current_user( self::fixture_user( 'subscriber' ) );

		$this->assert_error_response( $this->batch( array( $post ), 'change_status', array( 'status' => 'approved' ) ), 'sit_cwm_forbidden', 403 );
		$this->assert_status( $post, 'review' );
	}

	/**
	 * Approving three posts: the allowed ones change, the one whose status
	 * makes the move illegal is reported, and its meta is untouched.
	 *
	 * @return void
	 */
	public function test_approving_changes_allowed_posts_and_reports_the_rest() {
		$author  = self::fixture_user( 'author' );
		$first   = $this->create_managed_post( $this->post_args( $author, 'review' ) );
		$second  = $this->create_managed_post( $this->post_args( $author, 'review' ) );
		$illegal = $this->create_managed_post( $this->post_args( $author, 'writing' ) );

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$response = $this->batch( array( $first, $second, $illegal ), 'change_status', array( 'status' => 'approved' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $first, $second ), $data['succeeded'] );
		$this->assertSame( array( $illegal ), array_column( $data['failed'], 'post_id' ) );
		$this->assertSame( 'sit_cwm_invalid_transition', $data['failed'][0]['code'] );
		$this->assertSame( 400, $data['failed'][0]['status'] );
		$this->assertSame( array( $first, $second ), array_column( $data['items'], 'post_id' ) );
		$this->assertSame( array( 'approved', 'approved' ), array_column( $data['items'], 'status' ) );

		$this->assert_status( $first, 'approved' );
		$this->assert_status( $second, 'approved' );
		$this->assert_status( $illegal, 'writing' );
		$this->assertSame( 'writing', get_metadata_raw( 'post', $illegal, '_sit_cwm_status', true ) );
		$this->assert_activity_count( $illegal, 0 );
	}

	/**
	 * A post the user can see but not edit fails with `sit_cwm_forbidden`
	 * while the others in the batch succeed.
	 *
	 * @return void
	 */
	public function test_post_the_user_cannot_edit_is_forbidden_while_others_succeed() {
		$author = self::fixture_user( 'author' );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$own    = $this->create_managed_post( $this->post_args( $author, 'writing' ) );
		$theirs = $this->create_managed_post( $this->post_args( $other, 'writing', 'publish' ) );

		wp_set_current_user( $author );

		$data = $this->batch( array( $own, $theirs ), 'change_status', array( 'status' => 'review' ) )->get_data();

		$this->assertSame( array( $own ), $data['succeeded'] );
		$this->assertSame(
			array(
				array(
					'post_id' => $theirs,
					'code'    => 'sit_cwm_forbidden',
				),
			),
			array_map(
				static function ( $entry ) {
					return array_intersect_key( $entry, array_flip( array( 'post_id', 'code' ) ) );
				},
				$data['failed']
			)
		);
		$this->assert_status( $own, 'review' );
		$this->assert_status( $theirs, 'writing' );
		$this->assert_activity_count( $theirs, 0 );
	}

	/**
	 * Deleted, trashed and hidden posts fail with one identical error and no
	 * fatal, so a batch never confirms that a hidden post exists.
	 *
	 * @return void
	 */
	public function test_missing_trashed_and_hidden_posts_are_invalid() {
		$author  = self::fixture_user( 'author' );
		$own     = $this->create_managed_post( $this->post_args( $author, 'writing' ) );
		$trashed = $this->create_managed_post( $this->post_args( $author, 'writing', 'trash' ) );
		$hidden  = $this->create_managed_post( $this->post_args( self::fixture_user( 'editor' ), 'writing' ) );

		wp_set_current_user( $author );

		$data = $this->batch( array( $own, 999999, $trashed, $hidden ), 'change_status', array( 'status' => 'review' ) )->get_data();

		$this->assertSame( array( $own ), $data['succeeded'] );
		$this->assertSame( array( 999999, $trashed, $hidden ), array_column( $data['failed'], 'post_id' ) );
		$this->assertSame( array( 'sit_cwm_invalid_post' ), array_values( array_unique( array_column( $data['failed'], 'code' ) ) ) );
		$this->assertCount( 1, array_unique( array_column( $data['failed'], 'message' ) ) );
		$this->assert_status( $trashed, 'writing' );
		$this->assert_status( $hidden, 'writing' );
	}

	/**
	 * More than `MAX_ITEMS` ids, or none, is a 400 for the whole request.
	 *
	 * @return void
	 */
	public function test_batch_size_is_capped() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$this->assert_error_response( $this->batch( range( 1, BulkProcessor::MAX_ITEMS + 1 ), 'change_status', array( 'status' => 'writing' ) ), 'rest_invalid_param', 400 );
		$this->assert_error_response( $this->batch( array(), 'change_status', array( 'status' => 'writing' ) ), 'rest_invalid_param', 400 );
	}

	/**
	 * Duplicate ids are processed once: one success, one activity row, one action.
	 *
	 * @return void
	 */
	public function test_duplicate_ids_are_processed_once() {
		$post  = $this->create_managed_post( $this->post_args( self::fixture_user( 'author' ), 'writing' ) );
		$fired = $this->count_status_changes();

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$data = $this->batch( array( $post, $post, $post ), 'change_status', array( 'status' => 'review' ) )->get_data();

		$this->assertSame( array( $post ), $data['succeeded'] );
		$this->assertSame( array(), $data['failed'] );
		$this->assert_activity_count( $post, 1 );
		$this->assertSame( 1, $fired->count );
	}

	/**
	 * N successes log exactly N activity rows and fire N separate actions.
	 *
	 * @return void
	 */
	public function test_each_success_logs_and_fires_once() {
		$author = self::fixture_user( 'author' );
		$ids    = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->create_managed_post( $this->post_args( $author, 'review' ) );
		}

		$fired = $this->count_status_changes();

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$data = $this->batch( $ids, 'change_status', array( 'status' => 'approved' ) )->get_data();

		$this->assertSame( $ids, $data['succeeded'] );
		$this->assertSame( 3, $fired->count );
		$this->assertSame( $ids, $fired->post_ids );

		foreach ( $ids as $post_id ) {
			$this->assert_activity_count( $post_id, 1 );
		}
	}

	/**
	 * Assigning a user without `sit_cwm_review_content` fails for every item
	 * with a 400 and writes nothing; an eligible reviewer succeeds.
	 *
	 * @return void
	 */
	public function test_reviewer_must_be_able_to_review() {
		$author = self::fixture_user( 'author' );
		$ids    = array(
			$this->create_managed_post( $this->post_args( $author, 'review' ) ),
			$this->create_managed_post( $this->post_args( $author, 'review' ) ),
		);

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$data = $this->batch( $ids, 'assign_reviewer', array( 'reviewer_id' => self::fixture_user( 'subscriber' ) ) )->get_data();

		$this->assertSame( array(), $data['succeeded'] );
		$this->assertSame( $ids, array_column( $data['failed'], 'post_id' ) );
		$this->assertSame( array( 'sit_cwm_invalid_user' ), array_values( array_unique( array_column( $data['failed'], 'code' ) ) ) );
		$this->assertSame( array( 400 ), array_values( array_unique( array_column( $data['failed'], 'status' ) ) ) );

		foreach ( $ids as $post_id ) {
			$this->assertSame( 0, $this->posts->get_reviewer_id( $post_id ) );
			$this->assert_activity_count( $post_id, 0 );
		}

		$reviewer = self::fixture_user( 'reviewer' );
		$data     = $this->batch( $ids, 'assign_reviewer', array( 'reviewer_id' => $reviewer ) )->get_data();

		$this->assertSame( $ids, $data['succeeded'] );

		foreach ( $ids as $post_id ) {
			$this->assertSame( $reviewer, $this->posts->get_reviewer_id( $post_id ) );
		}
	}

	/**
	 * Due dates can be set and cleared in bulk.
	 *
	 * @return void
	 */
	public function test_bulk_due_date() {
		$author = self::fixture_user( 'author' );
		$ids    = array(
			$this->create_managed_post( $this->post_args( $author, 'writing' ) ),
			$this->create_managed_post( $this->post_args( $author, 'review' ) ),
		);

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$this->assertSame( $ids, $this->batch( $ids, 'set_due_date', array( 'due_date' => '2026-10-01' ) )->get_data()['succeeded'] );
		$this->assertSame( '2026-10-01', $this->posts->get_due_date( $ids[1] ) );

		$this->assertSame( $ids, $this->batch( $ids, 'set_due_date', array( 'due_date' => '' ) )->get_data()['succeeded'] );
		$this->assertSame( '', $this->posts->get_due_date( $ids[0] ) );
	}

	/**
	 * Malformed requests are rejected as a whole, before anything is written.
	 *
	 * @return void
	 */
	public function test_malformed_requests_are_bad_requests() {
		$post = $this->create_managed_post( $this->post_args( self::fixture_user( 'author' ), 'review' ) );

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$cases = array(
			'unknown action'       => array( 'delete_posts', array( 'status' => 'approved' ) ),
			'payload for another'  => array( 'change_status', array( 'reviewer_id' => 1 ) ),
			'unknown status'       => array( 'change_status', array( 'status' => 'bogus' ) ),
			'unknown payload key'  => array(
				'change_status',
				array(
					'status' => 'approved',
					'evil'   => 1,
				),
			),
			'impossible due date'  => array( 'set_due_date', array( 'due_date' => '2026-02-31' ) ),
			'negative reviewer id' => array( 'assign_reviewer', array( 'reviewer_id' => -3 ) ),
		);

		foreach ( $cases as $label => $case ) {
			$this->assert_error_response( $this->batch( array( $post ), $case[0], $case[1] ), 'rest_invalid_param', 400, $label );
		}

		$this->assert_error_response( $this->batch( array( $post ), 'change_status', null ), 'rest_missing_callback_param', 400 );
		$this->assert_status( $post, 'review' );
		$this->assert_activity_count( $post, 0 );
	}

	/**
	 * The route exists with a real permission callback, and the container
	 * builds the controller and processor.
	 *
	 * @return void
	 */
	public function test_route_and_container_wiring() {
		$routes = rest_get_server()->get_routes( 'sit-cwm/v1' );

		$this->assertArrayHasKey( self::ROUTE, $routes );

		foreach ( $routes[ self::ROUTE ] as $handler ) {
			$this->assertNotEmpty( $handler['permission_callback'] );
			$this->assertNotSame( '__return_true', $handler['permission_callback'] );
		}

		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( BatchController::class, $plugin->container()->get( 'rest.batch' ) );
		$this->assertInstanceOf( BulkProcessor::class, $plugin->container()->get( 'bulk_processor' ) );
		$this->assertContains( 'rest.batch', Plugin::BOOT_PHASES['rest'] );
	}

	/**
	 * Dispatches a batch request.
	 *
	 * @param array      $post_ids Post ids.
	 * @param string     $action   Action.
	 * @param array|null $payload  Payload; null omits it.
	 * @return WP_REST_Response
	 */
	private function batch( array $post_ids, string $action, ?array $payload ): WP_REST_Response {
		$body = array(
			'post_ids' => $post_ids,
			'action'   => $action,
		);

		if ( null !== $payload ) {
			$body['payload'] = $payload;
		}

		return $this->rest_request( 'POST', self::ROUTE, $body );
	}

	/**
	 * Factory arguments for a post at a workflow status.
	 *
	 * @param int    $author_id       Author id.
	 * @param string $workflow_status Workflow status.
	 * @param string $post_status     Native post status.
	 * @return array
	 */
	private function post_args( int $author_id, string $workflow_status, string $post_status = 'draft' ): array {
		return array(
			'post_author'     => $author_id,
			'post_status'     => $post_status,
			'workflow_status' => $workflow_status,
		);
	}

	/**
	 * Counts `sit_cwm_status_changed` actions from now on.
	 *
	 * @return object{count: int, post_ids: int[]}
	 */
	private function count_status_changes() {
		$fired = (object) array(
			'count'    => 0,
			'post_ids' => array(),
		);

		add_action(
			'sit_cwm_status_changed',
			static function ( $post_id ) use ( $fired ) {
				++$fired->count;
				$fired->post_ids[] = $post_id;
			}
		);

		return $fired;
	}
}
