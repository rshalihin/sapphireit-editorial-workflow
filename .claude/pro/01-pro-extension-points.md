# 24 — Pro extension points audit

**Goal:** prove that every Pro feature named in CLAUDE.md can be built as a
separate add-on plugin **without editing a single Free core file**. This step
writes no Pro code — it verifies the seams and fixes them if they're missing.
**Prerequisites:** all previous steps.
**Dev plan reference:** Phases 11–19 (Pro), CLAUDE.md "Extensibility".

## 24.1 Trace each Pro feature to its seam

| Pro feature | Seam it needs | Exists? |
|---|---|---|
| Email notifications | `sit_cwm_status_changed`, `sit_cwm_reviewer_assigned`, `sit_cwm_comment_added` | verify args are sufficient (post id, both values, actor) |
| Slack integration | same actions + `sit_cwm_activity_logged` | verify the entry object carries everything needed |
| Multiple workflows | `sit_cwm_statuses`, `sit_cwm_transition_map`, `sit_cwm_status_capability_map` must all accept a `$post_id` context arg | **likely gap — add the context parameter now** |
| Role-based workflows | `sit_cwm_status_capability_map` + `sit_cwm_can_transition` | verify the veto path works |
| Editorial calendar | `PostsController` query + `sit_cwm_posts_query_args` filter | **add the query-args filter if missing** |
| Checklist gating | `sit_cwm_can_transition` returning false + a message | **gap: the filter returns bool, so gating loses the reason** |
| Workflow rules engine | same as checklist, plus `sit_cwm_available_transitions` | as above |
| Advanced audit log | `sit_cwm_activity_logged`, `ActivityLogger` read API stability | verify the read API is public and documented |

## 24.2 Fixes to make in this step

1. **Pass context to every filter.** Each of `sit_cwm_statuses`,
   `sit_cwm_transition_map`, `sit_cwm_status_capability_map` gains a trailing
   `$post_id` (`0` when not post-specific). Without it, per-workflow and
   per-post-type Pro behaviour is impossible and Phase 15 would force a rewrite
   of Free core — exactly what CLAUDE.md forbids.
2. **Let a veto carry a reason.** Change the `sit_cwm_can_transition` contract to
   accept `true|false|WP_Error` as the filtered value: a `WP_Error` means denied
   *with a message* the REST layer surfaces verbatim. Free core never returns a
   `WP_Error` from that filter itself; it only honours one. This is what makes
   "Cannot approve: checklist incomplete" possible later.
3. **Add `sit_cwm_posts_query_args`** in `PostsController` so the calendar and
   saved views can extend the query without forking the controller.
4. **Make the service container reachable**: a single
   `sit_cwm_container(): Container` accessor (or a
   `sit_cwm_loaded` action passing the `Plugin`), so an add-on can fetch
   `workflow_manager` instead of instantiating a parallel stack.
5. **Freeze the public API surface**: mark every class/method Pro may call with
   `@since 1.0.0` and an explicit `@api` tag; everything else gets `@internal`.
   Breaking an `@api` method later requires a major version bump — write that
   rule in ARCHITECTURE.md.

## 24.3 Verification: build a throwaway probe add-on

Create `tests/fixtures/sit-cwm-probe/` — a tiny plugin (not shipped) that:
- adds a `legal_review` status via `sit_cwm_statuses`,
- rewires the map so `review → legal_review → approved`,
- requires a new capability for `legal_review`,
- vetoes `approved` with a `WP_Error` when a dummy checklist meta is unset,
- logs every `sit_cwm_status_changed` to a transient,
- extends the dashboard query via `sit_cwm_posts_query_args`.

Write an integration test that activates the probe and asserts each behaviour.
**If any of these needs a change inside `includes/`, the seam is missing —
fix the seam, not the probe.**

## Acceptance criteria

- The probe add-on achieves all six behaviours with zero edits to Free core.
- The sidebar and dashboard render the injected `legal_review` status correctly
  (labels/colours come from the registry, not hard-coded — proves step 05).
- The `WP_Error` veto message reaches the sidebar UI verbatim.
- Every `@api` method is listed in ARCHITECTURE.md with its signature.
- No Pro *feature* code lands in this repo — only seams, docs and the test
  fixture.
