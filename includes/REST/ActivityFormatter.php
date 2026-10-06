<?php
/**
 * REST presentation of activity entries.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

use DateTimeImmutable;
use DateTimeZone;
use Sit_Cwm\Activity\ActivityEntry;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shapes an ActivityEntry for the activity timeline and the dashboard's
 * "last activity" column.
 *
 * Users are never looked up here: callers collect ids with `user_ids()`, load
 * them once with UserSummaries, and pass the map to `format()`.
 *
 * @since 1.0.0
 */
final class ActivityFormatter {

	/**
	 * Actions whose old/new values are reviewer user ids.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const REVIEWER_ACTIONS = array( 'reviewer_assigned', 'reviewer_cleared' );

	/**
	 * Actions whose old/new values are `Y-m-d` dates.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const DUE_DATE_ACTIONS = array( 'due_date_set', 'due_date_cleared' );

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
	 * @param StatusManager $statuses Status registry.
	 */
	public function __construct( StatusManager $statuses ) {
		$this->statuses = $statuses;
	}

	/**
	 * Every user id `format()` needs for a set of entries: the actors and the
	 * reviewers named in reviewer changes.
	 *
	 * @since 1.0.0
	 *
	 * @param ActivityEntry[] $entries Entries.
	 * @return int[] Unique positive ids.
	 */
	public function user_ids( array $entries ): array {
		$ids = array();

		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof ActivityEntry ) {
				continue;
			}

			$ids[] = $entry->get_user_id();

			if ( in_array( $entry->get_action(), self::REVIEWER_ACTIONS, true ) ) {
				$ids[] = absint( $entry->get_old_value() );
				$ids[] = absint( $entry->get_new_value() );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * REST item for one entry.
	 *
	 * @since 1.0.0
	 *
	 * @param ActivityEntry $entry Entry.
	 * @param array         $users User id => summary, from UserSummaries::load().
	 * @return array{id: int, action: string, action_label: string, old_value: string|null, old_label: string|null, new_value: string|null, new_label: string|null, message: string, created_at: string, created_at_human: string, user_id: int, user: array|null}
	 */
	public function format( ActivityEntry $entry, array $users ): array {
		$action  = $entry->get_action();
		$user_id = $entry->get_user_id();
		$message = $entry->get_message();
		$created = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $entry->get_created_at(), new DateTimeZone( 'UTC' ) );

		return array(
			'id'               => $entry->get_id(),
			'action'           => $action,
			'action_label'     => $this->action_label( $action ),
			'old_value'        => $entry->get_old_value(),
			'old_label'        => $this->value_label( $action, $entry->get_old_value(), $users ),
			'new_value'        => $entry->get_new_value(),
			'new_label'        => $this->value_label( $action, $entry->get_new_value(), $users ),
			// Stored already sanitized; filtered again so a tampered row cannot inject markup.
			'message'          => null === $message ? '' : wp_kses_post( $message ),
			'created_at'       => false === $created ? '' : $created->format( DATE_ATOM ),
			'created_at_human' => false === $created ? '' : sprintf(
				/* translators: %s: Human-readable time difference, e.g. "2 hours". */
				__( '%s ago', 'sapphireit-editorial-workflow' ),
				human_time_diff( $created->getTimestamp(), time() )
			),
			// Lets the timeline tell the system (0) from a deleted user (id without summary).
			'user_id'          => $user_id,
			'user'             => $user_id > 0 && isset( $users[ $user_id ] ) ? $users[ $user_id ] : null,
		);
	}

	/**
	 * Human-readable label for an action slug. Not escaped; escape at output.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action Action slug.
	 * @return string Label, or the slug itself when none is registered.
	 */
	public function action_label( string $action ): string {
		$core = array(
			'status_changed'    => __( 'Status changed', 'sapphireit-editorial-workflow' ),
			'reviewer_assigned' => __( 'Reviewer assigned', 'sapphireit-editorial-workflow' ),
			'reviewer_cleared'  => __( 'Reviewer removed', 'sapphireit-editorial-workflow' ),
			'due_date_set'      => __( 'Due date set', 'sapphireit-editorial-workflow' ),
			'due_date_cleared'  => __( 'Due date removed', 'sapphireit-editorial-workflow' ),
			'comment_added'     => __( 'Comment added', 'sapphireit-editorial-workflow' ),
		);

		/**
		 * Filters the labels of activity actions, e.g. for actions added through
		 * `sit_cwm_activity_actions`.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $labels Action slug => label.
		 */
		$labels = apply_filters( 'sit_cwm_activity_action_labels', $core );

		if ( ! is_array( $labels ) ) {
			$labels = $core;
		}

		return isset( $labels[ $action ] ) && is_string( $labels[ $action ] ) && '' !== $labels[ $action ] ? $labels[ $action ] : $action;
	}

	/**
	 * Label for an old/new value, depending on the action.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $action Action slug.
	 * @param string|null $value  Stored value.
	 * @param array       $users  User id => summary.
	 * @return string|null Null when there is no value.
	 */
	private function value_label( string $action, ?string $value, array $users ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		if ( 'status_changed' === $action ) {
			return $this->statuses->label( $value );
		}

		if ( in_array( $action, self::REVIEWER_ACTIONS, true ) ) {
			$id = absint( $value );

			return isset( $users[ $id ] ) ? $users[ $id ]['name'] : __( 'Deleted user', 'sapphireit-editorial-workflow' );
		}

		if ( in_array( $action, self::DUE_DATE_ACTIONS, true ) ) {
			$utc  = new DateTimeZone( 'UTC' );
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $utc );

			// A calendar date, not an instant: format it in UTC so it never shifts a day.
			$label = false === $date ? false : wp_date( (string) get_option( 'date_format' ), $date->getTimestamp(), $utc );

			return false === $label ? $value : $label;
		}

		return $value;
	}
}
