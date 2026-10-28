# 09 — ActivityLogger

**Goal:** append-only audit trail in `wp_sit_cwm_activity`, with safe reads and
a hook for future notification channels.
**Prerequisites:** 04. Implements decision **D8**.
**Dev plan reference:** Phase 8.

## Files to create

```
includes/Activity/ActivityLogger.php
includes/Activity/ActivityEntry.php
tests/php/integration/ActivityLoggerTest.php
```

## Tasks

1. **`ActivityEntry`** — a small readonly-ish value object (PHP 7.4: private
   props + getters) wrapping one row: `id, post_id, user_id, action, old_value,
   new_value, message, context (array), created_at`. Static
   `from_row( object $row ): self` and `to_array(): array` for REST output.
   `context` decoded with `json_decode( $json, true )`, never `unserialize()`.
2. **`ActivityLogger::log()`**
   ```php
   public function log(
       int $post_id,
       string $action,
       array $args = []   // old_value, new_value, message, context, user_id, created_at
   ): int;                 // inserted id, 0 on failure
   ```
   - Validate `$action` against the closed set in D8, filtered through
     `sit_cwm_activity_actions`; unknown action → return `0`, do not insert.
   - `user_id` defaults to `get_current_user_id()`; `0` means system.
   - `created_at` defaults to `current_time( 'mysql', true )` (UTC — always UTC
     in storage, localize only at render).
   - `message` sanitized with `wp_kses_post()`; `old_value`/`new_value` with
     `sanitize_text_field()` and truncated to 191 chars.
   - Insert with `$wpdb->insert()` and an explicit `$format` array — never string
     concatenation.
   - On success: `do_action( 'sit_cwm_activity_logged', $id, $entry )`.
3. **Convenience loggers** (thin wrappers so callers can't mistype an action):
   `log_status_change( $post_id, $from, $to, $user_id )`,
   `log_reviewer_assigned( $post_id, $new_id, $old_id, $user_id )`,
   `log_due_date_changed( $post_id, $new, $old, $user_id )`,
   `log_comment( $post_id, $message, $user_id )`.
4. **Reads**
   ```php
   public function get_for_post( int $post_id, array $args = [] ): array; // ActivityEntry[]
   public function count_for_post( int $post_id, array $args = [] ): int;
   public function get_for_posts( array $post_ids, int $per_post = 1 ): array; // batch latest-N
   public function delete_for_post( int $post_id ): int;
   ```
   - `$args`: `per_page` (default 20, max 100), `page`, `action` filter,
     `order` (`ASC|DESC`, default `DESC`).
   - Every query built with `$wpdb->prepare()`; `ORDER BY` direction chosen from
     a whitelist, never interpolated from input; `LIMIT`/`OFFSET` via `%d`.
   - `get_for_posts()` uses one window-free query (`WHERE post_id IN (...)`
     with a prepared placeholder list built from `array_fill()`), for the
     dashboard's "last activity" column — step 21 depends on this.
5. **Cleanup hooks:** `before_delete_post` → `delete_for_post()` so deleting a
   post doesn't orphan rows. Do not delete on trash — only on permanent delete.
6. No caching in v1.0; note in the file docblock that an object-cache layer is a
   Pro/perf follow-up so the read API stays stable.

## Acceptance criteria

- `log()` with each valid action inserts exactly one row with the right columns;
  an invalid action inserts nothing and returns `0`.
- `message` containing `<script>alert(1)</script>` is stored stripped of the
  script tag; `<strong>` survives.
- `get_for_post()` respects pagination and returns newest-first by default.
- Attempted SQL injection via `$args['order'] = '1; DROP TABLE'` is ignored.
- Permanently deleting a post removes its rows; trashing does not.
- `get_for_posts( range( 1, 50 ) )` executes exactly one query.
