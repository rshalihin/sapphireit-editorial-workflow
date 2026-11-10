# Changelog

All notable changes to SapphireIT Editorial Workflow are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.2.0 - 2026-11-10

"Seams + governance." Add-ons can now change the workflow per post without
editing core, and site owners can show who approved what, how AI was used,
and export the whole audit trail.

Every new feature is off until a site owner turns it on. Upgrading from 1.1.0
changes no behaviour and needs no database migration: the schema is unchanged,
`sit_cwm_db_version` stays `1.0.0`, and no capability is added. Existing posts
read *Not declared* for AI use.

### Added

**Declared AI use**

- Each post can record how AI was used: *Human-written*, *AI-assisted* or
  *AI-generated*. It is declared by people, never detected: the plugin never
  inspects content.
- Set it from the editor sidebar, the classic editor meta box, the
  `sit-cwm/v1` workflow route (`ai_origin`) or the new `wp/v2` field
  `sit_cwm_ai_origin`, so an agent can declare it when it creates a post.
- An optional per-post-type default stamps new posts. A value declared on
  create always wins over the default.
- Every change is written to the activity history as *AI use declared*, with
  how it was made (`workflow`, `post_api` or `default`). A declaration can be
  changed but not withdrawn back to *Not declared*.
- Publication log rows record the AI use at the moment of publishing.

**Review attestation**

- Optional *Review attestation* setting (*Require approvers to confirm they
  reviewed the content*). When it is on, approving needs the approver to tick
  a confirmation, in the sidebar,
  the classic meta box and the dashboard bulk action alike.
- Approving without it returns `sit_cwm_attestation_required` (HTTP 422). A
  user who may not approve still gets 403.
- The confirmation and its exact wording are stored with the approval in the
  activity history.

**Front-end review disclosure**

- Opt-in *Review disclosure* block and `[sit_cwm_review_disclosure]` shortcode
  that print "Reviewed by {name} on {date}." and the declared AI use.
- Shown only on published content that was approved in the workflow and is
  still approved, so the front end never claims a review the audit trail
  cannot back. Off by default; the block is hidden from the inserter while the
  setting is off.

**Governance report**

- New *Governance report* screen under the Workflow menu, for users who can
  view activity and manage workflows (administrators by default). Over 30, 90
  or 365 days it shows how much was published after approval, publishing the
  gate blocked or let through, declared AI use now and at publication, and
  overdue content.
- Backed by `GET /sit-cwm/v1/reports/governance`.

**CSV audit export**

- Export the full activity history as CSV from the dashboard or the report,
  for the same users. Streamed in batches, so large histories don't exhaust
  memory. Cells that start with `=`, `+`, `-`, `@`, tab or CR are neutralised
  so spreadsheets open them as text.

**Admin**

- A shared header and tabs across every plugin screen, with a refreshed
  dashboard, settings and report.
- A *Getting Started* page, opened from the header's *Help* link, with setup
  steps worked out from your real settings, plus links to tutorials and docs.
- The Freemius SDK, in WordPress.org-compliant mode. New installs are asked
  once, by administrators only, whether to share usage data; *Skip* is always
  available, and sites upgrading from 1.1 are not asked. Nothing is sold or
  promoted. See the readme FAQ for what opting in shares.

**Extensibility (frozen add-on API)**

- The status registry, transition and rollback maps and the capability map
  receive the post id, so an add-on can give different posts different
  workflows. Results are memoised per post.
- `sit_cwm_can_transition` may return a `WP_Error`; its message reaches the
  sidebar verbatim, and the workflow response gains `blocked_transitions`.
- New action `sit_cwm_loaded` and function `sit_cwm_container()` give add-ons
  a safe bootstrap point and the service container.
- New filters: `sit_cwm_posts_query_args`, `sit_cwm_require_attestation` and
  `sit_cwm_admin_menu_position`. New actions: `sit_cwm_ai_origin_changed` and
  `sit_cwm_fs_loaded`.
- Every class is tagged `@api` or `@internal`, and the public surface is
  listed in [ARCHITECTURE.md](ARCHITECTURE.md). Breaking an `@api` signature
  now needs a major version. All changes are additive, so 1.0 and 1.1
  extensions keep working.

### Changed

- The *Editorial Workflow* admin menu now sits right after *Comments*
  (position 26) instead of at the bottom of the sidebar. Move it with the new
  `sit_cwm_admin_menu_position` filter.
- `uninstall.php` is gone; the same cleanup now runs through Freemius's
  uninstall hook. Data handling is unchanged: workflow data is deleted only
  when *Delete all workflow data* is on.
- The settings screen's Help pull-down moved to the Getting Started page.
- The workflow status post meta schema uses a `pattern` instead of a fixed
  `enum`, so add-on statuses validate.
- The WordPress.org listing, README, WORKFLOW.md, REST-API.md and
  ARCHITECTURE.md cover the new features, with an agent recipe (n8n and MCP)
  for declaring AI use over REST. Screenshots were replaced.

## 1.1.0 - 2026-10-29

"Enforce what it promises." The workflow can now stop unapproved content from
going live, every publication is on the record, and the people involved can be
told by email when something needs them.

Every new feature is off until a site owner turns it on. Upgrading from 1.0.0
changes no behaviour and needs no database migration: the schema is unchanged
and `sit_cwm_db_version` stays `1.0.0`.

### Added

**Publish gate**

- Per-post-type publish gate in the *Publish gate* settings section, with three
  modes: *Off* (the default), *Log only* (publishing below Approved is allowed
  and recorded as a bypass) and *Enforce* (content has to be Approved or
  Published in the workflow before it can be published or scheduled).
- *Enforce* covers every route that publishes through WordPress:
  - the block editor, which shows **Submit for Review** instead of **Publish**;
  - the classic editor, Quick Edit and Bulk Edit;
  - `wp/v2` REST, which refuses with `sit_cwm_publish_gated` (HTTP 403);
  - `wp_insert_post()` / `wp_update_post()` from anywhere, including WP-CLI;
  - scheduled publishing, judged again at go-live, so a post sent back for
    changes after it was scheduled never goes live.

  A backstop on `transition_post_status` reverts anything else that publishes
  a post without passing those checks, such as a direct `wp_publish_post()`.
- A live post sent back for re-review stays live, and an already scheduled post
  is not re-judged on unrelated edits.
- New capability `sit_cwm_bypass_publish_gate`, granted to administrators only.
  Its holders can still publish unapproved content in *Enforce* mode, and each
  such publish is recorded. Existing sites receive it on the first request
  after updating, without reactivation. Capabilities a site owner removed are
  not restored.
- The gate fails open: if it throws, the publish goes ahead, is recorded as a
  bypass with reason `error`, and fires `sit_cwm_publish_gate_failed`.
- The editor sidebar and the classic editor meta box tell the current user
  whether publishing is locked or will be recorded as a bypass. A refused
  classic or scheduled publish leaves an admin notice saying what the post was
  saved as instead.
- The `sit-cwm/v1` workflow response gains `publish_gate` (`mode`, `outcome`).

**Publication log**

- Every publication and unpublication of workflow-enabled content is written to
  the activity history in every gate mode, *Off* included. Each entry records
  the actor, the workflow status at that moment, the gate mode and the source
  (admin, REST, AJAX, cron, WP-CLI).
- Gate refusals and bypasses are recorded too. The activity timeline labels
  them *Publishing blocked* and *Published without approval*.
- REST activity items gain `workflow_status`, `workflow_status_label` and
  `gate_reason`, which are `null` for the 1.0 actions.

**Email notifications**

- Opt-in, site-level plain-text emails in the *Email notifications* settings
  section, one switch per event: a reviewer is assigned, content is submitted
  for review, changes are requested, content is approved, and a workflow
  comment is added. All are off by default.
- Each event goes to the assigned reviewer or the post author, as its label
  says. Submitting for review with no reviewer assigned emails nobody.
- Nobody is emailed about their own action. Users who cannot read the post, or
  have no valid email address, are skipped. Comment text is included only for
  recipients allowed to view the activity history.
- Exactly one email per recipient per event: a repeat of the same event within
  two minutes is not sent again. Each email is written in the recipient's own
  language.

**Self-approval prevention**

- Optional *Prevent people from approving their own content* setting. When it
  is on, nobody can move content they authored to Approved, administrators
  included, by any route: sidebar, classic meta box, dashboard bulk action and
  `sit-cwm/v1` REST. The author is also left out of their own post's reviewer
  list.
- A refused self-approval returns `sit_cwm_self_approval` (HTTP 403), and the
  workflow panel explains why Approve is not offered.
- In *Enforce* mode an administrator can still publish through the bypass
  capability, which is recorded, so a one-person site is never stuck.

**Extensibility**

- New filters: `sit_cwm_publish_gate_mode`, `sit_cwm_publish_gate_passes`,
  `sit_cwm_notification_recipients`, `sit_cwm_notification_message` and
  `sit_cwm_prevent_self_approval`.
- New actions: `sit_cwm_publish_gate_refused`, `sit_cwm_publish_gate_bypassed`,
  `sit_cwm_publish_gate_failed` and `sit_cwm_notification_sent`.
- All are documented in [ARCHITECTURE.md](ARCHITECTURE.md).

**Admin**

- A *Settings* link on the plugin's row in the Plugins screen, leading to the
  workflow settings, for users who can manage them.
- On the plugin's dashboard and settings screens, the admin footer shows the
  plugin's version and invites a WordPress.org review.

### Changed

- The WordPress.org listing, README, WORKFLOW.md, REST-API.md and
  ARCHITECTURE.md now cover the publish gate, the publication log and email
  notifications. The "sends no email" and "does not gate publishing" caveats
  are gone.
- All five WordPress.org screenshots were replaced.

### Fixed

- Clicks on the due date, the reviewer picker, "Add comment" and workflow
  actions were silently lost while another workflow change was still saving,
  because those controls were disabled (or ignored input) for the whole
  request. They now stay usable: the date picker and confirmation dialogs open
  straight away, and a date, reviewer, comment or transition chosen meanwhile
  is sent as soon as the earlier save finishes. A queued transition is dropped
  if the server no longer offers it, and a queued comment if its text was
  cleared.

### Known limitations

Documented in [WORKFLOW.md](WORKFLOW.md):

- The gate cannot see direct `$wpdb` writes to `post_status`, or code that
  removes the plugin's hooks.
- In *Enforce* mode, WP-CLI without `--user` and imports run from cron act as
  no user and are refused. Run them as a user with the bypass capability
  (`wp --user=<admin>`).
- Self-approval prevention compares against `post_author` only. Someone who
  rewrote a post they are not the author of can still approve it.
- Choosing which roles may approve is not a setting. `sit_cwm_approve_content`
  is a normal capability that a role-editor plugin can grant or remove.

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
