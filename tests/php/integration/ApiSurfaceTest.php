<?php
/**
 * Integration tests for the frozen public PHP surface (F6).
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every class says whether add-ons may call it, and ARCHITECTURE.md lists
 * exactly the `@api` methods and frozen service ids the code has.
 *
 * @since 1.2.0
 *
 * @coversNothing
 */
final class ApiSurfaceTest extends WP_UnitTestCase {

	/**
	 * Heading that opens the documented surface in ARCHITECTURE.md.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const SECTION = '## Public API (frozen in 1.2.0)';

	/**
	 * Every class docblock carries exactly one of `@api` / `@internal`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_every_class_is_tagged_exactly_once() {
		$classes = $this->classes();
		$this->assertNotEmpty( $classes );

		foreach ( $classes as $class ) {
			$doc  = (string) ( new ReflectionClass( $class ) )->getDocComment();
			$tags = preg_match_all( '/@(api|internal)\b/', $doc );
			$this->assertSame( 1, $tags, "{$class} needs exactly one of @api / @internal" );
		}
	}

	/**
	 * Every public method an `@api` class declares carries a tag.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_public_methods_of_api_classes_are_tagged() {
		foreach ( $this->api_classes() as $class ) {
			foreach ( ( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
				if ( $method->getDeclaringClass()->getName() !== $class || '__construct' === $method->getName() ) {
					continue;
				}
				$this->assertMatchesRegularExpression( '/@(api|internal)\b/', (string) $method->getDocComment(), "{$class}::{$method->getName()}" );
			}
		}
	}

	/**
	 * ARCHITECTURE.md lists every `@api` method, and nothing else.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_architecture_lists_every_api_method_and_nothing_else() {
		$documented = $this->documented_api_methods();
		$tagged     = array();

		foreach ( $this->api_classes() as $class ) {
			$short = substr( $class, strlen( 'Sit_Cwm\\' ) );
			foreach ( ( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
				if ( $method->getDeclaringClass()->getName() === $class && preg_match( '/@api\b/', (string) $method->getDocComment() ) ) {
					$tagged[] = $short . '::' . $method->getName();
				}
			}
		}

		sort( $documented );
		sort( $tagged );
		$this->assertNotEmpty( $tagged );
		$this->assertSame( $tagged, $documented );
	}

	/**
	 * Constructors of `@api` classes are `@internal`: add-ons get instances
	 * from the container, so constructors may change in minor versions.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_constructors_of_api_classes_are_internal() {
		foreach ( $this->api_classes() as $class ) {
			$ctor = ( new ReflectionClass( $class ) )->getConstructor();
			if ( null !== $ctor && $ctor->getDeclaringClass()->getName() === $class ) {
				$this->assertMatchesRegularExpression( '/@internal\b/', (string) $ctor->getDocComment(), $class );
			}
		}
	}

	/**
	 * Every documented service id resolves in the booted container.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_documented_service_ids_are_registered() {
		$ids = $this->documented_service_ids();
		$this->assertNotEmpty( $ids );

		foreach ( $ids as $id ) {
			$this->assertTrue( sit_cwm_container()->has( $id ), $id );
		}
	}

	/**
	 * Every class and interface under `includes/` and `admin/`, named by the
	 * same rule as `sit_cwm_autoload()`.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Fully qualified names.
	 */
	private function classes(): array {
		$map     = array(
			'admin'    => 'Sit_Cwm\\Admin\\',
			'includes' => 'Sit_Cwm\\',
		);
		$classes = array();

		foreach ( $map as $dir => $prefix ) {
			$base  = SIT_CWM_PLUGIN_DIR . $dir . '/';
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ) );

			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}

				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base ) ) );
				$class    = $prefix . str_replace( '/', '\\', substr( $relative, 0, -4 ) );

				if ( class_exists( $class ) || interface_exists( $class ) ) {
					$classes[] = $class;
				}
			}
		}

		sort( $classes );

		return $classes;
	}

	/**
	 * The classes whose class docblock says `@api`.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Fully qualified names.
	 */
	private function api_classes(): array {
		return array_values(
			array_filter(
				$this->classes(),
				static function ( $class_name ) {
					return 1 === preg_match( '/@api\b/', (string) ( new ReflectionClass( $class_name ) )->getDocComment() );
				}
			)
		);
	}

	/**
	 * Every `` `Short\Class::method(` `` token in the public API section.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] `Short\Class::method` entries.
	 */
	private function documented_api_methods(): array {
		preg_match_all( '/`([A-Za-z_]+(?:\\\\[A-Za-z_]+)+)::(\w+)\(`/', $this->section(), $matches, PREG_SET_ORDER );

		return array_map(
			static function ( $set ) {
				return $set[1] . '::' . $set[2];
			},
			$matches
		);
	}

	/**
	 * The backticked ids at the start of each `### Service ids` list item.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	private function documented_service_ids(): array {
		$section = $this->section();
		$start   = strpos( $section, '### Service ids' );
		$this->assertNotFalse( $start, 'ARCHITECTURE.md lacks "### Service ids".' );

		$list = substr( $section, $start );
		$next = strpos( $list, "\n### ", 1 );
		if ( false !== $next ) {
			$list = substr( $list, 0, $next );
		}

		preg_match_all( '/^- `([a-z0-9_.]+)`/m', $list, $matches );

		return $matches[1];
	}

	/**
	 * ARCHITECTURE.md from the public API heading to the next `## ` heading.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	private function section(): string {
		$doc   = (string) file_get_contents( SIT_CWM_PLUGIN_DIR . 'ARCHITECTURE.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$start = strpos( $doc, self::SECTION );
		$this->assertNotFalse( $start, 'ARCHITECTURE.md lacks "' . self::SECTION . '".' );

		$body = substr( $doc, $start + strlen( self::SECTION ) );
		$end  = strpos( $body, "\n## " );

		return false === $end ? $body : substr( $body, 0, $end );
	}
}
