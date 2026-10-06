# Contributing

Thanks for looking. This document covers what a change needs to be mergeable.
[DEVELOPMENT.md](DEVELOPMENT.md) covers how to get the environment running;
[ARCHITECTURE.md](ARCHITECTURE.md) covers why the code is shaped the way it is.

## Before you start

- **Open an issue first** for anything beyond a bug fix. A feature that belongs
  in Pro scope (see the [roadmap](README.md#roadmap)) will not be merged into
  Free core, and it is better to find that out before writing it.
- The plugin follows a written build plan in `.claude/.plan/`. Step 01,
  `01-decisions-and-conventions.md`, freezes the naming and data-shape decisions
  every other file assumes. If your change has to deviate from it, **update that
  file in the same PR** — it is the single source of truth.

## Setup

```sh
composer install
npm ci
npm run build
```

See [DEVELOPMENT.md](DEVELOPMENT.md) for the wp-env and the Docker-free routes.

## Coding standards

### PHP

Everything must pass `phpcs` with the WordPress ruleset, zero errors and zero
warnings:

```sh
composer lint         # check
composer lint:fix     # fix what phpcbf can
```

Specifically:

- Tabs for indentation. One space inside parentheses: `if ( $x ) {`.
- Yoda conditions: `if ( true === $value )`.
- Full docblocks on every class, method and function, with `@since`, `@param`
  and `@return`.
- Every PHP file starts with `if ( ! defined( 'ABSPATH' ) ) { exit; }`
  immediately after `<?php`.
- `snake_case` methods and variables; `StudlyCase` class names in the `Sit_Cwm\`
  namespace, one class per file, path mirroring the namespace.
- PHP 7.4 target. CI runs 7.4, 8.1 and 8.3.
- Every user-facing string is translatable, text domain
  `sapphireit-editorial-workflow` (it must equal the plugin slug).

`WordPress.Files.FileName` is the only sniff excluded repo-wide (we use PSR-4
file names, decision D2). Inline `phpcs:ignore` is allowed only with a reason
comment and a specific sniff name.

### Naming

The code prefix is `sit_cwm` / `Sit_Cwm` / `SIT_CWM_` / `sit-cwm`, without
exception. The text domain is not a code prefix: WordPress.org requires it to
equal the plugin slug.

| Element | Convention |
|---|---|
| Functions, hooks | `sit_cwm_` |
| Classes | `Sit_Cwm\` namespace |
| Constants | `SIT_CWM_` |
| Text domain, plugin slug | `sapphireit-editorial-workflow` |
| Asset handles, CSS classes, DOM ids, admin page slugs | `sit-cwm` |
| REST namespace | `sit-cwm/v1` |
| Tables | `{$wpdb->prefix}sit_cwm_` |
| Post meta | `_sit_cwm_` |
| Capabilities, options, nonces | `sit_cwm_` |

### JavaScript

```sh
npm run lint:js
npm run lint:css
npm run format
```

`@wordpress/eslint-plugin` via the flat `eslint.config.js`, and `wp-prettier`
(aliased over stock Prettier in `package.json` — stock Prettier rejects
WordPress' spaces-inside-parentheses).

## The rules that are not negotiable

These come from the plugin's job — it decides who may approve and publish
content — and a PR that breaks one will be sent back even if everything else is
perfect.

1. **The UI never decides whether a transition is allowed.** Permission and
   transition logic lives in PHP. React reads `available_transitions` and
   `capabilities`; it never computes them, and the server never trusts them on
   the way back in.
2. **Every REST route has a real `permission_callback`.** No
   `'permission_callback' => '__return_true'`. Every argument gets a `type`, a
   `sanitize_callback`, and a `validate_callback` or `enum`.
3. **Authorization lives in `PermissionManager`.** Controllers and templates
   call into it; they do not call `current_user_can()` themselves. There is one
   documented exception (the "PHP too old" notice, which runs before the
   autoloader).
4. **No hard-coded role checks.** Role names appear in exactly one place,
   `Capabilities::role_map()`. Everything else checks a `sit_cwm_*` capability.
5. **Never trust client state.** A payload saying `{"status":"approved"}` is a
   request, not a fact. The server re-reads the stored status and re-derives the
   answer.
6. **Escape at output, sanitize at input**, matched to the data type. Use
   `$wpdb->prepare()` with placeholders for every query.
7. **Workflow status stays separate from WordPress post status.** Do not
   collapse them, even where they look redundant —
   [why](ARCHITECTURE.md#why-workflow-status-is-not-wordpress-post-status).
8. **Fire a hook at every lifecycle point**, so Pro and third-party code can
   extend without editing Free core. New hooks go in the README extensibility
   table with their signature.

## Tests

Add tests with the change, not afterwards.

```sh
composer test                                  # PHP unit (no WordPress)
composer test:setup && composer test:integration   # PHP integration
npm run test:unit                              # Jest
npm run env:start && npm run test:e2e          # Playwright
```

What a change needs:

| Change | Needs |
|---|---|
| Transition or permission logic | Unit tests covering allowed **and** denied paths |
| A REST route or argument | Integration tests: happy path, unauthenticated, unauthorized, invalid input |
| A React component or hook | Jest tests for loading, success and error states |
| A user-visible flow | An E2E spec, if it is not covered by an existing one |

`tests/php/integration/NegativeSuiteTest.php` is the security regression suite.
**Never skip or weaken it** — if a change makes it fail, the change is wrong
until proven otherwise.

The integration suite must pass in random order:

```sh
vendor/bin/phpunit --testsuite integration --order-by=random
```

## Committing

- `assets/build/` is committed (decision D11). Run `npm run build` and commit
  the output alongside any `src/` change — CI fails if a rebuild produces a
  diff.
- Regenerate the POT when you add or change a translatable string:
  `npm run makepot`.
- Commits are made by the repository owner. Contributors: small, focused
  commits with a clear subject line; no unrelated reformatting in the same
  commit.

## Pull requests

A PR should say:

- What it changes and why.
- Which plan step (`.claude/.plan/NN-*.md`) or issue it relates to.
- What you ran: `composer lint`, `composer test`, `composer test:integration`,
  `npm run test:unit`, and E2E if the change is user-visible — with the actual
  results, including anything you could not run.
- Any decision it changes in `01-decisions-and-conventions.md`.

CI must be green on every matrix leg (PHP 7.4/8.1/8.3 × WP 6.8/latest, plus the
JS and E2E jobs) before review.

## Security

Do not open a public issue for a security problem. Email the maintainer
privately with the details and a reproduction, and give a reasonable window for
a fix before disclosing.

## License

By contributing you agree your work is licensed under GPL-2.0-or-later, the same
as the plugin.
