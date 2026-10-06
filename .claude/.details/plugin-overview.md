# Content Workflow Manager — Plugin Overview

A plain-language explanation of how the plugin works and what its core functionality is.

## What it is

Content Workflow Manager adds an **editorial approval process** on top of normal WordPress posts/pages. It does not create a new post type — it attaches workflow data to the content you already have, via post meta. Enabled post types are configurable (`post` and `page` by default).

## The workflow itself

Every managed post carries a **workflow status**, separate from WordPress's own `draft`/`publish`:

```
draft → writing → review → approved → published
                      ↓         ↓
                 needs_changes ←┘   (needs_changes → writing)
```

Moves are restricted by a state machine (`TransitionManager`). You can't jump `draft → approved`; you must go through the chain. Backwards moves ("rollbacks") are only allowed on defined edges — e.g. `review → needs_changes`, `published → writing`.

Important: the workflow status is a **separate concept** from the real WP post status. Marking a post "published" in the workflow does not itself publish the post; it records that the editorial process finished.

## Who can do what

Six custom capabilities (`sit_cwm_change_workflow`, `sit_cwm_review_content`, `sit_cwm_approve_content`, `sit_cwm_assign_reviewer`, `sit_cwm_view_activity`, `sit_cwm_manage_workflows`) are granted to roles on activation:

- **Contributor / Author** — can move content through the author-side states (`draft`/`writing`/`review`).
- **Editor** — the above, plus can send back for changes, approve, and assign reviewers.
- **Administrator** — everything, including settings and bulk actions.

Each target status requires a specific capability — reaching `needs_changes` needs `review_content`, reaching `approved`/`published` needs `approve_content`. On top of that, the user must also be allowed to edit that specific post.

## Per-post data

Three meta fields: `_sit_cwm_status`, `_sit_cwm_reviewer_id`, `_sit_cwm_due_date`. So each post has a current stage, an assigned reviewer, and a deadline.

## Activity history

Every change is written to a dedicated table (`wp_sit_cwm_activity`) as an append-only audit trail: status changes, reviewer assigned/cleared, due date set/cleared, comments added. Timestamps are stored in UTC. Comments live here too — the workflow discussion is separate from public WP comments.

## How the pieces fit

**All decisions happen in PHP.** `WorkflowManager` is the single gate: it asks `TransitionManager` "is this move structurally legal?" and `PermissionManager` "is this user allowed?", then writes meta and logs activity. The UI never decides anything — it only displays what the server says is permitted.

The REST API (`sit-cwm/v1`) exposes this to two React interfaces:

- **Gutenberg sidebar** — while editing a post: current status, allowed next steps, reviewer, due date, comments, timeline.
- **Admin dashboard** — a DataViews table of all workflow content, with filtering, per-row actions, and bulk operations (change status, assign reviewer, set due date) processed through a batch endpoint that re-authorizes every single post.

Even if someone crafts a request saying `{"status": "approved"}`, the server re-derives from scratch whether that user may make that transition on that post. The client's claim is never trusted.

## Extension points

Actions fire at each lifecycle event — `sit_cwm_status_changed`, `sit_cwm_reviewer_assigned`, `sit_cwm_due_date_changed`, `sit_cwm_comment_added`, `sit_cwm_activity_logged` — and the status registry and transition map are filterable. That's the seam for future Pro features (notifications, Slack, rules engine) to hook in without editing core files.
