<?php
/**
 * Bootable service contract.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A service that attaches WordPress hooks.
 *
 * Constructors of implementing classes must never hook WordPress; all hook
 * registration happens in `register()`. This keeps every service constructible
 * in unit tests without WordPress loaded.
 *
 * @since 1.0.0
 */
interface Bootable {

	/**
	 * Attaches the service's WordPress hooks.
	 *
	 * Called once per request by `Sit_Cwm\Core\Plugin`, at the phase the
	 * service is wired to (see `Plugin::BOOT_PHASES`).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void;
}
