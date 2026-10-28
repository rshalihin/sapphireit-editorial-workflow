# 06 — TransitionManager (the state machine)

**Goal:** pure, side-effect-free answer to "is `$from → $to` a structurally legal
move?" — no permissions, no database, no WordPress state.
**Prerequisites:** 05. Implements decision **D4**.
**Dev plan reference:** Phase 3.

## Files to create

```
includes/Workflow/TransitionManager.php
tests/php/unit/TransitionManagerTest.php
```

## Why this is separate from PermissionManager

Two independent questions must not be tangled:
*structural* legality (this class) and *authorization* (step 07).
`WorkflowManager` (step 10) is the only place they meet. Keeping them apart is
what lets Pro swap the transition map without touching permission code.

## Tasks

1. Define the map exactly as D4, as a class constant
   `const MAP = [ 'draft' => [ 'writing' ], ... ]`, passed through
   `apply_filters( 'sit_cwm_transition_map', self::MAP )`.
2. Validate the filtered map defensively: keys and values must be statuses known
   to `StatusManager`; drop unknown ones; drop self-transitions.
3. Contract:
   ```php
   public function __construct( StatusManager $statuses );
   public function map(): array;
   public function targets_for( string $from ): array;        // legal next slugs
   public function is_valid( string $from, string $to ): bool;
   public function describe( string $from, string $to ): array; // slug, label, is_forward, is_rollback
   ```
4. `describe()` classifies each transition so the UI can style it without
   knowing the graph: `is_rollback` true for `review → needs_changes`,
   `approved → needs_changes`, `published → writing`.
5. Constructor-inject `StatusManager`; no static access, no globals.

## Acceptance criteria

Unit tests cover the full matrix (6 × 6 = 36 pairs):
- Every pair in D4 → `is_valid()` true; every other pair false.
- `is_valid( 'review', 'review' )` false (no self-transition).
- `is_valid( 'writing', 'approved' )` false — skipping review is impossible
  regardless of who asks.
- `is_valid( 'bogus', 'review' )` and `is_valid( 'review', 'bogus' )` false.
- `targets_for( 'review' )` === `[ 'approved', 'needs_changes' ]` (order stable).
- Filter test: replacing the map with a two-status flow works; a filter
  introducing an unknown status is sanitized away.
- No test in this file touches `$wpdb`, `wp_set_current_user()`, or meta —
  if one does, logic leaked into the wrong class.
