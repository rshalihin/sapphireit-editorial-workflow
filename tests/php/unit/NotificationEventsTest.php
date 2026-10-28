<?php
/**
 * Tests for the notification event registry.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sit_Cwm\Notifications\NotificationEvents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NotificationEvents behaviour that needs no WordPress.
 *
 * `labels()` calls `__()`, so it is covered by the integration suite.
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Notifications\NotificationEvents
 */
final class NotificationEventsTest extends TestCase {

	/**
	 * The five events (E12), in order.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_all_lists_the_five_events_in_order() {
		$this->assertSame(
			array( 'reviewer_assigned', 'review_requested', 'changes_requested', 'approved', 'comment_added' ),
			NotificationEvents::all()
		);
	}

	/**
	 * Only `review`, `needs_changes` and `approved` targets notify.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_for_status_maps_only_the_three_notifying_targets() {
		$this->assertSame( 'review_requested', NotificationEvents::for_status( 'review' ) );
		$this->assertSame( 'changes_requested', NotificationEvents::for_status( 'needs_changes' ) );
		$this->assertSame( 'approved', NotificationEvents::for_status( 'approved' ) );
		$this->assertNull( NotificationEvents::for_status( 'writing' ) );
		$this->assertNull( NotificationEvents::for_status( 'published' ) );
		$this->assertNull( NotificationEvents::for_status( 'draft' ) );
	}
}
