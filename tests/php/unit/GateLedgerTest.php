<?php
/**
 * Tests for the per-request publish gate ledger.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sit_Cwm\Publishing\GateDecision;
use Sit_Cwm\Publishing\GateLedger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GateLedger behaviour.
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\GateLedger
 */
final class GateLedgerTest extends TestCase {

	/**
	 * A judged decision is returned until it is forgotten.
	 *
	 * @return void
	 */
	public function test_judge_and_forget() {
		$ledger   = new GateLedger();
		$decision = new GateDecision( GateDecision::OUTCOME_REFUSED, 'enforce', 'review', GateDecision::REASON_NONE );

		$this->assertNull( $ledger->judged( 7 ) );

		$ledger->judge( 7, $decision );
		$this->assertSame( $decision, $ledger->judged( 7 ) );
		$this->assertNull( $ledger->judged( 8 ) );

		$ledger->forget( 7 );
		$this->assertNull( $ledger->judged( 7 ) );
	}

	/**
	 * Holds are a LIFO stack per post type.
	 *
	 * @return void
	 */
	public function test_release_pops_latest_hold_for_type_only() {
		$ledger = new GateLedger();
		$a      = GateDecision::not_applicable( 'off' );
		$b      = new GateDecision( 'refused', 'enforce', 'draft', '' );
		$ledger->hold( 'post', 'publish', $a );
		$ledger->hold( 'page', 'future', $b );
		$ledger->hold( 'post', 'future', $b );
		$this->assertSame(
			array(
				'attempted' => 'future',
				'decision'  => $b,
			),
			$ledger->release( 'post' )
		);
		$this->assertSame(
			array(
				'attempted' => 'publish',
				'decision'  => $a,
			),
			$ledger->release( 'post' )
		);
		$this->assertNull( $ledger->release( 'post' ) );
		$this->assertNotNull( $ledger->release( 'page' ) );
	}

	/**
	 * The reverting flag is set only while the callback runs.
	 *
	 * @return void
	 */
	public function test_while_reverting_sets_flag_during_callback() {
		$ledger = new GateLedger();
		$seen   = null;
		$ledger->while_reverting(
			static function () use ( $ledger, &$seen ) {
				$seen = $ledger->is_reverting();
			}
		);
		$this->assertTrue( $seen );
		$this->assertFalse( $ledger->is_reverting() );
	}

	/**
	 * The reverting flag is reset even when the callback throws.
	 *
	 * @return void
	 */
	public function test_while_reverting_resets_flag_even_when_callback_throws() {
		$ledger = new GateLedger();
		try {
			$ledger->while_reverting(
				static function () {
					throw new RuntimeException( 'x' );
				}
			);
		} catch ( RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The exception is the point of the test.
		}
		$this->assertFalse( $ledger->is_reverting() );
	}
}
