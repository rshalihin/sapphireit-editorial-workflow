<?php
/**
 * Publish gate decision service.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether a post may become `publish`/`future` under its post type's
 * gate mode (E1, E2).
 *
 * Pure decision service: it writes nothing and hooks nothing. Whether a write
 * is a transition into a published status is the caller's question (E4); the
 * enforcement layers ask this service only for the outcome.
 *
 * @since 1.1.0
 * @internal
 */
final class PublishGate {

	/**
	 * The gate is not evaluated.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const MODE_OFF = 'off';

	/**
	 * The gate is evaluated and failures are recorded, but nothing is blocked.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const MODE_LOG_ONLY = 'log_only';

	/**
	 * The gate is evaluated and failures are blocked.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const MODE_ENFORCE = 'enforce';

	/**
	 * Every gate mode, in settings-screen order.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const MODES = array( self::MODE_OFF, self::MODE_LOG_ONLY, self::MODE_ENFORCE );

	/**
	 * Native post statuses the gate guards entry into.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const PUBLISH_STATUSES = array( 'publish', 'future' );

	/**
	 * Workflow statuses that pass the gate.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const PUBLISHABLE_STATUSES = array( 'approved', 'published' );

	/**
	 * Plugin settings.
	 *
	 * @since 1.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.1.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Capability checks.
	 *
	 * @since 1.1.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.1.0
	 *
	 * @param Settings          $settings    Plugin settings.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 * @param PermissionManager $permissions Capability checks.
	 */
	public function __construct( Settings $settings, PostRepository $posts, PermissionManager $permissions ) {
		$this->settings    = $settings;
		$this->posts       = $posts;
		$this->permissions = $permissions;
	}

	/**
	 * Gate mode for a post type, and optionally one post of it (E1).
	 *
	 * A post type the workflow does not manage, a missing setting and an
	 * unknown (filtered) mode all resolve to `off`.
	 *
	 * @since 1.1.0
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $post_id   Post id; `0` when not post-specific.
	 * @return string One of `MODES`.
	 */
	public function mode_for( string $post_type, int $post_id = 0 ): string {
		// Computed on every call: an unkeyed cache would hand every later post the first post's answer (E1).
		if ( ! $this->settings->is_post_type_enabled( $post_type ) ) {
			return self::MODE_OFF;
		}

		$mode = $this->settings->gate_modes()[ $post_type ] ?? self::MODE_OFF;

		/**
		 * Filters the publish gate mode for a post type, or for one post of it.
		 *
		 * A value that is not `off`, `log_only` or `enforce` resolves to `off`.
		 *
		 * @since 1.1.0
		 *
		 * @param string $mode      Stored mode: `off`, `log_only` or `enforce`.
		 * @param string $post_type Post type slug.
		 * @param int    $post_id   Post id; `0` when the question is not about
		 *                          one post (e.g. a new post with no id yet).
		 */
		$mode = apply_filters( 'sit_cwm_publish_gate_mode', $mode, $post_type, $post_id );

		return in_array( $mode, self::MODES, true ) ? $mode : self::MODE_OFF;
	}

	/**
	 * Whether a post's workflow status lets it be published (E2).
	 *
	 * An unknown stored status does not pass; a new post (id `0`) has the
	 * default status, which does not pass.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $post_id Post id; `0` for a post not yet inserted.
	 * @param string $mode    Gate mode the attempt is judged under.
	 * @return bool
	 */
	public function passes( int $post_id, string $mode ): bool {
		$status = $this->posts->get_status( $post_id );
		$passes = ! ( $post_id > 0 && $this->posts->has_unknown_status( $post_id ) )
			&& in_array( $status, self::PUBLISHABLE_STATUSES, true );

		/**
		 * Filters whether a publish attempt passes the gate.
		 *
		 * Only boolean `true` passes. Return `false` or a `WP_Error` to refuse;
		 * in 1.1 a `WP_Error` is treated exactly like `false` and its message is
		 * not shown anywhere yet.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $passes          Whether the workflow status is publishable.
		 * @param int    $post_id         Post id; `0` for a post not yet inserted.
		 * @param string $workflow_status Workflow status judged on.
		 * @param string $mode            Gate mode: `log_only` or `enforce`.
		 * @return bool|\WP_Error
		 */
		$filtered = apply_filters( 'sit_cwm_publish_gate_passes', $passes, $post_id, $status, $mode );

		return true === $filtered;
	}

	/**
	 * Judges one publish attempt by a user (roadmap §3.1A mode table).
	 *
	 * Pure: never writes, never fires actions. Exceptions are not caught here;
	 * the enforcement layers catch them and fail open (E5).
	 *
	 * @since 1.1.0
	 *
	 * @param int    $post_id   Post id; `0` for a post not yet inserted.
	 * @param string $post_type Post type slug.
	 * @param int    $user_id   Acting user id.
	 * @return GateDecision
	 */
	public function evaluate( int $post_id, string $post_type, int $user_id ): GateDecision {
		$mode = $this->mode_for( $post_type, $post_id );

		if ( self::MODE_OFF === $mode ) {
			return GateDecision::not_applicable( self::MODE_OFF );
		}

		$status = $this->posts->get_status( $post_id );

		if ( $this->passes( $post_id, $mode ) ) {
			return new GateDecision( GateDecision::OUTCOME_ALLOWED, $mode, $status, GateDecision::REASON_APPROVED );
		}

		if ( self::MODE_LOG_ONLY === $mode ) {
			return new GateDecision( GateDecision::OUTCOME_BYPASSED, $mode, $status, GateDecision::REASON_LOG_ONLY );
		}

		if ( $this->permissions->can_bypass_publish_gate( $user_id ) ) {
			return new GateDecision( GateDecision::OUTCOME_BYPASSED, $mode, $status, GateDecision::REASON_CAPABILITY );
		}

		return new GateDecision( GateDecision::OUTCOME_REFUSED, $mode, $status, GateDecision::REASON_NONE );
	}

	/**
	 * Gate state of a post for a user, as an editor hint (E10).
	 *
	 * Not applicable when the post is already `publish`/`future`: updating it
	 * is not a transition into a published status (E4). The layers re-decide.
	 *
	 * Pure: never writes, never fires actions.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @param int $user_id User id.
	 * @return array{mode: string, outcome: string}
	 */
	public function describe( int $post_id, int $user_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return array(
				'mode'    => self::MODE_OFF,
				'outcome' => GateDecision::OUTCOME_NOT_APPLICABLE,
			);
		}

		$mode = $this->mode_for( $post->post_type, (int) $post->ID );

		if ( in_array( $post->post_status, self::PUBLISH_STATUSES, true ) ) {
			return array(
				'mode'    => $mode,
				'outcome' => GateDecision::OUTCOME_NOT_APPLICABLE,
			);
		}

		return array(
			'mode'    => $mode,
			'outcome' => $this->evaluate( (int) $post->ID, $post->post_type, $user_id )->outcome(),
		);
	}
}
