# 08: Layer A: REST refusal, publish link, `publish_post` meta cap

**Goal:** the block editor adapts its own UI and `wp/v2` REST refuses by the same rule.
- When the gate would refuse, the `wp:action-publish` link disappears from the post's `context=edit` response, so Gutenberg offers **Submit for Review** instead of **Publish/Schedule**.
- Any `wp/v2` create or update into `publish`/`future` is refused with a clean 403.
- The `publish_post` meta capability agrees, for third-party code that checks it.

**Prerequisites:** 07. **Roadmap:** §3.1A Layer A, R2 as corrected by **E3**. **Decisions:** E3, E4, E5, E14.

**Files:**
- Create: `includes/Publishing/Layers/RestLayer.php`
- Modify: `includes/Core/Plugin.php` (factory `publish_gate.layer.rest`; `BOOT_PHASES['core']`)
- Test: `tests/php/integration/Publishing/RestLayerTest.php` (new)

**Interfaces:**
- Consumes: `PublishGate`, `GateLedger::judge()/judged()`, `GateRecorder::refused()/failed_open()`, `SaveLayer::is_entering_published()` (07), `Settings::enabled_post_types()`.
- Produces:
  - `RestLayer::__construct( PublishGate $gate, GateLedger $ledger, GateRecorder $recorder, Settings $settings )`
  - `RestLayer::PUBLISH_REL = 'https://api.w.org/action-publish'`
  - `RestLayer::ERROR_CODE = 'sit_cwm_publish_gated'`
  - `register(): void` hooks:
    - `rest_api_init` (20) → `register_post_type_filters()`, which adds `rest_prepare_{type}` (10, 3 args) and `rest_pre_insert_{type}` (10, 2 args) for each enabled type;
    - `map_meta_cap` (10, 4 args) → `filter_meta_cap()`.
  - `filter_prepare( $response, $post, $request )`
  - `filter_pre_insert( $prepared, $request )`, which returns `stdClass|WP_Error`
  - `filter_meta_cap( $caps, $cap, $user_id, $args ): array`

---

- [ ] **Step 1: Write the failing tests** `tests/php/integration/Publishing/RestLayerTest.php`

  In `set_up()`, register the full chain from 07 plus `RestLayer`, then `do_action( 'rest_api_init' )` after `reset_rest_server()`, so the per-type filters exist. Use `$this->dispatch()` (or the file's existing REST helper) for `wp/v2` requests.

```php
public function test_edit_context_drops_publish_link_when_gate_would_refuse() {
	$this->mode( 'enforce' );
	wp_set_current_user( self::fixture_user( 'author' ) );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => self::fixture_user( 'author' ) ) );
	$response = $this->get( "/wp/v2/posts/$post_id", array( 'context' => 'edit' ) );
	$this->assertArrayNotHasKey( RestLayer::PUBLISH_REL, $response->get_links() );
}

public function test_publish_link_stays_when_approved_or_log_only_or_bypass_or_off() {
	// Four cases, each asserting the rel IS present:
	// enforce + approved (author); log_only + review (author); enforce + draft (admin); off + draft (author).
}

public function test_publish_link_stays_for_live_and_scheduled_posts() { /* stored publish and future → link present (E4) */ }

/** Review Focus 4. */
public function test_rest_create_as_publish_is_refused_with_403() {
	$this->mode( 'enforce' );
	wp_set_current_user( self::fixture_user( 'author' ) );
	$response = $this->post( '/wp/v2/posts', array( 'title' => 'From an agent', 'status' => 'publish' ) );
	$this->assertSame( 403, $response->get_status() );
	$this->assertSame( 'sit_cwm_publish_gated', $response->as_error()->get_error_code() );
	$this->assertSame( 'This content has to be approved in the editorial workflow before it can be published.', $response->as_error()->get_error_message() );
	$this->assertSame( array(), get_posts( array( 'title' => 'From an agent', 'post_status' => 'any', 'fields' => 'ids' ) ) ); // Nothing created.
}

public function test_rest_update_to_publish_is_refused_and_recorded_once() {
	// Existing draft at review; POST /wp/v2/posts/<id> { status: publish } as author
	// → 403; status still draft; exactly ONE publish_gate_refused row with layer 'rest'
	// (Layer B must not add a second — it never runs because the insert is aborted).
}

public function test_rest_update_to_future_is_refused() { /* { status: future, date: +1 day } → 403 */ }

public function test_rest_publish_at_approved_succeeds_with_one_publication_row() { /* 200; publish; 1 post_published; 0 gate rows */ }

public function test_rest_admin_bypass_succeeds_and_is_recorded_once() {
	// enforce + draft, admin → 200; exactly ONE publish_gate_bypassed row (Layer B reused Layer A's judgement via the ledger).
}

/** Review Focus 1. */
public function test_rest_update_of_published_post_is_not_gated() {
	// stored publish, workflow writing; POST { title: 'Fix', status: 'publish' } as author → 200, still publish, 0 gate rows.
}

public function test_rest_pages_are_covered_when_page_is_gated() { /* mode page=enforce; POST /wp/v2/pages status publish → 403 */ }

public function test_ungated_type_is_untouched() { /* page mode off, post enforce: POST /wp/v2/pages publish → 201 */ }

public function test_publish_post_meta_cap_follows_the_gate() {
	$this->mode( 'enforce' );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => self::fixture_user( 'author' ) ) );
	$this->assertFalse( user_can( self::fixture_user( 'author' ), 'publish_post', $post_id ) );
	$this->assertTrue( user_can( self::fixture_user( 'admin' ), 'publish_post', $post_id ) );
	$this->posts->set_status( $post_id, 'approved' );
	$this->assertTrue( user_can( self::fixture_user( 'author' ), 'publish_post', $post_id ) );
}

public function test_meta_cap_filter_does_not_touch_other_caps_or_live_posts() {
	// edit_post unaffected; publish_post on a stored-publish post unaffected; publish_posts (primitive) unaffected.
}

public function test_workflow_published_transition_still_works_at_approved() {
	// Regression on D5: approved → published via WorkflowManager::transition() as editor, enforce mode → true.
}

/** Roadmap A4. */
public function test_exception_in_rest_layer_fails_open() {
	// Throwing sit_cwm_publish_gate_passes: POST status publish as author → 201/200 and published;
	// sit_cwm_publish_gate_failed fired; link present in GET.
}
```

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter RestLayerTest`
  Expected: FAIL, class not found.

- [ ] **Step 3: Implement `RestLayer`**

  Each public callback is `try { … } catch ( Throwable $e ) { $this->recorder->failed_open( 'rest', $post_id, $e ); return <input>; }`.

  `failed_open` is only reached from `filter_pre_insert`. For `filter_prepare` and `filter_meta_cap`, a failure returns the input, which keeps the link and leaves caps unchanged, and calls `failed_open( 'rest', 0, $e )` so the failure still fires the action and debug log without a row.

  - **`filter_prepare( $response, $post, $request )`:**
    - Return the response unchanged unless it is a `WP_REST_Response`, `$post` is a `WP_Post`, `'edit' === $request['context']` and the response has `PUBLISH_REL`.
    - If `SaveLayer::is_entering_published( 'publish', $post->post_status )` and `gate->evaluate( $post->ID, $post->post_type, get_current_user_id() )->is_refused()` → `$response->remove_link( self::PUBLISH_REL )`.
    - Record nothing (display only).
  - **`filter_pre_insert( $prepared, $request )`:**
    - Return unless `$prepared` is an object with a string `post_status` in `PublishGate::PUBLISH_STATUSES`.
    - `$post_id = isset( $prepared->ID ) ? (int) $prepared->ID : 0`; `$stored` as in SaveLayer.
    - Return unless `is_entering_published()`.
    - `$type = $post_id > 0 ? get_post_type( $post_id ) : (string) ( $prepared->post_type ?? '' )`. Return when `$type` is empty or not enabled.
    - `$decision = gate->evaluate( $post_id, $type, get_current_user_id() )`; return when it doesn't apply.
    - `ledger->judge( $post_id, $decision )` when `$post_id > 0`.
    - **Refused:** when `$post_id > 0`, `recorder->refused( $post_id, $decision, $stored, $attempted, 'rest', $user, 0 )`. `$notify_user_id` is `0` because the editor shows the REST error itself. Then return `new WP_Error( self::ERROR_CODE, __( '…E14…', 'sapphireit-editorial-workflow' ), array( 'status' => 403 ) )`.
    - **Bypassed:** for existing posts with `publish`, `recorder->bypassed( … 'rest' … )`; for `future` with reason `capability`, `remember_schedule_bypass()`. New posts are left to SaveLayer's hold, which records under the new id. SaveLayer finds no `judged( 0 )` and evaluates again, getting the same answer, so there is exactly one row. Assert this in the admin-bypass create test.
    - Return `$prepared`.
  - **`filter_meta_cap( $caps, $cap, $user_id, $args )`:**
    - Only for `'publish_post' === $cap` and `isset( $args[0] )`.
    - `$post = get_post( (int) $args[0] )`; return unless it exists, its type is enabled, and `is_entering_published( 'publish', $post->post_status )`.
    - Append `'do_not_allow'` when `gate->evaluate( $post->ID, $post->post_type, (int) $user_id )->is_refused()`.

    > Recursion note for the docblock: `evaluate()` only checks the primitive `sit_cwm_bypass_publish_gate` and reads meta. It never checks `publish_post`, so this filter cannot re-enter itself.
  - **`register_post_type_filters()`:** loop over `settings->enabled_post_types()` and add the two filters per type.

- [ ] **Step 4: Wire it in `Plugin`**

  - Factory: `publish_gate.layer.rest` → `new RestLayer( $c->get( 'publish_gate' ), $c->get( 'publish_gate.ledger' ), $c->get( 'publish_gate.recorder' ), $c->get( 'settings' ) )`.
  - Append it to `BOOT_PHASES['core']`, after `publish_gate.layer.save`.
  - `PluginTest`: assert the service resolves and `map_meta_cap` has the filter.

- [ ] **Step 5: Run the tests to verify they pass**

  Run the Step 2 command, then the full integration suite.
  Expected: PASS. `NegativeSuiteTest` and the REST suites run with mode `off`, so they are unchanged.

- [ ] **Step 6: Lint and hand off**

  Run: `composer lint`.
  Suggested message: `feat(gate): gate wp/v2 REST publishing and the publish link the block editor reads`.

## Acceptance criteria

- Block editor and REST: with the gate refusing, there is no publish link, and any create or update to `publish`/`future` is a 403 `sit_cwm_publish_gated` with the E14 message. A refusal on an existing post is recorded exactly once.
- `current_user_can( 'publish_post', $id )` agrees with the gate. The primitive `publish_posts` and every other capability are untouched.
- Live posts, scheduled posts, `log_only`, `off` and bypass holders keep the link.
