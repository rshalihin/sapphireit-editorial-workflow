# 11a: Prevent self-approval

**Goal:** an optional site setting, "Prevent people from approving their own content". When it is on, nobody can move content they authored to `approved`, administrators included, so Enforce mode means a *second* person approved it. Off by default, so upgrading changes nothing.

This is not in the roadmap. The developer added it on 2026-10-28 because, without it, an editor can approve their own post and then publish it, and the governance claim ("a human approves it") is weaker. **Choosing which roles may approve stays Pro** (developer decision, same date); 14 documents how to change it with a role-editor plugin instead.

**Prerequisites:** 02 (settings model), 10 (the `WorkflowPanel` edits it sits beside), 11 (the Publish gate settings section). **Decisions:** E16, E11, E14.

**Files:**
- Modify: `includes/Core/Settings.php` (key, default, `update()` branch, reader); `includes/Core/Activator.php` (`default_settings()` docblock shape)
- Modify: `includes/Workflow/PermissionManager.php` (`is_self_approval_blocked()`, `excluded_reviewers()`, rule in `can_change_status()`)
- Modify: `includes/Workflow/WorkflowManager.php` (`transition()` error, `assign_reviewer()` check, `get_workflow()` field)
- Modify: `includes/REST/UserController.php` (`get_items()` exclusion)
- Modify: `includes/REST/WorkflowController.php` (`get_item_schema()`: new field)
- Modify: `admin/Settings.php` (field in the Publish gate section, `sanitize()`, help tab)
- Modify: `src/components/WorkflowPanel.jsx` (one sentence under the transition buttons)
- Test: `tests/php/integration/SettingsTest.php`, `PermissionManagerTest.php`, `WorkflowManagerTest.php`, `REST/UserControllerTest.php` (or the file holding the reviewer-list tests), `REST/WorkflowControllerTest.php`, `REST/SchemaContractTest.php`, `Admin/SettingsPageTest.php` (all extended); `tests/js/ClassicPanel.test.jsx` (extend)

**Interfaces:**
- Consumes: `Settings`, `TransitionManager::is_valid()`, `PermissionManager::capability_for_status()`.
- Produces:
  - `Settings::defaults()` gains `'prevent_self_approval' => false`; `Settings::prevents_self_approval(): bool` (raw, unfiltered).
  - `PermissionManager::is_self_approval_blocked( int $post_id, ?int $user_id = null ): bool`.
  - `PermissionManager::excluded_reviewers( int $post_id ): int[]`.
  - Filter `sit_cwm_prevent_self_approval( bool $prevent, int $post_id )`.
  - Workflow REST field `self_approval_blocked: bool`.
  - Error `sit_cwm_self_approval` (403) from `POST …/workflow` and the batch route.
  - Settings field id `sit_cwm_self_approval`, form name `sit_cwm_settings[prevent_self_approval]`.

---

- [ ] **Step 1: Write the failing settings and permission tests**

  Append to `SettingsTest.php`:

```php
public function test_prevent_self_approval_defaults_off_and_upgrades_off() {
	$this->assertFalse( ( new Settings() )->defaults()['prevent_self_approval'] );
	update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) ); // 1.0.0 option.
	$this->assertFalse( ( new Settings() )->prevents_self_approval() );
}

public function test_update_stores_prevent_self_approval_as_bool() {
	$settings = new Settings();
	$this->assertTrue( $settings->update( array( 'prevent_self_approval' => '1' ) ) );
	$this->assertTrue( $settings->prevents_self_approval() );
}
```

  Append to `PermissionManagerTest.php` (the fixture post is authored by `editor`, who holds `sit_cwm_approve_content`):

```php
public function test_author_cannot_approve_own_post_when_prevented() {
	( new Settings() )->update( array( 'prevent_self_approval' => true ) );
	$post_id = self::factory()->post->create( array( 'post_author' => self::fixture_user( 'editor' ) ) );
	$this->assertFalse( $this->permissions->can_change_status( $post_id, 'approved', self::fixture_user( 'editor' ) ) );
	$this->assertTrue( $this->permissions->can_change_status( $post_id, 'approved', self::fixture_user( 'reviewer' ) ) );
	$this->assertTrue( $this->permissions->can_change_status( $post_id, 'needs_changes', self::fixture_user( 'editor' ) ), 'Only approved is restricted.' );
}

public function test_rule_applies_to_administrators() {
	( new Settings() )->update( array( 'prevent_self_approval' => true ) );
	$post_id = self::factory()->post->create( array( 'post_author' => self::fixture_user( 'admin' ) ) );
	$this->assertFalse( $this->permissions->can_change_status( $post_id, 'approved', self::fixture_user( 'admin' ) ) );
}

public function test_author_can_approve_own_post_when_setting_off() {
	$post_id = self::factory()->post->create( array( 'post_author' => self::fixture_user( 'editor' ) ) );
	$this->assertTrue( $this->permissions->can_change_status( $post_id, 'approved', self::fixture_user( 'editor' ) ) );
}

public function test_prevent_filter_receives_post_id_and_can_turn_rule_off_per_post() {
	( new Settings() )->update( array( 'prevent_self_approval' => true ) );
	$post_id = self::factory()->post->create( array( 'post_author' => self::fixture_user( 'editor' ) ) );
	$seen    = null;
	add_filter( 'sit_cwm_prevent_self_approval', function ( $prevent, $id ) use ( &$seen ) {
		$seen = $id;
		return false;
	}, 10, 2 );
	$this->assertTrue( $this->permissions->can_change_status( $post_id, 'approved', self::fixture_user( 'editor' ) ) );
	$this->assertSame( $post_id, $seen );
}

public function test_excluded_reviewers_is_the_author_only_when_prevented() {
	$post_id = self::factory()->post->create( array( 'post_author' => self::fixture_user( 'editor' ) ) );
	$this->assertSame( array(), $this->permissions->excluded_reviewers( $post_id ) );
	( new Settings() )->update( array( 'prevent_self_approval' => true ) );
	$this->assertSame( array( self::fixture_user( 'editor' ) ), $this->permissions->excluded_reviewers( $post_id ) );
	$this->assertSame( array(), $this->permissions->excluded_reviewers( 0 ) );
}
```

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'SettingsTest|PermissionManagerTest'`
  Expected: FAIL, undefined index `prevent_self_approval` / undefined method.

- [ ] **Step 3: Implement the setting and the rule**

  - `Settings`:
    - `defaults()` gains `'prevent_self_approval' => false`; update the array-shape docblocks (`defaults()`, `all()`, `Activator::default_settings()`).
    - `update()` gains a branch like `delete_data_on_uninstall`'s: `rest_sanitize_boolean()`.
    - `prevents_self_approval(): bool` returns `true === (bool) $this->get( 'prevent_self_approval', false )`.
  - `PermissionManager::is_self_approval_blocked( $post_id, $user_id )`:
    - resolve the user and post the same way `can_change_status()` does; false when either is missing;
    - `$prevent = $this->settings->prevents_self_approval()`, then `apply_filters( 'sit_cwm_prevent_self_approval', $prevent, $post->ID )` with an E11 docblock; only boolean `true` prevents;
    - return `$prevent && (int) $post->post_author === $user->ID`.
  - `PermissionManager::can_change_status()`: `$allowed` gains `&& ! ( 'approved' === $to && $this->is_self_approval_blocked( $post->ID, $user->ID ) )`, **before** `sit_cwm_can_transition`. Update the method docblock's list of checks. The filter can still widen it deliberately, as its docblock already says.
  - `PermissionManager::excluded_reviewers( $post_id )`: `array( (int) $post->post_author )` when `$post_id > 0`, the post exists, the author id is > 0 and `is_self_approval_blocked( $post_id, (int) $post->post_author )`; otherwise `array()`.

  Run the Step 2 command. Expected: PASS.

- [ ] **Step 4: Write the failing engine and REST tests**

  `WorkflowManagerTest.php`:

```php
public function test_self_approval_returns_its_own_error() {
	( new Settings() )->update( array( 'prevent_self_approval' => true ) );
	$post_id = $this->post_at( 'review', self::fixture_user( 'editor' ) ); // Existing helper, or factory + set_status.
	$result  = $this->workflow->transition( $post_id, 'review', 'approved', self::fixture_user( 'editor' ) );
	$this->assertWPError( $result );
	$this->assertSame( 'sit_cwm_self_approval', $result->get_error_code() );
	$this->assertSame( 403, $result->get_error_data()['status'] );
	$this->assertSame( 'review', $this->posts->get_status( $post_id ) );
}

public function test_author_cannot_be_assigned_as_reviewer_of_own_post_when_prevented() {
	( new Settings() )->update( array( 'prevent_self_approval' => true ) );
	$post_id = self::factory()->post->create( array( 'post_author' => self::fixture_user( 'editor' ) ) );
	$result  = $this->workflow->assign_reviewer( $post_id, self::fixture_user( 'editor' ), self::fixture_user( 'admin' ) );
	$this->assertSame( 'sit_cwm_invalid_user', $result->get_error_code() );
}

public function test_existing_self_assignment_is_kept_when_setting_turns_on() {
	// Assign editor to own post with the setting off; turn it on; get_reviewer_id() still returns the editor.
}

public function test_self_approval_blocked_hint_only_when_rule_is_the_reason() {
	// Setting on. Editor's own post at review   → get_workflow()['self_approval_blocked'] === true.
	// Same post at draft (approved not a legal next step) → false.
	// Author-role user's own post at review (lacks approve cap anyway) → false.
	// Reviewer viewing the editor's post        → false.
	// Setting off                               → false.
}

public function test_bulk_approve_skips_own_posts() {
	// Batch approve of [ own post, someone else's post ] as editor → own post fails with sit_cwm_self_approval; other succeeds.
	// Use the batch route or BulkProcessor the way the existing batch tests do.
}
```

  Reviewer-list test (in the file that already tests `GET /sit-cwm/v1/users`): with the setting on, `?post_id=<editor's post>` does not list the editor; without `post_id`, it does.

  `WorkflowControllerTest.php` / `SchemaContractTest.php`: the workflow item has `self_approval_blocked`, type `boolean`, readonly.

- [ ] **Step 5: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'WorkflowManagerTest|UserController|WorkflowControllerTest|SchemaContractTest|Batch'`
  Expected: the new tests FAIL.

- [ ] **Step 6: Implement**

  - `WorkflowManager::transition()`: where `can_change_status()` fails, return `new WP_Error( 'sit_cwm_self_approval', <E14 message>, array( 'status' => 403 ) )` when `'approved' === $to && $this->permissions->is_self_approval_blocked( $post_id, $user_id )`; otherwise the existing `forbidden()`. The 409 stale-`from` check keeps its current position.
  - `WorkflowManager::assign_reviewer()`: after the `can_be_reviewer()` check, return `sit_cwm_invalid_user` (400) with the E14 reviewer message when `in_array( $reviewer_id, $this->permissions->excluded_reviewers( $post_id ), true )`. Existing assignments are not touched.
  - `WorkflowManager::get_workflow()` adds `'self_approval_blocked'`: true only when all of these hold, so the panel never explains a refusal the rule did not cause:
    - `$this->transitions->is_valid( $state['status'], 'approved' )`;
    - `user_can( $user_id, $this->permissions->capability_for_status( 'approved' ) )`;
    - `$this->permissions->is_self_approval_blocked( $post_id, $user_id )`.
  - `UserController::get_items()`: when `$post_id > 0`, set `$query['exclude'] = $this->permissions->excluded_reviewers( $post_id )` if non-empty, **before** `sit_cwm_assignable_reviewers`. The controller relays; `PermissionManager` decides.
  - `WorkflowController::get_item_schema()`: `self_approval_blocked`, `boolean`, `readonly`, `context: [ 'view', 'edit' ]`.

  Run the Step 5 command, then the full integration suite. Expected: PASS. The setting defaults off, so existing suites are unaffected.

- [ ] **Step 7: Settings screen field**

  Append to `SettingsPageTest.php`:
  - the field `sit_cwm_self_approval` is registered in section `sit_cwm_publish_gate`;
  - it renders an unchecked checkbox `name="sit_cwm_settings[prevent_self_approval]" value="1"` with a `<label>`, checked after `update( array( 'prevent_self_approval' => true ) )`;
  - `sanitize()` turns a missing box into `false` and `'1'` into `true`.

  Implement in `admin/Settings.php`:
  - `add_settings_field( 'sit_cwm_self_approval', __( 'Self-approval', … ), array( $this, 'render_self_approval_field' ), MENU_SLUG, SECTION_GATE )`, after the mode field;
  - `render_self_approval_field()`: checkbox labelled with the E14 label, then `<p class="description">` with the E14 description;
  - `sanitize()`: `$clean['prevent_self_approval'] = ! empty( $input['prevent_self_approval'] ) && rest_sanitize_boolean( $input['prevent_self_approval'] )`;
  - help tab: one sentence mirroring the description.

  It sits in the Publish gate section because it only matters together with the gate, but it works in every mode: with the gate off, the author still cannot mark their own post Approved.

- [ ] **Step 8: Panel sentence (JS)**

  Extend `tests/js/ClassicPanel.test.jsx` (the panel is shared with the sidebar):
  - `self_approval_blocked: true` in the mocked workflow → the panel shows `You can't approve your own content. Another reviewer has to approve it.`;
  - `false` or absent (older server) → it does not.

  Implement in `WorkflowPanel.jsx`: directly under `<TransitionActions>`, when `workflow.self_approval_blocked` is true, render `<p className="sit-cwm-self-approval-note">{ __( '…E14…', 'sapphireit-editorial-workflow' ) }</p>`. The server decides; the panel only displays.

  Run: `npm run test:unit -- tests/js/ClassicPanel.test.jsx`, then `npm run test:unit`. Expected: PASS.

- [ ] **Step 9: Lint, build, hand off**

  Run: `composer lint && npm run lint:js && npm run build`.
  Suggested message: `feat(workflow): optional setting to prevent approving your own content`.

## Acceptance criteria

- With the setting off (the default and the upgrade state), nothing changes from 1.0.
- With it on, nobody can move content they authored to `approved` by any route: sidebar, classic meta box, dashboard bulk action, `sit-cwm/v1` REST. The refusal is a 403 `sit_cwm_self_approval` with the E14 message.
- The author is not offered, and cannot be assigned, as the reviewer of their own post. Existing assignments are kept.
- The panel explains the refusal only when the rule is the actual reason Approve is missing.
- Administrators are bound by the rule. In Enforce mode they can still publish through the bypass capability, and that is recorded (E5).
