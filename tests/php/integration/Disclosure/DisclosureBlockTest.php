<?php
/**
 * Integration tests for the review disclosure block.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Disclosure;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Disclosure\DisclosureBlock;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\ApprovedContent;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use WP_Block;
use WP_Block_Type_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `sit-cwm/review-disclosure` is always registered, insertable only while the
 * setting is on, and renders the shortcode's markup inside the block wrapper
 * for the post in block context (F11).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Disclosure\DisclosureBlock
 */
final class DisclosureBlockTest extends TestCase {

	use CreatesWorkflowPosts;
	use ApprovedContent;

	/**
	 * The booted plugin registered the block on `init`.
	 *
	 * @return void
	 */
	public function test_booted_plugin_registers_the_block() {
		$this->assertSame( 'sit-cwm/review-disclosure', DisclosureBlock::NAME );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( DisclosureBlock::NAME ) );
		$this->assertInstanceOf( DisclosureBlock::class, sit_cwm_container()->get( 'disclosure.block' ) );
	}

	/**
	 * Off hides the block from the inserter; on leaves core's default.
	 *
	 * @return void
	 */
	public function test_block_is_registered_with_inserter_following_the_setting() {
		$this->register_block();
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'sit-cwm/review-disclosure' );
		$this->assertNotNull( $type );
		$this->assertFalse( $type->supports['inserter'] );
		unregister_block_type( 'sit-cwm/review-disclosure' );
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$this->register_block();
		$this->assertArrayNotHasKey( 'inserter', WP_Block_Type_Registry::get_instance()->get_registered( 'sit-cwm/review-disclosure' )->supports );
	}

	/**
	 * Renders for the context post, honours the attributes, and wraps the
	 * markup in the block wrapper.
	 *
	 * @return void
	 */
	public function test_render_uses_block_context_post_and_attributes() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->approved_and_published(); // From the ApprovedContent trait (phase 18).
		$this->posts->set_ai_origin( $p, 'ai_generated' );
		$this->register_block();
		$block = new WP_Block(
			array(
				'blockName' => 'sit-cwm/review-disclosure',
				'attrs'     => array( 'showReviewer' => false ),
			),
			array( 'postId' => $p )
		);
		$out   = $block->render();
		$this->assertStringContainsString( 'This content was generated with AI and reviewed by a person.', $out );
		$this->assertStringNotContainsString( 'sit-cwm-disclosure__review', $out );
		// The wrapper class comes from get_block_wrapper_attributes.
		$this->assertStringContainsString( 'wp-block-sit-cwm-review-disclosure', $out );
	}

	/**
	 * Saved blocks print nothing while the setting is off.
	 *
	 * @return void
	 */
	public function test_render_is_empty_when_setting_off_even_for_saved_blocks() {
		$p = $this->approved_and_published();
		$this->register_block();
		$this->assertSame(
			'',
			( new WP_Block(
				array(
					'blockName' => 'sit-cwm/review-disclosure',
					'attrs'     => array(),
				),
				array( 'postId' => $p )
			) )->render()
		);
	}

	/**
	 * Nothing prints for content that was never approved.
	 *
	 * @return void
	 */
	public function test_render_is_empty_for_unapproved_content() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->create_managed_post( array( 'post_status' => 'publish' ) );
		$this->register_block();

		$block = new WP_Block(
			array(
				'blockName' => 'sit-cwm/review-disclosure',
				'attrs'     => array(),
			),
			array( 'postId' => $p )
		);

		$this->assertSame( '', $block->render() );
	}

	/**
	 * Re-registers the block through the plugin's service, so the current
	 * setting is applied to its arguments.
	 *
	 * @return void
	 */
	private function register_block() {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( DisclosureBlock::NAME ) ) {
			unregister_block_type( DisclosureBlock::NAME );
		}

		sit_cwm_container()->get( 'disclosure.block' )->register_block();
	}
}
