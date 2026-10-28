# SapphireIT Editorial Workflow — Browser QA Report

**Date:** 2026-10-05 · **Branch:** `rs-implement` (`f9694ff`) · **Environment:** Laragon, WP 7.1.2, PHP 8.3, WooCommerce 11.1.2 (HPOS on), Chrome, browser TZ Asia/Dhaka (UTC+6)

## 1. Summary

The core of the plugin is solid. The transition engine, the permission layer, the REST API, the sidebar and the dashboard all held up under functional, role-matrix, concurrency and injection testing. I found **no security or data-integrity defects**.

I found **3 issues** (1 medium, 2 low) and noted **2 design observations**. Every issue is a display or configuration problem in the UI layer.

| # | Severity | Area | Issue |
|---|---|---|---|
| 1 | Medium | Dashboard / sidebar | HTML entities in post titles render literally (`Q&amp;A`) |
| 2 | Low | Settings | Non-editorial / non-block-editor types (WooCommerce Orders, Coupons, Products) offered with no warning |
| 3 | Low | Sidebar UI | Reviewer avatar/name renders above the "Reviewer" label and duplicates the combobox value |

Automated suites (run during this session): PHPUnit unit **104/104 pass**, PHPCS **0 violations**, Jest **164/164 pass**.

---

## 2. Issues

### Issue 1 — HTML entities in titles shown literally (Medium)

**Observed:** A post created by an Author titled `CWMTEST Q&A Session` shows in the dashboard as **`CWMTEST Q&amp;A Session`**. The same happens for any `&`, `<`, `>`, `"` or `'` in titles saved by users without `unfiltered_html`: Authors and Contributors on every site, and everyone on multisite. These are the main users of this plugin.

**Reproduce:**
1. Log in as an Author and create a post titled `Q&A Session`. You can also run `wp post create --post_title='Q&A' --user=<author_id>`.
2. Open *Editorial Workflow → Dashboard*. The title reads `Q&amp;A Session`.
3. The same raw string appears in the row-action modals (Transition / Reviewer / Due date) and in the bulk-action item lists.

**Root cause:** For these users, kses stores `&` in `post_title` as `&amp;` (`wp_filter_kses`). The REST layer returns the raw DB value, and React renders it as a text node. That is correctly XSS-safe, but nothing decodes the entities first.
- `includes/REST/PostsController.php:500`: `'title' => $post->post_title`
- `includes/Workflow/WorkflowManager.php:151`: `'post_title' => $state['post_title']`
- Consumers that never decode: `src/dashboard/fields.js:81,237`, `src/dashboard/bulk/shared.jsx:110`, `src/dashboard/modals/{TransitionModal,ReviewerModal,DueDateModal}.jsx`

Core `wp-admin` avoids this because it echoes titles as HTML. Core's own DataViews screens decode on the client.

**Fix plan:**
1. Decode on the client, following the Gutenberg convention. Import `decodeEntities` from `@wordpress/html-entities` and apply it wherever `item.title` / `post_title` is rendered or used as a sort/search value. A small helper avoids repeating the call: `export const displayTitle = ( t ) => decodeEntities( t || '' ) || __( '(no title)', … )` in `src/utils/format.js`.
2. Add `@wordpress/html-entities` to the dependencies. `dashboard.asset.php` will then list `wp-html-entities` automatically.
3. Keep the REST payload raw. Do **not** add `html_entity_decode()` server-side. The JSON contract stays "raw DB value", the same as core's `title.raw`.
4. Apply the same treatment to user `display_name` (reviewer, author, activity actor). The same kses behaviour can affect names.
5. Tests: add a Jest case to `tests/js/fields.test.js` (title `A &amp; B` renders `A & B`) and one to `RowModals.test.jsx`. Add an E2E assertion in `dashboard.spec.js` with an author-created `Q&A` post.
6. Rebuild `assets/build/` and commit it with the source change (per D11).

---

### Issue 2 — Settings offers WooCommerce Orders / Coupons (and Products without a sidebar) (Low)

**Observed:** *Editorial Workflow → Settings → Enabled content types* lists **Posts, Pages, Products, Orders, Coupons**. On this site:

| Type | Block editor | supports `editor` | public | Notes |
|---|---|---|---|---|
| `product` | no | yes | yes | Enabling it gives dashboard-only workflow with no editor UI |
| `shop_order` | no | no | no | HPOS is on, so orders are not even stored as posts |
| `shop_coupon` | no | no | no | Not editorial content |

Enabling Orders or Coupons is effectively a no-op or a confusing state. Enabling Products works in the dashboard, but the product edit screen shows no workflow controls, and nothing in the UI says why. `readme.txt:136` documents the classic-editor limitation, but the settings screen never mentions it.

**Root cause:** `includes/Core/Settings.php:187` `available_post_types()` uses `get_post_types( array( 'show_ui' => true ) )` and only excludes `attachment`, `revision`, `nav_menu_item` and `wp_*`. `show_ui` is too broad a signal for "editorial content".

**Fix plan:**
1. Narrow eligibility in `available_post_types()` to `show_ui && show_in_rest && post_type_supports( $type, 'editor' )`. That drops `shop_order`/`shop_coupon` and similar back-office types from other plugins. Keep it filterable (`sit_cwm_available_post_types`) so a site can opt a type back in.
2. In `admin/Settings.php` `render_post_types_field()`, append a short note to types where `! use_block_editor_for_post_type( $type )`, for example: "(dashboard only — this type uses the classic editor, so the workflow sidebar is not available)".
3. Migration safety: if a type that is already enabled is no longer eligible, keep it in the option (don't silently drop data) and render it with an "unsupported" note. This matches the existing "data is kept when turned off" promise.
4. Tests: add integration tests to `SettingsPageTest` (non-`editor` types are excluded, and the classic-editor note is rendered).

---

### Issue 3 — Reviewer summary renders above its label (Low, cosmetic)

**Observed:** After a reviewer is assigned, the sidebar shows the avatar and name *between* the Status description and the "REVIEWER" label, so it reads as part of the Status section. The name is then repeated inside the combobox.

**Root cause:** `src/sidebar/components/ReviewerControl.jsx:47-60` renders `.sit-cwm-reviewer-current` before `<ComboboxControl label="Reviewer">`. The label is part of the combobox, so it ends up below the summary.

**Fix plan (pick one):**
- **A (simplest):** remove the separate summary. The combobox already shows the name. Optionally put the avatar in the option renderer (`__experimentalRenderItem`).
- **B:** keep the summary but render an explicit heading first: hide the combobox's label (`hideLabelFromVision`), put a `BaseControl.VisualLabel` above the summary, and keep the accessible label on the combobox.
- Update `tests/js/SidebarControls.test.jsx` and refresh the sidebar screenshot in `docs/`.

---

## 3. Design observations (not bugs, documented intentionally)

1. **The workflow does not gate native WordPress publishing.** An Author (who has `publish_posts`) can press *Publish* in the editor while the workflow status is still `draft`/`writing`. Workflow `published` also never publishes the post. This is decision D3 and is stated in `readme.txt:54` and `WORKFLOW.md:161`. Note it anyway, because "approval workflow" users often expect publishing to be blocked until approval. That makes a good candidate for a Pro "publish gate" (filter on `wp_insert_post_data` / `rest_pre_insert_{type}` using `sit_cwm_can_transition`). The extension points for it already exist.
2. **Relative timestamps don't tick.** `created_at_human` is computed by the server at fetch time, so "2 seconds ago" stays until the next refetch. The values are accurate on load. A client-side `humanTimeDiff( created_at )` would keep them live. Cosmetic.

---

## 4. What was tested and passed

**Transition engine (REST, as admin)**
- Legal chain `draft → writing → review → approved → published → writing` ✔
- Skip (`draft → approved`) → `400 sit_cwm_invalid_transition` ✔
- Stale `from` → `409 sit_cwm_status_conflict` ✔
- Unknown status → `400 rest_invalid_param` ✔
- Missing `from` → `400` ✔
- Combined request with an invalid transition plus a valid due date is rejected **atomically** (due date not applied) ✔

**Validation**
- Invalid date `2026-02-30` → 400 ✔
- Reviewer = subscriber / author / nonexistent ID → `400 sit_cwm_invalid_user` ✔
- Empty / whitespace-only comment → 400 ✔
- Comment `<script>`, `onerror` stripped by `wp_kses_post` ✔
- Post title with `<img onerror>` renders as inert text in the dashboard ✔

**Role matrix (real REST dispatch per user via WP-CLI)**
- Subscriber: workflow 404, statuses/users/posts 403 ✔
- Contributor: own post `draft → writing → review` ✔. `→ approved` 403 ✔. Assign reviewer 403 ✔. Comment on own post 201 ✔. User list 403 ✔. Other authors' posts 404 (no existence leak) ✔. Dashboard list shows own posts only ✔. Batch on admin's post → 0 succeeded ✔
- Author: same scope as contributor ✔
- Editor: sees all, `review → needs_changes` ✔, reviewer list ✔, activity ✔
- Unauthenticated REST → 401 ✔

**Gutenberg sidebar (browser)**
- Opens from the header toggle (accessible label "Editorial Workflow") ✔
- Only legal, permitted transitions shown ✔. Rollbacks styled distinctly ✔
- Reviewer combobox lists only users with `sit_cwm_review_content` ✔
- Due date picker stores the correct `Y-m-d` with the browser at UTC+6 (no off-by-one) ✔
- Comment with line breaks/markup posts and appears in the timeline ✔. Timeline grouped by day and updates live ✔
- Concurrency: status changed out-of-band, then a stale button clicked → "This post changed elsewhere — refreshed." and the controls refresh to the current state ✔
- No console errors ✔

**Admin dashboard (browser)**
- List, status badges, reviewer, author, type, last activity ✔
- Overdue highlighting (`October 1, 2026 (overdue)`) ✔
- Status filter, URL-synced (`&status=writing`), search (`&search=`) ✔
- Bulk *Change status*: the dialog offers only transitions valid for the selection, uses a two-step in-app confirm, shows "2 posts updated." ✔

**Settings:** renders, post-type list, uninstall opt-in with clear warning ✔

**Automated:** PHPUnit unit 104/104 · PHPCS clean · Jest 164/164. Integration and E2E suites were not run (they need the WP test DB / Playwright setup).

---

## 5. Test data left on the local site

Created for this session. Remove when done:

- Users: `cwmtest_editor` (528), `cwmtest_author` (529), `cwmtest_contributor` (530), `cwmtest_subscriber` (531)
- Posts: 1952, 1953, 1954, 1955, 1956 (titles start with `CWMTEST`), plus their activity rows

```
wp post delete 1952 1953 1954 1955 1956 --force
wp user delete 528 529 530 531 --yes
```

Activity rows for deleted posts are cleaned up by the plugin's `before_delete_post` handler.
