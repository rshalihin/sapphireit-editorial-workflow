# 17 — Filters and bulk actions

**Goal:** multi-select rows and apply a workflow action to all of them, with
per-post authorization and honest partial-success reporting.
**Prerequisites:** 16.
**Dev plan reference:** Phase 10.

## Files to create / modify

```
includes/REST/BatchController.php            (new)
includes/Workflow/BulkProcessor.php          (new)
src/dashboard/bulk/BulkActions.jsx           (new)
src/dashboard/bulk/AssignReviewerModal.jsx   (new)
src/dashboard/bulk/ChangeStatusModal.jsx     (new)
src/dashboard/bulk/SetDueDateModal.jsx       (new)
src/hooks/useBulkAction.js                   (new)
tests/php/integration/REST/BatchControllerTest.php
tests/js/useBulkAction.test.js
```

## 17.1 Server: `POST /sit-cwm/v1/posts/batch`

Request:
```json
{ "post_ids": [12, 34, 56],
  "action": "change_status",          // change_status | assign_reviewer | set_due_date
  "payload": { "status": "approved" } }
```

Rules:
- `post_ids`: array of integers, `minItems 1`, **`maxItems 100`** — a hard cap so
  one request cannot walk the whole site. Deduplicate and `absint` each.
- `permission_callback`: logged in + `can_manage()` **or** `edit_posts`; this is
  only the gate to *attempt* a batch.
- **Per post, re-run the full single-item path.** `BulkProcessor` loops and calls
  the same `WorkflowManager` methods a single request would — no shortcut
  "bulk SQL update", no permission check hoisted out of the loop. This is the
  rule that keeps bulk from becoming a privilege-escalation hole.
- For `change_status`, the server reads each post's **current** status as `from`
  (the client cannot supply one `from` for many posts). Posts whose current
  status makes the transition illegal are reported as failures, not forced.
- Response 207-style payload, always HTTP 200 unless the whole request is
  malformed:
  ```json
  { "succeeded": [12, 34],
    "failed": [ { "post_id": 56, "code": "sit_cwm_forbidden",
                  "message": "You are not allowed to approve this post." } ],
    "items": [ { …full workflow payload for each succeeded post… } ] }
  ```
- Each success logs its own activity row and fires its own
  `sit_cwm_status_changed` action — one per post, never one aggregate event.
- Guard runtime: if `count > 50`, call `wp_raise_memory_limit( 'admin' )` and
  `set_time_limit( 0 )` is **not** used; instead document the 100 cap.

## 17.2 Client

1. Enable DataViews `selection` + `onChangeSelection`; render `<BulkActions />`
   in the actions slot with the three actions, each `supportsBulk: true`.
2. Each action opens a modal collecting its payload (status select limited to
   statuses reachable from *at least one* selected row; reviewer combobox;
   date picker).
3. `useBulkAction()` → `{ run( action, payload, postIds ), isRunning, result }`.
   On completion: refetch the current page, clear the selection, and show a
   summary notice — `_n()`-pluralized, e.g. "3 posts updated. 1 could not be
   updated." with an expandable list of the failures and their reasons.
   **Never report a partial batch as a success.**
4. Confirmation: destructive/rollback bulk actions and `published` require a
   confirm dialog naming the count.
5. Disable the bulk bar while `isRunning`; no double-submit.

## 17.3 Filters (finishing step 16)

- Persist the active filter set in the URL (step 16) and add a
  "Clear all filters" control.
- Add the `due_before`/`due_after` date-range filter and an "Overdue only"
  quick filter (computed server-side against the site timezone, not the
  browser's).
- Add a saved-view affordance only if trivial via DataViews defaults —
  otherwise skip; custom saved views are Pro.

## Acceptance criteria

- Selecting 3 posts and approving: allowed ones change, disallowed ones are
  reported, and **no** disallowed post's meta changed (assert in PHP test).
- A batch of 101 ids → 400.
- A batch containing a post the user cannot edit → that id in `failed` with
  `sit_cwm_forbidden`, others succeed.
- A batch containing a trashed/deleted post id → `failed` with
  `sit_cwm_invalid_post`, no fatal.
- Exactly N activity rows for N successes.
- Duplicate ids in the request are processed once.
- Bulk assign reviewer to a user lacking `sit_cwm_review_content` → 400 for
  every item, nothing written.
