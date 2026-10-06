# Changelog

All notable changes to SapphireIT Editorial Workflow are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Fixed

- Clicks on the due date, the reviewer picker, "Add comment" and workflow
  actions were silently lost while another workflow change was still saving,
  because those controls were disabled (or ignored input) for the whole
  request. They now stay usable: the date picker and confirmation dialogs open
  straight away, and a date, reviewer, comment or transition chosen meanwhile
  is sent as soon as the earlier save finishes. A queued transition is dropped
  if the server no longer offers it, and a queued comment if its text was
  cleared.

## 1.0.0 - 2026-09-17

First public release. Free v1.0 feature scope, complete.

Plugin renamed from Content Workflow Manager before first release.

Requires WordPress 6.8 or later and PHP 7.4 or later. 6.8 is the oldest core
the bundled `@wordpress/dataviews` 11.3.0 runs on, and
`tests/php/integration/AssetCompatTest.php` fails the build if a built script
depends on a handle 6.8 does not register.

### Added

**Workflow engine**

- Six-status editorial workflow: Draft → Writing → Review → Needs Changes →
  Approved → Published, with a fixed transition graph and three explicit
  rollback edges.
- `WorkflowManager` as the single entry point for every workflow mutation.
  REST controllers, bulk actions and the React UI are thin clients over it.
- Transition validation split between `TransitionManager` (is the edge in the
  graph?) and `PermissionManager` (may this user take it?), combined only in
  `WorkflowManager`.
- Optimistic concurrency: a status change must state the status the client
  believed was current, or it fails with HTTP 409 and writes nothing.
- Six `sit_cwm_*` capabilities, granted to the administrator, editor, author and
  contributor roles at activation. No role names are hard-coded anywhere else.
- Reviewer assignment restricted to users holding `sit_cwm_review_content`.
- Due dates, with an *Overdue only* filter computed in the site timezone.

**Data**

- Workflow status, reviewer and due date stored as registered post meta
  (`_sit_cwm_status`, `_sit_cwm_reviewer_id`, `_sit_cwm_due_date`), kept
  entirely separate from the native WordPress post status.
- `_sit_cwm_status` locked against direct meta writes through both
  `auth_callback` and `map_meta_cap`, so the state machine cannot be bypassed.
- Activity history in a dedicated indexed table, `{prefix}sit_cwm_activity`,
  with six action types and a `(post_id, created_at)` key.
- Workflow comments stored in that table rather than in `wp_comments`.

**REST API** (`sit-cwm/v1`)

- `GET|POST /posts/{id}/workflow`, `GET /posts/{id}/activity`,
  `POST /posts/{id}/comments`, `GET /statuses`, `GET /posts`,
  `POST /posts/batch`, `GET /users`.
- Every route has a real `permission_callback`; every argument has a type, a
  sanitize callback and a validate callback or enum.
- Bulk endpoint applies one action to up to 100 posts, re-running the full
  single-item authorization per post.
- Every route publishes a response schema, including `GET /statuses`, and
  `SchemaContractTest` checks each response against its `OPTIONS` schema.

**Performance**

- `ActivityLogger::get_for_posts()` (the dashboard's *Last activity* column)
  reads each post's newest entry with one `LIMIT 1` index scan per post. The
  first version used a correlated subquery that was quadratic in history. With
  a 3 000-row history on the page, `GET /posts?per_page=100` drops from 8.3 s to
  96 ms. Budgets and
  `EXPLAIN` output are in [DEVELOPMENT.md](DEVELOPMENT.md#performance).

**Editor and admin UI**

- Gutenberg sidebar: status, reviewer, due date, transition buttons and the
  activity timeline, with confirmations on rollbacks and on publishing.
- Classic editor support: the same workflow panel in an *Editorial Workflow*
  meta box on `post.php` / `post-new.php` when a post type uses the classic
  editor (Classic Editor plugin, WooCommerce products). Changes save
  immediately through REST, independently of **Update**. The settings screen
  no longer marks these types as "dashboard only".
- Workflow comments can be submitted with Ctrl/Cmd+Enter in both editors.
- Admin dashboard built on `@wordpress/dataviews`, with server-side search,
  filtering, sorting and pagination, row actions and bulk actions.
- Activity timeline grouped by day.
- Settings screen for choosing which post types the workflow applies to.
- Status badges meet 4.5:1 contrast. The default Review (`#996800`) and
  Approved (`#008a20`) colours are darker than in the pre-release builds.
  RTL stylesheets are generated and loaded for RTL locales.

**Project**

- Full translation coverage with the `sapphireit-editorial-workflow` text domain and a generated POT.
- PHPUnit (unit + integration), Jest and Playwright suites; CI across
  PHP 7.4/8.1/8.3 × WordPress 6.8/latest, with end-to-end legs on WordPress
  6.8 and latest. The Playwright suite includes axe, keyboard-only, UI-state and
  subscriber-probe specs.
- Dev only: `composer test:coverage` measures line coverage for both PHP suites
  and `WorkflowManager` branch coverage (Xdebug), and checks them against the
  release minimums. `bin/measure.php` times the hot REST paths against the
  performance budgets. Neither ships in the release zip.
- `assets/build/` committed, so the plugin runs from a clone or zip with no
  build step.

### Known limitations

Documented in [ARCHITECTURE.md](ARCHITECTURE.md#known-trade-offs):

- Multi-field updates are not transactional.
- No object caching; state is read fresh on every request.
- Dashboard filters use `meta_query`, which scales with `wp_postmeta`.
- The 409 guard closes the read-modify-write window, not a microsecond-scale
  write race.
- Bulk actions are bounded at 100 posts and run synchronously.
- The activity log is append-only and is never pruned.

### Not included by design

v1.0 sends **no email**, and reaching the `published` workflow status does **not
publish the post**. Email, Slack, the editorial calendar, multiple workflows,
checklist gating, a rules engine and scheduled publishing are roadmap items, not
omissions.
