<?php
/**
 * REST controller for the dashboard collection of workflow content.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use Sit_Cwm\Workflow\WorkflowManager;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/posts` (D9): workflow-managed posts, filtered, sorted and
 * paginated for the admin dashboard.
 *
 * One page costs a fixed number of queries regardless of its size: one
 * ids query (plus found rows), one posts + one meta query to prime caches,
 * one `get_users()` for every author, reviewer and activity user, and one
 * activity query for the last entry of every row.
 *
 * Users who cannot edit others' posts only see posts they author or are
 * assigned to review; that scope is part of the SQL query.
 *
 * @since 1.0.0
 */
class PostsController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $rest_base = 'posts';

	/**
	 * Workflow orchestrator.
	 *
	 * @since 1.0.0
	 * @var WorkflowManager
	 */
	private $workflow;

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Activity history.
	 *
	 * @since 1.0.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Activity presentation.
	 *
	 * @since 1.0.0
	 * @var ActivityFormatter
	 */
	private $formatter;

	/**
	 * Batched user summaries.
	 *
	 * @since 1.0.0
	 * @var UserSummaries
	 */
	private $users;

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
	 * @param WorkflowManager   $workflow    Workflow orchestrator.
	 * @param PermissionManager $permissions Authorization.
	 * @param StatusManager     $statuses    Status registry.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 * @param ActivityLogger    $activity    Activity history.
	 * @param ActivityFormatter $formatter   Activity presentation.
	 * @param UserSummaries     $users       Batched user summaries.
	 * @param Settings          $settings    Plugin settings.
	 */
	public function __construct(
		WorkflowManager $workflow,
		PermissionManager $permissions,
		StatusManager $statuses,
		PostRepository $posts,
		ActivityLogger $activity,
		ActivityFormatter $formatter,
		UserSummaries $users,
		Settings $settings
	) {
		parent::__construct( $permissions, $posts );

		$this->workflow  = $workflow;
		$this->statuses  = $statuses;
		$this->activity  = $activity;
		$this->formatter = $formatter;
		$this->users     = $users;
		$this->settings  = $settings;
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
	 * Query parameters. Every value is schema-validated; sort columns and
	 * directions are closed enums, never interpolated input.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		$date = array(
			'type'              => 'string',
			'validate_callback' => array( $this, 'validate_due_date' ),
			'sanitize_callback' => array( $this, 'sanitize_due_date' ),
		);

		return array_merge(
			$this->pagination_args(),
			array(
				'search'      => array(
					'description'       => __( 'Limit to posts matching a search.', 'sapphireit-editorial-workflow' ),
					'type'              => 'string',
					'maxLength'         => 200,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'status'      => array(
					'description'       => __( 'Limit to one or more workflow statuses.', 'sapphireit-editorial-workflow' ),
					'type'              => 'array',
					'items'             => array(
						'type' => 'string',
						'enum' => $this->statuses->slugs(),
					),
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'rest_sanitize_request_arg',
				),
				'reviewer_id' => array(
					'description'       => __( 'Limit to posts with this reviewer; 0 for posts without one.', 'sapphireit-editorial-workflow' ),
					'type'              => 'integer',
					'minimum'           => 0,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'absint',
				),
				'author'      => array(
					'description'       => __( 'Limit to posts by this author.', 'sapphireit-editorial-workflow' ),
					'type'              => 'integer',
					'minimum'           => 1,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'absint',
				),
				'post_type'   => array(
					'description'       => __( 'Limit to one workflow-enabled post type.', 'sapphireit-editorial-workflow' ),
					'type'              => 'string',
					'enum'              => $this->settings->enabled_post_types(),
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_key',
				),
				'due_before'  => array_merge( $date, array( 'description' => __( 'Limit to posts due on or before this date (YYYY-MM-DD).', 'sapphireit-editorial-workflow' ) ) ),
				'due_after'   => array_merge( $date, array( 'description' => __( 'Limit to posts due on or after this date (YYYY-MM-DD).', 'sapphireit-editorial-workflow' ) ) ),
				'overdue'     => array(
					'description'       => __( 'Limit to posts whose due date has passed, in the site timezone, before the workflow completed.', 'sapphireit-editorial-workflow' ),
					'type'              => 'boolean',
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'rest_sanitize_boolean',
				),
				'orderby'     => array(
					'description'       => __( 'Sort column.', 'sapphireit-editorial-workflow' ),
					'type'              => 'string',
					'default'           => 'date',
					'enum'              => array( 'title', 'date', 'due_date', 'status' ),
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_key',
				),
				'order'       => array(
					'description'       => __( 'Sort direction.', 'sapphireit-editorial-workflow' ),
					'type'              => 'string',
					'default'           => 'desc',
					'enum'              => array( 'asc', 'desc' ),
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_key',
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * Logged in (401) → can edit content of at least one workflow-enabled
	 * post type, including the requested `post_type` if any (403).
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

		$post_types = $this->permissions->dashboard_post_types();

		if ( array() === $post_types ) {
			return $this->forbidden();
		}

		if ( $request->has_param( 'post_type' ) && ! in_array( $request->get_param( 'post_type' ), $post_types, true ) ) {
			return $this->forbidden();
		}

		return true;
	}

	/**
	 * One page of workflow content.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$user_id    = get_current_user_id();
		$per_page   = (int) $request->get_param( 'per_page' );
		$post_types = $this->permissions->dashboard_post_types( $user_id );

		if ( $request->has_param( 'post_type' ) ) {
			$post_types = array_values( array_intersect( $post_types, array( (string) $request->get_param( 'post_type' ) ) ) );
		}

		$result = $this->posts->query_posts(
			array(
				'post_types'  => $post_types,
				'search'      => (string) $request->get_param( 'search' ),
				'statuses'    => $request->has_param( 'status' ) ? (array) $request->get_param( 'status' ) : array(),
				'reviewer_id' => $request->has_param( 'reviewer_id' ) ? absint( $request->get_param( 'reviewer_id' ) ) : null,
				'author'      => absint( $request->get_param( 'author' ) ),
				'due_before'  => (string) $request->get_param( 'due_before' ),
				'due_after'   => (string) $request->get_param( 'due_after' ),
				'overdue'     => true === rest_sanitize_boolean( $request->get_param( 'overdue' ) ),
				'orderby'     => (string) $request->get_param( 'orderby' ),
				'order'       => (string) $request->get_param( 'order' ),
				'page'        => (int) $request->get_param( 'page' ),
				'per_page'    => $per_page,
				'visible_to'  => $this->permissions->can_see_all_posts( $post_types, $user_id ) ? 0 : $user_id,
			)
		);

		return $this->collection_response( $this->prepare_rows( $result['ids'], $user_id ), $result['total'], $per_page );
	}

	/**
	 * Dashboard row schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$summary = array(
			'type'       => array( 'object', 'null' ),
			'readonly'   => true,
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'name'   => array( 'type' => 'string' ),
				'avatar' => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
		);

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sit-cwm-post',
			'type'       => 'object',
			'properties' => array(
				'post_id'               => array(
					'description' => __( 'Post ID.', 'sapphireit-editorial-workflow' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'title'                 => array(
					'description' => __( 'Raw post title; escape at output.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'post_type'             => array(
					'description' => __( 'Post type.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'post_status'           => array(
					'description' => __( 'Native WordPress post status, separate from the workflow status.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'author'                => array(
					'description' => __( 'Post author.', 'sapphireit-editorial-workflow' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'id'   => array( 'type' => 'integer' ),
						'name' => array( 'type' => 'string' ),
					),
				),
				'status'                => array(
					'description' => __( 'Workflow status.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'enum'        => $this->statuses->slugs(),
					'readonly'    => true,
				),
				'status_label'          => array(
					'description' => __( 'Workflow status label.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status_is_unknown'     => array(
					'description' => __( 'Whether the stored workflow status is no longer registered and the default status is reported instead.', 'sapphireit-editorial-workflow' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'reviewer'              => array_merge( $summary, array( 'description' => __( 'Assigned reviewer.', 'sapphireit-editorial-workflow' ) ) ),
				'due_date'              => array(
					'description' => __( 'Due date as YYYY-MM-DD, or an empty string.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'is_overdue'            => array(
					'description' => __( 'Whether the due date has passed before the workflow completed.', 'sapphireit-editorial-workflow' ),
					'type'        => 'boolean',
					'readonly'    => true,
				),
				'last_activity'         => array(
					'description' => __( 'Most recent activity entry, shaped like the activity route items.', 'sapphireit-editorial-workflow' ),
					'type'        => array( 'object', 'null' ),
					'readonly'    => true,
				),
				'edit_link'             => array(
					'description' => __( 'Edit screen URL; empty when the user cannot edit the post.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'available_transitions' => array(
					'description' => __( 'Transitions the current user may perform now.', 'sapphireit-editorial-workflow' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'slug'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'is_forward'  => array( 'type' => 'boolean' ),
							'is_rollback' => array( 'type' => 'boolean' ),
						),
					),
				),
				'capabilities'          => array(
					'description' => __( 'What the current user may do on this post. Display hints only.', 'sapphireit-editorial-workflow' ),
					'type'        => 'object',
					'readonly'    => true,
					'properties'  => array(
						'can_change_status'   => array( 'type' => 'boolean' ),
						'can_assign_reviewer' => array( 'type' => 'boolean' ),
						'can_set_due_date'    => array( 'type' => 'boolean' ),
						'can_comment'         => array( 'type' => 'boolean' ),
						'can_view_activity'   => array( 'type' => 'boolean' ),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Builds rows for a page of post ids with batched lookups only.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $post_ids Post ids, in display order.
	 * @param int   $user_id  Requesting user id.
	 * @return array[]
	 */
	private function prepare_rows( array $post_ids, int $user_id ): array {
		if ( array() === $post_ids ) {
			return array();
		}

		// One query for the posts, one for all their meta.
		_prime_post_caches( $post_ids, false, true );

		$posts    = array();
		$user_ids = array();

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post ) {
				$posts[]    = $post;
				$user_ids[] = (int) $post->post_author;
				$user_ids[] = $this->posts->get_reviewer_id( $post->ID );
			}
		}

		$activity = $this->activity->get_for_posts( $post_ids );
		$users    = $this->users->load( array_merge( $user_ids, $this->formatter->user_ids( array_merge( array(), ...array_values( $activity ) ) ) ) );
		$today    = current_datetime()->format( 'Y-m-d' );
		$rows     = array();

		foreach ( $posts as $post ) {
			$rows[] = $this->prepare_row( $post, $user_id, $users, $activity, $today );
		}

		return $rows;
	}

	/**
	 * One dashboard row. All lookups must already be primed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post     Post.
	 * @param int     $user_id  Requesting user id.
	 * @param array   $users    User id => summary.
	 * @param array   $activity Post id => latest activity entries.
	 * @param string  $today    Today in the site timezone, `Y-m-d`.
	 * @return array
	 */
	private function prepare_row( WP_Post $post, int $user_id, array $users, array $activity, string $today ): array {
		$status      = $this->posts->get_status( $post->ID );
		$reviewer_id = $this->posts->get_reviewer_id( $post->ID );
		$due_date    = $this->posts->get_due_date( $post->ID );
		$author_id   = (int) $post->post_author;
		$transitions = $this->workflow->get_available_transitions( $post->ID, $user_id );

		return array(
			'post_id'               => $post->ID,
			'title'                 => $post->post_title,
			'post_type'             => $post->post_type,
			'post_status'           => $post->post_status,
			'author'                => array(
				'id'   => $author_id,
				'name' => isset( $users[ $author_id ] ) ? $users[ $author_id ]['name'] : '',
			),
			'status'                => $status,
			'status_label'          => $this->statuses->label( $status ),
			'status_is_unknown'     => $this->posts->has_unknown_status( $post->ID ),
			'reviewer'              => $reviewer_id > 0 && isset( $users[ $reviewer_id ] ) ? $users[ $reviewer_id ] : null,
			'due_date'              => $due_date,
			'is_overdue'            => '' !== $due_date && $due_date < $today && ! $this->statuses->is_final( $status ),
			'last_activity'         => isset( $activity[ $post->ID ][0] ) ? $this->formatter->format( $activity[ $post->ID ][0], $users ) : null,
			'edit_link'             => (string) get_edit_post_link( $post->ID, 'raw' ),
			'available_transitions' => $transitions,
			'capabilities'          => $this->workflow->get_capability_hints( $post->ID, $transitions, $user_id ),
		);
	}
}
