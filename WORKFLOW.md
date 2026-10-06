# The workflow

The editorial state machine: the statuses, the legal moves between them, who
may make each one, and what each flow looks like end to end.

Everything here is enforced in PHP. The sidebar and the dashboard only render
what `WorkflowManager` told them is currently possible.

- Statuses live in `Sit_Cwm\Workflow\StatusManager` (decision D3).
- Edges live in `Sit_Cwm\Workflow\TransitionManager::MAP` (D4).
- Capabilities live in `Sit_Cwm\Workflow\PermissionManager` (D5).

---

## The status graph

```
        ┌─────────┐
        │  draft  │  ← every managed post starts here
        └────┬────┘
             │
             ▼
        ┌─────────┐
   ┌───►│ writing │◄──────────────────────┐
   │    └────┬────┘                       │
   │         │                            │
   │         ▼                            │
   │    ┌─────────┐                       │
   │    │ review  │                       │
   │    └──┬───┬──┘                       │
   │       │   │                          │
   │       │   └──────────┐               │
   │       ▼              ▼               │
   │  ┌──────────┐   ┌───────────────┐    │
   │  │ approved │──►│ needs_changes │    │
   │  └────┬─────┘   └───────┬───────┘    │
   │       │                 │            │
   │       │                 └────────────┘
   │       ▼
   │  ┌───────────┐
   └──│ published │   (final — starts a new cycle by going back to writing)
      └───────────┘
```

Read as a table:

| From | May move to |
|---|---|
| `draft` | `writing` |
| `writing` | `review` |
| `review` | `approved`, `needs_changes` |
| `needs_changes` | `writing` |
| `approved` | `published`, `needs_changes` |
| `published` | `writing` |

Rules that hold for every edge:

- **No self-transitions.** `writing → writing` is invalid, not a no-op.
- **Any pair not in the table is invalid**, whatever capabilities the user
  holds. An administrator cannot jump `draft → approved`.
- **The graph is data, not code.** `sit_cwm_transition_map` can replace it
  wholesale; the result is validated against the status registry, so a filter
  cannot introduce an unknown status or a self-transition.

### Rollbacks

Three edges send content *backwards*. They are the same edges as above, flagged
separately (`TransitionManager::ROLLBACKS`, filter
`sit_cwm_rollback_transitions`) so the UI can style them as destructive and ask
for a confirmation:

| From | Rollback to | Meaning |
|---|---|---|
| `review` | `needs_changes` | Reviewer sends it back. |
| `approved` | `needs_changes` | Approval withdrawn before publishing. |
| `published` | `writing` | Start a new editing cycle on live content. |

Each transition the API returns carries `is_forward` and `is_rollback`, so a
client never has to know the graph to style a button.

---

## Who may reach each status

Reaching a status requires **all** of:

1. A logged-in user who exists.
2. A post that exists, whose type is workflow-enabled, and that the user can
   read.
3. WordPress' own `edit_post` on that specific post.
4. The capability mapped to the **target** status:

| Target status | Capability | Plus |
|---|---|---|
| `draft` | `sit_cwm_change_workflow` | |
| `writing` | `sit_cwm_change_workflow` | |
| `review` | `sit_cwm_change_workflow` | |
| `needs_changes` | `sit_cwm_review_content` | |
| `approved` | `sit_cwm_approve_content` | |
| `published` | `sit_cwm_approve_content` | WordPress `publish_post` on the post |

5. A `true` return from the `sit_cwm_can_transition` filter, which runs last and
   has the final say over steps 3–5. It cannot override steps 1–2.

Note that the capability is keyed to the **target**, not to the edge. Moving
`approved → needs_changes` needs `sit_cwm_review_content`, the same as
`review → needs_changes`, because both land on `needs_changes`.

### What that means per role, with the default grants

| | Draft | Writing | Review | Needs Changes | Approved | Published |
|---|---|---|---|---|---|---|
| **Administrator** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Editor** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| **Author** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Contributor** | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ |
| **Subscriber** | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |

✅ here means "holds the capability" — the per-post `edit_post` check still
applies. A contributor holds `sit_cwm_change_workflow`, but WordPress only
grants them `edit_post` on their *own* unpublished posts, so in practice they
move their own drafts and nothing else. An author cannot `publish_post` on
someone else's post either, whatever `sit_cwm_approve_content` says.

### The other capabilities

| Capability | Grants |
|---|---|
| `sit_cwm_assign_reviewer` | Set or clear the reviewer **and** the due date. |
| `sit_cwm_view_activity` | Read the activity timeline and post workflow comments. |
| `sit_cwm_manage_workflows` | The settings page, and attempting bulk actions. |

`sit_cwm_manage_workflows` gates only the *attempt* at a bulk action. Every post
in a batch is authorized individually, exactly as if the requests had been sent
one at a time.

### Who can be a reviewer

Only a user who holds `sit_cwm_review_content`. Assigning anyone else fails with
`sit_cwm_invalid_user` (400), single or bulk. With the default grants that means
editors and administrators.

---

## Worked examples

### 1. The approval flow

A staff writer drafts a post; an editor reviews and approves it.

| # | Actor | Action | Status after | Activity logged |
|---|---|---|---|---|
| 1 | Author | Creates the post. | `draft` | — (implicit default) |
| 2 | Author | Clicks **Start writing**. | `writing` | `status_changed` draft → writing |
| 3 | Editor | Assigns themselves as reviewer, due Friday. | `writing` | `reviewer_assigned`, `due_date_set` |
| 4 | Author | Clicks **Send for review**. | `review` | `status_changed` writing → review |
| 5 | Editor | Reads it, comments "Ship it." | `review` | `comment_added` |
| 6 | Editor | Clicks **Approve**. | `approved` | `status_changed` review → approved |
| 7 | Editor | Clicks **Mark published**. | `published` | `status_changed` approved → published |

**Step 7 does not publish the post.** The WordPress post status is untouched;
someone still hits *Publish* in the editor. The workflow status records that the
editorial process finished, and those two facts are allowed to disagree — see
[ARCHITECTURE.md](ARCHITECTURE.md#why-workflow-status-is-not-wordpress-post-status).

At step 2, the author's sidebar offers exactly one button. The graph allows only
`draft → writing`, so there is nothing else to offer — not because the UI hides
it, but because `available_transitions` came back with one entry.

### 2. The send-back flow

Same post, but the editor wants changes.

| # | Actor | Action | Status after | Activity logged |
|---|---|---|---|---|
| 1–4 | | As above. | `review` | |
| 5 | Editor | Comments "The intro buries the lede." | `review` | `comment_added` |
| 6 | Editor | Clicks **Request changes** (a rollback — the UI confirms first). | `needs_changes` | `status_changed` review → needs_changes |
| 7 | Author | Rewrites, clicks **Resume writing**. | `writing` | `status_changed` needs_changes → writing |
| 8 | Author | Clicks **Send for review**. | `review` | `status_changed` writing → review |

The author *cannot* perform step 6 themselves. While the post sits at `review`,
its only outgoing edges are `approved` and `needs_changes`, which need
`sit_cwm_approve_content` and `sit_cwm_review_content` respectively — neither of
which an author holds. `available_transitions` comes back empty and the sidebar
shows no status buttons at all. The post is parked with the reviewer, which is
the point.

### 3. The re-review flow

Content that was already published needs a correction.

| # | Actor | Action | Status after |
|---|---|---|---|
| 1 | Editor | Post is live, workflow at `published`. | `published` |
| 2 | Editor | Clicks **Start new cycle** (a rollback — confirmed). | `writing` |
| 3 | Author | Edits, **Send for review**. | `review` |
| 4 | Editor | **Approve**. | `approved` |
| 5 | Editor | **Mark published**. | `published` |

Throughout, the post stays publicly published. The workflow cycles underneath
it. This is the scenario the separate-status design exists for: collapsing the
two fields would mean either un-publishing live content to edit it, or losing
the record that a second review happened.

### 4. Approval withdrawn

| # | Actor | Action | Status after |
|---|---|---|---|
| 1 | Editor | Approves. | `approved` |
| 2 | Editor-in-chief | Spots a problem, clicks **Request changes**. | `needs_changes` |
| 3 | Author | **Resume writing**. | `writing` |

`approved → needs_changes` exists precisely so that withdrawing an approval does
not require a trip back through `review`.

---

## Concurrency

Two editors on the same post is normal, so a status change is **conditional**.
The client sends the status it believed was current:

```jsonc
POST /wp-json/sit-cwm/v1/posts/125/workflow
{ "from": "review", "status": "approved" }
```

If the stored status is no longer `review` — someone sent it back while this
editor was reading — the request fails with `sit_cwm_status_conflict` (409) and
nothing is written. The UI refetches and shows the real state rather than
silently clobbering the other person's decision.

The check is application-level, not a database lock: it closes the
read-modify-write window that the UI opens, not a simultaneous-write race
between two PHP processes. See
[ARCHITECTURE.md](ARCHITECTURE.md#known-trade-offs).

---

## What gets recorded

Every successful change writes exactly one row to `wp_sit_cwm_activity`:

| Action slug | Written when |
|---|---|
| `status_changed` | A transition succeeds. `old_value`/`new_value` are the slugs. |
| `reviewer_assigned` | A reviewer is set. Values are user IDs. |
| `reviewer_cleared` | The reviewer is removed. |
| `due_date_set` | A due date is set or changed. |
| `due_date_cleared` | The due date is removed. |
| `comment_added` | A workflow comment is posted. `message` holds the text. |

Rules:

- **A failed change writes nothing** — no meta, no activity row, no action hook.
- **A no-op writes nothing.** Re-assigning the same reviewer or re-setting the
  same date returns success and logs nothing.
- **A bulk action writes one row per affected post**, not one aggregate row.
  There is no such thing as a "bulk" activity entry.
- Rows are never edited or deleted by the plugin. Uninstalling drops the table.

Reading the timeline needs `sit_cwm_view_activity` plus `edit_post` on that
post.
