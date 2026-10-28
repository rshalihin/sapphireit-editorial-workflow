<?php
/**
 * Tests for the E4 "transition into published" rule.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sit_Cwm\Publishing\Layers\SaveLayer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SaveLayer::is_entering_published() (E4 rule 2).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\Layers\SaveLayer::is_entering_published
 */
final class SaveLayerRuleTest extends TestCase {

	/**
	 * Target status, stored status, expected result.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public function provide_transitions(): array {
		return array(
			'publish from draft'      => array( 'publish', 'draft', true ),
			'publish from pending'    => array( 'publish', 'pending', true ),
			'publish from future'     => array( 'publish', 'future', true ),
			'publish from auto-draft' => array( 'publish', 'auto-draft', true ),
			'publish of a new post'   => array( 'publish', '', true ),
			'publish from publish'    => array( 'publish', 'publish', false ),
			'future from draft'       => array( 'future', 'draft', true ),
			'future from future'      => array( 'future', 'future', false ),
			'future from publish'     => array( 'future', 'publish', false ),
			'private from draft'      => array( 'private', 'draft', false ),
			'draft from publish'      => array( 'draft', 'publish', false ),
		);
	}

	/**
	 * Only entry into `publish`/`future` from outside it is judged.
	 *
	 * @dataProvider provide_transitions
	 *
	 * @param string $target   Status being written.
	 * @param string $stored   Status stored before the write.
	 * @param bool   $expected Whether the write is judged.
	 * @return void
	 */
	public function test_is_entering_published( string $target, string $stored, bool $expected ) {
		$this->assertSame( $expected, SaveLayer::is_entering_published( $target, $stored ) );
	}
}
