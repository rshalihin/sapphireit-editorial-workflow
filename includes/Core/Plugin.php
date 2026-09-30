<?php
/**
 * Plugin composition root.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Admin\Settings as SettingsPage;
use Sit_Cwm\Content\PostMeta;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Editor\SidebarAssets;
use Sit_Cwm\REST\ActivityController;
use Sit_Cwm\REST\ActivityFormatter;
use Sit_Cwm\REST\BatchController;
use Sit_Cwm\REST\PostsController;
use Sit_Cwm\REST\UserController;
use Sit_Cwm\REST\UserSummaries;
use Sit_Cwm\REST\WorkflowController;
use Sit_Cwm\Workflow\BulkProcessor;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use Sit_Cwm\Workflow\TransitionManager;
use Sit_Cwm\Workflow\WorkflowManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires services into the container and registers them at the right time.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Service ids grouped by the phase at which `Bootable::register()` runs.
	 *
	 * - `core`:  at boot (`plugins_loaded`) on every request. Services hook
	 *            `init` themselves for meta, capability and status registration.
	 * - `admin`: at boot, only when `is_admin()`. Services hook `admin_menu`,
	 *            `admin_enqueue_scripts` and `enqueue_block_editor_assets`.
	 * - `rest`:  on `rest_api_init` (priority 5), so controllers are never built
	 *            on non-REST requests.
	 *
	 * Ids without a registered factory are skipped, so an id may be listed here
	 * before its class lands. Services that are not `Bootable` are skipped too.
	 *
	 * @since 1.0.0
	 * @var array<string, string[]>
	 */
	const BOOT_PHASES = array(
		'core'  => array(
			'status_manager',
			'transition_manager',
			'permission_manager',
			'post_meta',
			'post_repository',
			'activity_logger',
			'workflow_manager',
		),
		'admin' => array(
			'admin.dashboard',
			'admin.settings',
			'editor.sidebar',
		),
		'rest'  => array(
			'rest.workflow',
			'rest.activity',
			'rest.users',
			'rest.posts',
			'rest.batch',
		),
	);

	/**
	 * Service container.
	 *
	 * @since 1.0.0
	 * @var Container
	 */
	private $container;

	/**
	 * Whether `boot()` has already run.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Registers services and attaches plugin-level hooks. Runs once.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();

		// No load_plugin_textdomain(): WordPress.org translations load just in time (WP 4.6+).
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_database' ), 20 );
		add_action( 'rest_api_init', array( $this, 'register_rest_services' ), 5 );

		// Priority 11: after core creates the new site's tables at 10.
		add_action( 'wp_initialize_site', array( Activator::class, 'initialize_site' ), 11 );

		$this->register_phase( 'core' );

		if ( is_admin() ) {
			$this->register_phase( 'admin' );
		}
	}

	/**
	 * Returns the service container.
	 *
	 * @since 1.0.0
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Runs the database schema upgrade check, once the `database` service
	 * exists (step 04).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_upgrade_database(): void {
		if ( $this->container->has( 'database' ) ) {
			$this->container->get( 'database' )->maybe_upgrade();
		}
	}

	/**
	 * Registers the REST controllers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_rest_services(): void {
		$this->register_phase( 'rest' );
	}

	/**
	 * Registers service factories in the container.
	 *
	 * Each later build step adds its entry here, keyed by the ids in
	 * `BOOT_PHASES` (plus non-bootable helpers such as `database` and
	 * `settings`), as a closure receiving the container:
	 *
	 *     $this->container->set(
	 *         'status_manager',
	 *         static function ( Container $c ) {
	 *             return new StatusManager();
	 *         }
	 *     );
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function register_services(): void {
		$this->container->set(
			'database',
			static function () {
				return new Database();
			}
		);

		$this->container->set(
			'settings',
			static function () {
				return new Settings();
			}
		);

		$this->container->set(
			'status_manager',
			static function () {
				return new StatusManager();
			}
		);

		$this->container->set(
			'transition_manager',
			static function ( Container $c ) {
				return new TransitionManager( $c->get( 'status_manager' ) );
			}
		);

		$this->container->set(
			'permission_manager',
			static function ( Container $c ) {
				return new PermissionManager( $c->get( 'post_repository' ), $c->get( 'settings' ) );
			}
		);

		$this->container->set(
			'post_repository',
			static function ( Container $c ) {
				return new PostRepository( $c->get( 'status_manager' ), $c->get( 'settings' ), $c->get( 'activity_logger' ) );
			}
		);

		$this->container->set(
			'activity_logger',
			static function ( Container $c ) {
				return new ActivityLogger( $c->get( 'database' ) );
			}
		);

		$this->container->set(
			'workflow_manager',
			static function ( Container $c ) {
				return new WorkflowManager(
					$c->get( 'status_manager' ),
					$c->get( 'transition_manager' ),
					$c->get( 'permission_manager' ),
					$c->get( 'post_repository' ),
					$c->get( 'activity_logger' )
				);
			}
		);

		$this->container->set(
			'bulk_processor',
			static function ( Container $c ) {
				return new BulkProcessor( $c->get( 'workflow_manager' ) );
			}
		);

		$this->container->set(
			'rest.workflow',
			static function ( Container $c ) {
				return new WorkflowController(
					$c->get( 'workflow_manager' ),
					$c->get( 'permission_manager' ),
					$c->get( 'status_manager' ),
					$c->get( 'transition_manager' ),
					$c->get( 'post_repository' )
				);
			}
		);

		$this->container->set(
			'user_summaries',
			static function () {
				return new UserSummaries();
			}
		);

		$this->container->set(
			'activity_formatter',
			static function ( Container $c ) {
				return new ActivityFormatter( $c->get( 'status_manager' ) );
			}
		);

		$this->container->set(
			'rest.activity',
			static function ( Container $c ) {
				return new ActivityController(
					$c->get( 'workflow_manager' ),
					$c->get( 'permission_manager' ),
					$c->get( 'post_repository' ),
					$c->get( 'activity_logger' ),
					$c->get( 'activity_formatter' ),
					$c->get( 'user_summaries' )
				);
			}
		);

		$this->container->set(
			'rest.users',
			static function ( Container $c ) {
				return new UserController(
					$c->get( 'permission_manager' ),
					$c->get( 'post_repository' ),
					$c->get( 'user_summaries' )
				);
			}
		);

		$this->container->set(
			'rest.posts',
			static function ( Container $c ) {
				return new PostsController(
					$c->get( 'workflow_manager' ),
					$c->get( 'permission_manager' ),
					$c->get( 'status_manager' ),
					$c->get( 'post_repository' ),
					$c->get( 'activity_logger' ),
					$c->get( 'activity_formatter' ),
					$c->get( 'user_summaries' ),
					$c->get( 'settings' )
				);
			}
		);

		$this->container->set(
			'rest.batch',
			static function ( Container $c ) {
				return new BatchController(
					$c->get( 'bulk_processor' ),
					$c->get( 'permission_manager' ),
					$c->get( 'status_manager' ),
					$c->get( 'post_repository' )
				);
			}
		);

		$this->container->set(
			'assets',
			static function ( Container $c ) {
				return new Assets(
					$c->get( 'status_manager' ),
					$c->get( 'permission_manager' ),
					SIT_CWM_PLUGIN_DIR . 'assets/build/',
					SIT_CWM_PLUGIN_URL . 'assets/build/',
					SIT_CWM_PLUGIN_DIR . 'languages'
				);
			}
		);

		$this->container->set(
			'editor.sidebar',
			static function ( Container $c ) {
				return new SidebarAssets( $c->get( 'assets' ), $c->get( 'permission_manager' ) );
			}
		);

		$this->container->set(
			'admin.dashboard',
			static function ( Container $c ) {
				return new Dashboard( $c->get( 'assets' ), $c->get( 'permission_manager' ) );
			}
		);

		$this->container->set(
			'admin.settings',
			static function ( Container $c ) {
				return new SettingsPage( $c->get( 'settings' ), $c->get( 'permission_manager' ) );
			}
		);

		$this->container->set(
			'post_meta',
			static function ( Container $c ) {
				return new PostMeta(
					$c->get( 'status_manager' ),
					$c->get( 'permission_manager' ),
					$c->get( 'post_repository' ),
					$c->get( 'settings' )
				);
			}
		);
	}

	/**
	 * Calls `register()` on every bootable service in a phase.
	 *
	 * @since 1.0.0
	 *
	 * @param string $phase Key of `BOOT_PHASES`.
	 * @return void
	 */
	private function register_phase( string $phase ): void {
		foreach ( self::BOOT_PHASES[ $phase ] as $id ) {
			if ( ! $this->container->has( $id ) ) {
				continue;
			}

			$service = $this->container->get( $id );

			if ( $service instanceof Bootable ) {
				$service->register();
			}
		}
	}
}
