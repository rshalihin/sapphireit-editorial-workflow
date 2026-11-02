<?php
/**
 * Integration tests for the AI-origin creation stamp.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Governance;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Governance\AiOriginStamper;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * New workflow content gets its type's AI-origin default once, at creation,
 * unless something declared a value first (F9).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Governance\AiOriginStamper
 */
final class AiOriginStamperTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var AiOriginStamper
	 */
	private $stamper;

	/**
	 * Replaces the booted plugin's stamper with one under test, so every
	 * insert is stamped at most once, by fresh dependencies.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remove_booted_stampers();

		$this->stamper = new AiOriginStamper( $this->posts, new Settings(), $this->activity );
		$this->stamper->register();
	}

	/**
	 * Detaches the stamper under test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_action( 'wp_after_insert_post', array( $this->stamper, 'handle' ), 10 );

		parent::tear_down();
	}

	/**
	 * A new post of a type with a default gets it, with one `default` entry
	 * by the acting user and one action.
	 *
	 * @return void
	 */
	public function test_new_post_gets_default_stamped_and_logged() {
		( new Settings() )->update( array( 'ai_origin_defaults' => array( 'post' => 'ai_assisted' ) ) );
		$author = self::fixture_user( 'author' );
		wp_set_current_user( $author );
		$fired = array();
		add_action(
			'sit_cwm_ai_origin_changed',
			static function () use ( &$fired ) {
				$fired[] = func_get_args();
			},
			10,
			5
		);

		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );

		$this->assertSame( 'ai_assisted', $this->posts->get_ai_origin( $post_id ) );

		$entries = $this->activity->get_for_post( $post_id, array( 'action' => 'ai_origin_changed' ) );
		$this->assertCount( 1, $entries );
		$this->assertSame(
			array( 'ai_origin_changed', null, 'ai_assisted', array( 'via' => 'default' ), $author ),
			array( $entries[0]->get_action(), $entries[0]->get_old_value(), $entries[0]->get_new_value(), $entries[0]->get_context(), $entries[0]->get_user_id() )
		);
		$this->assertSame( array( array( $post_id, 'ai_assisted', '', $author, 'default' ) ), $fired );
	}

	/**
	 * Without a default nothing is stored and nothing is logged.
	 *
	 * @return void
	 */
	public function test_no_default_means_not_declared_and_no_row() {
		$post_id = self::factory()->post->create();

		$this->assertSame( '', $this->posts->get_ai_origin( $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, PostRepository::META_AI_ORIGIN, true ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'ai_origin_changed' ) ) );
	}

	/**
	 * Updates never stamp, so a default set later never reaches existing
	 * posts.
	 *
	 * @return void
	 */
	public function test_updates_never_stamp_and_changing_default_never_rewrites_history() {
		$post_id = self::factory()->post->create();
		( new Settings() )->update( array( 'ai_origin_defaults' => array( 'post' => 'human' ) ) );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited',
			)
		);

		$this->assertSame( '', $this->posts->get_ai_origin( $post_id ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'ai_origin_changed' ) ) );
	}

	/**
	 * Review Focus 4: a value declared during the insert (what the `wp/v2`
	 * field does before `wp_after_insert_post`) wins over the default.
	 *
	 * @return void
	 */
	public function test_declared_value_on_create_is_not_overwritten_by_default() {
		( new Settings() )->update( array( 'ai_origin_defaults' => array( 'post' => 'human' ) ) );
		add_action(
			'wp_insert_post',
			static function ( $id ) {
				update_post_meta( $id, PostRepository::META_AI_ORIGIN, 'ai_generated' );
			},
			5
		);

		$post_id = self::factory()->post->create();

		$this->assertSame( 'ai_generated', $this->posts->get_ai_origin( $post_id ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'ai_origin_changed' ) ) );
	}

	/**
	 * Types outside the workflow are never stamped.
	 *
	 * @return void
	 */
	public function test_unmanaged_type_is_not_stamped() {
		( new Settings() )->update(
			array(
				'post_types'         => array( 'page' ),
				'ai_origin_defaults' => array( 'page' => 'human' ),
			)
		);

		$this->assertSame( '', get_post_meta( self::factory()->post->create(), PostRepository::META_AI_ORIGIN, true ) );
		$this->assertSame( 'human', $this->posts->get_ai_origin( self::factory()->post->create( array( 'post_type' => 'page' ) ) ) );
	}

	/**
	 * Revisions and autosaves are never stamped.
	 *
	 * @return void
	 */
	public function test_revisions_and_autosaves_are_not_stamped() {
		$post_id = self::factory()->post->create();
		( new Settings() )->update( array( 'ai_origin_defaults' => array( 'post' => 'human' ) ) );

		$autosave = _wp_put_post_revision( get_post( $post_id ), true );

		$this->stamper->stamp( $post_id, get_post( $post_id ), true );
		$this->stamper->stamp( (int) $autosave, get_post( $autosave ), false );

		$this->assertSame( '', get_post_meta( $post_id, PostRepository::META_AI_ORIGIN, true ) );
		$this->assertSame( '', get_post_meta( $autosave, PostRepository::META_AI_ORIGIN, true ) );
	}

	/**
	 * Bad hook input is ignored rather than raised.
	 *
	 * @return void
	 */
	public function test_invalid_input_is_ignored() {
		( new Settings() )->update( array( 'ai_origin_defaults' => array( 'post' => 'human' ) ) );

		$this->stamper->handle( 123, null, false, null );

		$this->assertSame( 0, $this->activity->count_for_post( 123 ) );
	}

	/**
	 * Removes every `AiOriginStamper` the booted plugin attached.
	 *
	 * @return void
	 */
	private function remove_booted_stampers(): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['wp_after_insert_post'] ) ) {
			return;
		}

		foreach ( $wp_filter['wp_after_insert_post']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof AiOriginStamper ) {
					remove_action( 'wp_after_insert_post', $callback['function'], $priority );
				}
			}
		}
	}
}
