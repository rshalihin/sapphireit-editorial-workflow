# 05 — StatusManager

**Goal:** one authoritative registry of workflow statuses with labels, order,
colours and validation. Nothing else in the codebase hard-codes a status string.
**Prerequisites:** 03. Implements decision **D3**.
**Dev plan reference:** Phase 3 (statuses half).

## Files to create

```
includes/Workflow/StatusManager.php
tests/php/unit/StatusManagerTest.php
```

## Tasks

1. Define the status registry as a private method returning an array keyed by
   slug (labels wrapped in `esc_html__( ..., 'sit-cwm' )`, so build it lazily on
   `init`, not as a class constant — translations aren't loaded at file-parse
   time):
   ```php
   'review' => [
       'slug'        => 'review',
       'label'       => __( 'Review', 'sit-cwm' ),
       'description' => __( 'Awaiting reviewer feedback.', 'sit-cwm' ),
       'color'       => '#996800',   // used by the sidebar + DataViews badge
       'order'       => 30,
       'is_final'    => false,
   ],
   ```
   Orders: draft 10, writing 20, review 30, needs_changes 40, approved 50,
   published 60.
2. Run the whole registry through `apply_filters( 'sit_cwm_statuses', $statuses )`
   and re-key/validate the result (drop entries missing `slug`/`label`) so a
   badly behaved Pro filter can't corrupt core.
3. Public contract:
   ```php
   public function all(): array;                       // slug => definition
   public function slugs(): array;                     // ordered list of slugs
   public function get( string $slug ): ?array;
   public function exists( string $slug ): bool;
   public function label( string $slug ): string;      // falls back to the slug
   public function default_status(): string;           // 'draft'
   public function is_final( string $slug ): bool;
   public function sanitize( $value ): string;         // sanitize_key + whitelist, else default
   ```
4. `sanitize()` is the shared sanitizer used by `register_post_meta()` (step 08)
   and every REST arg — one implementation, no duplicates.
5. Memoise the resolved registry per request; expose `flush()` for tests.

## Acceptance criteria

- Unit tests (no WP bootstrap needed beyond function stubs):
  - `all()` returns exactly the six D3 slugs in `order` sequence.
  - `sanitize( 'APPROVED' )`, `sanitize( 'bogus' )`, `sanitize( null )`,
    `sanitize( [ 'review' ] )` → `'draft'`; `sanitize( ' review ' )` → `'review'`.
  - `exists( 'needs_changes' )` true, `exists( 'needs-changes' )` false.
  - A filter adding `legal_review` makes it appear in `all()`; a filter returning
    a non-array or malformed entries leaves core statuses intact.
- `grep -rn "'review'" includes/` shows status literals only in StatusManager
  and its tests.
