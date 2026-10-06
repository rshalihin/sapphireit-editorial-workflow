<?php
/**
 * Integration tests for capabilities and the permission manager.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Activator;
use Sit_Cwm\Core\Deactivator;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role grants and per-user, per-post authorization with real roles.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Workflow\Capabilities
 * @covers \Sit_Cwm\Workflow\PermissionManager
 */
final class PermissionManagerTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Grants the default capabilities and builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		$settings          = new Settings();
		$this->permissions = new PermissionManager( new PostRepository( new StatusManager(), $settings ), $settings );
	}

	/**
	 * Author can reach `writing`/`review` on their own post, not reviewer
	 * statuses.
	 *
	 * @return void
	 */
	public function test_author_can_write_and_submit_but_not_review() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		$this->assertTrue( $this->permissions->can_change_status( $post, 'writing', $author ) );
		$this->assertTrue( $this->permissions->can_change_status( $post, 'review', $author ) );
		$this->assertFalse( $this->permissions->can_change_status( $post, 'approved', $author ) );
		$this->assertFalse( $this->permissions->can_change_status( $post, 'needs_changes', $author ) );
		$this->assertFalse( $this->permissions->can_change_status( $post, 'published', $author ) );

		$this->assertTrue( $this->permissions->can_edit_post( $post, $author ) );
		$this->assertTrue( $this->permissions->can_view_activity( $post, $author ) );
		$this->assertTrue( $this->permissions->can_comment( $post, $author ) );
		$this->assertFalse( $this->permissions->can_assign_reviewer( $post, $author ) );
		$this->assertFalse( $this->permissions->can_set_due_date( $post, $author ) );
		$this->assertFalse( $this->permissions->can_manage( $author ) );
	}

	/**
	 * Editor can approve and send back someone else's post.
	 *
	 * @return void
	 */
	public function test_editor_can_review_and_approve() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $this->make_user( 'author' ) );

		$this->assertTrue( $this->permissions->can_change_status( $post, 'approved', $editor ) );
		$this->assertTrue( $this->permissions->can_change_status( $post, 'needs_changes', $editor ) );
		$this->assertTrue( $this->permissions->can_change_status( $post, 'published', $editor ) );
		$this->assertTrue( $this->permissions->can_assign_reviewer( $post, $editor ) );
		$this->assertTrue( $this->permissions->can_set_due_date( $post, $editor ) );
		$this->assertFalse( $this->permissions->can_manage( $editor ) );
	}

	/**
	 * Administrator holds every capability.
	 *
	 * @return void
	 */
	public function test_administrator_can_everything() {
		$admin = $this->make_user( 'administrator' );
		$post  = $this->make_post( $this->make_user( 'author' ) );

		$this->assertTrue( $this->permissions->can_manage( $admin ) );

		foreach ( ( new StatusManager() )->slugs() as $status ) {
			$this->assertTrue( $this->permissions->can_change_status( $post, $status, $admin ), $status );
		}

		foreach ( $this->post_checks( $post, $admin ) as $name => $result ) {
			$this->assertTrue( $result, $name );
		}
	}

	/**
	 * A directly granted approve capability does not help without `edit_post`
	 * on the specific post.
	 *
	 * @return void
	 */
	public function test_author_cannot_approve_others_post_even_with_capability() {
		$author = $this->make_user( 'author' );
		get_userdata( $author )->add_cap( Capabilities::APPROVE_CONTENT );

		$own    = $this->make_post( $author );
		$others = $this->make_post( $this->make_user( 'author' ) );

		$this->assertTrue( $this->permissions->can_change_status( $own, 'approved', $author ) );
		$this->assertFalse( $this->permissions->can_change_status( $others, 'approved', $author ) );
	}

	/**
	 * `published` also requires `publish_post` on the post.
	 *
	 * @return void
	 */
	public function test_published_requires_publish_post() {
		$contributor = $this->make_user( 'contributor' );
		get_userdata( $contributor )->add_cap( Capabilities::APPROVE_CONTENT );

		$post = $this->make_post( $contributor );

		$this->assertTrue( $this->permissions->can_change_status( $post, 'approved', $contributor ) );
		$this->assertFalse( $this->permissions->can_change_status( $post, 'published', $contributor ) );
	}

	/**
	 * Subscriber: every method false.
	 *
	 * @return void
	 */
	public function test_subscriber_is_denied_everything() {
		$subscriber = $this->make_user( 'subscriber' );
		$post       = $this->make_post( $this->make_user( 'author' ) );

		$this->assert_denied_everything( $post, $subscriber );
		$this->assertFalse( $this->permissions->can_manage( $subscriber ) );
	}

	/**
	 * Logged-out and unknown users: every method false, explicit or implicit.
	 *
	 * @return void
	 */
	public function test_logged_out_and_unknown_users_are_denied_everything() {
		$post = $this->make_post( $this->make_user( 'author' ) );

		wp_set_current_user( 0 );

		$this->assert_denied_everything( $post, 0 );
		$this->assert_denied_everything( $post, null );
		$this->assert_denied_everything( $post, 999999 );
		$this->assertFalse( $this->permissions->can_manage( 0 ) );
		$this->assertFalse( $this->permissions->can_manage() );
	}

	/**
	 * `null` resolves to the current user.
	 *
	 * @return void
	 */
	public function test_null_user_is_current_user() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $this->make_user( 'author' ) );

		wp_set_current_user( $editor );

		$this->assertTrue( $this->permissions->can_change_status( $post, 'approved' ) );
		$this->assertTrue( $this->permissions->can_assign_reviewer( $post ) );
	}

	/**
	 * Non-enabled post types and missing posts: every method false, even for
	 * an administrator.
	 *
	 * @return void
	 */
	public function test_unmanaged_or_missing_post_is_denied_even_for_admin() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );

		$admin = $this->make_user( 'administrator' );
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $admin,
				'post_status' => 'draft',
			)
		);

		$this->assert_denied_everything( $page, $admin );
		$this->assert_denied_everything( 999999, $admin );
		$this->assert_denied_everything( 0, $admin );
	}

	/**
	 * Capability flags list every plugin capability, true only where held.
	 *
	 * @return void
	 */
	public function test_capability_flags() {
		$editor = $this->make_user( 'editor' );
		$flags  = $this->permissions->capability_flags( $editor );

		$this->assertSame( Capabilities::all(), array_keys( $flags ) );
		$this->assertTrue( $flags[ Capabilities::APPROVE_CONTENT ] );
		$this->assertFalse( $flags[ Capabilities::MANAGE_WORKFLOWS ] );

		wp_set_current_user( $editor );
		$this->assertSame( $flags, $this->permissions->capability_flags() );

		$none = array_fill_keys( Capabilities::all(), false );

		$this->assertSame( $none, $this->permissions->capability_flags( $this->make_user( 'subscriber' ) ) );
		$this->assertSame( $none, $this->permissions->capability_flags( 0 ) );
		$this->assertSame( $none, $this->permissions->capability_flags( 999999 ) );
	}

	/**
	 * Capability map follows D5, is filterable, and unknown statuses fall back
	 * to the manage capability.
	 *
	 * @return void
	 */
	public function test_capability_for_status() {
		$this->assertSame( Capabilities::CHANGE_WORKFLOW, $this->permissions->capability_for_status( 'draft' ) );
		$this->assertSame( Capabilities::CHANGE_WORKFLOW, $this->permissions->capability_for_status( 'writing' ) );
		$this->assertSame( Capabilities::CHANGE_WORKFLOW, $this->permissions->capability_for_status( 'review' ) );
		$this->assertSame( Capabilities::REVIEW_CONTENT, $this->permissions->capability_for_status( 'needs_changes' ) );
		$this->assertSame( Capabilities::APPROVE_CONTENT, $this->permissions->capability_for_status( 'approved' ) );
		$this->assertSame( Capabilities::APPROVE_CONTENT, $this->permissions->capability_for_status( 'published' ) );
		$this->assertSame( Capabilities::MANAGE_WORKFLOWS, $this->permissions->capability_for_status( 'legal_review' ) );

		add_filter(
			'sit_cwm_status_capability_map',
			static function ( $map ) {
				$map['legal_review'] = 'sit_cwm_probe_legal';
				$map['review']       = '';
				return $map;
			}
		);

		$this->assertSame( 'sit_cwm_probe_legal', $this->permissions->capability_for_status( 'legal_review' ) );
		$this->assertSame( Capabilities::MANAGE_WORKFLOWS, $this->permissions->capability_for_status( 'review' ) );

		add_filter( 'sit_cwm_status_capability_map', '__return_null', 20 );

		$this->assertSame( Capabilities::CHANGE_WORKFLOW, $this->permissions->capability_for_status( 'review' ) );
	}

	/**
	 * An unknown target status is denied to an editor but allowed to an admin.
	 *
	 * @return void
	 */
	public function test_unknown_status_is_admin_only() {
		$post = $this->make_post( $this->make_user( 'author' ) );

		$this->assertFalse( $this->permissions->can_change_status( $post, 'legal_review', $this->make_user( 'editor' ) ) );
		$this->assertTrue( $this->permissions->can_change_status( $post, 'legal_review', $this->make_user( 'administrator' ) ) );
	}

	/**
	 * The transition filter receives full context and can veto.
	 *
	 * @return void
	 */
	public function test_can_transition_filter_can_veto() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $this->make_user( 'author' ) );
		update_post_meta( $post, '_sit_cwm_status', 'review' );

		$calls = array();
		add_filter(
			'sit_cwm_can_transition',
			static function ( $allowed, $post_id, $from, $to, $user_id ) use ( &$calls ) {
				$calls[] = array( $allowed, $post_id, $from, $to, $user_id );
				return false;
			},
			10,
			5
		);

		$this->assertFalse( $this->permissions->can_change_status( $post, 'approved', $editor ) );
		$this->assertSame( array( array( true, $post, 'review', 'approved', $editor ) ), $calls );
	}

	/**
	 * The filter is applied last, so it can deliberately widen a capability
	 * denial — but it never runs past the hard gates (user, managed post).
	 *
	 * @return void
	 */
	public function test_can_transition_filter_widens_only_past_hard_gates() {
		$subscriber = $this->make_user( 'subscriber' );
		$post       = $this->make_post( $this->make_user( 'author' ) );

		$received = null;
		add_filter(
			'sit_cwm_can_transition',
			static function ( $allowed ) use ( &$received ) {
				$received = $allowed;
				return true;
			}
		);

		$this->assertTrue( $this->permissions->can_change_status( $post, 'approved', $subscriber ) );
		$this->assertFalse( $received );

		// Hard gates: the filter cannot be the only gate.
		$received = null;
		$this->assertFalse( $this->permissions->can_change_status( $post, 'approved', 0 ) );

		update_option( Settings::OPTION, array( 'post_types' => array( 'page' ) ) );
		$this->assertFalse( $this->permissions->can_change_status( $post, 'approved', $subscriber ) );
		$this->assertNull( $received );
	}

	/**
	 * A truthy but non-`true` filter return denies.
	 *
	 * @return void
	 */
	public function test_can_transition_filter_requires_strict_true() {
		$subscriber = $this->make_user( 'subscriber' );
		$post       = $this->make_post( $this->make_user( 'author' ) );

		add_filter(
			'sit_cwm_can_transition',
			static function () {
				return 1;
			}
		);

		$this->assertFalse( $this->permissions->can_change_status( $post, 'writing', $subscriber ) );
	}

	/**
	 * Activation grants exactly the D5 table; deactivate → reactivate keeps it
	 * unchanged, without duplicates.
	 *
	 * @return void
	 */
	public function test_grants_survive_reactivation_without_duplication() {
		Capabilities::remove_caps();

		Activator::activate();
		$first = $this->plugin_caps_by_role();

		Deactivator::deactivate();
		$this->assertSame( $first, $this->plugin_caps_by_role() );

		Activator::activate();
		$this->assertSame( $first, $this->plugin_caps_by_role() );

		$expected               = Capabilities::role_map();
		$expected['subscriber'] = array();

		foreach ( $expected as $role => $caps ) {
			sort( $caps );
			$this->assertSame( $caps, $first[ $role ], $role );
		}
	}

	/**
	 * `remove_caps()` strips plugin capabilities from every role, including
	 * custom ones, and leaves core capabilities alone.
	 *
	 * @return void
	 */
	public function test_remove_caps_cleans_every_role() {
		add_role( 'sit_cwm_probe_role', 'Probe', array( Capabilities::VIEW_ACTIVITY => true ) );

		Capabilities::remove_caps();

		foreach ( $this->plugin_caps_by_role() as $role => $caps ) {
			$this->assertSame( array(), $caps, $role );
		}

		$this->assertTrue( get_role( 'editor' )->has_cap( 'edit_others_posts' ) );

		remove_role( 'sit_cwm_probe_role' );
	}

	/**
	 * The status registry is shown to users who edit content or manage the
	 * plugin, never to subscribers or logged-out visitors.
	 *
	 * @return void
	 */
	public function test_view_statuses_needs_edit_posts_or_manage() {
		$this->assertTrue( $this->permissions->can_view_statuses( $this->make_user( 'administrator' ) ) );
		$this->assertTrue( $this->permissions->can_view_statuses( $this->make_user( 'contributor' ) ) );
		$this->assertFalse( $this->permissions->can_view_statuses( $this->make_user( 'subscriber' ) ) );
		$this->assertFalse( $this->permissions->can_view_statuses( 0 ) );
		$this->assertFalse( $this->permissions->can_view_statuses( PHP_INT_MAX ) );

		$manager = $this->make_user( 'subscriber' );
		get_userdata( $manager )->add_cap( Capabilities::MANAGE_WORKFLOWS );

		$this->assertTrue( $this->permissions->can_view_statuses( $manager ) );
	}

	/**
	 * The dashboard lists only enabled types the user can edit; enabled types
	 * that are not registered are skipped.
	 *
	 * @return void
	 */
	public function test_dashboard_post_types_follow_edit_capabilities() {
		$author = $this->make_user( 'author' );
		$editor = $this->make_user( 'editor' );

		$this->assertSame( array( 'post' ), $this->permissions->dashboard_post_types( $author ) );
		$this->assertSame( array( 'post', 'page' ), $this->permissions->dashboard_post_types( $editor ) );
		$this->assertSame( array(), $this->permissions->dashboard_post_types( $this->make_user( 'subscriber' ) ) );
		$this->assertSame( array(), $this->permissions->dashboard_post_types( 0 ) );
		$this->assertTrue( $this->permissions->can_view_dashboard( $author ) );
		$this->assertFalse( $this->permissions->can_view_dashboard( 0 ) );

		$add_unregistered = static function ( $types ) {
			$types[] = 'sit_cwm_unregistered';
			return $types;
		};
		add_filter( 'sit_cwm_enabled_post_types', $add_unregistered );

		$this->assertSame( array( 'post', 'page' ), $this->permissions->dashboard_post_types( $editor ) );

		remove_filter( 'sit_cwm_enabled_post_types', $add_unregistered );
	}

	/**
	 * Seeing every post needs `edit_others_*` on every requested type, so a
	 * mixed query is as strict as its most restricted type.
	 *
	 * @return void
	 */
	public function test_see_all_posts_needs_edit_others_on_every_type() {
		$editor = $this->make_user( 'editor' );
		$author = $this->make_user( 'author' );

		$this->assertTrue( $this->permissions->can_see_all_posts( array( 'post', 'page' ), $editor ) );
		$this->assertFalse( $this->permissions->can_see_all_posts( array( 'post' ), $author ) );
		$this->assertFalse( $this->permissions->can_see_all_posts( array(), $editor ), 'An empty list grants nothing.' );
		$this->assertFalse( $this->permissions->can_see_all_posts( array( 'post', 'sit_cwm_unregistered' ), $editor ) );
		$this->assertFalse( $this->permissions->can_see_all_posts( array( 42 ), $editor ), 'Non-string types are refused.' );
		$this->assertFalse( $this->permissions->can_see_all_posts( array( 'post' ), 0 ) );

		// An editor who lost `edit_others_pages` is scoped on a mixed query.
		get_userdata( $editor )->add_cap( 'edit_others_pages', false );

		$this->assertTrue( $this->permissions->can_see_all_posts( array( 'post' ), $editor ) );
		$this->assertFalse( $this->permissions->can_see_all_posts( array( 'post', 'page' ), $editor ) );
	}

	/**
	 * Only reviewer assigners and plugin managers may enumerate reviewers;
	 * for one post, the post's own assignment check decides.
	 *
	 * @return void
	 */
	public function test_list_reviewers_is_limited_to_assigners() {
		$editor = $this->make_user( 'editor' );
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		$this->assertTrue( $this->permissions->can_list_reviewers( 0, $editor ) );
		$this->assertTrue( $this->permissions->can_list_reviewers( 0, $this->make_user( 'administrator' ) ) );
		$this->assertFalse( $this->permissions->can_list_reviewers( 0, $author ) );
		$this->assertFalse( $this->permissions->can_list_reviewers( 0, $this->make_user( 'subscriber' ) ) );
		$this->assertFalse( $this->permissions->can_list_reviewers( 0, 0 ) );

		$this->assertTrue( $this->permissions->can_list_reviewers( $post, $editor ) );
		$this->assertFalse( $this->permissions->can_list_reviewers( $post, $author ) );

		$manager = $this->make_user( 'subscriber' );
		get_userdata( $manager )->add_cap( Capabilities::MANAGE_WORKFLOWS );

		$this->assertTrue( $this->permissions->can_list_reviewers( 0, $manager ) );
	}

	/**
	 * Reviewers for a post must also be able to open it, which depends on
	 * its type and post status.
	 *
	 * @return void
	 */
	public function test_reviewer_capabilities_match_what_edit_post_needs() {
		$author = $this->make_user( 'author' );

		$this->assertSame( array( Capabilities::REVIEW_CONTENT ), $this->permissions->reviewer_capabilities( 0 ) );
		$this->assertSame( array( Capabilities::REVIEW_CONTENT ), $this->permissions->reviewer_capabilities( PHP_INT_MAX ) );

		$this->assertSame(
			array( Capabilities::REVIEW_CONTENT, 'edit_others_posts' ),
			$this->permissions->reviewer_capabilities( $this->make_post( $author ) )
		);

		$published = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		$this->assertSame(
			array( Capabilities::REVIEW_CONTENT, 'edit_others_posts', 'edit_published_posts' ),
			$this->permissions->reviewer_capabilities( $published )
		);

		$private_page = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'private',
				'post_type'   => 'page',
			)
		);
		$this->assertSame(
			array( Capabilities::REVIEW_CONTENT, 'edit_others_pages', 'edit_private_pages' ),
			$this->permissions->reviewer_capabilities( $private_page )
		);

		// A post type outside the workflow adds nothing.
		( new Settings() )->update( array( 'post_types' => array( 'page' ) ) );

		$this->assertSame( array( Capabilities::REVIEW_CONTENT ), $this->permissions->reviewer_capabilities( $published ) );
	}

	/**
	 * The read gate behind every 404: logged out, missing, unmanaged or
	 * unreadable posts are all refused.
	 *
	 * @return void
	 */
	public function test_read_post_gate() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		$this->assertTrue( $this->permissions->can_read_post( $post, $author ) );
		$this->assertTrue( $this->permissions->can_read_post( $post, $this->make_user( 'editor' ) ) );
		$this->assertFalse( $this->permissions->can_read_post( $post, $this->make_user( 'subscriber' ) ), 'Drafts are private to editors.' );
		$this->assertFalse( $this->permissions->can_read_post( $post, 0 ) );
		$this->assertFalse( $this->permissions->can_read_post( PHP_INT_MAX, $author ) );
		$this->assertFalse( $this->permissions->can_read_post( 0, $author ) );

		( new Settings() )->update( array( 'post_types' => array( 'page' ) ) );

		$this->assertFalse( $this->permissions->can_read_post( $post, $author ), 'Unmanaged post types are not workflow content.' );
	}

	/**
	 * Asserts every post-scoped method denies.
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id.
	 * @return void
	 */
	private function assert_denied_everything( int $post_id, ?int $user_id ) {
		foreach ( ( new StatusManager() )->slugs() as $status ) {
			$this->assertFalse( $this->permissions->can_change_status( $post_id, $status, $user_id ), $status );
		}

		foreach ( $this->post_checks( $post_id, $user_id ) as $name => $result ) {
			$this->assertFalse( $result, $name );
		}
	}

	/**
	 * Results of every post-scoped boolean method except status changes.
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id.
	 * @return array<string, bool>
	 */
	private function post_checks( int $post_id, ?int $user_id ): array {
		return array(
			'can_view_activity'   => $this->permissions->can_view_activity( $post_id, $user_id ),
			'can_edit_post'       => $this->permissions->can_edit_post( $post_id, $user_id ),
			'can_assign_reviewer' => $this->permissions->can_assign_reviewer( $post_id, $user_id ),
			'can_set_due_date'    => $this->permissions->can_set_due_date( $post_id, $user_id ),
			'can_comment'         => $this->permissions->can_comment( $post_id, $user_id ),
		);
	}

	/**
	 * Sorted plugin capabilities granted to each role, read from storage.
	 *
	 * @return array<string, string[]>
	 */
	private function plugin_caps_by_role(): array {
		$result = array();

		foreach ( get_option( wp_roles()->role_key ) as $role => $data ) {
			$caps = array_values( array_intersect( array_keys( array_filter( $data['capabilities'] ) ), Capabilities::all() ) );
			sort( $caps );
			$result[ $role ] = $caps;
		}

		return $result;
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
	 * Creates a draft post by an author.
	 *
	 * @param int $author_id Author user id.
	 * @return int Post id.
	 */
	private function make_post( int $author_id ): int {
		return self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);
	}
}
