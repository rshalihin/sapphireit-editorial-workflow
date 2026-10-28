# 15: E2E suite, acceptance matrix, screenshots

**Goal:** prove the roadmap §3.1 acceptance criteria in a real browser against real WordPress (6.8 and latest, as the CI matrix already runs). Capture the new listing screenshots, then run the full release-readiness check. **This is the only phase that writes or runs Playwright tests.**

**Prerequisites:** 02–14 (including 11a) all ticked. **Roadmap:** §3.1 A1–A5, B, C, D, plus E16. **Decisions:** all.

**Files:**
- Create: `tests/e2e/fixtures/sit-cwm-e2e-helpers.php`: a test-only mu-plugin, **never shipped**; `tests/` is already excluded from the zip by `bin/build-zip.php`.
- Modify: `.wp-env.json` (`env.tests.mappings`: map the helper into `wp-content/mu-plugins/sit-cwm-e2e-helpers.php`)
- Modify: `bin/setup-e2e-site.php` (local runs: copy or link the same file into the target site's `wp-content/mu-plugins/`)
- Modify: `tests/e2e/utils.js` (helpers below)
- Create: `tests/e2e/publish-gate.spec.js`
- Create: `tests/e2e/publish-gate-classic.spec.js`
- Create: `tests/e2e/publication-log.spec.js`
- Create: `tests/e2e/notifications.spec.js`
- Create: `tests/e2e/settings-gate.spec.js`
- Create: `tests/e2e/cli-matrix.spec.js`
- Create: `tests/e2e/self-approval.spec.js`
- Modify: `tests/e2e/a11y.spec.js` (settings page sections, gate notice)
- Modify: `tests/e2e/screenshots.spec.js` (new shot list, see 14 Step 5)
- Replace: `docs/screenshots/*` (via `npm run screenshots`)

**Interfaces:**
- Consumes: everything. Selectors use roles and the E14 strings only, never CSS internals, except `.sit-cwm-gate-notice` and the existing `sit-cwm-*` hooks.
- Produces: test-only REST routes under `sit-cwm-e2e/v1`, loaded only when the helper mu-plugin is present. Each route checks `current_user_can( 'manage_options' )`:
  - `GET /mails`: the captured mails `[ { to, subject, body } ]`;
  - `DELETE /mails`: clear them;
  - `POST /go-live/(?P<id>\d+)`: move the post's date to the past, then `do_action( 'publish_future_post', $id )`, which is exactly what cron does;
  - `POST /raw-publish/(?P<id>\d+)`: `wp_publish_post( $id )` (backstop path);
  - `POST /settings`: `( new \Sit_Cwm\Core\Settings() )->update( $request->get_json_params() )`, to arrange gate modes and toggles fast.
- Mail capture: `pre_wp_mail` returns `true` after appending to option `sit_cwm_e2e_mails`, so nothing leaves the container.
- New `utils.js` helpers:
  - `setSettings( requestUtils, partial )`
  - `getMails( requestUtils )`, `clearMails( requestUtils )`
  - `goLive( requestUtils, id )`, `rawPublish( requestUtils, id )`
  - `getActivityActions( requestUtils, id )`: returns `action` slugs, oldest first, from `GET /sit-cwm/v1/posts/<id>/activity`
  - `setWorkflowStatus( requestUtils, id, from, to )`: through the plugin's own REST route as admin, never meta

---

- [ ] **Step 1: Build the helper mu-plugin and wire it in**

  - The PHP file follows the plugin's coding standards: ABSPATH guard, docblocks, `sit_cwm_e2e_` prefixes, and a `permission_callback` on every route. Put a header comment: "Test fixture. Loaded only by wp-env tests / bin/setup-e2e-site.php."
  - Add the mapping to `.wp-env.json`, then run `npm run env:start` (or `php bin/setup-e2e-site.php` locally).
  - Expected: `curl -s -u admin:password http://localhost:8889/wp-json/sit-cwm-e2e/v1/mails` → `[]` (wp-env tests port; adjust the URL for local runs).
  - Confirm the fixture is not shipped: `npm run build:zip` and `unzip -l dist/*.zip | grep -c e2e-helpers` → `0`.

- [ ] **Step 2: `publish-gate.spec.js`** (block editor, `post` type)

  Users: `cwm_author` (author), `cwm_reviewer` (editor), `admin`. `beforeEach`: `setSettings( { publish_gate: { post: 'enforce' } } )`, delete all posts, clear mails.

  | Test | Steps | Assertions |
  |---|---|---|
  | `author sees Submit for Review, not Publish, below approved` | author creates a post, moves it to Review in the sidebar | top-bar button named `Submit for Review`; no button named `Publish`; panel shows `Publishing is locked until this content is approved.`; after typing and saving a draft (autosave path), `getActivityActions` has no `publish_gate_*` entry |
  | `approval in the sidebar enables Publish without reload` | reviewer opens the post, moves it to Approved | without reload, the button named `Publish` appears; the gate notice is gone |
  | `author publishes an approved post` | author opens the approved post, Publish → confirm | post status `publish` via REST; `getActivityActions` ends with `post_published`; timeline shows `published this while it was at Approved` |
  | `admin can bypass and it is recorded` | admin, new draft at Draft, Publish | published; actions include `publish_gate_bypassed` then `post_published`; timeline text `published this without approval` |
  | `live post in re-review stays live` (A3) | approved + published post; reviewer moves workflow to Writing; author edits the title and clicks Update | REST `status` is still `publish`; no `publish_gate_refused` |
  | `scheduled post sent back is not published at go-live` | approved post scheduled +1 day by the author; reviewer moves it to Needs Changes; `goLive()` | REST status `draft`; actions include `publish_gate_refused`; no `post_published` |
  | `direct wp_publish_post is reverted` | draft at Review; `rawPublish()` | status `draft`; actions end `post_published`, `publish_gate_refused`, `post_unpublished` |
  | `log_only allows and records` | `setSettings( { publish_gate: { post: 'log_only' } } )`; author publishes at Review | Publish button present; warning notice text (E14); published; `publish_gate_bypassed` present |

- [ ] **Step 3: `publish-gate-classic.spec.js`**

  Activate the Classic Editor plugin with `requestUtils.activatePlugin( 'classic-editor' )` in `beforeAll` and deactivate it in `afterAll`, as `classic-editor.spec.js` does.

  | Test | Assertions |
  |---|---|
  | `classic Publish below approved saves as draft with a notice` | after clicking Publish: `.sit-cwm-gate-notice` contains `was not published: it has to be approved in the editorial workflow first. It was saved as Draft.`; REST status `draft` |
  | `Quick Edit to Published below approved is refused` | `edit.php` Quick Edit, Status → Published, Update; row status still `Draft`; activity has `publish_gate_refused` with layer `save` (read the context via `/sit-cwm/v1/posts/<id>/activity`) |
  | `Bulk Edit to Published below approved is refused for every post` | two drafts, Bulk Edit Status → Published; both still `draft` |
  | `classic meta box shows the gate notice` | the meta box contains `Publishing is locked until this content is approved.` |

- [ ] **Step 4: `publication-log.spec.js`** (roadmap B acceptance)

  - `beforeAll`: `setSettings( { publish_gate: {} } )` (all off).
  - Test `publication at Review is logged with actor, time and workflow status with the gate off`:
    - author moves a post to Review and publishes it; Publish is available because the gate is off;
    - the timeline shows `cwm_author published this while it was at Review` with a time;
    - REST activity item `post_published` has `workflow_status: 'review'` and `user.name` set.
  - Test `unpublishing is logged`: switch to draft → `post_unpublished`.

- [ ] **Step 5: `notifications.spec.js`** (roadmap C acceptance)

  `beforeEach`: clear mails; enable all five events via `setSettings`.

  | Test | Action | Expected mails |
  |---|---|---|
  | `assigning a reviewer emails the reviewer once` | editor assigns `cwm_reviewer` in the sidebar | exactly 1, to the reviewer's email, subject `[<site>] Review assigned: <title>` |
  | `submitting for review emails the assigned reviewer` | author moves to Review | exactly 1, to the reviewer |
  | `submitting with no reviewer emails nobody` | author moves an unassigned post to Review | 0 |
  | `approval emails the author` | reviewer approves | exactly 1, to the author |
  | `comment by reviewer emails the author with the text` | reviewer comments `Tighten the intro.` | exactly 1, to the author, body contains `Tighten the intro.` |
  | `nobody is emailed about their own action` | author comments on their own unassigned post | 0 |
  | `disabling an event stops its mail` | `setSettings( { notifications: { approved: false } } )`; approve | 0 |
  | `double submit sends once` | fire the same transition twice quickly, via REST with the same `from`; the second returns 409 | exactly 1 |

- [ ] **Step 6: `settings-gate.spec.js`**

  - Admin opens Settings. Set Posts → Enforce and Pages → Log only, tick "Content is approved" and "Prevent people from approving their own content", Save.
  - The success notice shows; after reload the selects and both checkboxes keep their values.
  - An editor (no `sit_cwm_manage_workflows`) gets no menu item and a 403 page on the direct URL, as in 1.0.

- [ ] **Step 6a: `self-approval.spec.js`** (E16, phase 11a)

  `beforeEach`: `setSettings( { prevent_self_approval: true, publish_gate: { post: 'enforce' } } )`, delete all posts. `cwm_reviewer` (editor role) authors the fixture post.

  | Test | Steps | Assertions |
  |---|---|---|
  | `author with approve rights cannot approve own post` | `cwm_reviewer` creates a post and moves it to Review in the sidebar | no button named `Approve`; panel shows `You can't approve your own content. Another reviewer has to approve it.`; no button named `Publish` |
  | `another editor can approve it` | a second editor opens the post and approves it | status `Approved`; the author then sees `Publish` without reload |
  | `author is not offered as their own reviewer` | `cwm_reviewer` opens the reviewer picker on their own post | their own name is not in the list |
  | `REST self-approval is refused` | `POST /sit-cwm/v1/posts/<id>/workflow` as the author, `from: review, to: approved` | 403, code `sit_cwm_self_approval`, status still `review` |
  | `setting off allows it` | `setSettings( { prevent_self_approval: false } )`; author approves own post | status `Approved` |

- [ ] **Step 7: `cli-matrix.spec.js`** (roadmap A1 "WP-CLI")

  - Skip unless `process.env.SIT_CWM_E2E_CLI` is set, following `screenshots.spec.js`'s `test.skip( ! process.env.CWM_CAPTURE, … )` pattern.
  - The variable holds the command prefix:
    - wp-env: `npx wp-env run tests-cli wp`;
    - local: `wp --path=/path/to/site`.
  - Use `execSync` from `child_process`.

  | Test | Command | Expected |
  |---|---|---|
  | `CLI without a user is refused in enforce` | `post update <id> --post_status=publish` on a Review draft | `post get <id> --field=post_status` → `draft` |
  | `CLI as an administrator bypasses and is recorded` | same with `--user=admin` | `publish`; activity has `publish_gate_bypassed` |
  | `CLI create as publish is refused` | `post create --post_title=CLI --post_status=publish --porcelain` | the new id's status is `draft`; `publish_gate_refused` under that id |

  Document both prefixes in `DEVELOPMENT.md` (phase 14 Step 6).

- [ ] **Step 8: Accessibility and screenshots**

  - `a11y.spec.js`: add axe checks for the settings page (both new sections) and for the sidebar with the gate notice visible. Expected: no violations, the same threshold as the existing specs.
  - `screenshots.spec.js`: replace the shot list with the five captions from phase 14 Step 5, in that order. Arrange state with `setSettings`, `setWorkflowStatus` and `rawPublish` so the timeline shot shows a publication at Review and a blocked publish.
  - Run `CWM_CAPTURE=1 npm run screenshots`. Expected: `docs/screenshots/screenshot-1.png` … `-5.png` regenerated. Review each by eye and delete any stale higher-numbered files.

- [ ] **Step 9: Run the whole E2E suite on both WordPress versions**

  ```bash
  npm run env:start && npm run test:e2e
  WP_ENV_CORE=https://wordpress.org/latest.zip npm run env:start && npm run test:e2e
  SIT_CWM_E2E_CLI="npx wp-env run tests-cli wp" npx wp-scripts test-playwright tests/e2e/cli-matrix.spec.js
  ```

  Expected: every spec passes, old and new; `screenshots.spec.js` and `cli-matrix.spec.js` skip without their env vars. Fix failures in the owning phase's code, not by loosening assertions.

- [ ] **Step 10: Full release-readiness check**

  ```bash
  composer lint
  vendor/bin/phpunit --testsuite unit
  WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration
  composer test:coverage        # Needs Xdebug; Publishing/ and Notifications/ must meet the same minimums bin/coverage-report.php enforces for Workflow/
  npm run lint:js && npm run lint:css && npm run test:unit
  npm run build && git diff --exit-code -- assets/build   # Build output committed and current (CI checks this)
  npm run check:version         # Must still report 1.0.0 everywhere: no bump in this release branch
  ```

  Expected: all green. `bin/coverage-report.php` (`SIT_CWM_COVERAGE_LINE_MINIMUMS`) only lists `includes/Workflow/` (90 %), `includes/Activity/` (80 %) and `includes/Content/` (80 %). Add `includes/Publishing/` and `includes/Notifications/` at **90 %**, the same as `Workflow/`, because both decide who may do what. Do this in this step, before running the check.

- [ ] **Step 11: Tick the acceptance matrix**

  In `00-INDEX.md`, fill the "Roadmap acceptance → where it is proven" table with the passing test names, and tick every phase in the progress tracker.

- [ ] **Step 12: Hand off**

  - Suggested message: `test(e2e): prove publish gate, publication logging and notifications end to end; refresh screenshots`.
  - Remind the developer of what remains theirs: the version bump to 1.1.0 (`Version:`, `SIT_CWM_VERSION`, `package.json`, `Stable tag`), the `CHANGELOG.md` and readme changelog/upgrade-notice entries, a manual screen-reader pass of the settings sections and the gate notice, and tagging the release.

## Acceptance criteria

- Every row of the 00-INDEX acceptance table has a passing PHPUnit/Jest test and, where marked, a passing E2E test on WordPress 6.8 and latest.
- Screenshots match the new listing captions.
- Lint, unit, integration, coverage, Jest, build-drift and version checks are all green.
- The version is still 1.0.0.
