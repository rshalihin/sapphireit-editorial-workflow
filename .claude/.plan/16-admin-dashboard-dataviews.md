# 16 — Admin dashboard (DataViews)

**Goal:** a top-level admin screen listing all workflow-managed content in a
`@wordpress/dataviews` table.
**Prerequisites:** 12, 13.
**Dev plan reference:** Phase 9.

## Files to create

```
admin/Dashboard.php                    menu page + render root + enqueue
src/dashboard/index.js                 createRoot into #sit-cwm-dashboard
src/dashboard/App.jsx
src/dashboard/WorkflowDataViews.jsx
src/dashboard/fields.js                DataViews field definitions
src/dashboard/StatusBadge.jsx
src/hooks/usePosts.js
src/dashboard/style.scss
tests/js/usePosts.test.js
tests/js/fields.test.js
```

## Tasks

1. **`Dashboard implements Bootable`**
   - `admin_menu`: `add_menu_page()` — page title
     `__( 'Content Workflow', 'sit-cwm' )`, capability `sit_cwm_view_activity`
     (not `manage_options`; per-row actions are capability-checked server-side),
     slug `sit-cwm-dashboard`, dashicon `dashicons-clipboard`, position 25.
   - `render()`: echo a single `<div id="sit-cwm-dashboard" class="wrap"></div>`
     plus an `<h1 class="screen-reader-text">`; **no** markup built from data.
   - `admin_enqueue_scripts`: enqueue only on this screen (compare
     `$hook_suffix`), per step 13.
2. **`usePosts( query )`** — wraps `GET /sit-cwm/v1/posts`, returns
   `{ records, totalItems, totalPages, isLoading, error, refresh() }`.
   Maps the DataViews `view` object (`page`, `perPage`, `search`, `sort`,
   `filters`) to REST args in one `viewToQuery()` function so the mapping is
   testable in isolation.
3. **`fields.js`** — DataViews field definitions:

   | id | label | render | filter/sort |
   |---|---|---|---|
   | `title` | Title | link to `edit_link` | sortable, searchable |
   | `status` | Status | `<StatusBadge />` | `enumeration` filter, multi-select |
   | `reviewer` | Reviewer | avatar + name, "—" when unassigned | `enumeration` filter |
   | `due_date` | Due | localized date, overdue in red | sortable, date filter |
   | `author` | Author | name | `enumeration` filter |
   | `post_type` | Type | singular label | `enumeration` filter |
   | `last_activity` | Last activity | relative time | — |

   Field option lists (statuses, post types) come from the bootstrap
   `window.sitCwm`; reviewers/authors come from `/users` — never hard-coded.
4. **`WorkflowDataViews.jsx`** — `<DataViews />` with `data`, `fields`, `view`,
   `onChangeView`, `paginationInfo`, `defaultLayouts` (`table` + `grid`),
   `getItemId = ( item ) => item.post_id`, `isLoading`. Server-side
   pagination/sorting/filtering — pass the totals through, never slice in JS.
5. **Per-row actions** (DataViews `actions` prop), each with a `isEligible`
   predicate reading that row's `available_transitions` and `capabilities`:
   `Edit` (link), `Approve`, `Request changes`, `Assign reviewer`, `Set due date`.
   The mutating ones use `RenderModal` with `DataForm` (step 17 wires bulk).
   `isEligible` is a UX filter only — the server re-checks (say so in a comment).
6. **URL state:** reflect `view` into the query string (`?status=review&page=2`)
   with `history.replaceState` so a filtered dashboard is linkable and survives
   refresh.
7. **Density/scale:** default `perPage: 20`, options 20/50/100. Empty state:
   "No content is in the workflow yet" + a link to create a post.

## Acceptance criteria

- Menu appears for editors/authors, not subscribers.
- Table loads, paginates, sorts by title/due date, and filters by status and
  reviewer — every operation hitting the server, verified in the network tab.
- Row actions appear only where legal; approving from a row updates that row
  in place (refetch the page, not the whole app).
- Refreshing a filtered URL restores the same view.
- Assets load on this screen only (assert `$hook_suffix` guard in a PHP test).
- Jest: `viewToQuery()` maps every DataViews filter operator to the right REST
  arg; unknown operators are dropped rather than passed through.
