# 13 — JS build setup

**Goal:** `@wordpress/scripts` builds two entry points with correct dependency
extraction, and lint/format/test commands exist before any component is written.
**Prerequisites:** 02.
**Dev plan reference:** Phase 6 groundwork.

## Files to create

```
package.json
eslint.config.js        flat config spreading @wordpress/scripts' default (ESLint 10
                        dropped .eslintrc.*; see D11)
.prettierrc.js          require( '@wordpress/prettier-config' )
jest.config.js          (or the wp-scripts default + setup file)
src/sidebar/index.js
src/dashboard/index.js
tests/js/setup.js
```

## Tasks

1. **package.json**: name `sit-cwm`, version `1.0.0` (kept in lockstep with the
   PHP header, D11), `"private": true`.
   devDependencies: `@wordpress/scripts`, `@wordpress/env`,
   `@wordpress/dataviews`, `@wordpress/components`, `@wordpress/data`,
   `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/element`,
   `@testing-library/react`, `@testing-library/user-event`.
   Scripts:
   ```json
   "build":    "wp-scripts build",
   "start":    "wp-scripts start",
   "lint:js":  "wp-scripts lint-js",
   "lint:css": "wp-scripts lint-style",
   "format":   "wp-scripts format",
   "test:unit":"wp-scripts test-unit-js",
   "test:e2e": "wp-scripts test-playwright",
   "env:start":"wp-env start",
   "makepot":  "wp i18n make-pot . languages/sit-cwm.pot --domain=sit-cwm"
   ```
2. **Two entry points.** Default `wp-scripts` multi-entry via
   `src/sidebar/index.js` and `src/dashboard/index.js` (add a minimal
   `webpack.config.js` extending `@wordpress/scripts/config/webpack.config` that
   sets `entry: { sidebar: …, dashboard: … }` and
   `output.path: assets/build`). Keep the default
   `DependencyExtractionWebpackPlugin` — it emits `*.asset.php`.
3. **PHP enqueues read the asset files** — never hand-maintain dependency
   arrays:
   ```php
   $asset = require SIT_CWM_PLUGIN_DIR . 'assets/build/sidebar.asset.php';
   wp_enqueue_script( 'sit-cwm-sidebar-js', SIT_CWM_PLUGIN_URL . 'assets/build/sidebar.js', $asset['dependencies'], $asset['version'], true );
   wp_set_script_translations( 'sit-cwm-sidebar-js', 'sit-cwm', SIT_CWM_PLUGIN_DIR . 'languages' );
   ```
   Handles follow D1: `sit-cwm-sidebar-js`, `sit-cwm-sidebar-css`,
   `sit-cwm-dashboard-js`, `sit-cwm-dashboard-css`.
4. **`@wordpress/dataviews` is not bundled in core's script registry** for all
   target versions — import it as an npm dependency so webpack bundles it, and
   verify the built dashboard chunk does not try to `wp.dataviews`. Confirm the
   final bundle size is reasonable (< ~300 KB gzipped) and note it in
   DEVELOPMENT.md.
5. **Keep `assets/build/` tracked in git** (D11) so the plugin runs from a clone/zip without
   a build step. Add a note in `.gitignore` explaining the deliberate exception.
6. **Localized bootstrap data** via `wp_add_inline_script` with a single global
   `window.sitCwm = { restNamespace, statuses, capabilities, postTypes, adminUrl }`
   — JSON-encoded with `wp_json_encode()`. No `wp_localize_script` (it string-
   casts everything). No secrets in it.

## Acceptance criteria

- `npm run build` produces `assets/build/{sidebar,dashboard}.{js,asset.php}`
  and `.css` when styles exist.
- `npm run lint:js` clean on the stub entries.
- `npm run test:unit` runs (0 tests) without config errors.
- Loading the block editor with the stub sidebar produces no console errors and
  no jQuery/`wp.*` undefined warnings.
