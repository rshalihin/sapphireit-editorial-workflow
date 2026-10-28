# 01: Frozen decisions for 1.1.0

The single source of truth for every name, shape and rule the later phases assume. Change it here first, then in code. The 1.0 decisions in `../v-1.0.0/01-decisions-and-conventions.md` (D1–D11) stay in force. This file only adds to them (E1–E16) and lists the 1.0 decisions it amends.

---

## E1: Gate modes and storage

| Mode | Constant | Rule evaluated? | Publishing restricted? | What is recorded |
|---|---|---|---|---|
| `off` | `PublishGate::MODE_OFF` | no | no | nothing from the gate (publication is still logged, E6) |
| `log_only` | `PublishGate::MODE_LOG_ONLY` | yes | no | `publish_gate_bypassed`, reason `log_only` |
| `enforce` | `PublishGate::MODE_ENFORCE` | yes | yes | `publish_gate_refused`; or `publish_gate_bypassed`, reason `capability`, when the actor holds the bypass capability |

- Stored in the existing option `sit_cwm_settings`, under a new key `publish_gate`: `array<string post_type, string mode>`. Default `array()`.
- A missing key, an unknown mode, or a post type not currently workflow-enabled (`Settings::is_post_type_enabled()`) all resolve to `off`. Upgrading from 1.0.0 therefore needs no migration: `Settings::all()` already fills missing keys from defaults.
- Filter: `sit_cwm_publish_gate_mode( string $mode, string $post_type, int $post_id )`. `$post_id` is `0` when the question is not about one post (e.g. a brand-new post with no id yet). A filtered value not in `PublishGate::MODES` resolves to `off`.
- **Why `$post_id` now:** roadmap §3.2 adds a trailing `$post_id` to the registry filters in 1.2 so that multiple workflows become possible. A new 1.1 filter is born in that shape, so 1.2 does not have to change it after people depend on it (roadmap R4, §5 "Seam changes break third-party extensions later").
- **No per-request caching of the mode or of a decision unless it is keyed by post id.** The 1.2 section of the roadmap records why: with an unkeyed cache, every post after the first silently gets the first post's answer. `PublishGate` computes on every call; it is cheap (one autoloaded option read and a filter).

**Amendment to 1.0 decision D3, which said workflow status is never synced to post status:** still true. Publishing a post never changes `_sit_cwm_status`, and reaching workflow `published` never publishes the post. The developer decided this on 2026-10-28 ("keep separate").

## E2: The rule the gate evaluates

A publish attempt **passes** when the post's stored workflow status is in `PublishGate::PUBLISHABLE_STATUSES = array( 'approved', 'published' )`. An unknown stored status does not pass. A new post (id `0`) has the default status (`draft`), so it does not pass.

The outcome then goes through `sit_cwm_publish_gate_passes( bool $passes, int $post_id, string $workflow_status, string $mode )`. Only boolean `true` passes.

The filtered value may be `true`, `false` or a `WP_Error`. A `WP_Error` means "does not pass" and is treated exactly like `false` in 1.1; its message is not shown anywhere yet. This matches the `true|false|WP_Error` veto contract roadmap §3.2 gives `sit_cwm_can_transition` in 1.2, so a later release can show the message without changing the filter. The filter exists so a later checklist or multi-workflow add-on can tighten or redefine "approved".

## E3: Enforcement layers (**corrects roadmap R2**)

Verified against WordPress 7.1.3 core on 2026-10-28:
- `WP_REST_Posts_Controller::handle_status_param()` (`:1586`) and `get_available_actions()` (`:2352`), which builds the `wp:action-publish` link the block editor reads, check the **post-type primitive** `$post_type->cap->publish_posts`. No post id is passed, so the `map_meta_cap` filter for `publish_post` never runs there.
- `_wp_translate_postdata()` (`wp-admin/includes/post.php:154`), used by the classic editor, Quick Edit and Bulk Edit, does the same.

A `map_meta_cap` filter on `publish_post` alone, which is the roadmap's "primary mechanism", would therefore block nothing in the block editor, REST or the classic editor. The roadmap's *intent* holds: the editor adapts its own UI and REST refuses by the same rule. It is achieved like this:

| Layer | Class | Hook (priority) | Covers |
|---|---|---|---|
| A-link | `Layers\RestLayer` | `rest_prepare_{post_type}` (10) | Removes `wp:action-publish` when the gate would refuse, so the block editor shows **Submit for Review** with no JS from us |
| A-rest | `Layers\RestLayer` | `rest_pre_insert_{post_type}` (10) | `wp/v2` create/update to `publish`/`future` → `WP_Error` `sit_cwm_publish_gated`, HTTP 403 |
| A-cap | `Layers\RestLayer` | `map_meta_cap` (10) | Any code that checks `current_user_can( 'publish_post', $id )` |
| B | `Layers\SaveLayer` | `wp_insert_post_data` (10) | Classic editor, Quick Edit, Bulk Edit, `wp_insert_post()` / `wp_update_post()` from anywhere (incl. WP-CLI `wp post create/update`) |
| C | `Layers\ScheduleLayer` | `publish_future_post` (5) | Scheduled-publish cron, **before** core's `check_and_publish_future_post()` (10), so a refused post never goes live |
| Backstop | `Layers\ScheduleLayer` | `transition_post_status` (20) | Anything else that makes a post `publish` without passing B or C, e.g. a direct `wp_publish_post()` on a draft. It reverts after the fact |

Not covered, and documented as such (roadmap A6): direct `$wpdb` writes to `wp_posts.post_status`, and anything that removes the plugin's hooks.

## E4: When a layer judges a write ("transition into published")

A layer judges only when **all** of these hold:
1. The post type is workflow-enabled and its mode is not `off`.
2. One of these is true:
   - the target status is `publish` and the stored status is not `publish`;
   - the target status is `future` and the stored status is neither `publish` nor `future`.
3. The write is not an autosave or revision.

Consequences:
- A live post is never demoted by the gate. A live post in re-review stays `publish` (roadmap A3, the carve-out).
- An already-scheduled post is not re-judged on unrelated edits. It is judged at go-live by Layer C.
- `private` is not gated.

## E5: Refusal handling per layer

| Layer | On refusal |
|---|---|
| A-link | link removed (no record: this is display, not an attempt) |
| A-rest | return `WP_Error( 'sit_cwm_publish_gated', <E14 message>, array( 'status' => 403 ) )`; record refusal if the post exists |
| A-cap | append `do_not_allow` (no record) |
| B | set `post_status` to the stored status if that is `draft` or `pending`, else `draft`; record refusal; queue admin notice for the actor |
| C | `wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) )` while the ledger is reverting; record refusal (user 0); queue admin notice for the post author |
| Backstop | revert to `draft` while reverting; record refusal; queue admin notice for the actor, or the author if the actor is 0 |

**Bypass recording rule:** a bypass is recorded when the post actually goes **live** (`publish`), never when it is merely scheduled. When a bypass-holder schedules a post that fails the rule in `enforce` mode, their user id is stored in `_sit_cwm_publish_bypass_by` (E8). At go-live, Layer C honours it only if that user still holds `sit_cwm_bypass_publish_gate`. The meta is deleted whenever the post leaves `future`.

**Fail open (roadmap A4):** every layer callback body runs inside `try { … } catch ( Throwable $e )`. On an exception the layer returns its input unchanged (the publish proceeds) and calls `GateRecorder::failed_open( $layer, $post_id, $e )`. That method:
- best-effort writes `publish_gate_bypassed`, reason `error`, when the post id is known;
- fires `sit_cwm_publish_gate_failed( string $layer, Throwable $error, int $post_id )`;
- calls `error_log()` when `WP_DEBUG` is true.

The recorder's own work is wrapped too, so a failing recorder never blocks.

**Who is judged:**
- the current user (`get_current_user_id()`) everywhere except Layer C;
- user `0` (no bypass) in Layer C, unless E8's meta applies;
- WP-CLI with no `--user` and cron imports run as user 0, so in `enforce` mode they are refused.

This is intended and documented: "run imports as a user with the bypass capability (`wp --user=<admin>`)".

## E6: Publication logging (roadmap B)

`Publishing\PublicationLogger` on `transition_post_status` (10), for workflow-enabled post types, **in every gate mode including `off`**:
- into `publish` (`new === 'publish'`, `old !== 'publish'`) → `post_published`;
- out of `publish` (`old === 'publish'`, `new !== 'publish'`, including `trash`) → `post_unpublished`.

Hook order on `transition_post_status`:

| Priority | Callback | Why |
|---|---|---|
| 5 | `GateRecorder::release_pending` | Records a held new-post decision before the publication row |
| 10 | `PublicationLogger::handle_transition` | |
| 20 | `ScheduleLayer::backstop` | Its revert nests a `publish → draft` transition, so it must run after the logger. The log then reads published → refused → unpublished, in that order |
| 30 | `ScheduleLayer::forget_schedule_bypass` | |

## E7: Activity action shapes (amends D8)

D8's closed action set gains four slugs: `ActivityLogger::PUBLICATION_ACTIONS`. There is no schema change: `action` is `varchar(50)`.

| Action | `old_value` | `new_value` | `context` keys |
|---|---|---|---|
| `post_published` | previous post status (`new`, `auto-draft`, `draft`, `pending`, `future`, `private`) | `publish` | `workflow_status`, `gate_mode`, `source` |
| `post_unpublished` | `publish` | new post status | `workflow_status`, `gate_mode`, `source` |
| `publish_gate_refused` | stored post status | attempted (`publish`/`future`) | `workflow_status`, `gate_mode`, `layer`, `source` |
| `publish_gate_bypassed` | stored post status | `publish` | `workflow_status`, `gate_mode`, `layer`, `source`, `reason` (`log_only`/`capability`/`error`) |

- `source` comes from `RequestSource::detect()`: one of `cron`, `cli`, `rest`, `ajax`, `admin`, `other`, checked in that order.
- `layer` ∈ `rest`, `save`, `schedule`, `backstop`.
- A `publish_gate_bypassed` row with reason `error` (written by `GateRecorder::failed_open()`) has no `gate_mode`: the recorder does not depend on the gate, and resolving the mode may be what failed. Its `workflow_status` is read straight from post meta and is omitted when that read fails too (phase 07).
- `user_id` is the acting user; `0` for cron and for CLI without `--user`.

REST activity items (ActivityFormatter) gain three nullable fields on **every** item, `null` for the 1.0 actions:
- `workflow_status` (slug);
- `workflow_status_label`;
- `gate_reason`.

For the four new actions, `old_label`/`new_label` are post status labels. `new` and `auto-draft` are labelled "New".

## E8: New post meta (amends D6)

| Key | Constant | Type | REST | Auth | Purpose |
|---|---|---|---|---|---|
| `_sit_cwm_publish_bypass_by` | `PostRepository::META_PUBLISH_BYPASS` | integer | `show_in_rest => false` | always false | User id that scheduled a failing post under the bypass capability (E5) |

It is registered in `PostMeta::register_for_post_type()` and added to the `map_meta_cap` lock alongside `_sit_cwm_status` (`PostMeta::LOCKED_META_KEYS`). Uninstall already deletes `_sit_cwm_%`.

## E9: Bypass capability (amends D5)

- `Capabilities::BYPASS_PUBLISH_GATE = 'sit_cwm_bypass_publish_gate'`, included in `Capabilities::all()`, so administrators get it through `role_map()`.
- No other role is granted it.
- Checked only through `PermissionManager::can_bypass_publish_gate( ?int $user_id = null ): bool`.
- Multisite super admins pass it implicitly (core behaviour).

Upgrade without reactivation:
- `Capabilities::REVISION = 2` (1.0 is revision 1), stored in option `sit_cwm_caps_revision` (not autoloaded).
- `Capabilities::maybe_upgrade()` runs on `plugins_loaded` (20). For every revision above the stored one, it applies only that revision's grants from `Capabilities::revision_grants()`, which is `array( 2 => array( 'administrator' => array( BYPASS_PUBLISH_GATE ) ) )`. It never re-runs `add_caps()`, so caps a site owner removed are not restored.
- Activation writes the current revision after `add_caps()`.
- Uninstall deletes the option.

## E10: Gate state in the workflow REST response (amends D9)

`GET/POST /sit-cwm/v1/posts/{id}/workflow` gains a `publish_gate` object: `{ mode: 'off'|'log_only'|'enforce', outcome: 'not_applicable'|'allowed'|'bypassed'|'refused' }`.
- It is computed for the current user by `PublishGate::describe( int $post_id, int $user_id ): array`.
- It is `not_applicable` when the post is already `publish`/`future` (E4) or the mode is `off`.
- It is a UI hint only; the layers re-decide.

New REST error code: `sit_cwm_publish_gated` (403), returned by core's `wp/v2` routes via Layer A-rest.

## E11: New hooks (amends D10)

| Kind | Name | Args |
|---|---|---|
| filter | `sit_cwm_publish_gate_mode` | `string $mode, string $post_type, int $post_id` (`0` when not post-specific; E1) |
| filter | `sit_cwm_publish_gate_passes` | `bool $passes, int $post_id, string $workflow_status, string $mode`; returns `bool\|WP_Error` (E2) |
| action | `sit_cwm_publish_gate_refused` | `int $post_id, GateDecision $decision, int $user_id, string $layer` |
| action | `sit_cwm_publish_gate_bypassed` | `int $post_id, GateDecision $decision, int $user_id, string $layer` |
| action | `sit_cwm_publish_gate_failed` | `string $layer, Throwable $error, int $post_id` |
| filter | `sit_cwm_notification_recipients` | `int[] $user_ids, string $event, int $post_id, int $actor_id` |
| filter | `sit_cwm_notification_message` | `array{subject: string, body: string} $message, string $event, int $post_id, int $recipient_id` |
| action | `sit_cwm_notification_sent` | `string $event, int $post_id, int $recipient_id, bool $sent` |
| filter | `sit_cwm_prevent_self_approval` | `bool $prevent, int $post_id` (E16) |

Publication rows fire the existing `sit_cwm_activity_logged`. No separate publication action is added.

## E12: Email notifications (roadmap C)

**Defaults:** every event is **off** on fresh installs and upgrades alike. The roadmap sets no default, so this applies its only stated upgrade rule (§3.1A: "no existing install changes behaviour on upgrade"). The developer asked for "as the roadmap" on 2026-10-28. To flip the default for fresh installs later, change `Settings::defaults()` only.

Stored in `sit_cwm_settings` under `notifications`: `array<string event, bool>`. Default: every key in `NotificationEvents::all()` set to `false`.

| Event key | Trigger | Recipients (before E13 filtering) |
|---|---|---|
| `reviewer_assigned` | `sit_cwm_reviewer_assigned` with `$reviewer_id > 0` | the new reviewer |
| `review_requested` | `sit_cwm_status_changed` with `$to === 'review'` | the assigned reviewer; **nobody** when none is assigned (developer decision 2026-10-28) |
| `changes_requested` | `sit_cwm_status_changed` with `$to === 'needs_changes'` | the post author |
| `approved` | `sit_cwm_status_changed` with `$to === 'approved'` | the post author |
| `comment_added` | `sit_cwm_comment_added` | the post author and the assigned reviewer |

## E13: Recipient and content rules

- Recipients are filtered in this order:
  1. `sit_cwm_notification_recipients`;
  2. cast to positive ints and de-duplicated;
  3. the actor is removed;
  4. users that do not exist or have an empty or invalid `user_email` are removed;
  5. users failing `PermissionManager::can_read_post( $post_id, $recipient )` are removed.

  So the filter can add people but cannot add people who may not see the post.
- **Exactly one mail per recipient per event.**
- Comment bodies appear in a mail only when `PermissionManager::can_view_activity( $post_id, $recipient )` is true. Otherwise the mail says a comment was added and links to the post.
- Mail is plain text via `wp_mail()`. Subject and body are built inside `switch_to_user_locale( $recipient_id )` / `restore_previous_locale()`, so they are in the recipient's language.
- **Dedup:** `Deduplicator::claim()` sets a transient `sit_cwm_mail_` . `md5( "$event|$post_id|$recipient_id|$fingerprint" )` for `Deduplicator::WINDOW = 120` seconds. A claim that finds the transient returns false and nothing is sent. Fingerprints:

  | Event type | Fingerprint |
  |---|---|
  | status events | `$to` |
  | `reviewer_assigned` | the reviewer id |
  | `comment_added` | `md5( $message )` |

## E14: User-facing copy (exact strings, text domain `sapphireit-editorial-workflow`)

| Where | String |
|---|---|
| `sit_cwm_publish_gated` message | `This content has to be approved in the editorial workflow before it can be published.` |
| Admin refusal notice | `“%1$s” was not published: it has to be approved in the editorial workflow first. It was saved as %2$s.` (1: post title, 2: post status label) |
| Panel, `enforce` + `refused` | `Publishing is locked until this content is approved.` |
| Panel, `log_only` + `bypassed` | `This content is not approved yet. Publishing it now will be recorded as a bypass of the review.` |
| Panel, `enforce` + `bypassed` | `This content is not approved yet. You can still publish it, and that will be recorded as a bypass of the review.` |
| Activity labels | `post_published` → `Published`; `post_unpublished` → `Unpublished`; `publish_gate_refused` → `Publishing blocked`; `publish_gate_bypassed` → `Published without approval` |
| Settings section | title `Publish gate`; mode labels `Off`, `Log only`, `Enforce` |
| Settings section | title `Email notifications` |
| Event labels | `reviewer_assigned` → `A reviewer is assigned (sent to the reviewer)`; `review_requested` → `Content is submitted for review (sent to the assigned reviewer)`; `changes_requested` → `Changes are requested (sent to the author)`; `approved` → `Content is approved (sent to the author)`; `comment_added` → `A workflow comment is added (sent to the author and the reviewer)` |
| Mail subjects (1: site name, 2: post title) | `[%1$s] Review assigned: %2$s`; `[%1$s] Ready for review: %2$s`; `[%1$s] Changes requested: %2$s`; `[%1$s] Approved: %2$s`; `[%1$s] New comment: %2$s` |
| Self-approval setting (E16) | field title `Self-approval`; checkbox label `Prevent people from approving their own content`; description `When this is on, nobody can move content they wrote to Approved, administrators included. Someone else with approval rights has to approve it. “Wrote” means the author set on the post.` |
| `sit_cwm_self_approval` message | `You can't approve content you wrote. Another reviewer has to approve it.` |
| Panel, `self_approval_blocked` | `You can't approve your own content. Another reviewer has to approve it.` |
| Reviewer refused as the post's author | `The author can't review their own content while self-approval is prevented.` |

Mail body copy is fixed in phase 13.

## E15: Out-of-band decision: dashboard design

A branded dashboard redesign (the "Smart Brands Pro"-style look) is **not** in 1.1. It belongs in **Free 1.3**, together with the governance report screen, which is the first new screen with KPI cards. Pro's compliance reporting then inherits it. In 1.1, new settings use the plain Settings API and the editor notice uses `@wordpress/components` `Notice`, so a later restyle is cosmetic only.

## E16: Prevent self-approval (developer addition, not in the roadmap)

Added by the developer on 2026-10-28. Without it, an editor can approve their own post and then publish it, even in `enforce` mode.

- **Setting:** `prevent_self_approval` in `sit_cwm_settings`, bool, default `false` on fresh installs and upgrades (same rule as E1 and E12). Shown as a checkbox in the Publish gate section of the settings screen, but it works in every gate mode.
- **Rule:** when on, nobody may move a post to `approved` if they are its `post_author`. It is checked in `PermissionManager::can_change_status()` before `sit_cwm_can_transition`, so every route is covered: sidebar, classic meta box, dashboard bulk action, `sit-cwm/v1` REST and batch. Only the target `approved` is restricted.
- **Administrators are bound too.** In `enforce` mode they can still publish unapproved content through `sit_cwm_bypass_publish_gate`, which is recorded (E5), so a one-person site is never stuck.
- **"Author" means `post_author`.** Someone who rewrote a post they are not the author of is not caught. This limit is documented in 14.
- **Reviewer assignment:** while the rule is on, the post's author is left out of the assignable-reviewer list and cannot be assigned as reviewer of their own post (`sit_cwm_invalid_user`, 400). Existing assignments are kept; such a reviewer just cannot approve.
- **Errors and hints:** a refused self-approval returns `sit_cwm_self_approval` (403) instead of the generic `sit_cwm_forbidden`, under the same condition as the hint below (an author who lacks `sit_cwm_approve_content` anyway still gets `sit_cwm_forbidden`). The workflow REST payload gains `self_approval_blocked: bool`, true only when the rule is the actual reason Approve is not offered. The panel then shows one sentence (E14).
- **Filter:** `sit_cwm_prevent_self_approval( bool $prevent, int $post_id )`, so a later add-on can vary the rule per post (e.g. per workflow). Only boolean `true` prevents.
- **Choosing which roles may approve is Pro** (developer decision 2026-10-28, matching CLAUDE.md's "role-based workflow configuration" boundary). 1.1 does not add a role setting. 14 documents that `sit_cwm_approve_content` is a normal WordPress capability that a role-editor plugin can grant or remove.

**Amends 1.0 decision D5** (capabilities and roles): approving now also depends on not being the author, when the setting is on.

## New files and services (the map every phase refers to)

```
includes/Publishing/PublishGate.php            service id: publish_gate
includes/Publishing/GateDecision.php           value object
includes/Publishing/GateLedger.php             service id: publish_gate.ledger
includes/Publishing/GateRecorder.php           service id: publish_gate.recorder      (Bootable, core)
includes/Publishing/RefusalNotices.php         service id: publish_gate.notices       (Bootable, core)
includes/Publishing/PublicationLogger.php      service id: publication_logger         (Bootable, core)
includes/Publishing/RequestSource.php          static helper
includes/Publishing/Layers/SaveLayer.php       service id: publish_gate.layer.save    (Bootable, core)
includes/Publishing/Layers/RestLayer.php       service id: publish_gate.layer.rest    (Bootable, core)
includes/Publishing/Layers/ScheduleLayer.php   service id: publish_gate.layer.schedule (Bootable, core)
includes/Notifications/NotificationEvents.php  constants + labels
includes/Notifications/RecipientResolver.php   service id: notifications.recipients
includes/Notifications/MessageBuilder.php      service id: notifications.messages
includes/Notifications/Deduplicator.php        service id: notifications.dedup
includes/Notifications/Notifier.php            service id: notifications              (Bootable, core)
src/components/PublishGateNotice.jsx
src/hooks/useRefreshEditorPost.js
```

All `core`-phase services are appended to `Plugin::BOOT_PHASES['core']` **after** `workflow_manager`, in the order listed above.
