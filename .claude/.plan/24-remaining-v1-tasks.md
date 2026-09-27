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

- [ ] **Badge contrast.** Compute the contrast ratio of every status colour in the StatusManager defaults against its badge background and text colour (`src/dashboard/dashboard.scss`, sidebar styles).
  Every pair must be ≥ 4.5:1. Fix any colour that fails, and record the table in `DEVELOPMENT.md`.
- [ ] **RTL (optional).** Add a scratch mu-plugin on cwm-e2e that sets `text_direction` to `rtl`.
  Screenshot the dashboard and the sidebar, check that `dashboard-rtl.css` loads and the layout mirrors, then remove the mu-plugin.
- [ ] **i18n.** Run `npm run makepot` and confirm the `.pot` regenerates.
  Grep `src/**/*.jsx` for user-facing quoted strings that aren't wrapped in `__(` / `_n(` / `sprintf( __(`, and wrap any you find.
- [ ] **Screen-reader script.** Add a short NVDA + Chrome script to `DEVELOPMENT.md` for the developer to follow. It should cover:
  - the sidebar: status announcement, the Move buttons, the reviewer combobox, the due-date calendar, the ConfirmDialog, and the notices in the live region;
  - the dashboard: table navigation, row actions, the bulk modal, and the result notice.

## Full re-verification (after E)

- [ ] `composer lint` (phpcs): 0 errors.
- [ ] PHPUnit unit and integration suites pass. Last run: unit 104, integration 199, plus the tests added since.
- [ ] `npm run test:unit` (Jest) passes (164+). `npm run lint:js` and `npm run lint:css` are clean.
- [ ] `composer test:coverage` stays green. This confirms the `ActivityLogger` change didn't lower coverage.
- [ ] `npm run check:version` passes.
- [ ] The whole e2e suite (all specs, including probe, a11y, keyboard and ui-states) passes **twice in a row** on cwm-e2e (7.1).
- [ ] The whole e2e suite passes **once** on cwm-wp65 (6.8).

## F — Release readiness (step 23)

- [ ] Run `npm run build:zip`. Install the zip on **fresh** WP 6.8 and 7.1 sites (`wp plugin install dist/*.zip --activate`) with `WP_DEBUG` and `WP_DEBUG_LOG` on.
  Open the dashboard and the sidebar on each, confirm that `debug.log` stays empty and the console is clean, then remove the scratch sites.
- [ ] CI (`.github/workflows/ci.yml`):
  - make sure the e2e job runs every spec, including the four new ones;
  - optionally add a wp-env e2e leg on WP 6.8 so the compatibility blocker can't come back unnoticed.
- [ ] Add these to `CHANGELOG.md` `[1.0.0]`:
  - the `/statuses` route now declares a response schema;
  - the `get_for_posts()` long-history performance fix;
  - the minimum WordPress version is 6.8;
  - `composer test:coverage` and `bin/measure.php` (both dev only).
- [ ] Tick steps 19, 20, 21 and 22 in `00-INDEX.md` and replace their "open:" notes with the evidence pointers.
- [ ] Update `.claude/PLAN-SUMMARY.md` to match.
- [ ] Hand the developer a list of every changed and new file.

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
