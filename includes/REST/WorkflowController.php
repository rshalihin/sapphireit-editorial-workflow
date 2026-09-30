<?php
/**
 * REST controller for a post's workflow state.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use Sit_Cwm\Workflow\TransitionManager;
use Sit_Cwm\Workflow\WorkflowManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET|POST /sit-cwm/v1/posts/{post_id}/workflow` and `GET /sit-cwm/v1/statuses` (D9).
 *
 * Zero business logic: requests are authorized through PermissionManager and
 * WorkflowManager, applied through WorkflowManager, and answered with the
 * fresh `WorkflowManager::get_workflow()` payload.
 *
 * A POST may combine `status`, `reviewer_id` and `due_date`. Every part is
 * authorized and validated in the permission check before anything is
 * written, then applied in a fixed order (reviewer → due date → status). The
 * write itself is not transactional: if a later part still fails (e.g. a
 * concurrent edit between check and write), earlier parts stay applied.
 *
 * @since 1.0.0
 */
class WorkflowController extends AbstractController {

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
	 * Transition map.
	 *
	 * @since 1.0.0
	 * @var TransitionManager
	 */
	private $transitions;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param WorkflowManager   $workflow    Workflow orchestrator.
	 * @param PermissionManager $permissions Authorization.
	 * @param StatusManager     $statuses    Status registry.
	 * @param TransitionManager $transitions Transition map.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 */
	public function __construct(
		WorkflowManager $workflow,
		PermissionManager $permissions,
		StatusManager $statuses,
		TransitionManager $transitions,
		PostRepository $posts
	) {
		parent::__construct( $permissions, $posts );

		$this->workflow    = $workflow;
		$this->statuses    = $statuses;
		$this->transitions = $transitions;
	}

	/**
	 * Registers the routes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<post_id>[\d]+)/workflow',
			array(
				'args'   => array(
					'post_id' => $this->post_id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_update_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/statuses',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_statuses' ),
					'permission_callback' => array( $this, 'get_statuses_permissions_check' ),
				),
				'schema' => array( $this, 'get_statuses_schema' ),
			)
		);
	}

	/**
	 * Arguments accepted by the POST route. Anything else in the body is ignored.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_update_args(): array {
		$slugs = $this->statuses->slugs();

		return array(
			'status'      => array(
				'description'       => __( 'Target workflow status.', 'sapphireit-editorial-workflow' ),
				'type'              => 'string',
				'required'          => false,
				'enum'              => $slugs,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( $this->statuses, 'sanitize' ),
			),
			'from'        => array(
				'description'       => __( 'Workflow status the client saw as current. Required with status.', 'sapphireit-editorial-workflow' ),
				'type'              => 'string',
				'required'          => false,
				'enum'              => $slugs,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( $this->statuses, 'sanitize' ),
			),
			'reviewer_id' => array(
				'description'       => __( 'Reviewer user ID; 0 clears the reviewer.', 'sapphireit-editorial-workflow' ),
				'type'              => 'integer',
				'required'          => false,
				'minimum'           => 0,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'due_date'    => array(
				'description'       => __( 'Due date as YYYY-MM-DD; an empty string clears it.', 'sapphireit-editorial-workflow' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_due_date' ),
				'sanitize_callback' => array( $this, 'sanitize_due_date' ),
			),
		);
	}

	/**
	 * Permission check for reading a post's workflow.
	 *
	 * Logged in → post resolvable and readable (404) → may edit the post or
	 * view its activity (403).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		$post = $this->resolve_post( $request );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $this->permissions->can_edit_post( $post->ID ) && ! $this->permissions->can_view_activity( $post->ID ) ) {
			return $this->forbidden();
		}

		return true;
	}

	/**
	 * Current workflow state of a post.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		return $this->workflow_response( absint( $request->get_param( 'post_id' ) ) );
	}

	/**
	 * Permission check for changing a post's workflow.
	 *
	 * Logged in → post resolvable and readable (404) → may edit the post (403)
	 * → for each field present, the field's own authorization, and for a
	 * status change the full transition check (400/403/409). Nothing is
	 * written here; WorkflowManager re-checks everything when applying.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		$post = $this->resolve_post( $request );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $this->permissions->can_edit_post( $post->ID ) ) {
			return $this->forbidden();
		}

		if ( $request->has_param( 'reviewer_id' ) && ! $this->permissions->can_assign_reviewer( $post->ID ) ) {
			return $this->forbidden();
		}

		if ( $request->has_param( 'due_date' ) && ! $this->permissions->can_set_due_date( $post->ID ) ) {
			return $this->forbidden();
		}

		if ( ! $request->has_param( 'status' ) ) {
			return true;
		}

		if ( ! $request->has_param( 'from' ) ) {
			return new WP_Error(
				'rest_missing_callback_param',
				/* translators: %s: Comma-separated parameter names. */
				sprintf( __( 'Missing parameter(s): %s', 'sapphireit-editorial-workflow' ), 'from' ),
				array(
					'status' => 400,
					'params' => array( 'from' ),
				)
			);
		}

		$check = $this->workflow->check_transition( $post->ID, (string) $request->get_param( 'from' ), (string) $request->get_param( 'status' ) );

		return is_wp_error( $check ) ? $this->error_to_response( $check ) : true;
	}

	/**
	 * Applies reviewer, due date and status changes, in that order.
	 *
	 * The first failure aborts and is returned; earlier changes stay applied.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Fresh workflow state on success.
	 */
	public function update_item( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! $request->has_param( 'status' ) && ! $request->has_param( 'reviewer_id' ) && ! $request->has_param( 'due_date' ) ) {
			return new WP_Error(
				'rest_missing_callback_param',
				/* translators: %s: Comma-separated parameter names. */
				sprintf( __( 'Missing parameter(s): %s', 'sapphireit-editorial-workflow' ), 'status, reviewer_id, due_date' ),
				array(
					'status' => 400,
					'params' => array( 'status', 'reviewer_id', 'due_date' ),
				)
			);
		}

		$steps = array();

		if ( $request->has_param( 'reviewer_id' ) ) {
			$steps[] = function () use ( $post_id, $request ) {
				return $this->workflow->assign_reviewer( $post_id, absint( $request->get_param( 'reviewer_id' ) ) );
			};
		}

		if ( $request->has_param( 'due_date' ) ) {
			$steps[] = function () use ( $post_id, $request ) {
				return $this->workflow->set_due_date( $post_id, (string) $request->get_param( 'due_date' ) );
			};
		}

		if ( $request->has_param( 'status' ) ) {
			$steps[] = function () use ( $post_id, $request ) {
				return $this->workflow->transition( $post_id, (string) $request->get_param( 'from' ), (string) $request->get_param( 'status' ) );
			};
		}

		foreach ( $steps as $step ) {
			$result = $step();

			if ( is_wp_error( $result ) ) {
				return $this->error_to_response( $result );
			}
		}

		return $this->workflow_response( $post_id );
	}

	/**
	 * Permission check for the status registry.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_statuses_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature required by the REST server.
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		return $this->permissions->can_view_statuses() ? true : $this->forbidden();
	}

	/**
	 * Status registry and transition map, for the UI.
	 *
	 * Display metadata only: which transitions a user may perform on a given
	 * post comes from the workflow route.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_statuses( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature required by the REST server.
		$transitions = array();

		foreach ( $this->transitions->map() as $from => $targets ) {
			foreach ( $targets as $to ) {
				$transitions[ $from ][] = $this->transitions->describe( $from, $to );
			}
		}

		return rest_ensure_response(
			array(
				'default_status' => $this->statuses->default_status(),
				'statuses'       => array_values( $this->statuses->all() ),
				'transitions'    => (object) $transitions,
			)
		);
	}

	/**
	 * Workflow state schema (D9).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$transition = $this->transition_schema();

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sit-cwm-workflow',
			'type'       => 'object',
			'properties' => array(
				'post_id'               => array(
					'description' => __( 'Post ID.', 'sapphireit-editorial-workflow' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'post_title'            => array(
					'description' => __( 'Post title.', 'sapphireit-editorial-workflow' ),
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
				'edit_link'             => array(
					'description' => __( 'Edit screen URL.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'format'      => 'uri',
					'readonly'    => true,
				),
				'status'                => array(
					'description' => __( 'Workflow status.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'enum'        => $this->statuses->slugs(),
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
				'reviewer'              => array(
					'description' => __( 'Assigned reviewer.', 'sapphireit-editorial-workflow' ),
					'type'        => array( 'object', 'null' ),
					'properties'  => array(
						'id'     => array( 'type' => 'integer' ),
						'name'   => array( 'type' => 'string' ),
						'avatar' => array(
							'type'   => 'string',
							'format' => 'uri',
						),
					),
				),
				'due_date'              => array(
					'description' => __( 'Due date as YYYY-MM-DD, or an empty string.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
				),
				'available_transitions' => array(
					'description' => __( 'Transitions the current user may perform now.', 'sapphireit-editorial-workflow' ),
					'type'        => 'array',
					'items'       => $transition,
					'readonly'    => true,
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
	 * Status registry schema, published by `OPTIONS /statuses`.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_statuses_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sit-cwm-statuses',
			'type'       => 'object',
			'properties' => array(
				'default_status' => array(
					'description' => __( 'Status of managed content that has none stored yet.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'statuses'       => array(
					'description' => __( 'Registered workflow statuses, in display order.', 'sapphireit-editorial-workflow' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'slug'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'color'       => array( 'type' => 'string' ),
							'order'       => array( 'type' => 'integer' ),
							'is_final'    => array( 'type' => 'boolean' ),
						),
					),
				),
				'transitions'    => array(
					'description'          => __( 'Transition map: source status slug to the moves the workflow allows from it, whoever the user is.', 'sapphireit-editorial-workflow' ),
					'type'                 => 'object',
					'readonly'             => true,
					'additionalProperties' => array(
						'type'  => 'array',
						'items' => $this->transition_schema(),
					),
				),
			),
		);
	}

	/**
	 * Schema of one transition, shared by the workflow and statuses routes.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function transition_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'slug'        => array( 'type' => 'string' ),
				'label'       => array( 'type' => 'string' ),
				'is_forward'  => array( 'type' => 'boolean' ),
				'is_rollback' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Wraps the fresh workflow state in a response.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return WP_REST_Response|WP_Error 404 when the workflow is no longer accessible.
	 */
	private function workflow_response( int $post_id ) {
		$state = $this->workflow->get_workflow( $post_id );

		if ( array() === $state ) {
			return new WP_Error(
				'sit_cwm_not_managed',
				__( 'No workflow was found for this content.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $state );
	}
}
