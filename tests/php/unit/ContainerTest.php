<?php
/**
 * Tests for the service container.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sit_Cwm\Core\Container;
use stdClass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Container behaviour.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Core\Container
 */
final class ContainerTest extends TestCase {

	/**
	 * `get()` builds a service once and returns the same instance afterwards.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_get_memoises_instance() {
		$container = new Container();
		$calls     = 0;

		$container->set(
			'service',
			static function () use ( &$calls ) {
				++$calls;
				return new stdClass();
			}
		);

		$first  = $container->get( 'service' );
		$second = $container->get( 'service' );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $calls );
	}

	/**
	 * Factories are lazy and receive the container.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_factory_is_lazy_and_receives_container() {
		$container = new Container();
		$received  = null;

		$container->set(
			'service',
			static function ( Container $c ) use ( &$received ) {
				$received = $c;
				return new stdClass();
			}
		);

		$this->assertNull( $received );

		$container->get( 'service' );

		$this->assertSame( $container, $received );
	}

	/**
	 * `has()` reflects registered factories.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_has() {
		$container = new Container();

		$this->assertFalse( $container->has( 'service' ) );

		$container->set(
			'service',
			static function () {
				return new stdClass();
			}
		);

		$this->assertTrue( $container->has( 'service' ) );
	}

	/**
	 * Unknown ids throw a clear exception naming the id.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_get_unknown_id_throws() {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '"missing"' );

		( new Container() )->get( 'missing' );
	}

	/**
	 * Replacing a factory discards the instance built from the old one.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_set_replaces_existing_instance() {
		$container = new Container();

		$container->set(
			'service',
			static function () {
				return 'old';
			}
		);
		$container->get( 'service' );

		$container->set(
			'service',
			static function () {
				return 'new';
			}
		);

		$this->assertSame( 'new', $container->get( 'service' ) );
	}
}
