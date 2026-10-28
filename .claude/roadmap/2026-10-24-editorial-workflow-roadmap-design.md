# SapphireIT Editorial Workflow — Free & Pro Roadmap Design

**Date:** 24 October 2026
**Status:** Approved design, pending implementation plan
**Supersedes:** the October 2026 "Product & Development Roadmap" planning document, which
described Free v1.0 as unbuilt. It shipped on 17 October 2026.

---

## 1. Context

### 1.1 What exists today (verified against the repository, not the docs)

Free v1.0.0 is live on WordPress.org and is substantially complete:

- Six-status state machine (`draft → writing → review → needs_changes → approved →
  published`) with a validated transition graph and three rollback edges.
- `WorkflowManager` as the single writer; `TransitionManager` (graph) and
  `PermissionManager` (authorization) deliberately separate and unaware of each other.
- Six `sit_cwm_*` capabilities, role grants confined to `Capabilities::role_map()`.
- Optimistic concurrency: a status change states the status the client believed current,
  or fails 409 and writes nothing.
- `_sit_cwm_status` locked against direct meta writes through both `auth_callback` and
  `map_meta_cap`.
- Append-only activity table `{prefix}sit_cwm_activity`, indexed on `(post_id, created_at)`.
- Eight REST routes under `sit-cwm/v1`, every one with a real `permission_callback`, an
  argument schema and a published response schema.
- Block-editor sidebar and classic-editor meta box sharing one React panel; DataViews
  dashboard with server-side search, filter, sort, pagination and bulk actions.
- PHPUnit (unit + integration), Jest and Playwright suites; CI across PHP 7.4/8.1/8.3 ×
  WordPress 6.8/latest.

**Do not re-plan or rebuild any of the above.**

### 1.2 Three gaps found in the code

These are findings from reading `includes/`, not from the documentation.

**G1 — The workflow is advisory, not enforced.**
The author role natively holds `publish_posts`. The plugin filters `map_meta_cap` only in
`PostMeta::lock_status_meta()`, to protect `_sit_cwm_status`. It never filters
`publish_post`. An author can press Publish on a post whose workflow status is `draft`
and the post goes live.

**G2 — The audit trail does not record publication.**
The plugin registers no `transition_post_status`, `wp_insert_post_data` or
`future_to_publish` handler. Publication is not merely unblocked — it is invisible. The
activity log can show a post at `review` while it is live on the site, with no entry
explaining why. An audit trail with this hole is not evidence.

**G3 — The Pro extension seams were never built.**
`.claude/pro/01-pro-extension-points.md` specifies them as step 24. The code shows the
step did not happen:

- `PermissionManager::can_change_status()` ends `return true === $filtered;` — a veto
  through `sit_cwm_can_transition` cannot carry a reason, so checklist gating and a rules
  engine cannot say *why* they refused.
- `sit_cwm_statuses`, `sit_cwm_transition_map` and `sit_cwm_status_capability_map` take no
  `$post_id` context, and their results are memoised per request. Per-post variation is
  impossible, so multiple workflows cannot be built.
- No `sit_cwm_posts_query_args` filter in `PostsController`.
- No container accessor and no `sit_cwm_loaded` action — an add-on cannot reach the
  services and would have to build a parallel stack.
- Zero `@api` tags; no frozen public surface.

All of G3 consists of changes to Free core that must land **before Pro exists**, and that
get harder the longer the current behaviour is depended upon.

### 1.3 Market position (October 2026)

| Product | Active installs | Rating | Notes |
|---|---|---|---|
| PublishPress Revisions | 10,000+ | 4.6 (65) | Actively maintained; revision-approval, not workflow |
| PublishPress Planner | 6,000+ | 4.9 (178) | Calendar, board, overview; Slack + reminders are Pro |
| Oasis Workflow | 700+ | 4.8 (51) | Closest direct competitor; **last updated 8 months ago**, tested only to 6.9.7 |
| Multicollab | 300+ | 3.7 (10) | Listing states the free version is **no longer maintained** |
| Jumplinks Flow | <10 | — | **The live threat.** Shipping daily; gives away inline review comments, external email reviewers and mandatory publish-blocking; positions explicitly around AI agents and MCP |
| **SapphireIT Editorial Workflow** | **<10** | — | Strongest engine, weakest distribution |

**The opening:** the 2026 content-operations conversation has moved from editorial
calendars to AI content governance. WordPress VIP's 2026 research reports 85% of
enterprise leaders believe publishing AI-generated content without human review erodes
brand trust, and draws exactly the distinction this plugin's architecture already
embodies: in ungoverned workflows review is policy and easily bypassed; in governed ones
it is built into the architecture and cannot be skipped, with audit trails captured
automatically. It cites EU AI Act penalties up to EUR 35 million or 7% of global turnover,
GDPR Article 22's right to human review, and predicts "human in the loop" becoming a
procurement requirement within two RFP cycles.

Incumbents compete on planning. Nobody has taken the governance lane. The window will not
stay open — Jumplinks is already using the framing.

---

## 2. Positioning

The product sells **enforced human review with a defensible audit trail for AI-assisted
content**.

> AI can draft it. A human approves it. You can prove who approved what, and when.

This is a product decision, not a marketing one. It requires three things to be true:

1. Review must be **enforceable** — the publish gate (fixes G1).
2. The audit trail must be **complete** — publication logging (fixes G2).
3. The log must be **exportable** — evidence that cannot leave the database is not
   evidence.

The existing architecture is the credibility: the UI never decides, and a hostile client
with a valid cookie can do exactly what an honest one can and nothing more.

**Explicitly rejected:** competing head-on with PublishPress on editorial calendars and
kanban boards. Both are free there, with a decade of polish. No Free or Pro release in
this plan includes a calendar or board.

---

## 3. Free roadmap

Sized for 40+ hours per week. Sequence is fixed: 1.1 before 1.2 before 1.3.

### 3.1 Free 1.1 — "Enforce what it promises" (~2 weeks)

#### A. The publish gate

Three modes, configurable per post type. Default **off**, so no existing install changes
behaviour on upgrade.

| Mode | Rule evaluated? | Publishing restricted? | Bypass recorded? |
|---|---|---|---|
| `off` | No | No | No |
| `log_only` | Yes | No | Yes |
| `enforce` | Yes | Yes | n/a — refusal recorded instead |

The three modes differ in **whether the approval rule is evaluated and what happens when
it fails**, not in whether publication is recorded. Publication logging (section B below)
is unconditional and applies in all three modes, including `off`, because closing G2 is
independent of the gate.

So: `off` evaluates nothing and is the upgrade default. `log_only` evaluates the rule,
allows the publish anyway, and writes a distinct bypass event that surfaces in the
governance report. `enforce` refuses the publish and records the refusal.

`log_only` exists so a team can measure how often their process is bypassed before
enforcing anything. It is a safe trial mode that generates almost no support load, and it
is a governance feature in its own right.

**Primary mechanism: `map_meta_cap` on `publish_post` → `do_not_allow`.**
Chosen over intercepting the save because the block editor reads `wp:action-publish` from
the REST response and, when the capability is denied, substitutes "Submit for Review" for
the Publish button on its own. The user gets a correct UI with no JavaScript written for
it, and REST refuses by the same rule.

**Backstops**, following the three-layer pattern already proven in SapphireIT Publish
Guard (`src/Publishing/Publish_Guard.php`), because no single hook sees every path:

| Layer | Hook | Covers |
|---|---|---|
| A | `map_meta_cap` on `publish_post` | Block editor, classic editor, REST, anything that checks the capability |
| B | `wp_insert_post_data` | Quick Edit, Bulk Edit, direct `wp_update_post()` calls |
| C | `future_to_publish` | The scheduled-publish cron, which writes status with a raw query |

**Rules carried over from Publish Guard:**

- Enforce only on transitions **into** published. Never demote live content.
- **Fail open** on an internal exception. A bug in this plugin must never permanently
  prevent someone from publishing on their own site.

**New requirements specific to this plugin:**

- A dedicated `sit_cwm_bypass_publish_gate` capability, granted to administrators only, so
  there is always a way through.
- An explicit carve-out for already-published posts in re-review, so the existing
  re-review flow (published → writing → … → approved) does not break. A live post being
  corrected must remain live.

**Acceptance criteria:**

1. Test matrix passes for: block editor, classic editor, Quick Edit, Bulk Edit,
   `wp/v2/posts` REST, WP-CLI, scheduled publishing, updates to already-published posts,
   and the bypass capability.
2. In `enforce` mode, a post below `approved` cannot reach `publish` or `future` by any
   covered path.
3. A live post sent back for re-review stays `publish` throughout the cycle.
4. An exception thrown inside the gate allows the publish and logs the failure.
5. Upgrading from 1.0.0 leaves every post type in `off` mode.
6. Documentation states honestly that a direct `$wpdb` write is not covered.

#### B. Publication logging

Hook `transition_post_status`. Write activity rows for four new action types —
publication, unpublication, gate refusal (`enforce` mode) and gate bypass (`log_only`
mode) — each recording the workflow status at that moment.

Publication and unpublication are logged in **all** gate modes, including `off`. Refusal
and bypass are specific to `enforce` and `log_only` respectively.

No schema change: `action` is already `varchar(50)` and the action set is defined in code.

**Acceptance:** a post published while at `review` produces an activity entry naming the
actor, the time and the workflow status at publication — and does so with the gate `off`.

#### C. Email notifications

Built entirely on the existing actions (`sit_cwm_status_changed`,
`sit_cwm_reviewer_assigned`, `sit_cwm_comment_added`), so the module is self-contained and
removable.

Events: reviewer assigned, moved to review, sent back for changes, approved, comment
added. Per-event site-level toggles. Deduplicated with a short transient so a double save
does not double send.

Per-user preferences and digests are **Pro**.

**Acceptance:** each event sends exactly one mail to the correct recipient; no mail
contains workflow comment bodies visible to a user lacking `sit_cwm_view_activity`;
disabling an event stops its mail.

#### D. Listing rewrite

Rewrite the WordPress.org description around the governance positioning, replace the
screenshots, and remove the "it does not send email" statement.

### 3.2 Free 1.2 — The seams (~1.5 weeks)

Unglamorous, non-negotiable, and must precede any Pro code.

1. **Context on the registry filters.** Add a trailing `$post_id` (0 when not
   post-specific) to `sit_cwm_statuses`, `sit_cwm_transition_map` and
   `sit_cwm_status_capability_map` — **and rework the per-request memoisation to be keyed
   by post.** The step-24 audit missed this second half: with the context argument added
   but the cache unkeyed, every post after the first would silently receive the first
   post's answer. Multiple workflows is impossible until this is fixed.
2. **Vetoes carry a reason.** `sit_cwm_can_transition` accepts `true | false | WP_Error`.
   `true === $filtered` remains the allow condition, so existing extensions are
   unaffected; a `WP_Error` means denied with a message, surfaced verbatim through REST to
   the sidebar.
3. **`sit_cwm_posts_query_args`** in `PostsController`, so saved views can extend the
   dashboard query without forking the controller.
4. **`sit_cwm_loaded` action** passing the `Plugin`, plus a `sit_cwm_container()`
   accessor, so an add-on fetches `workflow_manager` instead of instantiating a parallel
   stack.
5. **Freeze the public surface.** `@api` on every class and method Pro may call,
   `@internal` on everything else; list every `@api` signature in ARCHITECTURE.md; record
   the rule that breaking one requires a major version bump.
6. **The probe add-on**, as a committed test fixture (`tests/fixtures/sit-cwm-probe/`): it
   injects a `legal_review` status, rewires the graph so `review → legal_review →
   approved`, requires a new capability for it, vetoes `approved` with a `WP_Error` when a
   checklist meta is unset, logs every `sit_cwm_status_changed`, and extends the dashboard
   query. An integration test activates it and asserts each behaviour.

**Acceptance:** the probe achieves all six behaviours with **zero edits inside
`includes/`**. If any behaviour requires one, the seam is missing — fix the seam, not the
probe. The injected status renders correctly in the sidebar and dashboard, and the
`WP_Error` veto message reaches the sidebar verbatim.

**Also in 1.2 — audit export (Free).** The full activity log as CSV, streamed in chunks,
gated on `sit_cwm_view_activity` plus `sit_cwm_manage_workflows`. Filtered, scheduled and
tamper-evident exports are Pro.

### 3.3 Free 1.3 — Governance features (~2 weeks)

**AI-origin flagging.** A registered `_sit_cwm_ai_origin` meta field with three values:
`human`, `ai_assisted`, `ai_generated`. Settable three ways — a sidebar dropdown, a REST
argument so a writing tool or agent can mark its own drafts, and a per-post-type site
default. Every change is logged to the activity table.

> **This is declared, not detected.** The plugin does not inspect text to guess whether a
> machine wrote it. AI detectors are unreliable and claiming detection would be
> indefensible. The value is that the declaration is recorded, tied to an approval, and
> exportable.

**Agent-facing documentation.** The REST API already supports application passwords.
Document the "an agent submits a draft for human review" flow explicitly, with n8n and MCP
examples. No integration is built; the recipe is the deliverable.

**Review attestation.** Optionally require the approver to confirm they reviewed the
content, recorded in the activity row. Cheap to build; it is the difference between a log
and evidence.

**Optional front-end disclosure.** "Reviewed by X on date" and an AI-disclosure line, as
both a block and a shortcode. Off by default.

**Governance report screen.** Share of published content that passed through approval;
content published while the gate was off or in `log_only`; AI-flagged counts; overdue
reviews.

### 3.4 Free non-goals

Not in any Free release: editorial calendar, kanban board, multi-stage approval, multiple
workflows, Slack, scheduled reminders, AI content generation, AI detection.

---

## 4. Pro

### 4.1 Architecture

A **separate add-on plugin**, `sapphireit-editorial-workflow-pro`, requiring Free and
editing none of its files. Free remains on WordPress.org, GPL, and complete in itself.

All licence handling sits behind one interface in Pro — `License_Gateway` with
`activate()`, `deactivate()`, `validate()`, `status()`. Feature code asks
`License::is_active()` and knows nothing else. The Paddle implementation goes behind it.

**This interface is the escape hatch.** If the self-hosted licence server becomes a time
sink, a Freemius adapter drops in behind the same interface without touching feature code.

### 4.2 Licensing and payments

**Decision: Paddle (merchant of record) + a self-hosted licence service on Next.js/Vercel.**

Rationale and constraints:

- Stripe does not support Bangladesh-registered businesses as merchants, so a self-hosted
  store with its own card processing is not available. A merchant of record collects the
  money in its own jurisdiction and pays out, which removes the problem.
- Paddle accepts individuals and sole traders — business verification is not required for
  them; they complete domain review and identity verification only. Bangladesh appears on
  Paddle's supported country list.
- Freemius is the documented fallback: it supports makers in 190+ countries, lists
  Bangladesh as a supported payout country, pays via PayPal, Payoneer, wire or Wise, with
  a $100 minimum balance and a 2% (capped at $20) fee on non-US PayPal transfers.

**What the licence service must do** — the full scope, not just key generation:

- Four endpoints: activate, deactivate, validate, update-check.
- Paddle webhooks for `transaction.completed`, `subscription.updated`,
  `subscription.canceled`, with signature verification.
- Site-count enforcement that survives staging clones, domain changes, and sites deleted
  rather than deactivated.
- An update server feeding `site_transient_update_plugins` and `plugins_api`.
- **Graceful degradation.** Cache the last successful validation and keep Pro working
  through a grace period when the service is unreachable. A paying customer locked out by
  a hosting hiccup is the worst possible support ticket.

**Operational notes:** Vercel's free tier is for hobby use and is not licensed for
commercial projects — budget for the paid tier or host the licence API elsewhere. Begin
the Paddle application during 1.2, not after Pro is built: domain review requires a real
website with pricing, terms and a refund policy, and is the step most likely to stall the
launch by weeks.

### 4.3 The Free/Pro boundary

**The rule: Free enforces one review. Pro coordinates many.**

Everything that makes the governance claim true stays free — the gate, the complete audit
trail, basic export, AI flagging, attestation. Credibility is not held hostage; Pro
charges for scale, automation and evidence at volume.

**Pro 1.0 (~4–5 weeks):**

- Multi-stage sequential approval (Writer → Editor → Legal → Final).
- Multiple workflows: different graphs per post type, category or team, as templates.
  Unlocked by the keyed memoisation fix in 1.2.
- Checklist gating, vetoing with a reason through the `WP_Error` contract.
- Reminders and escalation: due-date reminders before and after, overdue escalation to a
  fallback reviewer, on Action Scheduler rather than bare `wp_cron`.
- Slack and Microsoft Teams notifications.
- Per-user notification preferences and digests.
- Reviewer groups with fallback reassignment.
- Compliance reporting: filtered and scheduled exports, retention policy, tamper-evident
  log, reviewer workload and bottleneck analysis.
- AI policy rules (e.g. "AI-generated content requires two approvals") and enforced
  disclosure.

**Pro 1.1, in priority order, when customers ask:** external reviewers via sign-in-free
links (Jumplinks' strongest free feature — it will come up in comparisons), then webhooks
for n8n, Zapier and Make.

**Not in the first Pro release:** editorial calendar, AI content generation, a hosted SaaS
dashboard, an unrestricted workflow builder, or any requirement for a third-party account
to use core approval features.

### 4.4 Pricing

Context: PublishPress charges $149 (1 site), $249 (5 sites) and $599 (unlimited) — for a
bundle of fourteen Pro plugins. Multicollab's entry plan is $249/year.

One focused plugin from an unknown vendor prices below the incumbent bundle:

| Tier | Sites | Price |
|---|---|---|
| Personal | 1 | $79/yr |
| Agency | 5 | $149/yr |
| Unlimited | unlimited | $299/yr |

Annual recurring. **No lifetime licences** — this product commits to ongoing
compliance-grade maintenance.

**Founding-customer offer:** 50% off the first 50 licences, rate locked for as long as
they renew. It converts the absence of social proof into a reason to buy now, and produces
the testimonials that cannot otherwise be waited for.

---

## 5. Risks

| Risk | Mitigation |
|---|---|
| Publish gate generates support load | Off by default; `log_only` as a safe trial mode; bypass capability; fail-open on exception; honest documentation of uncovered paths |
| Paddle rejects the application at domain review | Apply during 1.2 with a complete site; Freemius documented as the fallback behind the same `License_Gateway` interface |
| Licence server becomes a time sink | Single swappable interface; graceful degradation designed in from the start |
| Jumplinks Flow reaches the governance positioning first | Ship 1.1 and 1.2 quickly; the enforced gate plus a complete audit trail is the defensible combination |
| Pro ships to an audience of nobody | Free 1.1–1.2 must move installs from <10 to the hundreds before Pro launches. This is the primary success condition, not a secondary one |
| Seam changes break third-party extensions later | Land all of them in 1.2, before anyone depends on current behaviour; freeze the surface with `@api` and a stated stability policy |
| Governance claims overreach | Never claim AI detection; never claim coverage of paths not tested; publish the uncovered-path list |

---

## 6. Success measures

Not downloads. The measures that decide whether Pro is worth building:

- Active installs and reviews on WordPress.org after 1.1 and 1.2.
- Share of installs that enable the publish gate in any mode.
- Share of gate-enabled sites that move from `log_only` to `enforce`.
- Review cycles completed per active site.
- Repeated requests for the same Pro capability.

---

## 7. Decisions recorded

| # | Decision | Rationale |
|---|---|---|
| R1 | Publish gate ships in **Free**, off by default, three modes | Jumplinks gives publish-blocking away free; it is also what makes the governance claim literally true |
| R2 | Gate uses `map_meta_cap` as primary, with two backstops | Block editor adapts its own UI when the capability is denied; proven pattern from Publish Guard |
| R3 | Positioning is **AI governance and compliance first** | Incumbents own planning; nobody owns governance; the existing architecture is already the hard part |
| R4 | Seam work (1.2) precedes all Pro code | Breaking changes to Free core get harder once depended upon |
| R5 | AI flagging stays in **Free** | One day of work; the free listing is the entire acquisition channel and must be able to demonstrate its own pitch; Pro sells the rules and reports built on the flag |
| R6 | Paddle + self-hosted licence service, Freemius as fallback | Stripe unavailable in Bangladesh; merchant of record resolves tax and collection; the learning goal is explicit and respected |
| R7 | Pro is a separate add-on plugin | Already required by the step-24 audit; keeps Free complete and GPL |
| R8 | No calendar or kanban in Free or Pro 1.0 | Free and mature in PublishPress; arriving second costs weeks |
| R9 | Annual pricing at $79 / $149 / $299, no lifetime | Below the incumbent bundle; recurring matches an ongoing maintenance commitment |

---

## 8. Sequence

| Release | Theme | Estimate |
|---|---|---|
| Free 1.1 | Publish gate, publication logging, email notifications, listing rewrite | ~2 weeks |
| Free 1.2 | Pro seams, probe fixture, CSV audit export. **Start Paddle application.** | ~1.5 weeks |
| Free 1.3 | AI flagging, attestation, disclosure, agent docs, governance report | ~2 weeks |
| Pro 1.0 | Multi-stage, multiple workflows, reminders, Slack, reporting, AI policy rules | ~4–5 weeks |

Estimates assume 40+ hours per week and exclude unforeseen WordPress compatibility work.
If the schedule slips, cut features before cutting the security and permission tests.

Work begins after SapphireIT Publish Guard for WooCommerce is complete.
