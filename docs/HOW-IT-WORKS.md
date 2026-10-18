# How This Plugin Works

SapphireIT Editorial Workflow adds an approval workflow to WordPress content.
Each managed post gets its own workflow status, separate from the normal post
status.

## Statuses

A post moves through six statuses:

`Draft → Writing → Review → Approved → Published`

`Review` can also send a post back to `Needs Changes`, which returns to
`Writing`. An approval can be withdrawn, and published content can start a new
cycle.

## How a change happens

The React UI (block editor sidebar, classic editor box, or dashboard) never
decides what is allowed. It only sends requests to the REST API.

1. The UI asks the server which transitions this user may make.
2. The user picks a move and sends it back.
3. The server re-reads the current status, checks the transition graph and the
   user's capabilities, then writes the change.
4. The change is recorded in the activity history.

Only `WorkflowManager` can change workflow state. `TransitionManager` answers
"is this move legal?" and `PermissionManager` answers "may this user do it?".

## What is stored

- `_sit_cwm_status` — the workflow status (post meta).
- `_sit_cwm_reviewer_id` — the assigned reviewer (post meta).
- `_sit_cwm_due_date` — the due date (post meta).
- `wp_sit_cwm_activity` — the activity log (dedicated table).

## Editors

The same workflow panel is available in the Gutenberg block editor sidebar, the
classic editor meta box, and an admin dashboard with filtering and bulk actions.

## Key rules

- Workflow status is independent of WordPress post status and never changes it.
- Every move is validated server-side, including optimistic concurrency.
- Six `sit_cwm_*` capabilities control who can reach each status.
