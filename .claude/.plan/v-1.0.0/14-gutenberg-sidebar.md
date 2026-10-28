# 14 — Gutenberg sidebar

**Goal:** the editor-side workflow panel, built as a thin client over the REST
API, with `useWorkflow()` as the single data hook.
**Prerequisites:** 11, 12, 13.
**Dev plan reference:** Phase 6, Phase 7.

## Files to create

```
includes/Editor/SidebarAssets.php      enqueue + conditional registration
src/sidebar/index.js                   registerPlugin
src/sidebar/Sidebar.jsx
src/sidebar/components/StatusControl.jsx
src/sidebar/components/ReviewerControl.jsx
src/sidebar/components/DueDateControl.jsx
src/sidebar/components/TransitionActions.jsx
src/sidebar/components/CommentForm.jsx
src/hooks/useWorkflow.js
src/hooks/useUsers.js
src/api/client.js                      apiFetch wrappers + error normalisation
src/utils/format.js
src/sidebar/style.scss
tests/js/useWorkflow.test.js
tests/js/StatusControl.test.jsx
```

## Tasks

1. **`SidebarAssets implements Bootable`** — on `enqueue_block_editor_assets`,
   bail unless the current post type is workflow-enabled and the user passes
   `PermissionManager::can_edit_post()`. Enqueue per step 13, add the inline
   bootstrap object, and `wp_set_script_translations`.
2. **`src/api/client.js`** — thin `apiFetch` wrappers:
   `getWorkflow(postId)`, `updateWorkflow(postId, payload)`,
   `getActivity(postId, args)`, `addComment(postId, message)`,
   `getUsers(args)`. One `normalizeError( error )` turning a REST `WP_Error`
   response into `{ code, message, status }`, so components never inspect raw
   fetch errors.
3. **`useWorkflow( postId )`** — the portfolio centrepiece. Returns:
   ```js
   { workflow, isLoading, isSaving, error,
     updateStatus( to ), assignReviewer( id ), setDueDate( date ),
     addComment( message ), refresh() }
   ```
   - Initial `GET` on mount; `AbortController` cleanup on unmount/post change.
   - **Optimistic concurrency:** every status write sends
     `{ from: workflow.status, status: to }`. On a `409` response, refetch and
     surface "This post changed elsewhere — refreshed." rather than retrying.
   - Mutations are *not* optimistic in the UI: show `isSaving`, apply the server
     response. The server is the source of truth (CLAUDE.md), and a rolled-back
     optimistic update in an approval UI is worse than a 300 ms spinner.
   - After a successful status change, dispatch a `core/notices` success notice
     and bump a local `activityVersion` counter the timeline listens to.
4. **`Sidebar.jsx`** — `registerPlugin( 'sit-cwm-sidebar', { render } )` with
   `PluginSidebar` + `PluginSidebarMoreMenuItem`, icon, title
   `__( 'Content Workflow', 'sit-cwm' )`. Read `postId`/`postType` with
   `useSelect( ( s ) => s( 'core/editor' ).getCurrentPostId() )`.
   Render order: status badge → reviewer → due date → available actions →
   comment form → `<ActivityTimeline />` (step 15).
5. **`StatusControl`** — read-only badge showing the current status with its
   colour from the bootstrap data. Status is changed by the action buttons, not
   a free-form `SelectControl`; a dropdown of all six statuses invites requests
   the server will reject. (If a select is wanted later, populate it strictly
   from `available_transitions`.)
6. **`TransitionActions`** — one `<Button>` per entry in
   `available_transitions`; `isDestructive` when `is_rollback`. Disabled while
   `isSaving`. Rollback actions and `published` open a confirm dialog
   (step 22 adds the shared `<ConfirmDialog>`).
7. **`ReviewerControl`** — `ComboboxControl` fed by `useUsers()` with a
   debounced (300 ms) `search`. Shows the current reviewer with avatar; includes
   a "— Unassigned —" option mapping to `reviewer_id: 0`. Hidden entirely when
   `workflow.capabilities.can_assign_reviewer` is false.
8. **`DueDateControl`** — `Dropdown` + `DatePicker`, displaying the date with
   `dateI18n( getSettings().formats.date, value )`. Stores `Y-m-d`. Clear button.
   Overdue dates render in the warning colour.
9. **`CommentForm`** — `TextareaControl` + submit, disabled while empty or
   saving, clears on success, shows the REST error message on failure.
10. **Empty/error/loading states** are part of this step, not deferred to 22:
    `<Spinner />` while loading, `<Notice status="error" isDismissible>` for
    errors, and a "Workflow not available for this post type" fallback.

## Acceptance criteria

- Sidebar appears only for enabled post types and permitted users.
- Approving from the sidebar updates status, timeline and available actions
  without a page reload.
- A user lacking `sit_cwm_approve_content` never sees an Approve button — and a
  crafted `POST` still returns 403 (re-assert the step 11 test).
- Simulated 409: two tabs, approve in one, approve in the other → the second
  shows the conflict notice and refreshes to the true state.
- Jest tests: `useWorkflow` loading → success → error paths with mocked
  `apiFetch`; `StatusControl` renders the right label/colour; `TransitionActions`
  renders exactly the allowed buttons.
- `npm run lint:js` clean; no `console.log` left behind.
