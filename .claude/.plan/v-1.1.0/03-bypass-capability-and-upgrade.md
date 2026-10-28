# 03: Bypass capability + capability upgrade

**Goal:**
- Add `sit_cwm_bypass_publish_gate`, granted to administrators only.
- Make sites upgraded from 1.0.0 (no reactivation) receive it without restoring capabilities a site owner removed.

**Prerequisites:** 02. **Roadmap:** §3.1A "A dedicated `sit_cwm_bypass_publish_gate` capability, granted to administrators only". **Decisions:** E9.

**Files:**
- Modify: `includes/Workflow/Capabilities.php`
- Modify: `includes/Workflow/PermissionManager.php`
- Modify: `includes/Core/Activator.php` (`activate_site()`)
- Modify: `includes/Core/Plugin.php` (`boot()`: one new `plugins_loaded` hook)
- Modify: `uninstall.php` (delete the revision option)
- Test: `tests/php/integration/CapabilitiesUpgradeTest.php` (new), `tests/php/integration/PermissionManagerTest.php` (extend)

**Interfaces:**
- Consumes: `Capabilities::role_map()`, `add_caps()`.
- Produces:
  - `Capabilities::BYPASS_PUBLISH_GATE`.
  - `Capabilities::REVISION = 2`.
  - `Capabilities::REVISION_OPTION = 'sit_cwm_caps_revision'`.
  - `Capabilities::revision_grants(): array<int, array<string, string[]>>`.
  - `Capabilities::maybe_upgrade(): void`.
  - `Capabilities::mark_current(): void`, which writes the current revision.
  - `PermissionManager::can_bypass_publish_gate( ?int $user_id = null ): bool`.

---

- [ ] **Step 1: Write the failing tests** `tests/php/integration/CapabilitiesUpgradeTest.php`, extending `TestCase`

```php
public function test_administrator_holds_bypass_after_activation() {
	$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ) );
	foreach ( array( 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
		$this->assertFalse( get_role( $role )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ), $role );
	}
}

public function test_upgrade_from_revision_1_grants_only_the_bypass_cap() {
	get_role( 'administrator' )->remove_cap( Capabilities::BYPASS_PUBLISH_GATE );
	get_role( 'editor' )->remove_cap( Capabilities::APPROVE_CONTENT ); // A site owner's deliberate change.
	delete_option( Capabilities::REVISION_OPTION );                    // 1.0.0 never wrote it.

	Capabilities::maybe_upgrade();

	$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ) );
	$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::APPROVE_CONTENT ), 'Upgrade must not restore removed caps.' );
	$this->assertSame( 2, (int) get_option( Capabilities::REVISION_OPTION ) );
}

public function test_upgrade_is_a_no_op_at_current_revision() {
	update_option( Capabilities::REVISION_OPTION, Capabilities::REVISION, false );
	get_role( 'administrator' )->remove_cap( Capabilities::BYPASS_PUBLISH_GATE );
	Capabilities::maybe_upgrade();
	$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ) );
}

public function test_upgrade_skips_a_missing_administrator_role_without_error() {
	delete_option( Capabilities::REVISION_OPTION );
	$saved = get_role( 'administrator' )->capabilities;
	remove_role( 'administrator' );
	Capabilities::maybe_upgrade();
	$this->assertSame( 2, (int) get_option( Capabilities::REVISION_OPTION ) );
	add_role( 'administrator', 'Administrator', $saved );
}

public function test_revision_option_is_not_autoloaded() {
	delete_option( Capabilities::REVISION_OPTION );
	Capabilities::maybe_upgrade();
	$this->assertSame( 'off', $this->autoload_flag( Capabilities::REVISION_OPTION ) );
}
```

  `autoload_flag()`: add a small private helper reading `autoload` from `$wpdb->options` via `$wpdb->prepare()`. Accept `'off'` and `'no'` (WP < 6.6 writes `no`) by normalising both to `'off'`.

  Append to `PermissionManagerTest.php`:

```php
public function test_can_bypass_publish_gate_only_for_holders() {
	$this->assertTrue( $this->permissions->can_bypass_publish_gate( self::fixture_user( 'admin' ) ) );
	$this->assertFalse( $this->permissions->can_bypass_publish_gate( self::fixture_user( 'editor' ) ) );
	$this->assertFalse( $this->permissions->can_bypass_publish_gate( 0 ) );
	$this->assertFalse( $this->permissions->can_bypass_publish_gate( 999999 ) );
}
```

  Use whatever `PermissionManager` accessor that test class already uses.

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'CapabilitiesUpgradeTest|PermissionManagerTest'`
  Expected: FAIL, `Undefined constant ... BYPASS_PUBLISH_GATE`.

- [ ] **Step 3: Implement in `Capabilities`**

  - Add the constant with a docblock: "Publish managed content that is not approved, while a publish gate is on. Every use is recorded."
  - Append it to `all()`, last.
  - `role_map()` is unchanged: `administrator => self::all()` already covers it.
  - Add `REVISION`, `REVISION_OPTION`, `revision_grants()` (E9 literal).
  - `maybe_upgrade()`:
    - read `(int) get_option( REVISION_OPTION, 1 )`;
    - for each `revision_grants()` entry `> stored` and `<= REVISION`, ascending: `get_role()`, skip null, `add_cap()` only the listed caps;
    - finally `update_option( REVISION_OPTION, REVISION, false )`.
    - Bail early when stored `>= REVISION`, so a normal request costs one autoload-miss option read.

    > Why default `1`: a 1.0.0 site has no option, and 1.0.0 is revision 1.
  - `mark_current()`: `update_option( REVISION_OPTION, REVISION, false )`.

- [ ] **Step 4: Wire it**

  - `Activator::activate_site()`: call `Capabilities::mark_current()` right after `Capabilities::add_caps()`.
  - `Plugin::boot()`: `add_action( 'plugins_loaded', array( Capabilities::class, 'maybe_upgrade' ), 20 );` next to the existing `maybe_upgrade_database` hook. Add the `use`.
  - `uninstall.php`: `delete_option( 'sit_cwm_caps_revision' );` next to `delete_option( 'sit_cwm_settings' );`. Use the literal string there, because the autoloader isn't loaded, but `Capabilities.php` is already required there, so `\Sit_Cwm\Workflow\Capabilities::REVISION_OPTION` also works. Prefer the constant.
  - `PermissionManager::can_bypass_publish_gate()`: resolve the user exactly like `can_manage()`, then `user_can( $user, Capabilities::BYPASS_PUBLISH_GATE )`.

- [ ] **Step 5: Make `TestCase` set the revision**

  `TestCase::set_up()` calls `Capabilities::add_caps()`. Add `Capabilities::mark_current();` after it, so other suites start from a "current" site.

- [ ] **Step 6: Run the tests to verify they pass**

  Run the Step 2 command, then the full integration suite: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration`.
  Expected: all PASS.

  `PermissionManagerTest::…capability_flags…` and `AssetsTest` derive their expectations from `Capabilities::all()`, so they pick up the seventh capability without edits. If any test hard-codes six capabilities, update it to use `Capabilities::all()`.

- [ ] **Step 7: Lint and hand off**

  Run: `composer lint` (expected clean).
  Suggested message: `feat(caps): add administrator-only sit_cwm_bypass_publish_gate with revision-based upgrade`.

## Acceptance criteria

- Fresh activation: only administrators hold the bypass capability.
- 1.0.0 → 1.1 file update without reactivation: administrators gain it on the next request, and no other capability changes.
- Uninstall removes the capability (via `remove_caps()` over `all()`) and the revision option.
