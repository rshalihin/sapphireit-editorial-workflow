# Free 1.1.0 "Enforce what it promises": Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the workflow binding and the audit trail complete. Add a per-post-type publish gate (off / log only / enforce), unconditional publication logging, opt-in email notifications, an optional "prevent self-approval" setting (added by the developer, not in the roadmap; E16) and a governance-first WordPress.org listing.

**Architecture:** Two new modules sit beside the existing engine and do not change it:
- `includes/Publishing/` holds one pure decision service (`PublishGate`) and three hook layers that ask it. A per-request ledger stops the layers judging the same write twice, and a recorder writes the audit rows.
- `includes/Notifications/` is a self-contained listener on the existing `sit_cwm_*` actions.

As in 1.0, all decisions stay in PHP. React only displays what the server reports.

**Tech Stack:** PHP 7.4+, WordPress 6.8+ (tested to 7.1), `@wordpress/scripts` 35, PHPUnit 9 + wp-phpunit, Jest, Playwright (`@wordpress/e2e-test-utils-playwright`).

**Spec:** `.claude/roadmap/2026-10-24-editorial-workflow-roadmap-design.md` §3.1 (A–D), with the corrections and frozen decisions in [`01-decisions-and-conventions.md`](01-decisions-and-conventions.md). Executors read both. Where they differ, `01` wins, because it records verified facts about WordPress core that the roadmap got wrong (see E3).

## Global Constraints

- Everything in `/CLAUDE.md` applies: `sit_cwm_` / `Sit_Cwm\` / `SIT_CWM_` / `sit-cwm` prefixes, text domain `sapphireit-editorial-workflow`, ABSPATH guard first in every PHP file, tabs, Yoda conditions, full docblocks with `@since 1.1.0` on everything new.
- `composer lint` (phpcs, WordPress-Extra) must report **zero** errors and warnings at the end of every phase. Inline `phpcs:ignore` only for a named sniff, with a reason, as in 1.0.
- **Every new 1.1 class gets `@internal` in its class docblock.** Roadmap §3.2 freezes the public surface in 1.2 by tagging what Pro may call `@api`; until then, nothing new becomes public by accident. The new hooks (E11) are public and are documented in `ARCHITECTURE.md` (phase 14). Methods added to existing 1.0 classes keep whatever those classes already have.
- `Requires at least: 6.8`, `Requires PHP: 7.4`. No syntax or API newer than PHP 7.4 / WP 6.8 (e.g. no `readonly`, no enums, no first-class callable syntax).
- **No version bump and no changelog entries.** `Version:`, `SIT_CWM_VERSION`, `package.json` `version`, `Stable tag`, `CHANGELOG.md` and the readme `== Changelog ==` / `== Upgrade Notice ==` stay untouched. The developer does them at release (global CLAUDE.md).
- **No git commits by the AI.** Each phase ends with "Hand off": report the changed files and a suggested commit message. The developer commits.
- `SIT_CWM_DB_VERSION` stays `1.0.0`: 1.1 changes no schema (roadmap §3.1B).
- Upgrading from 1.0.0 changes no behaviour: every gate mode is `off`, every email event is off and self-approval prevention is off until a site owner turns them on (E1, E12, E16).
- Pro scope stays out: no per-user notification preferences, digests, Slack, reminders, multi-stage approval or rules engine.
- `@wordpress/dataviews` stays pinned at exactly `11.3.0`.
- E2E (Playwright) tests are written and run **only in phase 15**. Phases 02–14 use PHPUnit and Jest only.

## Review Focus

Five inputs no single happy-path test exercises, which are the likeliest to hurt real sites. Each one has a pinned test in the phase that owns the code.

1. **A live post edited during re-review** (stored `publish`, workflow `writing`/`review`) must stay `publish` through a REST update, a classic Update and Quick Edit, in `enforce` mode. Pinned in 07 (`test_published_post_in_rereview_stays_published_on_update`) and 08 (`test_rest_update_of_published_post_is_not_gated`).
2. **A post scheduled while approved and then sent back for changes** must not go live when cron fires. Pinned in 09 (`test_scheduled_post_sent_back_after_scheduling_is_refused_at_go_live`).
3. **A post type with a gate mode that is later disabled in workflow settings** must behave as `off`, not keep enforcing on unmanaged content. Pinned in 06 (`test_mode_is_off_for_post_type_no_longer_enabled`).
4. **A brand-new post created directly as `publish`/`future`** (an agent over REST with an application password, or `wp_insert_post()` from an import) has no id when it is judged. A refusal or bypass must still be recorded against the id it ends up with. Pinned in 07 (`test_new_post_inserted_as_publish_is_refused_and_logged_under_new_id`) and 08 (`test_rest_create_as_publish_is_refused_with_403`).
5. **Notification recipients:** the actor, a deleted user, a user without email, a user who cannot read the post, and the `sit_cwm_reviewer_assigned( …, 0, … )` that user-deletion cleanup fires must never produce mail. Pinned in 12 (`test_recipients_exclude_actor_missing_unreadable_and_emailless_users`, `test_reviewer_cleared_by_user_deletion_resolves_no_recipients`).

---

## How to use this plan

- Work phases **in numeric order**. Don't start one whose prerequisites are unchecked.
- Each phase ends green on `composer lint`, `composer test`, the integration suite, and, where JS changed, `npm run lint:js` + `npm run test:unit`.
- Integration command used throughout:
  `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter <TestName>`
- If a phase forces a deviation from `01-decisions-and-conventions.md`, update `01` in the same phase.

## Phases

| # | Phase | Roadmap | Layer | Depends on |
|---|---|---|---|---|
| 01 | [Frozen decisions for 1.1](01-decisions-and-conventions.md) | all | doc | — |
| 02 | [Scope housekeeping + settings model](02-scope-and-settings-model.md) | A, C | core | 01 |
| 03 | [Bypass capability + capability upgrade](03-bypass-capability-and-upgrade.md) | A | workflow | 02 |
| 04 | [Publication activity actions + formatter](04-publication-activity-actions.md) | B | activity/REST | 02 |
| 05 | [Publication logger (closes G2)](05-publication-logger.md) | B | publishing | 04 |
| 06 | [Publish gate decision engine](06-publish-gate-decision.md) | A | publishing | 02, 03 |
| 07 | [Ledger, recorder, Layer B (save guard)](07-layer-b-save-guard.md) | A | publishing | 04, 05, 06 |
| 08 | [Layer A: REST + `publish_post` meta cap](08-layer-a-rest-and-meta-cap.md) | A | publishing | 07 |
| 09 | [Layer C: scheduled publishing + backstop](09-layer-c-schedule-and-backstop.md) | A | publishing | 07 |
| 10 | [Gate feedback: notices, REST field, editor UI](10-gate-feedback-ui.md) | A | admin/REST/React | 08, 09 |
| 11 | [Settings screen sections](11-settings-screen.md) | A, C | admin | 02 |
| 11a | [Prevent self-approval](11a-no-self-approval.md) | — (E16) | workflow/REST/admin/React | 02, 10, 11 |
| 12 | [Notification events + recipients](12-notification-events-and-recipients.md) | C | notifications | 02 |
| 13 | [Notification messages + delivery](13-notification-delivery.md) | C | notifications | 12 |
| 14 | [Docs + WordPress.org listing rewrite](14-docs-and-listing.md) | D | docs | 05–13 |
| 15 | [E2E suite, acceptance matrix, screenshots](15-e2e-and-acceptance.md) | all | quality | 02–14 |

Phases 04–05, 06 and 11–13 are independent of each other once 02/03 are done. Any order between those groups is fine. The table order is the recommended one.

## Roadmap acceptance → where it is proven

| Roadmap acceptance (§3.1) | PHPUnit / Jest | E2E (15) |
|---|---|---|
| A1 matrix: block editor, classic, Quick Edit, Bulk Edit, `wp/v2` REST, WP-CLI, scheduled, published updates, bypass | 07, 08, 09 | `publish-gate.spec.js` (all 8), `publish-gate-classic.spec.js` (`classic Publish below approved saves as draft with a notice`, `Quick Edit to Published below approved is refused`, `Bulk Edit to Published below approved is refused for every post`), `cli-matrix.spec.js` (all 3) |
| A2 below `approved` cannot reach `publish`/`future` in `enforce` | 07, 08, 09 | `publish-gate.spec.js`: `author sees Submit for Review, not Publish, below approved`, `scheduled post sent back is not published at go-live`, `direct wp_publish_post is reverted` |
| A3 live post in re-review stays `publish` | 07, 08 | `publish-gate.spec.js`: `live post in re-review stays live` |
| A4 exception inside the gate allows the publish and logs it | 07, 08, 09 | — |
| A5 upgrade from 1.0.0 leaves every type `off` | 02, 03 | — |
| A6 docs state honestly that direct `$wpdb` writes are not covered | — (14 is docs) | — |
| B publication at `review` logged with actor, time, workflow status, gate `off` | 05 | `publication-log.spec.js`: `publication at Review is logged with actor, time and workflow status with the gate off`, `unpublishing is logged` |
| C exactly one mail to the correct recipient per event | 12, 13 | `notifications.spec.js`: `assigning a reviewer emails the reviewer once`, `submitting for review emails the assigned reviewer`, `submitting with no reviewer emails nobody`, `approval emails the author`, `comment by reviewer emails the author with the text`, `nobody is emailed about their own action`, `double submit sends once` |
| C no comment body to a recipient without `sit_cwm_view_activity` | 13 | — |
| C disabling an event stops its mail | 13 | `notifications.spec.js`: `disabling an event stops its mail` |
| D listing rewritten, screenshots replaced, "does not send email" removed | — | `screenshots.spec.js` (5 shots, `docs/screenshots/screenshot-1.png` … `-5.png`, captured 2026-10-28) |
| E16 (not roadmap) with the setting on, nobody approves content they authored, by any route | 11a | `self-approval.spec.js` (all 5); `settings-gate.spec.js` |

## Progress tracker

Tick a box only when that phase's acceptance criteria all pass.

E2E status (2026-10-28): the whole suite passes on WordPress 7.1.3 against a local site (48 passed, 8 skipped by design; `cli-matrix.spec.js` 3 passed with `SIT_CWM_E2E_CLI`). It also passes on WordPress 6.8 against a fresh local site (`cwm-e2e-68`, PHP 8.1): 48 passed, 8 skipped by design; `cli-matrix.spec.js` 3 passed with `SIT_CWM_E2E_CLI` (WP-CLI 2.9.0). The first 6.8 run failed 18 tests, none on a plugin assertion: 16 because the block editor's welcome guide reopened over the sidebar for users with no saved preferences (fixed in `tests/e2e/fixtures/sit-cwm-e2e-helpers.php`), 1 because a Retry assertion in `ui-states.spec.js` waited only 5 s on a cold server (now 20 s), and 1 login timeout on the cold site that did not recur. All 18 passed on the rerun. Still open for 15: a 6.8 run on PHP 7.4 (the CI `e2e` job covers it), and `composer test:coverage` (needs Xdebug).

- [x] 02 Scope housekeeping + settings model
- [x] 03 Bypass capability + capability upgrade
- [x] 04 Publication activity actions + formatter
- [x] 05 Publication logger
- [x] 06 Publish gate decision engine
- [x] 07 Ledger, recorder, Layer B
- [x] 08 Layer A: REST + meta cap
- [x] 09 Layer C + backstop
- [x] 10 Gate feedback UI
- [x] 11 Settings screen sections
- [x] 11a Prevent self-approval
- [x] 12 Notification events + recipients
- [x] 13 Notification delivery
- [x] 14 Docs + listing rewrite
- [ ] 15 E2E, acceptance matrix, screenshots

## Out of scope for 1.1 (do not build)

The 1.2 seams (post-id context on registry filters, `WP_Error` vetoes, `sit_cwm_posts_query_args`, `sit_cwm_container()`, `@api` freeze), CSV export, AI-origin flagging, attestation, the governance report screen, a dashboard redesign (planned for 1.3; see `01` → E15), a setting to choose which roles may approve (Pro; 14 documents the role-editor route, see E16), and everything in roadmap §3.4 / §4.
