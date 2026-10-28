<?php
/**
 * Tests for the publish gate decision value object.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sit_Cwm\Publishing\GateDecision;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GateDecision behaviour.
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\GateDecision
 */
final class GateDecisionTest extends TestCase {

	/**
	 * The predicates are derived from the outcome alone.
	 *
	 * @return void
	 */
	public function test_predicates_follow_outcome() {
		$refused = new GateDecision( GateDecision::OUTCOME_REFUSED, 'enforce', 'review', GateDecision::REASON_NONE );
		$this->assertTrue( $refused->is_refused() );
		$this->assertFalse( $refused->is_bypassed() );
		$this->assertTrue( $refused->applies() );
		$this->assertFalse( GateDecision::not_applicable( 'off' )->applies() );
	}

	/**
	 * Failing open is recorded as a bypass for reason `error` (E5).
	 *
	 * @return void
	 */
	public function test_failed_open_is_a_bypass_with_reason_error() {
		$d = GateDecision::failed_open( 'enforce', '' );
		$this->assertSame( GateDecision::OUTCOME_BYPASSED, $d->outcome() );
		$this->assertSame( GateDecision::REASON_ERROR, $d->reason() );
	}

	/**
	 * An outcome outside the four constants is a programming error.
	 *
	 * @return void
	 */
	public function test_rejects_unknown_outcome() {
		$this->expectException( InvalidArgumentException::class );
		new GateDecision( 'maybe', 'enforce', 'review', '' );
	}

	/**
	 * The getters return what was passed in.
	 *
	 * @return void
	 */
	public function test_getters_return_constructor_values() {
		$d = new GateDecision( GateDecision::OUTCOME_BYPASSED, 'log_only', 'review', GateDecision::REASON_LOG_ONLY );
		$this->assertSame( 'bypassed', $d->outcome() );
		$this->assertSame( 'log_only', $d->mode() );
		$this->assertSame( 'review', $d->workflow_status() );
		$this->assertSame( 'log_only', $d->reason() );
		$this->assertTrue( $d->is_bypassed() );
		$this->assertFalse( $d->is_refused() );
	}
}
