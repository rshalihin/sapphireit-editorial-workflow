<?php
/**
 * AI-origin creation stamp.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Governance;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records the post type's AI-origin default on new workflow content (F9).
 *
 * Runs once, when a post is created, and only when nothing was declared
 * first: a value written during the insert (such as the `wp/v2` field an
 * agent sends) always wins. Updates never stamp, so changing the default
 * never rewrites existing posts.
 *
 * @since 1.2.0
 * @internal
 */
final class AiOriginStamper implements Bootable {

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
	 * Activity history.
	 *
	 * @since 1.2.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 *
	 * @param PostRepository $posts    Workflow meta persistence.
	 * @param Settings       $settings Plugin settings.
	 * @param ActivityLogger $activity Activity history.
	 */
	public function __construct( PostRepository $posts, Settings $settings, ActivityLogger $activity ) {
		$this->posts    = $posts;
		$this->settings = $settings;
		$this->activity = $activity;
	}

	/**
	 * Hooks the end of a post insert, after meta and terms are saved.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'handle' ), 10, 4 );
	}

	/**
	 * `wp_after_insert_post` callback.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $post_id     Post id.
	 * @param mixed $post        Post object.
	 * @param mixed $update      Whether this is an update of an existing post.
	 * @param mixed $post_before Post before the update; null for a new post.
	 * @return void
	 */
	public function handle( $post_id, $post, $update, $post_before ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( $post instanceof WP_Post ) {
			$this->stamp( (int) $post_id, $post, (bool) $update );
		}
	}

	/**
	 * Stamps a new post with its type's default, logs it and fires
	 * `sit_cwm_ai_origin_changed` with via `default`.
	 *
	 * @since 1.2.0
	 *
	 * @param int     $post_id Post id.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update of an existing post.
	 * @return void
	 */
	public function stamp( int $post_id, WP_Post $post, bool $update ): void {
		if ( $update ) {
			return;
		}

		if ( ! $this->settings->is_post_type_enabled( $post->post_type ) || 'revision' === $post->post_type || wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( AiOrigin::NOT_DECLARED !== $this->posts->get_ai_origin( $post_id ) ) {
			return;
		}

		$default = $this->settings->ai_origin_default( $post->post_type );

		if ( AiOrigin::NOT_DECLARED === $default || ! $this->posts->set_ai_origin( $post_id, $default ) ) {
			return;
		}

		$user_id = get_current_user_id();

		$this->activity->log_ai_origin_changed( $post_id, $default, AiOrigin::NOT_DECLARED, 'default', $user_id );

		/** This action is documented in includes/Workflow/WorkflowManager.php */
		do_action( 'sit_cwm_ai_origin_changed', $post_id, $default, AiOrigin::NOT_DECLARED, $user_id, 'default' );
	}
}
