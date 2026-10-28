<?php
/**
 * Integration tests for the notification deduplicator.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Notifications;

use Sit_Cwm\Notifications\Deduplicator;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A mail is claimed once per event, post, recipient and fingerprint within
 * the window (E13).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Notifications\Deduplicator
 */
final class DeduplicatorTest extends WP_UnitTestCase {

	/**
	 * The same claim is refused inside the window; any other part differing
	 * makes it a new claim.
	 *
	 * @return void
	 */
	public function test_second_claim_within_window_is_refused() {
		$d = new Deduplicator();

		$this->assertTrue( $d->claim( 'approved', 5, 7, 'approved' ) );
		$this->assertFalse( $d->claim( 'approved', 5, 7, 'approved' ) );
		$this->assertTrue( $d->claim( 'approved', 5, 8, 'approved' ) ); // Other recipient.
		$this->assertTrue( $d->claim( 'approved', 6, 7, 'approved' ) ); // Other post.
		$this->assertTrue( $d->claim( 'comment_added', 5, 7, md5( 'b' ) ) );
		$this->assertTrue( $d->claim( 'comment_added', 5, 7, md5( 'c' ) ) ); // Other comment.
	}

	/**
	 * The claim is a transient that expires after `WINDOW` seconds.
	 *
	 * @return void
	 */
	public function test_claim_expires_after_window() {
		$this->assertSame( 120, Deduplicator::WINDOW );

		( new Deduplicator() )->claim( 'approved', 5, 7, 'approved' );

		$key     = Deduplicator::PREFIX . md5( 'approved|5|7|approved' );
		$expires = (int) get_option( '_transient_timeout_' . $key ) - time();

		$this->assertNotFalse( get_transient( $key ) );
		$this->assertGreaterThan( 0, $expires );
		$this->assertLessThanOrEqual( Deduplicator::WINDOW, $expires );
	}
}
