# 08 — PostMeta + PostRepository

**Goal:** registered, sanitized, REST-aware meta; and a repository that is the
only code touching `get_post_meta`/`update_post_meta` for workflow fields.
**Prerequisites:** 04, 05. Implements decision **D6**.
**Dev plan reference:** Phase 2, build order step 7.

## Files to create

```
includes/Content/PostMeta.php
includes/Content/PostRepository.php
tests/php/integration/PostMetaTest.php
tests/php/integration/PostRepositoryTest.php
```

## Tasks

1. **`PostMeta implements Bootable`** — `register()` hooks `init` and calls
   `register_post_meta()` once per enabled post type for the three D6 keys:
   ```php
   register_post_meta( $post_type, '_sit_cwm_status', [
       'type'              => 'string',
       'single'            => true,
       'default'           => $this->statuses->default_status(),
       'show_in_rest'      => [ 'schema' => [ 'enum' => $this->statuses->slugs() ] ],
       'sanitize_callback' => [ $this->statuses, 'sanitize' ],
       'auth_callback'     => static function () { return false; },
   ] );
   ```
   - `_sit_cwm_status` `auth_callback` returns **false** on purpose: the status
     may only change through `WorkflowManager::transition()`, so core's meta REST
     endpoint and `wp.data` cannot bypass the state machine (CLAUDE.md: "never
     trust client-supplied state"). Add a comment saying exactly that so nobody
     "fixes" it later.
   - `_sit_cwm_reviewer_id`: integer, sanitize `absint` + `get_userdata()` check
     (unknown user → `0`), `auth_callback` → `PermissionManager::can_assign_reviewer`.
   - `_sit_cwm_due_date`: string, sanitize via a shared
     `sanitize_due_date( $value ): string` helper —
     `DateTimeImmutable::createFromFormat( 'Y-m-d', $v )` plus a round-trip
     equality check to reject `2026-02-31`; empty string clears;
     `auth_callback` → `can_set_due_date`.
2. Meta is registered per enabled post type, not globally, so disabling a post
   type in settings removes the fields from its REST schema.
3. **`PostRepository`** — constructor takes `StatusManager`, `Settings`.
   ```php
   public function get_status( int $post_id ): string;              // default when unset
   public function set_status( int $post_id, string $status ): bool;
   public function get_reviewer_id( int $post_id ): int;
   public function set_reviewer_id( int $post_id, int $user_id ): bool;   // 0 clears
   public function get_due_date( int $post_id ): string;
   public function set_due_date( int $post_id, string $date ): bool;      // '' clears
   public function get_workflow( int $post_id ): array;             // all three + post basics
   public function is_managed( int $post_id ): bool;                // post exists + type enabled
   public function get_statuses_for_posts( array $post_ids ): array; // batch, one query
   public function get_reviewers_for_posts( array $post_ids ): array;// batch, one query
   ```
4. **Batch methods matter** — they are what step 21 uses to kill the dashboard
   N+1. Implement with a single `update_meta_cache( 'post', $post_ids )` call
   followed by `get_post_meta()` reads (cache-warm, zero extra queries), not a
   hand-written SQL join.
5. `set_*` writers deliberately do **no** permission checking — they are the
   persistence layer. Authorization happens in `WorkflowManager`/REST above them.
   Put a docblock `@internal Callers must authorize first.` on each.
6. Deleting a user: hook `deleted_user` → clear `_sit_cwm_reviewer_id` on posts
   that referenced them and log a `reviewer_cleared` activity entry
   (system user id `0`). Use a bounded `WP_Query` with `meta_key`/`meta_value`.

## Acceptance criteria

- Meta appears in `GET /wp/v2/posts/<id>` for enabled types only.
- A direct `POST /wp/v2/posts/<id>` with `meta._sit_cwm_status` is **rejected**
  (auth_callback false) — explicit integration test; this is a security
  regression guard, not a nicety.
- An authorized user can set reviewer/due date through core meta REST; an
  unauthorized one gets 403.
- `set_due_date( $id, '2026-02-31' )` stores `''`; `'2026-09-20'` round-trips.
- `set_reviewer_id( $id, 999999 )` (no such user) stores `0`.
- `get_statuses_for_posts()` over 50 posts adds ≤ 2 queries (assert with
  `$wpdb->num_queries`).
