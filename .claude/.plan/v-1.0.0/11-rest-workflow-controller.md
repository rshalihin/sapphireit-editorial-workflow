# 11 — REST: WorkflowController

**Goal:** expose the workflow engine over `sit-cwm/v1` as a thin, hostile-input-
safe HTTP layer. Zero business logic here.
**Prerequisites:** 10. Implements decision **D9**.
**Dev plan reference:** Phase 5, Phase 21.

## Files to create

```
includes/REST/AbstractController.php
includes/REST/WorkflowController.php
tests/php/integration/REST/WorkflowControllerTest.php
```

## Tasks

1. **`AbstractController extends WP_REST_Controller`** — shared plumbing:
   - `protected $namespace = 'sit-cwm/v1';`
   - `resolve_post( WP_REST_Request $r )` → `WP_Post|WP_Error`, returning
     `sit_cwm_invalid_post` (404) for a missing post and
     `sit_cwm_not_managed` (404) for a disabled post type.
   - `error_to_response( WP_Error $e ): WP_Error` mapping our error codes to
     HTTP status via one array (`forbidden` 403, `invalid_*` 400,
     `status_conflict` 409, `not_managed`/`invalid_post` 404).
   - `require_login()` helper returning the standard
     `rest_forbidden` 401 for logged-out users.
2. **Routes** (`register_routes()` on `rest_api_init`):

   | Method | Route | Callback | permission_callback checks |
   |---|---|---|---|
   | GET | `/posts/(?P<post_id>[\d]+)/workflow` | `get_item` | logged in → post resolvable → `can_edit_post` OR `can_view_activity` |
   | POST | `/posts/(?P<post_id>[\d]+)/workflow` | `update_item` | logged in → post resolvable → `can_edit_post`; per-field caps re-checked in WorkflowManager |
   | GET | `/statuses` | `get_statuses` | logged in + `can_manage` OR any `edit_posts` |

3. **Argument schema — declare it, don't parse it.** Every arg gets `type`,
   `required`, `sanitize_callback`, `validate_callback`:
   ```php
   'status'      => [ 'type' => 'string',  'enum' => $statuses->slugs(), 'sanitize_callback' => [ $statuses, 'sanitize' ] ],
   'from'        => [ 'type' => 'string',  'enum' => $statuses->slugs() ],   // required when 'status' present
   'reviewer_id' => [ 'type' => 'integer', 'sanitize_callback' => 'absint' ],
   'due_date'    => [ 'type' => 'string',  'format' => 'date', 'validate_callback' => … ],
   ```
   `post_id` is validated by the route regex `[\d]+` and `absint`.
4. **`update_item()` semantics** — a single POST may carry any combination of
   `status`, `reviewer_id`, `due_date`. Apply them in a fixed order
   (reviewer → due date → status) by delegating to the matching
   `WorkflowManager` method. First `WP_Error` aborts and is returned; already-
   applied changes stay applied (documented in REST-API.md — v1.0 is not
   transactional). Respond with the fresh `get_workflow()` payload so the client
   never has to guess state.
5. **`permission_callback` is never `__return_true`** for any route, including
   GET — workflow state is editorial information. Every callback delegates to
   `PermissionManager`; it never calls `current_user_can()` itself.
6. **Nonces:** the REST cookie auth path already requires `X-WP-Nonce`; the JS
   clients use `@wordpress/api-fetch`, which sends it via the
   `wp-api-fetch` nonce middleware. Document that application-password /
   JWT clients authenticate without a nonce and are still capability-checked.
7. Register the controller from `Plugin::boot()` on `rest_api_init` via the
   container — no `new` inside hook callbacks.

## Response codes to honour

| Situation | Code |
|---|---|
| Success | 200 |
| Unauthenticated | 401 |
| Authenticated, not allowed | 403 |
| Post missing / not managed / not visible to user | 404 |
| Bad status, bad transition, bad date | 400 |
| Stale `from` (concurrent edit) | 409 |

## Acceptance criteria

REST integration tests via `rest_do_request()`:
- Logged-out GET → 401. Subscriber GET → 403 (or 404 for a private post).
- `POST { "status": "approved" }` as an author → 403 **and the meta is unchanged**
  — the headline security test from CLAUDE.md.
- `POST { "status": "approved" }` omitting `from` → 400.
- `POST { "status": "published" }` from `review` → 400 invalid transition.
- `POST { "reviewer_id": 999999 }` → 400 invalid user.
- `POST { "due_date": "20-09-2026" }` → 400.
- Unknown extra fields in the body are ignored, not persisted.
- A successful POST returns the full workflow payload with updated
  `available_transitions`.
- Route list assertion: every registered `sit-cwm/v1` route has a
  `permission_callback` that is not `__return_true` (loop
  `rest_get_server()->get_routes()` — this test also guards steps 12 and 17).
