<?php
/**
 * Integration tests for the Getting Started screen.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\ActivityExport;
use Sit_Cwm\Admin\AdminShell;
use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Admin\GettingStartedPage;
use Sit_Cwm\Admin\Settings as SettingsPage;
use Sit_Cwm\Audit\ActivityExporter;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\REST\ActivityFormatter;
use Sit_Cwm\REST\UserSummaries;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Tests\Traits\RestoresPluginInstance;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WPDieException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu placement, access, the setup steps and the help links.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Admin\GettingStartedPage
 */
final class GettingStartedPageTest extends TestCase {

	use CreatesWorkflowPosts;
	use RestoresPluginInstance;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $store;

	/**
	 * Workflow authorization.
	 *
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Status registry.
	 *
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Shared header.
	 *
	 * @var AdminShell
	 */
	private $shell;

	/**
	 * Service under test.
	 *
	 * @var GettingStartedPage
	 */
	private $page;

	/**
	 * Builds the page.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->remember_plugin_instance();
		$this->reset_admin_state();

		$this->statuses    = new StatusManager();
		$this->store       = new Settings();
		$this->permissions = new PermissionManager( $this->posts, $this->store );
		$this->shell       = new AdminShell( new Assets( $this->statuses, $this->permissions, '', '', '' ) );
		$this->page        = new GettingStartedPage( $this->shell, $this->permissions, $this->store, $this->statuses );
	}

	/**
	 * Restores global admin state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_admin_state();
		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * The menu hooks in after the other pages.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_menu_late() {
		$this->page->register();

		$this->assertSame( GettingStartedPage::MENU_PRIORITY, has_action( 'admin_menu', array( $this->page, 'add_menu' ) ) );
		$this->assertGreaterThan( 11, GettingStartedPage::MENU_PRIORITY );
	}

	/**
	 * Getting Started is the last Workflow submenu item, gated like the dashboard.
	 *
	 * @return void
	 */
	public function test_menu_is_last_under_workflow() {
		global $submenu;

		wp_set_current_user( self::fixture_user( 'admin' ) );

		$assets      = new Assets( $this->statuses, $this->permissions, '', '', '' );
		$export      = new ActivityExport( $this->permissions, new ActivityExporter( $this->activity, new ActivityFormatter( $this->statuses ), new UserSummaries() ) );
		$dashboard   = new Dashboard( $assets, $this->permissions, $export, $this->shell );
		$settings_ui = new SettingsPage( $this->store, $this->permissions, $this->shell );

		$dashboard->add_menu();
		$settings_ui->add_menu();
		$this->page->add_menu();

		$items = $submenu[ Dashboard::MENU_SLUG ];
		$last  = end( $items );

		$this->assertSame( 'Getting Started', $last[0] );
		$this->assertSame( Capabilities::VIEW_ACTIVITY, $last[1] );
		$this->assertSame( GettingStartedPage::MENU_SLUG, $last[2] );
		$this->assertSame( admin_url( 'admin.php?page=sit-cwm-getting-started' ), GettingStartedPage::url() );
	}

	/**
	 * Users who cannot open the dashboard get no menu and a 403 on render.
	 *
	 * @return void
	 */
	public function test_render_dies_without_view_activity() {
		global $submenu;

		wp_set_current_user( self::fixture_user( 'subscriber' ) );

		$this->page->add_menu();
		$this->assertEmpty( $submenu[ Dashboard::MENU_SLUG ] ?? array() );

		$this->expectException( WPDieException::class );

		$this->page->render();
	}

	/**
	 * Editors get the help but not the setup steps.
	 *
	 * @return void
	 */
	public function test_editor_sees_help_but_not_setup_steps() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$html = $this->render_page();

		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Getting Started</h1>', $html );
		$this->assertStringContainsString( 'Set up the workflow, learn it from short videos, and find help when you need it.', $html );
		$this->assertStringContainsString( 'Learn and get help', $html );
		$this->assertStringContainsString( 'How content moves', $html );
		$this->assertStringNotContainsString( 'Set up your editorial workflow', $html );
		$this->assertStringNotContainsString( 'Choose content types', $html );
	}

	/**
	 * The page prints the shell header before its heading.
	 *
	 * @return void
	 */
	public function test_render_prints_the_shell_header_first() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$GLOBALS['plugin_page'] = GettingStartedPage::MENU_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

		$this->page->add_menu();
		$html = $this->render_page();

		$header = strpos( $html, 'class="sit-cwm-shell"' );

		$this->assertStringStartsWith( '<div class="wrap sit-cwm-getting-started">', $html );
		$this->assertNotFalse( $header );
		$this->assertLessThan( strpos( $html, '<h1' ), $header );
		$this->assertStringContainsString( '<hr class="wp-header-end">', $html );
	}

	/**
	 * Each step is worked out from the real settings and content.
	 *
	 * @return void
	 */
	public function test_setup_steps_follow_real_state() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->store->update( array( 'post_types' => array() ) );
		$html = $this->render_page();
		$this->assertStringContainsString( '0 of 3 done', $html );
		$this->assertStringContainsString( 'role="img" aria-label="0 of 3 done"', $html );
		$this->assertStringContainsString( 'Pick the content types that go through the workflow.', $html );

		$this->store->update(
			array(
				'post_types'   => array( 'post', 'page' ),
				'publish_gate' => array( 'post' => 'off' ),
			)
		);
		$html = $this->render_page();
		$this->assertStringContainsString( '1 of 3 done', $html );
		$this->assertStringContainsString( 'Posts and Pages are in the workflow.', $html );
		$this->assertStringContainsString( 'The publish gate is Off: nothing changes until you choose Log only or Enforce.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'post-new.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'Write a post', $html );

		$this->store->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
		$html = $this->render_page();
		$this->assertStringContainsString( '2 of 3 done', $html );
		$this->assertStringContainsString( 'The publish gate is on for at least one content type.', $html );
		$this->assertStringContainsString( '#sit-cwm-publish-gate', $html );

		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $post_id, PostRepository::META_STATUS, 'writing' );

		$html = $this->render_page();
		$this->assertStringContainsString( '3 of 3 done', $html );
		$this->assertStringContainsString( 'Content is moving through the workflow.', $html );
		$this->assertStringNotContainsString( 'Write a post', $html );
	}

	/**
	 * A status on a post of a type that is not enabled does not count.
	 *
	 * @return void
	 */
	public function test_first_post_step_ignores_disabled_types() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->store->update( array( 'post_types' => array( 'page' ) ) );

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		update_post_meta( $post_id, PostRepository::META_STATUS, 'writing' );

		$html = $this->render_page();

		$this->assertStringContainsString( '1 of 3 done', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'post-new.php?post_type=page' ) ) . '"', $html );
	}

	/**
	 * Renders a page whose URL constants and lists are all filled in.
	 *
	 * @return string Page markup.
	 */
	private function render_page_with_urls() {
		$page = new class( $this->shell, $this->permissions, $this->store, $this->statuses ) extends GettingStartedPage {
			const TOUR_URL    = 'https://example.com/tour';
			const VIDEOS_URL  = 'https://example.com/videos';
			const DOCS_URL    = 'https://example.com/docs';
			const WEBSITE_URL = 'https://example.com/';

			/**
			 * One tutorial.
			 *
			 * @return array
			 */
			protected function tutorials(): array {
				return array(
					array(
						'title'    => 'Set up the publish gate',
						'duration' => '2:10',
						'url'      => 'https://example.com/videos/gate',
					),
				);
			}

			/**
			 * One doc.
			 *
			 * @return array
			 */
			protected function docs(): array {
				return array(
					array(
						'title' => 'Publish gate',
						'url'   => 'https://example.com/docs/gate',
					),
				);
			}
		};

		ob_start();
		$page->render();

		return (string) ob_get_clean();
	}

	/**
	 * Filled-in URL constants and lists show their cards and links.
	 *
	 * @return void
	 */
	public function test_url_constants_show_their_cards() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$html = $this->render_page_with_urls();

		foreach ( array( 'Watch the video tour', 'Video tutorials', 'Documentation', 'Plugin website', 'Set up the publish gate', 'Publish gate' ) as $text ) {
			$this->assertStringContainsString( $text, $html );
		}
	}

	/**
	 * Empty URL constants and lists hide their cards and links.
	 *
	 * @return void
	 */
	public function test_empty_url_constants_hide_their_cards() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$page = new class( $this->shell, $this->permissions, $this->store, $this->statuses ) extends GettingStartedPage {
			const TOUR_URL    = '';
			const VIDEOS_URL  = '';
			const DOCS_URL    = '';
			const WEBSITE_URL = '';

			/**
			 * No tutorials.
			 *
			 * @return array
			 */
			protected function tutorials(): array {
				return array();
			}

			/**
			 * No docs.
			 *
			 * @return array
			 */
			protected function docs(): array {
				return array();
			}
		};

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Watch the video tour', $html );
		$this->assertStringNotContainsString( 'Video tutorials', $html );
		$this->assertStringNotContainsString( 'Documentation', $html );
		$this->assertStringNotContainsString( 'Plugin website', $html );
		$this->assertStringContainsString( 'Support forum', $html );
		$this->assertStringNotContainsString( 'href=""', $html );
	}

	/**
	 * Every external link opens in a new tab and says so to screen readers.
	 *
	 * @return void
	 */
	public function test_external_links_open_in_new_tab_with_screen_reader_text() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$html = $this->render_page_with_urls();

		preg_match_all( '/<a\s[^>]*href="(https?:\/\/[^"]+)"[^>]*>(.*?)<\/a>/s', $html, $links, PREG_SET_ORDER );

		$external = array_filter(
			$links,
			static function ( $link ) {
				return 0 !== strpos( $link[1], admin_url() );
			}
		);

		$this->assertNotEmpty( $external );

		foreach ( $external as $link ) {
			$this->assertStringContainsString( 'target="_blank"', $link[0], $link[1] );
			$this->assertStringContainsString( 'rel="noopener noreferrer"', $link[0], $link[1] );
			$this->assertStringContainsString( '<span class="screen-reader-text"> (opens in a new tab)</span>', $link[0], $link[1] );
		}

		foreach ( array( GettingStartedPage::SUPPORT_URL, 'https://example.com/', 'https://example.com/docs', 'https://example.com/tour' ) as $url ) {
			$this->assertStringContainsString( 'href="' . esc_url( $url ) . '"', $html );
		}
	}

	/**
	 * Rendering makes no HTTP request: no embed, no remote image.
	 *
	 * @return void
	 */
	public function test_page_makes_no_remote_request() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$fail = function ( $pre, $args, $url ) {
			$this->fail( 'Unexpected HTTP request to ' . $url );
		};

		add_filter( 'pre_http_request', $fail, 10, 3 );

		$html = $this->render_page();

		remove_filter( 'pre_http_request', $fail, 10 );

		$this->assertStringNotContainsString( '<iframe', $html );
		$this->assertStringNotContainsString( 'ytimg', $html );
		$this->assertDoesNotMatchRegularExpression( '/<img[^>]+src="https?:\/\/(?!example\.org)/', $html );
	}

	/**
	 * "How content moves" lists the core statuses with their labels and colours.
	 *
	 * @return void
	 */
	public function test_how_content_moves_uses_the_status_registry() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$html = $this->render_page();

		foreach ( array( 'draft', 'writing', 'review', 'approved', 'published' ) as $slug ) {
			$status = $this->statuses->get( $slug );
			$this->assertStringContainsString( esc_html( $status['label'] ), $html, $slug );
			$this->assertStringContainsString( esc_attr( $status['color'] ), $html, $slug );
		}

		$draft     = strpos( $html, 'Any author' );
		$published = strpos( $html, 'It goes live' );

		$this->assertNotFalse( $draft );
		$this->assertLessThan( $published, $draft );
		$this->assertStringContainsString( 'Needs Changes: a reviewer sends it back to the author, who returns it to Review.', $html );
	}

	/**
	 * The container builds the page for the admin phase, after the shell.
	 *
	 * @return void
	 */
	public function test_container_wires_page() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( GettingStartedPage::class, $plugin->container()->get( 'admin.getting_started' ) );
		$this->assertContains( 'admin.getting_started', Plugin::BOOT_PHASES['admin'] );
	}

	/**
	 * Buffers `render()`.
	 *
	 * @return string
	 */
	private function render_page(): string {
		ob_start();
		$this->page->render();

		return (string) ob_get_clean();
	}

	/**
	 * Resets menu globals, the current screen and `$plugin_page`.
	 *
	 * @return void
	 */
	private function reset_admin_state() {
		global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages, $current_screen, $plugin_page;

		$menu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$submenu           = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$admin_page_hooks  = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$_parent_pages     = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$current_screen    = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates the current screen between tests.
		$plugin_page       = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates the requested page between tests.
	}
}
