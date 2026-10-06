# 22 — UX polish, accessibility and i18n

**Goal:** make every state legible — loading, empty, error, forbidden, stale —
and make every string translatable and every control reachable by keyboard.
**Prerequisites:** 14–18.
**Dev plan reference:** Phase 23.

## 22.1 States to cover everywhere

For the sidebar, the timeline and the dashboard:

| State | Treatment |
|---|---|
| Loading (first) | `<Spinner />` / skeleton rows, never a blank panel |
| Loading (refetch) | keep old data, show a subtle busy indicator |
| Saving | disable the control, spinner in the button, block double-submit |
| Empty | explanatory sentence + the next action, not just "No results" |
| Error | `<Notice status="error">` with the server message + Retry |
| Forbidden | hide the control **and** explain why when the whole panel is gated |
| Offline / network fail | "Couldn't reach the server" + Retry (distinct from 4xx) |

## 22.2 Edge cases from the dev plan (each needs a defined behaviour)

- **Reviewer deleted** → meta cleared by the `deleted_user` hook (step 08); UI
  shows "Unassigned"; timeline keeps the historical name as "Someone".
- **Post deleted while open** → REST 404 → sidebar shows a dismissible notice and
  disables controls.
- **Status no longer valid** (a Pro filter removed a status) → treat unknown
  stored status as the default, show a warning notice, offer the valid moves.
- **Permission revoked mid-session** → the next mutation returns 403; surface the
  message and refetch so the UI stops offering the action.
- **Concurrent edit** → 409 handling from step 14.
- **Overdue due date** → warning styling + `aria-label` including "overdue".

## 22.3 Confirmations

Shared `<ConfirmDialog>` used for: Approve, Publish, any `is_rollback`
transition, and every bulk action. Copy pattern from the dev plan:

> **Approve Content?**
> This will mark the content as approved.
> [Cancel] [Approve]

Confirm dialogs must be dismissible with Escape and must return focus to the
trigger.

## 22.4 Accessibility

- All interactive elements are real buttons/links; no click handlers on `<div>`.
- Visible focus rings (don't override WP admin defaults).
- Status changes announced via `speak()` from `@wordpress/a11y` or an
  `aria-live="polite"` region.
- Colour is never the only signal — status badges carry text; overdue carries an
  icon/label, not just red.
- Contrast ≥ 4.5:1 for badge text against badge background; check every status
  colour from D3.
- Dashboard table is keyboard-navigable; modals trap focus.
- Test one full flow with the keyboard only, and one pass with a screen reader.

## 22.5 Internationalization

- Every user-facing string wrapped with the `sit-cwm` text domain — PHP and JS.
- `sprintf`-with-placeholders for interpolation; `/* translators: */` comments on
  every placeholder string; `_n()` for counts; `_x()` where context disambiguates
  (e.g. "Draft" the status vs. the verb).
- `wp_set_script_translations()` called for both bundles (step 13).
- Generate `languages/sit-cwm.pot` via `npm run makepot`; keep it tracked in git.
- Dates rendered with `dateI18n`/`date_i18n` and the site timezone, never
  hard-coded formats.
- RTL: run `wp-scripts build` with RTL CSS generation and verify the sidebar and
  dashboard in an RTL locale.

## 22.6 Responsive

- Dashboard usable at 782 px (WP's mobile admin breakpoint): DataViews grid
  layout as the small-screen default, filters collapsing into a drawer.
- Sidebar controls stack without overflow at the minimum editor sidebar width.

## Acceptance criteria

- Every state in 22.1 demonstrable in the running plugin (screenshot each for
  step 23's README).
- Every edge case in 22.2 has a defined, tested behaviour — no white screens,
  no silent failures.
- `npm run makepot` produces a POT with zero untranslated user-facing strings
  (spot-check by grepping for quoted strings in JSX without `__(`).
- Keyboard-only pass completes the full editorial flow.
- No accessibility errors in an axe scan of the dashboard screen.
