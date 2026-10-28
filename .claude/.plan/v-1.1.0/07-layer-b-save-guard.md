# 07: Ledger, recorder, refusal notices, Layer B (save guard)

**Goal:** the first enforcing layer. Every `wp_insert_post()` / `wp_update_post()` that would move managed content into `publish`/`future` is judged; refused writes are downgraded, and refusals and bypasses are recorded once. This covers:
- the classic editor, Quick Edit and Bulk Edit;
- programmatic saves;
- WP-CLI `wp post create|update`.

This phase also builds the shared infrastructure the other layers reuse.

**Prerequisites:** 04, 05, 06. **Roadmap:** §3.1A Layer B, "Enforce only on transitions into published", "Fail open". **Decisions:** E4, E5, E6 (priority table), E7, E8, E14.

**Files:**
- Create: `includes/Publishing/GateLedger.php`
- Create: `includes/Publishing/RefusalNotices.php`
- Create: `includes/Publishing/GateRecorder.php`
- Create: `includes/Publishing/Layers/SaveLayer.php`
- Modify: `includes/Content/PostRepository.php` (`META_PUBLISH_BYPASS` constant)
- Modify: `includes/Content/PostMeta.php` (register the new key; `LOCKED_META_KEYS`)
- Modify: `includes/Core/Plugin.php` (four factories; `BOOT_PHASES['core']`)
- Test: `tests/php/unit/GateLedgerTest.php`, `tests/php/unit/SaveLayerRuleTest.php` (the E4 data provider), `tests/php/integration/Publishing/SaveLayerTest.php`, `tests/php/integration/Publishing/RefusalNoticesTest.php`, `tests/php/integration/PostMetaTest.php` (extend)

**Interfaces:**
- Consumes: `PublishGate::evaluate()` / `mode_for()` (06), `GateDecision` (06), `ActivityLogger::log_publication_event()` (04), `RequestSource::detect()` (05).
- Produces:
  - **`GateLedger`** (no WordPress calls):
    - `judge( int $post_id, GateDecision $decision ): void`
    - `judged( int $post_id ): ?GateDecision`
    - `forget( int $post_id ): void`
    - `hold( string $post_type, string $attempted, GateDecision $decision ): void` (a LIFO stack for new posts)
    - `release( string $post_type ): ?array{attempted: string, decision: GateDecision}` (pops the most recent hold for that type)
    - `is_reverting(): bool`
    - `while_reverting( callable $callback ): void` (sets the flag in `try/finally`)
  - **`RefusalNotices`** (Bootable):
    - `TRANSIENT_PREFIX = 'sit_cwm_gate_notices_'`, `TTL = DAY_IN_SECONDS`
    - `queue( int $user_id, int $post_id ): void` (no-op for user 0)
    - `pending( int $user_id ): int[]` (post ids, unique, in queue order)
    - `render(): void`, hooked on `admin_notices`; prints and clears the current user's queue. The "saved as" status is read with `get_post_status()` at render time, by which point the layer's downgrade has been written. So no layer has to know, while it is still filtering, which status will be stored.
  - **`GateRecorder`** (Bootable):
    - `__construct( ActivityLogger $activity, GateLedger $ledger, RefusalNotices $notices )`
    - `refused( int $post_id, GateDecision $d, string $stored, string $attempted, string $layer, int $user_id, int $notify_user_id ): void`
    - `bypassed( int $post_id, GateDecision $d, string $stored, string $layer, int $user_id ): void`
    - `remember_schedule_bypass( int $post_id, int $user_id ): void`
    - `schedule_bypass_user( int $post_id ): int`
    - `forget_schedule_bypass( int $post_id ): void`
    - `failed_open( string $layer, int $post_id, Throwable $error ): void`
    - `release_pending( $new_status, $old_status, $post ): void`, hooked on `transition_post_status` at **5**
  - **`Layers\SaveLayer`** (Bootable):
    - `__construct( PublishGate $gate, GateLedger $ledger, GateRecorder $recorder )`
    - `filter_insert_post_data( $data, $postarr ): mixed`, on `wp_insert_post_data` at 10 with 2 args
    - `end_insert( $post_id ): void`, on `wp_insert_post` at `PHP_INT_MAX`
    - `public static function is_entering_published( string $target, string $stored ): bool` (E4 rule 2, reused by 08 and 09)
    - `const KEPT_STATUSES = array( 'draft', 'pending' )`

---

- [ ] **Step 1: Write the failing ledger unit test** `tests/php/unit/GateLedgerTest.php`

```php
public function test_judge_and_forget() { /* judged() returns the stored decision, null after forget() */ }

public function test_release_pops_latest_hold_for_type_only() {
	$ledger = new GateLedger();
	$a = GateDecision::not_applicable( 'off' );
	$b = new GateDecision( 'refused', 'enforce', 'draft', '' );
	$ledger->hold( 'post', 'publish', $a );
	$ledger->hold( 'page', 'future', $b );
	$ledger->hold( 'post', 'future', $b );
	$this->assertSame( array( 'attempted' => 'future', 'decision' => $b ), $ledger->release( 'post' ) );
	$this->assertSame( array( 'attempted' => 'publish', 'decision' => $a ), $ledger->release( 'post' ) );
	$this->assertNull( $ledger->release( 'post' ) );
	$this->assertNotNull( $ledger->release( 'page' ) );
}

public function test_while_reverting_resets_flag_even_when_callback_throws() {
	$ledger = new GateLedger();
	try { $ledger->while_reverting( function () { throw new RuntimeException( 'x' ); } ); } catch ( RuntimeException $e ) {}
	$this->assertFalse( $ledger->is_reverting() );
}
```

  Plus a unit test for the E4 rule, `SaveLayer::is_entering_published()`, as a data provider:

  | target | stored | expected |
  |---|---|---|
  | `publish` | `draft` | true |
  | `publish` | `pending` | true |
  | `publish` | `future` | true |
  | `publish` | `auto-draft` | true |
  | `publish` | `''` | true |
  | `publish` | `publish` | false |
  | `future` | `draft` | true |
  | `future` | `future` | false |
  | `future` | `publish` | false |
  | `private` | `draft` | false |
  | `draft` | `publish` | false |

- [ ] **Step 2: Run it to verify it fails**

  Run: `vendor/bin/phpunit --testsuite unit --filter 'GateLedgerTest|SaveLayerRuleTest'`
  Expected: FAIL, classes not found.

- [ ] **Step 3: Implement `GateLedger` and `SaveLayer::is_entering_published()`**

  `GateLedger` is plain arrays plus a bool. `is_entering_published()` is the E4 rule as a two-line boolean.

  Run the Step 2 command. Expected: PASS.

- [ ] **Step 4: Register the bypass meta**

  - `PostRepository::META_PUBLISH_BYPASS = '_sit_cwm_publish_bypass_by'`.
  - `PostMeta::register_for_post_type()` registers it: `type` integer, `single` true, `default` 0, `show_in_rest` false, `sanitize_callback` `absint`, `auth_callback` always false. Put a comment that only `GateRecorder` writes it.
  - Replace the single-key check in `lock_status_meta()` with `in_array( $args[1], self::LOCKED_META_KEYS, true )`, where `LOCKED_META_KEYS = array( PostRepository::META_STATUS, PostRepository::META_PUBLISH_BYPASS )`.

  Test (append to `PostMetaTest.php`): `current_user_can( 'edit_post_meta', $post_id, '_sit_cwm_publish_bypass_by' )` is false for an administrator, and the key is absent from `/wp/v2/posts/<id>?context=edit` `meta`.

- [ ] **Step 5: Write the failing notices test** `RefusalNoticesTest.php`

```php
public function test_queue_and_render_prints_escaped_title_and_status_label_then_clears() {
	$user = self::fixture_user( 'author' );
	$post = self::factory()->post->create( array( 'post_title' => '<em>Hot</em> take', 'post_author' => $user, 'post_status' => 'draft' ) );
	$notices = new RefusalNotices();
	$notices->queue( $user, $post );
	wp_set_current_user( $user );
	ob_start(); $notices->render(); $html = ob_get_clean();
	$this->assertStringContainsString( 'notice-warning', $html );
	$this->assertStringContainsString( '“&lt;em&gt;Hot&lt;/em&gt; take” was not published', $html );
	$this->assertStringContainsString( 'It was saved as Draft.', $html );
	$this->assertSame( array(), $notices->pending( $user ) );
}

public function test_queue_for_user_zero_is_ignored() { /* pending( 0 ) stays empty, no transient written */ }

public function test_render_skips_posts_the_user_can_no_longer_edit() { /* deleted post id → nothing printed */ }
```

  - Render uses the E14 copy: `get_the_title()` passed through `wp_strip_all_tags()` → `esc_html()`, and the status label from `get_post_status_object( get_post_status( $id ) )->label` → `esc_html()`.
  - Markup: `<div class="notice notice-warning is-dismissible sit-cwm-gate-notice"><p>…</p></div>`.

- [ ] **Step 6: Write the failing Layer B tests** `tests/php/integration/Publishing/SaveLayerTest.php`

  In `set_up()`, build the real chain (`PublishGate`, `GateLedger`, `RefusalNotices`, `GateRecorder`, `SaveLayer`, `PublicationLogger`) from `TestCase` objects and `register()` each one. In `tear_down()`, unregister them; give each class an `unregister()` that mirrors `register()`, as Publish Guard does. Set the `post` mode per test via `Settings::update()`.

```php
public function test_enforce_refuses_publish_below_approved_and_keeps_draft() {
	$this->mode( 'enforce' );
	wp_set_current_user( self::fixture_user( 'author' ) );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => self::fixture_user( 'author' ) ) );
	$this->posts->set_status( $post_id, 'review' );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$this->assertSame( 'draft', get_post_status( $post_id ) );
	$row = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) )[0];
	$this->assertSame( 'publish', $row->get_new_value() );
	$this->assertSame( 'save', $row->get_context()['layer'] );
	$this->assertSame( 'review', $row->get_context()['workflow_status'] );
	$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ) );
	$this->assertContains( $post_id, $this->notices->pending( self::fixture_user( 'author' ) ) );
}

public function test_enforce_refuses_future_below_approved() { /* status stays draft; refused row new_value 'future' */ }
public function test_refusal_from_pending_keeps_pending() { /* stored pending → stays pending */ }
public function test_enforce_allows_publish_at_approved_with_no_gate_row() { /* publish; post_published row; no refused/bypassed rows */ }

public function test_enforce_admin_bypass_publishes_and_records_bypass_before_publication() {
	// Admin, workflow draft → publish succeeds; rows in order (oldest first):
	// publish_gate_bypassed (reason capability, layer save), post_published.
}

public function test_enforce_admin_scheduling_below_approved_stores_bypass_meta_not_a_bypass_row() {
	// → future; META_PUBLISH_BYPASS = admin id; zero publish_gate_bypassed rows (E5: recorded at go-live).
}

public function test_log_only_allows_and_records_one_bypass() { /* author, review → publish; exactly 1 bypassed row, reason log_only */ }
public function test_off_mode_records_nothing_from_gate() { /* publish succeeds, no refused/bypassed rows */ }

/** Review Focus 1 / roadmap A3. */
public function test_published_post_in_rereview_stays_published_on_update() {
	$this->mode( 'enforce' );
	$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	$this->posts->set_status( $post_id, 'writing' );
	wp_set_current_user( self::fixture_user( 'author' ) );
	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Correction', 'post_status' => 'publish' ) );
	$this->assertSame( 'publish', get_post_status( $post_id ) );
	$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
}

/** Review Focus 4. */
public function test_new_post_inserted_as_publish_is_refused_and_logged_under_new_id() {
	$this->mode( 'enforce' );
	wp_set_current_user( self::fixture_user( 'author' ) );
	$post_id = wp_insert_post( array( 'post_title' => 'Agent draft', 'post_status' => 'publish' ) );
	$this->assertSame( 'draft', get_post_status( $post_id ) );
	$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
}

public function test_new_post_inserted_as_publish_by_admin_logs_bypass_under_new_id() { /* 1 bypassed row on the new id */ }

public function test_cli_without_user_is_refused_in_enforce() {
	$this->mode( 'enforce' );
	wp_set_current_user( 0 );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$this->assertSame( 'draft', get_post_status( $post_id ) );
}

public function test_quick_edit_path_is_covered() {
	// Simulate wp_ajax_inline_save's core call: edit_post() with $_POST data
	// array( 'post_ID' => $id, '_status' => 'publish', … ) as the author → stays draft.
	// Use the 1.0 test approach for admin-only functions: require_once ABSPATH . 'wp-admin/includes/post.php'.
}

public function test_bulk_edit_path_is_covered() {
	// bulk_edit_posts( array( 'post' => array( $a, $b ), '_status' => 'publish' ) ) as author → both stay draft, 2 refused rows.
}

public function test_bulk_edit_with_no_status_change_leaves_scheduled_post_alone() {
	// Stored future + workflow review; bulk_edit_posts( … '_status' => '-1', 'tags_input' => 'x' ) → still future (E4).
}

public function test_revision_save_is_ignored() {
	// enforce; draft at review; wp_save_post_revision( $post_id ) → 0 publish_gate_* rows, post still draft.
	// (DOING_AUTOSAVE cannot be defined per test; the constant branch is covered by phase 15's block-editor autosave during typing.)
}

/** Roadmap A4. */
public function test_exception_in_gate_fails_open_and_is_logged() {
	$this->mode( 'enforce' );
	add_filter( 'sit_cwm_publish_gate_passes', function () { throw new RuntimeException( 'boom' ); } );
	$failed = 0;
	add_action( 'sit_cwm_publish_gate_failed', function () use ( &$failed ) { $failed++; } );
	wp_set_current_user( self::fixture_user( 'author' ) );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$this->assertSame( 'publish', get_post_status( $post_id ) );
	$this->assertSame( 1, $failed );
	$row = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) )[0];
	$this->assertSame( 'error', $row->get_context()['reason'] );
}

public function test_refused_action_fires_once_with_decision() { /* sit_cwm_publish_gate_refused fired once, args ( $id, GateDecision, $user, 'save' ) */ }
```

- [ ] **Step 7: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'SaveLayerTest|RefusalNoticesTest|PostMetaTest'`
  Expected: FAIL, classes not found.

- [ ] **Step 8: Implement `RefusalNotices`**

  - Per-user transient `TRANSIENT_PREFIX . $user_id`, holding a list of unique post ids. Use `set_transient`, not user meta: it is a one-shot UI message.
  - `register()`: `add_action( 'admin_notices', array( $this, 'render' ) )`.
  - `render()`:
    - skip posts that are missing or that `current_user_can( 'edit_post', $id )` rejects;
    - print the E14 notice;
    - `delete_transient()`.

- [ ] **Step 9: Implement `GateRecorder`**

  - **`refused()`:**
    - `activity->log_publication_event( $post_id, 'publish_gate_refused', $stored, $attempted, array( 'workflow_status' => $d->workflow_status(), 'gate_mode' => $d->mode(), 'layer' => $layer, 'source' => RequestSource::detect() ), $user_id )`;
    - `notices->queue( $notify_user_id, $post_id )`;
    - `do_action( 'sit_cwm_publish_gate_refused', $post_id, $d, $user_id, $layer )`.
  - **`bypassed()`:** the same row shape with action `publish_gate_bypassed`, new value `publish`, plus context `reason`; then `do_action( 'sit_cwm_publish_gate_bypassed', … )`.
  - **`remember_schedule_bypass()` / `schedule_bypass_user()` / `forget_schedule_bypass()`:** `update_post_meta` / `(int) get_post_meta( …, true )` / `delete_post_meta` on `META_PUBLISH_BYPASS`.
  - **`failed_open()`:**
    - when `$post_id > 0`, write a `publish_gate_bypassed` row with reason `error`, `layer` = `$layer`, and `workflow_status` read in its own `try` (empty on failure);
    - `do_action( 'sit_cwm_publish_gate_failed', $layer, $error, $post_id )`;
    - when `WP_DEBUG`, `error_log( sprintf( 'SapphireIT Editorial Workflow: publish gate %1$s failed open after %2$s: %3$s', … ) )` with `// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Developer diagnostics; the publish was allowed.`;
    - wrap the whole method in `try/catch ( Throwable )` that swallows.
  - **`release_pending( $new, $old, $post )`:** only when `'new' === $old` and `$post instanceof WP_Post`.
    - `$held = ledger->release( $post->post_type )`; return when null.
    - Then:

      | Held decision | New status | Action |
      |---|---|---|
      | refused | any | `refused( $post->ID, …, 'new', $held['attempted'], 'save', current user, current user )` |
      | bypassed | `publish` | `bypassed( … )` |
      | bypassed, reason `capability` | `future` | `remember_schedule_bypass( $post->ID, current user )` |

    - Wrap the body in `try/catch` → `failed_open( 'save', $post->ID, $e )`.

- [ ] **Step 10: Implement `SaveLayer`**

  `filter_insert_post_data( $data, $postarr )` wraps `guard( array $data, array $postarr ): array` in `try/catch ( Throwable ) → recorder->failed_open( 'save', $post_id, $e ); return $data;`. `guard()` does the following, in order:
  1. Return `$data` unless both arguments are arrays, and `$data['post_status']` is a string in `PublishGate::PUBLISH_STATUSES`. Bulk Edit's "No change" leaves it absent or unchanged.
  2. `$post_id = absint( $postarr['ID'] ?? 0 )`; `$stored = $post_id > 0 ? (string) get_post_status( $post_id ) : ''`.
  3. Return unless `self::is_entering_published( $data['post_status'], $stored )`.
  4. Return for autosaves (`DOING_AUTOSAVE`) and for revisions (`wp_is_post_revision( $post_id )`).
  5. Return when `$this->ledger->is_reverting()`.
  6. Look up `$decision = ledger->judged( $post_id )` for an existing post (Layer A may have judged already in this request). When it is null:
     - `$decision = gate->evaluate( $post_id, $data['post_type'], get_current_user_id() )`;
     - return when `! $decision->applies()`;
     - `ledger->judge( $post_id, $decision )` for existing posts.

     When reusing a judged decision, **record nothing**: Layer A already did.
  7. Refused:
     - `$kept = in_array( $stored, self::KEPT_STATUSES, true ) ? $stored : 'draft'`;
     - `$data['post_status'] = $kept`;
     - for an existing post, `recorder->refused( $post_id, $decision, $stored, $attempted, 'save', $user, $user )`;
     - for a new post, `ledger->hold( $data['post_type'], $attempted, $decision )`.
  8. Bypassed:
     - existing + `publish` → `recorder->bypassed( … 'save' … )`;
     - existing + `future` + reason `capability` → `recorder->remember_schedule_bypass( $post_id, $user )`;
     - new post → `ledger->hold()`.
  9. Return `$data`.

  `end_insert( $post_id )`: `ledger->forget( (int) $post_id )`.

  > Why the stack is keyed by post type only: an insert nested inside another insert's `wp_insert_post_data` filter is the only way two holds coexist, and LIFO pops the inner one first. This matches the inner insert's transition firing first. Document this in the docblock.

- [ ] **Step 11: Wire it in `Plugin`**

  - Factories: `publish_gate.ledger` (`new GateLedger()`), `publish_gate.notices`, `publish_gate.recorder`, `publish_gate.layer.save`.
  - Append `publish_gate.recorder`, `publish_gate.notices` and `publish_gate.layer.save` to `BOOT_PHASES['core']`, in the 01 order.
  - `PluginTest`: assert each id resolves to its class and the three hooks are attached at the E6 priorities.

- [ ] **Step 12: Run the tests to verify they pass**

  Run the Step 7 command, the unit suite, then the full integration suite.
  Expected: PASS. The default mode is `off`, so existing suites are unaffected; if one changes, investigate before adjusting.

- [ ] **Step 13: Lint and hand off**

  Run: `composer lint`.
  Suggested message: `feat(gate): enforce the publish gate on wp_insert_post_data with fail-open recording`.

## Acceptance criteria

- In `enforce` mode, no save path that goes through `wp_insert_post()` takes a below-`approved` post into `publish`/`future` (roadmap A2 for those paths).
- Live posts are never demoted. Refusals and bypasses are recorded exactly once, including for posts that had no id when judged.
- An exception inside the gate allows the publish, fires `sit_cwm_publish_gate_failed`, and leaves a `bypassed`/`error` row.
