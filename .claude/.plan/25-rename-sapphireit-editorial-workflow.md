# 25 — Rename to "SapphireIT Editorial Workflow" (WordPress.org pre-review fixes)

## Goal

Get the plugin past the WordPress.org automated pre-review on the **first**
submission. The pre-review of the sibling plugin "Product Publish Guard"
(Review ID `P0TDX376766HGN`, 2026-09-28) flagged a generic name. This plugin has
the same problem, and two more that the review bot or Plugin Check would flag:

| # | Finding | Where | Risk |
|---|---|---|---|
| F1 | Generic name: "Content Workflow Manager" | plugin header, readme.txt | High (name/trademark check) |
| F2 | Text domain `sit-cwm` ≠ slug | every `__()` in PHP + JS, `load_plugin_textdomain`, `wp_set_script_translations`, `.pot` | High (Plugin Check error) |
| F3 | `Contributors: shappireit`: not the WP.org account (`sapphireit`) | readme.txt:2 | High |
| F4 | Brand misspelled as "Shappire IT" / `shappire-it` in author, URIs, LICENSE, composer/package metadata | see Phase C | Medium (they check URLs and author names) |
| F5 | Tag `editorial calendar` names a Pro-only feature that isn't built | readme.txt:3 | Low (misleading tag) |
| F6 | `register_setting()` sanitization | admin/Settings.php:181 | **No change**: a real callback already validates against an allow-list. Only answer the reviewer if the bot flags it anyway (see "Reviewer notes"). |
| F7 | Guideline 11 (dashboard hijacking) | content-workflow-manager.php:71 | **No change**: the only notice is the PHP < 7.4 one. |

## Prerequisites

- Steps 02–23 done (they are).
- Clean working tree. The developer commits; the AI never runs `git commit`.

## Frozen decisions (update `01-decisions-and-conventions.md` D1 first)

| Item | Old | New |
|---|---|---|
| Display name | Content Workflow Manager | **SapphireIT Editorial Workflow** |
| WP.org slug | `content-workflow-manager` | **`sapphireit-editorial-workflow`** |
| Text domain | `sit-cwm` | **`sapphireit-editorial-workflow`** (must equal the slug) |
| Main plugin file | `content-workflow-manager.php` | **`sapphireit-editorial-workflow.php`** |
| Zip / top-level dir in zip | `content-workflow-manager/` | `sapphireit-editorial-workflow/` |
| `.pot` file | `languages/sit-cwm.pot` | `languages/sapphireit-editorial-workflow.pot` |
| Author | Shappire IT | **SapphireIT** |
| readme Contributors | `shappireit` | **`sapphireit`** |
| Admin menu / sidebar / settings title | "Content Workflow", "Content Workflow Settings" | **"Editorial Workflow"**, **"Editorial Workflow Settings"** |
| composer package name | `shappire-it/content-workflow-manager` | `sapphireit/sapphireit-editorial-workflow` |
| npm package name | `sit-cwm` | `sapphireit-editorial-workflow` |

**Stays unchanged.** These are code prefixes. They must be unique, not equal to the slug:

- PHP functions/hooks `sit_cwm_`, namespace `Sit_Cwm\`, constants `SIT_CWM_`
- REST namespace `sit-cwm/v1`
- Asset/script handles `sit-cwm-*`, CSS classes `.sit-cwm-*`, DOM ids `#sit-cwm-*`
- DB table `{prefix}sit_cwm_activity`, meta `_sit_cwm_*`, options `sit_cwm_*`,
  capabilities `sit_cwm_*`, nonce actions `sit_cwm_*`
- Admin page slugs `sit-cwm-dashboard` / settings slug

Keeping these unchanged means no data migration: existing meta, options,
activity rows and role capabilities on dev and e2e sites keep working. The
`sit` prefix still comes from the brand initials (SapphireIT), so only the
explanation text in CLAUDE.md changes.

**Open decision for the developer: Plugin URI / GitHub.** The repo lives at
`github.com/shappire-it/content-workflow-manager`. Reviewers check plugin URLs
and treat inconsistent branding as a naming issue. Options:
1. *(Recommended)* Rename the GitHub org/repo to `sapphireit/sapphireit-editorial-workflow`.
   GitHub redirects the old URLs. Then use the new URL everywhere.
2. Keep the repo name and remove `Plugin URI` from the header and readme until
   it's renamed.
Until the developer decides, this plan writes
`https://github.com/sapphireit/sapphireit-editorial-workflow` as a
single find/replace token, so switching is one pass.

---

## Phase A: Conventions and plan docs (do first; they're the source of truth)

1. `.claude/.plan/01-decisions-and-conventions.md` D1: split the
   "Slug / text domain / asset handles" row into:
   - Slug / text domain → `sapphireit-editorial-workflow`
   - Asset handles / CSS / DOM ids → `sit-cwm` (unchanged)
   Add a dated note: "2026-09-30: renamed for WP.org naming review, see step 25."
2. `CLAUDE.md`:
   - Title/overview: "Content Workflow Manager (CWM)" → "SapphireIT Editorial
     Workflow (CWM internally)".
   - Line 15: "Shappire IT" → "SapphireIT".
   - Naming table: split the text-domain row the same way as D1.
   - Replace "`sit-cwm`" in the i18n bullet ("with text domain `sit-cwm`") with
     the new domain.
3. `CONTRIBUTING.md` lines 50, 58, 65: same split.
4. `.claude/.plan/00-INDEX.md`: add step 25 to the progress tracker.
   `.claude/PLAN-SUMMARY.md` and `.claude/pro/README.md`: title rename only.
   Leave historical steps 02–24 as they are. They record what was built at the time.

## Phase B: Main plugin file

1. `git mv content-workflow-manager.php sapphireit-editorial-workflow.php`
   (the developer can run it; the AI may use a plain file rename if preferred).
2. Header:
   ```
    * Plugin Name:       SapphireIT Editorial Workflow
    * Plugin URI:        https://github.com/sapphireit/sapphireit-editorial-workflow
    * Author:            SapphireIT
    * Text Domain:       sapphireit-editorial-workflow
   ```
   Update the docblock `@package` line only if it mentions the old name. `Sit_Cwm` stays.
3. Line ~115, the PHP-version notice: "Content Workflow Manager requires PHP…" →
   "SapphireIT Editorial Workflow requires PHP…", with the new text domain.
4. Update every reference to the old filename:
   - `tests/php/bootstrap.php:50`
   - `bin/check-version.php:70,75,76`
   - `bin/setup-e2e-site.php:191` (the activation path)
   - `ARCHITECTURE.md:671`
   - `SIT_CWM_PLUGIN_FILE` is `__FILE__`, so it needs no change.

## Phase C: Text domain swap (PHP + JS)

Mechanical replacement of the **quoted** string only:
`'sit-cwm'` → `'sapphireit-editorial-workflow'` in i18n calls.

1. PHP, 17 files (counts from the 2026-09-30 grep):
   `admin/Dashboard.php`, `admin/Settings.php`, `includes/Content/PostMeta.php`,
   `includes/REST/*.php` (7 files), `includes/Workflow/{BulkProcessor,StatusManager,WorkflowManager}.php`,
   the main plugin file.
   - `includes/Core/Plugin.php:158`: `load_plugin_textdomain( 'sapphireit-editorial-workflow', … )`
   - `includes/Core/Assets.php:167`: `wp_set_script_translations( $handle, 'sapphireit-editorial-workflow', … )`
   - **Careful:** `'sit-cwm'` might also appear as a bare string that is *not* a
     text domain (handle base, slug). Before replacing, run
     `grep -rn "'sit-cwm'" includes admin` and confirm each hit is the second
     argument of `__`/`_e`/`esc_html__`/`esc_attr__`/`_n`/`_x`/`sprintf(__(…))`
     or one of the two calls above. Leave anything else as it is.
2. JS/JSX, 29 files under `src/`: same replacement. Every hit should be an
   `@wordpress/i18n` call. Check with
   `grep -rn "'sit-cwm'" src | grep -v "__(\|_n(\|_x(\|sprintf"`, which should
   print nothing.
3. `phpcs.xml.dist:41`: `<element value="sapphireit-editorial-workflow"/>`.
4. `package.json` `makepot` script:
   `wp i18n make-pot . languages/sapphireit-editorial-workflow.pot --domain=sapphireit-editorial-workflow --exclude=…`
5. Delete `languages/sit-cwm.pot`, then regenerate with `npm run makepot` (Laragon PHP;
   see memory `laragon-php-cli`).
6. `npm run build` so `assets/build/*.js` pick up the new domain. The build output
   is committed, so the stale domain would otherwise ship.

## Phase D: User-facing names and labels

1. Admin menu, dashboard heading and settings page:
   - `admin/Dashboard.php:130,197`: "Content Workflow" → "Editorial Workflow"
   - `admin/Settings.php:134,270`: "Content Workflow Settings" → "Editorial Workflow Settings"
   - `admin/Settings.php:186`: description "Content Workflow Manager settings."
     → "SapphireIT Editorial Workflow settings."
   - Docblocks at `admin/Dashboard.php:21`, `admin/Settings.php:21`
2. Editor sidebar:
   - `src/sidebar/Sidebar.jsx:313`: title → "Editorial Workflow"
   - `src/sidebar/Sidebar.jsx:61`: "…enable it in the Editorial Workflow settings."
   - `src/dashboard/App.jsx:50`
3. `includes/Core/Container.php:77`: exception prefix → "SapphireIT Editorial Workflow:".
4. `uninstall.php:3,30`: docblocks.

## Phase E: Metadata, build and release tooling

1. `readme.txt`:
   - `=== SapphireIT Editorial Workflow ===`
   - `Contributors: sapphireit`
   - `Tags: editorial workflow, approval workflow, content review, reviewer, publishing`
     (5 max, and no `editorial calendar`)
   - Description line 15 and any other "Content Workflow Manager" mentions
   - Line 82 URL, line 86 install path `/wp-content/plugins/sapphireit-editorial-workflow/`
   - Screenshot captions: "Content Workflow" labels → "Editorial Workflow"
2. `README.md`: title (line 1), zip name (100), URLs (101, 112), copyright (309).
3. `LICENSE` lines 1 and 3; `CHANGELOG.md` line 3 and link refs 117–118. Version stays
   1.0.0 because nothing has been released yet. Add under 1.0.0: "Plugin renamed
   from Content Workflow Manager before first release."
4. `composer.json`: `name`, author `name`. Run `composer validate`.
5. `package.json`: `name`, `author`. `npm install --package-lock-only` refreshes
   the lock's root name.
6. `bin/build-zip.php:7,16,17,45`: `SIT_CWM_SLUG` → `'sapphireit-editorial-workflow'`
   (the constant name stays; only the value changes).
7. `.github/workflows/release.yml:71` `SLUG=`, `:87` pot path, `:118,124,125,135`
   zip names, and `:134` release name.
8. `.wp-env.json:14`: the mapping path → `wp-content/plugins/sapphireit-editorial-workflow/tests`.
   **Note:** wp-env mounts `.` under the *directory name*. If the local folder
   isn't renamed (Phase G), keep this mapping matched to the actual folder name.
9. `phpcs.xml.dist:2-3`: ruleset name and description.
10. `DEVELOPMENT.md`: lines 3, 14–15, 26, 256–257, 351, 702.
11. `ARCHITECTURE.md:3`, `docs/screenshots/README.md:36`.

## Phase F: Tests

1. `tests/php/integration/AssetsTest.php:37`: `BUILD_URL` uses the folder
   name. Derive it from `plugins_url()` / `SIT_CWM_PLUGIN_URL` instead of
   hard-coding, so a folder rename can't break it. `:83` → assert
   `'sapphireit-editorial-workflow'`.
2. `tests/php/integration/Admin/DashboardTest.php:120,199`: "Editorial Workflow".
3. `tests/e2e/utils.js:113,172`: sidebar button name "Editorial Workflow".
4. `tests/e2e/screenshots.spec.js:275`: heading "Editorial Workflow Settings".
5. Jest snapshots/tests that match on "Content Workflow": grep `src/**/*.test.*`
   and `tests/js` and update them.
6. Add a regression test in `tests/php/integration/PluginTest.php`: the main
   plugin file's `Text Domain` header (via `get_file_data()`) equals the
   directory slug used by `bin/build-zip.php`, so the two can't drift apart again.

## Phase G: Local folder and environment (developer, optional, do last)

The zip's top-level directory comes from `SIT_CWM_SLUG`, so **WP.org gets the
right slug even if the local folder keeps its old name.** Renaming the local
folder is cosmetic, but it has side effects:

- Claude Code's project memory and history are keyed by the folder path
  (`…plugins-content-workflow-manager`). After a rename, copy the `memory/`
  folder to the new project key or they'll be lost.
- Directory junctions in `G:\laragon\www\cwm-e2e` and `cwm-wp65` point at the old
  path. Remove them with `cmd /c rmdir` (never delete the target) and recreate
  them with `New-Item -ItemType Junction`.
- After the main-file rename (Phase B), WP sees a "different" plugin on
  flow-manager, cwm-e2e and cwm-wp65. It will show as deactivated, so reactivate it
  (`wp plugin activate sapphireit-editorial-workflow` or the old folder
  name). Data is kept because option, meta and table keys are unchanged.
  Activation reruns and is idempotent.

Recommendation: do Phases A–F now, submit, and rename the folder after approval.

## Verification (all must pass before submitting)

1. Leftover scan. Each of these should print nothing, except the listed allowed hits:
   ```
   grep -rIn "Content Workflow Manager\|content-workflow-manager\|Shappire\|shappire" \
     --exclude-dir={node_modules,vendor,coverage,dist,artifacts,.git,.claude} .
   ```
   Allowed hits: the CHANGELOG "renamed from" line, and local paths in
   DEVELOPMENT.md if Phase G is deferred.
   ```
   grep -rIn "'sit-cwm'" --include=*.php --include=*.js --include=*.jsx admin includes src *.php
   ```
   This should print nothing: no i18n call left on the old domain, and no stale domain in the build.
   Also run `grep -c "sit-cwm\"" assets/build/*.js`. Only handle/CSS-class hits
   should remain, not `__()` domains.
2. `composer lint` (phpcs, zero errors, with the new text_domain property, so any
   missed PHP string fails here).
3. `composer test` (unit + integration), `npm run lint:js`, `npm test` (Jest).
4. `npm run test:e2e:local -- --base-url=http://127.0.0.1/cwm-e2e`.
5. `php bin/check-version.php`.
6. `npm run build:zip`, then unzip it and confirm the top-level dir is
   `sapphireit-editorial-workflow/`, the main file is
   `sapphireit-editorial-workflow.php`, and `languages/sapphireit-editorial-workflow.pot` exists.
7. **Plugin Check** on a scratch site (cwm-wp65), against the *zip contents*, not
   the dev folder:
   `wp plugin install plugin-check --activate` then
   `wp plugin check sapphireit-editorial-workflow`. Expect zero errors,
   particularly no `textdomain_mismatch` and no `trademarked_term`.
8. Manual check: activate on a clean site. The menu reads "Editorial Workflow",
   the sidebar title matches, settings save, and the Plugins screen shows
   "SapphireIT Editorial Workflow" by "SapphireIT".
9. Recapture screenshots with `bin/capture-screenshots.js`, because the menu and
   sidebar labels changed and reviewers look at graphics too.
10. Name check: search wordpress.org/plugins, Google and GitHub for "SapphireIT
    Editorial Workflow" and "Editorial Workflow" to make sure the name is
    distinctive. The branded prefix is what makes it unique.

## Submission notes

- WP.org derives the slug from the `Plugin Name` header on first upload, which
  gives `sapphireit-editorial-workflow`. Confirm it on the upload screen before
  submitting. It **cannot** be changed after approval.
- Submit from the `sapphireit` account (it must match `Contributors`).

### Reviewer notes (use only if the bot raises these)

- register_setting: "The option is registered with a `sanitize_callback`
  (`Settings::sanitize()`, admin/Settings.php) that rebuilds the value from
  defaults, keeps only post types from the allow-list shown on the settings screen, and
  casts the uninstall flag to a boolean."
- Keep any reply short, with no change list, as the review email asks.

## Acceptance criteria

- [ ] D1 in `01-decisions-and-conventions.md`, CLAUDE.md and CONTRIBUTING.md updated
- [ ] Main file renamed; header shows new name, author, text domain and URI
- [ ] Zero i18n calls on `sit-cwm`; phpcs `text_domain` = new slug
- [ ] New `.pot` generated, old removed; `assets/build` rebuilt
- [ ] readme.txt: name, `Contributors: sapphireit`, tags without "editorial calendar"
- [ ] No "Shappire"/"shappire" anywhere shipped
- [ ] Zip top dir, release.yml and build-zip use the new slug
- [ ] All suites green, including e2e and the new text-domain/slug regression test
- [ ] Plugin Check: zero errors on the built zip
- [ ] Screenshots recaptured
- [ ] Developer decided the GitHub URI question
