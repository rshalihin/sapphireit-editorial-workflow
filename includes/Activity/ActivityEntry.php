<?php
/**
 * Activity entry value object.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Activity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row of the activity table (D8), immutable once built.
 *
 * @since 1.0.0
 */
final class ActivityEntry {

	/**
	 * Row id.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $id;

	/**
	 * Post id.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $post_id;

	/**
	 * Acting user id; `0` for the system.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	private $user_id;

	/**
	 * Action slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $action;

	/**
	 * Previous value, if the action has one.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private $old_value;

	/**
	 * New value, if the action has one.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private $new_value;

	/**
	 * Message (comment body), already passed through `wp_kses_post()`.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private $message;

	/**
	 * Extra structured data.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $context;

	/**
	 * Creation time, UTC, `Y-m-d H:i:s`.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $created_at;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $id         Row id.
	 * @param int         $post_id    Post id.
	 * @param int         $user_id    Acting user id; `0` for the system.
	 * @param string      $action     Action slug.
	 * @param string|null $old_value  Previous value.
	 * @param string|null $new_value  New value.
	 * @param string|null $message    Message.
	 * @param array       $context    Extra structured data.
	 * @param string      $created_at Creation time, UTC, `Y-m-d H:i:s`.
	 */
	public function __construct(
		int $id,
		int $post_id,
		int $user_id,
		string $action,
		?string $old_value,
		?string $new_value,
		?string $message,
		array $context,
		string $created_at
	) {
		$this->id         = $id;
		$this->post_id    = $post_id;
		$this->user_id    = $user_id;
		$this->action     = $action;
		$this->old_value  = $old_value;
		$this->new_value  = $new_value;
		$this->message    = $message;
		$this->context    = $context;
		$this->created_at = $created_at;
	}

	/**
	 * Builds an entry from a database row.
	 *
	 * `context` is decoded as JSON only; stored PHP-serialized data is never
	 * unserialized and reads as an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Row as returned by `$wpdb->get_results()`.
	 * @return self
	 */
	public static function from_row( object $row ): self {
		$context = array();

		if ( isset( $row->context ) && is_string( $row->context ) && '' !== $row->context ) {
			$decoded = json_decode( $row->context, true );
			$context = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			isset( $row->id ) ? (int) $row->id : 0,
			isset( $row->post_id ) ? (int) $row->post_id : 0,
			isset( $row->user_id ) ? (int) $row->user_id : 0,
			isset( $row->action ) ? (string) $row->action : '',
			self::nullable_string( $row->old_value ?? null ),
			self::nullable_string( $row->new_value ?? null ),
			self::nullable_string( $row->message ?? null ),
			$context,
			isset( $row->created_at ) ? (string) $row->created_at : ''
		);
	}

	/**
	 * Plain array form, for REST responses and hook consumers.
	 *
	 * @since 1.0.0
	 *
	 * @return array{id: int, post_id: int, user_id: int, action: string, old_value: string|null, new_value: string|null, message: string|null, context: array, created_at: string}
	 */
	public function to_array(): array {
		return array(
			'id'         => $this->id,
			'post_id'    => $this->post_id,
			'user_id'    => $this->user_id,
			'action'     => $this->action,
			'old_value'  => $this->old_value,
			'new_value'  => $this->new_value,
			'message'    => $this->message,
			'context'    => $this->context,
			'created_at' => $this->created_at,
		);
	}

	/**
	 * Row id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Post id.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_post_id(): int {
		return $this->post_id;
	}

	/**
	 * Acting user id; `0` for the system.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public function get_user_id(): int {
		return $this->user_id;
	}

	/**
	 * Action slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_action(): string {
		return $this->action;
	}

	/**
	 * Previous value.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_old_value(): ?string {
		return $this->old_value;
	}

	/**
	 * New value.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_new_value(): ?string {
		return $this->new_value;
	}

	/**
	 * Message (comment body). Escape or `wp_kses_post()` again at render time.
	 *
	 * @since 1.0.0
	 *
	 * @return string|null
	 */
	public function get_message(): ?string {
		return $this->message;
	}

	/**
	 * Extra structured data.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * Creation time, UTC, `Y-m-d H:i:s`. Localize only at render time.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_created_at(): string {
		return $this->created_at;
	}

	/**
	 * Casts a nullable column value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Column value.
	 * @return string|null
	 */
	private static function nullable_string( $value ): ?string {
		return null === $value ? null : (string) $value;
	}
}
