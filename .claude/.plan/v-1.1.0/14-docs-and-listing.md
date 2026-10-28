# 14: Docs + WordPress.org listing rewrite

**Goal:**
- Rewrite the listing around governance (roadmap §2 positioning, §3.1D).
- Document the gate honestly, including what it does **not** cover (roadmap A6).
- Bring the developer docs in line with E7–E11.
- Regenerate the `.pot`.

No version bump and no changelog or upgrade-notice entries (Global Constraints). The screenshot *images* are captured in phase 15; this phase only rewrites their captions.

**Prerequisites:** 05–13 and 11a merged, so the docs describe shipped behaviour. **Roadmap:** §3.1D, A6, §2, §5 "Governance claims overreach → never claim coverage of paths not tested; publish the uncovered-path list".

**Files:**
- Modify: `readme.txt`. Edit: short description, `== Description ==`, `= What it does =`, `= What it deliberately does not do =`, `== Frequently Asked Questions ==`, `== Privacy ==`, `== Screenshots ==`. Do not touch: `Stable tag`, `== Changelog ==`, `== Upgrade Notice ==`.
- Modify: `README.md`. Edit: the feature list, and the "No email" bullet at line ~61; drop "Email notifications" from the future-work list at line ~301 and leave "Slack integration".
- Modify: `WORKFLOW.md`: a new section, "Publishing and the publish gate".
- Modify: `ARCHITECTURE.md`: the `Publishing/` and `Notifications/` modules, the layer table (E3), the hook-order table (E6), and the new hooks (E11).
- Modify: `REST-API.md`: the `publish_gate` field (E10), the activity fields and actions (E7), and the error `sit_cwm_publish_gated`.
- Modify: `DEVELOPMENT.md`: how to run the new E2E specs (phase 15) and the CLI matrix env var.
- Regenerate: `languages/sapphireit-editorial-workflow.pot`.

---

- [ ] **Step 1: Rewrite the readme short description (≤150 characters)**

  Use exactly: `Editorial approval workflow with an enforceable publish gate and a complete audit trail. AI can draft it. A human approves it.`

  Check: `awk 'NR==11' readme.txt | wc -c` (the short description line) is at most 151, newline included.

- [ ] **Step 2: Rewrite `== Description ==` and `= What it does =`**

  - Open with the roadmap tagline paragraph: "AI can draft it. A human approves it. You can prove who approved what, and when."
  - Then three bullets, matching roadmap §2's three requirements:
    - **Enforceable review**: the publish gate's three modes, per content type, off by default;
    - **A complete audit trail**: every publish and unpublish recorded with the workflow status at that moment, even with the gate off;
    - **Notifications**: opt-in emails for the five events.
  - Keep the 1.0 bullets (state machine, server-side enforcement, sidebar, classic meta box, dashboard) after these.
  - Do not mention CSV export, AI flagging or attestation; those are 1.2/1.3.

- [ ] **Step 3: Rewrite `= What it deliberately does not do =`**

  - Remove the `**It does not send email.**` bullet (line ~53).
  - Add: "**It does not detect AI-written text.**" (roadmap §3.3 rule; no claim of detection).
  - Add: "**It does not guard database writes made by other code.**" with one sentence that names direct `$wpdb` updates to `wp_posts`.

- [ ] **Step 4: FAQ**

  - Replace `= Does it send email when I assign a reviewer? =` with `= Does it send email? =`. The answer covers the five events, that they are off until turned on under Settings, who receives each, that nobody is emailed about their own action, and that comment text is included only for people allowed to read activity.
  - Add `= Can it stop unapproved content from being published? =`, covering:
    - the three modes in one sentence each;
    - "administrators can still publish, and that is recorded";
    - the covered-paths list (E3 rows in plain words: block editor, classic editor, Quick Edit, Bulk Edit, the REST API, WP-CLI, scheduled posts, other plugins calling WordPress's publishing functions);
    - the honest limit: "Code that writes `post_status` straight to the database is not covered. Imports run from WP-CLI need `--user=<an administrator>` when the gate is set to Enforce."
  - Add `= Will turning on the gate unpublish anything? =`: No. Live content is never taken down; content already scheduled is checked when its time comes.
  - Update `= Does reaching "Published" publish my post? =` to add: "With the publish gate on, it works the other way round too: content has to reach Approved before WordPress will publish it."
  - Add `= Who can approve content? =` (E16):
    - by default, Administrators and Editors; Authors and Contributors cannot;
    - approval is the WordPress capability `sit_cwm_approve_content`, so a role-editor plugin (e.g. Members or User Role Editor) can give it to or take it from any role;
    - choosing roles from this plugin's own settings is not part of the free plugin.
  - Add `= Can someone approve their own content? =` (E16): not when "Prevent people from approving their own content" is ticked under Settings → Publish gate. It applies to administrators too, who can still publish with the gate on, and that is recorded. Say plainly that "own" means the author set on the post.

- [ ] **Step 5: `== Privacy ==` and `== Screenshots ==`**

  - Privacy: add that when notifications are on, the plugin sends email to site users' registered addresses through WordPress's own `wp_mail()`; no external service is contacted. Keep the existing statements.
  - Screenshots: rewrite the caption list to match the shots phase 15 captures, in this order:
    1. the editor sidebar with the "Publishing is locked" notice;
    2. the activity timeline showing a publication at Review and a blocked publish;
    3. the settings screen with the Publish gate and Email notifications sections;
    4. the dashboard;
    5. the classic editor meta box.

- [ ] **Step 6: Developer docs**

  - `WORKFLOW.md`: the E1 mode table, the E4 rule, the carve-out, the bypass capability and its recording, the uncovered list, and a short "Self-approval" subsection (E16: the rule, that admins are bound, the `post_author` limit, the reviewer-list exclusion).
  - `ARCHITECTURE.md`:
    - a new "Publishing" section with the E3 table **including the R2 correction note** (why the `publish_post` meta cap alone is not enough), E5 and E6;
    - a "Notifications" section with E12 and E13;
    - E11 appended to the hooks list.
  - `REST-API.md`:
    - document `publish_gate` and `self_approval_blocked` on the workflow object, and the `sit_cwm_self_approval` (403) error on `POST …/workflow` and the batch route;
    - add `workflow_status`, `workflow_status_label` and `gate_reason` to the activity item, plus the four new `action` enum values;
    - add a short "Core routes" note: `wp/v2` create/update can return `sit_cwm_publish_gated` (403), and the `wp:action-publish` link is removed when the gate would refuse.
  - `DEVELOPMENT.md`: a "Publish gate E2E" subsection that points to phase 15's specs and the `SIT_CWM_E2E_CLI` variable.

- [ ] **Step 7: Regenerate translations**

  Run: `npm run makepot`.
  Expected: the `.pot` gains every E14 string, the mail copy (13) and the timeline sentences (04). Spot-check with `grep -c "Publishing is locked until this content is approved." languages/sapphireit-editorial-workflow.pot` → `1`.

- [ ] **Step 8: Validate**

  - Run `composer lint` (the docs don't affect it; confirms nothing else drifted).
  - Paste `readme.txt` into the WordPress.org readme validator (https://wordpress.org/plugins/developers/readme-validator/), or run Plugin Check if installed: `wp plugin check sapphireit-editorial-workflow --checks=plugin_readme`. Expected: no errors.
  - `grep -n "does not send email" readme.txt README.md` → no matches.
  - `git diff --stat -- readme.txt | tail -1`, and confirm by eye that `Stable tag: 1.0.0` and the Changelog section are unchanged.

- [ ] **Step 9: Hand off**

  Suggested message: `docs: rewrite listing around enforced review and document the publish gate honestly`.

## Acceptance criteria

- The listing leads with governance and no longer says it does not send email.
- The uncovered paths are stated in both the readme and `WORKFLOW.md` (roadmap A6).
- Every new REST field, error and hook is documented.
- The `.pot` is current.
- No version or changelog change.
