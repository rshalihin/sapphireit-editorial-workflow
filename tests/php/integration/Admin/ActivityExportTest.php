<?php
/**
 * Integration tests for the activity export handler.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\ActivityExport;
use Sit_Cwm\Audit\ActivityExporter;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\REST\ActivityFormatter;
use Sit_Cwm\REST\UserSummaries;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Tests\Traits\RestoresPluginInstance;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WPDieException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nonce, then `can_audit()`, then the stream; and the form's visibility (F8).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Admin\ActivityExport
 * @covers \Sit_Cwm\Workflow\PermissionManager::can_audit
 */
final class ActivityExportTest extends TestCase {

	use CreatesWorkflowPosts;
	use RestoresPluginInstance;

	/**
	 * Handler under test, with headers and exit recorded instead of sent.
	 *
	 * @var ActivityExport
	 */
	private $export;

	/**
	 * Builds the handler.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->remember_plugin_instance();

		$settings    = new Settings();
		$permissions = new PermissionManager( $this->posts, $settings );
		$exporter    = new ActivityExporter( $this->activity, new ActivityFormatter( new StatusManager() ), new UserSummaries(), 2 );

		$this->export = new class( $permissions, $exporter ) extends ActivityExport {

			/**
			 * Filenames passed to `send_headers()`.
			 *
			 * @var string[]
			 */
			public $sent = array();

			/**
			 * Times `finish()` ran.
			 *
			 * @var int
			 */
			public $finished = 0;

			/**
			 * Records the filename instead of sending headers.
			 *
			 * @param string $filename Download filename.
			 * @return void
			 */
			protected function send_headers( string $filename ): void {
				$this->sent[] = $filename;
			}

			/**
			 * Records the end of the response instead of exiting.
			 *
			 * @return void
			 */
			protected function finish(): void {
				++$this->finished;
			}
		};
	}

	/**
	 * Clears the request superglobals.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_POST[ ActivityExport::NONCE_FIELD ], $_REQUEST[ ActivityExport::NONCE_FIELD ] );
		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * `register()` hooks the admin-post action.
	 *
	 * @return void
	 */
	public function test_register_hooks_admin_post_action() {
		$this->export->register();

		$this->assertSame( 10, has_action( 'admin_post_' . ActivityExport::ACTION, array( $this->export, 'handle' ) ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_' . ActivityExport::ACTION ) );
	}

	/**
	 * No nonce, or a nonce for another action, dies with 403 before anything
	 * is sent, even for an auditor.
	 *
	 * @return void
	 */
	public function test_missing_or_bad_nonce_dies_with_403() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->assert_dies_with_403();

		$this->set_nonce( wp_create_nonce( 'sit_cwm_something_else' ) );
		$this->assert_dies_with_403();
	}

	/**
	 * A valid nonce is not authorization.
	 *
	 * @return void
	 */
	public function test_non_auditor_dies_with_403() {
		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->set_nonce( wp_create_nonce( ActivityExport::ACTION ) );

		$this->assert_dies_with_403();
	}

	/**
	 * An auditor with a valid nonce gets the CSV.
	 *
	 * @return void
	 */
	public function test_auditor_gets_csv_headers_and_body() {
		$p = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->activity->log_comment( $p, "note {$i}", self::fixture_user( 'editor' ) );
		}

		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->set_nonce( wp_create_nonce( ActivityExport::ACTION ) );

		ob_start();
		$this->export->handle();
		$body = ob_get_clean();

		$this->assertCount( 1, $this->export->sent );
		$this->assertMatchesRegularExpression( '/^sit-cwm-activity-\d{8}-\d{6}\.csv$/', $this->export->sent[0] );
		$this->assertSame( 1, $this->export->finished );

		$header = "\xEF\xBB\xBF" . implode( ',', ActivityExporter::COLUMNS ) . "\n";

		$this->assertStringStartsWith( $header, $body );
		$this->assertSame( 4, substr_count( $body, "\n" ) );
	}

	/**
	 * The form shows to auditors only, and posts to admin-post.php with the
	 * action and nonce.
	 *
	 * @return void
	 */
	public function test_form_renders_only_for_auditors() {
		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->assertSame( '', $this->export->render_form() );

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->export->render_form() );

		wp_set_current_user( self::fixture_user( 'admin' ) );
		$html = $this->export->render_form();

		$this->assertStringContainsString( 'method="post"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="sit_cwm_export_activity"', $html );
		$this->assertStringContainsString( 'name="' . ActivityExport::NONCE_FIELD . '" value="' . wp_create_nonce( ActivityExport::ACTION ) . '"', $html );
		$this->assertStringContainsString( 'Export activity log (CSV)', $html );
	}

	/**
	 * The container builds the handler for the admin phase.
	 *
	 * @return void
	 */
	public function test_container_wires_export() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( ActivityExport::class, $plugin->container()->get( 'admin.export' ) );
		$this->assertInstanceOf( ActivityExporter::class, $plugin->container()->get( 'activity_exporter' ) );
		$this->assertContains( 'admin.export', Plugin::BOOT_PHASES['admin'] );
	}

	/**
	 * Puts a nonce on the request, as the form would.
	 *
	 * @param string $nonce Nonce.
	 * @return void
	 */
	private function set_nonce( string $nonce ): void {
		$_POST[ ActivityExport::NONCE_FIELD ]    = $nonce;
		$_REQUEST[ ActivityExport::NONCE_FIELD ] = $nonce;
	}

	/**
	 * Asserts `handle()` dies with 403 and sends nothing.
	 *
	 * @return void
	 */
	private function assert_dies_with_403(): void {
		ob_start();

		try {
			$this->export->handle();
			$this->fail( 'handle() did not die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		} finally {
			$output = ob_get_clean();
		}

		$this->assertSame( '', $output );
		$this->assertSame( array(), $this->export->sent );
		$this->assertSame( 0, $this->export->finished );
	}
}
