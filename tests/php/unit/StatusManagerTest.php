<?php
/**
 * Tests for the workflow status registry.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * StatusManager behaviour.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Workflow\StatusManager
 */
final class StatusManagerTest extends TestCase {

	/**
	 * Core slugs (D3) in order.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const CORE_SLUGS = array( 'draft', 'writing', 'review', 'needs_changes', 'approved', 'published' );

	/**
	 * Manager under test.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $manager;

	/**
	 * Fresh manager, no filters.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		remove_all_filters( 'sit_cwm_statuses' );
		$this->manager = new StatusManager();
	}

	/**
	 * Clears filters added by a test.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		remove_all_filters( 'sit_cwm_statuses' );
		$GLOBALS['sit_cwm_test_actions']['init'] = 1;
		parent::tearDown();
	}

	/**
	 * `all()` returns exactly the six core slugs in `order` sequence.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_all_returns_core_statuses_in_order() {
		$all = $this->manager->all();

		$this->assertSame( self::CORE_SLUGS, array_keys( $all ) );
		$this->assertSame( self::CORE_SLUGS, $this->manager->slugs() );

		$orders = array_column( $all, 'order' );
		$sorted = $orders;
		sort( $sorted );
		$this->assertSame( $sorted, $orders );

		foreach ( $all as $slug => $status ) {
			$this->assertSame( $slug, $status['slug'] );
			$this->assertSame( array( 'slug', 'label', 'description', 'color', 'order', 'is_final' ), array_keys( $status ) );
		}
	}

	/**
	 * Sanitizer whitelists canonical slugs and falls back to the default.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider provide_sanitize_cases
	 *
	 * @param mixed  $input    Raw value.
	 * @param string $expected Expected slug.
	 * @return void
	 */
	public function test_sanitize( $input, $expected ) {
		$this->assertSame( $expected, $this->manager->sanitize( $input ) );
	}

	/**
	 * Sanitize cases.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function provide_sanitize_cases() {
		return array(
			'uppercase'      => array( 'APPROVED', 'draft' ),
			'unknown'        => array( 'bogus', 'draft' ),
			'null'           => array( null, 'draft' ),
			'array'          => array( array( 'review' ), 'draft' ),
			'padded'         => array( ' review ', 'review' ),
			'valid'          => array( 'needs_changes', 'needs_changes' ),
			'hyphen variant' => array( 'needs-changes', 'draft' ),
			'integer'        => array( 30, 'draft' ),
			'empty'          => array( '', 'draft' ),
		);
	}

	/**
	 * `exists()` is an exact match.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_exists() {
		$this->assertTrue( $this->manager->exists( 'needs_changes' ) );
		$this->assertFalse( $this->manager->exists( 'needs-changes' ) );
	}

	/**
	 * `get()`, `label()`, `default_status()` and `is_final()`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_accessors() {
		$this->assertSame( 'draft', $this->manager->default_status() );
		$this->assertSame( 'Review', $this->manager->label( 'review' ) );
		$this->assertSame( 'unknown_slug', $this->manager->label( 'unknown_slug' ) );
		$this->assertNull( $this->manager->get( 'unknown_slug' ) );
		$this->assertSame( '#996800', $this->manager->get( 'review' )['color'] );
		$this->assertTrue( $this->manager->is_final( 'published' ) );
		$this->assertFalse( $this->manager->is_final( 'approved' ) );
		$this->assertFalse( $this->manager->is_final( 'unknown_slug' ) );
	}

	/**
	 * A filter can add a status, which is normalised and sorted by order.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_filter_adds_status() {
		add_filter(
			'sit_cwm_statuses',
			static function ( $statuses ) {
				$statuses['legal_review'] = array(
					'slug'  => 'legal_review',
					'label' => 'Legal Review',
					'order' => 35,
				);
				return $statuses;
			}
		);

		$this->assertSame(
			array( 'draft', 'writing', 'review', 'legal_review', 'needs_changes', 'approved', 'published' ),
			$this->manager->slugs()
		);

		$legal = $this->manager->get( 'legal_review' );
		$this->assertSame( '', $legal['description'] );
		$this->assertSame( StatusManager::FALLBACK_COLOR, $legal['color'] );
		$this->assertFalse( $legal['is_final'] );
		$this->assertSame( 'legal_review', $this->manager->sanitize( 'legal_review' ) );
	}

	/**
	 * A filter returning a non-array leaves core statuses intact.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_keeps_core() {
		add_filter(
			'sit_cwm_statuses',
			static function () {
				return 'nonsense';
			}
		);

		$this->assertSame( self::CORE_SLUGS, $this->manager->slugs() );
	}

	/**
	 * Malformed entries are dropped; a registry with none left keeps core.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_malformed_entries_are_dropped() {
		add_filter(
			'sit_cwm_statuses',
			static function ( $statuses ) {
				$statuses[]              = 'junk';
				$statuses['no_label']    = array( 'slug' => 'no_label' );
				$statuses['no_slug']     = array( 'label' => 'No Slug' );
				$statuses['bad_slug']    = array(
					'slug'  => 'Bad Slug!',
					'label' => 'Bad',
				);
				$statuses['empty_label'] = array(
					'slug'  => 'empty_label',
					'label' => '   ',
				);
				$statuses['draft']       = array( 'slug' => 'draft' );
				return $statuses;
			}
		);

		// Filter broke `draft` (the default status), so core is restored.
		$this->assertSame( self::CORE_SLUGS, $this->manager->slugs() );

		remove_all_filters( 'sit_cwm_statuses' );
		$this->manager->flush();

		add_filter(
			'sit_cwm_statuses',
			static function () {
				return array( 'junk', array( 'label' => 'No Slug' ) );
			}
		);

		$this->assertSame( self::CORE_SLUGS, $this->manager->slugs() );
	}

	/**
	 * The resolved registry is memoised until `flush()`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_memoised_until_flush() {
		$this->manager->all();

		add_filter(
			'sit_cwm_statuses',
			static function ( $statuses ) {
				$statuses['extra'] = array(
					'slug'  => 'extra',
					'label' => 'Extra',
				);
				return $statuses;
			}
		);

		$this->assertFalse( $this->manager->exists( 'extra' ) );

		$this->manager->flush();

		$this->assertTrue( $this->manager->exists( 'extra' ) );
	}

	/**
	 * Before `init`, the registry is not cached (labels would be untranslated).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_not_memoised_before_init() {
		$GLOBALS['sit_cwm_test_actions']['init'] = 0;

		$this->manager->all();

		add_filter(
			'sit_cwm_statuses',
			static function ( $statuses ) {
				$statuses['extra'] = array(
					'slug'  => 'extra',
					'label' => 'Extra',
				);
				return $statuses;
			}
		);

		$this->assertTrue( $this->manager->exists( 'extra' ) );
	}
}
