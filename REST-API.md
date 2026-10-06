# REST API

Namespace: **`sit-cwm/v1`** — every route lives under
`https://example.com/wp-json/sit-cwm/v1/`.

Everything the Gutenberg sidebar and the admin dashboard do goes through these
eight endpoints. There is no private AJAX action and no hidden path: the React
app is a client of this API, and so can you be.

**Nothing here is public.** Every route has a real `permission_callback`; there
is no `__return_true` anywhere in the plugin. Authorization is re-derived
server-side on every request, so the `capabilities` and `available_transitions`
a response hands you are display hints, never a grant.

---

## Authenticating

### From the browser (what the plugin's own UI does)

`@wordpress/api-fetch` sends the `wp_rest` nonce with the logged-in session
cookie. Nothing else is needed.

### From outside WordPress (`curl`, scripts, CI)

Use an [application password](https://wordpress.org/documentation/article/application-passwords/):
**Users → Profile → Application Passwords**. Then send it as HTTP Basic auth.

```sh
export CWM_SITE="https://example.com"
export CWM_AUTH="editor:abcd EFGH ijkl MNOP qrst UVWX"   # user:application-password

curl -sS -u "$CWM_AUTH" "$CWM_SITE/wp-json/sit-cwm/v1/statuses" | jq
```

Every example below assumes those two variables. Application passwords require
HTTPS unless the site defines `WP_ENVIRONMENT_TYPE` as `local`.

---

## Endpoints at a glance

| Method | Route | Purpose |
|---|---|---|
| `GET` | [`/posts/{post_id}/workflow`](#get-postspost_idworkflow) | One post's workflow state |
| `POST` | [`/posts/{post_id}/workflow`](#post-postspost_idworkflow) | Change status, reviewer and/or due date |
| `GET` | [`/posts/{post_id}/activity`](#get-postspost_idactivity) | Paginated activity history |
| `POST` | [`/posts/{post_id}/comments`](#post-postspost_idcomments) | Add a workflow comment |
| `GET` | [`/statuses`](#get-statuses) | Status registry + transition map |
| `GET` | [`/posts`](#get-posts) | Dashboard collection |
| `POST` | [`/posts/batch`](#post-postsbatch) | Bulk status / reviewer / due date |
| `GET` | [`/users`](#get-users) | Assignable reviewers |

---

## `GET /posts/{post_id}/workflow`

Current workflow state of one post, plus what *this* user may do with it next.

**Permissions** — logged in → the post exists, has a workflow, and is readable
(otherwise `404`) → the user can edit the post **or** view its activity.

### Arguments

| Name | In | Type | Constraints |
|---|---|---|---|
| `post_id` | path | integer | required, ≥ 1 |

### Example

```sh
curl -sS -u "$CWM_AUTH" "$CWM_SITE/wp-json/sit-cwm/v1/posts/125/workflow" | jq
```

```json
{
  "post_id": 125,
  "post_title": "Q3 launch announcement",
  "post_type": "post",
  "post_status": "draft",
  "edit_link": "https://example.com/wp-admin/post.php?post=125&action=edit",
  "status": "review",
  "status_label": "Review",
  "status_is_unknown": false,
  "reviewer": {
    "id": 4,
    "name": "Dana Okafor",
    "avatar": "https://secure.gravatar.com/avatar/…?s=48"
  },
  "due_date": "2026-10-02",
  "available_transitions": [
    { "slug": "approved",      "label": "Approved",      "is_forward": true,  "is_rollback": false },
    { "slug": "needs_changes", "label": "Needs Changes", "is_forward": false, "is_rollback": true  }
  ],
  "capabilities": {
    "can_change_status": true,
    "can_assign_reviewer": true,
    "can_set_due_date": true,
    "can_comment": true,
    "can_view_activity": true
  }
}
```

`post_status` is the **WordPress** post status and is deliberately unrelated to
`status`, the workflow status. `status_is_unknown` is `true` when the stored
slug is no longer registered (a filter was removed); the default status is
reported instead. `reviewer` is `null` when none is assigned, `due_date` is `""`
when unset.

---

## `POST /posts/{post_id}/workflow`

Changes the status, the reviewer, the due date, or any combination. At least one
must be present.

**Permissions** — logged in → post readable (`404`) → `edit_post` on it (`403`)
→ per field: `sit_cwm_assign_reviewer` for `reviewer_id` and `due_date` (`403`)
→ for `status`, the full transition check (`400`/`403`/`409`). Everything is
authorized *before* anything is written.

### Arguments

| Name | In | Type | Constraints |
|---|---|---|---|
| `post_id` | path | integer | required, ≥ 1 |
| `status` | body | string | one of the registered status slugs |
| `from` | body | string | **required whenever `status` is sent**; the status the client believed was current |
| `reviewer_id` | body | integer | ≥ 0; `0` clears the reviewer. Must hold `sit_cwm_review_content` |
| `due_date` | body | string | `YYYY-MM-DD`, or `""` to clear |

Unknown body fields are ignored.

### Order of operations

Changes are applied **reviewer → due date → status**. The write is not
transactional: if a later step fails (a concurrent edit between the check and
the write), the earlier steps stay applied. In practice the permission check has
already validated every part, so this only triggers on a genuine race.

### Example — approve a post

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"from":"review","status":"approved"}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/125/workflow" | jq
```

The response is the full workflow object above, freshly re-read — same shape as
`GET`.

### Example — assign a reviewer and a due date in one call

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"reviewer_id":4,"due_date":"2026-10-02"}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/125/workflow" | jq
```

### Example — the concurrency guard firing

Someone else already sent the post back while you were reading it:

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"from":"review","status":"approved"}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/125/workflow" -w '\n%{http_code}\n'
```

```json
{
  "code": "sit_cwm_status_conflict",
  "message": "The workflow status was changed by someone else. Reload and try again.",
  "data": { "status": 409 }
}
```
```
409
```

Nothing was written. Re-`GET` the workflow and decide again.

---

## `GET /posts/{post_id}/activity`

One page of the post's history, newest first.

**Permissions** — logged in → post readable (`404`) → `sit_cwm_view_activity`
plus `edit_post` on the post (`403`).

### Arguments

| Name | In | Type | Constraints |
|---|---|---|---|
| `post_id` | path | integer | required, ≥ 1 |
| `page` | query | integer | ≥ 1, default `1` |
| `per_page` | query | integer | 1–100, default `20` |
| `action` | query | string | one of `status_changed`, `reviewer_assigned`, `reviewer_cleared`, `due_date_set`, `due_date_cleared`, `comment_added` |

### Example

```sh
curl -sS -u "$CWM_AUTH" -D- \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/125/activity?per_page=3" | jq
```

Response headers carry the totals, WordPress-style:

```
X-WP-Total: 17
X-WP-TotalPages: 6
```

```json
[
  {
    "id": 412,
    "action": "status_changed",
    "action_label": "Status changed",
    "old_value": "writing",
    "old_label": "Writing",
    "new_value": "review",
    "new_label": "Review",
    "message": "",
    "created_at": "2026-09-16T14:22:08+00:00",
    "created_at_human": "18 hours ago",
    "user_id": 7,
    "user": { "id": 7, "name": "Sam Idrissi", "avatar": "https://…" }
  },
  {
    "id": 411,
    "action": "comment_added",
    "action_label": "Comment added",
    "old_value": null,
    "old_label": null,
    "new_value": null,
    "new_label": null,
    "message": "Second half needs a source.",
    "created_at": "2026-09-16T14:20:51+00:00",
    "created_at_human": "18 hours ago",
    "user_id": 4,
    "user": { "id": 4, "name": "Dana Okafor", "avatar": "https://…" }
  }
]
```

`created_at` is always ISO 8601 in **UTC**. `user_id` is `0` for system-generated
rows; a non-zero `user_id` with `"user": null` means the account was deleted.
`old_label`/`new_label` resolve slugs and user IDs to display names so a client
does not have to.

---

## `POST /posts/{post_id}/comments`

Adds a workflow comment. These live in the activity table, **not** in
`wp_comments`, and never appear on the front end.

**Permissions** — logged in → post readable (`404`) → `sit_cwm_view_activity`
plus `edit_post` (`403`).

### Arguments

| Name | In | Type | Constraints |
|---|---|---|---|
| `post_id` | path | integer | required, ≥ 1 |
| `message` | body | string | required, ≤ 5000 characters, must contain visible text after `wp_kses_post()` |

### Example

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"message":"Second half needs a source."}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/125/comments" -w '\n%{http_code}\n'
```

Returns **201** with the stored activity entry, shaped exactly like an item from
`GET /activity`.

---

## `GET /statuses`

The status registry and the transition graph. Display metadata for building a
UI — it says nothing about what the current user may do on any particular post;
that comes from a post's `available_transitions`.

**Permissions** — logged in → `sit_cwm_manage_workflows` or WordPress
`edit_posts`.

### Example

```sh
curl -sS -u "$CWM_AUTH" "$CWM_SITE/wp-json/sit-cwm/v1/statuses" | jq
```

```json
{
  "default_status": "draft",
  "statuses": [
    {
      "slug": "draft",
      "label": "Draft",
      "description": "Not yet started in the workflow.",
      "color": "#757575",
      "order": 10,
      "is_final": false
    },
    { "slug": "writing", "label": "Writing", "…": "…" },
    { "slug": "review", "label": "Review", "…": "…" },
    { "slug": "needs_changes", "label": "Needs Changes", "…": "…" },
    { "slug": "approved", "label": "Approved", "…": "…" },
    { "slug": "published", "label": "Published", "color": "#2271b1", "order": 60, "is_final": true }
  ],
  "transitions": {
    "draft":         [ { "slug": "writing", "label": "Writing", "is_forward": true, "is_rollback": false } ],
    "writing":       [ { "slug": "review", "label": "Review", "is_forward": true, "is_rollback": false } ],
    "review":        [ { "slug": "approved", "…": "…" }, { "slug": "needs_changes", "is_rollback": true, "…": "…" } ],
    "needs_changes": [ { "slug": "writing", "…": "…" } ],
    "approved":      [ { "slug": "published", "…": "…" }, { "slug": "needs_changes", "is_rollback": true, "…": "…" } ],
    "published":     [ { "slug": "writing", "is_rollback": true, "…": "…" } ]
  }
}
```

---

## `GET /posts`

The dashboard collection: workflow-managed posts, filtered, sorted and paginated
**server-side**. Nothing here loads every row.

**Permissions** — logged in → able to edit content of at least one
workflow-enabled post type (`403` otherwise, and `403` for a `post_type` outside
that set). Users who cannot edit others' posts only see posts they authored or
are assigned to review; that scope is part of the SQL query, not a post-filter.

### Arguments

| Name | Type | Constraints | Default |
|---|---|---|---|
| `page` | integer | ≥ 1 | `1` |
| `per_page` | integer | 1–100 | `20` |
| `search` | string | ≤ 200 characters | — |
| `status` | array of string | each a registered status slug | — |
| `reviewer_id` | integer | ≥ 0; `0` matches posts with no reviewer | — |
| `author` | integer | ≥ 1 | — |
| `post_type` | string | one workflow-enabled post type | — |
| `due_before` | string | `YYYY-MM-DD` | — |
| `due_after` | string | `YYYY-MM-DD` | — |
| `overdue` | boolean | due date passed, in the site timezone, while the status is non-final | — |
| `orderby` | string | `title`, `date`, `due_date`, `status` | `date` |
| `order` | string | `asc`, `desc` | `desc` |

Repeat `status[]` for several values, or send a comma-separated list.

### Example

```sh
curl -sS -u "$CWM_AUTH" -D- -G \
  --data-urlencode 'status[]=review' \
  --data-urlencode 'status[]=needs_changes' \
  --data-urlencode 'overdue=true' \
  --data-urlencode 'orderby=due_date' \
  --data-urlencode 'order=asc' \
  --data-urlencode 'per_page=2' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts" | jq
```

```json
[
  {
    "post_id": 125,
    "title": "Q3 launch announcement",
    "post_type": "post",
    "post_status": "draft",
    "author": { "id": 7, "name": "Sam Idrissi" },
    "status": "review",
    "status_label": "Review",
    "status_is_unknown": false,
    "reviewer": { "id": 4, "name": "Dana Okafor", "avatar": "https://…" },
    "due_date": "2026-09-12",
    "is_overdue": true,
    "last_activity": {
      "id": 412,
      "action": "status_changed",
      "action_label": "Status changed",
      "new_value": "review",
      "new_label": "Review",
      "created_at": "2026-09-16T14:22:08+00:00",
      "created_at_human": "18 hours ago",
      "user_id": 7,
      "user": { "id": 7, "name": "Sam Idrissi", "avatar": "https://…" }
    },
    "edit_link": "https://example.com/wp-admin/post.php?post=125&action=edit",
    "available_transitions": [ { "slug": "approved", "…": "…" } ],
    "capabilities": { "can_change_status": true, "…": "…" }
  }
]
```

With `X-WP-Total` and `X-WP-TotalPages` headers. `title` is the **raw** post
title — escape it at output. `edit_link` is `""` when the user cannot edit the
post.

One page costs a fixed number of queries regardless of its size: the reviewer,
author and last-activity lookups are batch-loaded, not per row.

---

## `POST /posts/batch`

Applies one action to up to 100 posts.

**Permissions** — logged in → `sit_cwm_manage_workflows` or `edit_posts`. **That
check only gates the attempt.** Every post in the batch re-runs the identical
single-item code path, including its own authorization, so a batch can never do
anything the same requests sent one at a time could not.

### Arguments

| Name | Type | Constraints |
|---|---|---|
| `post_ids` | array of integer | required, 1–100 items, each ≥ 1. Duplicates are processed once |
| `action` | string | required: `change_status`, `assign_reviewer` or `set_due_date` |
| `payload` | object | required; exactly the key the action needs, no others |

| `action` | required `payload` key |
|---|---|
| `change_status` | `status` — a registered status slug |
| `assign_reviewer` | `reviewer_id` — integer ≥ 0, `0` clears |
| `set_due_date` | `due_date` — `YYYY-MM-DD`, or `""` to clear |

`change_status` starts from each post's **own** current status. The client
cannot supply one `from` for many posts, so a post whose current status makes
the move illegal fails rather than being forced.

### Example

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"post_ids":[125,126,999],"action":"change_status","payload":{"status":"approved"}}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/batch" -w '\n%{http_code}\n' | jq
```

```json
{
  "succeeded": [ 125 ],
  "failed": [
    {
      "post_id": 126,
      "code": "sit_cwm_invalid_transition",
      "message": "This workflow transition is not allowed.",
      "status": 400
    },
    {
      "post_id": 999,
      "code": "sit_cwm_invalid_post",
      "message": "No workflow content was found with this ID.",
      "status": 404
    }
  ],
  "items": [ { "post_id": 125, "status": "approved", "…": "…" } ]
}
```
```
200
```

A partly-failed batch is still **HTTP 200** — per-item outcomes live in the body.
`items` holds the fresh workflow state of each success, so a client can update
its rows without refetching. Only a request malformed *as a whole* is a `400`:
zero or more than 100 IDs, an unknown action, a payload missing the action's key
or carrying an unknown one.

Missing, trashed, non-workflow and unreadable posts all fail with the identical
`sit_cwm_invalid_post` entry — the batch endpoint is not a post-ID oracle.

Every success writes its own activity row and fires its own action hook. There
is no aggregate event.

---

## `GET /users`

Users who may be assigned as a reviewer: those holding
`sit_cwm_review_content`.

**Permissions** — logged in → with `post_id`, the post must be readable (`404`)
and the user must be able to assign *that post's* reviewer; without it,
`sit_cwm_assign_reviewer` or `sit_cwm_manage_workflows`.

This is a classic user-enumeration hole, so it is deliberately narrow:
responses carry **id, display name and avatar only**, and `search` matches
display name and nicename — never logins or email addresses.

### Arguments

| Name | Type | Constraints |
|---|---|---|
| `search` | string | ≤ 100 characters |
| `per_page` | integer | 1–100, default `20` |
| `post_id` | integer | ≥ 1; scopes the list to users who can review that post |

### Example

```sh
curl -sS -u "$CWM_AUTH" -G \
  --data-urlencode 'post_id=125' \
  --data-urlencode 'search=dana' \
  "$CWM_SITE/wp-json/sit-cwm/v1/users" | jq
```

```json
[
  { "id": 4, "name": "Dana Okafor", "avatar": "https://secure.gravatar.com/avatar/…?s=48" }
]
```

---

## Errors

Errors are standard WordPress `WP_Error` JSON:

```json
{
  "code": "sit_cwm_forbidden",
  "message": "You are not allowed to perform this workflow action.",
  "data": { "status": 403 }
}
```

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `rest_forbidden` | **401** | Not logged in. |
| `sit_cwm_forbidden` | **403** | Logged in, post visible, but not allowed to do this. |
| `sit_cwm_not_managed` | **404** | The post is missing, has no workflow, **or** is not readable by you. Deliberately indistinguishable — see below. |
| `sit_cwm_invalid_post` | **404** | Per-batch-item equivalent of the above. |
| `sit_cwm_invalid_status` | **400** | `status` is not a registered slug. |
| `sit_cwm_invalid_transition` | **400** | That edge is not in the transition graph from the post's current status. |
| `sit_cwm_invalid_user` | **400** | The reviewer does not exist, or does not hold `sit_cwm_review_content`. |
| `sit_cwm_invalid_date` | **400** | The due date is not a real `YYYY-MM-DD` date. |
| `sit_cwm_invalid_batch` | **400** | Fewer than 1 or more than 100 IDs, unknown action, or a mismatched payload. |
| `sit_cwm_empty_comment` | **400** | The comment has no visible text after sanitizing. |
| `sit_cwm_comment_too_long` | **400** | Over 5000 characters. |
| `sit_cwm_status_conflict` | **409** | `from` does not match the stored status; someone else moved the post. Nothing was written. |
| `sit_cwm_update_failed` | **500** | The database write failed after every check passed. |
| `rest_missing_callback_param` | **400** | A required argument is absent — including `from` when `status` is sent. |
| `rest_invalid_param` | **400** | An argument failed its schema (type, `enum`, range, length). |

### Why 404 and not 403

A post you cannot read, a post that does not exist, and a post whose type has no
workflow all return the **identical** `sit_cwm_not_managed` 404 — same code,
same message, same status. A `403` is only ever returned for a post you can
already see. Otherwise the difference between the two responses would let anyone
enumerate private post IDs.

### Check order

Each permission callback runs: **authenticated → capability → access to this
specific post → transition validity**, in that order, entirely server-side.

One core behaviour to be aware of: WordPress validates the argument schema
*before* calling `permission_callback`, so a malformed request can answer `400`
before it would have answered `401` or `403`. That is core's ordering, not the
plugin's.

### Errors leak nothing

Messages are generic by design. They never include post titles, content,
usernames or email addresses.

---

## Discovering the schema

Every route publishes a JSON Schema. To read it:

```sh
curl -sS -u "$CWM_AUTH" "$CWM_SITE/wp-json/sit-cwm/v1" | jq '.routes | keys'
curl -sS -u "$CWM_AUTH" -X OPTIONS "$CWM_SITE/wp-json/sit-cwm/v1/posts" | jq '.endpoints[0].args'
```
