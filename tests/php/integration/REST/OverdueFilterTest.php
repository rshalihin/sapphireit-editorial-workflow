<?php
/**
 * Integration tests for the dashboard "Overdue only" filter.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/posts?overdue=true`.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\PostsController::get_items
 * @covers \Sit_Cwm\Content\PostRepository::query_posts
 * @covers \Sit_Cwm\Content\PostRepository::dashboard_meta_query
 * @covers \Sit_Cwm\Content\PostRepository::unfinished_status_clause
 */
final class OverdueFilterTest extends TestCase {

	/**
	 * Only posts due before today in the site timezone, and not in a final
	 * status, match; the rows agree with the server's `is_overdue` flag.
	 *
	 * @return void
	 */
	public function test_overdue_filter_uses_site_timezone_and_skips_final_statuses() {
		// UTC+14, so the site's date is often a day ahead of UTC.
		update_option( 'timezone_string', 'Pacific/Kiritimati' );

		$final = null;

		foreach ( ( new StatusManager() )->slugs() as $slug ) {
			if ( ( new StatusManager() )->is_final( $slug ) ) {
				$final = $slug;
				break;
			}
		}

		$this->assertNotNull( $final, 'A final status is registered.' );

		$editor    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$today     = current_datetime()->format( 'Y-m-d' );
		$yesterday = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );
		$tomorrow  = current_datetime()->modify( '+1 day' )->format( 'Y-m-d' );
		$utc_today = gmdate( 'Y-m-d' );
		$base      = array(
			'post_author' => $editor,
			'post_title'  => 'overdueprobe',
		);

		$late    = $this->create_managed_post( $base + array( 'workflow_status' => 'review', 'due_date' => $yesterday ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture rows.
		$fresh   = $this->create_managed_post( $base + array( 'due_date' => $yesterday ) );
		$done    = $this->create_managed_post( $base + array( 'workflow_status' => $final, 'due_date' => $yesterday ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture rows.
		$due_now = $this->create_managed_post( $base + array( 'workflow_status' => 'review', 'due_date' => $today ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture rows.
		$future  = $this->create_managed_post( $base + array( 'workflow_status' => 'review', 'due_date' => $tomorrow ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture rows.
		$utc     = $this->create_managed_post( $base + array( 'workflow_status' => 'review', 'due_date' => $utc_today ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Short fixture rows.
		$undated = $this->create_managed_post( $base + array( 'workflow_status' => 'review' ) );

		wp_set_current_user( $editor );

		$expected = array( $late, $fresh );

		// A date that is "today" in UTC is overdue only if the site is already a day ahead.
		if ( $utc_today < $today ) {
			$expected[] = $utc;
		}

		$response = $this->get_posts( array( 'overdue' => 'true' ) );
		$ids      = array_column( $response->get_data(), 'post_id' );

		sort( $expected );
		sort( $ids );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected, $ids );
		$this->assertSame( array( true ), array_values( array_unique( array_column( $response->get_data(), 'is_overdue' ) ) ) );

		// `overdue=false` applies no filter.
		$this->assertCount( 7, $this->get_posts( array( 'overdue' => 'false' ) )->get_data() );

		unset( $done, $due_now, $future, $undated );
	}

	/**
	 * A non-boolean value is rejected.
	 *
	 * @return void
	 */
	public function test_invalid_value_is_bad_request() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assert_error_response( $this->get_posts( array( 'overdue' => 'sometimes' ) ), 'rest_invalid_param', 400 );
	}

	/**
	 * Requests the probe posts.
	 *
	 * @param array $query Extra query parameters.
	 * @return \WP_REST_Response
	 */
	private function get_posts( array $query ) {
		return $this->rest_request(
			'GET',
			'/sit-cwm/v1/posts',
			null,
			array_merge(
				array(
					'search'   => 'overdueprobe',
					'per_page' => 100,
				),
				$query
			)
		);
	}
}
