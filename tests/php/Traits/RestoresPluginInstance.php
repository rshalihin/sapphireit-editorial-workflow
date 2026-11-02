<?php
/**
 * Plugin singleton isolation for tests that boot their own plugin.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Traits;

use ReflectionProperty;
use Sit_Cwm\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booting a `Plugin` replaces `Plugin::instance()`, which `sit_cwm_container()`
 * reads. WP_UnitTestCase restores hooks after each test, so REST and every
 * other hook keep using the bootstrap's plugin; a leaked instance would hand
 * later tests a different container than the one serving requests.
 *
 * The using test calls `remember_plugin_instance()` in `set_up()` and
 * `restore_plugin_instance()` in `tear_down()`.
 *
 * @since 1.2.0
 */
trait RestoresPluginInstance {

	/**
	 * The plugin instance before the test ran.
	 *
	 * @var Plugin|null
	 */
	private $remembered_plugin;

	/**
	 * Remembers the current plugin instance.
	 *
	 * @return void
	 */
	protected function remember_plugin_instance(): void {
		$this->remembered_plugin = Plugin::instance();
	}

	/**
	 * Puts the remembered plugin instance back.
	 *
	 * @return void
	 */
	protected function restore_plugin_instance(): void {
		$property = new ReflectionProperty( Plugin::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->remembered_plugin );
	}
}
