<?php
/**
 * Tests for the declared AI-origin value set.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sit_Cwm\Governance\AiOrigin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Values, the strict sanitizer and the labels (F9, F16).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Governance\AiOrigin
 */
final class AiOriginTest extends TestCase {

	/**
	 * The value set is fixed and only exact values survive sanitizing.
	 *
	 * @return void
	 */
	public function test_values_and_sanitize() {
		$this->assertSame( array( 'human', 'ai_assisted', 'ai_generated' ), AiOrigin::VALUES );
		$this->assertSame( '', AiOrigin::NOT_DECLARED );
		$this->assertSame( 'ai_generated', AiOrigin::sanitize( 'ai_generated' ) );

		foreach ( array( 'AI_GENERATED', ' human', 'robot', '', 1, null, array(), true ) as $bad ) {
			$this->assertSame( '', AiOrigin::sanitize( $bad ) );
		}
	}

	/**
	 * Every value has its F16 label; anything else reads "Not declared".
	 *
	 * @return void
	 */
	public function test_labels() {
		$this->assertSame( 'Human-written', AiOrigin::label( 'human' ) );
		$this->assertSame( 'AI-assisted', AiOrigin::label( 'ai_assisted' ) );
		$this->assertSame( 'AI-generated', AiOrigin::label( 'ai_generated' ) );
		$this->assertSame( 'Not declared', AiOrigin::label( '' ) );
		$this->assertSame( 'Not declared', AiOrigin::label( 'junk' ) );
	}

	/**
	 * Options list the three values, in `VALUES` order, with their labels.
	 *
	 * @return void
	 */
	public function test_options() {
		$this->assertSame(
			array(
				'human'        => 'Human-written',
				'ai_assisted'  => 'AI-assisted',
				'ai_generated' => 'AI-generated',
			),
			AiOrigin::options()
		);
	}
}
