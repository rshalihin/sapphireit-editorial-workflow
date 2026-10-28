<?php
/**
 * Integration tests for notification delivery.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Notifications;

use RuntimeException;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Notifications\Deduplicator;
use Sit_Cwm\Notifications\MessageBuilder;
use Sit_Cwm\Notifications\NotificationEvents;
use Sit_Cwm\Notifications\Notifier;
use Sit_Cwm\Notifications\RecipientResolver;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use Sit_Cwm\Workflow\TransitionManager;
use Sit_Cwm\Workflow\WorkflowManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Real workflow actions fire the 1.0 hooks, and the notifier turns them into
 * exactly one mail per recipient for enabled events only (roadmap §3.1C).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Notifications\Notifier
 */
final class NotifierTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * The 1.0 actions the notifier listens to.
	 *
	 * @var string[]
	 */
	const ACTIONS = array( 'sit_cwm_status_changed', 'sit_cwm_reviewer_assigned', 'sit_cwm_comment_added' );

	/**
	 * Real workflow manager, so the 1.0 actions fire as in production.
	 *
	 * @var WorkflowManager
	 */
	private $workflow;

	/**
	 * Settings, for enabling events.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Builds the services and a notifier that is the only listener at 20.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		reset_phpmailer_instance();

		$this->settings = new Settings();
		$statuses       = new StatusManager();
		$permissions    = new PermissionManager( $this->posts, $this->settings );
		$this->workflow = new WorkflowManager( $statuses, new TransitionManager( $statuses ), $permissions, $this->posts, $this->activity );

		// The plugin booted at load time has its own notifier; this test owns priority 20.
		foreach ( self::ACTIONS as $action ) {
			remove_all_actions( $action, 20 );
		}

		$notifier = new Notifier(
			$this->settings,
			new RecipientResolver( $this->posts, $permissions ),
			new MessageBuilder( $permissions ),
			new Deduplicator()
		);
		$notifier->register();
	}

	/**
	 * Clears the captured mail.
	 *
	 * @return void
	 */
	public function tear_down() {
		reset_phpmailer_instance();

		parent::tear_down();
	}

	/**
	 * Sets notification toggles.
	 *
	 * @param array<string, bool> $toggles Event => on.
	 * @return void
	 */
	private function enable( array $toggles ): void {
		$this->assertTrue( $this->settings->update( array( 'notifications' => $toggles ) ) );
	}

	/**
	 * Mail captured so far.
	 *
	 * @return array[]
	 */
	private function sent(): array {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	/**
	 * "To" address of a captured mail.
	 *
	 * @param int $index Mail index.
	 * @return string
	 */
	private function to( int $index = 0 ): string {
		return tests_retrieve_phpmailer_instance()->get_recipient( 'to', $index )->address;
	}

	/**
	 * A post authored by `author` with `reviewer` assigned.
	 *
	 * @param string $status Workflow status.
	 * @return int Post id.
	 */
	private function fixture_post( string $status = 'review' ): int {
		return $this->create_managed_post(
			array(
				'post_author'     => self::fixture_user( 'author' ),
				'post_status'     => 'draft',
				'workflow_status' => $status,
				'reviewer_id'     => self::fixture_user( 'reviewer' ),
			)
		);
	}

	/**
	 * Moves a post in review to approved as the editor.
	 *
	 * @return int Post id.
	 */
	private function move_to_approved_as_editor(): int {
		$post_id = $this->fixture_post();
		$this->assertTrue( $this->workflow->transition( $post_id, 'review', 'approved', self::fixture_user( 'editor' ) ) );

		return $post_id;
	}

	/**
	 * Performs the workflow action behind an event as the fixture actor.
	 *
	 * @param string $event Event key.
	 * @return void
	 */
	private function perform( string $event ): void {
		switch ( $event ) {
			case NotificationEvents::REVIEWER_ASSIGNED:
				$post_id = $this->create_managed_post(
					array(
						'post_author'     => self::fixture_user( 'author' ),
						'workflow_status' => 'writing',
					)
				);
				$this->assertTrue( $this->workflow->assign_reviewer( $post_id, self::fixture_user( 'reviewer' ), self::fixture_user( 'editor' ) ) );
				break;
			case NotificationEvents::REVIEW_REQUESTED:
				$this->assertTrue( $this->workflow->transition( $this->fixture_post( 'writing' ), 'writing', 'review', self::fixture_user( 'author' ) ) );
				break;
			case NotificationEvents::CHANGES_REQUESTED:
				$this->assertTrue( $this->workflow->transition( $this->fixture_post(), 'review', 'needs_changes', self::fixture_user( 'editor' ) ) );
				break;
			case NotificationEvents::APPROVED:
				$this->move_to_approved_as_editor();
				break;
			case NotificationEvents::COMMENT_ADDED:
				$this->assertIsInt( $this->workflow->add_comment( $this->fixture_post(), 'Looks good.', self::fixture_user( 'reviewer' ) ) );
				break;
		}
	}

	/**
	 * Roadmap C: exactly one mail to the correct recipient.
	 *
	 * @dataProvider data_events
	 *
	 * @param string $event         Event key.
	 * @param string $recipient_key Fixture key of the expected recipient.
	 * @return void
	 */
	public function test_each_event_sends_exactly_one_mail_to_the_correct_recipient( string $event, string $recipient_key ) {
		$this->enable( array( $event => true ) );

		$this->perform( $event );

		$this->assertCount( 1, $this->sent() );
		$this->assertSame( get_userdata( self::fixture_user( $recipient_key ) )->user_email, $this->to() );
	}

	/**
	 * Data for test_each_event_sends_exactly_one_mail_to_the_correct_recipient().
	 *
	 * @return array<string, string[]>
	 */
	public function data_events(): array {
		return array(
			'reviewer_assigned' => array( NotificationEvents::REVIEWER_ASSIGNED, 'reviewer' ),
			'review_requested'  => array( NotificationEvents::REVIEW_REQUESTED, 'reviewer' ),
			'changes_requested' => array( NotificationEvents::CHANGES_REQUESTED, 'author' ),
			'approved'          => array( NotificationEvents::APPROVED, 'author' ),
			'comment_added'     => array( NotificationEvents::COMMENT_ADDED, 'author' ), // The reviewer comments, so only the author is left.
		);
	}

	/**
	 * Roadmap C: disabling an event stops its mail.
	 *
	 * @return void
	 */
	public function test_disabled_event_sends_nothing() {
		$this->enable(
			array(
				'approved'         => false,
				'review_requested' => true,
			)
		);

		$this->move_to_approved_as_editor();

		$this->assertCount( 0, $this->sent() );
	}

	/**
	 * Every event is off by default (E12).
	 *
	 * @return void
	 */
	public function test_default_settings_send_nothing_for_any_event() {
		foreach ( NotificationEvents::all() as $event ) {
			$this->perform( $event );
		}

		$this->assertCount( 0, $this->sent() );
	}

	/**
	 * Roadmap C: "a double save does not double send".
	 *
	 * @return void
	 */
	public function test_same_event_twice_within_window_sends_once() {
		$this->enable( array( 'approved' => true ) );
		$post_id = $this->fixture_post( 'approved' );

		do_action( 'sit_cwm_status_changed', $post_id, 'needs_changes', 'approved', self::fixture_user( 'editor' ) );
		do_action( 'sit_cwm_status_changed', $post_id, 'needs_changes', 'approved', self::fixture_user( 'editor' ) );

		$this->assertCount( 1, $this->sent() );
	}

	/**
	 * Different comments are different events, not duplicates.
	 *
	 * @return void
	 */
	public function test_two_different_comments_both_send() {
		$this->enable( array( 'comment_added' => true ) );
		$post_id = $this->fixture_post();

		$this->assertIsInt( $this->workflow->add_comment( $post_id, 'First.', self::fixture_user( 'reviewer' ) ) );
		$this->assertIsInt( $this->workflow->add_comment( $post_id, 'Second.', self::fixture_user( 'reviewer' ) ) );

		$this->assertCount( 2, $this->sent() );
	}

	/**
	 * Clearing the reviewer notifies nobody.
	 *
	 * @return void
	 */
	public function test_reviewer_cleared_sends_nothing() {
		$this->enable( array( 'reviewer_assigned' => true ) );
		$post_id = $this->fixture_post();

		$this->assertTrue( $this->workflow->assign_reviewer( $post_id, 0, self::fixture_user( 'editor' ) ) );

		$this->assertCount( 0, $this->sent() );
	}

	/**
	 * A failing `wp_mail()` is reported but leaves the transition alone.
	 *
	 * @return void
	 */
	public function test_wp_mail_failure_does_not_break_the_transition() {
		$this->enable( array( 'approved' => true ) );
		add_filter( 'pre_wp_mail', '__return_false' );

		$calls = array();
		add_action(
			'sit_cwm_notification_sent',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			4
		);

		$post_id = $this->move_to_approved_as_editor();

		$this->assert_status( $post_id, 'approved' );
		$this->assertSame( array( array( 'approved', $post_id, self::fixture_user( 'author' ), false ) ), $calls );
	}

	/**
	 * An exception inside the notifier never reaches the workflow action.
	 *
	 * @return void
	 */
	public function test_notifier_exception_does_not_break_the_transition() {
		$this->enable( array( 'approved' => true ) );
		add_filter(
			'sit_cwm_notification_recipients',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		$post_id = $this->move_to_approved_as_editor();

		$this->assert_status( $post_id, 'approved' );
		$this->assertCount( 0, $this->sent() );
	}

	/**
	 * `sit_cwm_notification_sent` fires once per recipient.
	 *
	 * @return void
	 */
	public function test_sent_action_fires_per_recipient() {
		$this->enable( array( 'comment_added' => true ) );

		$calls = array();
		add_action(
			'sit_cwm_notification_sent',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			4
		);

		$post_id = $this->fixture_post();
		$this->assertIsInt( $this->workflow->add_comment( $post_id, 'Please tighten.', self::fixture_user( 'editor' ) ) );

		$this->assertSame(
			array(
				array( 'comment_added', $post_id, self::fixture_user( 'author' ), true ),
				array( 'comment_added', $post_id, self::fixture_user( 'reviewer' ), true ),
			),
			$calls
		);
		$this->assertCount( 2, $this->sent() );
	}

	/**
	 * The three actions are hooked at 20, after the 1.0 core work.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_three_actions_at_20() {
		$notifier = new Notifier( $this->settings, new RecipientResolver( $this->posts, new PermissionManager( $this->posts, $this->settings ) ), new MessageBuilder( new PermissionManager( $this->posts, $this->settings ) ), new Deduplicator() );
		$notifier->register();

		$this->assertSame( 20, has_action( 'sit_cwm_status_changed', array( $notifier, 'on_status_changed' ) ) );
		$this->assertSame( 20, has_action( 'sit_cwm_reviewer_assigned', array( $notifier, 'on_reviewer_assigned' ) ) );
		$this->assertSame( 20, has_action( 'sit_cwm_comment_added', array( $notifier, 'on_comment_added' ) ) );
	}
}
