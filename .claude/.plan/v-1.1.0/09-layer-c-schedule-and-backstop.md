# 09: Layer C: scheduled publishing + transition backstop

**Goal:** close the two paths that never reach `wp_insert_post_data`:
1. The scheduled-publish cron. Judged on `publish_future_post` **before** core publishes, so a refused post never goes live.
2. Any other code that writes `publish` directly, e.g. `wp_publish_post()` on a draft. Caught on `transition_post_status` after the fact and reverted.

**Prerequisites:** 07. **Roadmap:** §3.1A Layer C ("the scheduled-publish cron, which writes status with a raw query"), roadmap A1 "scheduled publishing". **Decisions:** E3, E5 (bypass at go-live, schedule-bypass meta), E6 (priorities 20 and 30).

**Files:**
- Create: `includes/Publishing/Layers/ScheduleLayer.php`
- Modify: `includes/Core/Plugin.php` (factory `publish_gate.layer.schedule`; `BOOT_PHASES['core']`)
- Test: `tests/php/integration/Publishing/ScheduleLayerTest.php` (new)

**Interfaces:**
- Consumes: `PublishGate`, `GateLedger` (`judge`, `judged`, `is_reverting`, `while_reverting`), `GateRecorder` (`refused`, `bypassed`, `schedule_bypass_user`, `forget_schedule_bypass`, `failed_open`), `PermissionManager::can_bypass_publish_gate()`, `Settings::is_post_type_enabled()`.
- Produces:
  - `ScheduleLayer::__construct( PublishGate $gate, GateLedger $ledger, GateRecorder $recorder, PermissionManager $permissions, Settings $settings )`
  - `register(): void` hooks:
    - `publish_future_post` (5) → `guard_scheduled( $post_id )`;
    - `transition_post_status` (20, 3 args) → `backstop( $new, $old, $post )`;
    - `transition_post_status` (30, 3 args) → `forget_schedule_bypass( $new, $old, $post )`.

---

- [ ] **Step 1: Write the failing tests** `tests/php/integration/Publishing/ScheduleLayerTest.php`

  Register the full chain from 07 plus `ScheduleLayer`. Helper `scheduled( string $workflow_status, int $author ): int` creates a `future` post one day ahead, written while the mode is `off` so Layer B doesn't intervene, sets the workflow status, then switches the mode on. Simulate the cron with:

```php
wp_set_current_user( 0 );
do_action( 'publish_future_post', $post_id ); // Exactly what wp-cron runs; core's check_and_publish_future_post() is hooked at 10.
```

  Core's callback refuses to publish a post whose date is in the future. Update the post's `post_date`/`post_date_gmt` to the past with `$wpdb->update()` before firing, as core's own tests do.

```php
/** Review Focus 2. */
public function test_scheduled_post_sent_back_after_scheduling_is_refused_at_go_live() {
	$post_id = $this->scheduled( 'approved', self::fixture_user( 'author' ) );
	$this->mode( 'enforce' );
	$this->posts->set_status( $post_id, 'needs_changes' );
	$this->go_live( $post_id );
	$this->assertSame( 'draft', get_post_status( $post_id ) );
	$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ), 'Never live, not even briefly.' );
	$row = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) )[0];
	$this->assertSame( 'schedule', $row->get_context()['layer'] );
	$this->assertSame( 0, $row->get_user_id() );
	$this->assertContains( $post_id, $this->notices->pending( self::fixture_user( 'author' ) ) );
}

public function test_scheduled_approved_post_goes_live_with_one_publication_row() { /* publish; 1 post_published (user 0, old future); 0 gate rows */ }

public function test_log_only_scheduled_post_below_approved_goes_live_and_records_bypass() {
	// publish; exactly 1 publish_gate_bypassed (reason log_only, layer schedule, user 0), then post_published.
}

public function test_capability_scheduled_bypass_is_honoured_at_go_live_and_meta_cleared() {
	// Admin schedules (via wp_update_post with mode enforce + workflow draft) → meta = admin id.
	// go_live → publish; 1 bypassed row (reason capability, user = admin id, layer schedule); meta deleted.
}

public function test_capability_bypass_is_not_honoured_if_scheduler_lost_the_cap() {
	// Same, then get_role( 'administrator' )->remove_cap( BYPASS ) before go_live → refused, draft. Restore cap in finally.
}

public function test_off_mode_does_nothing_at_go_live() { /* publish; 0 gate rows */ }

public function test_backstop_reverts_direct_wp_publish_post_on_a_draft() {
	$this->mode( 'enforce' );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
	$this->posts->set_status( $post_id, 'review' );
	wp_set_current_user( self::fixture_user( 'author' ) );
	wp_publish_post( $post_id ); // Raw $wpdb->update inside core; no wp_insert_post_data.
	$this->assertSame( 'draft', get_post_status( $post_id ) );
	$actions = wp_list_pluck( array_map( function ( $e ) { return $e->to_array(); }, array_reverse( $this->activity->get_for_post( $post_id ) ) ), 'action' );
	$this->assertSame( array( 'post_published', 'publish_gate_refused', 'post_unpublished' ), array_values( array_intersect( $actions, array( 'post_published', 'publish_gate_refused', 'post_unpublished' ) ) ) );
}

public function test_backstop_ignores_writes_layer_b_already_judged() {
	// Admin bypass through wp_update_post → publish; backstop does not record a second row (judged in ledger).
}

public function test_backstop_ignores_new_posts_and_live_posts() { /* old 'new' → handled by B; old 'publish' → never demote */ }

public function test_backstop_does_not_judge_its_own_revert() { /* no infinite loop; exactly one refused row */ }

public function test_schedule_bypass_meta_is_cleared_when_post_leaves_future() {
	// Meta set; wp_update_post( status draft ) → meta gone.
}

/** Roadmap A4. */
public function test_exception_in_schedule_layer_fails_open() {
	// Throwing passes-filter; go_live → publish (core proceeds); sit_cwm_publish_gate_failed fired with layer 'schedule'.
}
```

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter ScheduleLayerTest`
  Expected: FAIL, class not found.

- [ ] **Step 3: Implement `ScheduleLayer`**

  - **`guard_scheduled( $post_id )`**, wrapped in `try/catch → recorder->failed_open( 'schedule', $id, $e )`:
    1. `$post = get_post( (int) $post_id )`. Return unless it exists, `'future' === $post->post_status`, and its type is enabled.
    2. `$decision = gate->evaluate( $post->ID, $post->post_type, 0 )`. Return when it doesn't apply.
    3. When it is refused (in `enforce`, `evaluate` with user 0 never yields `capability`), check the schedule-bypass meta: `$by = recorder->schedule_bypass_user( $post->ID )`. If `$by > 0` and `permissions->can_bypass_publish_gate( $by )`, convert it with `new GateDecision( 'bypassed', $decision->mode(), $decision->workflow_status(), 'capability' )` and use `$by` as the recorded user.
    4. `ledger->judge( $post->ID, $decision )`, so the backstop skips core's coming transition.
    5. **Refused:**
       - `ledger->while_reverting( fn → wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) ) )`;
       - `recorder->refused( $post->ID, $decision, 'future', 'publish', 'schedule', 0, (int) $post->post_author )`;
       - `recorder->forget_schedule_bypass( $post->ID )`.

       Core's `check_and_publish_future_post()` then finds a non-`future` post and returns.
    6. **Bypassed:** `recorder->bypassed( $post->ID, $decision, 'future', 'schedule', $user )`, where `$user` is `$by` for capability and 0 for `log_only`. Core then publishes, and the logger writes `post_published` afterwards.
  - **`backstop( $new, $old, $post )`**, wrapped in `try/catch → failed_open( 'backstop', … )`:
    1. Return unless `'publish' === $new`, `! in_array( $old, array( 'publish', 'new' ), true )`, `$post instanceof WP_Post`, the type is enabled, `! ledger->is_reverting()`, and `null === ledger->judged( $post->ID )`.
    2. `$user = get_current_user_id()`; `$decision = gate->evaluate( $post->ID, $post->post_type, $user )`. Return when it doesn't apply.
    3. **Refused:**
       - `recorder->refused( $post->ID, $decision, $old, 'publish', 'backstop', $user, $user > 0 ? $user : (int) $post->post_author )`;
       - then `ledger->while_reverting( fn → wp_update_post( array( 'ID' => $post->ID, 'post_status' => 'draft' ) ) )`.
    4. **Bypassed:** `recorder->bypassed( … 'backstop' … )`.

    The docblock states what this covers: any entry into `publish` that bypassed `wp_insert_post()` and cron. It also states the honest limit: the post *was* live between the raw write and this hook. The audit rows reflect that.
  - **`forget_schedule_bypass( $new, $old, $post )`:** when `'future' === $old && 'future' !== $new`, call `recorder->forget_schedule_bypass( $post->ID )`. Ignore errors silently with `try/catch`.
  - Implement `unregister()` for tests.

- [ ] **Step 4: Wire it in `Plugin`**

  - Factory: `publish_gate.layer.schedule` → `new ScheduleLayer( $c->get( 'publish_gate' ), $c->get( 'publish_gate.ledger' ), $c->get( 'publish_gate.recorder' ), $c->get( 'permission_manager' ), $c->get( 'settings' ) )`.
  - Append it to `BOOT_PHASES['core']`, after `publish_gate.layer.rest`.
  - `PluginTest`: assert the `has_action()` priorities 5, 20 and 30.

- [ ] **Step 5: Run the tests to verify they pass**

  Run the Step 2 command, then the full integration suite.
  Expected: PASS.

- [ ] **Step 6: Lint and hand off**

  Run: `composer lint`.
  Suggested message: `feat(gate): judge scheduled publishing before it goes live and backstop raw publishes`.

## Acceptance criteria

- A scheduled post below `approved` at go-live never becomes `publish` in `enforce` mode. Exception: a scheduler who still holds the bypass capability, whose use is recorded.
- Direct `wp_publish_post()` on an unapproved draft is reverted, and the log shows publish → refusal → unpublish in that order.
- Roadmap A2 now holds for every path in E3. The only gap is a raw `$wpdb` write, documented in 14.
