# 10 — WorkflowManager (the orchestrator)

**Goal:** the single entry point for every workflow mutation. REST controllers,
the sidebar, bulk actions and future Pro code all go through this class — and
this is the class that combines *structural validity* with *authorization*.
**Prerequisites:** 06, 07, 08, 09.
**Dev plan reference:** Phase 3, Phase 7 (the full request pipeline).

## Files to create

```
includes/Workflow/WorkflowManager.php
tests/php/integration/WorkflowManagerTest.php
```

## Construction

```php
public function __construct(
	StatusManager     $statuses,
	TransitionManager $transitions,
	PermissionManager $permissions,
	PostRepository    $posts,
	ActivityLogger    $activity
);
```

## Contract

```php
public function get_status( int $post_id ): string;
public function get_workflow( int $post_id ): array;                 // REST-shaped state
public function get_available_transitions( int $post_id, ?int $user_id = null ): array;

public function can_transition( int $post_id, string $from, string $to, ?int $user_id = null ): bool;
public function transition( int $post_id, string $from, string $to, ?int $user_id = null );  // true|WP_Error

public function assign_reviewer( int $post_id, int $reviewer_id, ?int $user_id = null );     // true|WP_Error
public function set_due_date( int $post_id, string $date, ?int $user_id = null );            // true|WP_Error
public function add_comment( int $post_id, string $message, ?int $user_id = null );          // int id|WP_Error
```

## `transition()` pipeline — implement exactly in this order

1. `is_managed( $post_id )` → else `WP_Error( 'sit_cwm_not_managed', 404 )`.
2. Re-read the **current** status from the repository. If `$from` does not match
   it → `WP_Error( 'sit_cwm_status_conflict', 409 )`. This is the optimistic-
   concurrency guard: two editors racing in two tabs cannot both approve.
   (The client sends the `from` it saw; the server never takes its word for the
   current state — it compares.)
3. `$this->statuses->exists( $to )` → else `sit_cwm_invalid_status` (400).
4. `$this->transitions->is_valid( $from, $to )` → else
   `sit_cwm_invalid_transition` (400).
5. `$this->permissions->can_change_status( $post_id, $to, $user_id )` → else
   `sit_cwm_forbidden` (403).
6. Persist via `PostRepository::set_status()`.
7. `ActivityLogger::log_status_change()`.
8. `do_action( 'sit_cwm_status_changed', $post_id, $from, $to, $user_id )`.
9. Return `true`.

Steps 3–5 never run before 1–2; a 403 must never leak the existence of a post
the user cannot see (return 404 in that case — see step 20).

## `can_transition()`

Same checks as 2–5 but boolean, no writes, no actions. `transition()` must not
be implemented by calling `can_transition()` and then blindly writing — it
re-runs the checks itself so a caller cannot TOCTOU between the two.
Acceptable duplication: extract a private `validate( ... ): ?WP_Error` used by
both.

## `get_workflow()` return shape (mirrors D9 / dev plan Phase 5)

```php
[
	'post_id'               => 125,
	'post_title'            => 'Product Guide',
	'post_type'             => 'post',
	'post_status'           => 'draft',          // native WP status, kept distinct (D3)
	'edit_link'             => '…',
	'status'                => 'review',
	'status_label'          => 'Review',
	'reviewer'              => [ 'id' => 27, 'name' => 'John', 'avatar' => '…' ] | null,
	'due_date'              => '2026-09-20',
	'available_transitions' => [ [ 'slug' => 'approved', 'label' => 'Approve', 'is_rollback' => false ], … ],
	'capabilities'          => [ 'can_change_status' => true, 'can_assign_reviewer' => true, 'can_comment' => true, 'can_view_activity' => true ],
]
```

The `capabilities` block is what lets React hide controls **as a courtesy** —
the server still enforces everything. Say so in the docblock.

## `assign_reviewer()` / `set_due_date()` / `add_comment()`

Each: managed check → sanitize/validate input → `PermissionManager` check →
persist → log activity → `do_action` (D10) → return. `assign_reviewer( $id, 0 )`
clears and logs `reviewer_cleared`. Assigning the same reviewer twice is a no-op
that returns `true` without logging a duplicate entry.

## Acceptance criteria

Integration tests, one per bullet:
- Happy path `draft → writing → review → approved` as the right users.
- `review → published` rejected (400, invalid transition) even for an admin —
  proves structure is checked independently of capability.
- `review → approved` as an author rejected (403) — proves capability is checked
  independently of structure.
- Stale `from` (client says `writing`, DB says `review`) → 409, and the DB is
  unchanged.
- Every successful mutation writes exactly one activity row.
- Every failing mutation writes **zero** activity rows and fires no action.
- `sit_cwm_status_changed` fires once with `( $post_id, $from, $to, $user_id )`.
- Unmanaged post type → 404 for all six methods.
- `add_comment( $id, '' )` → 400; whitespace-only likewise.
