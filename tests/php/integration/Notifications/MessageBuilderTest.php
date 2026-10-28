<?php
/**
 * Integration tests for the notification message builder.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Notifications;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Notifications\MessageBuilder;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mail copy is plain text in the recipient's language, and comment text only
 * reaches recipients who may read the activity history (E13).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Notifications\MessageBuilder
 */
final class MessageBuilderTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var MessageBuilder
	 */
	private $builder;

	/**
	 * Builds the message builder.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->builder = new MessageBuilder( new PermissionManager( $this->posts, new Settings() ) );
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
				'post_title'  => 'Launch plan',
				'reviewer_id' => self::fixture_user( 'reviewer' ),
			)
		);
	}

	/**
	 * Subject follows E14, the body starts with the fixed first line, links to
	 * the post and carries no markup.
	 *
	 * @return void
	 */
	public function test_approved_message_subject_and_body() {
		update_option( 'blogname', 'Acme & Co' );
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Q3 <b>launch</b>',
				'post_author' => self::fixture_user( 'author' ),
			)
		);

		$m = $this->builder->build( 'approved', $post_id, self::fixture_user( 'author' ), self::fixture_user( 'editor' ) );

		$this->assertSame( '[Acme & Co] Approved: Q3 launch', $m['subject'] );
		$this->assertStringStartsWith( 'Editor Fixture approved “Q3 launch”.', $m['body'] );
		// No current user (as in cron): core's get_edit_post_link() would return null.
		$this->assertStringContainsString( 'Open it: ' . admin_url( 'post.php?post=' . $post_id . '&action=edit' ), $m['body'] );
		$this->assertStringContainsString( 'You are receiving this because email notifications are turned on for Acme & Co.', $m['body'] );
		$this->assertStringNotContainsString( '<', $m['body'] );
	}

	/**
	 * Every event has its own E14 subject and fixed first line.
	 *
	 * @dataProvider data_copy
	 *
	 * @param string $event   Event key.
	 * @param string $subject Expected subject after the site name.
	 * @param string $first   Expected first body line.
	 * @return void
	 */
	public function test_copy_per_event( string $event, string $subject, string $first ) {
		update_option( 'blogname', 'Site' );
		$post_id = $this->fixture_post();

		$m = $this->builder->build( $event, $post_id, self::fixture_user( 'reviewer' ), self::fixture_user( 'editor' ), array( 'message' => 'Fine.' ) );

		$this->assertSame( '[Site] ' . $subject, $m['subject'] );
		$this->assertStringStartsWith( $first, $m['body'] );
	}

	/**
	 * Data for test_copy_per_event().
	 *
	 * @return array<string, string[]>
	 */
	public function data_copy(): array {
		return array(
			'reviewer_assigned' => array( 'reviewer_assigned', 'Review assigned: Launch plan', 'Editor Fixture asked you to review “Launch plan”.' ),
			'review_requested'  => array( 'review_requested', 'Ready for review: Launch plan', 'Editor Fixture submitted “Launch plan” for review.' ),
			'changes_requested' => array( 'changes_requested', 'Changes requested: Launch plan', 'Editor Fixture asked for changes to “Launch plan”.' ),
			'approved'          => array( 'approved', 'Approved: Launch plan', 'Editor Fixture approved “Launch plan”.' ),
			'comment_added'     => array( 'comment_added', 'New comment: Launch plan', "Editor Fixture commented on “Launch plan”:\n\nFine." ),
		);
	}

	/**
	 * Roadmap C: no comment body without sit_cwm_view_activity.
	 *
	 * @return void
	 */
	public function test_comment_body_only_for_recipients_who_may_view_activity() {
		$post_id = $this->fixture_post();
		$with    = $this->builder->build( 'comment_added', $post_id, self::fixture_user( 'reviewer' ), self::fixture_user( 'editor' ), array( 'message' => '<p>Cut the <em>intro</em> &amp; outro.</p>' ) );
		$this->assertStringContainsString( 'Cut the intro & outro.', $with['body'] );

		$no_view = $this->create_user_with_caps( array( 'edit_posts', 'edit_others_posts', 'read' ), 'subscriber' ); // Can read, lacks sit_cwm_view_activity.
		$without = $this->builder->build( 'comment_added', $post_id, $no_view, self::fixture_user( 'editor' ), array( 'message' => '<p>Secret</p>' ) );
		$this->assertStringNotContainsString( 'Secret', $without['body'] );
		$this->assertStringStartsWith( 'Editor Fixture added a workflow comment to “Launch plan”.', $without['body'] );
	}

	/**
	 * Subject and body are translated into the recipient's locale, and the
	 * previous locale is restored afterwards.
	 *
	 * @return void
	 */
	public function test_message_is_built_in_the_recipients_locale() {
		$recipient = self::fixture_user( 'author' );
		update_user_meta( $recipient, 'locale', 'de_DE' );

		add_filter(
			'gettext',
			static function ( $translation, $text, $domain ) {
				if ( 'sapphireit-editorial-workflow' === $domain && 'de_DE' === determine_locale() ) {
					return '[de] ' . $translation;
				}

				return $translation;
			},
			10,
			3
		);

		$before = determine_locale();
		$m      = $this->builder->build( 'approved', $this->fixture_post(), $recipient, self::fixture_user( 'editor' ) );

		$this->assertStringStartsWith( '[de] ', $m['subject'] );
		$this->assertStringStartsWith( '[de] ', $m['body'] );
		$this->assertSame( $before, determine_locale() );
	}

	/**
	 * Actor 0 is named "System"; a deleted actor is "Someone".
	 *
	 * @return void
	 */
	public function test_system_actor_is_named_system() {
		$post_id = $this->fixture_post();

		$system = $this->builder->build( 'approved', $post_id, self::fixture_user( 'author' ), 0 );
		$this->assertStringStartsWith( 'System approved', $system['body'] );

		$gone = self::factory()->user->create();
		wp_delete_user( $gone );
		$deleted = $this->builder->build( 'approved', $post_id, self::fixture_user( 'author' ), $gone );
		$this->assertStringStartsWith( 'Someone approved', $deleted['body'] );
	}

	/**
	 * A post without a title is named "(no title)".
	 *
	 * @return void
	 */
	public function test_untitled_post_has_placeholder_title() {
		$post_id = self::factory()->post->create( array( 'post_title' => '' ) );

		$m = $this->builder->build( 'approved', $post_id, self::fixture_user( 'author' ), self::fixture_user( 'editor' ) );

		$this->assertStringStartsWith( 'Editor Fixture approved “(no title)”.', $m['body'] );
	}

	/**
	 * `sit_cwm_notification_message` can replace the message; anything but an
	 * array with string subject and body is ignored.
	 *
	 * @return void
	 */
	public function test_message_filter_can_override_and_bad_return_falls_back() {
		$post_id   = $this->fixture_post();
		$recipient = self::fixture_user( 'author' );
		$seen      = array();

		$override = static function ( $message, $event, $filtered_post_id, $recipient_id ) use ( &$seen ) {
			$seen = array( $event, $filtered_post_id, $recipient_id, is_array( $message ) );

			return array(
				'subject' => 'X',
				'body'    => 'Y',
			);
		};
		add_filter( 'sit_cwm_notification_message', $override, 10, 4 );

		$this->assertSame(
			array(
				'subject' => 'X',
				'body'    => 'Y',
			),
			$this->builder->build( 'approved', $post_id, $recipient, self::fixture_user( 'editor' ) )
		);
		$this->assertSame( array( 'approved', $post_id, $recipient, true ), $seen );

		remove_filter( 'sit_cwm_notification_message', $override, 10 );
		$original = $this->builder->build( 'approved', $post_id, $recipient, self::fixture_user( 'editor' ) );

		$bad_returns = array(
			'nope',
			array( 'subject' => 'X' ),
			array(
				'subject' => 1,
				'body'    => 'Y',
			),
		);

		foreach ( $bad_returns as $bad ) {
			$filter = static function () use ( $bad ) {
				return $bad;
			};
			add_filter( 'sit_cwm_notification_message', $filter );

			$this->assertSame( $original, $this->builder->build( 'approved', $post_id, $recipient, self::fixture_user( 'editor' ) ) );

			remove_filter( 'sit_cwm_notification_message', $filter );
		}
	}
}
