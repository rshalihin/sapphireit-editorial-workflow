# 24 — Remaining tasks to finish Free v1.0

Status snapshot: 2026-09-27. This lists what is still open in the completion plan
(Phases A–G, `C:\Users\Hp\.claude\plans\generate-a-full-plan-synchronous-spindle.md`).
Work the sections in order. Tick a box only once its evidence is recorded.

## Constraints (unchanged)

- The AI assistant never runs `git commit`. The developer commits.
- Run PHP through Laragon PHP 8.3 by full path: `G:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe`,
  with `C:/wp-cli/wp-cli.phar` and `C:/ProgramData/ComposerSetup/bin/composer.phar`.
- Run e2e **only** against `http://127.0.0.1/cwm-e2e` (WP 7.1.2) or `http://127.0.0.1/cwm-wp65` (WP 6.8).
  Never run it against `flow-manager`: the specs delete every post and user.
- Follow CLAUDE.md: phpcs WordPress-Extra, `sit_cwm` naming, ABSPATH guards, and the security rules.
- The minimum WordPress version is now **6.8** (the developer's decision).

## Already done (for context)

- **Phase A:** the WP compatibility blocker is fixed, and `AssetCompatTest` guards it.
- **Phase B (step 19):** coverage is measured with Xdebug and `composer test:coverage` passes.
  The table in `DEVELOPMENT.md` meets every minimum (`Workflow/` 95.5 %, 100 % branch coverage, overall PHP 93.5 %, Jest 91.7 %).
  Rebuilding the assets produces no diff, and the e2e suite passed twice in a row on the rebuilt cwm-e2e.
- **Phase C (step 20):** `subscriber-probe.spec.js` passes.
  Its results are recorded in `ARCHITECTURE.md`, and the checklist in `20-security-hardening-review.md` is ticked.
- **Phase D (step 21):** `bin/measure.php` is added and the budgets and `EXPLAIN` output are recorded in `DEVELOPMENT.md`.
  The `ActivityLogger::get_for_posts()` long-history fix is in (8.3 s → 96 ms).
- **Phase E, so far:**
  - `a11y.spec.js` (axe: 0 violations), `keyboard-flow.spec.js` and `ui-states.spec.js` all pass.
  - The 782 px screenshots were reviewed. The dashboard uses the grid layout with no overflow, and the sidebar panel fits.

## E — UX / accessibility (step 22), remainder

- [x] **Badge contrast.** Compute the contrast ratio of every status colour in the StatusManager defaults against its badge background and text colour (`src/dashboard/dashboard.scss`, sidebar styles).
  Every pair must be ≥ 4.5:1. Fix any colour that fails, and record the table in `DEVELOPMENT.md`.
- [x] **RTL (optional).** Add a scratch mu-plugin on cwm-e2e that sets `text_direction` to `rtl`.
  Screenshot the dashboard and the sidebar, check that `dashboard-rtl.css` loads and the layout mirrors, then remove the mu-plugin.
- [x] **i18n.** Run `npm run makepot` and confirm the `.pot` regenerates.
  Grep `src/**/*.jsx` for user-facing quoted strings that aren't wrapped in `__(` / `_n(` / `sprintf( __(`, and wrap any you find.
- [x] **Screen-reader script.** Add a short NVDA + Chrome script to `DEVELOPMENT.md` for the developer to follow. It should cover:
  - the sidebar: status announcement, the Move buttons, the reviewer combobox, the due-date calendar, the ConfirmDialog, and the notices in the live region;
  - the dashboard: table navigation, row actions, the bulk modal, and the result notice.

## Full re-verification (after E)

- [x] `composer lint` (phpcs): 0 errors.
- [x] PHPUnit unit and integration suites pass. Last run: unit 104, integration 199, plus the tests added since.
- [x] `npm run test:unit` (Jest) passes (164+). `npm run lint:js` and `npm run lint:css` are clean.
- [x] `composer test:coverage` stays green. This confirms the `ActivityLogger` change didn't lower coverage.
- [x] `npm run check:version` passes.
- [x] The whole e2e suite (all specs, including probe, a11y, keyboard and ui-states) passes **twice in a row** on cwm-e2e (7.1).
- [x] The whole e2e suite passes **once** on cwm-wp65 (6.8).

## F — Release readiness (step 23)

- [x] Run `npm run build:zip`. Install the zip on **fresh** WP 6.8 and 7.1 sites (`wp plugin install dist/*.zip --activate`) with `WP_DEBUG` and `WP_DEBUG_LOG` on.
  Open the dashboard and the sidebar on each, confirm that `debug.log` stays empty and the console is clean, then remove the scratch sites.
- [x] CI (`.github/workflows/ci.yml`):
  - make sure the e2e job runs every spec, including the four new ones;
  - optionally add a wp-env e2e leg on WP 6.8 so the compatibility blocker can't come back unnoticed.
- [x] Add these to `CHANGELOG.md` `[1.0.0]`:
  - the `/statuses` route now declares a response schema;
  - the `get_for_posts()` long-history performance fix;
  - the minimum WordPress version is 6.8;
  - `composer test:coverage` and `bin/measure.php` (both dev only).
- [x] Tick steps 19, 20, 21 and 22 in `00-INDEX.md` and replace their "open:" notes with the evidence pointers.
- [x] Update `.claude/PLAN-SUMMARY.md` to match.
- [x] Hand the developer a list of every changed and new file.

### Evidence (2026-09-28/29)

- **Badge contrast:** Review `#f0b849` (1.80:1) → `#996800` (4.84:1) and Approved `#00a32a` (3.35:1) → `#008a20` (4.51:1).
  The badge background is fixed to `#fff` and the text to `#1e1e1e` in both stylesheets. Table: `DEVELOPMENT.md` → "Status badge contrast".
- **RTL:** checked on cwm-e2e. Both `-rtl.css` files load, the layout mirrors at 1440 and 782 px, and the console is clean. The mu-plugin was removed.
  Note: the mu-plugin must also set `wp_styles()->text_direction`, because core fixes it when `WP_Styles` is built.
- **i18n:** `npm run makepot` regenerates the `.pot` (+3 strings from the `/statuses` schema). The grep found no unwrapped strings.
- **Screen-reader script:** `DEVELOPMENT.md` → "Screen-reader script (NVDA + Chrome)".
- **Re-verification:**
  - phpcs: 74 files, 0 errors.
  - PHPUnit: unit 104 and integration 199 pass.
  - Jest: 164 pass. One expectation was updated for the new Approved colour.
  - `lint:js` and `lint:css` are clean.
  - Coverage is green. `Activity/` went up to 92.3 %.
  - `check:version` passes.
- **e2e:** the final code passed on cwm-e2e (WP 7.1.2) three times in a row (23:51, 00:04, 00:09) and on cwm-wp65 (WP 6.8) once. Two spec fixes came out of this:
  - `ui-states.spec.js` now deletes pages too. Pages are workflow-enabled by default, and a fresh install's Sample Page and Privacy Policy broke the empty state. This failed on 6.8 and would also fail in CI.
  - `utils.js` `loginAs()` waits for core's `wp_attempt_focus()` before typing. Its 200 ms focus-and-select on `#user_login` could land mid-typing and put the password into the username field. That was the cause of the "stalls" at `waitForURL`.
  - One `keyboard-flow` `toHaveText` failure happened once on cwm-e2e (23:45) and did not reproduce in 12 isolated repeats or in the three later full runs. Its artifacts were overwritten before they could be inspected.
- **Zip:** the 42-file zip installed and activated on fresh WP 6.8 and 7.1.2 sites (`WP_DEBUG_LOG` on).
  The dashboard and sidebar rendered, the console had no errors, and `debug.log` stayed empty. The sites and databases were then removed.
  Note: a fresh Laragon site needs core's `.htaccess` for `/wp-json/`, because wp-cli does not write it.
- **CI:** the e2e job is now a matrix with WP 6.8 (the `.wp-env.json` pin) and latest (`WP_ENV_CORE`). `npm run test:e2e` picks up every spec. `lint:css` was added to the JS job.

## G — Developer-only steps

- [ ] Commit the work and push `rs-implement`.
- [ ] Get CI green on every leg. The assistant can watch it read-only with `gh run watch`.
- [ ] Do the screen-reader pass using the script in `DEVELOPMENT.md`.
- [ ] Tag `v1.0.0` and push the tag so `release.yml` attaches the zip.

## Decisions / notes for the developer

- **OPcache is off** in Laragon's Apache PHP. With it off, the dashboard first-paint budget is missed on this machine (2.06 s at 20 per page, 2.60 s at 100).
  About 700 ms of that is the WordPress bootstrap: core `/wp/v2/types` alone takes about 650 ms. Enabling OPcache is the developer's decision.
  A follow-up could preload the first page of `/posts` into the dashboard.
- **Optional:** run `/security-review` over the branch before tagging.
- Xdebug (`php_xdebug.dll`) sits in Laragon's `ext/` folder but is loaded only by `-d` for coverage runs. `php.ini` is unchanged.
