<?php
/**
 * Integration tests that keep the documentation honest against the code.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The agent recipe uses real routes and fields, the listing never claims
 * AI detection, and every hook the code fires is documented.
 *
 * @since 1.2.0
 *
 * @coversNothing
 */
final class DocsContractTest extends WP_UnitTestCase {

	/**
	 * Reads a file from the plugin root.
	 *
	 * @since 1.2.0
	 *
	 * @param string $relative Path relative to the plugin directory.
	 * @return string File contents.
	 */
	private function read( $relative ) {
		$path = SIT_CWM_PLUGIN_DIR . $relative;
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	}

	/**
	 * The agent recipe names the routes and fields the code really has.
	 *
	 * @since 1.2.0
	 */
	public function test_agents_doc_uses_real_routes_and_fields() {
		$doc     = $this->read( 'docs/AGENTS.md' );
		$needles = array(
			'/wp-json/wp/v2/posts',
			'sit_cwm_ai_origin',
			'/wp-json/sit-cwm/v1/posts/',
			'/workflow',
			'"from"',
			'"status"',
			'Application Passwords',
			'ai_generated',
			'/wp-json/sit-cwm/v1/users?post_id=',
			'sit_cwm_status_conflict',
		);
		foreach ( $needles as $needle ) {
			$this->assertStringContainsString( $needle, $doc, $needle );
		}

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/sit-cwm/v1/posts/(?P<post_id>[\d]+)/workflow', $routes );
		$this->assertArrayHasKey( '/sit-cwm/v1/users', $routes );
	}

	/**
	 * The n8n workflow embedded in the recipe is valid JSON and carries no secret.
	 *
	 * @since 1.2.0
	 */
	public function test_agents_doc_n8n_workflow_is_valid_json_without_secrets() {
		$doc = $this->read( 'docs/AGENTS.md' );
		$this->assertSame( 1, preg_match( '/```json n8n\n(.+?)\n```/s', $doc, $m ), 'n8n block missing' );

		$workflow = json_decode( $m[1], true );
		$this->assertIsArray( $workflow );
		$this->assertArrayHasKey( 'nodes', $workflow );
		$this->assertArrayHasKey( 'connections', $workflow );
		$this->assertStringNotContainsStringIgnoringCase( 'password', $m[1] );
	}

	/**
	 * The listing never claims detection, and keeps the uncovered-path note.
	 *
	 * @since 1.2.0
	 */
	public function test_readme_never_claims_ai_detection() {
		$readme = strtolower( $this->read( 'readme.txt' ) );
		$this->assertDoesNotMatchRegularExpression( '/\bdetects? (ai|machine)/', $readme );
		$this->assertStringContainsString( 'declared, not detected', $readme );
		$this->assertStringContainsString( '$wpdb', $readme );
		$this->assertStringContainsString( '$wpdb', $this->read( 'ARCHITECTURE.md' ) );

		foreach ( array( 'no external requests', 'collects no data' ) as $claim ) {
			$this->assertStringNotContainsString( $claim, $readme, $claim );
		}
	}

	/**
	 * Every `sit_cwm_*` hook the code fires appears in ARCHITECTURE.md.
	 *
	 * @since 1.2.0
	 */
	public function test_every_hook_in_code_is_in_architecture() {
		$arch = $this->read( 'ARCHITECTURE.md' );
		$code = $this->read( 'sapphireit-editorial-workflow.php' );
		foreach ( array( 'includes', 'admin' ) as $dir ) {
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( SIT_CWM_PLUGIN_DIR . $dir ) ) as $f ) {
				if ( '.php' === substr( (string) $f, -4 ) ) {
					$code .= file_get_contents( (string) $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
				}
			}
		}

		preg_match_all( "/(?:apply_filters|do_action)\(\s*'(sit_cwm_[a-z_]+)'/", $code, $m );
		$this->assertNotEmpty( $m[1] );
		foreach ( array_unique( $m[1] ) as $hook ) {
			$this->assertStringContainsString( "`{$hook}`", $arch, $hook );
		}
	}

	/**
	 * The Getting Started page ships no placeholder demo video.
	 *
	 * @since 1.2.0
	 */
	public function test_getting_started_has_no_demo_video_placeholder() {
		$this->assertStringNotContainsString( 'DEMO_VIDEO_URL', $this->read( 'admin/GettingStartedPage.php' ) );
	}
}
