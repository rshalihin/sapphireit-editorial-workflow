<?php
/**
 * Front-end review disclosure.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Disclosure;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Governance\AiOrigin;
use Sit_Cwm\Publishing\PublishGate;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Reviewed by X on date" plus the declared AI-use line, for published
 * content whose approval still stands (F11).
 *
 * Shows only what the audit trail supports: the latest approval row names
 * the approver and date, and nothing is shown for content that was never
 * approved or has left the approved states since. Public data by design, so
 * no capability check; the shortcode and block only ever ask about the
 * current post.
 *
 * @since 1.2.0
 * @internal
 */
final class ReviewDisclosure {

	/**
	 * Plugin settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.2.0
	 * @var PostRepository
	 */
	private $posts;

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
	 * @param Settings       $settings Plugin settings.
	 * @param PostRepository $posts    Workflow meta persistence.
	 * @param ActivityLogger $activity Activity history.
	 */
	public function __construct( Settings $settings, PostRepository $posts, ActivityLogger $activity ) {
		$this->settings = $settings;
		$this->posts    = $posts;
		$this->activity = $activity;
	}

	/**
	 * What the disclosure may say about a post.
	 *
	 * Null unless, in this order: the setting is on, the post's type is in
	 * the workflow, it is published, its workflow status is approved or
	 * published, and an approval row exists.
	 *
	 * @since 1.2.0
	 *
	 * @param int $post_id Post id.
	 * @return array{approver_id: int, approver_name: string, approved_at: string, ai_origin: string}|null
	 *         `approver_name` is `''` when the approver no longer exists;
	 *         `approved_at` is UTC `Y-m-d H:i:s`. Not escaped.
	 */
	public function data_for( int $post_id ): ?array {
		if ( ! $this->settings->is_disclosure_enabled() || ! $this->posts->is_managed( $post_id ) ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( ! in_array( $this->posts->get_status( $post_id ), PublishGate::PUBLISHABLE_STATUSES, true ) ) {
			return null;
		}

		$approval = $this->activity->latest_for_post( $post_id, 'status_changed', 'approved' );

		if ( null === $approval ) {
			return null;
		}

		$approver = $approval->get_user_id() > 0 ? get_userdata( $approval->get_user_id() ) : false;

		return array(
			'approver_id'   => $approval->get_user_id(),
			'approver_name' => false !== $approver ? (string) $approver->display_name : '',
			'approved_at'   => $approval->get_created_at(),
			'ai_origin'     => $this->posts->get_ai_origin( $post_id ),
		);
	}

	/**
	 * The disclosure markup for a post.
	 *
	 * @since 1.2.0
	 *
	 * @param int  $post_id       Post id.
	 * @param bool $show_reviewer Whether to include the "Reviewed by" line.
	 * @param bool $show_ai       Whether to include the AI-use line.
	 * @return string Escaped HTML, or `''` when there is nothing to say.
	 */
	public function render( int $post_id, bool $show_reviewer = true, bool $show_ai = true ): string {
		if ( ! $show_reviewer && ! $show_ai ) {
			return '';
		}

		$data = $this->data_for( $post_id );

		if ( null === $data ) {
			return '';
		}

		$lines = array();

		if ( $show_reviewer && '' !== $data['approver_name'] ) {
			$timestamp = strtotime( $data['approved_at'] . ' UTC' );
			$date      = false !== $timestamp ? wp_date( (string) get_option( 'date_format' ), $timestamp ) : false;

			if ( false !== $date ) {
				$lines[] = sprintf(
					'<p class="%1$s">%2$s</p>',
					esc_attr( 'sit-cwm-disclosure__review' ),
					sprintf(
						/* translators: 1: approver's display name, 2: approval date. */
						esc_html__( 'Reviewed by %1$s on %2$s.', 'sapphireit-editorial-workflow' ),
						esc_html( $data['approver_name'] ),
						esc_html( $date )
					)
				);
			}
		}

		$ai_line = $show_ai ? $this->ai_line( $data['ai_origin'] ) : '';

		if ( '' !== $ai_line ) {
			$lines[] = sprintf( '<p class="%1$s">%2$s</p>', esc_attr( 'sit-cwm-disclosure__ai' ), esc_html( $ai_line ) );
		}

		if ( array() === $lines ) {
			return '';
		}

		return sprintf( '<div class="%1$s">%2$s</div>', esc_attr( 'sit-cwm-disclosure' ), implode( '', $lines ) );
	}

	/**
	 * The AI-use sentence for a declared origin (F16).
	 *
	 * @since 1.2.0
	 *
	 * @param string $origin A value from `AiOrigin::VALUES`, or `''`.
	 * @return string Not escaped; `''` when nothing was declared.
	 */
	private function ai_line( string $origin ): string {
		$lines = array(
			AiOrigin::HUMAN        => __( 'This content was written by a person.', 'sapphireit-editorial-workflow' ),
			AiOrigin::AI_ASSISTED  => __( 'This content was created with AI assistance and reviewed by a person.', 'sapphireit-editorial-workflow' ),
			AiOrigin::AI_GENERATED => __( 'This content was generated with AI and reviewed by a person.', 'sapphireit-editorial-workflow' ),
		);

		return $lines[ $origin ] ?? '';
	}
}
