<?php
/**
 * Integration tests for the classic editor workflow meta box.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\Editor;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Editor\ClassicMetaBox;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_Post;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The meta box and its bundle load only in the classic editor, for an enabled
 * post type and a user who may edit the post.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Editor\ClassicMetaBox
 */
final class ClassicMetaBoxTest extends WP_UnitTestCase {

	/**
	 * Script handle of the classic bundle.
	 *
	 * @var string
	 */
	const SCRIPT = 'sit-cwm-classic-js';

	/**
	 * Style handle of the classic bundle.
	 *
	 * @var string
	 */
	const STYLE = 'sit-cwm-classic-css';

	/**
	 * Service under test.
	 *
	 * @var ClassicMetaBox
	 */
	private $meta_box;

	/**
	 * Loads `add_meta_box()`, `use_block_editor_for_post()` and
	 * `get_default_post_to_edit()`, which only exist in wp-admin.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
	}

	/**
	 * Resets registries, grants capabilities, forces the classic editor and
	 * builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_dependencies();
		$GLOBALS['wp_meta_boxes'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resets core's meta box registry.
		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		add_filter( 'use_block_editor_for_post', '__return_false' );

		$statuses    = new StatusManager();
		$settings    = new Settings();
		$permissions = new PermissionManager( new PostRepository( $statuses, $settings ), $settings );
		$assets      = new Assets(
			$statuses,
			$permissions,
			dirname( __DIR__, 2 ) . '/fixtures/build',
			'http://example.org/build/',
			'/languages'
		);

		$this->meta_box = new ClassicMetaBox( $assets, $permissions, $settings );
	}

	/**
	 * Resets registries, filters and the global post.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'use_block_editor_for_post', '__return_false' );
		$this->reset_dependencies();
		$GLOBALS['wp_meta_boxes'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Resets core's meta box registry.
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * `register()` hooks the meta box and the admin enqueue.
	 *
	 * @return void
	 */
	public function test_register_hooks_meta_box_and_enqueue() {
		$this->meta_box->register();

		$this->assertSame( 10, has_action( 'add_meta_boxes', array( $this->meta_box, 'add_meta_box' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->meta_box, 'enqueue' ) ) );
	}

	/**
	 * Classic editor, enabled type, editor: the box is in the side column at
	 * high priority, hidden from the block editor, and the bundle loads.
	 *
	 * @return void
	 */
	public function test_adds_box_and_enqueues_in_classic_editor() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->edit_post( $editor, $this->make_post( 'post', $editor ) );

		$box = $this->meta_box_entry( 'post' );

		$this->assertIsArray( $box );
		$this->assertSame( 'Editorial Workflow', $box['title'] );
		$this->assertSame( array( $this->meta_box, 'render' ), $box['callback'] );
		$this->assertTrue( $box['args']['__back_compat_meta_box'] );

		$this->assertTrue( wp_script_is( self::SCRIPT, 'enqueued' ) );
		$this->assertTrue( wp_style_is( self::STYLE, 'enqueued' ) );
		$this->assertContains( 'wp-dom-ready', wp_scripts()->registered[ self::SCRIPT ]->deps );
	}

	/**
	 * `post-new.php` enqueues just like `post.php`.
	 *
	 * @return void
	 */
	public function test_enqueues_on_post_new_screen() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->edit_post( $editor, $this->make_post( 'post', $editor ), 'post-new.php' );

		$this->assertTrue( wp_script_is( self::SCRIPT, 'enqueued' ) );
	}

	/**
	 * The block editor has the sidebar, so no box and no bundle there.
	 *
	 * @return void
	 */
	public function test_skips_block_editor() {
		remove_filter( 'use_block_editor_for_post', '__return_false' );

		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = $this->make_post( 'post', $editor );

		$this->assertTrue( use_block_editor_for_post( $post_id ), 'Precondition: posts use the block editor.' );

		$this->edit_post( $editor, $post_id );

		$this->assert_not_loaded( 'post' );
	}

	/**
	 * Post types that are not workflow-enabled get neither.
	 *
	 * @return void
	 */
	public function test_skips_unmanaged_post_type() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->edit_post( $admin, $this->make_post( 'page', $admin ) );

		$this->assert_not_loaded( 'page' );
	}

	/**
	 * A subscriber cannot edit the post, so gets neither.
	 *
	 * @return void
	 */
	public function test_skips_subscriber() {
		$post_id = $this->make_post( 'post', self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->edit_post( self::factory()->user->create( array( 'role' => 'subscriber' ) ), $post_id );

		$this->assert_not_loaded( 'post' );
	}

	/**
	 * An author cannot edit someone else's post, so gets neither.
	 *
	 * @return void
	 */
	public function test_skips_author_on_other_users_post() {
		$post_id = $this->make_post( 'post', self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->edit_post( self::factory()->user->create( array( 'role' => 'author' ) ), $post_id );

		$this->assert_not_loaded( 'post' );
	}

	/**
	 * Other admin screens never load the bundle, even with a post in scope.
	 *
	 * @dataProvider data_other_screens
	 *
	 * @param string $hook_suffix Hook suffix of the admin screen.
	 * @return void
	 */
	public function test_skips_other_screens( string $hook_suffix ) {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		wp_set_current_user( $editor );
		$GLOBALS['post'] = get_post( $this->make_post( 'post', $editor ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates a post in scope.

		$this->meta_box->enqueue( $hook_suffix );

		$this->assertFalse( wp_script_is( self::SCRIPT, 'registered' ) );
	}

	/**
	 * Admin screens other than the post editor.
	 *
	 * @return array<string, string[]>
	 */
	public function data_other_screens() {
		return array(
			'posts list' => array( 'edit.php' ),
			'dashboard'  => array( 'index.php' ),
		);
	}

	/**
	 * No post in scope: no bundle, and no box for a non-post object.
	 *
	 * @return void
	 */
	public function test_skips_without_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $GLOBALS['post'] );

		$this->meta_box->enqueue( 'post.php' );
		$this->meta_box->add_meta_box( 'comment', new \stdClass() );

		$this->assertFalse( wp_script_is( self::SCRIPT, 'registered' ) );
		$this->assertNull( $this->meta_box_entry( 'comment' ) );
	}

	/**
	 * The `post-new.php` auto-draft gets the box and the bundle.
	 *
	 * @return void
	 */
	public function test_auto_draft_gets_box_and_bundle() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$post = get_default_post_to_edit( 'post', true );

		$this->assertSame( 'auto-draft', $post->post_status );

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates post-new.php.

		$this->meta_box->add_meta_box( 'post', $post );
		$this->meta_box->enqueue( 'post-new.php' );

		$this->assertIsArray( $this->meta_box_entry( 'post' ) );
		$this->assertTrue( wp_script_is( self::SCRIPT, 'enqueued' ) );
	}

	/**
	 * `render()` prints the mount point with the post's id and type, and the
	 * no-JS fallback.
	 *
	 * @return void
	 */
	public function test_render_prints_mount_point() {
		$post = get_post( $this->make_post( 'post', self::factory()->user->create( array( 'role' => 'editor' ) ) ) );

		$html = get_echo( array( $this->meta_box, 'render' ), array( $post ) );

		$this->assertStringContainsString( 'id="sit-cwm-classic-root"', $html );
		$this->assertStringContainsString( 'data-post-id="' . $post->ID . '"', $html );
		$this->assertStringContainsString( 'data-post-type="post"', $html );
		$this->assertStringContainsString( '<p class="hide-if-js">The editorial workflow needs JavaScript.</p>', $html );
	}

	/**
	 * `render()` escapes the attributes it prints.
	 *
	 * @return void
	 */
	public function test_render_escapes_attributes() {
		$post = new WP_Post(
			(object) array(
				'ID'        => 7,
				'post_type' => 'x"><script>alert(1)</script>',
			)
		);

		$html = get_echo( array( $this->meta_box, 'render' ), array( $post ) );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'data-post-type="x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html );
	}

	/**
	 * Runs the meta box hook and the enqueue as a user editing a post.
	 *
	 * @param int    $user_id     User id.
	 * @param int    $post_id     Post id.
	 * @param string $hook_suffix Admin screen hook suffix.
	 * @return void
	 */
	private function edit_post( int $user_id, int $post_id, string $hook_suffix = 'post.php' ) {
		wp_set_current_user( $user_id );

		$post            = get_post( $post_id );
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the post being edited.

		$this->meta_box->add_meta_box( $post->post_type, $post );
		$this->meta_box->enqueue( $hook_suffix );
	}

	/**
	 * Asserts that neither the box nor the bundle loaded.
	 *
	 * @param string $post_type Post type screen.
	 * @return void
	 */
	private function assert_not_loaded( string $post_type ) {
		$this->assertNull( $this->meta_box_entry( $post_type ), 'The meta box must not be registered.' );
		$this->assertFalse( wp_script_is( self::SCRIPT, 'registered' ), 'The classic bundle must not load.' );
		$this->assertFalse( wp_style_is( self::STYLE, 'registered' ), 'The classic stylesheet must not load.' );
	}

	/**
	 * The registered meta box for a screen, if any.
	 *
	 * @param string $screen Screen id (the post type).
	 * @return array|null
	 */
	private function meta_box_entry( string $screen ) {
		global $wp_meta_boxes;

		return $wp_meta_boxes[ $screen ]['side']['high'][ ClassicMetaBox::META_BOX_ID ] ?? null;
	}

	/**
	 * Creates a draft.
	 *
	 * @param string $post_type Post type.
	 * @param int    $author_id Author user id.
	 * @return int Post id.
	 */
	private function make_post( string $post_type, int $author_id ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * Removes every plugin script and style (and their inline data).
	 *
	 * @return void
	 */
	private function reset_dependencies() {
		foreach ( Assets::ENTRIES as $entry ) {
			wp_dequeue_script( 'sit-cwm-' . $entry . '-js' );
			wp_deregister_script( 'sit-cwm-' . $entry . '-js' );
			wp_dequeue_style( 'sit-cwm-' . $entry . '-css' );
			wp_deregister_style( 'sit-cwm-' . $entry . '-css' );
		}
	}
}
