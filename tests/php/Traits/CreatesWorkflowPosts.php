<?php
/**
 * Six-user fixture for workflow tests.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Traits;

use WP_UnitTest_Factory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates one user per editorial role once per test class: admin, editor,
 * author, contributor, reviewer (an editor, so they hold
 * `sit_cwm_review_content`) and subscriber.
 *
 * Capabilities come from the roles, so the using test must grant the default
 * role capabilities (`TestCase::set_up()` does).
 *
 * @since 1.0.0
 */
trait CreatesWorkflowPosts {

	/**
	 * Fixture user ids by key.
	 *
	 * @var array<string, int>
	 */
	protected static $fixture_users = array();

	/**
	 * Creates the fixture users; runs once before the class's tests.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Name dictated by the WP test library.
		$users = array(
			'admin'       => 'administrator',
			'editor'      => 'editor',
			'author'      => 'author',
			'contributor' => 'contributor',
			'reviewer'    => 'editor',
			'subscriber'  => 'subscriber',
		);

		foreach ( $users as $key => $role ) {
			self::$fixture_users[ $key ] = $factory->user->create(
				array(
					'role'         => $role,
					'display_name' => ucfirst( $key ) . ' Fixture',
				)
			);
		}
	}

	/**
	 * A fixture user id.
	 *
	 * @param string $key `admin`, `editor`, `author`, `contributor`, `reviewer` or `subscriber`.
	 * @return int User id.
	 */
	protected static function fixture_user( string $key ): int {
		return self::$fixture_users[ $key ];
	}
}
