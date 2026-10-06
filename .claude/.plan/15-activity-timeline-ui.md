# 15 — Activity timeline UI

**Goal:** a readable, grouped, paginated history component reused by the sidebar
(and later by the dashboard row expander).
**Prerequisites:** 12, 14.
**Dev plan reference:** Phase 8.

## Files to create

```
src/components/ActivityTimeline.jsx
src/components/ActivityItem.jsx
src/hooks/useActivity.js
src/utils/groupByDay.js
src/components/activity.scss
tests/js/useActivity.test.js
tests/js/ActivityTimeline.test.jsx
```

## Tasks

1. **`useActivity( postId, { perPage = 20, version } )`** returns
   `{ items, isLoading, error, hasMore, loadMore(), refresh() }`.
   - Appends pages rather than replacing, using `X-WP-Total`/`X-WP-TotalPages`
     read via `apiFetch( { parse: false } )` → `response.headers.get(...)`.
   - `version` is the counter `useWorkflow` bumps after a mutation; changing it
     resets to page 1 so a new entry appears immediately.
2. **`groupByDay( items )`** — buckets into `Today` / `Yesterday` / a localized
   date heading, using the site's timezone. Convert the ISO UTC `created_at`
   with `@wordpress/date`'s `dateI18n`/`getDate` — never `new Date()` arithmetic
   in local browser time, or entries land under the wrong heading for users in
   another timezone than the site.
3. **`ActivityItem`** — renders an avatar, an actor name, a humanized sentence,
   and the relative time as a `<time dateTime>` with the absolute timestamp in
   `title`. Sentence builder maps action → translatable string using
   `sprintf`/`_x` with placeholders, **never** string concatenation (breaks
   translation):
   ```js
   sprintf(
     /* translators: 1: user name, 2: previous status, 3: new status */
     __( '%1$s changed status from %2$s to %3$s', 'sit-cwm' ),
     name, oldLabel, newLabel
   )
   ```
   Comment bodies render through a small allow-list renderer, not
   `dangerouslySetInnerHTML` on raw input — server-side `wp_kses_post` is the
   guarantee, but the client should not re-introduce risk. Prefer rendering the
   plain-text projection of the comment in v1.0.
4. **States:** skeleton rows while loading the first page; an empty state
   ("No activity yet."); an error notice with a retry button; "Load more"
   button (not infinite scroll — keyboard and screen-reader friendlier).
5. **Deleted users** render as `__( 'Someone', 'sit-cwm' )` with a generic
   avatar; `user_id = 0` renders as `__( 'System', 'sit-cwm' )`.
6. Accessibility: the timeline is an `<ol>`; each item is an `<li>`; new entries
   announced through an `aria-live="polite"` region.

## Acceptance criteria

- Timeline shows entries newest-first, grouped by day, with correct headings for
  a site timezone offset from the tester's browser.
- Adding a comment or changing status prepends the new entry without a reload.
- "Load more" fetches page 2 and appends without duplicates.
- Empty, loading and error states each render (covered by Jest tests with mocked
  `apiFetch`).
- A user without `sit_cwm_view_activity` sees the timeline section hidden and
  the REST call is never made.
