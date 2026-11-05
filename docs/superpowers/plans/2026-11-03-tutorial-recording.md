# Tutorial Recording (Playwright + Written Guide) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One command (`npm run tutorial`) that drives a real WordPress through the plugin's main flows and writes a captioned tutorial video per chapter and the screenshots for a step-by-step written guide (`docs/tutorial/GUIDE.md`).

**Architecture:** This is a generator spec, like `tests/e2e/screenshots.spec.js`: `tests/e2e/tutorial.spec.js` runs only when `CWM_TUTORIAL` is set, and `bin/record-tutorial.js` sets it and points Playwright at a local scratch site (copied from `bin/capture-screenshots.js`). Each chapter is one `test()` with Playwright video recording on. An injected overlay draws the mouse cursor, click ripples and caption banners, because headless video shows none of these. Stills for the guide come from the same run, so the video and the guide always match.

**Tech Stack:** `@playwright/test` 1.63 via `@wordpress/scripts` `test-playwright`, `@wordpress/e2e-test-utils-playwright` fixtures, existing helpers in `tests/e2e/utils.js`, and Clipchamp (bundled with Windows 11) for optional voiceover and MP4 export.

**Spec:** This conversation (2026-11-03): "Playwright + a written guide". There is no separate spec doc. **Recording date:** not set yet (the original 2026-11-04 slot passed before implementation). Record once Tasks 1–5 are done.

## Global Constraints

- Naming: asset, CSS and DOM ids use `sit-cwm-` (overlay elements are `#sit-cwm-tutorial-cursor` and `#sit-cwm-tutorial-caption`); env flag is `CWM_TUTORIAL` (matches `CWM_CAPTURE`).
- JS follows `@wordpress/scripts` ESLint: `npm run lint:js` must report zero errors on every new file.
- The spec is **destructive** (`deleteAllPosts()`), so it is run only against the scratch site `http://127.0.0.1/cwm-e2e` and never `flow-manager.test`.
- Viewport and video size are both **1280×720** (16:9, text readable on YouTube and at phone size). Stills use the same viewport.
- `slowMo: 120` ms plus explicit caption holds. A viewer must be able to read every caption (hold time is at least `60 ms × words`, minimum 2500 ms).
- No plugin PHP or `src/` changes. The tutorial only *uses* the UI. If a flow cannot be driven, record that and do not patch the plugin.
- Videos are **not committed** (`docs/tutorial/video/` is gitignored). Stills and `GUIDE.md` are committed.
- Persona names (fixed, used in captions and guide): **Sam Rivera** (author, `sam`), **Dana Okafor** (reviewer, editor role, `dana`), **Alex Chen** (managing editor, editor role, `alex`). Admin is `admin`.
- Copy in captions and the guide refers to the product as "SapphireIT Editorial Workflow" and to statuses exactly as the UI labels them: Draft, Writing, Review, Needs Changes, Approved, Published.
- **The cursor glides, it never jumps.** Playwright moves the mouse straight to each target, so the overlay dot would teleport. Every on-camera click in the tutorial spec goes through `clickVisibly()` (or `moveToVisibly()` for workflow moves), which moves the real mouse in small steps first. Clicks made inside shared `utils.js` helpers (for example the confirm button in `moveTo()`) are smoothed by a CSS `transition` on the dot. Do not call `locator.click()` directly in `tutorial.spec.js`.
- **Mail never leaves the machine.** Chapter 1 turns every notification on, so later chapters send mail. The scratch site's helper mu-plugin (`tests/e2e/fixtures/sit-cwm-e2e-helpers.php`, installed by `bin/setup-e2e-site.php`) captures `wp_mail()` through `pre_wp_mail`. `seedTutorialSite()` calls `clearMails()` first, which fails fast if the mu-plugin is missing.

## Review Focus

1. **Video lost or empty because the page closed too late or too early.** Expect `docs/tutorial/video/<slug>.webm` to exist and be larger than 100 KB after each chapter. Pinned in Task 1, Step 4.
2. **Overlay disappears after navigation or a `loginAs()` switch.** The cursor and caption must survive `page.goto`, because the overlay is installed with `addInitScript` and re-created on every document. Pinned in Task 1 (`overlay.spec` check after a reload).
3. **Post-lock dialog mid-video when switching users.** `loginAs()` already releases the lock and `openWorkflowSidebar()` takes over. A lock dialog visible in a still is a failure. Pinned by `settled()`-style waits before every `still()` in Tasks 2–4.
4. **Due-date picker lands on the wrong month** when today + 7 days crosses a month boundary. `pickDueDate()` must click "next month" first. Pinned in Task 2 by asserting the toggle's label afterwards.
5. **Leftover settings from an earlier spec run** (gate on Enforce, attestation on) change what chapters show. `beforeAll` resets settings explicitly and `afterAll` restores defaults. Pinned in Task 1's seed.
6. **Cursor teleports between clicks.** Watch the smoke video: the dot must travel to the button before the click ring appears. Pinned in Task 1, Step 4.
7. **Chapter 3 re-recorded on its own starts from nothing.** When chapter 2 did not run in the same invocation, `ensureChapterThreeStart()` builds the same starting point off camera. Pinned in Task 3, Step 2 (run chapter 3 alone).

---

## File Structure

| File | Responsibility |
|---|---|
| `bin/record-tutorial.js` (create) | Runner: sets `CWM_TUTORIAL=1`, `WP_BASE_URL` (default `http://127.0.0.1/cwm-e2e/`), user/pass. Creates `docs/tutorial/{video,images}`. Spawns `wp-scripts test-playwright tests/e2e/tutorial.spec.js` and passes extra args through (for example `--grep "Chapter 2"`). |
| `tests/e2e/tutorial/overlay.js` (create) | `installOverlay( page )`, `caption( page, text, opts )`, `clearCaption( page )`, `glide( page, locator, opts )`, `clickVisibly( page, locator )` |
| `tests/e2e/tutorial/recorder.js` (create) | `still( page, name, locator? )`, `saveChapterVideo( page, slug )`, `pickDueDate( page, panel, daysAhead )`, `moveToVisibly( page, panel, label, opts )` |
| `tests/e2e/tutorial/seed.js` (create) | `seedTutorialSite( requestUtils )` → personas, background posts, reset settings; `applySetupSettings( requestUtils )`; `ensureChapterThreeStart( requestUtils, users )` |
| `tests/e2e/tutorial.spec.js` (create) | The chapters, one `test()` each, skipped unless `CWM_TUTORIAL` |
| `docs/tutorial/GUIDE.md` (create) | The written guide, one section per chapter, images from `docs/tutorial/images/` |
| `docs/tutorial/README.md` (create) | How to regenerate, and post-production steps |
| `package.json` (modify `scripts`) | `"tutorial": "node bin/record-tutorial.js"` |
| `.gitignore` (modify) | `/docs/tutorial/video/` |
| `DEVELOPMENT.md` (modify, command table near line 55) | Row: `npm run tutorial` → regenerate tutorial videos + guide stills (**destructive**) |

## Chapters (shared reference for Tasks 2–4 and the guide)

| # | Slug | Who | What the viewer sees | Stills |
|---|---|---|---|---|
| 1 | `01-setup` | admin | Getting started page → Settings tabs **Workflow** (enabled types, tick **Prevent people from approving their own content**), **Publish gate** (Posts → Enforce, Save), **Email notifications** (tick all, Save) | `01-getting-started`, `02-settings-workflow`, `03-settings-publish-gate`, `04-settings-notifications` |
| 2 | `02-write-and-submit` | Sam → Alex → Sam | Sam creates "How we plan our editorial quarter" in the block editor (types title + one paragraph), opens Editorial Workflow sidebar, **Move to Writing**. Alex assigns **Reviewer** Dana and a **Due date** via the picker. Sam **Move to Review**. | `05-sidebar-draft`, `06-reviewer-and-due-date`, `07-submitted-for-review` |
| 3 | `03-review-and-publish` | Dana → Sam → Dana → Alex | Dana sees the "Publishing is locked" notice, **Move to Needs Changes** (confirm), adds comment "Please add a source for the Q2 numbers." Sam **Move to Writing** → **Move to Review**. Dana **Move to Approved** (confirm). Alex clicks WordPress **Publish** (gate now allows it), then **Move to Published** (confirm). Caption explains that workflow status and WordPress status are separate. | `08-locked-notice`, `09-needs-changes-comment`, `10-approved`, `11-published-timeline` |
| 4 | `04-dashboard-and-report` | admin | Dashboard: summary tiles, sort by **Due** (ascending, all five posts visibly reorder), filter **Status = Review** (two rows), search "launch" (one row), open it. Governance report page. | `12-dashboard`, `13-dashboard-filtered`, `14-governance-report` |
| 5 | `05-ai-use-and-attestation` | admin → Dana | Settings **Review attestation** on. Dana opens "Migrating to block themes", sets **AI use** = AI-assisted, ticks the confirmation checkbox, **Move to Approved**. | `15-ai-use`, `16-attestation` |

---

### Task 1: Runner, overlay, recorder, seed, and a smoke chapter

**Files:**
- Create: `bin/record-tutorial.js`, `tests/e2e/tutorial/overlay.js`, `tests/e2e/tutorial/recorder.js`, `tests/e2e/tutorial/seed.js`, `tests/e2e/tutorial.spec.js`
- Modify: `package.json` (scripts), `.gitignore`

**Interfaces:**
- Consumes: `loginAs`, `openWorkflowSidebar`, `setSettings`, `allNotifications`, `clearMails`, `walkWorkflow`, `moveTo`, `ADMIN` from `tests/e2e/utils.js`.
- Produces:
  - `installOverlay( page: Page ): Promise<void>`. Uses `page.addInitScript` to add, on every document, a 20 px semi-transparent dot `#sit-cwm-tutorial-cursor` that follows `mousemove`, a 400 ms ring on `mousedown`, and an empty bottom-centre `#sit-cwm-tutorial-caption` (dark pill, 22 px white text, `pointer-events: none`, `z-index: 2147483647`). The dot is positioned with `transform: translate(…)` and has `transition: transform 250ms ease-out`, so a jump made by a shared helper's plain `click()` still reads as movement. It reads the current caption from `sessionStorage['sit-cwm-tutorial-caption']` so the caption survives navigation. It also keeps the last mouse position in `sessionStorage['sit-cwm-tutorial-mouse']` and puts the dot there on a new document, so the dot does not restart at the top-left corner after `page.goto`.
  - `glide( page: Page, locator: Locator, { steps?: number } = {} ): Promise<void>`. Scrolls the locator into view, reads its bounding box, and calls `page.mouse.move( centreX, centreY, { steps: steps ?? 25 } )`, so the dot travels from wherever it is now. Playwright remembers the mouse position per page, so no bookkeeping is needed between calls.
  - `clickVisibly( page: Page, locator: Locator ): Promise<void>`. `glide()`, a 150 ms pause, then `locator.click()`. Every on-camera click in the spec uses this.
  - `caption( page: Page, text: string, { hold?: number } = {} ): Promise<void>`. Writes text to sessionStorage and the element, then waits `hold ?? Math.max( 2500, 60 * wordCount )` ms.
  - `clearCaption( page: Page ): Promise<void>`
  - `still( page: Page, name: string, locator?: Locator ): Promise<void>`. Hides the cursor and caption, saves `docs/tutorial/images/<name>.png` (element screenshot when `locator` is given, otherwise the viewport), then shows them again.
  - `saveChapterVideo( page: Page, slug: string ): Promise<void>`. Runs `const v = page.video(); await page.close(); await v.saveAs( docs/tutorial/video/<slug>.webm )`.
  - `pickDueDate( page: Page, panel: Locator, daysAhead: number ): Promise<string>`. Clicks the `Change due date:` toggle, clicks "next month" in the picker if the target date is in the next month, clicks the target day, and returns the expected `formatDate` text. All three clicks go through `clickVisibly()`.
  - `moveToVisibly( page: Page, panel: Locator, label: string, opts ): Promise<void>`. `glide()` to the panel's `Move to <label>` button, a 150 ms pause, then `moveTo( page, panel, label, opts )` from `utils.js`. The confirm-dialog click inside `moveTo()` is smoothed by the dot's CSS transition.
  - `seedTutorialSite( requestUtils ): Promise<{ users: { sam, dana, alex }, ids: Object<string, number> }>`. Calls `clearMails( requestUtils )` first. If that request fails, it throws `"Mail capture mu-plugin missing: run php bin/setup-e2e-site.php --path=G:/laragon/www/cwm-e2e"`. Then it runs `deleteAllPosts()`, creates the three personas (with display names, `.catch` returns the existing user as in `screenshots.spec.js`), and resets settings (`publish_gate: {}`, `notifications: allNotifications( false )`, `require_attestation: false`, `prevent_self_approval: false`). Last, it seeds four background posts for the dashboard, each with a due date relative to today (a local `daysAhead( n )` helper returns `Y-m-d`). They are seeded in an order that does **not** match their due order, so sorting visibly reorders the table. The posts:
    - "Q3 launch announcement": Review, due +4.
    - "Migrating to block themes": Review, reviewer Dana, due +6.
    - "Accessibility audit findings": Needs Changes, due +2.
    - "Release notes 4.2": Writing, due +12.

    Use the `ROUTES` walk and the `due_date` / `reviewer_id` POST from `screenshots.spec.js`.
  - `applySetupSettings( requestUtils ): Promise<void>`. The end state of chapter 1, applied through `setSettings()` off camera: `publish_gate: { post: 'enforce' }`, `notifications: allNotifications( true )`, `prevent_self_approval: true`.
  - `ensureChapterThreeStart( requestUtils, users ): Promise<number>`. Chapter 3's starting point for a re-record of that chapter alone. It calls `applySetupSettings()` and creates "How we plan our editorial quarter" (`author: users.sam.id`, content "This quarter we focus on three launches."). It sets reviewer Dana and due date +7, walks the post `draft → writing → review` with `walkWorkflow()`, and returns its id.
  - Spec top level: `test.use( { viewport: { width: 1280, height: 720 }, video: { mode: 'on', size: { width: 1280, height: 720 } }, launchOptions: { slowMo: 120 } } )`. `launchOptions` is worker-scoped, so it must be top level and not inside `describe`. Also `test.describe.configure( { mode: 'serial' } )`, `test.setTimeout( 10 * 60 * 1000 )`, a `beforeEach` that calls `installOverlay( page )`, and an `afterEach( ( { page }, testInfo ) => saveChapterVideo( page, slugFromTitle( testInfo.title ) ) )`, where the title starts with the slug, for example `'01-setup: …'`.

- [ ] **Step 1: Write the smoke chapter** `test( '00-smoke: overlay survives navigation', … )`. It goes to `/wp-admin/`, calls `caption( page, 'Smoke test' )`, reloads, and asserts that `#sit-cwm-tutorial-caption` has the text `Smoke test` and that `#sit-cwm-tutorial-cursor` is attached. It then calls `clickVisibly()` on the admin menu's **Posts** link and `still( page, '00-smoke' )`.
- [ ] **Step 2: Run it before the helpers exist**
  Run: `npm run tutorial -- --grep 00-smoke`
  Expected: FAIL (`Cannot find module './tutorial/overlay'` or the runner is missing).
- [ ] **Step 3: Implement** the runner, `overlay.js`, `recorder.js` and `seed.js` with the signatures above. Add `"tutorial"` to `package.json` and `/docs/tutorial/video/` to `.gitignore`.
- [ ] **Step 4: Run and verify**
  Run: `npm run tutorial -- --grep 00-smoke`
  Expected: PASS. `docs/tutorial/images/00-smoke.png` exists with no cursor or caption in it. `docs/tutorial/video/00-smoke.webm` exists and is over 100 KB (`ls -l docs/tutorial/video`). Open the webm in Chrome: the dot cursor and caption are visible, and the dot travels to **Posts** before the click ring shows, with no jump.
- [ ] **Step 5: Lint**
  Run: `npx wp-scripts lint-js bin/record-tutorial.js tests/e2e/tutorial tests/e2e/tutorial.spec.js`
  Expected: no errors.
- [ ] **Step 6: Delete the smoke test** and its two output files, then commit.
  ```bash
  git add bin/record-tutorial.js tests/e2e/tutorial tests/e2e/tutorial.spec.js package.json .gitignore
  git commit -m "test(tutorial): Playwright tutorial recorder with cursor and caption overlay"
  ```

### Task 2: Chapters 1 and 2 (setup, write and submit)

**Files:**
- Modify: `tests/e2e/tutorial.spec.js`

**Interfaces:**
- Consumes: everything Task 1 produces, plus `statusBadge( panel )` from `utils.js`. Workflow moves use `moveToVisibly()`, never `moveTo()` directly.
- Produces: post id of "How we plan our editorial quarter", kept in a describe-scoped `let tutorialPostId` for Task 3 (serial mode makes this safe). Task 3 falls back to `ensureChapterThreeStart()` when it is unset.

- [ ] **Step 1: Write chapter `01-setup`** as in the Chapters table. Settings tabs are reached with `page.getByRole( 'tab', { name: 'Workflow' | 'Publish gate' | 'Email notifications' } )`. Before clicking Save, every control is changed with `clickVisibly()` and not `setSettings()`, so the viewer sees it. On the Workflow tab, that includes ticking the checkbox "Prevent people from approving their own content". Captions, in order:
  1. "SapphireIT Editorial Workflow adds an approval step before anything goes live."
  2. "Choose which content types use the workflow, and stop people approving their own work."
  3. "The publish gate: on Enforce, nothing is published until it is approved."
  4. "Email the right person at each step."
  Each tab gets its `still()` after its caption.
- [ ] **Step 2: Write chapter `02-write-and-submit`.** Sam goes to `/wp-admin/post-new.php`, closes the welcome guide if shown, types the title and the paragraph "This quarter we focus on three launches." with `page.keyboard.type( text, { delay: 35 } )`, and saves the draft. `openWorkflowSidebar( page, id )` comes next (read the id from `wp.data.select( 'core/editor' ).getCurrentPostId()`), then `moveToVisibly( …, 'Writing' )`. Alex: `clickVisibly()` on the `Reviewer` combobox, `fill( 'Dana' )`, `clickVisibly()` on option `Dana Okafor`, then `pickDueDate( page, panel, 7 )`, and assert the toggle label contains the returned text. Sam: `moveToVisibly( …, 'Review' )`. Stills come from the Chapters table, with the panel locator.
- [ ] **Step 3: Run**
  Run: `npm run tutorial -- --grep "01-setup|02-write"`
  Expected: 2 passed, plus `01-setup.webm`, `02-write-and-submit.webm` and stills 01–07. Watch both videos end to end. No lock dialog, welcome guide or spinner should appear in any still.
- [ ] **Step 4: Commit** with `git commit -m "test(tutorial): setup and write-and-submit chapters"`.

### Task 3: Chapter 3 (review and publish)

**Files:**
- Modify: `tests/e2e/tutorial.spec.js`

**Interfaces:**
- Consumes: `tutorialPostId` from Task 2 when chapter 2 ran in the same invocation. Otherwise the chapter starts with `tutorialPostId ??= await ensureChapterThreeStart( requestUtils, users )`, before the chapter's first caption (`users` comes from the describe-scoped result of `seedTutorialSite()`). That way the chapter can be re-recorded on its own. The only difference is that the timeline lacks chapter 2's on-camera history, so the **final** recording should still be a full run.

- [ ] **Step 1: Write chapter `03-review-and-publish`** as in the Chapters table. All clicks use `clickVisibly()` and all moves use `moveToVisibly()`. Before the first `still()`, assert that `panel.locator( '.sit-cwm-gate-notice' )` contains "Publishing is locked until this content is approved." For the comment, use the textbox `Add a workflow comment` and the button `Add comment`, then assert the comment text is visible. After Dana approves, Alex clicks the editor's `Publish` button, then the confirm `Publish` in the pre-publish panel, waits for "is now live", then runs `moveToVisibly( …, 'Published', { confirm: true } )`. The caption at that point reads: "Publishing the post and closing the workflow are separate steps: WordPress status and workflow status are tracked independently." The last still is the activity timeline (`panel.locator( '.sit-cwm-activity' )`) after `aria-busy="false"`.
- [ ] **Step 2: Run**
  Run: `npm run tutorial -- --grep "02-write|03-review"`
  Expected: 2 passed, plus `03-review-and-publish.webm` and stills 08–11. The timeline still shows the needs-changes comment and the approval.
- [ ] **Step 3: Run chapter 3 alone**
  Run: `npm run tutorial -- --grep 03-review`
  Expected: 1 passed, because `ensureChapterThreeStart()` built the starting point. The "Publishing is locked" notice is visible in still 08, which proves `applySetupSettings()` turned the gate on.
- [ ] **Step 4: Commit** with `git commit -m "test(tutorial): review and publish chapter"`.

### Task 4: Chapters 4 and 5 (dashboard and report, AI use and attestation)

**Files:**
- Modify: `tests/e2e/tutorial.spec.js`

- [ ] **Step 1: Write chapter `04-dashboard-and-report`.** `admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' )`. Before the first still, assert the seeded titles are visible. The order is chosen so every step changes what is on screen:
  1. Open the **Due** column header menu and choose **Sort ascending**. Assert the first row is "Accessibility audit findings" (due +2), then take `still( '12-dashboard' )` with all five posts.
  2. Apply the DataViews filter **Status** → **Review**. Assert exactly two rows ("Q3 launch announcement", "Migrating to block themes"), then take `still( '13-dashboard-filtered' )`.
  3. Search "launch". Assert one row, then open it.

  All clicks use `clickVisibly()`. Then go to `page=sit-cwm-report` and assert that `.sit-cwm-report__headline-value` is not `—` before its still.
- [ ] **Step 2: Write chapter `05-ai-use-and-attestation`.** Admin turns on Settings tab `Review attestation` with a real click and saves. Dana opens "Migrating to block themes", selects the `AI use` combobox option for AI-assisted, ticks the checkbox "I confirm I have reviewed this content and approve it for publication.", and runs `moveToVisibly( …, 'Approved', { confirm: true } )`. Dana is not the author (the seed creates the post as admin), so the self-approval setting from chapter 1 does not block her. `afterAll` restores defaults with the same reset `seedTutorialSite` uses.
- [ ] **Step 3: Full run**
  Run: `npm run tutorial`
  Expected: 5 passed, 5 webm files and stills 01–16. Run it a **second time** and expect the same result, which proves the seed is idempotent.
- [ ] **Step 4: Commit** with `git commit -m "test(tutorial): dashboard, report and AI-use chapters"`.

### Task 5: Written guide and docs

**Files:**
- Create: `docs/tutorial/GUIDE.md`, `docs/tutorial/README.md`
- Modify: `DEVELOPMENT.md` (command table)

- [ ] **Step 1: Write `GUIDE.md`.** It has one `##` section per chapter (titles: "1. Set up the workflow", "2. Write and submit for review", "3. Review, approve and publish", "4. Track work on the dashboard", "5. Declare AI use and confirm review"). Each section has numbered steps that match the video, and every still is embedded with alt text, for example `![The Publish gate tab set to Enforce for Posts](images/03-settings-publish-gate.png)`. Add a "Who can do what" table (author / reviewer / editor / admin rows, using the capability names from `WORKFLOW.md`) and a "Status at a glance" line: Draft → Writing → Review → Needs Changes → Approved → Published.
- [ ] **Step 2: Write `README.md`.** Cover regenerating (`npm run tutorial`, `--grep` for one chapter, scratch site only), the note that chapter 3 can run alone but its timeline is fuller in a full run, the mail-capture mu-plugin requirement, the output locations, and post-production steps (copied from the checklist below).
- [ ] **Step 3: Add the `npm run tutorial` row** to `DEVELOPMENT.md`.
- [ ] **Step 4: Verify** that every `images/*.png` referenced in `GUIDE.md` exists:
  `grep -o 'images/[^)]*' docs/tutorial/GUIDE.md | sort -u | while read f; do test -f docs/tutorial/$f || echo MISSING $f; done`
  Expected: no output.
- [ ] **Step 5: Commit** with `git commit -m "docs(tutorial): written guide and regeneration notes"`.

---

## Recording-day checklist (date to be set once Tasks 1–5 are done)

1. **Scratch site up:** Laragon running, `http://127.0.0.1/cwm-e2e/wp-admin/` loads. If it doesn't exist yet:
   `php bin/setup-e2e-site.php --install --path=G:/laragon/www/cwm-e2e --url=http://127.0.0.1/cwm-e2e --db=cwm_e2e`
   (use Laragon PHP 8.x with mysqli). Then run `php bin/setup-e2e-site.php --path=G:/laragon/www/cwm-e2e`, which also installs the mail-capture mu-plugin. Without it, the seed stops with a clear error and no mail is sent.
2. **Fresh build:** `npm run build`, so the recorded UI is the current branch (`tier-1.2.0`).
3. **Record:** `npm run tutorial`. Use `-- --headed` to watch it live. To redo one chapter: `npm run tutorial -- --grep 04-dashboard`. Any chapter, including 3, can run alone. For the final cut, do one full run so chapter 3's timeline shows the whole story.
4. **Review:** watch each `docs/tutorial/video/*.webm` in Chrome. If a caption is too fast, raise its `hold` and re-run only that chapter.
5. **Post-production (optional, Clipchamp):** import the five webm files, add a title card, record a voiceover per chapter (*Record & create → Audio*), and export **1080p MP4**. The 720p source upscales cleanly because the captions are already burned in.
6. **Publish:** upload to YouTube or your product site (sapphireitlabs.com/editorial-workflow), and paste `GUIDE.md` (or render it) on the docs page with the images.
