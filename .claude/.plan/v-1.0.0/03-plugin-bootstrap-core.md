# 03 — Plugin bootstrap / Core

**Goal:** a wired-up service container that instantiates and boots subsystems in
a defined order, plus activation/deactivation lifecycle.
**Prerequisites:** 02.

## Files to create

```
includes/Core/Plugin.php
includes/Core/Container.php
includes/Core/Activator.php
includes/Core/Deactivator.php
includes/Core/Interfaces/Bootable.php
```

## Tasks

1. **`Bootable` interface** — single method `register(): void`, where a service
   attaches its WordPress hooks. Nothing hooks WordPress in a constructor; that
   keeps every class constructible in unit tests without WP loaded.
2. **`Container`** — a tiny service locator: `set( string $id, callable $factory )`,
   `get( string $id )` with lazy instantiation + memoisation, `has()`.
   No third-party DI package; ~60 lines. This satisfies CLAUDE.md's
   "dependency injection over static calls / globals".
3. **`Plugin`** — singleton-free: `Plugin::__construct( Container $container )`,
   `boot()` which:
   - registers every service factory (wiring table below),
   - calls `register()` on each `Bootable` at the right hook
     (`init` for meta/caps/status registration, `rest_api_init` for controllers,
     `admin_menu`/`admin_enqueue_scripts` for admin, `enqueue_block_editor_assets`
     for the sidebar),
   - loads the text domain on `init` (`load_plugin_textdomain( 'sit-cwm', false, dirname( plugin_basename( SIT_CWM_PLUGIN_FILE ) ) . '/languages' )`),
   - runs the DB upgrade check on `plugins_loaded` (step 04).
4. **Wiring table** (fill in as later steps land; each entry is a factory
   closure receiving the container):
   `status_manager`, `transition_manager`, `permission_manager`,
   `post_meta`, `post_repository`, `activity_logger`, `workflow_manager`,
   `rest.workflow`, `rest.activity`, `rest.users`, `admin.dashboard`,
   `admin.settings`, `editor.sidebar`.
5. **`Activator::activate()`** — create/upgrade the activity table (step 04),
   add capabilities to roles (step 07), seed `sit_cwm_settings` defaults if the
   option is absent, store `sit_cwm_db_version`, `flush_rewrite_rules()` last.
   Must be idempotent — safe to run on every reactivation.
6. **`Deactivator::deactivate()`** — clear scheduled events (none yet) and flush
   rewrite rules. **Do not** drop tables, delete meta, or remove capabilities —
   that belongs to `uninstall.php` only.
7. **Main file bootstrap** — register
   `register_activation_hook( __FILE__, [ Activator::class, 'activate' ] )` and
   the deactivation twin, then instantiate `Plugin` and call `boot()` on
   `plugins_loaded` priority 10. Bail with an admin notice if PHP < 7.4.

## Contract

```php
final class Plugin {
	public function __construct( Container $container );
	public function boot(): void;
	public function container(): Container;
}
```

## Acceptance criteria

- Activate → deactivate → reactivate leaves no duplicate rows, no PHP notices.
- `Container::get()` returns the same instance across calls; unknown id throws a
  clear `InvalidArgumentException`.
- Unit test: container memoisation + `Plugin::boot()` registers the expected
  hook set (assert via `has_action()` in an integration test).
