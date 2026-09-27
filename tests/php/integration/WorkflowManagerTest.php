<?php
/**
 * Integration tests for the workflow manager.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use Sit_Cwm\Workflow\TransitionManager;
use Sit_Cwm\Workflow\WorkflowManager;
use WP_Error;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The transition pipeline, mutators, disclosure rules and read model.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Workflow\WorkflowManager
 */
final class WorkflowManagerTest extends WP_UnitTestCase {

	/**
	 * Actions a mutation may fire.
	 *
	 * @var string[]
	 */
	const ACTIONS = array(
		'sit_cwm_status_changed',
		'sit_cwm_reviewer_assigned',
		'sit_cwm_due_date_changed',
		'sit_cwm_comment_added',
		'sit_cwm_activity_logged',
	);

	/**
	 * Service under test.
	 *
	 * @var WorkflowManager
	 */
	private $workflow;

	/**
	 * Workflow meta persistence.
	 *
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Activity history.
	 *
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Fired plugin actions: list of [ hook, args ].
	 *
	 * @var array
	 */
	private $fired = array();

	/**
	 * Ensures the real activity table exists.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		( new Database() )->install();

		parent::set_up_before_class();
	}

	/**
	 * Grants default capabilities, builds the services and records actions.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		$settings       = new Settings();
		$statuses       = new StatusManager();
		$this->activity = new ActivityLogger( new Database() );
		$this->posts    = new PostRepository( $statuses, $settings );
		$this->workflow = new WorkflowManager(
			$statuses,
			new TransitionManager( $statuses ),
			new PermissionManager( $this->posts, $settings ),
			$this->posts,
			$this->activity
		);

		$this->fired = array();

		foreach ( self::ACTIONS as $hook ) {
			add_action(
				$hook,
				function ( ...$args ) use ( $hook ) {
					$this->fired[] = array( $hook, $args );
				},
				10,
				5
			);
		}
	}

	/**
	 * `draft → writing → review` by the author, `→ approved` by an editor.
	 *
	 * @return void
	 */
	public function test_happy_path() {
		$author = $this->make_user( 'author' );
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $author );

		$this->assertTrue( $this->workflow->transition( $post, 'draft', 'writing', $author ) );
		$this->assertTrue( $this->workflow->transition( $post, 'writing', 'review', $author ) );
		$this->assertTrue( $this->workflow->transition( $post, 'review', 'approved', $editor ) );

		$this->assertSame( 'approved', $this->workflow->get_status( $post ) );

		$entries = $this->activity->get_for_post( $post, array( 'order' => 'ASC' ) );
		$this->assertCount( 3, $entries );
		$this->assertSame(
			array(
				array( 'draft', 'writing', $author ),
				array( 'writing', 'review', $author ),
				array( 'review', 'approved', $editor ),
			),
			array_map(
				static function ( $entry ) {
					return array( $entry->get_old_value(), $entry->get_new_value(), $entry->get_user_id() );
				},
				$entries
			)
		);
	}

	/**
	 * Structure is checked independently of capability: even an administrator
	 * cannot skip `approved`.
	 *
	 * @return void
	 */
	public function test_illegal_edge_rejected_even_for_admin() {
		$admin = $this->make_user( 'administrator' );
		$post  = $this->make_post( $admin, 'review' );

		$this->assert_error( $this->workflow->transition( $post, 'review', 'published', $admin ), 'sit_cwm_invalid_transition', 400 );
		$this->assert_error( $this->workflow->transition( $post, 'review', 'bogus', $admin ), 'sit_cwm_invalid_status', 400 );
		$this->assertSame( 'review', $this->workflow->get_status( $post ) );
	}

	/**
	 * Capability is checked independently of structure: a legal edge is still
	 * refused to an author.
	 *
	 * @return void
	 */
	public function test_author_cannot_approve() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author, 'review' );

		$this->assert_error( $this->workflow->transition( $post, 'review', 'approved', $author ), 'sit_cwm_forbidden', 403 );
		$this->assertSame( 'review', $this->workflow->get_status( $post ) );
	}

	/**
	 * A stale `from` is a 409 and leaves the database untouched.
	 *
	 * @return void
	 */
	public function test_stale_from_is_conflict() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $this->make_user( 'author' ), 'review' );

		$this->assert_error( $this->workflow->transition( $post, 'writing', 'review', $editor ), 'sit_cwm_status_conflict', 409 );
		$this->assertFalse( $this->workflow->can_transition( $post, 'writing', 'review', $editor ) );
		$this->assertSame( 'review', $this->workflow->get_status( $post ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
	}

	/**
	 * Each successful mutation writes exactly one row with the right action;
	 * repeating a reviewer or due date is a silent no-op.
	 *
	 * @return void
	 */
	public function test_each_successful_mutation_writes_one_row() {
		$editor   = $this->make_user( 'editor' );
		$reviewer = $this->make_user( 'editor' );
		$post     = $this->make_post( $this->make_user( 'author' ) );

		$steps = array(
			'status_changed'    => function () use ( $post, $editor ) {
				return $this->workflow->transition( $post, 'draft', 'writing', $editor );
			},
			'reviewer_assigned' => function () use ( $post, $reviewer, $editor ) {
				return $this->workflow->assign_reviewer( $post, $reviewer, $editor );
			},
			'reviewer_cleared'  => function () use ( $post, $editor ) {
				return $this->workflow->assign_reviewer( $post, 0, $editor );
			},
			'due_date_set'      => function () use ( $post, $editor ) {
				return $this->workflow->set_due_date( $post, '2026-09-20', $editor );
			},
			'due_date_cleared'  => function () use ( $post, $editor ) {
				return $this->workflow->set_due_date( $post, '', $editor );
			},
			'comment_added'     => function () use ( $post, $editor ) {
				return $this->workflow->add_comment( $post, '<strong>Looks good</strong>', $editor );
			},
		);

		foreach ( $steps as $action => $step ) {
			$before = $this->activity->count_for_post( $post );
			$result = $step();

			$this->assertNotWPError( $result, $action );
			$this->assertSame( $before + 1, $this->activity->count_for_post( $post ), $action );
			$this->assertSame( $action, $this->activity->get_for_post( $post )[0]->get_action(), $action );
		}

		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
		$this->assertSame( '', $this->posts->get_due_date( $post ) );

		// No-ops: same reviewer, same date, clearing what is already clear.
		$this->workflow->assign_reviewer( $post, $reviewer, $editor );
		$this->workflow->set_due_date( $post, '2026-09-20', $editor );
		$before = $this->activity->count_for_post( $post );

		$this->assertTrue( $this->workflow->assign_reviewer( $post, $reviewer, $editor ) );
		$this->assertTrue( $this->workflow->set_due_date( $post, ' 2026-09-20 ', $editor ) );
		$this->assertSame( $before, $this->activity->count_for_post( $post ) );
	}

	/**
	 * Every failing mutation writes no meta, no rows and fires no action.
	 *
	 * @return void
	 */
	public function test_failing_mutations_write_nothing_and_fire_nothing() {
		$author     = $this->make_user( 'author' );
		$editor     = $this->make_user( 'editor' );
		$subscriber = $this->make_user( 'subscriber' );
		$post       = $this->make_post( $author, 'review' );

		$failures = array(
			array( 'sit_cwm_invalid_transition', $this->workflow->transition( $post, 'review', 'published', $editor ) ),
			array( 'sit_cwm_invalid_status', $this->workflow->transition( $post, 'review', 'bogus', $editor ) ),
			array( 'sit_cwm_forbidden', $this->workflow->transition( $post, 'review', 'approved', $author ) ),
			array( 'sit_cwm_status_conflict', $this->workflow->transition( $post, 'approved', 'published', $editor ) ),
			array( 'sit_cwm_not_managed', $this->workflow->transition( $post, 'review', 'approved', $subscriber ) ),
			array( 'sit_cwm_forbidden', $this->workflow->assign_reviewer( $post, $editor, $author ) ),
			array( 'sit_cwm_invalid_user', $this->workflow->assign_reviewer( $post, 999999, $editor ) ),
			array( 'sit_cwm_invalid_user', $this->workflow->assign_reviewer( $post, -1 * $editor, $editor ) ),
			array( 'sit_cwm_forbidden', $this->workflow->set_due_date( $post, '2026-09-20', $author ) ),
			array( 'sit_cwm_invalid_date', $this->workflow->set_due_date( $post, '20-09-2026', $editor ) ),
			array( 'sit_cwm_invalid_date', $this->workflow->set_due_date( $post, '2026-02-31', $editor ) ),
			array( 'sit_cwm_empty_comment', $this->workflow->add_comment( $post, '', $editor ) ),
			array( 'sit_cwm_not_managed', $this->workflow->add_comment( $post, 'Hi', 0 ) ),
		);

		foreach ( $failures as $index => $failure ) {
			$this->assertWPError( $failure[1], (string) $index );
			$this->assertSame( $failure[0], $failure[1]->get_error_code(), (string) $index );
		}

		$this->assertSame( 'review', $this->posts->get_status( $post ) );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
		$this->assertSame( '', $this->posts->get_due_date( $post ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * A write the database refuses is reported as a 500, never as success:
	 * nothing is logged and no action fires for a change that did not stick.
	 *
	 * @return void
	 */
	public function test_storage_failure_is_reported_and_leaves_no_trace() {
		$author = $this->make_user( 'author' );
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $author, 'review' );

		$refuse = static function ( $check, $object_id, $meta_key ) {
			return 0 === strpos( $meta_key, '_sit_cwm_' ) ? false : $check;
		};
		add_filter( 'update_post_metadata', $refuse, 10, 3 );
		add_filter( 'add_post_metadata', $refuse, 10, 3 );

		$results = array(
			'transition'      => $this->workflow->transition( $post, 'review', 'approved', $editor ),
			'assign_reviewer' => $this->workflow->assign_reviewer( $post, $editor, $editor ),
			'set_due_date'    => $this->workflow->set_due_date( $post, '2026-10-01', $editor ),
		);

		remove_filter( 'update_post_metadata', $refuse, 10 );
		remove_filter( 'add_post_metadata', $refuse, 10 );

		foreach ( $results as $method => $result ) {
			$this->assertWPError( $result, $method );
			$this->assertSame( 'sit_cwm_update_failed', $result->get_error_code(), $method );
			$this->assertSame( 500, $result->get_error_data()['status'], $method );
		}

		$this->assertSame( 'review', $this->posts->get_status( $post ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * An existing user without the review capability cannot be assigned.
	 *
	 * @return void
	 */
	public function test_reviewer_must_hold_the_review_capability() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $this->make_user( 'author' ), 'review' );

		$result = $this->workflow->assign_reviewer( $post, $this->make_user( 'author' ), $editor );

		$this->assertWPError( $result );
		$this->assertSame( 'sit_cwm_invalid_user', $result->get_error_code() );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
	}

	/**
	 * Authorization precedes reviewer validation, so an unauthorized user
	 * cannot tell existing user ids from missing ones.
	 *
	 * @return void
	 */
	public function test_unauthorized_user_cannot_probe_user_ids() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		$this->assert_error( $this->workflow->assign_reviewer( $post, $this->make_user( 'editor' ), $author ), 'sit_cwm_forbidden', 403 );
		$this->assert_error( $this->workflow->assign_reviewer( $post, 999999, $author ), 'sit_cwm_forbidden', 403 );
	}

	/**
	 * `sit_cwm_status_changed` fires once with `( $post_id, $from, $to, $user_id )`,
	 * resolving a null user to the current user.
	 *
	 * @return void
	 */
	public function test_status_changed_action_fires_once_with_context() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		wp_set_current_user( $author );

		$this->assertTrue( $this->workflow->transition( $post, 'draft', 'writing' ) );

		$status_changes = array_values(
			array_filter(
				$this->fired,
				static function ( $call ) {
					return 'sit_cwm_status_changed' === $call[0];
				}
			)
		);

		$this->assertSame( array( array( 'sit_cwm_status_changed', array( $post, 'draft', 'writing', $author ) ) ), $status_changes );
	}

	/**
	 * The other D10 actions fire with their documented arguments.
	 *
	 * @return void
	 */
	public function test_mutator_actions_carry_context() {
		$editor   = $this->make_user( 'editor' );
		$reviewer = $this->make_user( 'editor' );
		$post     = $this->make_post( $this->make_user( 'author' ) );

		$this->workflow->assign_reviewer( $post, $reviewer, $editor );
		$this->workflow->set_due_date( $post, '2026-09-20', $editor );
		$comment = $this->workflow->add_comment( $post, 'Ship it<script>x</script>', $editor );

		$plugin_actions = array_values(
			array_filter(
				$this->fired,
				static function ( $call ) {
					return 'sit_cwm_activity_logged' !== $call[0];
				}
			)
		);

		$this->assertSame(
			array(
				array( 'sit_cwm_reviewer_assigned', array( $post, $reviewer, 0, $editor ) ),
				array( 'sit_cwm_due_date_changed', array( $post, '2026-09-20', '', $editor ) ),
				array( 'sit_cwm_comment_added', array( $post, $comment, 'Ship itx', $editor ) ),
			),
			$plugin_actions
		);
	}

	/**
	 * Unmanaged post types and missing posts are 404 (or empty) for every
	 * method, even for an administrator.
	 *
	 * @return void
	 */
	public function test_unmanaged_post_is_not_found_everywhere() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );

		$admin = $this->make_user( 'administrator' );
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $admin,
				'post_status' => 'draft',
			)
		);

		foreach ( array( $page, 999999, 0 ) as $post ) {
			$this->assert_error( $this->workflow->transition( $post, 'draft', 'writing', $admin ), 'sit_cwm_not_managed', 404 );
			$this->assert_error( $this->workflow->assign_reviewer( $post, $admin, $admin ), 'sit_cwm_not_managed', 404 );
			$this->assert_error( $this->workflow->set_due_date( $post, '2026-09-20', $admin ), 'sit_cwm_not_managed', 404 );
			$this->assert_error( $this->workflow->add_comment( $post, 'Hi', $admin ), 'sit_cwm_not_managed', 404 );
			$this->assertFalse( $this->workflow->can_transition( $post, 'draft', 'writing', $admin ) );
			$this->assertSame( array(), $this->workflow->get_available_transitions( $post, $admin ) );
			$this->assertSame( array(), $this->workflow->get_workflow( $post, $admin ) );
		}

		$this->assertSame( array(), $this->fired );
	}

	/**
	 * A post the user cannot read is indistinguishable from a missing one:
	 * 404, never 403.
	 *
	 * @return void
	 */
	public function test_unreadable_post_is_not_found_not_forbidden() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $this->make_user( 'author' ), 'review' );

		$this->assert_error( $this->workflow->transition( $post, 'review', 'approved', $author ), 'sit_cwm_not_managed', 404 );
		$this->assert_error( $this->workflow->transition( $post, 'writing', 'review', $author ), 'sit_cwm_not_managed', 404, 'No 409 either: the status must not leak.' );
		$this->assert_error( $this->workflow->add_comment( $post, 'Hi', $author ), 'sit_cwm_not_managed', 404 );
		$this->assertSame( array(), $this->workflow->get_workflow( $post, $author ) );
	}

	/**
	 * `add_comment()` rejects empty, whitespace-only and markup-only bodies.
	 *
	 * @return void
	 */
	public function test_empty_comments_are_rejected() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor );

		foreach ( array( '', '   ', "\n\t", '<p> </p>', '<script></script>' ) as $message ) {
			$this->assert_error( $this->workflow->add_comment( $post, $message, $editor ), 'sit_cwm_empty_comment', 400 );
		}

		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
	}

	/**
	 * `can_transition()` agrees with `transition()` and writes nothing.
	 *
	 * @return void
	 */
	public function test_can_transition_is_side_effect_free() {
		$author = $this->make_user( 'author' );
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $author, 'review' );

		$this->assertTrue( $this->workflow->can_transition( $post, 'review', 'approved', $editor ) );
		$this->assertFalse( $this->workflow->can_transition( $post, 'review', 'approved', $author ) );
		$this->assertFalse( $this->workflow->can_transition( $post, 'review', 'published', $editor ) );

		$this->assertSame( 'review', $this->posts->get_status( $post ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
		$this->assertSame( array(), $this->fired );
	}

	/**
	 * `get_workflow()` returns the D9 shape with per-user transitions and
	 * capabilities.
	 *
	 * @return void
	 */
	public function test_get_workflow_shape() {
		$author   = $this->make_user( 'author' );
		$editor   = $this->make_user( 'editor' );
		$reviewer = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'John',
			)
		);
		$post     = $this->make_post( $author, 'review' );

		$this->posts->set_reviewer_id( $post, $reviewer );
		$this->posts->set_due_date( $post, '2026-09-20' );

		wp_set_current_user( $editor );
		$state = $this->workflow->get_workflow( $post );

		$this->assertSame(
			array( 'post_id', 'post_title', 'post_type', 'post_status', 'edit_link', 'status', 'status_label', 'status_is_unknown', 'reviewer', 'due_date', 'available_transitions', 'capabilities' ),
			array_keys( $state )
		);
		$this->assertSame( $post, $state['post_id'] );
		$this->assertSame( 'draft', $state['post_status'], 'Native post status stays separate.' );
		$this->assertSame( 'review', $state['status'] );
		$this->assertSame( 'Review', $state['status_label'] );
		$this->assertSame( '2026-09-20', $state['due_date'] );
		$this->assertStringContainsString( 'post=' . $post, $state['edit_link'] );
		$this->assertSame( $reviewer, $state['reviewer']['id'] );
		$this->assertSame( 'John', $state['reviewer']['name'] );
		$this->assertArrayNotHasKey( 'email', $state['reviewer'] );
		$this->assertSame( array( 'approved', 'needs_changes' ), array_column( $state['available_transitions'], 'slug' ) );
		$this->assertSame( array( false, true ), array_column( $state['available_transitions'], 'is_rollback' ) );
		$this->assertSame(
			array(
				'can_change_status'   => true,
				'can_assign_reviewer' => true,
				'can_set_due_date'    => true,
				'can_comment'         => true,
				'can_view_activity'   => true,
			),
			$state['capabilities']
		);

		$as_author = $this->workflow->get_workflow( $post, $author );
		$this->assertSame( array(), $as_author['available_transitions'] );
		$this->assertFalse( $as_author['capabilities']['can_change_status'] );
		$this->assertFalse( $as_author['capabilities']['can_assign_reviewer'] );
		$this->assertTrue( $as_author['capabilities']['can_comment'] );

		$this->posts->set_reviewer_id( $post, 0 );
		$this->assertNull( $this->workflow->get_workflow( $post, $editor )['reviewer'] );
	}

	/**
	 * The available-transitions filter can relabel and remove but not widen.
	 *
	 * @return void
	 */
	public function test_available_transitions_filter_cannot_widen() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor, 'review' );

		add_filter(
			'sit_cwm_available_transitions',
			static function ( $transitions ) {
				$transitions[0]['label'] = 'Approve';
				unset( $transitions[1] );
				$transitions[] = array(
					'slug'  => 'published',
					'label' => 'Publish',
				);
				return $transitions;
			}
		);

		$available = $this->workflow->get_available_transitions( $post, $editor );

		$this->assertSame( array( 'approved' ), array_column( $available, 'slug' ) );
		$this->assertSame( 'Approve', $available[0]['label'] );
		$this->assertFalse( $this->workflow->can_transition( $post, 'review', 'published', $editor ) );
	}

	/**
	 * The container builds the manager.
	 *
	 * @return void
	 */
	public function test_container_wires_workflow_manager() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( WorkflowManager::class, $plugin->container()->get( 'workflow_manager' ) );
	}

	/**
	 * Asserts a WP_Error with a code and HTTP status.
	 *
	 * @param mixed  $result  Result to check.
	 * @param string $code    Expected error code.
	 * @param int    $status  Expected HTTP status.
	 * @param string $message Optional failure message.
	 * @return void
	 */
	private function assert_error( $result, string $code, int $status, string $message = '' ) {
		$this->assertInstanceOf( WP_Error::class, $result, $message );
		$this->assertSame( $code, $result->get_error_code(), $message );
		$this->assertSame( array( 'status' => $status ), $result->get_error_data(), $message );
	}

	/**
	 * Creates a user with a role.
	 *
	 * @param string $role Role slug.
	 * @return int User id.
	 */
	private function make_user( string $role ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}

	/**
	 * Creates a draft post, optionally at a workflow status.
	 *
	 * @param int    $author_id Author user id.
	 * @param string $status    Workflow status; empty leaves the default.
	 * @return int Post id.
	 */
	private function make_post( int $author_id, string $status = '' ): int {
		$post = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);

		if ( '' !== $status ) {
			$this->posts->set_status( $post, $status );
		}

		return $post;
	}
}
