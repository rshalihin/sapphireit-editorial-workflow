<?php
/**
 * REST controller for bulk workflow actions.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Workflow\BulkProcessor;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /sit-cwm/v1/posts/batch` (D9): one workflow action applied to up to
 * `BulkProcessor::MAX_ITEMS` posts.
 *
 * The permission check is only the gate to *attempt* a batch. Every post is
 * then authorized and validated individually by BulkProcessor, through the
 * same WorkflowManager methods a single request uses.
 *
 * The answer is a 207-style payload with HTTP 200: `succeeded` ids, `failed`
 * entries with their error code and message, and the fresh workflow state of
 * every succeeded post. Only a malformed request as a whole (bad ids, unknown
 * action, missing payload) is a 400.
 *
 * @since 1.0.0
 */
class BatchController extends AbstractController {

	/**
	 * Route base.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $rest_base = 'posts/batch';

	/**
	 * Bulk processor.
	 *
	 * @since 1.0.0
	 * @var BulkProcessor
	 */
	private $bulk;

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param BulkProcessor     $bulk        Bulk processor.
	 * @param PermissionManager $permissions Authorization.
	 * @param StatusManager     $statuses    Status registry.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 */
	public function __construct( BulkProcessor $bulk, PermissionManager $permissions, StatusManager $statuses, PostRepository $posts ) {
		parent::__construct( $permissions, $posts );

		$this->bulk     = $bulk;
		$this->statuses = $statuses;
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_batch_args(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Arguments of the batch route. Anything else in the body is ignored, and
	 * unknown payload keys are rejected by the schema.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_batch_args(): array {
		return array(
			'post_ids' => array(
				'description'       => __( 'IDs of the posts to update; duplicates are processed once.', 'sapphireit-editorial-workflow' ),
				'type'              => 'array',
				'required'          => true,
				'minItems'          => 1,
				'maxItems'          => BulkProcessor::MAX_ITEMS,
				'items'             => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => array( $this, 'sanitize_post_ids' ),
			),
			'action'   => array(
				'description'       => __( 'Bulk action to apply.', 'sapphireit-editorial-workflow' ),
				'type'              => 'string',
				'required'          => true,
				'enum'              => array_keys( BulkProcessor::ACTIONS ),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
			'payload'  => array(
				'description'          => __( 'Action payload: status, reviewer_id or due_date, matching the action.', 'sapphireit-editorial-workflow' ),
				'type'                 => 'object',
				'required'             => true,
				'properties'           => array(
					'status'      => array(
						'type' => 'string',
						'enum' => $this->statuses->slugs(),
					),
					'reviewer_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'due_date'    => array(
						'type'      => 'string',
						'maxLength' => 10,
					),
				),
				'additionalProperties' => false,
				'validate_callback'    => array( $this, 'validate_payload' ),
				'sanitize_callback'    => array( $this, 'sanitize_payload' ),
			),
		);
	}

	/**
	 * Unique positive post ids, in request order.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Validated value.
	 * @return int[]
	 */
	public function sanitize_post_ids( $value ): array {
		return array_values( array_filter( wp_parse_id_list( $value ) ) );
	}

	/**
	 * Validates the payload against its schema and requires the key the
	 * action needs; a due date must also be a real `Y-m-d` date or empty.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value   Raw value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_payload( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$action = $request->get_param( 'action' );

		// An unknown action is reported by the `action` argument itself.
		if ( ! is_string( $action ) || ! isset( BulkProcessor::ACTIONS[ $action ] ) ) {
			return true;
		}

		$key   = BulkProcessor::ACTIONS[ $action ];
		$value = (array) $value;

		if ( ! array_key_exists( $key, $value ) ) {
			return new WP_Error(
				'sit_cwm_invalid_payload',
				/* translators: %s: Payload key, e.g. "status". */
				sprintf( __( 'The payload must include %s for this action.', 'sapphireit-editorial-workflow' ), $key ),
				array( 'status' => 400 )
			);
		}

		if ( 'due_date' === $key ) {
			$date = is_string( $value['due_date'] ) ? trim( $value['due_date'] ) : '';

			if ( '' !== $date && $this->posts->sanitize_due_date( $date ) !== $date ) {
				return new WP_Error(
					'sit_cwm_invalid_date',
					__( 'The due date must be a valid date in YYYY-MM-DD format.', 'sapphireit-editorial-workflow' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Keeps only the payload key the action uses, sanitized for its type.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value   Validated value.
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public function sanitize_payload( $value, $request ): array {
		$action = (string) $request->get_param( 'action' );
		$value  = (array) $value;
		$key    = BulkProcessor::ACTIONS[ $action ] ?? '';

		if ( '' === $key || ! array_key_exists( $key, $value ) ) {
			return array();
		}

		switch ( $key ) {
			case 'status':
				return array( 'status' => sanitize_key( (string) $value['status'] ) );

			case 'reviewer_id':
				return array( 'reviewer_id' => absint( $value['reviewer_id'] ) );

			default:
				return array( 'due_date' => $this->sanitize_due_date( $value['due_date'] ) );
		}
	}

	/**
	 * Permission check: logged in (401) → may attempt a batch (403).
	 *
	 * Per-post authorization happens for every item inside the batch.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature required by the REST server.
		$login = $this->require_login();

		if ( is_wp_error( $login ) ) {
			return $login;
		}

		return $this->permissions->can_attempt_batch() ? true : $this->forbidden();
	}

	/**
	 * Runs the batch.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$result = $this->bulk->process(
			(array) $request->get_param( 'post_ids' ),
			(string) $request->get_param( 'action' ),
			(array) $request->get_param( 'payload' )
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_to_response( $result );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Batch result schema.
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
			'title'      => 'sit-cwm-batch-result',
			'type'       => 'object',
			'properties' => array(
				'succeeded' => array(
					'description' => __( 'IDs of the posts that were updated.', 'sapphireit-editorial-workflow' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'integer' ),
					'readonly'    => true,
				),
				'failed'    => array(
					'description' => __( 'Posts that were not updated, with the reason.', 'sapphireit-editorial-workflow' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'post_id' => array( 'type' => 'integer' ),
							'code'    => array( 'type' => 'string' ),
							'message' => array( 'type' => 'string' ),
							'status'  => array( 'type' => 'integer' ),
						),
					),
				),
				'items'     => array(
					'description' => __( 'Fresh workflow state of every updated post.', 'sapphireit-editorial-workflow' ),
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
