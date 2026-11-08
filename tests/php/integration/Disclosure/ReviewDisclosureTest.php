<?php
/**
 * Integration tests for the front-end review disclosure.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Disclosure;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Disclosure\ReviewDisclosure;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\ApprovedContent;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The disclosure shows only what the audit trail supports, and nothing for
 * content that is not published, not managed or not approved (F11).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Disclosure\ReviewDisclosure
 */
final class ReviewDisclosureTest extends TestCase {

	use CreatesWorkflowPosts;
	use ApprovedContent;

	/**
	 * Service under test.
	 *
	 * @var ReviewDisclosure
	 */
	private $disclosure;

	/**
	 * Builds the service over the test's repositories.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->disclosure = new ReviewDisclosure( new Settings(), $this->posts, $this->activity );
	}

	/**
	 * Off by default: no data, no markup.
	 *
	 * @return void
	 */
	public function test_nothing_while_setting_is_off() {
		$p = $this->approved_and_published();
		$this->assertNull( $this->disclosure->data_for( $p ) );
		$this->assertSame( '', $this->disclosure->render( $p ) );
	}

	/**
	 * The filter can switch the line on for one post with the setting off.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_enables_disclosure_for_one_post_while_setting_off() {
		$on  = $this->approved_and_published();
		$off = $this->approved_and_published();

		add_filter(
			'sit_cwm_disclosure_enabled',
			static function ( $enabled, $post_id ) use ( $on ) {
				return $post_id === $on ? true : $enabled;
			},
			10,
			2
		);

		$this->assertNotNull( $this->disclosure->data_for( $on ) );
		$this->assertNull( $this->disclosure->data_for( $off ) );
	}

	/**
	 * The filter never shows a line without an approval row.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_cannot_bypass_approval_requirement() {
		add_filter( 'sit_cwm_disclosure_enabled', '__return_true' );

		$post = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		$this->posts->set_status( $post, 'approved' );
		wp_publish_post( $post );

		$this->assertNull( $this->disclosure->data_for( $post ) );
	}

	/**
	 * The filter never shows a line on a post that is not published.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_cannot_bypass_published_requirement() {
		add_filter( 'sit_cwm_disclosure_enabled', '__return_true' );

		$post = $this->approved_and_published();

		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);

		$this->assertNull( $this->disclosure->data_for( $post ) );
	}

	/**
	 * Only boolean `true` enables.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_truthy_non_boolean_does_not_enable() {
		add_filter(
			'sit_cwm_disclosure_enabled',
			static function () {
				return 1;
			}
		);

		$this->assertNull( $this->disclosure->data_for( $this->approved_and_published() ) );
	}

	/**
	 * The filter can switch the line off for a post with the setting on.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filter_can_disable_for_a_post_while_setting_on() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		add_filter( 'sit_cwm_disclosure_enabled', '__return_false' );

		$this->assertNull( $this->disclosure->data_for( $this->approved_and_published() ) );
	}

	/**
	 * Both lines when on; each can be switched off, and both off is empty.
	 *
	 * @return void
	 */
	public function test_renders_reviewer_date_and_ai_line_when_on() {
		update_option( 'date_format', 'Y-m-d' );
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->approved_and_published();
		$this->posts->set_ai_origin( $p, 'ai_assisted' );

		$html = $this->disclosure->render( $p );
		$this->assertStringStartsWith( '<div class="sit-cwm-disclosure">', $html );
		$this->assertStringContainsString( '<p class="sit-cwm-disclosure__review">Reviewed by Editor Fixture on 2026-10-20.</p>', $html );
		$this->assertStringContainsString( '<p class="sit-cwm-disclosure__ai">This content was created with AI assistance and reviewed by a person.</p>', $html );
		$this->assertStringNotContainsString( 'sit-cwm-disclosure__ai', $this->disclosure->render( $p, true, false ) );
		$this->assertStringNotContainsString( 'sit-cwm-disclosure__review', $this->disclosure->render( $p, false, true ) );
		$this->assertSame( '', $this->disclosure->render( $p, false, false ) );

		$data = $this->disclosure->data_for( $p );
		$this->assertSame( self::fixture_user( 'editor' ), $data['approver_id'] );
		$this->assertSame( '2026-10-20 09:00:00', $data['approved_at'] );
		$this->assertSame( 'ai_assisted', $data['ai_origin'] );
	}

	/**
	 * The AI line follows the declaration, and is omitted when none was made.
	 *
	 * @return void
	 */
	public function test_ai_line_per_origin_and_none_when_not_declared() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->approved_and_published();

		$this->assertStringNotContainsString( 'sit-cwm-disclosure__ai', $this->disclosure->render( $p ) );
		$this->assertSame( '', $this->disclosure->render( $p, false, true ), 'Nothing declared and no reviewer line: empty.' );

		$this->posts->set_ai_origin( $p, 'ai_generated' );
		$this->assertStringContainsString( 'This content was generated with AI and reviewed by a person.', $this->disclosure->render( $p ) );
	}

	/**
	 * Published while still in Review: no approval in force (Review Focus 6).
	 *
	 * @return void
	 */
	public function test_no_disclosure_for_post_published_without_approval() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->create_managed_post( array( 'post_status' => 'publish' ) );
		$this->posts->set_status( $p, 'review' );
		$this->assertNull( $this->disclosure->data_for( $p ) );
	}

	/**
	 * An approved status with no approval row says nothing either.
	 *
	 * @return void
	 */
	public function test_no_disclosure_without_an_approval_row() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->create_managed_post(
			array(
				'post_status'     => 'publish',
				'workflow_status' => 'approved',
			)
		);
		$this->assertNull( $this->disclosure->data_for( $p ) );
	}

	/**
	 * Sent back for changes while live: the approval no longer stands.
	 *
	 * @return void
	 */
	public function test_no_disclosure_once_sent_back_for_changes_while_live() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->approved_and_published();
		$this->posts->set_status( $p, 'needs_changes' );
		$this->assertNull( $this->disclosure->data_for( $p ) );
	}

	/**
	 * Unpublished content and types outside the workflow get nothing.
	 *
	 * @return void
	 */
	public function test_no_disclosure_for_drafts_or_unmanaged_types() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->approved_and_published();
		wp_update_post(
			array(
				'ID'          => $p,
				'post_status' => 'draft',
			)
		);
		$this->assertNull( $this->disclosure->data_for( $p ) );
		$this->assertNull( $this->disclosure->data_for( self::factory()->post->create( array( 'post_type' => 'attachment' ) ) ) );
		$this->assertNull( $this->disclosure->data_for( 0 ) );
		$this->assertSame( '', $this->disclosure->render( 0 ) );
	}

	/**
	 * The newest approval names the approver; once that user is deleted the
	 * reviewer line is dropped and the AI line stays.
	 *
	 * @return void
	 */
	public function test_latest_approval_wins_and_deleted_approver_drops_the_reviewer_line() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p     = $this->approved_and_published();
		$other = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Later Approver',
			)
		);
		$this->activity->log(
			$p,
			'status_changed',
			array(
				'old_value'  => 'review',
				'new_value'  => 'approved',
				'user_id'    => $other,
				'created_at' => '2026-10-25 09:00:00',
			)
		);
		$this->assertSame( 'Later Approver', $this->disclosure->data_for( $p )['approver_name'] );

		wp_delete_user( $other );
		$this->posts->set_ai_origin( $p, 'human' );
		$html = $this->disclosure->render( $p );
		$this->assertStringNotContainsString( 'sit-cwm-disclosure__review', $html );
		$this->assertStringContainsString( 'This content was written by a person.', $html );
	}

	/**
	 * Display names are escaped.
	 *
	 * @return void
	 */
	public function test_output_is_escaped() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$evil = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => '<script>x</script>',
			)
		);
		$p    = $this->approved_and_published( array( 'approver' => $evil ) );

		// Core may strip tags on insert; force the raw value so escaping is what is tested.
		global $wpdb;
		$wpdb->update( $wpdb->users, array( 'display_name' => '<script>x</script>' ), array( 'ID' => $evil ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test arrange.
		clean_user_cache( $evil );

		$html = $this->disclosure->render( $p );
		$this->assertStringContainsString( 'sit-cwm-disclosure__review', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
