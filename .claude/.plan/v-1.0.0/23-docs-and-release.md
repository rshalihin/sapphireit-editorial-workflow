# 23 — Documentation and Free v1.0 release

**Goal:** a repository that reads as a portfolio piece — architecture explained,
API documented, screenshots included — and a shippable plugin zip.
**Prerequisites:** all previous steps.
**Dev plan reference:** Phase 24 + "Release Free v1.0".

## Files to create

```
README.md
ARCHITECTURE.md
WORKFLOW.md
REST-API.md
DEVELOPMENT.md
CHANGELOG.md
CONTRIBUTING.md
LICENSE                       GPL-2.0-or-later
readme.txt                    WordPress.org format
.distignore                   files excluded from the release zip
.github/workflows/release.yml build + zip on tag
docs/screenshots/*.png|gif
```

## 23.1 README.md

Lead with what it is and a screenshot/GIF of the sidebar, then the architecture
diagram from the dev plan:

```
React Gutenberg UI → REST API → PHP Workflow Engine
  → Permissions + Transition Rules → WordPress Data → Activity Log
```

Sections: Features · Screenshots (sidebar, dashboard, timeline, bulk actions) ·
Requirements (WP 6.8+, PHP 7.4+) · Installation · Quick start (the six statuses
and who can do what) · Architecture summary linking ARCHITECTURE.md ·
Extensibility (hooks table from D10) · Development · Roadmap (name the Pro
features as roadmap, not vapour) · License.

Keep it honest: v1.0 does not send email, does not publish automatically.

## 23.2 ARCHITECTURE.md

- The layer diagram and the rule that the UI never decides a transition.
- Why workflow status is separate from WP post status (D3) — the single most
  interesting design decision here; explain the scheduled-publishing scenario it
  protects.
- Class-by-class responsibilities and the dependency graph.
- The `transition()` pipeline, numbered, including the 409 concurrency guard.
- The separation of TransitionManager (structure) from PermissionManager
  (authorization) and why.
- Data model: meta keys, activity schema, indexes.
- Known trade-offs: non-transactional multi-field updates, no object caching,
  meta_query scaling — with the Pro follow-up for each.

## 23.3 WORKFLOW.md

The status graph, a table of who may reach each status, rollback paths, and
worked examples of each flow (approval, send-back, re-review).

## 23.4 REST-API.md

Every endpoint from D9: method, route, args with types and constraints, an
example request and response, and the complete error-code table with HTTP
statuses. Include `curl` examples with application-password auth so the API is
demonstrably usable without React.

## 23.5 DEVELOPMENT.md

`wp-env` setup, `composer install`, `npm ci`, build/watch commands, how to run
each test suite, coding standards commands, the performance budgets and measured
numbers from step 21, and the release process below.

## 23.6 readme.txt (WordPress.org)

Correct header fields (`Contributors`, `Tags`, `Requires at least`,
`Tested up to`, `Requires PHP`, `Stable tag`, `License`), short + long
description, Installation, FAQ, Screenshots, Changelog, Upgrade Notice.
`Stable tag` must match the plugin header version.

## 23.7 Release mechanics

1. Bump the version in exactly four places — main plugin header,
   `SIT_CWM_VERSION`, `package.json`, `readme.txt` `Stable tag` — and verify with
   a grep; a mismatch is the classic WP release bug.
2. `npm run build` (output in `assets/build/`, tracked in git).
3. `npm run makepot` to regenerate the POT.
4. Update CHANGELOG.md (Keep a Changelog format) — `## [1.0.0] - <date>`.
5. Full CI green on every matrix leg.
6. Build the zip excluding `.distignore` entries (`tests/`, `src/`, `node_modules/`,
   `vendor/` dev deps, `.github/`, `bin/`, `*.md` except readme.txt, `.claude/`).
7. Install the zip into a clean WP, activate, run the E2E flow manually once.
8. Tag `v1.0.0`, push, let `release.yml` attach the zip to the GitHub release.

## Acceptance criteria

- A developer who has never seen the repo can clone, `composer install && npm ci
  && npm run build && npm run env:start`, and reach a working workflow in under
  10 minutes following DEVELOPMENT.md.
- Every hook in D10 appears in the README extensibility table with a signature.
- The zip installs and activates cleanly on a fresh WP 6.8 and WP latest, with
  `WP_DEBUG` on and no notices.
- Version string identical in all four locations.
- Screenshots/GIFs exist for sidebar, dashboard, timeline and bulk actions.
