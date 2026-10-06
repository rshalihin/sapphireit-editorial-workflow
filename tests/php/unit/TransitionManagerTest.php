<?php
/**
 * Tests for the workflow transition map.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sit_Cwm\Workflow\StatusManager;
use Sit_Cwm\Workflow\TransitionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TransitionManager behaviour. Pure structure: no database, users or meta.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Workflow\TransitionManager
 */
final class TransitionManagerTest extends TestCase {

	/**
	 * Core slugs (D3).
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const CORE_SLUGS = array( 'draft', 'writing', 'review', 'needs_changes', 'approved', 'published' );

	/**
	 * Legal pairs (D4), as "from>to".
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const LEGAL = array(
		'draft>writing',
		'writing>review',
		'review>approved',
		'review>needs_changes',
		'needs_changes>writing',
		'approved>published',
		'approved>needs_changes',
		'published>writing',
	);

	/**
	 * Rollback pairs, as "from>to".
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const ROLLBACKS = array(
		'review>needs_changes',
		'approved>needs_changes',
		'published>writing',
	);

	/**
	 * Manager under test.
	 *
	 * @since 1.0.0
	 * @var TransitionManager
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
		$this->clear_filters();
		$this->manager = new TransitionManager( new StatusManager() );
	}

	/**
	 * Clears filters added by a test.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clear_filters();
		$GLOBALS['sit_cwm_test_actions']['init'] = 1;
		parent::tearDown();
	}

	/**
	 * Every one of the 36 pairs is valid exactly when it is in D4.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider provide_matrix
	 *
	 * @param string $from     Source slug.
	 * @param string $to       Target slug.
	 * @param bool   $expected Whether the pair is legal.
	 * @return void
	 */
	public function test_matrix( $from, $to, $expected ) {
		$this->assertSame( $expected, $this->manager->is_valid( $from, $to ) );
	}

	/**
	 * Full 6 × 6 matrix.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function provide_matrix() {
		$cases = array();

		foreach ( self::CORE_SLUGS as $from ) {
			foreach ( self::CORE_SLUGS as $to ) {
				$cases[ "{$from} > {$to}" ] = array( $from, $to, in_array( "{$from}>{$to}", self::LEGAL, true ) );
			}
		}

		return $cases;
	}

	/**
	 * Explicit guarantees called out in the plan.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_explicit_rejections() {
		$this->assertFalse( $this->manager->is_valid( 'review', 'review' ) );
		$this->assertFalse( $this->manager->is_valid( 'writing', 'approved' ) );
		$this->assertFalse( $this->manager->is_valid( 'bogus', 'review' ) );
		$this->assertFalse( $this->manager->is_valid( 'review', 'bogus' ) );
		$this->assertFalse( $this->manager->is_valid( '', '' ) );
	}

	/**
	 * `map()` equals the core map; `targets_for()` keeps map order.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_map_and_targets() {
		$this->assertSame( TransitionManager::MAP, $this->manager->map() );
		$this->assertSame( array( 'approved', 'needs_changes' ), $this->manager->targets_for( 'review' ) );
		$this->assertSame( array( 'published', 'needs_changes' ), $this->manager->targets_for( 'approved' ) );
		$this->assertSame( array(), $this->manager->targets_for( 'bogus' ) );
	}

	/**
	 * `describe()` classifies every pair.
	 *
	 * @since 1.0.0
	 *
	 * @dataProvider provide_matrix
	 *
	 * @param string $from     Source slug.
	 * @param string $to       Target slug.
	 * @param bool   $is_valid Whether the pair is legal.
	 * @return void
	 */
	public function test_describe( $from, $to, $is_valid ) {
		$is_rollback = in_array( "{$from}>{$to}", self::ROLLBACKS, true );

		$this->assertSame(
			array(
				'slug'        => $to,
				'label'       => ( new StatusManager() )->label( $to ),
				'is_forward'  => $is_valid && ! $is_rollback,
				'is_rollback' => $is_rollback,
			),
			$this->manager->describe( $from, $to )
		);
	}

	/**
	 * `describe()` on an unknown target falls back to the slug as label.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_describe_unknown() {
		$this->assertSame(
			array(
				'slug'        => 'bogus',
				'label'       => 'bogus',
				'is_forward'  => false,
				'is_rollback' => false,
			),
			$this->manager->describe( 'review', 'bogus' )
		);
	}

	/**
	 * A filter can replace the map with a two-status flow.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_filter_replaces_map() {
		add_filter(
			'sit_cwm_transition_map',
			static function () {
				return array(
					'draft'     => array( 'published' ),
					'published' => array( 'draft' ),
				);
			}
		);

		$this->assertSame(
			array(
				'draft'     => array( 'published' ),
				'published' => array( 'draft' ),
			),
			$this->manager->map()
		);
		$this->assertTrue( $this->manager->is_valid( 'draft', 'published' ) );
		$this->assertTrue( $this->manager->is_valid( 'published', 'draft' ) );
		$this->assertFalse( $this->manager->is_valid( 'draft', 'writing' ) );
		$this->assertFalse( $this->manager->is_valid( 'review', 'approved' ) );

		// `published → draft` is not in the rollback map, so it is forward.
		$this->assertTrue( $this->manager->describe( 'published', 'draft' )['is_forward'] );
	}

	/**
	 * Unknown statuses, self-transitions and malformed entries are sanitized away.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_filter_is_sanitized() {
		add_filter(
			'sit_cwm_transition_map',
			static function ( $map ) {
				$map['draft']        = array( 'writing', 'bogus', 'draft', 'writing', 42, array( 'review' ) );
				$map['bogus']        = array( 'review' );
				$map['review']       = 'approved';
				$map['writing']      = array( 'review', 'review' );
				$map[0]              = array( 'writing' );
				$map['only_invalid'] = array( 'bogus' );
				return $map;
			}
		);

		$this->assertSame(
			array(
				'draft'         => array( 'writing' ),
				'writing'       => array( 'review' ),
				'needs_changes' => array( 'writing' ),
				'approved'      => array( 'published', 'needs_changes' ),
				'published'     => array( 'writing' ),
			),
			$this->manager->map()
		);
		$this->assertFalse( $this->manager->is_valid( 'draft', 'bogus' ) );
		$this->assertFalse( $this->manager->is_valid( 'draft', 'draft' ) );
		$this->assertSame( array(), $this->manager->targets_for( 'review' ) );
	}

	/**
	 * A filter returning a non-array keeps the core map.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_keeps_core() {
		add_filter(
			'sit_cwm_transition_map',
			static function () {
				return null;
			}
		);

		$this->assertSame( TransitionManager::MAP, $this->manager->map() );
	}

	/**
	 * A status added through the registry becomes usable in the map.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_filtered_status_is_accepted() {
		add_filter(
			'sit_cwm_statuses',
			static function ( $statuses ) {
				$statuses['legal_review'] = array(
					'slug'  => 'legal_review',
					'label' => 'Legal Review',
				);
				return $statuses;
			}
		);
		add_filter(
			'sit_cwm_transition_map',
			static function ( $map ) {
				$map['review']       = array( 'legal_review', 'needs_changes' );
				$map['legal_review'] = array( 'approved', 'needs_changes' );
				return $map;
			}
		);

		$this->assertTrue( $this->manager->is_valid( 'review', 'legal_review' ) );
		$this->assertFalse( $this->manager->is_valid( 'review', 'approved' ) );
		$this->assertSame( 'Legal Review', $this->manager->describe( 'review', 'legal_review' )['label'] );
	}

	/**
	 * The rollback map is filterable and only applies to legal transitions.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_rollback_filter() {
		add_filter(
			'sit_cwm_rollback_transitions',
			static function ( $rollbacks ) {
				$rollbacks['needs_changes'] = array( 'writing' );
				$rollbacks['writing']       = array( 'draft' );
				return $rollbacks;
			}
		);

		$this->assertTrue( $this->manager->describe( 'needs_changes', 'writing' )['is_rollback'] );
		$this->assertFalse( $this->manager->describe( 'writing', 'draft' )['is_rollback'] );
	}

	/**
	 * The resolved map is memoised until `flush()`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_memoised_until_flush() {
		$this->manager->map();

		add_filter(
			'sit_cwm_transition_map',
			static function ( $map ) {
				$map['writing'][] = 'approved';
				return $map;
			}
		);

		$this->assertFalse( $this->manager->is_valid( 'writing', 'approved' ) );

		$this->manager->flush();

		$this->assertTrue( $this->manager->is_valid( 'writing', 'approved' ) );
	}

	/**
	 * Before `init`, the map is not cached (filters may not be attached yet).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_not_memoised_before_init() {
		$GLOBALS['sit_cwm_test_actions']['init'] = 0;

		$this->manager->map();

		add_filter(
			'sit_cwm_transition_map',
			static function ( $map ) {
				$map['writing'][] = 'approved';
				return $map;
			}
		);

		$this->assertTrue( $this->manager->is_valid( 'writing', 'approved' ) );
	}

	/**
	 * Removes every filter this suite touches.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function clear_filters() {
		remove_all_filters( 'sit_cwm_statuses' );
		remove_all_filters( 'sit_cwm_transition_map' );
		remove_all_filters( 'sit_cwm_rollback_transitions' );
	}
}
