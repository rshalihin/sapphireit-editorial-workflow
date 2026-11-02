<?php
/**
 * `sit_cwm_ai_origin` field on core's post routes.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\REST;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Governance\AiOrigin;
use Sit_Cwm\Workflow\WorkflowManager;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the declared AI origin on `wp/v2/{type}` for workflow-enabled types
 * (F9), so an agent can create a draft and declare its origin in one call.
 *
 * Edit context only. Writes go through `WorkflowManager::set_ai_origin()`
 * with via `post_api`, so they are authorized and logged like any other
 * declaration. Core saves additional fields before `wp_after_insert_post`,
 * so a value sent on create wins over the type's default stamp.
 *
 * @since 1.2.0
 * @internal
 */
final class AiOriginField implements Bootable {

	/**
	 * REST field name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const FIELD = 'sit_cwm_ai_origin';

	/**
	 * Workflow orchestrator, the only writer of the flag.
	 *
	 * @since 1.2.0
	 * @var WorkflowManager
	 */
	private $workflow;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.2.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Plugin settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 *
	 * @param WorkflowManager $workflow Workflow orchestrator.
	 * @param PostRepository  $posts    Workflow meta persistence.
	 * @param Settings        $settings Plugin settings.
	 */
	public function __construct( WorkflowManager $workflow, PostRepository $posts, Settings $settings ) {
		$this->workflow = $workflow;
		$this->posts    = $posts;
		$this->settings = $settings;
	}

	/**
	 * Registers the field on every workflow-enabled post type. Runs in the
	 * rest phase, during `rest_api_init`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->settings->enabled_post_types() as $post_type ) {
			register_rest_field(
				$post_type,
				self::FIELD,
				array(
					'get_callback'    => array( $this, 'get_callback' ),
					'update_callback' => array( $this, 'update_callback' ),
					'schema'          => array(
						'description' => __( 'Declared AI use (not detected).', 'sapphireit-editorial-workflow' ),
						'type'        => 'string',
						'enum'        => array_merge( array( AiOrigin::NOT_DECLARED ), AiOrigin::VALUES ),
						'context'     => array( 'edit' ),
					),
				)
			);
		}
	}

	/**
	 * Reads the declared value of a prepared post.
	 *
	 * @since 1.2.0
	 *
	 * @param array $prepared Post data prepared for the response.
	 * @return string A value from `AiOrigin::VALUES`, or `''`.
	 */
	public function get_callback( $prepared ): string {
		return $this->posts->get_ai_origin( (int) $prepared['id'] );
	}

	/**
	 * Declares a post's AI origin with via `post_api`.
	 *
	 * `''` is accepted and ignored, so clients can send back the value they
	 * read. A declaration is never withdrawn this way.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed   $value Validated field value.
	 * @param WP_Post $post  Post being created or updated.
	 * @return true|WP_Error `sit_cwm_invalid_ai_origin` (400), `sit_cwm_forbidden`
	 *                       (403) or `sit_cwm_not_managed` (404) on failure.
	 */
	public function update_callback( $value, $post ) {
		if ( AiOrigin::NOT_DECLARED === $value || ! $post instanceof WP_Post ) {
			return true;
		}

		return $this->workflow->set_ai_origin( (int) $post->ID, (string) $value, null, 'post_api' );
	}
}
