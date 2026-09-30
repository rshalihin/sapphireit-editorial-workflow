<?php
/**
 * REST controller for a post's activity history and workflow comments.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\WorkflowManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/posts/{post_id}/activity` and
 * `POST /sit-cwm/v1/posts/{post_id}/comments` (D9).
 *
 * Reads are authorized by PermissionManager; comments are written through
 * WorkflowManager::add_comment(), which re-checks everything.
 *
 * @since 1.0.0
 */
class ActivityController extends AbstractController {

	/**
	 * Longest accepted comment, in characters, before sanitizing.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MESSAGE_MAX_LENGTH = 5000;

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
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param WorkflowManager   $workflow    Workflow orchestrator.
	 * @param PermissionManager $permissions Authorization.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 * @param ActivityLogger    $activity    Activity history.
	 * @param ActivityFormatter $formatter   Activity presentation.
	 * @param UserSummaries     $users       Batched user summaries.
	 */
	public function __construct(
		WorkflowManager $workflow,
		PermissionManager $permissions,
		PostRepository $posts,
		ActivityLogger $activity,
		ActivityFormatter $formatter,
		UserSummaries $users
	) {
		parent::__construct( $permissions, $posts );

		$this->workflow  = $workflow;
		$this->activity  = $activity;
		$this->formatter = $formatter;
		$this->users     = $users;
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
			'/' . $this->rest_base . '/(?P<post_id>[\d]+)/activity',
			array(
				'args'   => array(
					'post_id' => $this->post_id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<post_id>[\d]+)/comments',
			array(
				'args'   => array(
					'post_id' => $this->post_id_arg(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'message' => array(
							'description'       => __( 'Comment text; post-safe HTML is kept.', 'sapphireit-editorial-workflow' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => array( $this, 'validate_message' ),
							'sanitize_callback' => array( $this, 'sanitize_message' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Query parameters of the activity collection.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_collection_params() {
		return array_merge(
			$this->pagination_args(),
			array(
				'action' => array(
					'description'       => __( 'Limit to one activity action.', 'sapphireit-editorial-workflow' ),
					'type'              => 'string',
					'enum'              => $this->activity->get_actions(),
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_key',
				),
			)
		);
	}

	/**
	 * Validates a comment: a string of at most 5000 characters with visible
	 * text left after sanitizing.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value   Raw value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_message( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		if ( mb_strlen( $value ) > self::MESSAGE_MAX_LENGTH ) {
			return new WP_Error(
				'sit_cwm_comment_too_long',
				/* translators: %d: Maximum number of characters. */
				sprintf( __( 'The comment cannot be longer than %d characters.', 'sapphireit-editorial-workflow' ), self::MESSAGE_MAX_LENGTH ),
				array( 'status' => 400 )
			);
		}

		if ( '' === trim( wp_strip_all_tags( wp_kses_post( $value ) ) ) ) {
			return new WP_Error(
				'sit_cwm_empty_comment',
				__( 'The comment cannot be empty.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Sanitizes a validated comment to post-safe HTML.
	 *
	 * No `wp_unslash()`: the REST server already unslashes form bodies and
	 * JSON bodies are never slashed, so unslashing again would strip real
	 * backslashes from the comment.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Validated value.
	 * @return string
	 */
	public function sanitize_message( $value ): string {
		return wp_kses_post( is_string( $value ) ? $value : '' );
	}

	/**
	 * Permission check for reading a post's activity.
	 *
	 * Logged in (401) → post resolvable and readable (404) → may view its
	 * activity (403).
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

		$post = $this->resolve_post( $request );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->permissions->can_view_activity( $post->ID ) ? true : $this->forbidden();
	}

	/**
	 * One page of a post's activity, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$post_id  = absint( $request->get_param( 'post_id' ) );
		$per_page = (int) $request->get_param( 'per_page' );
		$query    = array(
			'page'     => (int) $request->get_param( 'page' ),
			'per_page' => $per_page,
		);

		if ( $request->has_param( 'action' ) ) {
			$query['action'] = (string) $request->get_param( 'action' );
		}

		$total   = $this->activity->count_for_post( $post_id, $query );
		$entries = $this->activity->get_for_post( $post_id, $query );
		$users   = $this->users->load( $this->formatter->user_ids( $entries ) );
		$data    = array();

		foreach ( $entries as $entry ) {
			$data[] = $this->formatter->format( $entry, $users );
		}

		return $this->collection_response( $data, $total, $per_page );
	}

	/**
	 * Permission check for adding a comment.
	 *
	 * Logged in (401) → post resolvable and readable (404) → may comment (403).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		$post = $this->resolve_post( $request );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->permissions->can_comment( $post->ID ) ? true : $this->forbidden();
	}

	/**
	 * Adds a workflow comment and returns the stored entry with 201.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$activity_id = $this->workflow->add_comment( absint( $request->get_param( 'post_id' ) ), (string) $request->get_param( 'message' ) );

		if ( is_wp_error( $activity_id ) ) {
			return $this->error_to_response( $activity_id );
		}

		$entry = $this->activity->get( $activity_id );

		if ( null === $entry ) {
			return new WP_Error(
				'sit_cwm_update_failed',
				__( 'The workflow could not be saved. Please try again.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 500 )
			);
		}

		$response = rest_ensure_response( $this->formatter->format( $entry, $this->users->load( $this->formatter->user_ids( array( $entry ) ) ) ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Activity item schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$nullable_string = array(
			'type'     => array( 'string', 'null' ),
			'readonly' => true,
		);

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sit-cwm-activity',
			'type'       => 'object',
			'properties' => array(
				'id'               => array(
					'description' => __( 'Activity ID.', 'sapphireit-editorial-workflow' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'action'           => array(
					'description' => __( 'Action slug.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'action_label'     => array(
					'description' => __( 'Action label.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'old_value'        => $nullable_string,
				'old_label'        => $nullable_string,
				'new_value'        => $nullable_string,
				'new_label'        => $nullable_string,
				'message'          => array(
					'description' => __( 'Comment text, post-safe HTML.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
				),
				'created_at'       => array(
					'description' => __( 'Creation time, ISO 8601, UTC.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'readonly'    => true,
				),
				'created_at_human' => array(
					'description' => __( 'Creation time relative to now.', 'sapphireit-editorial-workflow' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'user_id'          => array(
					'description' => __( 'Acting user ID; 0 for the system.', 'sapphireit-editorial-workflow' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'user'             => array(
					'description' => __( 'Acting user; null for the system or a deleted user.', 'sapphireit-editorial-workflow' ),
					'type'        => array( 'object', 'null' ),
					'readonly'    => true,
					'properties'  => array(
						'id'     => array( 'type' => 'integer' ),
						'name'   => array( 'type' => 'string' ),
						'avatar' => array(
							'type'   => 'string',
							'format' => 'uri',
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
