<?php
/**
 * Minimal service container.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lazy, memoising service locator.
 *
 * Each service is described by a factory closure that receives the container,
 * so services can resolve their own dependencies. A factory runs at most once;
 * later `get()` calls return the same instance.
 *
 * @since 1.0.0
 */
final class Container {

	/**
	 * Service factories keyed by service id.
	 *
	 * @since 1.0.0
	 * @var array<string, callable>
	 */
	private $factories = array();

	/**
	 * Resolved service instances keyed by service id.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * Registers (or replaces) a service factory.
	 *
	 * Replacing a factory discards any instance already built from the old one.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $id      Service id, e.g. `workflow_manager`.
	 * @param callable $factory Receives this container, returns the service.
	 * @return void
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Returns a service, building it on first access.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Service id.
	 * @return mixed The service instance.
	 *
	 * @throws InvalidArgumentException When no factory is registered for `$id`.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! $this->has( $id ) ) {
			throw new InvalidArgumentException(
				sprintf( 'SapphireIT Editorial Workflow: no service registered with id "%s".', $id ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message, never rendered as HTML.
			);
		}

		$this->instances[ $id ] = call_user_func( $this->factories[ $id ], $this );

		return $this->instances[ $id ];
	}

	/**
	 * Whether a factory is registered for a service id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
