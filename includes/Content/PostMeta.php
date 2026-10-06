<?php
/**
 * Workflow post meta registration.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Content;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the workflow meta keys (D6) with type, sanitizer, authorization
 * and REST schema, once per workflow-enabled post type.
 *
 * Registration is per post type rather than global, so disabling a post type
 * in settings removes the fields from its REST schema. Core only exposes
 * `meta` in REST for post types that support `custom-fields`.
 *
 * All three fields are REST-visible in the `edit` context only: reviewer and
 * due date are internal editorial data and must not leak through the public
 * `view` context of published posts.
 *
 * @since 1.0.0
 */
final class PostMeta implements Bootable {

	/**
	 * Meta capabilities that must never allow writing the workflow status.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const STATUS_LOCKED_CAPS = array( 'add_post_meta', 'edit_post_meta', 'delete_post_meta' );

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Authorization layer.
	 *
	 * @since 1.0.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Meta persistence and shared sanitizers.
	 *
	 * @since 1.0.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Plugin settings.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param StatusManager     $statuses    Status registry.
	 * @param PermissionManager $permissions Authorization layer.
	 * @param PostRepository    $posts       Meta persistence and sanitizers.
	 * @param Settings          $settings    Plugin settings.
	 */
	public function __construct( StatusManager $statuses, PermissionManager $permissions, PostRepository $posts, Settings $settings ) {
		$this->statuses    = $statuses;
		$this->permissions = $permissions;
		$this->posts       = $posts;
		$this->settings    = $settings;
	}

	/**
	 * Attaches meta registration and the status write lock.
	 *
	 * Registration runs at `init` priority 20 so post types, status filters
	 * and enabled-post-type filters added at the default priority are in place.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_filter( 'map_meta_cap', array( $this, 'lock_status_meta' ), 10, 4 );
	}

	/**
	 * Registers the three workflow meta keys for every enabled post type.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( $this->settings->enabled_post_types() as $post_type ) {
			$this->register_for_post_type( $post_type );
		}
	}

	/**
	 * Registers the workflow meta keys for one post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type slug.
	 * @return void
	 */
	public function register_for_post_type( string $post_type ): void {
		register_post_meta(
			$post_type,
			PostRepository::META_STATUS,
			array(
				'type'              => 'string',
				'description'       => __( 'Editorial workflow status.', 'sapphireit-editorial-workflow' ),
				'single'            => true,
				'default'           => $this->statuses->default_status(),
				'show_in_rest'      => array(
					'schema' => array(
						'enum'    => $this->statuses->slugs(),
						'context' => array( 'edit' ),
					),
				),
				'sanitize_callback' => array( $this->statuses, 'sanitize' ),
				// Deliberately always false — do not "fix". The workflow status may
				// only change through WorkflowManager::transition(), which validates
				// the transition and the user's permission. Allowing writes here
				// would let core's meta REST endpoint and wp.data bypass the state
				// machine. See also lock_status_meta().
				'auth_callback'     => static function () {
					return false;
				},
			)
		);

		register_post_meta(
			$post_type,
			PostRepository::META_REVIEWER,
			array(
				'type'              => 'integer',
				'description'       => __( 'User id of the assigned reviewer; 0 when none.', 'sapphireit-editorial-workflow' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => array(
					'schema' => array(
						'minimum' => 0,
						'context' => array( 'edit' ),
					),
				),
				'sanitize_callback' => array( $this->posts, 'sanitize_reviewer_id' ),
				'auth_callback'     => array( $this, 'can_edit_reviewer' ),
			)
		);

		register_post_meta(
			$post_type,
			PostRepository::META_DUE_DATE,
			array(
				'type'              => 'string',
				'description'       => __( 'Workflow due date (Y-m-d); empty when none.', 'sapphireit-editorial-workflow' ),
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => array(
					'schema' => array(
						'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
						'context' => array( 'edit' ),
					),
				),
				'sanitize_callback' => array( $this->posts, 'sanitize_due_date' ),
				'auth_callback'     => array( $this, 'can_edit_due_date' ),
			)
		);
	}

	/**
	 * Meta auth callback for the reviewer key.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $allowed   Whether the user can edit the meta (unused).
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post id.
	 * @param int    $user_id   User id.
	 * @return bool
	 */
	public function can_edit_reviewer( $allowed, $meta_key, $object_id, $user_id ): bool {
		return $this->permissions->can_assign_reviewer( (int) $object_id, (int) $user_id );
	}

	/**
	 * Meta auth callback for the due date key.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $allowed   Whether the user can edit the meta (unused).
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post id.
	 * @param int    $user_id   User id.
	 * @return bool
	 */
	public function can_edit_due_date( $allowed, $meta_key, $object_id, $user_id ): bool {
		return $this->permissions->can_set_due_date( (int) $object_id, (int) $user_id );
	}

	/**
	 * Denies every meta capability on the workflow status key to every user.
	 *
	 * `auth_callback => false` alone is not enough: on multisite, super admins
	 * pass every capability check except `do_not_allow`. Hooked on
	 * `map_meta_cap`.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $caps    Primitive capabilities required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User id.
	 * @param array    $args    Context; for meta caps `[ $object_id, $meta_key ]`.
	 * @return string[]
	 */
	public function lock_status_meta( $caps, $cap, $user_id, $args ): array {
		$caps = (array) $caps;

		if (
			in_array( $cap, self::STATUS_LOCKED_CAPS, true )
			&& isset( $args[1] )
			&& PostRepository::META_STATUS === $args[1]
		) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}
}
