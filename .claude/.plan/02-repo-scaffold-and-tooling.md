# 02 — Repo scaffold and tooling

**Goal:** an empty-but-correct plugin skeleton that lints and runs tests, so
every later step can end green.
**Prerequisites:** none.

## Files to create

```
content-workflow-manager.php      main plugin file (header + guard + bootstrap)
uninstall.php                     data removal on delete
composer.json                     PSR-4 autoload + dev tooling
phpcs.xml.dist                    WordPress ruleset config
phpunit.xml.dist                  test suite config
.gitignore                        vendor/, node_modules/, .DS_Store (NOT assets/build)
.editorconfig                     tabs for PHP, spaces for JS/JSON/MD
.wp-env.json                      local WP env for integration tests
includes/  admin/  src/  assets/  tests/php/  tests/js/  tests/e2e/   (+ .gitkeep)
```

## Tasks

1. **Main plugin file header.** Plugin Name `Content Workflow Manager`,
   Description, Version `1.0.0`, Author `Shappire IT`, License GPL-2.0-or-later,
   `Text Domain: sit-cwm`, `Domain Path: /languages`, `Requires at least: 6.5`,
   `Requires PHP: 7.4`. First statement after `<?php` docblock:
   `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
2. **Constants** (D1): `SIT_CWM_VERSION`, `SIT_CWM_PLUGIN_FILE`,
   `SIT_CWM_PLUGIN_DIR` (`plugin_dir_path`), `SIT_CWM_PLUGIN_URL`
   (`plugin_dir_url`), `SIT_CWM_DB_VERSION`. Guard each with `defined()`.
3. **composer.json**: PSR-4 `"Sit_Cwm\\": "includes/"`; require-dev
   `squizlabs/php_codesniffer`, `wp-coding-standards/wpcs`,
   `dealerdirect/phpcodesniffer-composer-installer`,
   `phpcompatibility/phpcompatibility-wp`, `yoast/phpunit-polyfills`,
   `wp-phpunit/wp-phpunit`. Scripts: `lint` → `phpcs`, `lint:fix` → `phpcbf`,
   `test` → `phpunit`.
4. **phpcs.xml.dist**: rules `WordPress`, `WordPress-Extra`,
   `WordPress-Docs`, `PHPCompatibilityWP` with `testVersion 7.4-`. Config
   `minimum_wp_version 6.5`. Set `text_domain` to `sit-cwm` and prefixes to
   `sit_cwm`/`Sit_Cwm`/`SIT_CWM` on the respective sniffs. Exclude exactly one
   sniff, with an XML comment citing decision D2: `WordPress.Files.FileName`.
   (`WordPress.NamingConventions.ValidClassName` no longer exists in WPCS 3.)
   Exclude paths: `vendor/`, `node_modules/`, `assets/build/`.
5. **phpunit.xml.dist**: bootstrap `tests/php/bootstrap.php`, two suites —
   `unit` (`tests/php/unit`, no WP) and `integration` (`tests/php/integration`,
   WP test library). Bootstrap loads the WP test lib only when `WP_TESTS_DIR`
   or `WP_PHPUNIT__TESTS_CONFIG` is set, so unit tests run anywhere.
   (`WP_PHPUNIT__DIR` is not usable as the signal: wp-phpunit's autoloaded file
   sets it unconditionally.) Local non-Docker runs (Laragon): copy
   `tests/php/wp-tests-config-sample.php` to `tests/php/wp-tests-config.php`
   (git-ignored, dedicated DB) and run `composer test:integration`.
6. **uninstall.php**: `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }`;
   delete option `sit_cwm_settings`, `sit_cwm_db_version`; drop the activity
   table; delete `_sit_cwm_*` meta; remove custom caps from all roles. Gate the
   destructive part behind `sit_cwm_settings['delete_data_on_uninstall']`
   (default `false`) — leave data alone unless the user opted in.
7. **.wp-env.json**: core `WordPress/WordPress#6.7`, plugin `.`, mappings for
   the tests dir. Document `npm run env:start` in step 23's DEVELOPMENT.md.

## Acceptance criteria

- `composer install && composer lint` → zero errors, zero warnings.
- `composer test` runs and reports 0 tests (no failures/errors).
- Activating the (still inert) plugin in `wp-env` produces no notices with
  `WP_DEBUG` and `WP_DEBUG_DISPLAY` on.
- `php -l` clean on every PHP file.
