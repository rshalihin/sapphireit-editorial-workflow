# SapphireIT Editorial Workflow — Build Plan Report

Plain-language explanation of what each file in `.claude/.plan/` does. Files
are meant to be built **in numeric order** — each one is a self-contained change,
and later steps assume earlier ones are already done. See `00-INDEX.md` for
the authoritative dependency table and progress checklist.

## Milestones (how the 24 steps group together)

| Milestone | Steps | What it delivers |
|-----------|-------|------------------|
| **M1 — Headless engine** | 02–10 | Workflow transitions, permissions, meta, activity log — all provable with PHPUnit alone, no UI |
| **M2 — API** | 11–12 | A complete backend usable from `curl`/wp-cli without React |
| **M3 — Editor UX** | 13–15 | Gutenberg sidebar + activity timeline |
| **M4 — Dashboard** | 16–18 | Admin DataViews table, filters, bulk actions, settings |
| **M5 — Ship** | 19–23 | Tests, security, performance, polish, docs |

**Progress (2026-09-28):** steps 02–23 are done; see the checklist in
`00-INDEX.md`. What remains for v1.0 is the developer's part of
`24-remaining-v1-tasks.md` (section G): commit and push, get CI green, do the
screen-reader pass from `DEVELOPMENT.md`, then tag `v1.0.0`.

---

## 00 — INDEX
The master map of the whole plan: build order, dependency table, milestones,
and a progress checklist. Start here every time — it says what to build next
and what it depends on.

## 01 — Decisions and conventions
The **frozen rulebook**. Locks in every naming choice (prefixes, classes,
tables), the 6 workflow statuses, the exact transition map (which status can
move to which), which capability is required for which action, the post-meta
keys, the activity table schema, the full REST surface, and every WordPress
hook/filter the plugin fires. Every later step just implements what's decided
here — nobody re-decides naming mid-project; if a step forces a deviation,
this file gets updated first.

## 02 — Repo scaffold and tooling
Builds the empty skeleton: main plugin file, `composer.json`, phpcs config,
PHPUnit config, `.gitignore`, `.editorconfig`, `.wp-env.json`, and the folder
structure. Goal: an inert plugin that already lints clean and "passes" 0
tests — the foundation everything else builds on.

## 03 — Plugin bootstrap / Core
The engine room: a `Plugin` class plus a tiny dependency-injection
`Container` that wires up every future service in the right order, and
`Activator`/`Deactivator` classes for the activate/deactivate lifecycle
(creating things, flushing rewrite rules). Nothing hooks WordPress inside a
constructor, so everything stays unit-testable.

## 04 — Data model + activation
Creates the actual custom database table (`wp_sit_cwm_activity`) with a
version-tracked, auto-upgrading installer (`dbDelta`), plus a `Settings`
class wrapping the plugin's one option (which post types are managed,
whether to delete data on uninstall).

## 05 — StatusManager
The single source of truth for the 6 workflow statuses (Draft, Writing,
Review, Needs Changes, Approved, Published) — their labels, colors, sort
order, and a shared sanitizer/validator. Nothing else in the codebase is
allowed to hard-code a status string.

## 06 — TransitionManager
The pure state machine. Answers only one question: "is moving from status A
to status B structurally legal?" (e.g., you can't jump from Writing straight
to Approved). Knows nothing about permissions or the database — kept
deliberately separate so Pro can swap the transition graph without touching
authorization code.

## 07 — Capabilities + PermissionManager
Defines the 6 custom capabilities (e.g. `sit_cwm_approve_content`) and which
roles get them by default at activation. `PermissionManager` answers "is
*this user* allowed to do *this action* on *this post*?" — the one and only
place `current_user_can()`-style logic lives; no role-name checks scattered
elsewhere.

## 08 — PostMeta + PostRepository
Registers the 3 post-meta fields (status, reviewer, due date) with
`register_post_meta()`, with sanitization and REST visibility. Notably locks
direct REST writes to the status field (`auth_callback` returns false) so
status can only ever change through the real workflow engine, not by faking
a plain REST call. `PostRepository` becomes the only class allowed to
read/write these fields, including batch-loading methods that prevent slow
N+1 queries later.

## 09 — ActivityLogger
Writes an append-only audit trail to the activity table for every workflow
event (status changes, reviewer assignment, comments), using only prepared
queries, with automatic cleanup when a post is permanently deleted (not
trashed).

## 10 — WorkflowManager (the orchestrator)
The single door every workflow mutation must pass through. Combines
*structural* validity (06) with *authorization* (07), guards against two
people racing to change the same post (optimistic concurrency / 409
conflict), persists via the repository (08), and logs the event (09). The
REST API, sidebar, and dashboard are all "thin clients" over this class —
none of them decide anything on their own.

## 11 — REST: WorkflowController
Exposes the workflow engine over `sit-cwm/v1` as a thin HTTP layer with zero
business logic of its own — every route delegates to `WorkflowManager`.
Covers `GET/POST /posts/{id}/workflow` and `GET /statuses`. Every route has a
real `permission_callback` (never `__return_true`), strict argument schemas,
and correct HTTP status codes (401/403/404/400/409).

## 12 — REST: Activity, comments, users, dashboard collection
The remaining endpoints the React apps need: paginated activity timeline +
add-comment (`ActivityController`), an assignable-reviewers list that's
capability-gated to prevent user enumeration (`UserController`), and the
dashboard's filterable/sortable/paginated post collection
(`PostsController`) — built to stay under a tight query budget via batch
loading.

## 13 — JS build setup
Sets up `@wordpress/scripts` to build two entry points (sidebar +
dashboard), with ESLint/Prettier/Jest configured before any component
exists. PHP enqueues read the generated `.asset.php` files instead of
hand-maintaining dependency arrays. Also defines the single
`window.sitCwm` bootstrap object (statuses, capabilities, REST namespace)
passed safely from PHP to JS.

## 14 — Gutenberg sidebar
The editor-side workflow panel. Centerpiece is the `useWorkflow()` hook,
which fetches/mutates workflow state, handles the 409 concurrency conflict,
and is *not* optimistic (waits for the real server response — the server is
the source of truth). Components: status badge, transition action buttons,
reviewer combobox, due-date picker, comment form — each hidden when the
current user lacks the relevant server-checked capability.

## 15 — Activity timeline UI
A reusable, grouped, paginated history component (used by both the sidebar
and later the dashboard). Groups entries by day in the site's timezone
(never raw browser-local time), builds translatable sentences per action
type, and handles loading/empty/error states plus deleted-user fallbacks
("Someone").

## 16 — Admin dashboard (DataViews)
A top-level admin screen listing every workflow-managed post in a
`@wordpress/dataviews` table — server-side pagination, sorting, and
filtering (by status, reviewer, due date, etc.), with per-row actions that
are only a UX courtesy (the server re-checks everything). Filter/sort/page
state is reflected into the URL so views are linkable.

## 17 — Filters and bulk actions
Lets users multi-select rows and apply one action (status change, assign
reviewer, set due date) to all of them at once, via
`POST /sit-cwm/v1/posts/batch`. Critically, the server re-runs the **full
single-item permission and validation path per post** — no shortcut bulk
SQL update — so bulk actions can't become a privilege-escalation hole.
Partial success is reported honestly (some succeed, some listed as failed
with reasons), never silently swept under "success."

## 18 — Settings page
A minimal settings screen: which post types the workflow applies to, and
whether to delete workflow data on uninstall. Built on WordPress's Settings
API (not a hand-rolled AJAX/admin-post handler) so nonces and capability
checks come for free. Disabling a post type stops the workflow applying but
never deletes existing data.

## 19 — Testing sweep
Not the first time tests are written (that happens per-step) — this is the
audit and the end-to-end pass. Sets required coverage minimums per layer
(90%+ on the workflow engine, 100% branch coverage on the core
transition/permission logic), adds shared test fixtures/helpers, writes a
full Playwright end-to-end editorial flow (draft → ... → published) and a
dashboard E2E spec, a negative/regression suite (the security tests that
must never silently break), and a CI workflow matrix across PHP/WP versions.
**Done:** coverage is measured with Xdebug through `composer test:coverage`,
and the results are in `DEVELOPMENT.md` → "Coverage".

## 20 — Security hardening review
A deliberate, checklist-driven, line-by-line audit of the whole codebase
against CLAUDE.md's security rules — because this plugin decides who can
approve and publish content. Checks authorization ordering, input
sanitization, output escaping, safe SQL, no `eval`/`unserialize`, and
information-disclosure rules (e.g., a post you can't see returns 404, not
403, so you can't confirm it exists). Ends with grep-based verification and
a manual "attack" pass logged in in a subscriber session.
**Done:** that pass is automated as `tests/e2e/subscriber-probe.spec.js`, and
its results are in `ARCHITECTURE.md`.

## 21 — Performance pass (kill the N+1)
Seeds a realistic dataset (500 posts, 5,000 activity rows) and measures real
query counts/timing against fixed budgets (e.g., the dashboard collection
must stay ≤ 8 queries regardless of page size). Applies the batch-loading
fixes planned back in steps 08/09/12 (batch meta cache, batch user lookups,
batch "last activity" lookups), tunes `WP_Query` usage, and adds a
regression-guarding PHPUnit test that fails if someone reintroduces a
per-row query. **Done:** `bin/measure.php` produces the measurements, and the
budgets and `EXPLAIN` output are in `DEVELOPMENT.md`. A long activity history no
longer slows the dashboard (8.3 s → 96 ms).

## 22 — UX polish, accessibility and i18n
Makes every state legible: loading, saving, empty, error, forbidden,
offline, and stale-conflict states across the sidebar, timeline, and
dashboard. Defines behavior for every edge case (deleted reviewer, deleted
post, revoked permission mid-session, invalid stored status). Adds shared
confirmation dialogs for destructive/rollback actions, accessibility passes
(keyboard-only flow, screen reader, contrast, focus management), and full
internationalization (translatable strings, `.pot` file, RTL support, and
localized dates). **Done:** the axe, keyboard and UI-state specs pass. Two
default badge colours were darkened so every badge is at least 4.5:1 contrast.
RTL was checked on a live site. `DEVELOPMENT.md` → "Accessibility" holds the
contrast table and the NVDA script for the developer's screen-reader pass.

## 23 — Documentation and Free v1.0 release
Turns the repo into a "portfolio-ready" package: README with
screenshots/architecture diagram, `ARCHITECTURE.md` (why workflow status is
separate from WP post status, the transition pipeline, class
responsibilities), `WORKFLOW.md` (the status graph and who can do what),
`REST-API.md` (every endpoint with examples), `DEVELOPMENT.md` (setup/build/
test commands and measured perf numbers), a WordPress.org-style
`readme.txt`, and the actual release mechanics (version bump in 4 places,
build, changelog, zip, tag).

---

## Out of scope for v1.0 (do not build)

Email notifications, Slack integration, editorial calendar, multiple
workflows, role-configurable workflows, checklist gating, a rules engine,
AI features, team management, and a custom post type for managed content.
Pro plans, including the former step 24 (Pro extension points audit), live
in `.claude/pro/` and are not part of the Free build.
