# 26 — Classic editor support (workflow meta box)

## Goal

Give posts edited in the **classic editor** the same workflow controls the
block-editor sidebar has: status, reviewer, due date, transitions, comments and
activity. Show them in a meta box in the side column of `post.php` /
`post-new.php`.

Who this is for:
- Sites running the Classic Editor or Disable Gutenberg plugins.
- REST-enabled post types that still use the classic editor. WooCommerce
  **Products** are the known case (see `.claude/reports/2026-10-05-browser-qa-report.md`).

The meta box is a **React mount of the existing `WorkflowPanel`**. It is a thin
client over the existing REST API, just like the sidebar. No new PHP business
logic, REST routes, capabilities, meta or tables.

## Prerequisites

- Steps 02–23 done.
- Step 25 (rename) done or at least committed. This step touches readme.txt and
  `admin/Settings.php`, which 25 also edits.
- The developer commits; the AI never runs `git commit`.

## Frozen decisions

| # | Decision | Why |
|---|---|---|
| C1 | One React meta box that reuses `WorkflowPanel`. **No** PHP form and **no** `save_post` handler. | A PHP form would duplicate the UI. It would also tie workflow changes to the post's Update button and lose the `from`-status conflict check, which the REST path already has. |
| C2 | Workflow changes **save immediately** through REST, independent of the post's Update button. The meta box says so in one line. | This matches the sidebar, and it keeps workflow status separate from post status (CLAUDE.md). |
| C3 | Build entry `classic`, handles `sit-cwm-classic` / `sit-cwm-classic-style`, meta box id `sit-cwm-workflow`, root element `#sit-cwm-classic-root`. | Follows the `sit-cwm` naming rule. |
| C4 | Context `side`, priority `high`. | Workflow status is the first thing a reviewer looks for. The activity timeline gets a height cap (step 5). |
| C5 | Enqueue only when **all** of these are true: the screen is `post.php`/`post-new.php`, the post type is enabled (`Settings::is_post_type_enabled()`), `! use_block_editor_for_post( $post )`, and `PermissionManager::can_edit_post()`. | Never load on block-editor screens. That would duplicate the sidebar and cost bundle weight. |
| C6 | Post type eligibility stays as it is (`show_in_rest` + `editor` support, `Settings.php:195`). | The client is REST-based. Widening the list is a separate decision; developers can use `sit_cwm_available_post_types`. |
| C7 | Free scope. | Not on the Pro list in CLAUDE.md. |

## Files touched

| File | Change |
|---|---|
| `src/components/WorkflowPanel.jsx` | **new**: moved out of `src/sidebar/Sidebar.jsx` (together with `Unavailable` and `LoadError`) |
| `src/components/Snackbars.jsx` | **new**: moved out of `src/dashboard/App.jsx` |
| `src/sidebar/Sidebar.jsx` | becomes a thin `PluginSidebar` wrapper |
| `src/dashboard/App.jsx` | imports the shared `Snackbars` |
| `src/sidebar/components/CommentForm.jsx` | `<form>` → `<div>`, submits on button click / Ctrl+Enter |
| `src/classic/index.js` | **new**: entry point, mounts into the meta box |
| `src/classic/ClassicPanel.jsx` | **new**: `WorkflowPanel` + `Snackbars` + "saves immediately" hint + Enter-key guard |
| `src/classic/classic.scss` | **new**: meta box layout overrides |
| `webpack.config.js` | add the `classic` entry |
| `includes/Editor/ClassicMetaBox.php` | **new**: `Bootable` service |
| `includes/Core/Plugin.php` | register `editor.classic` + add it to the `admin` phase |
| `admin/Settings.php` | replace the "dashboard only" note (`post_type_note()`, ~line 424) |
| `readme.txt`, `README.md`, `ARCHITECTURE.md`, `CHANGELOG.md` | docs |
| `tests/php/integration/Editor/ClassicMetaBoxTest.php` | **new** |
| `tests/php/integration/PluginTest.php` | assert the new service is wired |
| `tests/php/integration/Admin/SettingsPageTest.php` | update the note assertion |
| `tests/js/CommentForm.test.jsx` (or `SidebarControls.test.jsx`) | update for the no-`<form>` markup |
| `tests/js/ClassicPanel.test.jsx` | **new** |
| `tests/e2e/classic-editor.spec.js` | **new** |
| `.wp-env.json` | add the Classic Editor plugin (tests env only, see step 9) |
| `languages/sapphireit-editorial-workflow.pot` | regenerate |
| `assets/build/*` | rebuilt |

## Tasks

### Step 1: Move `WorkflowPanel` into a shared component (JS refactor, no behaviour change)

1. Create `src/components/WorkflowPanel.jsx`. Move `Unavailable`, `LoadError`
   and `WorkflowPanel` from `src/sidebar/Sidebar.jsx` **verbatim**, and fix the
   import paths (`./components/*` → `../sidebar/components/*`).
2. Export: `export default WorkflowPanel` and `export { Unavailable }`.
3. Keep the root class `sit-cwm-sidebar` and add a `className` prop that is
   appended to it. The classic mount passes
   `sit-cwm-workflow-panel--classic`. That way, existing sidebar CSS and e2e
   selectors keep working.
4. Add a prop `isClassic = false`. Its only use is in Step 4 (the hint line), so
   don't spread editor checks through the component.
5. `src/sidebar/Sidebar.jsx` now only holds `PLUGIN_NAME`, `SIDEBAR_NAME` and
   the default `Sidebar` export, and imports `WorkflowPanel` / `Unavailable`.
6. Optional (only if the move makes it cleaner): move the four
   `src/sidebar/components/*` controls to `src/components/`. **Skip it for
   now** to keep the diff small. The imports work either way.

**Check:** `npm run build`, `npm run test:unit`, `npm run lint:js` are green,
and `tests/e2e/editorial-flow.spec.js` passes. The sidebar works exactly as
before.

### Step 2: Move `Snackbars` into a shared component

1. Move the `Snackbars` component from `src/dashboard/App.jsx` into
   `src/components/Snackbars.jsx`, keeping the `className` prop (default
   `sit-cwm-snackbars`).
2. `App.jsx` imports it.

**Check:** dashboard Jest tests and `dashboard.spec.js` stay green.

### Step 3: Make `CommentForm` safe inside another form

The classic editor wraps every meta box in `<form id="post">`. A nested
`<form>` is invalid HTML: the browser drops the inner one, so "Add comment"
would submit (and save) the whole post.

1. In `src/sidebar/components/CommentForm.jsx`, replace
   `<form className="sit-cwm-comment-form" onSubmit={ handleSubmit }>` with
   `<div className="sit-cwm-comment-form">`.
2. On the button, `type="submit"` → `type="button"` with `onClick={ handleSubmit }`.
   `handleSubmit` keeps `event?.preventDefault()`.
3. Keyboard parity: on the textarea, `onKeyDown`, **Ctrl/Cmd+Enter** calls
   `handleSubmit`. Plain Enter still makes a newline. The block editor sidebar
   gets this too, so both editors behave the same.
4. Keep `role="group"` plus `aria-label` on the wrapper, so screen readers
   still announce a grouping.

**Check:** update the JS tests that query `form` / submit by Enter
(`tests/js/SidebarControls.test.jsx`). Add a case for Ctrl+Enter. Run
`keyboard-flow.spec.js` and `a11y.spec.js`.

### Step 4: Classic entry point (`src/classic/`)

1. `src/classic/ClassicPanel.jsx`:
   - Props: `postId`, `postType`.
   - If `! isEnabledPostType( postType )`, render `<Unavailable />`. This is
     belt-and-braces, because PHP already filters (C5).
   - Otherwise render:
     ```jsx
     <div className="sit-cwm-classic" onKeyDown={ stopEnterSubmit }>
         <p className="sit-cwm-classic__hint">
             { __( 'Workflow changes are saved immediately. You don’t need to click Update.', 'sapphireit-editorial-workflow' ) }
         </p>
         <WorkflowPanel postId={ postId } isClassic className="sit-cwm-workflow-panel--classic" />
         <Snackbars className="sit-cwm-snackbars sit-cwm-snackbars--classic" />
     </div>
     ```
   - `stopEnterSubmit( event )`: if `event.key === 'Enter'` and the target is an
     `<input>` (not a textarea, button or combobox option), call
     `event.preventDefault()`. Without this, pressing Enter in the due-date
     field submits `#post`. **Do not** call `stopPropagation` on everything,
     because `ComboboxControl` needs its own Enter handling. Test the reviewer
     combobox explicitly.
2. `src/classic/index.js`:
   ```js
   import { createRoot } from '@wordpress/element';
   import domReady from '@wordpress/dom-ready';
   import ClassicPanel from './ClassicPanel';
   import './classic.scss';

   domReady( () => {
       const root = document.getElementById( 'sit-cwm-classic-root' );
       if ( ! root ) {
           return;
       }
       const postId = parseInt( root.dataset.postId, 10 );
       const postType = root.dataset.postType || '';
       if ( ! postId ) {
           return;
       }
       createRoot( root ).render(
           <ClassicPanel postId={ postId } postType={ postType } />
       );
   } );
   ```
   Add `@wordpress/dom-ready` to `package.json` dependencies if it isn't
   already there. The dependency extractor maps it to `wp-dom-ready`.
3. `src/classic/classic.scss`:
   - Remove the `PanelBody` outer borders/padding inside `.postbox` (the meta box
     already has a frame).
   - `.sit-cwm-classic .sit-cwm-activity-timeline { max-height: 320px; overflow-y: auto; }`
   - Snackbars: `position: fixed; bottom: 24px; left: 180px` (past the admin
     menu). Use a smaller left offset under `body.folded` and at
     `@media (max-width: 782px)`. Check RTL output (`classic-rtl.css` is
     generated).
   - Hint: `.description` style (`color: #646970; font-style: italic`).
4. `webpack.config.js`: add
   `classic: path.resolve( __dirname, 'src/classic/index.js' ),` and update the
   header comment ("three entry points").

**Check:** `npm run build` writes `assets/build/classic.js`,
`classic.asset.php`, `classic.css` and `classic-rtl.css`. The asset file lists
`wp-element`, `wp-components`, `wp-api-fetch`, `wp-data`, `wp-notices`,
`wp-dom-ready` and `wp-i18n`, and **not** `wp-editor` / `wp-edit-post`. That
last part confirms the shared panel has no Gutenberg-only imports.

### Step 5: `includes/Editor/ClassicMetaBox.php`

Model it on `includes/Editor/SidebarAssets.php`. ABSPATH guard, full docblocks,
`@since 1.0.0` (or the next version, see Step 10).

```php
final class ClassicMetaBox implements Bootable {

	const ENTRY       = 'classic';
	const META_BOX_ID = 'sit-cwm-workflow';
	const ROOT_ID     = 'sit-cwm-classic-root';

	public function __construct( Assets $assets, PermissionManager $permissions, Settings $settings ) { ... }

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/** @param string $post_type Post type. @param WP_Post|mixed $post Post. */
	public function add_meta_box( $post_type, $post ): void {
		if ( ! $this->applies( $post ) ) {
			return;
		}
		add_meta_box(
			self::META_BOX_ID,
			__( 'Editorial Workflow', 'sapphireit-editorial-workflow' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'high',
			array( '__back_compat_meta_box' => true ) // Never shown in the block editor's meta box area.
		);
	}

	public function render( WP_Post $post ): void {
		printf(
			'<div id="%1$s" class="sit-cwm-classic-root" data-post-id="%2$s" data-post-type="%3$s"><p class="hide-if-js">%4$s</p></div>',
			esc_attr( self::ROOT_ID ),
			esc_attr( (string) $post->ID ),
			esc_attr( $post->post_type ),
			esc_html__( 'The editorial workflow needs JavaScript.', 'sapphireit-editorial-workflow' )
		);
	}

	public function enqueue( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}
		if ( ! $this->applies( get_post() ) ) {
			return;
		}
		$this->assets->enqueue( self::ENTRY );
	}

	/** Single source of truth for C5. */
	private function applies( $post ): bool {
		return $post instanceof WP_Post
			&& $this->settings->is_post_type_enabled( $post->post_type )
			&& function_exists( 'use_block_editor_for_post' )
			&& ! use_block_editor_for_post( $post )
			&& $this->permissions->can_edit_post( $post->ID );
	}
}
```

Notes:
- **No nonce field and no `save_post` hook.** Nothing is posted with the post
  form, because all writes go through REST with the `wp_rest` nonce
  (`api-fetch`). Write that in the class docblock so a reviewer doesn't flag a
  "missing nonce".
- `get_post()` inside `admin_enqueue_scripts` on `post.php` / `post-new.php`
  returns the global `$post`. It is set by then, including the auto-draft on
  `post-new.php`. Verify this in the integration test (Step 8).
- `__back_compat_meta_box => true` makes the block editor skip this box even if
  a site forces meta boxes there, so users never see the sidebar and the box
  at once.
- `can_edit_post()` does **not** check whether the post type is enabled
  (`PermissionManager::can_on_post()` only checks `edit_post` + capability),
  which is why `Settings` is injected and checked separately. Users with no
  edit rights (e.g. subscribers) are already excluded by `can_edit_post()`.

### Step 6: Wire it in `includes/Core/Plugin.php`

1. `use Sit_Cwm\Editor\ClassicMetaBox;`
2. Register next to `editor.sidebar` (~line 357):
   ```php
   $this->container->set(
   	'editor.classic',
   	static function ( Container $c ) {
   		return new ClassicMetaBox( $c->get( 'assets' ), $c->get( 'permission_manager' ), $c->get( 'settings' ) );
   	}
   );
   ```
3. Add `'editor.classic'` to `BOOT_PHASES['admin']` after `'editor.sidebar'`.

### Step 7: Auto-draft check (post-new.php)

On `post-new.php` the post exists as `auto-draft` with an id. Things to verify
(read the code first; change only what fails):
1. `GET /sit-cwm/v1/posts/{id}/workflow` on an auto-draft returns 200 with
   the default status. The block editor sidebar already does this today, so it
   should work.
2. A transition, reviewer, due date or comment on an auto-draft succeeds, and
   the meta survives when the user then clicks **Save Draft**. WordPress keeps
   the same id when it promotes an auto-draft, so meta should survive.
3. `BulkProcessor::SKIPPED_POST_STATUSES` skips auto-drafts. That's correct
   for bulk actions and doesn't affect the meta box.

If (2) turns out to lose data, the fallback is to show a notice in
`ClassicPanel` ("Save the draft first to start the workflow") while
`post_status === 'auto-draft'`. Pass `data-post-status` from `render()` in
that case.

### Step 8: Settings screen and docs

1. `admin/Settings.php::post_type_note()`: delete the
   `use_block_editor_for_post_type()` branch ("dashboard only"). Every enabled
   type now has an editor UI. Keep the `$unsupported` branch.
2. `readme.txt`:
   - FAQ "Does it work with the classic editor?" → "Yes. In the classic editor
     the workflow controls appear in an **Editorial Workflow** box in the
     sidebar column. Changes there save immediately, independently of the
     Update button."
   - Description intro (line 18): "managed from a Gutenberg sidebar, a classic
     editor meta box and an admin dashboard".
   - Installation step 4: mention the classic-editor box.
   - Changelog: add the line.
   - Screenshots: add "6. The workflow box in the classic editor." and capture
     it (`bin/capture-screenshots.js` / `screenshots.spec.js`).
3. `README.md`, `ARCHITECTURE.md` (Editor layer: two thin clients over
   one panel), `CHANGELOG.md`.
4. `npm run makepot`.

### Step 9: Tests

**PHP integration**: `tests/php/integration/Editor/ClassicMetaBoxTest.php`,
modelled on `SidebarAssetsTest.php`:
- Classic editor forced (`add_filter( 'use_block_editor_for_post', '__return_false' )`),
  enabled type, editor user: the meta box is registered (`$wp_meta_boxes['post']['side']['high']['sit-cwm-workflow']`)
  and `sit-cwm-classic` is enqueued for `post.php`.
- The block editor is in use: no meta box, no enqueue.
- Post type not enabled: no meta box, no enqueue.
- User cannot edit the post (subscriber, or an author on someone else's post):
  no meta box, no enqueue.
- `$hook_suffix` = `edit.php` / `index.php`: no enqueue.
- Auto-draft post: meta box present, enqueue happens.
- `render()` output: `data-post-id` matches, attributes are escaped, and the
  `hide-if-js` fallback is present.
- `PluginTest.php`: `editor.classic` resolves and is in the admin phase.
- `SettingsPageTest.php`: the "dashboard only" note is gone.

**Jest**: `tests/js/ClassicPanel.test.jsx`:
- Renders the hint, the panel and the snackbars for an enabled type, and
  `Unavailable` for a disabled one.
- Enter in an `<input>` inside the panel has `defaultPrevented === true`;
  Enter in the textarea does not.
- `index.js` mount: no root → nothing; root without `data-post-id` → nothing.
- `CommentForm`: no `form` element; button click and Ctrl+Enter submit; plain
  Enter does not.

**E2E**: `tests/e2e/classic-editor.spec.js`:
- Setup: add `"https://downloads.wordpress.org/plugin/classic-editor.zip"` to
  `env.tests.plugins` in `.wp-env.json` (tests env only, so normal dev keeps the
  block editor). Activate it in `beforeAll` via `requestUtils.activatePlugin( 'classic-editor' )`
  and deactivate it in `afterAll`, so the other specs are unaffected.
- As editor: open `post-new.php` and see the box. Assign a reviewer, set a due
  date, move Draft → Writing → Review, and add a comment (with the
  Ctrl+Enter path too). Each change should show a snackbar and a timeline entry.
- **Regression guard:** after adding a comment, the URL is still `post-new.php`
  / `post.php?...` and no "Post updated" / "Draft saved" notice appeared, which
  proves the outer form was not submitted.
- Press Enter in the due-date field: the post is not submitted.
- Click **Update** after workflow changes: the workflow status is unchanged
  (separate fields).
- As author (other user's post): no box.
- Run axe over `#sit-cwm-workflow` (reuse the `a11y.spec.js` helper).

### Step 10: Release housekeeping

- Version: if 1.0.0 is not tagged yet, ship it in 1.0.0 and keep `@since 1.0.0`.
  Otherwise bump the minor version (1.1.0), use `@since 1.1.0` on the new class
  and methods, and run `npm run check:version`.
- `npm run build`, and commit `assets/build/classic*` (the build is versioned
  in this repo).
- `npm run build:zip`, then confirm `assets/build/classic.*` is in the zip and
  nothing in `.distignore` excludes it.

## Public contract

| Item | Value |
|---|---|
| PHP class | `Sit_Cwm\Editor\ClassicMetaBox` (container id `editor.classic`) |
| Hooks used | `add_meta_boxes`, `admin_enqueue_scripts` |
| Meta box id | `sit-cwm-workflow` |
| Root element | `<div id="sit-cwm-classic-root" data-post-id data-post-type>` |
| Script / style handles | `sit-cwm-classic` / `sit-cwm-classic-style` (from `Assets::script_handle()` / `style_handle()`) |
| New REST routes / caps / meta / options | **none** |
| New actions / filters | none required. Optional: `sit_cwm_classic_meta_box_context` (default `side`) and `sit_cwm_classic_meta_box_enabled`, only if a site needs it. Don't add them speculatively. |

## Acceptance criteria

- [ ] With the Classic Editor plugin active, an editor sees the **Editorial
      Workflow** box on an enabled post type, and every sidebar action works
      there.
- [ ] The box and `classic.js` **never** load in the block editor, on
      disabled post types, or for users who cannot edit the post.
- [ ] Adding a comment, or pressing Enter in any field of the box, never
      submits or saves the post.
- [ ] Clicking Update never changes the workflow status, and changing the
      workflow status never changes the post status.
- [ ] Success and error notices are visible (snackbars) in the classic editor.
- [ ] The sidebar is unchanged: all existing Jest and e2e specs pass.
- [ ] WooCommerce Product edit screen (classic): the box appears once
      `product` is enabled. Re-run the QA report's scenario.
- [ ] `composer lint` has zero errors, `composer test` is green,
      `npm run lint:js` / `lint:css` / `test:unit` / e2e are green.
- [ ] readme.txt, the Settings note and the `.pot` are updated.

## Risks and mitigations

| Risk | Mitigation |
|---|---|
| Nested form submits the post | Step 3 (no `<form>`), and an e2e regression guard |
| Enter in an input submits `#post` | `stopEnterSubmit` at the panel root, with an e2e case |
| Users expect Update to save workflow changes | Hint line (C2), plus immediate snackbars |
| Side column too narrow / timeline too long | Height-capped timeline. Users can drag the box to the main column (WP remembers this per user). |
| A plugin re-renders or moves meta boxes (some page builders) | `domReady` mount by id. If the root is missing, do nothing silently. |
| `classic.js` bundle size | It shares the WP runtime packages (externals), so only plugin code is bundled. Compare its size to `sidebar.js` in the build output. |
| Auto-draft data loss | Verify in Step 7; the fallback notice is already designed. |

## Estimate

| Step | Effort |
|---|---|
| 1–3 JS refactor + CommentForm | ~0.25 day |
| 4 Classic entry + styles | ~0.25 day |
| 5–7 PHP class, wiring, auto-draft check | ~0.25 day |
| 8 Settings + docs + screenshot | ~0.25 day |
| 9 Tests (PHP, Jest, e2e) | ~0.5 day |
| **Total** | **~1.5 days** |
