# Content Workflow Manager — Build Plan Index

Step-by-step implementation plan for **Free v1.0**, derived from
`Content Workflow Manager — Development Plan.md` and constrained by `CLAUDE.md`.

Read `01-decisions-and-conventions.md` before writing any code — it freezes the
naming, class-style, and data-shape decisions that every later step assumes.

## How to use this plan

- Work the steps **in numeric order**. Each step file lists: goal, prerequisites,
  files touched, detailed tasks, the public contract (method/route signatures),
  acceptance criteria, and tests.
- Do not start a step whose prerequisites are unchecked.
- **Git commits are made by the developer, never by the AI assistant.** Finish a
  step, verify it, report the changed files, and leave staging/committing to
  the developer.
- Every step ends green: `composer lint` (phpcs, zero errors), `composer test`
  (PHPUnit), and `npm run lint:js` where JS exists.
- If a step forces a deviation from `01-decisions-and-conventions.md`, update
  that file as part of the same step — it is the single source of truth.

## Build order (mirrors the dev plan's "Actual Build Order")

| # | Step | Layer | Depends on |
|---|---|---|---|
| 02 | [Repo scaffold and tooling](02-repo-scaffold-and-tooling.md) | infra | — |
| 03 | [Plugin bootstrap / Core](03-plugin-bootstrap-core.md) | PHP core | 02 |
| 04 | [Data model + activation](04-data-model-and-activation.md) | DB | 03 |
| 05 | [StatusManager](05-status-manager.md) | workflow | 03 |
| 06 | [TransitionManager](06-transition-engine.md) | workflow | 05 |
| 07 | [Capabilities + PermissionManager](07-capabilities-and-permissions.md) | workflow | 05, 06 |
| 08 | [PostMeta + PostRepository](08-post-meta-and-repository.md) | content | 04, 05 |
| 09 | [ActivityLogger](09-activity-logger.md) | activity | 04 |
| 10 | [WorkflowManager (orchestrator)](10-workflow-manager.md) | workflow | 06, 07, 08, 09 |
| 11 | [REST: WorkflowController](11-rest-workflow-controller.md) | REST | 10 |
| 12 | [REST: Activity + Users + collection](12-rest-activity-and-users.md) | REST | 10, 11 |
| 13 | [JS build setup](13-js-build-setup.md) | infra | 02 |
| 14 | [Gutenberg sidebar](14-gutenberg-sidebar.md) | React | 11, 12, 13 |
| 15 | [Activity timeline UI](15-activity-timeline-ui.md) | React | 12, 14 |
| 16 | [Admin dashboard (DataViews)](16-admin-dashboard-dataviews.md) | React | 12, 13 |
| 17 | [Filters + bulk actions](17-filters-and-bulk-actions.md) | React + REST | 16 |
| 18 | [Settings page](18-settings-page.md) | admin | 08 |
| 19 | [Testing sweep](19-testing.md) | quality | all |
| 20 | [Security hardening review](20-security-hardening-review.md) | quality | all |
| 21 | [Performance pass (N+1)](21-performance-pass.md) | quality | 16, 17 |
| 22 | [UX polish + i18n](22-ux-polish-i18n.md) | UX | 14–18 |
| 23 | [Docs + release](23-docs-and-release.md) | release | all |

## Progress tracker

Tick a box only when that step's acceptance criteria all pass.

- [x] 02 Repo scaffold and tooling  _(activation check run on local Laragon WP 7.1, not wp-env)_
- [x] 03 Plugin bootstrap / Core
- [x] 04 Data model + activation
- [x] 05 StatusManager
- [x] 06 TransitionManager
- [x] 07 Capabilities + PermissionManager
- [x] 08 PostMeta + PostRepository
- [x] 09 ActivityLogger
- [x] 10 WorkflowManager
- [x] 11 REST: WorkflowController
- [x] 12 REST: Activity + Users + collection
- [x] 13 JS build setup
- [x] 14 Gutenberg sidebar
- [x] 15 Activity timeline UI
- [x] 16 Admin dashboard (DataViews)
- [x] 17 Filters + bulk actions
- [x] 18 Settings page
- [x] 19 Testing sweep  _(PHPUnit unit 104 + integration 199, Jest 164; coverage measured with Xdebug and `composer test:coverage` green: `DEVELOPMENT.md` → "Coverage" — `Workflow/` 95.5 %, 100 % status-change branches; re-verified 2026-09-28, see `24-remaining-v1-tasks.md`)_
- [x] 20 Security hardening review  _(checklist in `20-security-hardening-review.md` ticked; `tests/e2e/subscriber-probe.spec.js` passes, results in `ARCHITECTURE.md` → "Subscriber probe")_
- [x] 21 Performance pass  _(`bin/measure.php`; budgets, timings and `EXPLAIN` in `DEVELOPMENT.md` → "Performance"; `get_for_posts()` long-history fix 8.3 s → 96 ms)_
- [x] 22 UX polish + i18n  _(axe/keyboard/ui-states specs pass; badge contrast table, RTL check and NVDA script in `DEVELOPMENT.md` → "Accessibility"; `.pot` regenerated; the manual screen-reader pass itself is the developer's, step G of `24-remaining-v1-tasks.md`)_
- [x] 23 Docs + release  _(docs, screenshots, zip tooling and release.yml done and verified locally; the tag push, the clean-WP install check and the full CI matrix are the developer's)_

## Out of scope for v1.0 (do not build)

Email, Slack, editorial calendar, multiple workflows, role-configurable
workflows, checklist gating, rules engine, AI, team management, a custom post
type for managed content. Pro plans (including the former step 24, the Pro
extension points audit) live in `../pro/` and are not part of this build.

## Milestones

- **M1 — Headless engine (steps 02–10).** Workflow transitions, permissions,
  meta, and activity work with zero UI; provable by PHPUnit alone.
- **M2 — API (steps 11–12).** A complete backend application usable from
  `wp-cli`/curl without React.
- **M3 — Editor UX (steps 13–15).** Gutenberg sidebar + timeline.
- **M4 — Dashboard (steps 16–18).** DataViews table, filters, bulk actions,
  settings.
- **M5 — Ship (steps 19–23).** Tests, security, performance, polish, docs.
