<?php
/**
 * Integration tests for review attestation.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Governance;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Governance\Attestation;
use Sit_Cwm\Tests\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether approving needs attestation follows the setting and the filter,
 * and the statement is the F16 copy (F10).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Governance\Attestation
 */
final class AttestationTest extends TestCase {

	/**
	 * Off by default; the setting turns it on; the filter decides per post,
	 * and only boolean true requires.
	 *
	 * @return void
	 */
	public function test_off_by_default_and_follows_setting_and_filter() {
		$attestation = new Attestation( new Settings() );
		$post        = $this->create_managed_post();
		$other       = $this->create_managed_post();

		$this->assertFalse( $attestation->is_required( $post ) );

		( new Settings() )->update( array( 'require_attestation' => true ) );
		$this->assertTrue( $attestation->is_required( $post ) );

		add_filter(
			'sit_cwm_require_attestation',
			static function ( $required, $post_id ) use ( $post ) {
				return $post_id === $post ? 'yes' : $required;
			},
			10,
			2
		);

		$this->assertFalse( $attestation->is_required( $post ), 'Only boolean true requires.' );
		$this->assertTrue( $attestation->is_required( $other ) );
		$this->assertSame( 'I confirm I have reviewed this content and approve it for publication.', $attestation->statement() );
	}

	/**
	 * The filter can require attestation on a post while the site setting is off.
	 *
	 * @return void
	 */
	public function test_filter_can_require_per_post() {
		$attestation = new Attestation( new Settings() );
		$post        = $this->create_managed_post();
		$other       = $this->create_managed_post();

		add_filter(
			'sit_cwm_require_attestation',
			static function ( $required, $post_id ) use ( $post ) {
				return $post_id === $post ? true : $required;
			},
			10,
			2
		);

		$this->assertTrue( $attestation->is_required( $post ) );
		$this->assertFalse( $attestation->is_required( $other ) );
	}
}
