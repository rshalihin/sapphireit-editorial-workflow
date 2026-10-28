# 02: Scope housekeeping + settings model

**Goal:**
- Align the project's scope docs with the roadmap: basic email is now Free.
- Extend `Core\Settings` with the two new stored keys (`publish_gate`, `notifications`), their validation, and typed readers.

No behaviour change yet. Nothing reads the new keys until later phases.

**Prerequisites:** 01. **Roadmap:** §3.1A (modes, default off), §3.1C (per-event toggles). **Decisions:** E1, E12.

**Files:**
- Modify: `CLAUDE.md` (project): the "Pro scope" bullet and the "Don't (Free v1.0)" list.
- Modify: `.claude/.plan/v-1.0.0/00-INDEX.md`: the "Out of scope for v1.0" paragraph.
- Modify: `.claude/pro/README.md`: the "Pro scope" paragraph.
- Create: `includes/Publishing/PublishGate.php`: **constants only** in this phase (the class body comes in 06).
- Create: `includes/Notifications/NotificationEvents.php`.
- Modify: `includes/Core/Settings.php`.
- Test: `tests/php/unit/NotificationEventsTest.php` (new), `tests/php/integration/SettingsTest.php` (extend).

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `PublishGate::MODE_OFF = 'off'`, `MODE_LOG_ONLY = 'log_only'`, `MODE_ENFORCE = 'enforce'`, `MODES` (all three, in that order), `PUBLISH_STATUSES = array( 'publish', 'future' )`, `PUBLISHABLE_STATUSES = array( 'approved', 'published' )`.
  - `NotificationEvents::REVIEWER_ASSIGNED|REVIEW_REQUESTED|CHANGES_REQUESTED|APPROVED|COMMENT_ADDED`; `NotificationEvents::all(): string[]` (in that order); `NotificationEvents::labels(): array<string, string>`; `NotificationEvents::for_status( string $to ): ?string`.
  - `Settings::defaults()` now also returns `'publish_gate' => array()` and `'notifications' => array_fill_keys( NotificationEvents::all(), false )`.
  - `Settings::gate_modes(): array<string, string>`: the stored map, filtered down to valid entries (raw, no `sit_cwm_publish_gate_mode` filter; that lives in `PublishGate::mode_for()`).
  - `Settings::is_notification_enabled( string $event ): bool`.
  - `Settings::update()` accepts `publish_gate` and `notifications`.

---

- [ ] **Step 1: Update the scope docs**

  - Project `CLAUDE.md`:
    - In "Pro scope", replace `email notifications` with `per-user notification preferences and digests`.
    - In "Don't (Free v1.0)", replace `email notifications` with `per-user notification preferences or digests`.
    - Add one line under "Free v1.0 scope": `**Free 1.1 adds**: the publish gate (off / log only / enforce), publication logging, site-level email notifications, and an optional "prevent self-approval" setting (see .claude/.plan/v-1.1.0/). Choosing which roles may approve stays Pro.`
  - `.claude/.plan/v-1.0.0/00-INDEX.md`: append `(Basic email notifications moved to Free 1.1 by the 2026-10-24 roadmap.)` to the out-of-scope paragraph. Leave the rest of that file historical.
  - `.claude/pro/README.md`: change `Email notifications` in "Pro scope" to `Per-user notification preferences and digests`.

  Expected: `git diff --stat` shows only those three files.

- [ ] **Step 2: Write the failing unit test** `tests/php/unit/NotificationEventsTest.php`

```php
public function test_all_lists_the_five_events_in_order() {
	$this->assertSame(
		array( 'reviewer_assigned', 'review_requested', 'changes_requested', 'approved', 'comment_added' ),
		NotificationEvents::all()
	);
}

public function test_for_status_maps_only_the_three_notifying_targets() {
	$this->assertSame( 'review_requested', NotificationEvents::for_status( 'review' ) );
	$this->assertSame( 'changes_requested', NotificationEvents::for_status( 'needs_changes' ) );
	$this->assertSame( 'approved', NotificationEvents::for_status( 'approved' ) );
	$this->assertNull( NotificationEvents::for_status( 'writing' ) );
	$this->assertNull( NotificationEvents::for_status( 'published' ) );
	$this->assertNull( NotificationEvents::for_status( 'draft' ) );
}
```

  `labels()` calls `__()`, so its test lives in the integration suite (Step 5).

- [ ] **Step 3: Run it to verify it fails**

  Run: `vendor/bin/phpunit --testsuite unit --filter NotificationEventsTest`
  Expected: FAIL, `Class "Sit_Cwm\Notifications\NotificationEvents" not found`.

- [ ] **Step 4: Implement `NotificationEvents` and the `PublishGate` constants**

  - `final class NotificationEvents` in `includes/Notifications/NotificationEvents.php`:
    - the five constants;
    - `all()`;
    - `labels()`, returning E14's event labels keyed by event;
    - `for_status()` as a `switch`.
  - `final class PublishGate` in `includes/Publishing/PublishGate.php` with only the five constants above and a class docblock saying "Decision service; body added in phase 06". `@since 1.1.0` on everything.

  Run the Step 3 command. Expected: PASS.

- [ ] **Step 5: Write the failing integration tests** (append to `tests/php/integration/SettingsTest.php`)

```php
public function test_defaults_include_gate_and_notification_keys_all_off() {
	$defaults = ( new Settings() )->defaults();
	$this->assertSame( array(), $defaults['publish_gate'] );
	$this->assertSame( array_fill_keys( NotificationEvents::all(), false ), $defaults['notifications'] );
}

public function test_upgrade_from_1_0_option_reads_every_gate_off_and_every_event_off() {
	update_option( Settings::OPTION, array( 'post_types' => array( 'post', 'page' ), 'delete_data_on_uninstall' => false ) );
	$settings = new Settings();
	$this->assertSame( array(), $settings->gate_modes() );
	foreach ( NotificationEvents::all() as $event ) {
		$this->assertFalse( $settings->is_notification_enabled( $event ) );
	}
}

public function test_update_stores_valid_gate_modes() {
	$settings = new Settings();
	$this->assertTrue( $settings->update( array( 'publish_gate' => array( 'post' => 'enforce', 'page' => 'log_only' ) ) ) );
	$this->assertSame( array( 'post' => 'enforce', 'page' => 'log_only' ), $settings->gate_modes() );
}

public function test_update_rejects_unknown_mode_or_unselectable_type_and_saves_nothing() {
	$settings = new Settings();
	$this->assertFalse( $settings->update( array( 'publish_gate' => array( 'post' => 'block' ) ) ) );
	$this->assertFalse( $settings->update( array( 'publish_gate' => array( 'nonexistent_cpt' => 'enforce' ) ) ) );
	$this->assertFalse( $settings->update( array( 'publish_gate' => 'enforce' ) ) );
	$this->assertSame( array(), $settings->gate_modes() );
}

public function test_update_replaces_the_whole_gate_map() {
	$settings = new Settings();
	$settings->update( array( 'publish_gate' => array( 'post' => 'enforce', 'page' => 'log_only' ) ) );
	$this->assertTrue( $settings->update( array( 'publish_gate' => array( 'page' => 'enforce' ) ) ) );
	$this->assertSame( array( 'page' => 'enforce' ), $settings->gate_modes() ); // 'post' is gone, so it reads as off.
	$this->assertTrue( $settings->update( array( 'publish_gate' => array() ) ) );
	$this->assertSame( array(), $settings->gate_modes() );
}

public function test_gate_modes_drops_tampered_stored_entries() {
	update_option( Settings::OPTION, array( 'publish_gate' => array( 'post' => 'enforce', 'page' => 'nonsense', 7 => 'enforce' ) ) );
	$this->assertSame( array( 'post' => 'enforce' ), ( new Settings() )->gate_modes() );
}

public function test_update_stores_notification_toggles_and_rejects_unknown_events() {
	$settings = new Settings();
	$this->assertTrue( $settings->update( array( 'notifications' => array( 'approved' => true ) ) ) );
	$this->assertTrue( $settings->is_notification_enabled( 'approved' ) );
	$this->assertFalse( $settings->is_notification_enabled( 'comment_added' ) );
	$this->assertFalse( $settings->update( array( 'notifications' => array( 'slack' => true ) ) ) );
	$this->assertFalse( $settings->is_notification_enabled( 'slack' ) );
}

public function test_notification_labels_cover_every_event() {
	$this->assertSame( NotificationEvents::all(), array_keys( NotificationEvents::labels() ) );
}
```

- [ ] **Step 6: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter SettingsTest`
  Expected: the new tests FAIL (undefined index `publish_gate`, undefined method `gate_modes`). The existing ones still PASS.

- [ ] **Step 7: Implement in `includes/Core/Settings.php`**

  - Extend `defaults()` and every array-shape docblock (`defaults()`, `all()`; also `Activator::default_settings()`).
  - `update()` gains two branches, both following the existing reject-whole-update style:
    - **`publish_gate`:** must be an array. Every key must be a string in `selectable_post_types()` (after `sanitize_key`). Every value must be `in_array( $v, PublishGate::MODES, true )`. Any violation → `return false`. A type set to `off` is stored as `off` (explicit), not removed. **The submitted map replaces the stored map** (unlike `notifications` below): types not in it go back to `off`. The settings screen always submits every enabled type, and phase 15's `setSettings( { publish_gate: {} } )` relies on this to turn every gate off.
    - **`notifications`:** must be an array. Every key must be in `NotificationEvents::all()`. Values go through `rest_sanitize_boolean()`. The result is merged over the stored map, so a partial update keeps the other events.
  - `gate_modes()`: read `get( 'publish_gate', array() )` and keep only string keys whose value is in `PublishGate::MODES`.
  - `is_notification_enabled( string $event )`: `in_array( $event, NotificationEvents::all(), true ) && ! empty( $map[ $event ] )`.

- [ ] **Step 8: Run the tests to verify they pass**

  Run the Step 6 command, then `vendor/bin/phpunit --testsuite unit`.
  Expected: all PASS.

- [ ] **Step 9: Lint**

  Run: `composer lint`. Expected: no errors, no warnings.

- [ ] **Step 10: Hand off**

  Report the changed files. Suggested message: `feat(settings): store publish gate modes and notification toggles (off by default)`.

## Acceptance criteria

- With an option written by 1.0.0, every gate mode reads as absent (→ `off`) and every event as `false`.
- No invalid mode, unknown post type or unknown event can be stored through `Settings::update()`.
- The scope docs no longer call basic email "Pro".
