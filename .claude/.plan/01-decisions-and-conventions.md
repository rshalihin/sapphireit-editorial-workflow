# 01 — Frozen decisions and conventions

Single source of truth for every naming/shape decision the later steps assume.
Change here first, then in code.

## D1 — Prefixes (from CLAUDE.md, non-negotiable)

| Element | Value |
|---|---|
| Functions / hooks | `sit_cwm_` |
| Namespace root | `Sit_Cwm\` |
| Constants | `SIT_CWM_` |
| Slug / text domain / asset handles | `sit-cwm` |
| REST namespace | `sit-cwm/v1` |
| DB tables | `{$wpdb->prefix}sit_cwm_*` |
| Post meta | `_sit_cwm_*` |
| Capabilities | `sit_cwm_*` |
| Options | `sit_cwm_*` |
| Nonce actions | `sit_cwm_*` |

## D2 — Class style: namespaced, PSR-4, `StudlyCase` class names

CLAUDE.md permits either `Sit_Cwm_Workflow_Manager` or
`Sit_Cwm\Workflow\WorkflowManager`. **We pick the namespaced form**, matching the
example in CLAUDE.md and the directory layout in the dev plan.

- File path mirrors namespace: `Sit_Cwm\Workflow\WorkflowManager` →
  `includes/Workflow/WorkflowManager.php`.
- Composer PSR-4: `"Sit_Cwm\\": "includes/"`.
- Methods and variables stay `snake_case` (`can_transition()`, `$post_id`).
- Consequence: exactly one WordPress phpcs sniff is excluded repo-wide in
  `phpcs.xml.dist` — `WordPress.Files.FileName` (documented in step 02, nothing
  else gets excluded). WPCS 3 has no `WordPress.NamingConventions.ValidClassName`
  sniff (referencing it is a fatal phpcs error); its replacement,
  `PEAR.NamingConventions.ValidClassName`, accepts `StudlyCase` class names, so
  it stays enabled (verified in step 02).
- Inline `phpcs:ignore`/`phpcs:disable` is allowed only for names dictated by
  WordPress itself (e.g. `ABSPATH`, `DB_*` in test bootstrap/config), always
  scoped to the specific sniff with a reason.

## D3 — Workflow statuses (`_sit_cwm_status`)

Slugs are lowercase `snake_case`, stored as-is in meta:

| Slug | Label | Notes |
|---|---|---|
| `draft` | Draft | Default for any managed post with no status yet |
| `writing` | Writing | Author is working |
| `review` | Review | Awaiting reviewer |
| `needs_changes` | Needs Changes | Sent back by reviewer |
| `approved` | Approved | Cleared for publication |
| `published` | Published | Terminal for the cycle |

Workflow status is **never** a WP post status and is never written to
`wp_posts.post_status`. Reaching `published` does not itself publish the post in
v1.0 — that stays a manual editor action (scheduled publishing is Pro, Phase 13).

## D4 — Transition map (single source: `TransitionManager::MAP`)

```
draft         → writing
writing       → review
review        → approved, needs_changes
needs_changes → writing
approved      → published, needs_changes
published     → writing            (start a new editing cycle)
```

Rules: no self-transitions; any pair not in the map is invalid regardless of
capability; the map is filterable via `sit_cwm_transition_map` so Pro can replace
it wholesale.

## D5 — Capability required per target status

| Target status | Required capability |
|---|---|
| `writing` | `sit_cwm_change_workflow` |
| `review` | `sit_cwm_change_workflow` |
| `needs_changes` | `sit_cwm_review_content` |
| `approved` | `sit_cwm_approve_content` |
| `published` | `sit_cwm_approve_content` + WP `publish_post` on the post |
| `draft` | `sit_cwm_change_workflow` |

Plus, for every transition: the user must pass `edit_post` on that specific post
(mapped meta cap), and the post type must be workflow-enabled (D7).

Amendment (step 17): an assigned reviewer must hold `sit_cwm_review_content`
(`PermissionManager::can_be_reviewer()`), otherwise `sit_cwm_invalid_user` 400.

Other capabilities: `sit_cwm_assign_reviewer` (set/clear reviewer and due date),
`sit_cwm_view_activity` (read activity + comments),
`sit_cwm_manage_workflows` (settings page, bulk actions across posts).

Default role grants (set at activation, step 07):

| Role | Caps |
|---|---|
| Administrator | all six |
| Editor | change_workflow, assign_reviewer, review_content, approve_content, view_activity |
| Author | change_workflow, view_activity |
| Contributor | change_workflow, view_activity |
| Subscriber | none |

No `current_user_can( 'editor' )`-style checks anywhere — roles only appear in
the activation-time grant table.

## D6 — Post meta

| Key | Type | Sanitize | Default |
|---|---|---|---|
| `_sit_cwm_status` | string | `sanitize_key` + whitelist against StatusManager | `draft` |
| `_sit_cwm_reviewer_id` | integer | `absint` + user-exists check | `0` |
| `_sit_cwm_due_date` | string | `Y-m-d` validation, `''` clears | `''` |

All registered with `register_post_meta()`, `single => true`,
`show_in_rest => true`, `auth_callback` delegating to `PermissionManager`.
REST **writes** go through our controller, not core's meta endpoint: the
`auth_callback` returns false for direct meta writes on `_sit_cwm_status` so the
state machine cannot be bypassed. (Reviewer/due date allow authorized direct
writes.)

Hardening added in step 08 (beyond the table above):
- All three fields use REST schema `context: [ 'edit' ]`, so reviewer id, due
  date and status never appear in the public `view` context of published posts.
- `_sit_cwm_status` is also locked via `map_meta_cap` (`add_/edit_/delete_post_meta`
  → `do_not_allow`), because multisite super admins bypass `auth_callback`.
- Reviewer sanitizing rejects negative ids instead of `absint()`-flipping them
  onto a real user.
- Meta only shows in core REST for post types supporting `custom-fields`; the
  plugin's own `sit-cwm/v1` routes do not depend on that.

## D7 — Enabled post types

Option `sit_cwm_settings` (array), key `post_types`, default `[ 'post', 'page' ]`.
Filter: `sit_cwm_enabled_post_types`. A post whose type is not enabled has no
workflow: REST returns 404 for its workflow routes, the sidebar does not render.

Amendment (step 18): the post types that may be enabled are
`Settings::available_post_types()`: registered with `show_ui`, minus
`attachment`, `revision`, `nav_menu_item` and every `wp_*` type (previously
"registered public post types"). Both `Settings::update()` and the settings
screen validate against it. Disabling a type never deletes its meta or activity.

## D8 — Activity table `{$wpdb->prefix}sit_cwm_activity`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | PK |
| `post_id` | BIGINT UNSIGNED NOT NULL | index |
| `user_id` | BIGINT UNSIGNED NOT NULL | index; `0` = system |
| `action` | VARCHAR(50) NOT NULL | index |
| `old_value` | VARCHAR(191) NULL | |
| `new_value` | VARCHAR(191) NULL | |
| `message` | TEXT NULL | comment body (`wp_kses_post`) |
| `context` | LONGTEXT NULL | JSON extras, never unserialized PHP |
| `created_at` | DATETIME NOT NULL | UTC, index `(post_id, created_at)` |

Action slugs (closed set in v1.0): `status_changed`, `reviewer_assigned`,
`reviewer_cleared`, `due_date_set`, `due_date_cleared`, `comment_added`.

Schema version stored in option `sit_cwm_db_version` (`'1.0.0'`), checked on
`plugins_loaded` so upgrades run without reactivation.

## D9 — REST surface (`sit-cwm/v1`)

| Method | Route | Purpose |
|---|---|---|
| GET | `/posts/(?P<post_id>[\d]+)/workflow` | current workflow state + available transitions |
| POST | `/posts/(?P<post_id>[\d]+)/workflow` | change status / reviewer / due date |
| GET | `/posts/(?P<post_id>[\d]+)/activity` | paginated activity |
| POST | `/posts/(?P<post_id>[\d]+)/comments` | add workflow comment |
| GET | `/statuses` | status + transition metadata for UI |
| GET | `/posts` | dashboard collection (filter/search/sort/paginate) |
| POST | `/posts/batch` | bulk status / reviewer / due date |
| GET | `/users` | assignable reviewers |

Every route defines a real `permission_callback`. Errors use `WP_Error` with
codes `sit_cwm_invalid_post`, `sit_cwm_not_managed`, `sit_cwm_forbidden`,
`sit_cwm_invalid_status`, `sit_cwm_invalid_transition`, `sit_cwm_invalid_user`,
`sit_cwm_invalid_date` and correct HTTP statuses (400/403/404/409).

Amendments (steps 17 and 20):
- `POST /posts/batch` takes `{ post_ids (1–100), action, payload }` with
  `action` ∈ `change_status` / `assign_reviewer` / `set_due_date`, and answers
  HTTP 200 with `{ succeeded, failed, items }`. Per-item post-gate failures are
  `sit_cwm_invalid_post`; a malformed request as a whole is 400.
- Per-post routes answer missing, unmanaged and unreadable posts with the
  identical `sit_cwm_not_managed` 404 (a missing id is no longer
  `sit_cwm_invalid_post`), so hidden post ids cannot be enumerated.
- `GET /posts` also accepts `overdue` (boolean, site timezone, non-final
  statuses only).

## D10 — Action/filter hooks fired by Free core (Pro extension surface)

Actions: `sit_cwm_status_changed( $post_id, $from, $to, $user_id )`,
`sit_cwm_reviewer_assigned( $post_id, $reviewer_id, $previous_id, $user_id )`,
`sit_cwm_due_date_changed( $post_id, $date, $previous, $user_id )`,
`sit_cwm_comment_added( $post_id, $activity_id, $message, $user_id )`,
`sit_cwm_activity_logged( $activity_id, $entry )`.

Filters: `sit_cwm_statuses`, `sit_cwm_transition_map`,
`sit_cwm_status_capability_map`, `sit_cwm_can_transition` (last word on a
decision, receives the computed bool + context), `sit_cwm_enabled_post_types`,
`sit_cwm_available_transitions`, `sit_cwm_activity_actions`.

## D11 — Versions and environment

- `Requires at least: 6.8`, `Requires PHP: 7.4`, `Tested up to: 7.1`.
  Raised from 6.5 during completion: every `@wordpress/dataviews` release with
  `DataForm` needs core private APIs that WP 6.5–6.7 lack (6.5 rejects the
  private-APIs consent string; 6.6/6.7 lack `Menu.TriggerButton`), and 12.0+
  pulls in `@wordpress/ui` → `wp-theme`, which only 7.1 registers.
  `@wordpress/dataviews` is pinned **exactly** at `11.3.0`, verified on WP
  6.8, 6.9, 7.0 and 7.1. Do not bump it without re-checking
  `dashboard.asset.php` against the minimum (`AssetCompatTest`).
- Plugin version `1.0.0` in the header, `SIT_CWM_VERSION`, and `package.json` —
  kept in lockstep, bumped only in step 23.
- Build: `@wordpress/scripts`. Source `src/`, output `assets/build/` (tracked in
  git, not ignored, so the plugin runs from a clone without `npm install`).
- JS lint config is **flat `eslint.config.js`**, not `.eslintrc.js` (step 13
  deviation): `@wordpress/scripts` 35 ships ESLint 10, which ignores eslintrc
  files. It spreads `@wordpress/scripts/config/eslint.config.cjs`.
- PHP enqueues go through `Sit_Cwm\Core\Assets` only (step 13): it reads each
  entry's `*.asset.php`, uses handles `sit-cwm-{entry}-js` / `sit-cwm-{entry}-css`,
  and prints the `window.sitCwm` bootstrap
  (`restNamespace, statuses, capabilities, postTypes, adminUrl`) with
  `wp_add_inline_script`. `capabilities` comes from
  `PermissionManager::capability_flags()` and is a UI hint only.
