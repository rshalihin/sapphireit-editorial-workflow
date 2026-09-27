# Changelog

All notable changes to Content Workflow Manager are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing yet.

## [1.0.0] - 2026-09-17

First public release. Free v1.0 feature scope, complete.

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

**Editor and admin UI**

- Gutenberg sidebar: status, reviewer, due date, transition buttons and the
  activity timeline, with confirmations on rollbacks and on publishing.
- Admin dashboard built on `@wordpress/dataviews`, with server-side search,
  filtering, sorting and pagination, row actions and bulk actions.
- Activity timeline grouped by day.
- Settings screen for choosing which post types the workflow applies to.

**Project**

- Full translation coverage with the `sit-cwm` text domain and a generated POT.
- PHPUnit (unit + integration), Jest and Playwright suites; CI across
  PHP 7.4/8.1/8.3 × WordPress 6.8/latest.
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

[Unreleased]: https://github.com/shappire-it/content-workflow-manager/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/shappire-it/content-workflow-manager/releases/tag/v1.0.0
