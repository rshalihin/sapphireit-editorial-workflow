<?php
/**
 * Integration tests for the review disclosure shortcode.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Disclosure;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Disclosure\DisclosureShortcode;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\ApprovedContent;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[sit_cwm_review_disclosure]` renders the current post's disclosure only,
 * honours its attributes, and prints nothing while the setting is off (F11).
 *
 * Runs against the shortcode the booted plugin registered on `init`.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Disclosure\DisclosureShortcode
 */
final class DisclosureShortcodeTest extends TestCase {

	use CreatesWorkflowPosts;
	use ApprovedContent;

	/**
	 * The booted plugin registered the shortcode.
	 *
	 * @return void
	 */
	public function test_shortcode_is_registered() {
		$this->assertSame( 'sit_cwm_review_disclosure', DisclosureShortcode::TAG );
		$this->assertTrue( shortcode_exists( DisclosureShortcode::TAG ) );
		$this->assertInstanceOf( DisclosureShortcode::class, sit_cwm_container()->get( 'disclosure.shortcode' ) );
	}

	/**
	 * Renders for the current post; `reviewer` and `ai` switch lines off.
	 *
	 * @return void
	 */
	public function test_shortcode_renders_for_current_post_and_respects_attributes() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$p = $this->approved_and_published();
		$this->posts->set_ai_origin( $p, 'human' );
		$this->go_to( get_permalink( $p ) );
		the_post();

		$this->assertStringContainsString( 'sit-cwm-disclosure__review', do_shortcode( '[sit_cwm_review_disclosure]' ) );
		$this->assertStringNotContainsString( 'sit-cwm-disclosure__review', do_shortcode( '[sit_cwm_review_disclosure reviewer="0"]' ) );

		foreach ( array( '0', 'no', 'false', 'NO' ) as $off ) {
			$this->assertStringNotContainsString( 'sit-cwm-disclosure__ai', do_shortcode( "[sit_cwm_review_disclosure ai=\"{$off}\"]" ), $off );
		}

		$this->assertStringContainsString( 'sit-cwm-disclosure__ai', do_shortcode( '[sit_cwm_review_disclosure ai="1"]' ) );
		$this->assertSame( '', do_shortcode( '[sit_cwm_review_disclosure reviewer="no" ai="false"]' ) );
	}

	/**
	 * Off prints nothing; a `post_id` attribute cannot point it elsewhere.
	 *
	 * @return void
	 */
	public function test_shortcode_prints_nothing_when_off_and_ignores_post_id_attribute() {
		$p = $this->approved_and_published();
		$this->go_to( get_permalink( $p ) );
		the_post();
		$this->assertSame( '', do_shortcode( '[sit_cwm_review_disclosure]' ) );

		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$other = $this->create_managed_post( array( 'post_status' => 'publish' ) );
		$this->assertNotSame( '', do_shortcode( '[sit_cwm_review_disclosure]' ) );
		$this->assertSame( do_shortcode( '[sit_cwm_review_disclosure]' ), do_shortcode( "[sit_cwm_review_disclosure post_id=\"{$other}\"]" ) );

		// Pointed at an approved post from an unapproved one, it still says nothing.
		$this->go_to( get_permalink( $other ) );
		the_post();
		$this->assertSame( '', do_shortcode( "[sit_cwm_review_disclosure post_id=\"{$p}\"]" ) );
	}

	/**
	 * Outside the loop there is no current post, so nothing prints.
	 *
	 * @return void
	 */
	public function test_shortcode_prints_nothing_without_a_current_post() {
		( new Settings() )->update( array( 'disclosure_enabled' => true ) );
		$this->approved_and_published();
		$GLOBALS['post'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Clearing the loop post under test.

		$this->assertSame( '', do_shortcode( '[sit_cwm_review_disclosure]' ) );
	}
}
