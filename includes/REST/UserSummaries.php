<?php
/**
 * Batched public user summaries for REST responses.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves user ids to public `{ id, name, avatar }` summaries in one query.
 *
 * Summaries never include login or email. The email is fetched only to build
 * the avatar URL: `get_avatar_url()` given a user id runs one `get_user_by()`
 * lookup per user, while given the already-loaded email it runs none.
 *
 * @since 1.0.0
 */
final class UserSummaries {

	/**
	 * User columns fetched; never exposed as-is.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const FIELDS = array( 'ID', 'display_name', 'user_email' );

	/**
	 * Avatar size in pixels.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const AVATAR_SIZE = 48;

	/**
	 * Summaries for many users, in a single `get_users()` query.
	 *
	 * Users are looked up network-wide, so history entries by users removed
	 * from the current site still show a name.
	 *
	 * @since 1.0.0
	 *
	 * @param array $user_ids User ids; invalid, zero and duplicate ids are ignored.
	 * @return array<int, array{id: int, name: string, avatar: string}> User id => summary;
	 *                                                                    missing users are absent.
	 */
	public function load( array $user_ids ): array {
		$ids = array();

		foreach ( $user_ids as $user_id ) {
			$id = is_bool( $user_id ) ? false : filter_var( $user_id, FILTER_VALIDATE_INT );

			if ( false !== $id && $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		if ( array() === $ids ) {
			return array();
		}

		$rows = get_users(
			array(
				'include'     => array_values( $ids ),
				'blog_id'     => 0,
				'fields'      => self::FIELDS,
				'number'      => count( $ids ),
				'count_total' => false,
			)
		);

		$summaries = array();

		foreach ( $rows as $row ) {
			$summary = $this->from_row( $row );

			if ( null !== $summary ) {
				$summaries[ $summary['id'] ] = $summary;
			}
		}

		return $summaries;
	}

	/**
	 * Summary from a `get_users()` row (field list or `WP_User`).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $row User row.
	 * @return array{id: int, name: string, avatar: string}|null Null when the row has no valid id.
	 */
	public function from_row( $row ): ?array {
		if ( ! is_object( $row ) || ! isset( $row->ID ) ) {
			return null;
		}

		$id = (int) $row->ID;

		if ( $id <= 0 ) {
			return null;
		}

		$email = isset( $row->user_email ) ? (string) $row->user_email : '';

		return array(
			'id'     => $id,
			'name'   => isset( $row->display_name ) ? (string) $row->display_name : '',
			'avatar' => (string) get_avatar_url( '' !== $email ? $email : $id, array( 'size' => self::AVATAR_SIZE ) ),
		);
	}
}
