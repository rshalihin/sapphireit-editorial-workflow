<?php
/**
 * Integration tests for the notification recipient resolver.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Notifications;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Notifications\RecipientResolver;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each event resolves to the E12 recipients, and the E13 safety rules hold
 * whatever `sit_cwm_notification_recipients` adds.
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Notifications\RecipientResolver
 */
final class RecipientResolverTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var RecipientResolver
	 */
	private $resolver;

	/**
	 * Builds the resolver.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->resolver = new RecipientResolver( $this->posts, new PermissionManager( $this->posts, new Settings() ) );
	}

	/**
	 * A draft post authored by `author` with `reviewer` assigned.
	 *
	 * @return int Post id.
	 */
	private function fixture_post(): int {
		return $this->create_managed_post(
			array(
				'post_author' => self::fixture_user( 'author' ),
				'post_status' => 'draft',
				'reviewer_id' => self::fixture_user( 'reviewer' ),
			)
		);
	}

	/**
	 * E12 routing for every event.
	 *
	 * @dataProvider data_routing
	 *
	 * @param string   $event         Event key.
	 * @param string   $actor_key     Fixture key of the acting user.
	 * @param string[] $expected_keys Fixture keys of the expected recipients, in order.
	 * @return void
	 */
	public function test_routing( string $event, string $actor_key, array $expected_keys ) {
		$post_id  = $this->fixture_post();
		$expected = array_map( array( self::class, 'fixture_user' ), $expected_keys );

		$this->assertSame( $expected, $this->resolver->resolve( $event, $post_id, self::fixture_user( $actor_key ), self::fixture_user( 'reviewer' ) ) );
	}

	/**
	 * Routing cases.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string[]}>
	 */
	public function data_routing() {
		return array(
			'assigned → reviewer'           => array( 'reviewer_assigned', 'editor', array( 'reviewer' ) ),
			'review requested → reviewer'   => array( 'review_requested', 'author', array( 'reviewer' ) ),
			'changes requested → author'    => array( 'changes_requested', 'reviewer', array( 'author' ) ),
			'approved → author'             => array( 'approved', 'editor', array( 'author' ) ),
			'comment by reviewer → author'  => array( 'comment_added', 'reviewer', array( 'author' ) ),
			'comment by author → reviewer'  => array( 'comment_added', 'author', array( 'reviewer' ) ),
			'comment by third party → both' => array( 'comment_added', 'editor', array( 'author', 'reviewer' ) ),
			'self-assignment → nobody'      => array( 'reviewer_assigned', 'reviewer', array() ),
			'author approves own → nobody'  => array( 'approved', 'author', array() ),
		);
	}

	/**
	 * E12: review requests with no reviewer assigned go to nobody.
	 *
	 * @return void
	 */
	public function test_review_requested_without_reviewer_goes_to_nobody() {
		$post_id = $this->fixture_post();
		$this->posts->set_reviewer_id( $post_id, 0 );

		$this->assertSame( array(), $this->resolver->resolve( 'review_requested', $post_id, self::fixture_user( 'author' ) ) );
	}

	/**
	 * Review Focus 5: the filter cannot add the actor, nonexistent users,
	 * users without an email address, or users who cannot read the post.
	 *
	 * @return void
	 */
	public function test_recipients_exclude_actor_missing_unreadable_and_emailless_users() {
		global $wpdb;

		$post_id = $this->fixture_post();
		$no_mail = self::factory()->user->create( array( 'role' => 'editor' ) );
		$wpdb->update( $wpdb->users, array( 'user_email' => '' ), array( 'ID' => $no_mail ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Core's user API refuses an empty email.
		clean_user_cache( $no_mail );
		$outsider = self::fixture_user( 'subscriber' ); // Cannot read a draft.
		$editor   = self::fixture_user( 'editor' );

		add_filter(
			'sit_cwm_notification_recipients',
			static function ( $ids ) use ( $no_mail, $outsider, $editor ) {
				return array_merge( $ids, array( $no_mail, $outsider, 999999, 'x', -3, $editor ) );
			}
		);

		// Actor = editor, so the editor must be removed even though the filter re-added them.
		$this->assertSame( array( self::fixture_user( 'author' ) ), $this->resolver->resolve( 'approved', $post_id, $editor ) );
	}

	/**
	 * A non-array filter result falls back to the unfiltered list.
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_is_ignored() {
		$post_id = $this->fixture_post();
		add_filter( 'sit_cwm_notification_recipients', '__return_false' );

		$this->assertSame( array( self::fixture_user( 'author' ) ), $this->resolver->resolve( 'approved', $post_id, self::fixture_user( 'editor' ) ) );
	}

	/**
	 * Numeric strings from the filter count as user ids.
	 *
	 * @return void
	 */
	public function test_numeric_string_ids_are_accepted() {
		$post_id = $this->fixture_post();
		$admin   = self::fixture_user( 'admin' );

		add_filter(
			'sit_cwm_notification_recipients',
			static function ( $ids ) use ( $admin ) {
				$ids[] = (string) $admin;
				return $ids;
			}
		);

		$this->assertSame( array( self::fixture_user( 'author' ), $admin ), $this->resolver->resolve( 'approved', $post_id, self::fixture_user( 'editor' ) ) );
	}

	/**
	 * Review Focus 5: the reviewer-cleared signal (`$reviewer_id` 0) notifies nobody.
	 *
	 * @return void
	 */
	public function test_reviewer_cleared_by_user_deletion_resolves_no_recipients() {
		$this->assertSame( array(), $this->resolver->resolve( 'reviewer_assigned', $this->fixture_post(), 0, 0 ) );
	}

	/**
	 * E13: exactly one mail per recipient per event.
	 *
	 * @return void
	 */
	public function test_duplicates_are_collapsed() {
		$post_id = $this->fixture_post();
		$this->posts->set_reviewer_id( $post_id, self::fixture_user( 'author' ) ); // Author reviews own post (allowed when they hold the cap).

		$this->assertSame( array( self::fixture_user( 'author' ) ), $this->resolver->resolve( 'comment_added', $post_id, self::fixture_user( 'editor' ) ) );
	}

	/**
	 * Unknown events and missing posts resolve to nobody.
	 *
	 * @return void
	 */
	public function test_unknown_event_or_missing_post_resolves_nobody() {
		$this->assertSame( array(), $this->resolver->resolve( 'slack', $this->fixture_post(), 0 ) );
		$this->assertSame( array(), $this->resolver->resolve( 'approved', 999999, 0 ) );
	}

	/**
	 * The filter receives the base list, the event, the post and the actor.
	 *
	 * @return void
	 */
	public function test_filter_receives_event_post_and_actor() {
		$post_id = $this->fixture_post();
		$actor   = self::fixture_user( 'editor' );
		$args    = null;

		add_filter(
			'sit_cwm_notification_recipients',
			static function ( $ids, $event, $filtered_post_id, $actor_id ) use ( &$args ) {
				$args = array( $ids, $event, $filtered_post_id, $actor_id );
				return $ids;
			},
			10,
			4
		);

		$this->resolver->resolve( 'approved', $post_id, $actor );

		$this->assertSame( array( array( self::fixture_user( 'author' ) ), 'approved', $post_id, $actor ), $args );
	}
}
