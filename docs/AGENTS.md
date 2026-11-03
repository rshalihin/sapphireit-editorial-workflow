# Agents: submitting a draft for human review

A recipe for an AI agent, script or automation (n8n, Zapier-style tools, an MCP
client) that writes drafts and hands them to a person for approval. It uses
only the plugin's public REST API and WordPress core. Nothing extra is
installed.

Contents:

1. [What this is](#1-what-this-is)
2. [Set up](#2-set-up)
3. [The flow, as raw HTTP](#3-the-flow-as-raw-http)
4. [n8n](#4-n8n)
5. [MCP](#5-mcp)
6. [What gets recorded](#6-what-gets-recorded)
7. [Limits](#7-limits)

Full reference for every route and field: [REST-API.md](../REST-API.md).

---

## 1. What this is

An agent drafts the content and submits it for review. A person reviews and
approves it. The plugin records who approved what, and when.

The agent is an ordinary WordPress user, and the plugin checks it exactly like
one. It can never approve or publish unless its user holds the capabilities to
do so. So:

- **Give the agent its own user with the Author role.** Authors can move their
  own content through *Draft → Writing → Review*, but cannot approve
  (`sit_cwm_approve_content` belongs to Editors and Administrators).
- **Set the publish gate to *Enforce*** for the post types the agent writes
  (*Workflow → Settings → Publish gate*). An Author can normally publish its own
  posts in WordPress; with the gate in *Enforce*, publishing unapproved content
  is refused. With both in place, the agent cannot publish at all: only a
  person's approval opens the gate.

A dedicated user also means every row in the activity log names the agent,
not a person whose password it borrowed.

## 2. Set up

1. **Create the agent user.** *Users → Add New*, role **Author**. Use a name
   that reads well in the timeline, for example "Drafting agent".
2. **Create an Application Password** for it. Log in as an administrator, open
   the agent's profile (*Users → All Users → the agent → Edit*), scroll to
   **Application Passwords**, enter a name ("n8n", "MCP") and click *Add New
   Application Password*. Copy it now; WordPress shows it once. One password per
   tool lets you revoke one tool without breaking the others.
3. **Use HTTPS.** WordPress only offers Application Passwords over HTTPS (or on
   a site whose `WP_ENVIRONMENT_TYPE` is `local`). The password travels as HTTP
   Basic auth, so never send it over plain HTTP.
4. **Make sure the post type is in the workflow** (*Workflow → Settings →
   Content types*). Types outside the workflow return `404
   sit_cwm_not_managed` on every workflow route.

Optional: an Author cannot pick its reviewer (that needs
`sit_cwm_assign_reviewer`). To let the agent assign one, grant that single
capability to the agent user only, for example with WP-CLI:

```sh
wp user add-cap drafting-agent sit_cwm_assign_reviewer
```

## 3. The flow, as raw HTTP

Every example assumes these two variables. The application password may contain
spaces; keep them.

```sh
export CWM_SITE="https://example.com"
export CWM_AUTH="drafting-agent:abcd EFGH ijkl MNOP qrst UVWX"   # user:application-password
```

### Step 1: create the draft and declare AI use

One call to WordPress core creates the post and declares how it was made. The
`sit_cwm_ai_origin` field takes `human`, `ai_assisted` or `ai_generated`.

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"title":"Q4 product update","content":"<p>Draft text…</p>","status":"draft","sit_cwm_ai_origin":"ai_generated"}' \
  "$CWM_SITE/wp-json/wp/v2/posts" | jq '{id, status, sit_cwm_ai_origin}'
```

```json
{ "id": 131, "status": "draft", "sit_cwm_ai_origin": "ai_generated" }
```

Keep `id`. For pages or a custom post type, use that type's route
(`/wp-json/wp/v2/pages`, `/wp-json/wp/v2/{rest_base}`).

Check the HTTP status, not only the body. If the AI-use field is refused (for
example a user without `sit_cwm_change_workflow`), WordPress still creates the
post but answers with the error. That is how core handles extra fields. Look
the draft up or declare again on the workflow route (step 2 accepts
`"ai_origin"` too).

### Step 2: move it to Writing

New content starts in the workflow status `draft`. Every status change sends
`from`, the status the agent believes is current, and `status`, the one it
wants:

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"from":"draft","status":"writing"}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/131/workflow" | jq '.status'
```

```json
"writing"
```

### Step 3: submit it for review

```sh
curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"from":"writing","status":"review"}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/131/workflow" | jq '.status, .available_transitions'
```

```json
"review"
[]
```

`available_transitions` is empty: from Review, only a reviewer can move the
post on. The agent's part is done.

**Optional: assign a reviewer in the same call.** Only if the agent holds
`sit_cwm_assign_reviewer` (see [Set up](#2-set-up)). List who can review this
post, then send `reviewer_id` with the status change:

```sh
curl -sS -u "$CWM_AUTH" "$CWM_SITE/wp-json/sit-cwm/v1/users?post_id=131" | jq

curl -sS -u "$CWM_AUTH" -X POST \
  -H 'Content-Type: application/json' \
  -d '{"from":"writing","status":"review","reviewer_id":4}' \
  "$CWM_SITE/wp-json/sit-cwm/v1/posts/131/workflow"
```

### Step 4: wait for the decision

Poll the workflow route and read `status`:

```sh
curl -sS -u "$CWM_AUTH" "$CWM_SITE/wp-json/sit-cwm/v1/posts/131/workflow" | jq '.status'
```

| `status` | Meaning | What the agent does |
|---|---|---|
| `review` | Still waiting | Poll again later. Every few minutes is plenty. |
| `needs_changes` | The reviewer sent it back | Read the comments (`GET /wp-json/sit-cwm/v1/posts/131/activity`), revise the post through `wp/v2`, then send `{"from":"needs_changes","status":"writing"}` and repeat step 3 |
| `approved` | A person approved it | Done. Publishing is a person's decision unless your setup says otherwise. |
| `published` | It went live | Done. |

### Errors the agent must handle

| HTTP | Code | Meaning and response |
|---|---|---|
| 409 | `sit_cwm_status_conflict` | `from` no longer matches: someone moved the post meanwhile. Nothing was written. Re-read with `GET …/workflow` and decide again from the new `status`. Never retry blindly with the same `from`. |
| 400 | `sit_cwm_invalid_transition` | The step is not allowed from the current status (for example `draft → review`, skipping Writing). Re-read and follow the order. |
| 403 | `sit_cwm_forbidden` | The agent's user may not do this. Check its role and the post's author. Do not retry. |
| 404 | `sit_cwm_not_managed` | The post does not exist, is not readable by the agent, or its type is not in the workflow. |
| 4xx | anything else | A site rule (an add-on) refused the step. The `message` explains why, in plain text. Report it to a person; do not retry unchanged. The same reasons are listed in advance in the workflow route's `blocked_transitions`. |

See [REST-API.md → Errors](../REST-API.md#errors) for the full list.

---

## 4. n8n

The same three requests as **HTTP Request** nodes. Written for n8n 2.45.7
(2026-10-30); the nodes use HTTP Request version 4.2 and Edit Fields (Set)
version 3.4.

1. In n8n, create a credential of type **Basic Auth** named
   `WordPress drafting agent`. User: the agent's username. Password: its
   application password.
2. Copy the JSON below, then in a new n8n workflow press Ctrl+V (Cmd+V) on the
   canvas, or use *… → Import from File*.
3. Open each HTTP Request node and pick the credential if n8n did not match it
   by name.
4. In **Draft input**, set `site` to your site's address (`https://`, no
   trailing slash), and replace the title and content with your agent's
   output, usually by connecting the AI node in front of it.

The JSON holds no secrets: the credential is referenced by name only.

```json n8n
{
  "name": "Submit a draft for human review",
  "nodes": [
    {
      "parameters": {},
      "id": "6f1d3b2e-1a01-4a5e-9b11-000000000001",
      "name": "Start",
      "type": "n8n-nodes-base.manualTrigger",
      "typeVersion": 1,
      "position": [0, 0]
    },
    {
      "parameters": {
        "assignments": {
          "assignments": [
            { "id": "a1", "name": "site", "value": "https://example.com", "type": "string" },
            { "id": "a2", "name": "title", "value": "Q4 product update", "type": "string" },
            { "id": "a3", "name": "content", "value": "<p>Draft text from the agent.</p>", "type": "string" },
            { "id": "a4", "name": "ai_origin", "value": "ai_generated", "type": "string" }
          ]
        },
        "options": {}
      },
      "id": "6f1d3b2e-1a01-4a5e-9b11-000000000002",
      "name": "Draft input",
      "type": "n8n-nodes-base.set",
      "typeVersion": 3.4,
      "position": [220, 0]
    },
    {
      "parameters": {
        "method": "POST",
        "url": "={{ $json.site }}/wp-json/wp/v2/posts",
        "authentication": "genericCredentialType",
        "genericAuthType": "httpBasicAuth",
        "sendBody": true,
        "specifyBody": "json",
        "jsonBody": "={{ JSON.stringify({ title: $json.title, content: $json.content, status: 'draft', sit_cwm_ai_origin: $json.ai_origin }) }}",
        "options": {}
      },
      "id": "6f1d3b2e-1a01-4a5e-9b11-000000000003",
      "name": "Create draft",
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4.2,
      "position": [440, 0],
      "credentials": {
        "httpBasicAuth": { "name": "WordPress drafting agent" }
      }
    },
    {
      "parameters": {
        "method": "POST",
        "url": "={{ $('Draft input').item.json.site }}/wp-json/sit-cwm/v1/posts/{{ $('Create draft').item.json.id }}/workflow",
        "authentication": "genericCredentialType",
        "genericAuthType": "httpBasicAuth",
        "sendBody": true,
        "specifyBody": "json",
        "jsonBody": "{\"from\":\"draft\",\"status\":\"writing\"}",
        "options": {}
      },
      "id": "6f1d3b2e-1a01-4a5e-9b11-000000000004",
      "name": "Move to Writing",
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4.2,
      "position": [660, 0],
      "credentials": {
        "httpBasicAuth": { "name": "WordPress drafting agent" }
      }
    },
    {
      "parameters": {
        "method": "POST",
        "url": "={{ $('Draft input').item.json.site }}/wp-json/sit-cwm/v1/posts/{{ $('Create draft').item.json.id }}/workflow",
        "authentication": "genericCredentialType",
        "genericAuthType": "httpBasicAuth",
        "sendBody": true,
        "specifyBody": "json",
        "jsonBody": "{\"from\":\"writing\",\"status\":\"review\"}",
        "options": {}
      },
      "id": "6f1d3b2e-1a01-4a5e-9b11-000000000005",
      "name": "Submit for review",
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4.2,
      "position": [880, 0],
      "credentials": {
        "httpBasicAuth": { "name": "WordPress drafting agent" }
      }
    }
  ],
  "connections": {
    "Start": { "main": [[{ "node": "Draft input", "type": "main", "index": 0 }]] },
    "Draft input": { "main": [[{ "node": "Create draft", "type": "main", "index": 0 }]] },
    "Create draft": { "main": [[{ "node": "Move to Writing", "type": "main", "index": 0 }]] },
    "Move to Writing": { "main": [[{ "node": "Submit for review", "type": "main", "index": 0 }]] }
  },
  "settings": { "executionOrder": "v1" }
}
```

Any non-2xx answer stops the run with the plugin's error code and message
(see [Errors the agent must handle](#errors-the-agent-must-handle)). To wait
for the decision, add a *Schedule Trigger* workflow that calls `GET
…/sit-cwm/v1/posts/{id}/workflow` and branches on `status` with an *If* node.

---

## 5. MCP

An MCP-capable agent (Claude, an IDE assistant, a custom agent) makes the same
three requests through an MCP server that can send authenticated HTTP requests.

**State of the WordPress MCP Adapter, checked 2026-11-03.** The official
[MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin
([`WordPress/mcp-adapter`](https://github.com/WordPress/mcp-adapter)) is at
version 0.7.1 and needs WordPress 6.9. It exposes **abilities** registered
through the WordPress Abilities API, not arbitrary REST routes. This plugin
registers no abilities in 1.2, so the adapter cannot reach its workflow routes
today. Use an HTTP-capable MCP server instead, as below. The REST routes are
the stable contract either way: if a later release adds abilities, they will
call the same server-side checks.

### Configuration

Use any MCP server that offers an HTTP request tool with custom headers (many
general-purpose "fetch" or "HTTP" servers do). Pass the credential through the
environment, never in the prompt. The header value is
`Basic base64(username:application-password)`:

```sh
printf '%s' 'drafting-agent:abcd EFGH ijkl MNOP qrst UVWX' | base64
```

A typical client configuration (`mcpServers` in Claude Desktop, Claude Code's
`.mcp.json`, and similar). Replace the command with the HTTP server you
chose, following its own documentation for how it reads default headers:

```json
{
  "mcpServers": {
    "wordpress-http": {
      "command": "<your HTTP MCP server command>",
      "args": [],
      "env": {
        "WP_BASE_URL": "https://example.com",
        "WP_AUTH_HEADER": "Basic ZHJhZnRpbmctYWdlbnQ6YWJjZCBFRkdIIGlqa2wgTU5PUCBxcnN0IFVWV1g="
      }
    }
  }
}
```

### The calls the agent makes

Give the agent this as its tool instructions (the system prompt or a skill):

> To submit a draft for review on the WordPress site, with header
> `Authorization: $WP_AUTH_HEADER` and `Content-Type: application/json`:
>
> 1. `POST $WP_BASE_URL/wp-json/wp/v2/posts` with
>    `{"title": …, "content": …, "status": "draft", "sit_cwm_ai_origin": "ai_generated"}`.
>    Keep `id` from the response.
> 2. `POST $WP_BASE_URL/wp-json/sit-cwm/v1/posts/{id}/workflow` with
>    `{"from": "draft", "status": "writing"}`.
> 3. The same URL with `{"from": "writing", "status": "review"}`.
>
> Stop at Review: a person approves. On HTTP 409 (`sit_cwm_status_conflict`),
> `GET` the same workflow URL, read `status`, and decide again. On any other
> error, report the `message` to the user and do not retry unchanged. Declare
> `ai_assisted` instead when a person wrote most of the text.

No integration code is part of this plugin. The recipe is the deliverable.

---

## 6. What gets recorded

The agent's submission leaves these rows in the post's activity log, all
attributed to the agent's user:

| Row | Values | When |
|---|---|---|
| `ai_origin_changed` | `null → ai_generated`, `via=post_api` | Step 1 |
| `status_changed` | `draft → writing` | Step 2 |
| `status_changed` | `writing → review` | Step 3 |
| `reviewer_assigned` | the reviewer's user id | Step 3, only if sent |

Then, from the people involved:

- **The approval**, `status_changed` `review → approved`, attributed to the
  approver. When the site requires review attestation, the row also stores the
  statement the approver confirmed ("I confirm I have reviewed this content and
  approve it for publication.").
- **The publication**, `post_published`, with the workflow status, the publish
  gate mode and the declared AI use at that moment.

If the post type has an AI use default, it is **not** stamped on top of the
agent's declaration: the declaration sent on create wins.

Where to read it:

- **The timeline** in the editor's Workflow panel, and
  `GET /wp-json/sit-cwm/v1/posts/{id}/activity`.
- **CSV export** of the whole log, with `ai_origin`, `via`, `attested` and
  `attestation` columns: *Export activity log (CSV)* on the Workflow dashboard
  (administrators).
- **Governance report** (*Workflow → Governance report*): how much was
  published after approval, and declared AI use across all workflow content and
  across what was published in the period.

---

## 7. Limits

- **AI use is declared, not detected.** The plugin never inspects content. The
  value is what the agent (or a person) said. A wrong declaration is not caught,
  but it is visible: each change is a log row naming the user who made it and
  how it arrived (`via`). A person can correct it; the correction is logged too.
  A declaration can be changed but never withdrawn back to "Not declared".
- **The agent is only as limited as its user.** Give it the Author role. An
  Editor or Administrator agent can approve its own work (unless
  self-approval is prevented in *Workflow → Settings*) and, with the gate off,
  publish.
- **Direct database writes bypass everything.** Code that writes with `$wpdb`
  skips WordPress hooks, so the workflow, the publish gate and the activity log
  never see it. Agents that go through the REST API are always covered. See
  [ARCHITECTURE.md → trade-off 8](../ARCHITECTURE.md#8-the-publish-gate-only-sees-writes-that-pass-wordpress-hooks).
- **Polling is the only notification an agent gets.** Email notifications go
  to people, per the site's settings.
