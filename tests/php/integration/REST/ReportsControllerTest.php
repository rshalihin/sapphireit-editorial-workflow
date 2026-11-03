<?php
/**
 * Integration tests for the governance report REST controller.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Governance\GovernanceReport;
use Sit_Cwm\REST\ReportsController;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Tests\Traits\RestoresPluginInstance;
use Sit_Cwm\Workflow\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/reports/governance`, dispatched through the REST server.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\REST\ReportsController
 */
final class ReportsControllerTest extends TestCase {

	use CreatesWorkflowPosts;
	use RestoresPluginInstance;

	/**
	 * Route under test.
	 *
	 * @var string
	 */
	const ROUTE = '/sit-cwm/v1/reports/governance';

	/**
	 * Remembers the plugin instance.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remember_plugin_instance();
	}

	/**
	 * Restores the plugin instance.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * Logged out is 401; a user who cannot audit is 403.
	 *
	 * @return void
	 */
	public function test_requires_login_and_audit_permission() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest_request( 'GET', self::ROUTE )->get_status() );

		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->assert_error_response( $this->rest_request( 'GET', self::ROUTE ), 'sit_cwm_forbidden', 403 );
	}

	/**
	 * Managing workflows alone is not enough: auditing also needs
	 * `sit_cwm_view_activity`.
	 *
	 * @return void
	 */
	public function test_manage_without_view_activity_is_forbidden() {
		$user = $this->create_user_with_caps( array( Capabilities::MANAGE_WORKFLOWS ) );

		wp_set_current_user( $user );

		$this->assertSame( 403, $this->rest_request( 'GET', self::ROUTE )->get_status() );
	}

	/**
	 * Administrators get the report: 90 days by default, 30/90/365 on
	 * request, and any other period is a 400.
	 *
	 * @return void
	 */
	public function test_admin_gets_report_with_default_and_explicit_period() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->assertSame( 90, $this->rest_request( 'GET', self::ROUTE )->get_data()['period_days'] );

		foreach ( GovernanceReport::PERIODS as $period ) {
			$response = $this->rest_request( 'GET', self::ROUTE, null, array( 'period' => $period ) );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $period, $response->get_data()['period_days'] );
		}

		$this->assertSame( 400, $this->rest_request( 'GET', self::ROUTE, null, array( 'period' => 7 ) )->get_status() );
	}

	/**
	 * The route has a real permission callback and the container builds the
	 * controller and the service.
	 *
	 * @return void
	 */
	public function test_route_and_container_wiring() {
		$routes = rest_get_server()->get_routes( 'sit-cwm/v1' );

		$this->assertArrayHasKey( '/sit-cwm/v1/reports/governance', $routes );

		foreach ( $routes['/sit-cwm/v1/reports/governance'] as $handler ) {
			$this->assertNotEmpty( $handler['permission_callback'] );
			$this->assertNotSame( '__return_true', $handler['permission_callback'] );
		}

		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( ReportsController::class, $plugin->container()->get( 'rest.reports' ) );
		$this->assertInstanceOf( GovernanceReport::class, $plugin->container()->get( 'governance_report' ) );
		$this->assertContains( 'rest.reports', Plugin::BOOT_PHASES['rest'] );
	}
}
