# 04 — Data model + activation

**Goal:** the activity table exists, versioned and upgradable; settings defaults
are seeded.
**Prerequisites:** 03. Implements decision **D8**.
**Dev plan reference:** Phase 2.

## Files to create

```
includes/Core/Database.php
includes/Core/Settings.php
tests/php/integration/DatabaseTest.php
```

## Tasks

1. **`Database::table_name(): string`** — `$wpdb->prefix . 'sit_cwm_activity'`.
   Every consumer calls this; the literal string appears nowhere else.
2. **`Database::install(): void`** — build the `CREATE TABLE` SQL per D8 with
   `$wpdb->get_charset_collate()` and run it through
   `dbDelta()` (`require_once ABSPATH . 'wp-admin/includes/upgrade.php'`).
   dbDelta formatting rules matter: two spaces after `PRIMARY KEY`, `KEY` not
   `INDEX`, one field per line, lowercase types.
   Indexes: `PRIMARY KEY (id)`, `KEY post_created (post_id, created_at)`,
   `KEY user_id (user_id)`, `KEY action (action)`.
3. **`Database::maybe_upgrade(): void`** — compare option `sit_cwm_db_version`
   against `SIT_CWM_DB_VERSION`; run `install()` and update the option when they
   differ. Hooked on `plugins_loaded` from `Plugin::boot()` so a plugin update
   via FTP/Git doesn't need reactivation.
4. **`Database::drop(): void`** — used only by `uninstall.php` and tests.
   `$wpdb->query( "DROP TABLE IF EXISTS {$table}" )` — table name comes from
   `table_name()`, never user input; add the phpcs
   `// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared` with a why-comment.
5. **`Settings`** — typed accessor over option `sit_cwm_settings`:
   ```php
   public function all(): array;
   public function get( string $key, $default = null );
   public function update( array $partial ): bool;   // merges, sanitizes, saves
   public function defaults(): array;                 // post_types, delete_data_on_uninstall
   ```
   Defaults per D7: `[ 'post_types' => [ 'post', 'page' ], 'delete_data_on_uninstall' => false ]`.
   Sanitize on write: post types filtered through `get_post_types( [ 'public' => true ] )`
   intersection; booleans cast with `rest_sanitize_boolean`.
6. **`Settings::enabled_post_types(): array`** — returns
   `apply_filters( 'sit_cwm_enabled_post_types', $types )`, and
   `is_post_type_enabled( string $post_type ): bool`.
7. Hook `Database::install()` + settings seeding into `Activator::activate()`.
8. **Multisite note:** v1.0 targets single site. In `Activator`, if
   `is_multisite() && $network_wide`, loop sites with `get_sites()` and run
   install per blog with `switch_to_blog()`/`restore_current_blog()`. Also hook
   `wp_initialize_site` so a newly created site gets the table.

## Acceptance criteria

- Fresh activation creates the table with exactly the D8 columns and indexes
  (`SHOW CREATE TABLE` assertion in the integration test).
- Running `install()` twice changes nothing (dbDelta idempotent).
- Bumping `SIT_CWM_DB_VERSION` triggers one upgrade run, then stops.
- `Settings::update()` rejects a non-existent post type and a non-array payload.
