# Architecture

How SapphireIT Editorial Workflow is put together, and why. Read
[WORKFLOW.md](WORKFLOW.md) first if you want the editorial model rather than
the code.

## Contents

- [The layers](#the-layers)
- [Why workflow status is not WordPress post status](#why-workflow-status-is-not-wordpress-post-status)
- [Class-by-class](#class-by-class)
- [The dependency graph](#the-dependency-graph)
- [The `transition()` pipeline](#the-transition-pipeline)
- [Structure vs. authorization](#structure-vs-authorization)
- [Data model](#data-model)
- [Known trade-offs](#known-trade-offs)
- [Authorization](#authorization-sit_cwmworkflowpermissionmanager) *(detail)*
- [Orchestration](#orchestration-sit_cwmworkflowworkflowmanager) *(detail)*
- [Bulk actions](#bulk-actions-sit_cwmworkflowbulkprocessor) *(detail)*
- [Security review](#security-review-step-20) *(detail)*

---

## The layers

```
┌──────────────────────────────────────────────────────────────┐
│  React                                                       │
│  src/sidebar/   Gutenberg PluginSidebar                      │
│  src/classic/   classic editor meta box (same panel)         │
│  src/components/WorkflowPanel, shared by both editors        │
│  src/dashboard/ @wordpress/dataviews table + bulk actions    │
│  src/hooks/     useWorkflow, usePosts, useActivity, …        │
│  src/api/       the only apiFetch caller                     │
└───────────────────────────┬──────────────────────────────────┘
                            │  sit-cwm/v1  (REST, nonce/cookie or app password)
┌───────────────────────────▼──────────────────────────────────┐
│  REST controllers            includes/REST/                  │
│  Workflow · Activity · Posts · Batch · User                  │
│  Argument schemas, permission_callbacks, response shaping.   │
│  Zero business logic.                                        │
└───────────────────────────┬──────────────────────────────────┘
┌───────────────────────────▼──────────────────────────────────┐
│  WorkflowManager             includes/Workflow/              │
│  The only way workflow state ever changes.                   │
│    ├─ TransitionManager   is this edge in the graph?         │
│    ├─ PermissionManager   may this user do it?               │
│    ├─ StatusManager       what statuses exist?               │
│    └─ BulkProcessor       the same path, N times             │
└──────────┬────────────────────────────────┬──────────────────┘
┌──────────▼──────────────┐      ┌──────────▼──────────────────┐
│ PostRepository          │      │ ActivityLogger              │
│ includes/Content/       │      │ includes/Activity/          │
│ _sit_cwm_* post meta    │      │ wp_sit_cwm_activity table   │
└──────────┬──────────────┘      └──────────┬──────────────────┘
┌──────────▼─────────────────────────────────▼─────────────────┐
│  WordPress: postmeta, users, capabilities, $wpdb             │
└──────────────────────────────────────────────────────────────┘
```

**The UI never decides whether a transition is allowed.** This is the rule the
whole design hangs on:

- The sidebar renders buttons from `available_transitions` — a list the *server*
  computed for this user, on this post, from its *current* status.
- Clicking one sends `{ from, status }` back. The server does not trust either
  value: it re-reads the stored status, re-checks the graph, re-checks the
  capabilities, and only then writes.
- `capabilities` in a response is a display hint for hiding controls. Every
  mutating path re-derives the same answer before acting.

Consequence: a hostile client with a valid cookie and nonce can do exactly what
the UI lets an honest one do, and nothing more. Deleting the JavaScript would
not weaken a single rule.

**Editor layer: two thin clients over one panel.** The block editor sidebar
(`src/sidebar/`, loaded by `Editor\SidebarAssets`) and the classic editor meta
box (`src/classic/`, loaded by `Editor\ClassicMetaBox`) both render the same
`src/components/WorkflowPanel`. They only differ in how they are mounted: a
`PluginSidebar`, or a React root in an `Editorial Workflow` meta box. Only one
loads on a given screen, because `ClassicMetaBox` requires
`! use_block_editor_for_post()`. The meta box adds no PHP form, nonce field or
`save_post` handler. Every change goes through the same REST routes with the
`wp_rest` nonce and saves immediately. The post's **Update** button never
touches workflow state, which keeps the two status fields apart (see below).
This also works on `post-new.php`: the auto-draft core creates there already
has its final id, so workflow changes made before the first save survive it
(`tests/php/integration/REST/AutoDraftTest.php`).

---

## Why workflow status is not WordPress post status

The most consequential decision in the plugin (decision D3), and the one most
often gotten wrong by editorial plugins: **`_sit_cwm_status` never touches
`wp_posts.post_status`.** They are two independent fields that are allowed to
disagree.

It is tempting to collapse them. Register `cwm_review` and `cwm_approved` as
custom post statuses, and the workflow "just works" in the posts list. That
approach breaks in several places at once:

**1. Scheduled publishing.** A post scheduled for next Tuesday has
`post_status = future`. If the workflow owns that field, an editor approving the
post must write `approved` into it — destroying the schedule, and with it the
`_wp_cron` event WordPress created. Keeping them separate means a post can be
`future` *and* `approved`: WordPress publishes it on Tuesday, and the workflow
independently records that a human cleared it. Neither system has to know about
the other. The same argument applies to `private`, `pending`, and to whatever
the next core status turns out to be.

**2. Editing live content.** [Re-review flow](WORKFLOW.md#3-the-re-review-flow):
published article needs a correction, so it goes back to `writing`. If the two
fields were one, that would *unpublish* the article — a 404 for readers and,
briefly, for search engines. With separate fields the post stays `publish` while
the workflow cycles underneath it.

**3. Every other plugin's assumptions.** `post_status` is load-bearing core
infrastructure. Feeds, sitemaps, caches, REST `status` filters, `WP_Query`
defaults, SEO plugins and membership plugins all branch on it. A custom status
slug in that field means a post that is invisible to half of them, and the bug
reports arrive months later.

**4. Losing the audit trail.** One field can hold one fact. Two fields hold
"this is publicly published" *and* "this went through review twice and was
approved by Dana on the 12th" at the same time.

What this costs: the workflow status does not appear in the core posts-list
status filter, and **reaching `published` does not publish anything**. That
second point is stated plainly in the README, the sidebar and `readme.txt`,
because a plugin that silently publishes content would be worse than one that
does not. Turning `approved` into a scheduled publish is a Pro feature — it can
be built entirely on the `sit_cwm_status_changed` hook, without touching core.

---

## Class-by-class

### `includes/Core/`

| Class | Responsibility |
|---|---|
| `Plugin` | Wires every service into the `Container` and registers it in the right phase: `core` on `plugins_loaded`, `admin` only when `is_admin()`, `rest` on `rest_api_init` (priority 5) so controllers are never constructed on a front-end request. |
| `Container` | A minimal service locator: `set( $id, $factory )` / `get( $id )`, lazily instantiated, memoised. Not a DI framework — just enough to keep constructors explicit and classes testable. |
| `Interfaces\Bootable` | `register()`. The only thing `Plugin` needs to know about a service to boot it. |
| `Activator` / `Deactivator` | Create the activity table, grant capabilities, flush caches. `Activator::initialize_site()` also runs on `wp_initialize_site` for new multisite sites. |
| `Database` | Owns `{$wpdb->prefix}sit_cwm_activity`: `dbDelta()` schema, the `sit_cwm_db_version` option, and an upgrade check on `plugins_loaded` so a schema change does not need reactivation. |
| `Settings` | Reads `sit_cwm_settings`; `enabled_post_types()`, `available_post_types()` (offered: admin UI + REST + `editor` support) and `selectable_post_types()` (offered plus already-enabled types that are no longer offered, so saving never drops them). The `sit_cwm_enabled_post_types` and `sit_cwm_available_post_types` filters live here. |
| `Assets` | The single enqueue path. Reads each entry's generated `*.asset.php` for dependencies and version, uses the `sit-cwm-{entry}-{js,css}` handles, wires `wp_set_script_translations()`, and prints the `window.sitCwm` bootstrap with `wp_add_inline_script()`. |

### `includes/Workflow/`

| Class | Responsibility |
|---|---|
| `StatusManager` | The authoritative status registry. Nothing else hard-codes a slug. Filterable (`sit_cwm_statuses`) with the result validated, so a broken extension cannot corrupt core. Also the shared status sanitizer. |
| `TransitionManager` | Pure graph structure: `is_valid( $from, $to )`, `targets_for()`, `describe()`. No permissions, no database, no post state. |
| `PermissionManager` | Every authorization question in the plugin. The only class that answers "may this user…". Uses `user_can( $user_id, … )` throughout so it works for arbitrary users and inside bulk loops. |
| `Capabilities` | The six capability constants and the **only** place role names appear. `add_caps()`/`remove_caps()` run from activation and uninstall only. |
| `WorkflowManager` | The orchestrator, and the only writer. Combines the three above, persists through `PostRepository`, logs through `ActivityLogger`, fires the `sit_cwm_*` actions, and produces the REST-shaped read model (`get_workflow()`). |
| `BulkProcessor` | Loops up to 100 posts, calling the *same* `WorkflowManager` method a single request would. No bulk SQL, no hoisted permission check. |

### `includes/Content/`

| Class | Responsibility |
|---|---|
| `PostMeta` | `register_post_meta()` for the three keys, with `type`, `sanitize_callback`, `auth_callback`, `show_in_rest` and `context: [ 'edit' ]`. Also locks `_sit_cwm_status` against direct meta writes via `map_meta_cap`, so the state machine cannot be bypassed through core's meta endpoint. |
| `PostRepository` | All meta reads and writes, the due-date sanitizer, `is_managed()`, and the dashboard's `WP_Query`. The only place that knows the meta keys are strings. |

### `includes/Activity/`

| Class | Responsibility |
|---|---|
| `ActivityEntry` | An immutable value object for one row. |
| `ActivityLogger` | Writes and reads the activity table. `get_for_posts()` batch-loads the last entry of many posts in one query (the dashboard's N+1 fix). Fires `sit_cwm_activity_logged`. |

### `includes/REST/`

| Class | Responsibility |
|---|---|
| `AbstractController` | Shared argument schemas (`post_id`, pagination, due date), `require_login()`, `resolve_post()` (the uniform 404), `forbidden()`, `error_to_response()` (code → HTTP status) and `collection_response()` (the `X-WP-Total` headers). |
| `WorkflowController` | `GET|POST /posts/{id}/workflow`, `GET /statuses`. |
| `ActivityController` | `GET /posts/{id}/activity`, `POST /posts/{id}/comments`. |
| `PostsController` | `GET /posts` — the dashboard collection, batch-loaded. |
| `BatchController` | `POST /posts/batch`, delegating to `BulkProcessor`. |
| `UserController` | `GET /users` — assignable reviewers, deliberately narrow. |
| `ActivityFormatter` | Turns an `ActivityEntry` into its REST shape, resolving slugs and user IDs to labels. |
| `UserSummaries` | Batch user loading. One `get_users()` field-list query for every author, reviewer and activity actor on a page. |

### `admin/`

| Class | Responsibility |
|---|---|
| `Dashboard` | Registers the top-level menu and prints an empty React root. |
| `Settings` | The settings screen, via the Settings API only — `options.php` handles the nonce, and `option_page_capability_sit_cwm_settings_group` makes it require `sit_cwm_manage_workflows` instead of `manage_options`. |

---

## The dependency graph

Every arrow is a constructor argument. There are no static calls between
services and no globals.

```
StatusManager  (no dependencies)
     ▲
     ├──────────────── TransitionManager ────┐
     │                                       │
     ├──────────────── PostRepository ───────┤
     │                        ▲              │
     │                        │              │
     ├──── PermissionManager ─┤              │
     │         ▲   ▲   ▲                     │
     │         │   │   └── Settings          │
     │         │   └────── Capabilities      │
     │         │                             │
     └─────────┴──────── WorkflowManager ◄───┘
                            ▲   │
                            │   └──► ActivityLogger ──► Database
                            │
          ┌─────────────────┼──────────────────┐
          │                 │                  │
     BulkProcessor    REST controllers    Assets / Dashboard / Sidebar
```

Two properties fall out of this shape:

- **`TransitionManager` and `PermissionManager` do not know about each other.**
  Neither can be tricked into standing in for the other.
- **Everything above `PostRepository` and `ActivityLogger` is testable without
  a database.** `tests/php/unit/` covers `StatusManager`, `TransitionManager`
  and `Container` with no WordPress at all.

---

## The `transition()` pipeline

`WorkflowManager::transition( $post_id, $from, $to, $user_id )`, in order. Any
step failing returns a `WP_Error` and **writes nothing** — no meta, no activity
row, no action fired.

| # | Check | On failure |
|---|---|---|
| 1 | **Post gate.** The post exists, its type is workflow-enabled, and the user can read it. | `sit_cwm_not_managed` **404** |
| 2 | **Concurrency guard.** `$from` equals the status stored right now. | `sit_cwm_status_conflict` **409** |
| 3 | **Status exists.** `$to` is in the `StatusManager` registry. | `sit_cwm_invalid_status` **400** |
| 4 | **Edge exists.** `TransitionManager::is_valid( $from, $to )`. | `sit_cwm_invalid_transition` **400** |
| 5 | **Authorization.** `PermissionManager::can_change_status()` — user exists, `edit_post` on this post, the capability mapped to `$to`, `publish_post` for `published`, then the `sit_cwm_can_transition` filter's final word. | `sit_cwm_forbidden` **403** |
| 6 | **Persist.** `PostRepository` writes `_sit_cwm_status`. | `sit_cwm_update_failed` **500** |
| 7 | **Log.** Exactly one `status_changed` row. | |
| 8 | **Announce.** `do_action( 'sit_cwm_status_changed', $post_id, $from, $to, $user_id )`. | |

Notes on the ordering:

- **Step 1 before everything** and identical for missing / unmanaged /
  unreadable posts, so a `403` is only ever returned for a post the caller can
  already see. Otherwise the error codes would enumerate private post IDs.
- **Step 2 before step 5.** A stale client learns its view is out of date rather
  than being told it lacks a permission it may well have.
- **Step 5 last** among the checks, because it is the expensive one — it
  consults roles, meta capabilities and a filter.
- `can_transition()` runs steps 1–5 *without* writing, and `transition()`
  re-runs all of them itself. There is deliberately no check-then-act gap
  between the two: the REST permission callback calling `check_transition()`
  first is an optimization for error reporting, never the authorization.

### The 409 concurrency guard

A status change is conditional on the status the client believed was current:

```
client GET  → status = "review"
             …meanwhile another editor moves it to "needs_changes"…
client POST → { from: "review", status: "approved" }   → 409, nothing written
```

This closes the read-modify-write window that any UI opens. It is
**application-level, not a database lock**: two PHP processes writing in the
same millisecond can still interleave. That is the accepted trade-off — see
[Known trade-offs](#known-trade-offs) — because the realistic failure is a human
with a stale browser tab, measured in minutes, not two concurrent writes
measured in microseconds.

`from` is required whenever `status` is sent, so a client cannot opt out of the
guard by omitting it.

---

## Structure vs. authorization

`TransitionManager` answers **"is `review → approved` an edge in the graph?"**
`PermissionManager` answers **"may user 7 put post 125 into `approved`?"**

They are separate classes, with separate filters, that never call each other.
`WorkflowManager` is the only place both answers are consulted. Why keep them
apart:

- **They fail differently, and callers care which.** A missing edge is a `400`
  (the request is nonsense from this state); a denied capability is a `403` (the
  request is sensible, you just may not). Merging them would flatten both into
  "no".
- **They vary independently.** Pro adds workflows with different graphs
  (`sit_cwm_transition_map`) and different role rules
  (`sit_cwm_status_capability_map`, `sit_cwm_can_transition`). Those are
  separate extension points precisely because a customer usually wants one
  without the other.
- **One of them is pure.** `TransitionManager` has no database, no current user
  and no WordPress state, so the state machine is exhaustively unit-testable
  with no fixtures.
- **Neither can substitute for the other.** A filter on the graph cannot grant a
  capability; a filter on permissions cannot invent an edge. Widening one leaves
  the other standing.

The same split shows up in the permission checks themselves: steps 1–2 of
`can_change_status()` are **hard gates** that no filter can override (the user
must exist; the post must exist and be workflow-enabled), while steps 3–5 pass
through `sit_cwm_can_transition`. A filter can therefore tighten or loosen
*policy*, but never make the plugin act on a post that has no workflow or a user
who is not logged in.

---

## Data model

### Post meta (decision D6)

| Key | Type | Default | Sanitizer |
|---|---|---|---|
| `_sit_cwm_status` | string | `draft` | `StatusManager::sanitize()` — `sanitize_key` plus an allow-list against the registry |
| `_sit_cwm_reviewer_id` | integer | `0` | `absint`, existing user, must hold `sit_cwm_review_content`. Negative IDs are rejected, not flipped |
| `_sit_cwm_due_date` | string | `''` | Round-trip `Y-m-d` parse; `''` clears |

All three are registered with `register_post_meta()`, `single => true`,
`show_in_rest => true` with `context: [ 'edit' ]` — so a reviewer ID or due date
never leaks into the public `view` context of a published post — and an
`auth_callback` that delegates to `PermissionManager`.

`_sit_cwm_status` is additionally locked against direct writes: its
`auth_callback` returns false, **and** `map_meta_cap` maps
`add_/edit_/delete_post_meta` on that key to `do_not_allow`. The belt and braces
are deliberate — multisite super admins bypass `auth_callback`. The result is
that the status can only change by going through the state machine.

### Activity table (decision D8)

`{$wpdb->prefix}sit_cwm_activity`:

| Column | Type | Notes |
|---|---|---|
| `id` | `bigint(20) unsigned` | `AUTO_INCREMENT`, primary key |
| `post_id` | `bigint(20) unsigned` | |
| `user_id` | `bigint(20) unsigned` | `0` = system |
| `action` | `varchar(50)` | Closed set: `status_changed`, `reviewer_assigned`, `reviewer_cleared`, `due_date_set`, `due_date_cleared`, `comment_added` |
| `old_value` | `varchar(191)` NULL | Status slug or user ID, as text |
| `new_value` | `varchar(191)` NULL | |
| `message` | `text` NULL | Comment body, `wp_kses_post()` |
| `context` | `longtext` NULL | JSON extras. **Never** serialized PHP — nothing here is ever `unserialize()`d |
| `created_at` | `datetime` | UTC |

Indexes:

| Key | Columns | Serves |
|---|---|---|
| `PRIMARY` | `id` | |
| `post_created` | `(post_id, created_at)` | The timeline query and the dashboard's last-activity batch. `EXPLAIN` must show `type: ref, key: post_created` |
| `user_id` | `(user_id)` | "What has this person done" (Pro) |
| `action` | `(action)` | The `action` filter on the timeline |

Why a table and not post meta: an activity log is append-only, needs ordering
and pagination, and grows without bound. A serialized array in meta would be
read and rewritten in full on every append, would break under concurrent writes,
and could not be indexed or paginated.

Schema version lives in the `sit_cwm_db_version` option (not autoloaded) and is
compared on `plugins_loaded`, so an upgrade runs without reactivation.

### Options

| Option | Autoloaded | Contents |
|---|---|---|
| `sit_cwm_settings` | yes | `{ post_types: [ 'post', 'page' ] }` — small on purpose |
| `sit_cwm_db_version` | no | Schema version |

### Uninstall

`uninstall.php` (guarded by `WP_UNINSTALL_PLUGIN`) drops the activity table,
deletes both options, removes all `_sit_cwm_*` post meta with a prepared `LIKE`
delete, and strips the six capabilities from **every** role — not just the four
in `role_map()`, so grants a site owner added to custom roles are cleaned up
too.

---

## Known trade-offs

These are real limitations, chosen deliberately. Each names its Pro follow-up.

### 1. Multi-field updates are not transactional

`POST /posts/{id}/workflow` can change the reviewer, the due date and the status
in one call. All three are authorized and validated *before* anything is
written, then applied in a fixed order (reviewer → due date → status). But the
writes are separate `update_post_meta()` calls with no transaction around them:
if the status write fails after the reviewer write succeeded — realistically
only a concurrent edit landing between the check and the write — the reviewer
change stays applied and the response reports the status error.

*Why accepted:* WordPress' options and meta APIs are not transactional, and
wrapping `$wpdb` in explicit transactions across core API calls is fragile (and
a no-op on MyISAM). The window is a few hundred microseconds after every check
has already passed.

*Pro follow-up:* a compensating write — replay the pre-change snapshot the
permission check already loaded — plus an `activity` row recording the partial
application.

### 2. No object caching

Every request re-reads the status registry, the transition map and the post's
meta. The registry and map are memoised **per request** (and only once `init`
has fired, so untranslated labels are never cached), but nothing is written to
`wp_cache_*` or a transient.

*Why accepted:* the data is small, `get_post_meta()` is already served by
WordPress' own post-meta cache, and a cache is a correctness liability in a
plugin whose whole job is answering "what is the status *right now*". The
concurrency guard depends on reading fresh state.

*Pro follow-up:* cache the *derived* per-user permission matrix (the expensive
part) under a key that includes the user, the post and the post's
`modified_gmt`, invalidated on `sit_cwm_status_changed`.

### 3. `meta_query` does not scale indefinitely

The dashboard filters on `_sit_cwm_status`, `_sit_cwm_reviewer_id` and
`_sit_cwm_due_date` through `WP_Query`'s `meta_query`. Each clause is a join
against `wp_postmeta`, a table that grows with every plugin on the site. The
query keeps the most selective clause first and combines at most a few clauses,
and the collection is capped at 100 rows per page with `fields => ids`, so a
page costs a fixed number of queries regardless of its size — but the *join*
itself gets slower as `wp_postmeta` grows.

*Why accepted:* it is correct, it uses core APIs, and it is comfortably fast at
the scale Free targets (measured against 500 posts / 5 000 activity rows; see
[DEVELOPMENT.md](DEVELOPMENT.md#budgets)).

*Pro follow-up:* a dedicated `{$wpdb->prefix}sit_cwm_index` table holding
status, reviewer and due date per post, with a composite index, kept in sync
from `sit_cwm_status_changed` and friends. The `PostRepository` query method is
the single seam it would replace.

### 4. The 409 guard is not a lock

Covered [above](#the-409-concurrency-guard): it closes the human-scale
read-modify-write window, not a microsecond-scale write race. A genuine
simultaneous double-write can still produce a lost update.

*Pro follow-up:* `SELECT … FOR UPDATE` on a row in the index table from (3),
which gives a real lock to take — something the meta table cannot offer.

### 5. Bulk actions are bounded, not queued

A batch is capped at 100 posts and runs synchronously inside the request.
Batches over 50 raise the memory limit to the admin limit; the **time** limit is
never lifted, because `MAX_ITEMS` is what bounds the runtime.

*Why accepted:* a queue needs `wp_cron` (unreliable on low-traffic sites) or a
real worker, and partial-progress reporting. 100 posts covers a full dashboard
page.

*Pro follow-up:* an Action Scheduler-backed queue with progress reporting.

### 6. The activity log is append-only and never pruned

Rows accumulate forever and are only removed at uninstall. A busy site
accumulates a few rows per post per cycle.

*Why accepted:* an audit log that deletes itself is not an audit log, and the
table is narrow and indexed.

*Pro follow-up:* retention policy plus CSV/JSON export, as part of advanced
audit logs.

### 7. `@wordpress/dataviews` is bundled, not a core script

It is not registered by every supported WordPress version, so it ships inside
`dashboard.js`. The bundled copy still unlocks core's private APIs
(`wp-private-apis`, `wp-components`), so the DataViews version bounds the
minimum WordPress version: `11.3.0` is pinned exactly and works on 6.8+
([details](DEVELOPMENT.md#bundle-size-and-wordpressdataviews)).

*Pro follow-up:* none needed — newer DataViews become usable as the minimum
supported version rises.

---

# Reference detail

The sections above are the design. The ones below are the exact contracts —
check order, error codes, filter semantics — written as the classes were built,
and the security review that audited them.

## Authorization (`Sit_Cwm\Workflow\PermissionManager`)

Every workflow authorization question is answered by `PermissionManager`.
Nothing else calls `current_user_can()` for workflow decisions; all checks use
`user_can( $user_id, … )` so they work for arbitrary users and in bulk loops.

`PermissionManager` decides *who may reach a status*. It does **not** decide
whether `from → to` is a legal edge — that is `TransitionManager`.
`WorkflowManager` is the only class that combines the two, so neither can be
bypassed by the other.

### `can_change_status( $post_id, $to, $user_id )` — order of checks

1. The user exists (`0` / logged-out / unknown → deny). **Hard gate.**
2. The post exists and its type is workflow-enabled. **Hard gate.**
3. `edit_post` on this specific post (WordPress's own object-level check).
4. The capability mapped to `$to` (`sit_cwm_status_capability_map`; unknown
   statuses require `sit_cwm_manage_workflows`).
5. For `published`: also `publish_post` on this post.
6. The result of 3–5 passes through
   `sit_cwm_can_transition( $allowed, $post_id, $from, $to, $user_id )`.

### The `sit_cwm_can_transition` contract

- The filter runs **last**, so its return value is final for checks 3–5:
  returning `false` vetoes an allowed move (Pro can tighten rules), and
  returning `true` grants a move capabilities would deny (Pro can widen —
  deliberately; a filter that blindly returns `true` opens every status to every
  user who reaches it).
- It never runs for the hard gates 1–2: no filter can grant a logged-out user,
  a missing post, or a post whose type has no workflow. The filter is therefore
  never the *only* gate.
- Only boolean `true` allows; any other value denies.
- Structural validity (`TransitionManager`) is checked separately by
  `WorkflowManager` and cannot be widened through this filter.

### Other permission checks

All require an existing user, a managed post and `edit_post` on it, plus:

| Method | Capability |
|---|---|
| `can_read_post` | WP `read_post` instead of `edit_post` (visibility gate) |
| `can_edit_post` | — |
| `can_view_activity` | `sit_cwm_view_activity` |
| `can_comment` | `sit_cwm_view_activity` |
| `can_assign_reviewer` | `sit_cwm_assign_reviewer` |
| `can_set_due_date` | `sit_cwm_assign_reviewer` |
| `can_manage` (no post) | `sit_cwm_manage_workflows` |

Role grants live only in `Capabilities::role_map()` and are applied at
activation (`Capabilities::add_caps()`, idempotent) and removed on uninstall
(`Capabilities::remove_caps()`, every role).

## Orchestration (`Sit_Cwm\Workflow\WorkflowManager`)

The single entry point for every workflow mutation and for the REST-shaped
read model (`get_workflow()`). REST controllers, the sidebar, bulk actions and
Pro code call it; they never write meta or activity rows themselves.

### Post gate (every method)

A post that is missing, not workflow-enabled, **or not readable by the acting
user** (`PermissionManager::can_read_post`) yields the identical
`sit_cwm_not_managed` 404. A 403 is therefore only ever returned for a post the
user can already see. Read methods return `[]` / `false` in the same cases.

### `transition( $post_id, $from, $to, $user_id )` — order of checks

| # | Check | Error |
|---|---|---|
| 1 | Post gate | `sit_cwm_not_managed` 404 |
| 2 | `$from` equals the stored status (optimistic concurrency) | `sit_cwm_status_conflict` 409 |
| 3 | `$to` is a registered status | `sit_cwm_invalid_status` 400 |
| 4 | `$from → $to` is in the transition map | `sit_cwm_invalid_transition` 400 |
| 5 | `PermissionManager::can_change_status` | `sit_cwm_forbidden` 403 |

Then: persist → log one `status_changed` row → `sit_cwm_status_changed`.
`can_transition()` runs the same private validator without writing, and
`transition()` always re-validates, so there is no check-then-act gap between
the two. The 409 guard is application-level, not a database lock.

### Reviewer, due date, comment

Post gate (404) → capability (403) → input validation (400:
`sit_cwm_invalid_user`, `sit_cwm_invalid_date`, `sit_cwm_empty_comment`) →
persist → one activity row → D10 action. Authorization runs *before* input
validation so a user without the capability cannot use 400-vs-403 to probe
which user ids exist. Re-assigning the same reviewer or date is a no-op that
returns `true` and logs nothing. A persistence failure after all checks is
`sit_cwm_update_failed` 500.

Failing calls write no meta, no activity rows and fire no actions.

### `available_transitions` and `capabilities`

Courtesy data for the UI to hide controls; never trusted on the way back in.
`sit_cwm_available_transitions` may remove, reorder or relabel entries but
cannot add a slug the user was not already allowed.

### Reviewer eligibility

`assign_reviewer()` accepts only an existing user who holds
`sit_cwm_review_content` (`PermissionManager::can_be_reviewer()`); anyone else
is `sit_cwm_invalid_user` 400. The check runs after authorization, like the
existence check, and applies to single and bulk requests alike.

## Bulk actions (`Sit_Cwm\Workflow\BulkProcessor`)

`POST /sit-cwm/v1/posts/batch` takes `{ post_ids, action, payload }` with
`action` one of `change_status` (`{ status }`), `assign_reviewer`
(`{ reviewer_id }`) or `set_due_date` (`{ due_date }`).

- **The permission check only gates the attempt.**
  `PermissionManager::can_attempt_batch()` requires a logged-in user with
  `sit_cwm_manage_workflows` or `edit_posts`. It grants nothing on any post.
- **Every post re-runs the single-item path.** `BulkProcessor` loops over the
  unique ids and calls the same `WorkflowManager` method a single request
  uses. There is no bulk SQL and no permission check hoisted out of the loop,
  so a batch can never do more than the same requests sent one by one.
- `change_status` starts from each post's **own** current status; the client
  cannot supply one `from` for many posts. Posts whose status makes the move
  illegal fail with `sit_cwm_invalid_transition` instead of being forced.
- Missing, trashed, not workflow-enabled and unreadable posts all fail with the
  identical `sit_cwm_invalid_post` entry.
- The answer is HTTP 200 with `succeeded` (ids), `failed`
  (`{ post_id, code, message, status }`) and `items` (fresh workflow state of
  each success). Only a malformed request as a whole is a 400: 0 or more than
  100 ids, an unknown action, a payload that lacks the action's key or carries
  unknown keys, an invalid date.
- Each success logs its own activity row and fires its own D10 action. No
  aggregate event exists.
- Runtime is bounded by the 100-id cap. Batches over 50 ids raise the memory
  limit to the admin limit; the time limit is never lifted.

## Security review (step 20)

### Information-disclosure rules

1. A post that is missing, not workflow-enabled, or not readable by the user
   gets the **identical 404** (code, message, status): `sit_cwm_not_managed` on
   per-post routes, `sit_cwm_invalid_post` per batch item. A 403 is only
   returned when the user can read the post but may not perform the action.
   *(Fixed in step 20: `AbstractController::resolve_post()` used to answer
   missing ids with `sit_cwm_invalid_post` and hidden posts with
   `sit_cwm_not_managed`, which let anyone enumerate hidden post ids.)*
2. `/users` is gated by `can_list_reviewers()`, returns id, display name and
   avatar only, and searches display name and nicename, never login or email.
3. Activity and comments require `sit_cwm_view_activity` and `edit_post` on
   that post, behind the readable-post gate.
4. Error messages are generic and never include post titles or content.

### Checklist

| Area | Rule | Result |
|---|---|---|
| Authorization | No `permission_callback => '__return_true'` | ✅ grep empty; `NegativeSuiteTest` asserts every route |
| | Checks run login → capability → post access → transition validity | ✅ in every permission callback. WordPress validates the argument schema before calling `permission_callback`, so malformed input can answer 400 before 401/403 (core behaviour) |
| | No `current_user_can()` outside `PermissionManager` | ✅ exception E1 |
| | No role names outside `Capabilities::role_map()` | ✅ grep empty (settings help text names roles in translated prose only) |
| | Per-post decisions use `edit_post`/`publish_post`/`read_post` with the id | ✅ exception E3 for non-per-post gates |
| | Bulk paths re-check per post | ✅ `BulkProcessor` |
| Input | Every REST arg has `type` + `sanitize_callback` (+ `enum`/`validate_callback` for closed domains) | ✅ after adding `sanitize_key` to `orderby`/`order` on `GET /posts` (step 20 fix) |
| | `wp_unslash()` before sanitizing superglobals | ✅ n/a: plugin code never reads `$_GET`/`$_POST`/`$_REQUEST` (grep empty); the settings form goes through `options.php` |
| | `absint()` for ids, no `intval()` | ✅ grep empty; negative reviewer ids are rejected, not flipped |
| | Dates validated by round-trip parse | ✅ `PostRepository::sanitize_due_date()` |
| | Comments `wp_kses_post` + length cap | ✅ 5000 characters |
| | Unknown request fields ignored, never persisted | ✅ workflow route ignores them; batch payload rejects unknown keys; settings sanitize drops them |
| Output | Every echo in `admin/` escaped at output | ✅ `Dashboard`, `Settings` |
| | JSON to JS via `wp_json_encode()` | ✅ `Assets::add_bootstrap()` |
| | No `dangerouslySetInnerHTML` in `src/` | ✅ grep empty |
| | Errors never leak titles/content | ✅ |
| Database | `$wpdb->prepare()` everywhere; `IN ()` lists from `array_fill()` placeholders | ✅ |
| | `ORDER BY`/`LIMIT` from whitelists and `%d` | ✅ |
| | Table name only from `Database::table_name()` | ✅ |
| | Every DB `phpcs:ignore` has a why-comment | ✅ all reviewed |
| General | No `eval()`, variable `include`, `unserialize()` of stored data | ✅ exception E4 |
| | ABSPATH guard on every PHP file | ✅ 31 of 31 in `includes/`, `admin/`, main file, `uninstall.php` (generated `assets/build/*.asset.php` excluded as build output) |
| | `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN` | ✅ |
| | Nonces on non-REST forms/AJAX | ✅ only the settings form (Settings API nonce, `option_page_capability_sit_cwm_settings_group` → `sit_cwm_manage_workflows`); no `admin_post_`/AJAX handlers |
| | No secrets in the bootstrap JS object | ✅ `restNamespace`, `statuses`, capability flags, `postTypes`, `adminUrl` |

### Exceptions (allowed deliberately)

- **E1** `current_user_can( 'activate_plugins' )` in
  `sapphireit-editorial-workflow.php`: decides only whether to show the
  "PHP too old" notice, runs before the autoloader, not a workflow decision.
- **E2** `$wpdb->query()` in `Database::drop()` (`DROP TABLE` on the name from
  `table_name()`) and `uninstall.php` (prepared `LIKE` delete of `_sit_cwm_*`
  meta). Neither takes user input.
- **E3** The bare `edit_posts` primitive in
  `PermissionManager::can_view_statuses()` and `can_attempt_batch()`: neither
  is a per-post decision, and every post in a batch is authorized again.
- **E4** Computed `require` paths: the fallback autoloader maps a class name to
  a file under the plugin directory (checked with `is_readable()`), and
  `Assets` requires `*.asset.php` only for the hard-coded `ENTRIES`.

### Verification (re-run 2026-09-27; first pass 2026-09-15)

- `composer lint` (WordPress, WordPress-Extra, WordPress-Docs,
  PHPCompatibilityWP): **0 errors, 0 warnings**.
- Grep suite over `includes/`, `admin/`, `src/`, the main file and
  `uninstall.php`:
  - `__return_true`, `dangerouslySetInnerHTML`, `intval(`,
    `$_(GET|POST|REQUEST)`, `unserialize(`, `eval(`: no matches.
  - `current_user_can(`: E1 only, plus two docblock mentions.
  - `$wpdb->query(`: E2 only.
  - Role names (`'editor'`, `'administrator'`, …) outside
    `Capabilities::role_map()`: none; the only `'author'` matches are the
    post-author field.
  - ABSPATH guard: present in all 29 files under `includes/` and `admin/` and
    in the main file; `uninstall.php` checks `WP_UNINSTALL_PLUGIN`.
- Test suites, all green: PHPUnit unit (104) and integration (199, including
  the 19.4 negative suite in `tests/php/integration/NegativeSuiteTest.php` and
  the route/schema contract in `REST/SchemaContractTest.php`), Jest (164) and
  Playwright. Coverage is recorded in DEVELOPMENT.md → Coverage.
- **Subscriber probe:** `tests/e2e/subscriber-probe.spec.js`, run over HTTP
  against a WordPress 7.1.2 site with a real login cookie and `wp_rest` nonce.
  Every body was also checked for the probed post titles and the author's
  login and email; none appeared. The draft is unreadable for a subscriber,
  the published post is readable. Measured:

  | Route | Method | Logged out | Subscriber, draft post | Subscriber, published post |
  |---|---|---|---|---|
  | `/posts/{id}/workflow` | GET | 401 `rest_forbidden` | 404 `sit_cwm_not_managed` | 403 `sit_cwm_forbidden` |
  | `/posts/{id}/workflow` | POST | 401 `rest_forbidden` | 404 `sit_cwm_not_managed` | 403 `sit_cwm_forbidden` |
  | `/posts/{id}/activity` | GET | 401 `rest_forbidden` | 404 `sit_cwm_not_managed` | 403 `sit_cwm_forbidden` |
  | `/posts/{id}/comments` | POST | 401 `rest_forbidden` | 404 `sit_cwm_not_managed` | 403 `sit_cwm_forbidden` |
  | `/posts` | GET | 401 `rest_forbidden` | 403 `sit_cwm_forbidden` | — |
  | `/posts/batch` | POST | 401 `rest_forbidden` | 403 `sit_cwm_forbidden` | — |
  | `/statuses` | GET | 401 `rest_forbidden` | 403 `sit_cwm_forbidden` | — |
  | `/users` | GET | 401 `rest_forbidden` | 403 `sit_cwm_forbidden` | — |

  A logged-in cookie sent without the nonce is treated as logged out
  (401 `rest_forbidden`), so a cross-site request cannot act as the user.
