# 19 — Testing sweep

**Goal:** close the gaps left by per-step tests and add the end-to-end flow.
Tests were written as each step landed (CLAUDE.md); this step is the audit, not
the first time testing happens.
**Prerequisites:** all feature steps.
**Dev plan reference:** Phase 20.

## Files to create

```
tests/php/bootstrap.php                       (from step 02, extend)
tests/php/TestCase.php                        shared base: factories, cap helpers
tests/php/Traits/CreatesWorkflowPosts.php
tests/e2e/editorial-flow.spec.js
tests/e2e/dashboard.spec.js
.github/workflows/ci.yml
```

## 19.1 Coverage audit

Go class by class and record actual coverage. Required minimums before release:

| Area | Minimum |
|---|---|
| `Workflow/` (state machine + permissions + manager) | 90 % lines, **100 % of branches in `can_transition`/`transition`** |
| `Activity/`, `Content/` | 80 % |
| `REST/` | every route: happy path, unauthenticated, unauthorized, invalid input |
| JS hooks (`useWorkflow`, `useActivity`, `usePosts`, `useBulkAction`) | loading/success/error each |
| JS components | render + one interaction each |

Generate with `phpunit --coverage-text` (Xdebug or PCOV) and record the numbers
in DEVELOPMENT.md.

## 19.2 Test helpers

- `TestCase` extends `WP_UnitTestCase` and provides
  `create_user_with_caps( array $caps )`, `create_managed_post( array $args )`,
  `assert_activity_count( int $post_id, int $expected )`,
  `assert_status( int $post_id, string $expected )`.
- A trait for the six-user fixture (admin, editor, author, contributor,
  reviewer, subscriber) built once per test class.

## 19.3 The end-to-end flow (dev plan Phase 20)

`tests/e2e/editorial-flow.spec.js` with `@wordpress/e2e-test-utils-playwright`:

1. Admin creates a post, opens the sidebar → status is `Draft`.
2. Author moves it to `Writing`.
3. Author assigns a reviewer and a due date.
4. Author submits for `Review`.
5. Reviewer sends it back → `Needs Changes`, leaving a comment.
6. Author returns it to `Writing`, then `Review`.
7. Reviewer approves → `Approved`.
8. Editor marks `Published`.
9. Timeline shows all eight events in order with the right actors.
10. Dashboard lists the post with the final status and reviewer.

`dashboard.spec.js`: filter by status, sort by due date, bulk-approve two posts,
verify the summary notice and the resulting rows.

## 19.4 Negative/regression suite (these must never silently regress)

- Direct `POST /wp/v2/posts/<id>` writing `meta._sit_cwm_status` → rejected.
- Author `POST` approving → 403, meta unchanged.
- Skipping the state machine (`writing → published`) → 400.
- Stale `from` → 409.
- Bulk with an unauthorized id → that id fails, others succeed.
- Every `sit-cwm/v1` route has a non-`__return_true` `permission_callback`.
- SQL-injection attempts in `orderby`, `order`, `search`, activity `action`.

## 19.5 CI

`.github/workflows/ci.yml`, matrix PHP 7.4 / 8.1 / 8.3 × WP 6.8 / latest:
- `composer install`, `composer lint`, `composer test`
- `npm ci`, `npm run lint:js`, `npm run test:unit`, `npm run build`
- E2E on one matrix leg only (`wp-env` + Playwright), artifacts on failure.
- Fail the build on any phpcs error — no warnings-only mode.

## Acceptance criteria

- Full suite green locally and in CI on every matrix leg.
- Coverage minimums met and recorded.
- The E2E flow passes from a clean `wp-env` twice in a row (no order dependence).
- Deleting `assets/build/` and running `npm run build` reproduces byte-stable
  enough output for CI to pass (no uncommitted build drift).
