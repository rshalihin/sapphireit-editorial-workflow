# 20 — Security hardening review

**Goal:** a deliberate, line-by-line pass over the whole codebase against the
CLAUDE.md security rules, because this plugin decides who may approve and
publish content.
**Prerequisites:** all feature steps.
**Dev plan reference:** Phase 21.

## 20.1 Audit checklist — walk every file, tick every line

> Done 2026-09-15, re-verified 2026-09-27. Evidence per item: ARCHITECTURE.md →
> "Security review (step 20)"; exceptions E1–E4 listed there; subscriber probe results in
> its Verification section.

**Authorization**
- [x] No `permission_callback => '__return_true'` anywhere (grep).
- [x] Every REST route checks, in order: authentication → capability → access to
      *this* post → transition validity.
- [x] No `current_user_can()` outside `PermissionManager` (grep `includes/`,
      `admin/`; template rendering may use it for display only — list exceptions
      explicitly in ARCHITECTURE.md).
- [x] No role-name checks (`'editor'`, `'administrator'`) outside
      `Capabilities::role_map()`.
- [x] `edit_post`/`publish_post` meta-cap checks use the post id, never the bare
      `edit_posts` primitive, for per-post decisions.
- [x] Bulk paths re-check per post (step 17).

**Input**
- [x] Every REST arg has `type` + `sanitize_callback` (+ `enum`/`validate_callback`
      where the domain is closed).
- [x] `wp_unslash()` before every sanitize on `$_POST`/`$_GET`/`$_REQUEST`.
- [x] `absint()` for ids; no `intval()` on user ids that could go negative.
- [x] Date input validated by round-trip parse, not regex alone.
- [x] Comment bodies `wp_kses_post` + length-capped.
- [x] Unknown request fields ignored, never persisted.

**Output**
- [x] Every echo in `admin/` and any template escaped at the point of output.
- [x] JSON to JS via `wp_json_encode()`; nothing interpolated into inline script
      strings.
- [x] No `dangerouslySetInnerHTML` in `src/` (grep).
- [x] Error messages never leak post titles/content to users who cannot see them.

**Database**
- [x] Every custom query uses `$wpdb->prepare()`; placeholder lists for `IN ()`
      built with `array_fill()` + `implode()`, never `implode( ',', $ids )` of
      raw input.
- [x] `ORDER BY`/`LIMIT` from whitelists and `%d`.
- [x] Table name only from `Database::table_name()`.
- [x] Every deliberate `phpcs:ignore` for DB sniffs has a why-comment.

**General**
- [x] No `eval()`, no variable `include`/`require`, no `unserialize()` of stored
      data (`context` is JSON).
- [x] `ABSPATH` guard at the top of every PHP file (script-scan the tree).
- [x] `uninstall.php` guarded by `WP_UNINSTALL_PLUGIN`.
- [x] Nonces on any non-REST admin form/AJAX handler.
- [x] No secrets, keys or emails in the bootstrap JS object.

## 20.2 Information-disclosure rules

Decide and apply consistently, then document:
- A user who cannot read a post gets **404**, not 403 — a 403 confirms the post
  exists. 403 is reserved for "you can see it but may not do this".
- `/users` never returns emails or logins, and is capability-gated (step 12).
- Activity messages are only readable with `sit_cwm_view_activity` **and**
  access to the post.

## 20.3 Verification, not just reading

- Run `composer lint` with WordPress-Extra: zero errors.
- Run a targeted grep suite and paste the (empty) results into the PR:
  `__return_true`, `current_user_can(`, `$wpdb->query(`, `dangerouslySetInnerHTML`,
  `intval(`, `\$_(GET|POST|REQUEST)`.
- Re-run the step 19.4 negative suite.
- Manual probe with an authenticated subscriber session: attempt every route and
  record the status codes in a small table in the PR description.
- Optional: run `/security-review` over the branch diff and triage findings.

## Acceptance criteria

- Checklist fully ticked, with the exceptions list written down rather than
  silently allowed.
- Negative test suite green.
- The subscriber probe table shows 401/403/404 for every mutating route and no
  data leakage in any body.
