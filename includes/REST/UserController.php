<?php
/**
 * REST controller for assignable reviewers.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/users` (D9): users who may be assigned as reviewer.
 *
 * A classic user-enumeration hole, so it is capability-gated: only users who
 * may assign reviewers can call it. Responses carry id, display name and
 * avatar only, and search never matches logins or email addresses.
 *
 * @since 1.0.0
 */
class UserController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $rest_base = 'users';

	/**
	 * Batched user summaries.
	 *
	 * @since 1.0.0
	 * @var UserSummaries
	 */
	private $users;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param PermissionManager $permissions Authorization.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 * @param UserSummaries     $users       Batched user summaries.
	 */
	public function __construct( PermissionManager $permissions, PostRepository $posts, UserSummaries $users ) {
		parent::__construct( $permissions, $posts );

		$this->users = $users;
	}

	/**
	 * Registers the route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Query parameters.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$pagination = $this->pagination_args();

		return array(
			'search'   => array(
				'description'       => __( 'Limit to users whose name matches.', 'sapphireit-editorial-workflow' ),
				'type'              => 'string',
				'maxLength'         => 100,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'per_page' => $pagination['per_page'],
			'post_id'  => array(
				'description'       => __( 'Limit to users who can review this post.', 'sapphireit-editorial-workflow' ),
				'type'              => 'integer',
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Permission check.
	 *
	 * Logged in (401) → with `post_id`: post resolvable and readable (404) →
	 * may assign that post's reviewer (403); without: may assign reviewers or
	 * manage the plugin (403).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		$post_id = 0;

		if ( $request->has_param( 'post_id' ) ) {
			$post = $this->resolve_post( $request );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$post_id = $post->ID;
		}

		return $this->permissions->can_list_reviewers( $post_id ) ? true : $this->forbidden();
	}

	/**
	 * Assignable reviewers, ordered by display name.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$search  = trim( (string) $request->get_param( 'search' ) );

		$query = array(
			'meta_query' => $this->capability_meta_query( $this->permissions->reviewer_capabilities( $post_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Capabilities live in user meta; same query core builds for `capability`.
			'orderby'    => 'display_name',
			'order'      => 'ASC',
			'number'     => (int) $request->get_param( 'per_page' ),
		);

		if ( '' !== $search ) {
			$query['search']         = '*' . $search . '*';
			$query['search_columns'] = array( 'display_name', 'user_nicename' );
		}

		/**
		 * Filters the `get_users()` arguments of the assignable reviewer list.
		 *
		 * `fields` and `count_total` are enforced after the filter, and the
		 * response only ever carries id, name and avatar.
		 *
		 * @since 1.0.0
		 *
		 * @param array $query   `get_users()` arguments.
		 * @param int   $post_id Post the reviewer is for; `0` for none.
		 * @param int   $user_id Requesting user id.
		 */
		$filtered = apply_filters( 'sit_cwm_assignable_reviewers', $query, $post_id, get_current_user_id() );

		if ( is_array( $filtered ) ) {
			$query = $filtered;
		}

		$query['fields']      = UserSummaries::FIELDS;
		$query['count_total'] = false;

		$data = array();

		foreach ( get_users( $query ) as $row ) {
			$summary = $this->users->from_row( $row );

			if ( null !== $summary ) {
				$data[] = $summary;
			}
		}

		return rest_ensure_response( $data );
	}

	/**
	 * User `meta_query` matching users who hold every given capability, either
	 * granted directly or through any role that has it.
	 *
	 * Built here rather than with `get_users()`' `capability` argument: core
	 * maps each role to only the first listed capability it holds, so with
	 * several capabilities a role that has all of them fails to match.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $capabilities Capabilities that must all be held.
	 * @return array
	 */
	private function capability_meta_query( array $capabilities ): array {
		global $wpdb;

		$key   = $wpdb->get_blog_prefix() . 'capabilities';
		$roles = wp_roles()->roles;
		$query = array( 'relation' => 'AND' );

		foreach ( $capabilities as $capability ) {
			$clause = array(
				'relation' => 'OR',
				array(
					'key'     => $key,
					'value'   => '"' . $capability . '"',
					'compare' => 'LIKE',
				),
			);

			foreach ( $roles as $role => $data ) {
				if ( ! empty( $data['capabilities'][ $capability ] ) ) {
					$clause[] = array(
						'key'     => $key,
						'value'   => '"' . $role . '"',
						'compare' => 'LIKE',
					);
				}
			}

			$query[] = $clause;
		}

		return $query;
	}

	/**
	 * Reviewer item schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sit-cwm-reviewer',
			'type'       => 'object',
			'properties' => array(
				'id'     => array(
					'description' => __( 'User ID.', 'sapphireit-editorial-workflow' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'name'   => array(
					'description' => __( 'Display name.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'avatar' => array(
					'description' => __( 'Avatar URL.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
