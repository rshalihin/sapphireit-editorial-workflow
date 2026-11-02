<?php
/**
 * Integration tests for the seam probe add-on (F7).
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A real add-on, `tests/fixtures/sit-cwm-probe`, reshapes the workflow
 * through the 1.2 seams alone. Every assertion goes through the booted
 * container's services, exactly as an add-on sees Free.
 *
 * @since 1.2.0
 *
 * @coversNothing
 */
final class ProbeAddonTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Loads the probe plugin file.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once dirname( __DIR__, 2 ) . '/fixtures/sit-cwm-probe/sit-cwm-probe.php';
	}

	/**
	 * Activates the probe and lets it see `sit_cwm_loaded`.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		sit_cwm_probe_register();
		sit_cwm_probe_activate();
		sit_cwm_probe_on_loaded( Plugin::instance() );
		$this->flush_registries();
		delete_option( SIT_CWM_PROBE_LOG_OPTION );

		// On a real site the add-on is loaded before `rest_api_init`, so the
		// route enums include its status; rebuild them the same way.
		$this->reset_rest_server();
	}

	/**
	 * Removes the probe's hooks and capability.
	 *
	 * @return void
	 */
	public function tear_down() {
		sit_cwm_probe_unregister();

		foreach ( array( 'editor', 'administrator' ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null !== $role ) {
				$role->remove_cap( SIT_CWM_PROBE_CAP );
			}
		}

		$this->flush_registries();

		parent::tear_down();
	}

	/**
	 * Behaviour 1: `legal_review` exists site-wide and per post.
	 *
	 * @return void
	 */
	public function test_1_legal_review_status_is_registered_everywhere() {
		$statuses = sit_cwm_container()->get( 'status_manager' );
		$post_id  = $this->create_managed_post();

		$this->assertSame( 'Legal Review', $statuses->label( 'legal_review' ) );
		$this->assertSame( 'Legal Review', $statuses->label( 'legal_review', $post_id ) );
		$this->assertSame( '#8a2be2', $statuses->get( 'legal_review' )['color'] );
		$this->assertSame( array( 'draft', 'writing', 'review', 'legal_review', 'needs_changes', 'approved', 'published' ), $statuses->slugs() );
	}

	/**
	 * Behaviour 2: review leads through legal review, never straight to approved.
	 *
	 * @return void
	 */
	public function test_2_graph_is_rewired() {
		$transitions = sit_cwm_container()->get( 'transition_manager' );

		$this->assertSame( array( 'legal_review', 'needs_changes' ), $transitions->targets_for( 'review' ) );
		$this->assertSame( array( 'approved', 'needs_changes' ), $transitions->targets_for( 'legal_review' ) );
		$this->assertFalse( $transitions->is_valid( 'review', 'approved' ) );
	}

	/**
	 * Behaviour 3: reaching `legal_review` takes the probe's capability.
	 *
	 * @return void
	 */
	public function test_3_new_capability_is_required_for_legal_review() {
		$workflow = sit_cwm_container()->get( 'workflow_manager' );
		$post_id  = $this->create_managed_post();
		update_post_meta( $post_id, PostRepository::META_STATUS, 'review' );
		$reviewer = $this->create_user_with_caps( array( 'sit_cwm_review_content', 'edit_others_posts', 'edit_posts' ) );

		$this->assertSame( 'sit_cwm_forbidden', $workflow->transition( $post_id, 'review', 'legal_review', $reviewer )->get_error_code() );
		$this->assertTrue( $workflow->transition( $post_id, 'review', 'legal_review', self::fixture_user( 'editor' ) ) );
	}

	/**
	 * Behaviour 4: the approval veto reaches REST verbatim, in the workflow
	 * state and as the error body, and lifts once the checklist is done.
	 *
	 * @return void
	 */
	public function test_4_approval_veto_reaches_rest_verbatim_and_lifts_when_checklist_done() {
		$post_id = $this->create_managed_post();
		update_post_meta( $post_id, PostRepository::META_STATUS, 'legal_review' );
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$get = $this->rest_request( 'GET', "/sit-cwm/v1/posts/{$post_id}/workflow" )->get_data();
		$this->assertSame( 'The legal checklist is not complete.', $get['blocked_transitions'][0]['message'] );

		$post = $this->rest_request(
			'POST',
			"/sit-cwm/v1/posts/{$post_id}/workflow",
			array(
				'from'   => 'legal_review',
				'status' => 'approved',
			)
		);
		$this->assert_error_response( $post, 'sit_cwm_probe_checklist', 422 );
		$this->assertSame( 'The legal checklist is not complete.', $post->get_data()['message'] );

		update_post_meta( $post_id, SIT_CWM_PROBE_CHECKLIST_META, '1' );

		$approved = $this->rest_request(
			'POST',
			"/sit-cwm/v1/posts/{$post_id}/workflow",
			array(
				'from'   => 'legal_review',
				'status' => 'approved',
			)
		);
		$this->assertSame( 200, $approved->get_status() );
	}

	/**
	 * Behaviour 5: the probe hears every status change.
	 *
	 * @return void
	 */
	public function test_5_every_status_change_is_logged_by_the_probe() {
		$workflow = sit_cwm_container()->get( 'workflow_manager' );
		$post_id  = $this->create_managed_post();

		$workflow->transition( $post_id, 'draft', 'writing', self::fixture_user( 'editor' ) );
		$workflow->transition( $post_id, 'writing', 'review', self::fixture_user( 'editor' ) );

		$this->assertSame( array( "{$post_id}:draft>writing", "{$post_id}:writing>review" ), get_option( SIT_CWM_PROBE_LOG_OPTION ) );
	}

	/**
	 * Behaviour 6: the probe's saved view narrows the dashboard collection.
	 *
	 * @return void
	 */
	public function test_6_dashboard_view_is_extended() {
		$open = $this->create_managed_post();
		$done = $this->create_managed_post();
		update_post_meta( $done, SIT_CWM_PROBE_CHECKLIST_META, '1' );
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$ids = wp_list_pluck( $this->rest_request( 'GET', '/sit-cwm/v1/posts', null, array( 'view' => SIT_CWM_PROBE_VIEW ) )->get_data(), 'post_id' );

		$this->assertContains( $open, $ids );
		$this->assertNotContains( $done, $ids );
	}

	/**
	 * Behaviour 7: the probe drives Free's own WorkflowManager from the
	 * container, so Free logs the move.
	 *
	 * @return void
	 */
	public function test_7_probe_uses_frees_own_workflow_manager_from_the_container() {
		$post_id = $this->create_managed_post();
		update_post_meta( $post_id, PostRepository::META_STATUS, 'review' );
		$rows = $this->activity->count_for_post( $post_id ); // The factory post's own publication row.

		$this->assertTrue( sit_cwm_probe_send_to_legal( $post_id, self::fixture_user( 'editor' ) ) );
		$this->assertSame( 'legal_review', sit_cwm_container()->get( 'post_repository' )->get_status( $post_id ) );
		$this->assertSame( $rows + 1, $this->activity->count_for_post( $post_id ) ); // Logged by Free, not by a parallel stack.
	}

	/**
	 * The injected status reaches the statuses route and the bootstrap data.
	 *
	 * @return void
	 */
	public function test_injected_status_appears_in_bootstrap_and_statuses_route() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$slugs = wp_list_pluck( $this->rest_request( 'GET', '/sit-cwm/v1/statuses' )->get_data()['statuses'], 'slug' );

		$this->assertContains( 'legal_review', $slugs );
		$this->assertContains( 'legal_review', wp_list_pluck( sit_cwm_container()->get( 'assets' )->bootstrap_data()['statuses'], 'slug' ) );
	}

	/**
	 * Drops the container's memoised status and transition registries.
	 *
	 * @return void
	 */
	private function flush_registries(): void {
		sit_cwm_container()->get( 'status_manager' )->flush();
		sit_cwm_container()->get( 'transition_manager' )->flush();
	}
}
