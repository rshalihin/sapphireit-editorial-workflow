<?php
/**
 * Shared REST controller plumbing.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for every `sit-cwm/v1` controller.
 *
 * Controllers are thin HTTP adapters: they resolve the post, delegate every
 * authorization question to PermissionManager and every mutation to
 * WorkflowManager, and translate `WP_Error` codes to HTTP statuses. They never
 * call `current_user_can()` themselves.
 *
 * Authentication: cookie-authenticated requests must carry the `wp_rest` nonce
 * (`X-WP-Nonce`), which `@wordpress/api-fetch` sends automatically. Clients
 * using application passwords authenticate without a nonce and are
 * capability-checked exactly the same way.
 *
 * @since 1.0.0
 */
abstract class AbstractController extends WP_REST_Controller implements Bootable {

	/**
	 * HTTP status per plugin error code. Codes not listed but starting with
	 * `sit_cwm_invalid_` are 400.
	 *
	 * @since 1.0.0
	 * @var array<string, int>
	 */
	const ERROR_STATUS = array(
		'sit_cwm_forbidden'       => 403,
		'sit_cwm_invalid_post'    => 404,
		'sit_cwm_not_managed'     => 404,
		'sit_cwm_status_conflict' => 409,
		'sit_cwm_empty_comment'   => 400,
		'sit_cwm_update_failed'   => 500,
	);

	/**
	 * Default collection page size.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Largest collection page size.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Route namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	protected $namespace = 'sit-cwm/v1';

	/**
	 * Authorization.
	 *
	 * @since 1.0.0
	 * @var PermissionManager
	 */
	protected $permissions;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.0.0
	 * @var PostRepository
	 */
	protected $posts;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param PermissionManager $permissions Authorization.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 */
	public function __construct( PermissionManager $permissions, PostRepository $posts ) {
		$this->permissions = $permissions;
		$this->posts       = $posts;
	}

	/**
	 * Registers the routes once the REST server initializes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Schema of the `post_id` URL parameter.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function post_id_arg(): array {
		return array(
			'description'       => __( 'Post ID.', 'sapphireit-editorial-workflow' ),
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'absint',
		);
	}

	/**
	 * Schema of the `page` and `per_page` collection parameters.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	protected function pagination_args(): array {
		return array(
			'page'     => array(
				'description'       => __( 'Current page of the collection.', 'sapphireit-editorial-workflow' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'description'       => __( 'Maximum number of items to return.', 'sapphireit-editorial-workflow' ),
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'minimum'           => 1,
				'maximum'           => self::MAX_PER_PAGE,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Wraps a collection page in a response with core-style pagination headers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data     Items of the current page.
	 * @param int   $total    Total items across all pages.
	 * @param int   $per_page Page size.
	 * @return WP_REST_Response
	 */
	protected function collection_response( array $data, int $total, int $per_page ): WP_REST_Response {
		$response = rest_ensure_response( $data );

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) ( $per_page > 0 ? (int) ceil( $total / $per_page ) : 0 ) );

		return $response;
	}

	/**
	 * Validates a due date argument: empty, or a real `Y-m-d` date.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed           $value   Raw value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @return true|WP_Error
	 */
	public function validate_due_date( $value, $request, $param ) {
		$valid = rest_validate_request_arg( $value, $request, $param );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$value = trim( (string) $value );

		if ( '' === $value || $this->posts->sanitize_due_date( $value ) === $value ) {
			return true;
		}

		return new WP_Error(
			'sit_cwm_invalid_date',
			__( 'The due date must be a valid date in YYYY-MM-DD format.', 'sapphireit-editorial-workflow' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Sanitizes a due date argument after validation.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Validated value.
	 * @return string `Y-m-d`, or `''`.
	 */
	public function sanitize_due_date( $value ): string {
		return $this->posts->sanitize_due_date( is_string( $value ) ? $value : '' );
	}

	/**
	 * Resolves the `post_id` URL parameter to an accessible, managed post.
	 *
	 * A post that does not exist, is not workflow-enabled, or that the user
	 * cannot read yields the identical `sit_cwm_not_managed` 404 (code, message
	 * and status), so a response never distinguishes a hidden post from a
	 * missing id (step 20.2).
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_Post|WP_Error
	 */
	protected function resolve_post( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || ! $this->posts->is_managed( $post->ID ) || ! $this->permissions->can_read_post( $post->ID ) ) {
			return new WP_Error(
				'sit_cwm_not_managed',
				__( 'No workflow was found for this content.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 404 )
			);
		}

		return $post;
	}

	/**
	 * Sets the HTTP status of a plugin error from its code.
	 *
	 * Other error data is kept. Unknown codes keep their own status, or 500.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error $error Error from the workflow layer.
	 * @return WP_Error
	 */
	protected function error_to_response( WP_Error $error ): WP_Error {
		$code = (string) $error->get_error_code();
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		if ( isset( self::ERROR_STATUS[ $code ] ) ) {
			$data['status'] = self::ERROR_STATUS[ $code ];
		} elseif ( 0 === strpos( $code, 'sit_cwm_invalid_' ) ) {
			$data['status'] = 400;
		} elseif ( ! isset( $data['status'] ) ) {
			$data['status'] = 500;
		}

		return new WP_Error( $code, $error->get_error_message(), $data );
	}

	/**
	 * Rejects logged-out requests.
	 *
	 * @since 1.0.0
	 *
	 * @return true|WP_Error `rest_forbidden` with 401 when logged out.
	 */
	protected function require_login() {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You must be logged in to access workflows.', 'sapphireit-editorial-workflow' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Authorization failure for a resource the user can see.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error
	 */
	protected function forbidden(): WP_Error {
		return new WP_Error(
			'sit_cwm_forbidden',
			__( 'You are not allowed to perform this workflow action.', 'sapphireit-editorial-workflow' ),
			array( 'status' => 403 )
		);
	}
}
