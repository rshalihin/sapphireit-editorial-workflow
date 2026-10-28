# 12 — REST: Activity, Comments, Users, and the dashboard collection

**Goal:** the remaining endpoints the two React apps need.
**Prerequisites:** 10, 11.
**Dev plan reference:** Phase 5, Phase 8, Phase 9.

## Files to create

```
includes/REST/ActivityController.php
includes/REST/UserController.php
includes/REST/PostsController.php
tests/php/integration/REST/ActivityControllerTest.php
tests/php/integration/REST/UserControllerTest.php
tests/php/integration/REST/PostsControllerTest.php
```

## 12.1 ActivityController

| Method | Route | Notes |
|---|---|---|
| GET | `/posts/(?P<post_id>[\d]+)/activity` | paginated timeline |
| POST | `/posts/(?P<post_id>[\d]+)/comments` | add a workflow comment |

- GET args: `page` (min 1), `per_page` (1–100, default 20), `action` (enum of
  D8 action slugs). Send `X-WP-Total` and `X-WP-TotalPages` headers so the UI
  can paginate like core.
- Response item shape:
  ```json
  { "id": 12, "action": "status_changed", "action_label": "Status changed",
    "old_value": "writing", "old_label": "Writing",
    "new_value": "review",  "new_label": "Review",
    "message": "", "created_at": "2026-09-12T09:30:00+00:00",
    "created_at_human": "2 hours ago",
    "user": { "id": 4, "name": "John", "avatar": "https://…" } }
  ```
  `created_at` is ISO-8601 UTC (`mysql_to_rfc3339`); the human string is built
  server-side with `human_time_diff` so the client needs no date library.
- Resolve users **in batch** — collect `user_id`s from the page of rows, then one
  `get_users( [ 'include' => $ids ] )`, then map. Never `get_userdata()` in a
  loop (step 21 will otherwise flag it).
- `permission_callback` → `PermissionManager::can_view_activity()`.
- POST `/comments`: arg `message`, required, `sanitize_callback` =>
  `wp_kses_post` after `wp_unslash`, `validate_callback` rejecting empty /
  whitespace-only and anything over 5000 chars. Delegates to
  `WorkflowManager::add_comment()`. Returns 201 with the created entry.

## 12.2 UserController

| Method | Route | Notes |
|---|---|---|
| GET | `/users` | assignable reviewers |

- Args: `search` (string), `per_page` (default 20, max 100), `post_id`
  (optional — scopes to who can actually review *that* post).
- Implementation: `get_users()` filtered to users who have
  `sit_cwm_review_content`, ordered by `display_name`, with
  `'fields' => [ 'ID', 'display_name', 'user_email' ]` to avoid hydrating full
  objects. Never return `user_email` in the response — it's fetched only if a
  Gravatar URL needs it; prefer `get_avatar_url( $id )`.
- `permission_callback` → `PermissionManager::can_assign_reviewer( $post_id )`,
  falling back to `can_manage()` when no `post_id` is given. A user who cannot
  assign reviewers must not be able to enumerate the user base — this endpoint
  is a classic user-enumeration hole, so it is capability-gated, not public.
- Apply `sit_cwm_assignable_reviewers` filter to the query args (Pro hook).

## 12.3 PostsController (dashboard collection)

| Method | Route | Notes |
|---|---|---|
| GET | `/posts` | workflow-managed posts, filtered/sorted/paginated |

- Args: `search`, `status` (enum, repeatable), `reviewer_id`, `author`,
  `post_type` (must be in enabled types), `due_before`, `due_after`,
  `orderby` (`title|date|due_date|status`, whitelist), `order` (`asc|desc`),
  `page`, `per_page` (max 100).
- Build one `WP_Query` with `meta_query` for status/reviewer/due-date filters,
  `'fields' => 'ids'` + `'no_found_rows' => false`, then hydrate in batch:
  `update_meta_cache()`, one `get_users()` for reviewers, one
  `ActivityLogger::get_for_posts()` for last activity. Target: **≤ 6 queries
  regardless of page size** — asserted in step 21.
- Row shape: `post_id, title, post_type, post_status, author {id,name},
  status, status_label, reviewer|null, due_date, is_overdue, last_activity|null,
  edit_link, available_transitions` (per-row transitions so DataViews can show
  per-row actions the user may actually perform).
- `permission_callback`: logged in + `edit_posts` on at least one enabled type;
  then the query itself is scoped — non-`edit_others_posts` users see only their
  own posts, plus posts where they are the assigned reviewer. Enforce that in
  the query args, not by filtering the result array afterwards.

## Acceptance criteria

- Activity GET honours pagination and returns correct `X-WP-Total`.
- Subscriber gets 403 on activity; the assigned reviewer gets 200 on a post they
  don't own.
- Comment POST with `<script>` is stored/returned sanitized; empty → 400.
- `/users` as a subscriber → 403; as an editor → only users with
  `sit_cwm_review_content`; no email addresses in the payload.
- `/posts?status=review&reviewer_id=27` returns exactly the matching set.
- An author calling `/posts` sees only their own + assigned-reviewer posts,
  verified by creating posts owned by a second user.
- `orderby=; DROP TABLE` → 400 (enum rejection), nothing executed.
