# Content Workflow Manager — Pro plans

Plans that belong to a future **Pro** add-on, kept apart from the Free v1.0
build plan in `../.plan/`. Nothing in this folder is part of the Free
release; do not implement these steps in this repository unless the developer
explicitly asks.

## Pro scope

Email notifications, Slack integration, editorial calendar, multiple
workflows, role-configurable workflows, checklist gating, a workflow rules
engine and advanced audit logs.

## Plans

| # | Plan | What it covers |
|---|---|---|
| 01 | [Pro extension points audit](01-pro-extension-points.md) | Formerly Free step 24. Adds the seams a Pro add-on needs (post-id context on the status, transition and capability filters; a `WP_Error` veto reason in `sit_cwm_can_transition`; a `sit_cwm_posts_query_args` filter; a service-container accessor; `@api` tags) and proves them with a probe add-on. |

Status: not started.
