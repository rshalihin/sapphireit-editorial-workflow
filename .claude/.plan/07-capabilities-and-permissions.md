# 07 — Capabilities + PermissionManager

**Goal:** every authorization question in the plugin has exactly one answer,
computed here. Nothing else calls `current_user_can()` for workflow decisions.
**Prerequisites:** 05, 06. Implements decision **D5**.
**Dev plan reference:** Phase 4, Phase 21.

## Files to create

```
includes/Workflow/Capabilities.php
includes/Workflow/PermissionManager.php
tests/php/integration/PermissionManagerTest.php
```

## Tasks

1. **`Capabilities`** — the six cap slugs as constants, `all(): array`, and the
   role→caps grant table from D5 as `role_map(): array`.
   - `add_caps(): void` — loop `get_role()` and `add_cap()`; called from
     `Activator`. Idempotent.
   - `remove_caps(): void` — called from `uninstall.php` only.
   - Never referenced outside activation/uninstall; runtime code checks caps, it
     does not manage them.
2. **`PermissionManager`** — constructor takes `StatusManager`,
   `TransitionManager`, `Settings`. Contract:
   ```php
   public function can_manage( ?int $user_id = null ): bool;
   public function can_view_activity( int $post_id, ?int $user_id = null ): bool;
   public function can_edit_post( int $post_id, ?int $user_id = null ): bool;
   public function can_assign_reviewer( int $post_id, ?int $user_id = null ): bool;
   public function can_set_due_date( int $post_id, ?int $user_id = null ): bool;
   public function can_comment( int $post_id, ?int $user_id = null ): bool;
   public function can_change_status( int $post_id, string $to, ?int $user_id = null ): bool;
   public function capability_for_status( string $to ): string;   // D5 table, filterable
   ```
3. **`can_change_status()` order of checks** (fail fast, cheapest first):
   1. `$user_id` resolves to an existing user (default: current user; `0` → false)
   2. post exists and its type is workflow-enabled (`Settings::is_post_type_enabled`)
   3. `user_can( $user_id, 'edit_post', $post_id )` — WP's own object-level check
   4. `user_can( $user_id, $this->capability_for_status( $to ) )`
   5. for `published`: also `user_can( $user_id, 'publish_post', $post_id )`
   6. result passed through
      `apply_filters( 'sit_cwm_can_transition', $allowed, $post_id, $from, $to, $user_id )`
4. **Structural validity is NOT checked here** — that's TransitionManager, and
   `WorkflowManager` combines both. This class answers "may this user reach this
   status", not "is this a legal edge".
5. Use `user_can( $user_id, ... )` rather than `current_user_can()` everywhere so
   the class is testable with arbitrary users and safe inside bulk loops.
6. `capability_for_status()` returns the D5 mapping through
   `apply_filters( 'sit_cwm_status_capability_map', $map )`; unknown status →
   `sit_cwm_manage_workflows` (deny-by-default for anyone but an admin).

## Acceptance criteria

Integration tests with real roles (`self::factory()->user->create`):
- Author can `writing`/`review`, cannot `approved`, cannot `needs_changes`.
- Editor can `approved` and `needs_changes` on a post they can edit.
- Author **cannot** approve someone else's post even with `sit_cwm_approve_content`
  granted directly, because `edit_post` fails → confirms check 3 runs.
- Subscriber: every method false.
- Logged-out (`user_id = 0`): every method false.
- Post of a non-enabled post type: every method false even for admin.
- Capability grants survive deactivate → reactivate without duplication.
- `sit_cwm_can_transition` filter can veto an otherwise-allowed transition
  (proves Pro can tighten rules) but **cannot** be the only gate — a test
  asserts a `true`-returning filter is still applied after, i.e. Pro can widen
  deliberately; document this in ARCHITECTURE.md.
