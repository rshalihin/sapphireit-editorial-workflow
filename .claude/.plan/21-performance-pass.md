# 21 — Performance pass (kill the N+1)

**Goal:** the dashboard and timeline stay flat as content grows — no per-row
reviewer or activity query.
**Prerequisites:** 16, 17.
**Dev plan reference:** Phase 22 (explicitly flagged by CLAUDE.md).

## 21.1 Measure first

1. Seed a realistic dataset with a WP-CLI script (`bin/seed.php`, dev-only,
   excluded from the release zip): 500 posts, random statuses, 10 reviewers,
   ~5 000 activity rows.
2. Install Query Monitor in `wp-env` and record, for each scenario:
   queries, duration, peak memory.

| Scenario | Budget |
|---|---|
| `GET /sit-cwm/v1/posts?per_page=100` | ≤ 8 queries, < 300 ms |
| `GET /posts/<id>/workflow` | ≤ 5 queries |
| `GET /posts/<id>/activity?per_page=20` | ≤ 4 queries |
| `POST /posts/<id>/workflow` | ≤ 10 queries |
| `POST /posts/batch` (50 posts) | linear in posts, no per-post user query |
| Dashboard first paint | < 1.5 s on the seeded set |

Write the measured numbers into DEVELOPMENT.md so regressions are visible.

## 21.2 Fixes to apply

1. **Batch meta:** `update_meta_cache( 'post', $post_ids )` once per collection
   response, before reading any workflow meta (step 08's batch methods).
2. **Batch users:** collect every reviewer/author id across the page, one
   `get_users( [ 'include' => $ids, 'fields' => [...] ] )`, then
   `cache_users( $ids )` so `get_userdata()` in later code is cache-hot.
   Never `get_userdata()` inside a row loop.
3. **Batch last-activity:** one `ActivityLogger::get_for_posts()` query for the
   whole page rather than one per row.
4. **`WP_Query` tuning:** `'fields' => 'ids'`, `'update_post_term_cache' => false`
   when terms aren't rendered, `'no_found_rows' => false` only where the total is
   actually needed (it is, for pagination).
5. **meta_query cost:** filtering by `_sit_cwm_status` scans `postmeta`. Keep the
   query to a single meta clause where possible; if two clauses are needed, put
   the most selective first. Document that a dedicated index table is a Pro-scale
   follow-up — do **not** add one in v1.0.
6. **Avoid `posts_per_page => -1`** anywhere (grep). Hard cap `per_page` at 100.
7. **Activity table:** confirm `(post_id, created_at)` is actually used —
   run `EXPLAIN` on the timeline query and paste the output in the PR.
8. **Front end:** the dashboard fetches one page at a time; debounce search
   (300 ms); cancel in-flight requests on view change with `AbortController`;
   memoize `fields` and row renderers so a filter change doesn't re-render every
   cell needlessly.
9. **Autoload:** ensure `sit_cwm_settings` is autoloaded (`yes`) — it's small and
   read on every request — and that no large option is added.

## 21.3 Guard against regression

Add a PHPUnit test asserting query counts for the two hot paths, using
`$wpdb->num_queries` deltas with generous-but-real ceilings (e.g. `<= 8`).
A failing count is a legitimate failure, not flake — it means someone
reintroduced a loop query.

## Acceptance criteria

- Every budget in 21.1 met on the seeded dataset.
- Query-count tests pass and are wired into CI.
- Query Monitor shows no duplicate queries on the dashboard screen.
- Doubling the dataset to 1 000 posts does not increase the per-request query
  count (only row count/time).
