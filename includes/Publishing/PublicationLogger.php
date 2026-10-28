<?php
/**
 * Publication logger.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Throwable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every time managed content goes live or stops being live, with the
 * actor, the time and the workflow status at that moment, in every gate mode
 * including `off` (roadmap §3.1B, E6).
 *
 * @since 1.1.0
 * @internal
 */
final class PublicationLogger implements Bootable {

	/**
	 * Activity history.
	 *
	 * @since 1.1.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.1.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Plugin settings.
	 *
	 * @since 1.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Publish gate, for the filtered mode.
	 *
	 * @since 1.1.0
	 * @var PublishGate
	 */
	private $gate;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.1.0
	 *
	 * @param ActivityLogger $activity Activity history.
	 * @param PostRepository $posts    Workflow meta persistence.
	 * @param Settings       $settings Plugin settings.
	 * @param PublishGate    $gate     Publish gate, for the filtered mode.
	 */
	public function __construct( ActivityLogger $activity, PostRepository $posts, Settings $settings, PublishGate $gate ) {
		$this->activity = $activity;
		$this->posts    = $posts;
		$this->settings = $settings;
		$this->gate     = $gate;
	}

	/**
	 * Hooks native post status transitions.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'handle_transition' ), 10, 3 );
	}

	/**
	 * Logs `post_published` on entry into `publish` and `post_unpublished` on
	 * exit from it (including to `trash`), for workflow-enabled post types.
	 *
	 * Never raises: logging must not break a save, so any failure is swallowed
	 * (the same fail-open principle as the gate layers, E5).
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status (`new` for a new post).
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public function handle_transition( $new_status, $old_status, $post ): void {
		try {
			if ( ! $post instanceof WP_Post || ! is_string( $new_status ) || ! is_string( $old_status ) || $new_status === $old_status ) {
				return;
			}

			if ( ! $this->settings->is_post_type_enabled( $post->post_type ) ) {
				return;
			}

			if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
				return;
			}

			if ( 'publish' === $new_status ) {
				$action = 'post_published';
			} elseif ( 'publish' === $old_status ) {
				$action = 'post_unpublished';
			} else {
				return;
			}

			$this->activity->log_publication_event(
				(int) $post->ID,
				$action,
				$old_status,
				$new_status,
				array(
					'workflow_status' => $this->posts->get_status( (int) $post->ID ),
					'gate_mode'       => $this->gate->mode_for( $post->post_type, (int) $post->ID ),
					'source'          => RequestSource::detect(),
				),
				get_current_user_id()
			);
		} catch ( Throwable $e ) {
			return;
		}
	}
}
