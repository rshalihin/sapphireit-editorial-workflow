=== SapphireIT Editorial Workflow ===
Contributors: sapphireit
Tags: editorial workflow, approval workflow, content review, reviewer, publishing
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Editorial approval workflow with an enforceable publish gate and a complete audit trail. AI can draft it. A human approves it.

== Description ==

AI can draft it. A human approves it. You can prove who approved what, and
when.

SapphireIT Editorial Workflow puts a real review step between writing content
and publishing it, on the posts, pages and custom post types you already have:

* **Enforceable review.** A publish gate for each content type, with three
  modes. **Off** changes nothing. **Log only** lets people publish but records
  every publish that skipped approval. **Enforce** stops content from going live
  until it has been approved in the workflow. Every content type starts at Off.
* **A complete audit trail.** Every time managed content is published or
  unpublished, the plugin records who did it, when, and which workflow status
  the content was at in that moment. This happens even with the gate off.
* **Notifications.** Opt-in emails when a reviewer is assigned, when content is
  submitted for review, when changes are requested, when content is approved
  and when a workflow comment is added.

Every managed post gets a workflow status, an assigned reviewer, a due date,
workflow comments and a complete activity history, managed from a Gutenberg
sidebar, a classic editor meta box and an admin dashboard.

**Six statuses, one clear path:** Draft → Writing → Review → Needs Changes →
Approved → Published, with explicit paths for sending content back and for
re-editing something that is already live.

= What it does =

* **A real state machine.** Only the moves in the transition graph are possible.
  Nobody jumps a draft straight to approved, whatever their role.
* **Server-side enforcement.** Every transition is re-checked in PHP against the
  graph, the capability map and the post's current stored status. The editor UI
  shows you what you can do; it does not decide it. The publish gate works the
  same way: the editor offers **Submit for Review** instead of **Publish** when
  the gate would refuse, and the server refuses by the same rule whatever the
  client sends.
* **Seven dedicated capabilities**, granted to roles at activation and editable
  with any capability-management plugin. No hard-coded role checks.
* **Reviewer assignment and due dates**, with an "overdue only" filter computed
  in your site's timezone.
* **Workflow comments** that stay in the editorial log and never appear on the
  front end.
* **A complete activity history** in a dedicated indexed table: who changed
  what, when, including every publish, unpublish, blocked publish and publish
  without approval.
* **Conflict protection.** If two editors act on the same post at once, the
  second one is told the post moved instead of silently overwriting the first.
* **An admin dashboard** with server-side search, filtering, sorting and
  pagination, row actions, and bulk actions across up to 100 posts.
* **A documented REST API** under `sit-cwm/v1`. Everything the UI does is an
  endpoint you can call yourself.
* **Works with what you have.** Posts, pages and any custom post type, through
  post meta. No custom post type of its own, and nothing to migrate.

= What it deliberately does not do =

Being straight about this matters more than a longer feature list:

* **It does not publish anything.** Reaching the "Published" workflow status
  records that the editorial process finished — it does not touch the
  WordPress post status. Publishing stays a deliberate action by a human. The
  two are separate fields on purpose, so that scheduled posts keep their
  schedule and live content can be re-edited without being unpublished.
* **It does not detect AI-written text.** It records who approved content, not
  who or what wrote it.
* **It does not guard database writes made by other code.** Code that updates
  `post_status` in `wp_posts` directly through `$wpdb`, without WordPress's
  publishing functions, is not seen by the publish gate.
* No Slack, no editorial calendar, no multiple workflows, no rules engine. Those
  are on the roadmap, not hidden behind an upsell in this plugin.

= Who can do what =

* Draft, Writing, Review — `sit_cwm_change_workflow`
* Needs Changes — `sit_cwm_review_content`
* Approved — `sit_cwm_approve_content`
* Published — `sit_cwm_approve_content` plus WordPress `publish_post`
* Publish unapproved content while the gate is set to Enforce —
  `sit_cwm_bypass_publish_gate` (recorded every time it is used)

Every change also requires WordPress' own permission to edit that specific post.
By default administrators get everything, editors can review and approve,
authors and contributors can move their own content up to Review, and
subscribers get nothing. Only administrators can publish past the gate.

= Developers =

The plugin is built as a thin React client over a PHP workflow engine, and fires
actions and filters at every lifecycle point (`sit_cwm_status_changed`,
`sit_cwm_can_transition`, `sit_cwm_transition_map`,
`sit_cwm_publish_gate_passes`, `sit_cwm_notification_recipients` and more) so
it can be extended without editing its files.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/sapphireit-editorial-workflow/`, or
   install it through **Plugins → Add New → Upload Plugin**.
2. Activate it through the **Plugins** menu. Activation creates the activity
   table and grants the workflow capabilities to your existing roles.
3. Go to **Editorial Workflow → Settings** and choose which post types the
   workflow applies to. Posts and pages are enabled by default.
4. Open any post in the block editor and click the **Editorial Workflow** icon in
   the top-right toolbar. In the classic editor, use the **Editorial Workflow**
   box in the sidebar column instead.

Deactivating or deleting the plugin keeps your workflow data by default. To
remove everything when the plugin is deleted, tick **Delete all workflow data
when the plugin is deleted** under **Editorial Workflow → Settings** first;
deleting the plugin then removes the activity table, the plugin's post meta, its
options and its capabilities.

== Frequently Asked Questions ==

= Does reaching "Published" publish my post? =

No, and that is deliberate. The workflow status and the WordPress post status
are separate fields. Marking a post as Published in the workflow records that
the editorial process is complete; a human still hits Publish.

Keeping them apart is what lets a scheduled post keep its schedule while it is
being approved, and lets an already-live article go back through a round of
edits without disappearing from your site.

With the publish gate on, it works the other way round too: content has to
reach Approved before WordPress will publish it.

= Can it stop unapproved content from being published? =

Yes, once you turn it on. Under **Editorial Workflow → Settings → Publish
gate**, each content type has one of three modes:

* **Off**: publishing works as it always has. Publishing and unpublishing are
  still recorded in the activity history.
* **Log only**: anyone allowed to publish still can, but publishing content
  that has not been approved is recorded as a publish without approval.
* **Enforce**: content cannot be published or scheduled until it has reached
  Approved in the workflow. The editor offers Submit for Review instead of
  Publish, and blocked attempts on existing content are recorded.

Administrators can still publish, and that is recorded.

The gate covers the block editor, the classic editor, Quick Edit, Bulk Edit,
the REST API, WP-CLI, scheduled posts, and other plugins that publish through
WordPress's own publishing functions. A scheduled post is checked again when
its time comes, so content sent back for changes after it was scheduled does
not go live.

Code that writes `post_status` straight to the database is not covered. Imports
run from WP-CLI need `--user=<an administrator>` when the gate is set to
Enforce. Making content private is not gated.

= Will turning on the gate unpublish anything? =

No. Live content is never taken down: a published post that goes back through
review stays published while it is edited. Content already scheduled is
checked when its time comes; if it is not approved by then, it is put back to
draft and its author sees a notice explaining why.

= Does it send email? =

Only if you turn it on. Under **Editorial Workflow → Settings → Email
notifications** you can switch on email for five events. All five are off until
you turn them on.

* A reviewer is assigned: sent to the reviewer.
* Content is submitted for review: sent to the assigned reviewer, or to nobody
  if no reviewer is assigned.
* Changes are requested: sent to the author.
* Content is approved: sent to the author.
* A workflow comment is added: sent to the author and the reviewer.

Nobody is emailed about their own action, and nobody is emailed about content
they are not allowed to read. The text of a workflow comment is only included
for people allowed to read the workflow activity; everyone else is told that a
comment was added, with a link to the content. Each email is written in the
recipient's own language.

= Who can approve content? =

By default, Administrators and Editors. Authors and Contributors cannot.

Approving is the WordPress capability `sit_cwm_approve_content`, so a
role-editor plugin (for example Members or User Role Editor) can give it to, or
take it from, any role. For finer rules ("only the assigned reviewer may
approve", say), the `sit_cwm_can_transition` filter has the final word on every
decision. Choosing roles from this plugin's own settings is not part of the free
plugin.

= Can someone approve their own content? =

Not when "Prevent people from approving their own content" is ticked under
Settings → Publish gate. Then nobody can move content they wrote to Approved,
administrators included, and the author is not offered as its reviewer.
Someone else with approval rights has to approve it. With the gate set to
Enforce, administrators can still publish unapproved content, and that is
recorded.

"Own" means the author set on the post. Someone who rewrote a post they are not
the author of can still approve it.

The setting is off until you turn it on.

= Can I change the statuses or the order they go in? =

The `sit_cwm_statuses` and `sit_cwm_transition_map` filters replace the status
registry and the transition graph. Both results are validated, so a mistake in
your code cannot leave the plugin in a broken state. There is no settings screen
for editing statuses or building multiple workflows in the free plugin.

= Does it work with custom post types? =

Yes. Any editorial content type (one with an admin UI, REST API support and the
editor feature) can be enabled in the settings. Back-office types such as shop
orders and coupons are not offered; developers can change the list with the
`sit_cwm_available_post_types` filter. The plugin uses post meta, so there is
nothing to migrate and nothing to convert.

= Does it work with the classic editor? =

Yes. In the classic editor the workflow controls appear in an **Editorial
Workflow** box in the sidebar column. Changes there save immediately,
independently of the Update button.

= What happens to my data if I deactivate it? =

Deactivating changes nothing — your statuses, reviewers, due dates and history
stay. Deleting the plugin keeps them too, unless you have ticked **Delete all
workflow data when the plugin is deleted** under **Editorial Workflow →
Settings**. With that option on, deleting the plugin permanently removes the
activity table, workflow post meta, settings and capabilities.

= Where are the workflow comments? =

In the plugin's own activity table, shown in the timeline in the sidebar (or
the classic editor box). They are editorial notes, so they never appear on the front end and are not mixed in
with your readers' comments.

= Is there an API? =

Yes, at `/wp-json/sit-cwm/v1/`. Eight documented endpoints, all authenticated,
all with JSON schemas, and usable with application passwords.

= Does the plugin contact any external service? =

No. It makes no remote requests, loads nothing from a CDN, sets no cookies of
its own and collects no usage data. Everything stays in your WordPress
database. Email notifications, when you turn them on, go out through
WordPress's own `wp_mail()`, the same way your site sends password-reset
emails.

= Where is the source code for the JavaScript? =

The editor sidebar, classic editor box and dashboard ship as compiled files in
`assets/build/`. The human-readable React source is in the `src/` directory of
the public repository at
https://github.com/rshalihin/sapphireit-editorial-workflow. Run `npm install`
and then `npm run build` to rebuild the compiled files.

== Privacy ==

The plugin stores, in your own database: the user ID of whoever makes each
workflow change, the user ID of the assigned reviewer, the text of workflow
comments and the time of each action. It also stores the user ID of whoever
publishes or unpublishes managed content, or tries to publish it while the
publish gate blocks them. This information is visible to users who have the
`sit_cwm_view_activity` capability. Apart from the notification emails
described below, nothing is sent off your site.

When email notifications are turned on, the plugin sends email to the
registered email addresses of your site's users, through WordPress's own
`wp_mail()`. No external service is contacted by the plugin; mail is delivered
however your site already sends email.

== Screenshots ==

1. The editor sidebar with the "Publishing is locked" notice: the content is
   not approved yet, so the gate blocks publishing.
2. The activity timeline, showing content published while it was still at
   Review, and a publish the gate blocked.
3. The settings screen, with the Publish gate and Email notifications sections.
4. The admin dashboard, with server-side filtering, sorting and search.
5. The workflow box in the classic editor.

== Changelog ==

= 1.1.0 =
* New: publish gate per content type (Off, Log only, Enforce). In Enforce mode,
  content has to be approved in the workflow before it can be published or
  scheduled, from the block editor, classic editor, Quick Edit, Bulk Edit, the
  REST API, WP-CLI or the scheduler.
* New: administrators can still publish unapproved content in Enforce mode, and
  every such publish is recorded as a bypass.
* New: every publication and unpublication is logged in the activity history,
  with who did it, when, and the workflow status at the time, even with the
  gate off.
* New: optional email notifications when a reviewer is assigned, content is
  submitted for review, changes are requested, content is approved, or a
  workflow comment is added.
* New: optional setting to stop people approving their own content.
* New: Settings link on the Plugins screen.
* Fix: clicks on the due date, reviewer, comment and workflow buttons are no
  longer lost while another workflow change is still saving.
* Developer: new filters and actions for the publish gate, notifications and
  self-approval. See ARCHITECTURE.md in the public repository at
  https://github.com/rshalihin/sapphireit-editorial-workflow.

= 1.0.0 =
* Initial release.
* Six-status editorial workflow with a validated transition graph and rollback
  paths.
* Server-side transition and permission enforcement; the UI never decides.
* Six dedicated capabilities with sensible default role grants.
* Reviewer assignment, due dates and an overdue filter.
* Workflow comments and a full activity history in a dedicated indexed table.
* Optimistic concurrency: conflicting edits are rejected, not silently merged.
* Gutenberg sidebar with an activity timeline.
* Classic editor support: the same workflow controls in an Editorial Workflow
  meta box.
* Admin dashboard with server-side filtering, sorting, pagination, row actions
  and bulk actions.
* Settings screen for choosing workflow-enabled post types.
* REST API under `sit-cwm/v1`, with actions and filters for extending the
  plugin.

== Upgrade Notice ==

= 1.1.0 =
Adds an optional publish gate, publication logging, email notifications and a self-approval setting. All new options start off, so nothing changes until you turn them on.

= 1.0.0 =
First release. Activation creates the activity table and grants the workflow
capabilities to your existing roles; no existing content is modified.
