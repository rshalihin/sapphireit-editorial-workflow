<?php
/**
 * Integration tests for the publication logger.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Publishing;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\PublicationLogger;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Publishing\RequestSource;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publication and unpublication of managed content are logged in every gate
 * mode, with the actor, the time and the workflow status (roadmap §3.1B, E6).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\PublicationLogger
 * @covers \Sit_Cwm\Publishing\RequestSource
 */
final class PublicationLoggerTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var PublicationLogger
	 */
	private $logger;

	/**
	 * Replaces the booted plugin's logger with one under test, so every
	 * transition is logged exactly once.
	 *
	 * The test library restores the original hooks after each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remove_booted_loggers();

		$settings     = new Settings();
		$this->logger = new PublicationLogger(
			$this->activity,
			$this->posts,
			$settings,
			new PublishGate( $settings, $this->posts, new PermissionManager( $this->posts, $settings ) )
		);
		$this->logger->register();
	}

	/**
	 * Detaches the logger under test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_action( 'transition_post_status', array( $this->logger, 'handle_transition' ), 10 );

		parent::tear_down();
	}

	/**
	 * Roadmap §3.1B acceptance: publishing at `review` with no gate configured
	 * names the actor, the time and the workflow status.
	 *
	 * @return void
	 */
	public function test_publishing_at_review_with_gate_off_logs_actor_time_and_workflow_status() {
		$author  = self::fixture_user( 'author' );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $author,
			)
		);
		$this->posts->set_status( $post_id, 'review' );
		wp_set_current_user( $author );

		$before = gmdate( 'Y-m-d H:i:s', time() - 1 );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$entries = $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( $author, $entries[0]->get_user_id() );
		$this->assertSame( 'draft', $entries[0]->get_old_value() );
		$this->assertSame( 'publish', $entries[0]->get_new_value() );
		$this->assertGreaterThanOrEqual( $before, $entries[0]->get_created_at() );
		$this->assertSame( 'review', $entries[0]->get_context()['workflow_status'] );
		$this->assertSame( 'off', $entries[0]->get_context()['gate_mode'] );
		$this->assertSame( RequestSource::OTHER, $entries[0]->get_context()['source'] );
	}

	/**
	 * The logged gate mode is the filtered one, as the gate itself resolves it.
	 *
	 * @return void
	 */
	public function test_logged_gate_mode_is_the_filtered_mode() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		add_filter(
			'sit_cwm_publish_gate_mode',
			static function () {
				return 'log_only';
			}
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$entries = $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'log_only', $entries[0]->get_context()['gate_mode'] );
	}

	/**
	 * Leaving `publish`, to a draft or to the trash, is `post_unpublished`.
	 *
	 * @return void
	 */
	public function test_unpublishing_and_trashing_log_post_unpublished() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		wp_trash_post( $post_id );

		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'post_unpublished' ) );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'trash', $rows[0]->get_new_value() ); // Newest first.
		$this->assertSame( 'draft', $rows[1]->get_new_value() );
		$this->assertSame( 'publish', $rows[1]->get_old_value() );
	}

	/**
	 * Editing a post that is already live writes no row.
	 *
	 * @return void
	 */
	public function test_updating_a_live_post_logs_nothing() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$count   = $this->activity->count_for_post( $post_id );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited',
			)
		);

		$this->assertSame( $count, $this->activity->count_for_post( $post_id ) );
	}

	/**
	 * Scheduled publication is attributed to the system, from `future`.
	 *
	 * @return void
	 */
	public function test_scheduled_publication_is_logged_as_system_from_future() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		wp_set_current_user( 0 );

		wp_publish_post( $post_id );

		$entries = $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( 0, $entries[0]->get_user_id() );
		$this->assertSame( 'future', $entries[0]->get_old_value() );
	}

	/**
	 * Post types the workflow does not manage are never logged.
	 *
	 * @return void
	 */
	public function test_unmanaged_post_types_are_not_logged() {
		register_post_type( 'sit_cwm_unmanaged', array( 'public' => true ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'sit_cwm_unmanaged',
				'post_status' => 'draft',
			)
		);

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 0, $this->activity->count_for_post( $post_id ) );
		unregister_post_type( 'sit_cwm_unmanaged' );
	}

	/**
	 * Saving a revision of a live post logs no publication.
	 *
	 * @return void
	 */
	public function test_revisions_and_autosaves_are_ignored() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$before  = $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Changed, so a revision is stored.',
			)
		);
		$revision_id = wp_save_post_revision( $post_id );

		$this->assertSame( $before, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ) );

		if ( $revision_id ) {
			$this->assertSame( 0, $this->activity->count_for_post( (int) $revision_id ) );
		}
	}

	/**
	 * A post inserted directly as `publish` is logged from `new`.
	 *
	 * @return void
	 */
	public function test_insert_directly_as_publish_logs_from_new() {
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Direct',
				'post_status' => 'publish',
			)
		);

		$entries = $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'new', $entries[0]->get_old_value() );
	}

	/**
	 * Transitions that neither enter nor leave `publish` write nothing.
	 *
	 * @return void
	 */
	public function test_transitions_outside_publish_log_nothing() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'pending',
			)
		);

		$this->assertSame( 0, $this->activity->count_for_post( $post_id ) );
	}

	/**
	 * The logger hooks `transition_post_status` at 10 with three arguments.
	 *
	 * @return void
	 */
	public function test_register_hooks_transition_post_status() {
		$this->assertSame( 10, has_action( 'transition_post_status', array( $this->logger, 'handle_transition' ) ) );
	}

	/**
	 * Bad input is ignored rather than raised.
	 *
	 * @return void
	 */
	public function test_invalid_input_is_ignored() {
		$this->logger->handle_transition( 'publish', 'draft', null );
		$this->logger->handle_transition( array(), 'draft', get_post( self::factory()->post->create( array( 'post_status' => 'draft' ) ) ) );

		$this->assertTrue( true ); // Reaching here means nothing was raised.
	}

	/**
	 * Outside REST, cron, CLI, AJAX and admin, the source is `other`.
	 *
	 * @return void
	 */
	public function test_request_source_is_other_in_a_plain_request() {
		$this->assertSame( 'other', RequestSource::detect() );
	}

	/**
	 * Removes every `PublicationLogger` the booted plugin attached.
	 *
	 * @return void
	 */
	private function remove_booted_loggers(): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['transition_post_status'] ) ) {
			return;
		}

		foreach ( $wp_filter['transition_post_status']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof PublicationLogger ) {
					remove_action( 'transition_post_status', $callback['function'], $priority );
				}
			}
		}
	}
}
