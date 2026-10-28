<?php
/**
 * Integration tests for the publish gate decision service.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Publishing;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The gate resolves a mode per post type and post, and judges a publish
 * attempt without side effects (roadmap §3.1A, E1, E2).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\PublishGate
 * @covers \Sit_Cwm\Publishing\GateDecision
 */
final class PublishGateTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var PublishGate
	 */
	private $gate;

	/**
	 * Builds the gate over the shared repository.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->gate = new PublishGate( new Settings(), $this->posts, new PermissionManager( $this->posts, new Settings() ) );
	}

	/**
	 * With nothing configured, every type is off (upgrade from 1.0.0).
	 *
	 * @return void
	 */
	public function test_mode_defaults_to_off() {
		$this->assertSame( 'off', $this->gate->mode_for( 'post' ) );
	}

	/**
	 * The stored mode goes through the filter; an unknown filtered value is off.
	 *
	 * @return void
	 */
	public function test_mode_reads_settings_and_filter_and_rejects_bad_filter_values() {
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
		$this->assertSame( 'enforce', $this->gate->mode_for( 'post' ) );

		add_filter(
			'sit_cwm_publish_gate_mode',
			static function () {
				return 'log_only';
			}
		);
		$this->assertSame( 'log_only', $this->gate->mode_for( 'post' ) );

		add_filter(
			'sit_cwm_publish_gate_mode',
			static function () {
				return 'block';
			},
			20
		);
		$this->assertSame( 'off', $this->gate->mode_for( 'post' ) );
	}

	/**
	 * Review Focus 3: a type removed from the workflow is off, whatever its
	 * stored mode.
	 *
	 * @return void
	 */
	public function test_mode_is_off_for_post_type_no_longer_enabled() {
		( new Settings() )->update( array( 'publish_gate' => array( 'page' => 'enforce' ) ) );
		( new Settings() )->update( array( 'post_types' => array( 'post' ) ) );

		$this->assertSame( 'off', $this->gate->mode_for( 'page' ) );
	}

	/**
	 * E1: the filter gets the post id, and the answer is not cached across posts.
	 *
	 * @return void
	 */
	public function test_mode_filter_receives_post_id_and_answers_per_post() {
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
		$strict = self::factory()->post->create();
		$loose  = self::factory()->post->create();
		$seen   = array();

		add_filter(
			'sit_cwm_publish_gate_mode',
			static function ( $mode, $type, $post_id ) use ( &$seen, $loose ) {
				$seen[] = $post_id;
				return $loose === $post_id ? 'log_only' : $mode;
			},
			10,
			3
		);

		$this->assertSame( 'enforce', $this->gate->mode_for( 'post', $strict ) );
		$this->assertSame( 'log_only', $this->gate->mode_for( 'post', $loose ) ); // Fails if the first answer were cached.
		$this->assertSame( 'enforce', $this->gate->mode_for( 'post' ) );
		$this->assertSame( array( $strict, $loose, 0 ), $seen );
		$this->assertTrue( $this->gate->evaluate( $loose, 'post', self::fixture_user( 'author' ) )->is_bypassed() ); // evaluate() passes the id through.
	}

	/**
	 * Every row of the roadmap §3.1A mode table.
	 *
	 * @dataProvider data_outcomes
	 *
	 * @param string $mode            Gate mode.
	 * @param string $workflow_status Stored workflow status.
	 * @param string $user_key        Fixture user key.
	 * @param string $outcome         Expected outcome.
	 * @param string $reason          Expected reason.
	 * @return void
	 */
	public function test_evaluate_outcomes( string $mode, string $workflow_status, string $user_key, string $outcome, string $reason ) {
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => $mode ) ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->posts->set_status( $post_id, $workflow_status );

		$d = $this->gate->evaluate( $post_id, 'post', self::fixture_user( $user_key ) );

		$this->assertSame( $outcome, $d->outcome() );
		$this->assertSame( $reason, $d->reason() );
		$this->assertSame( 'not_applicable' === $outcome ? '' : $workflow_status, $d->workflow_status() );
	}

	/**
	 * Mode table cases.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public function data_outcomes() {
		return array(
			'off never applies'             => array( 'off', 'draft', 'author', 'not_applicable', '' ),
			'log_only approved passes'      => array( 'log_only', 'approved', 'author', 'allowed', 'approved' ),
			'log_only review bypasses'      => array( 'log_only', 'review', 'author', 'bypassed', 'log_only' ),
			'enforce approved passes'       => array( 'enforce', 'approved', 'author', 'allowed', 'approved' ),
			'enforce published passes'      => array( 'enforce', 'published', 'author', 'allowed', 'approved' ),
			'enforce review refuses author' => array( 'enforce', 'review', 'author', 'refused', '' ),
			'enforce review refuses editor' => array( 'enforce', 'needs_changes', 'editor', 'refused', '' ),
			'enforce draft admin bypasses'  => array( 'enforce', 'draft', 'admin', 'bypassed', 'capability' ),
			'log_only admin is log_only'    => array( 'log_only', 'draft', 'admin', 'bypassed', 'log_only' ),
		);
	}

	/**
	 * E2: a brand-new post has the default status and does not pass.
	 *
	 * @return void
	 */
	public function test_new_post_id_zero_uses_default_status_and_is_refused_in_enforce() {
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );

		$d = $this->gate->evaluate( 0, 'post', self::fixture_user( 'author' ) );

		$this->assertTrue( $d->is_refused() );
		$this->assertSame( 'draft', $d->workflow_status() );
	}

	/**
	 * E2: an unregistered stored status does not pass.
	 *
	 * @return void
	 */
	public function test_unknown_stored_status_does_not_pass() {
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, PostRepository::META_STATUS, 'legacy_status' );

		$this->assertTrue( $this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) )->is_refused() );
	}

	/**
	 * E2: only boolean `true` from the filter passes.
	 *
	 * @return void
	 */
	public function test_passes_filter_can_refuse_an_approved_post_and_only_true_passes() {
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
		$post_id = self::factory()->post->create();
		$this->posts->set_status( $post_id, 'approved' );

		add_filter( 'sit_cwm_publish_gate_passes', '__return_zero' );

		$this->assertTrue( $this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) )->is_refused() );
	}

	/**
	 * E2: a `WP_Error` from the passes filter is a refusal.
	 *
	 * @return void
	 */
	public function test_passes_filter_wp_error_refuses() {
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
		$post_id = self::factory()->post->create();
		$this->posts->set_status( $post_id, 'approved' );

		add_filter(
			'sit_cwm_publish_gate_passes',
			static function () {
				return new WP_Error( 'checklist_incomplete', 'Checklist incomplete.' );
			}
		);

		$this->assertTrue( $this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) )->is_refused() );
	}

	/**
	 * Judging writes no activity and fires no gate action.
	 *
	 * @return void
	 */
	public function test_evaluate_has_no_side_effects() {
		// Fixture first: the booted save layer would refuse its publish.
		$post_id = self::factory()->post->create();
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
		$count = $this->activity->count_for_post( $post_id );

		$this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) );
		$this->gate->describe( $post_id, self::fixture_user( 'author' ) );

		$this->assertSame( $count, $this->activity->count_for_post( $post_id ) );
		$this->assertSame( 0, did_action( 'sit_cwm_publish_gate_refused' ) );
		$this->assertSame( 0, did_action( 'sit_cwm_publish_gate_bypassed' ) );
	}

	/**
	 * E4, E10: the editor hint is not applicable to live and scheduled posts.
	 *
	 * @return void
	 */
	public function test_describe_is_not_applicable_for_live_and_scheduled_posts() {
		// Fixtures first: the booted save layer would refuse their publish/schedule.
		$live      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$scheduled = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );

		$this->assertSame(
			array(
				'mode'    => 'enforce',
				'outcome' => 'not_applicable',
			),
			$this->gate->describe( $live, self::fixture_user( 'author' ) )
		);

		$this->assertSame( 'not_applicable', $this->gate->describe( $scheduled, self::fixture_user( 'author' ) )['outcome'] );

		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->assertSame(
			array(
				'mode'    => 'enforce',
				'outcome' => 'refused',
			),
			$this->gate->describe( $draft, self::fixture_user( 'author' ) )
		);
	}

	/**
	 * A missing post is described as off.
	 *
	 * @return void
	 */
	public function test_describe_missing_post_is_off() {
		$this->assertSame(
			array(
				'mode'    => 'off',
				'outcome' => 'not_applicable',
			),
			$this->gate->describe( PHP_INT_MAX, self::fixture_user( 'author' ) )
		);
	}
}
