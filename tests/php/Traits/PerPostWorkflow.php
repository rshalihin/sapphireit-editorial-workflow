<?php
/**
 * Post-specific workflow fixture.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Traits;

use Sit_Cwm\Workflow\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives one post its own workflow through the registry filters: a
 * `legal_review` status between `review` and `approved`, reachable with
 * `sit_cwm_review_content`. Every other post, and the site-wide context
 * (post id 0), keeps the core workflow.
 *
 * The using test calls `remove_per_post_workflow()` in `tear_down()`.
 *
 * @since 1.2.0
 */
trait PerPostWorkflow {

	/**
	 * Filters added by `enable_legal_review_for()`: list of [ hook, callback ].
	 *
	 * @var array<int, array{0: string, 1: callable}>
	 */
	private $per_post_filters = array();

	/**
	 * Registers the `legal_review` workflow for one post only.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	protected function enable_legal_review_for( int $post_id ): void {
		$filters = array(
			'sit_cwm_statuses'              => static function ( $statuses, $id = 0 ) use ( $post_id ) {
				if ( $post_id === $id ) {
					$statuses['legal_review'] = array(
						'slug'  => 'legal_review',
						'label' => 'Legal Review',
						'order' => 35,
					);
				}
				return $statuses;
			},
			'sit_cwm_transition_map'        => static function ( $map, $id = 0 ) use ( $post_id ) {
				if ( $post_id === $id ) {
					$map['review']       = array( 'legal_review' );
					$map['legal_review'] = array( 'approved' );
				}
				return $map;
			},
			'sit_cwm_status_capability_map' => static function ( $map, $id = 0 ) use ( $post_id ) {
				if ( $post_id === $id ) {
					$map['legal_review'] = Capabilities::REVIEW_CONTENT;
				}
				return $map;
			},
		);

		foreach ( $filters as $hook => $callback ) {
			add_filter( $hook, $callback, 10, 2 );
			$this->per_post_filters[] = array( $hook, $callback );
		}
	}

	/**
	 * Removes every filter added by `enable_legal_review_for()`.
	 *
	 * @return void
	 */
	protected function remove_per_post_workflow(): void {
		foreach ( $this->per_post_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], 10 );
		}

		$this->per_post_filters = array();
	}
}
