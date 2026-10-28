# 06: Publish gate decision engine

**Goal:** one side-effect-free service that answers, for a post type, post and user: *what mode applies, and does this publish attempt pass, get bypassed, or get refused?* Every layer in 07–09 and the REST hint in 10 ask it. It writes nothing and hooks nothing.

**Prerequisites:** 02, 03. **Roadmap:** §3.1A mode table. **Decisions:** E1, E2, E4 (the caller decides "is this a transition into published"; this service only decides the outcome), E10.

**Files:**
- Modify: `includes/Publishing/PublishGate.php` (body added to the constants from 02)
- Create: `includes/Publishing/GateDecision.php`
- Modify: `includes/Publishing/PublicationLogger.php` (log the filtered mode, see 05's note)
- Modify: `includes/Core/Plugin.php` (factory `publish_gate`; update the `publication_logger` factory)
- Test: `tests/php/unit/GateDecisionTest.php` (new), `tests/php/integration/Publishing/PublishGateTest.php` (new)

**Interfaces:**
- Consumes: `Settings::gate_modes()`, `Settings::is_post_type_enabled()`, `PostRepository::get_status()`, `PostRepository::has_unknown_status()`, `PermissionManager::can_bypass_publish_gate()`.
- Produces:
  - `GateDecision` (final, immutable):
    - outcome constants `OUTCOME_NOT_APPLICABLE = 'not_applicable'`, `OUTCOME_ALLOWED = 'allowed'`, `OUTCOME_BYPASSED = 'bypassed'`, `OUTCOME_REFUSED = 'refused'`;
    - reason constants `REASON_NONE = ''`, `REASON_APPROVED = 'approved'`, `REASON_LOG_ONLY = 'log_only'`, `REASON_CAPABILITY = 'capability'`, `REASON_ERROR = 'error'`;
    - `__construct( string $outcome, string $mode, string $workflow_status, string $reason )`;
    - getters `outcome()`, `mode()`, `workflow_status()`, `reason()`;
    - predicates `is_refused(): bool`, `is_bypassed(): bool`, `applies(): bool` (outcome ≠ not_applicable);
    - static `not_applicable( string $mode ): self` and `failed_open( string $mode, string $workflow_status ): self` (outcome `bypassed`, reason `error`).
  - `PublishGate::__construct( Settings $settings, PostRepository $posts, PermissionManager $permissions )`.
  - `PublishGate::mode_for( string $post_type, int $post_id = 0 ): string`.
  - `PublishGate::passes( int $post_id, string $mode ): bool`.
  - `PublishGate::evaluate( int $post_id, string $post_type, int $user_id ): GateDecision`.
  - `PublishGate::describe( int $post_id, int $user_id ): array{mode: string, outcome: string}`.

---

- [ ] **Step 1: Write the failing unit test** `tests/php/unit/GateDecisionTest.php`

```php
public function test_predicates_follow_outcome() {
	$refused = new GateDecision( GateDecision::OUTCOME_REFUSED, 'enforce', 'review', GateDecision::REASON_NONE );
	$this->assertTrue( $refused->is_refused() );
	$this->assertFalse( $refused->is_bypassed() );
	$this->assertTrue( $refused->applies() );
	$this->assertFalse( GateDecision::not_applicable( 'off' )->applies() );
}

public function test_failed_open_is_a_bypass_with_reason_error() {
	$d = GateDecision::failed_open( 'enforce', '' );
	$this->assertSame( GateDecision::OUTCOME_BYPASSED, $d->outcome() );
	$this->assertSame( GateDecision::REASON_ERROR, $d->reason() );
}

public function test_rejects_unknown_outcome() {
	$this->expectException( InvalidArgumentException::class );
	new GateDecision( 'maybe', 'enforce', 'review', '' );
}
```

- [ ] **Step 2: Run it to verify it fails**

  Run: `vendor/bin/phpunit --testsuite unit --filter GateDecisionTest`
  Expected: FAIL, class not found.

- [ ] **Step 3: Implement `GateDecision`**

  Plain value object: private properties, no WordPress calls. The constructor validates `$outcome` against the four constants and throws `InvalidArgumentException`; use the same phpcs ignore comment `Container::get()` uses for its exception message.

  Run the Step 2 command. Expected: PASS.

- [ ] **Step 4: Write the failing integration tests** `tests/php/integration/Publishing/PublishGateTest.php`

  Extend `TestCase`, use `CreatesWorkflowPosts`, build `new PublishGate( new Settings(), $this->posts, new PermissionManager( $this->posts, new Settings() ) )`.

```php
public function test_mode_defaults_to_off() {
	$this->assertSame( 'off', $this->gate->mode_for( 'post' ) );
}

public function test_mode_reads_settings_and_filter_and_rejects_bad_filter_values() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$this->assertSame( 'enforce', $this->gate->mode_for( 'post' ) );
	add_filter( 'sit_cwm_publish_gate_mode', function () { return 'log_only'; } );
	$this->assertSame( 'log_only', $this->gate->mode_for( 'post' ) );
	add_filter( 'sit_cwm_publish_gate_mode', function () { return 'block'; }, 20 );
	$this->assertSame( 'off', $this->gate->mode_for( 'post' ) );
}

/** Review Focus 3. */
public function test_mode_is_off_for_post_type_no_longer_enabled() {
	( new Settings() )->update( array( 'publish_gate' => array( 'page' => 'enforce' ) ) );
	( new Settings() )->update( array( 'post_types' => array( 'post' ) ) );
	$this->assertSame( 'off', $this->gate->mode_for( 'page' ) );
}

/** E1: post-id context, and no unkeyed caching. */
public function test_mode_filter_receives_post_id_and_answers_per_post() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$strict = self::factory()->post->create();
	$loose  = self::factory()->post->create();
	$seen   = array();
	add_filter( 'sit_cwm_publish_gate_mode', function ( $mode, $type, $post_id ) use ( &$seen, $loose ) {
		$seen[] = $post_id;
		return $loose === $post_id ? 'log_only' : $mode;
	}, 10, 3 );
	$this->assertSame( 'enforce', $this->gate->mode_for( 'post', $strict ) );
	$this->assertSame( 'log_only', $this->gate->mode_for( 'post', $loose ) ); // Fails if the first answer were cached.
	$this->assertSame( 'enforce', $this->gate->mode_for( 'post' ) );
	$this->assertSame( array( $strict, $loose, 0 ), $seen );
	$this->assertTrue( $this->gate->evaluate( $loose, 'post', self::fixture_user( 'author' ) )->is_bypassed() ); // evaluate() passes the id through.
}

/**
 * @dataProvider data_outcomes
 */
public function test_evaluate_outcomes( string $mode, string $workflow_status, string $user_key, string $outcome, string $reason ) {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => $mode ) ) );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
	$this->posts->set_status( $post_id, $workflow_status );
	$d = $this->gate->evaluate( $post_id, 'post', self::fixture_user( $user_key ) );
	$this->assertSame( $outcome, $d->outcome() );
	$this->assertSame( $reason, $d->reason() );
	$this->assertSame( $workflow_status, $d->workflow_status() );
}

public function data_outcomes() {
	return array(
		'off never applies'              => array( 'off', 'draft', 'author', 'not_applicable', '' ),
		'log_only approved passes'       => array( 'log_only', 'approved', 'author', 'allowed', 'approved' ),
		'log_only review bypasses'       => array( 'log_only', 'review', 'author', 'bypassed', 'log_only' ),
		'enforce approved passes'        => array( 'enforce', 'approved', 'author', 'allowed', 'approved' ),
		'enforce published passes'       => array( 'enforce', 'published', 'author', 'allowed', 'approved' ),
		'enforce review refuses author'  => array( 'enforce', 'review', 'author', 'refused', '' ),
		'enforce review refuses editor'  => array( 'enforce', 'needs_changes', 'editor', 'refused', '' ),
		'enforce draft admin bypasses'   => array( 'enforce', 'draft', 'admin', 'bypassed', 'capability' ),
		'log_only admin is log_only'     => array( 'log_only', 'draft', 'admin', 'bypassed', 'log_only' ),
	);
}

public function test_new_post_id_zero_uses_default_status_and_is_refused_in_enforce() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$d = $this->gate->evaluate( 0, 'post', self::fixture_user( 'author' ) );
	$this->assertTrue( $d->is_refused() );
	$this->assertSame( 'draft', $d->workflow_status() );
}

public function test_unknown_stored_status_does_not_pass() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$post_id = self::factory()->post->create();
	update_post_meta( $post_id, PostRepository::META_STATUS, 'legacy_status' );
	$this->assertTrue( $this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) )->is_refused() );
}

public function test_passes_filter_can_refuse_an_approved_post_and_only_true_passes() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$post_id = self::factory()->post->create();
	$this->posts->set_status( $post_id, 'approved' );
	add_filter( 'sit_cwm_publish_gate_passes', '__return_zero' );
	$this->assertTrue( $this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) )->is_refused() );
}

/** E2: a WP_Error from the passes filter is a refusal. */
public function test_passes_filter_wp_error_refuses() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$post_id = self::factory()->post->create();
	$this->posts->set_status( $post_id, 'approved' );
	add_filter( 'sit_cwm_publish_gate_passes', function () {
		return new WP_Error( 'checklist_incomplete', 'Checklist incomplete.' );
	} );
	$this->assertTrue( $this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) )->is_refused() );
}

public function test_evaluate_has_no_side_effects() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$post_id = self::factory()->post->create();
	$count   = $this->activity->count_for_post( $post_id );
	$this->gate->evaluate( $post_id, 'post', self::fixture_user( 'author' ) );
	$this->assertSame( $count, $this->activity->count_for_post( $post_id ) );
	$this->assertSame( 0, did_action( 'sit_cwm_publish_gate_refused' ) );
}

public function test_describe_is_not_applicable_for_live_and_scheduled_posts() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$live = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	$this->assertSame( array( 'mode' => 'enforce', 'outcome' => 'not_applicable' ), $this->gate->describe( $live, self::fixture_user( 'author' ) ) );
	$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
	$this->assertSame( array( 'mode' => 'enforce', 'outcome' => 'refused' ), $this->gate->describe( $draft, self::fixture_user( 'author' ) ) );
}
```

- [ ] **Step 5: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter PublishGateTest`
  Expected: FAIL, undefined methods.

- [ ] **Step 6: Implement `PublishGate`**

  - **`mode_for( $post_type, $post_id = 0 )`:**
    - not enabled → `off`;
    - `$mode = settings->gate_modes()[ $post_type ] ?? MODE_OFF`;
    - apply `sit_cwm_publish_gate_mode( $mode, $post_type, $post_id )`, with a docblock per E11 that says `$post_id` is `0` when not post-specific;
    - return it only when `in_array( …, MODES, true )`, else `MODE_OFF`.
    - No memoisation (E1). Put a one-line comment saying why, so nobody adds an unkeyed cache later.
  - **`passes( $post_id, $mode )`:**
    - `$status = posts->get_status( $post_id )`;
    - `$passes = ! ( $post_id > 0 && posts->has_unknown_status( $post_id ) ) && in_array( $status, PUBLISHABLE_STATUSES, true )`;
    - apply `sit_cwm_publish_gate_passes( $passes, $post_id, $status, $mode )`, with a docblock stating the `bool|WP_Error` contract (E2);
    - return `true === $filtered`. That already makes a `WP_Error` a refusal; don't special-case it in 1.1.
  - **`evaluate()`:**
    - `$mode = mode_for( $post_type, $post_id )`;
    - mode `off` → `GateDecision::not_applicable( MODE_OFF )`;
    - passes → `allowed` / `approved`;
    - `log_only` → `bypassed` / `log_only`;
    - `enforce` with `permissions->can_bypass_publish_gate( $user_id )` → `bypassed` / `capability`;
    - else `refused` / `''`.

    Don't catch exceptions here; the layers do (E5). Docblock: "Pure: never writes, never fires actions."
  - **`describe( $post_id, $user_id )`:**
    - `$post = get_post( $post_id )`; return `array( 'mode' => 'off', 'outcome' => 'not_applicable' )` for a missing post;
    - `$mode = mode_for( $post->post_type, $post->ID )`;
    - when `in_array( $post->post_status, array( 'publish', 'future' ), true )` → `not_applicable` with that mode;
    - else `evaluate()`'s outcome.
  - Factory `'publish_gate'` → `new PublishGate( $c->get( 'settings' ), $c->get( 'post_repository' ), $c->get( 'permission_manager' ) )`.

- [ ] **Step 7: Switch `PublicationLogger` to the filtered mode**

  - Constructor gains a fourth parameter, `PublishGate $gate`.
  - `gate_mode` in the context becomes `$this->gate->mode_for( $post->post_type, $post->ID )`.
  - Update the factory and `PublicationLoggerTest::set_up()`.
  - Add to `PublicationLoggerTest`: with `add_filter( 'sit_cwm_publish_gate_mode', fn → 'log_only' )`, the logged `gate_mode` is `log_only`. Use a closure; PHP 7.4 supports `fn`, but match the file's existing closure style.

- [ ] **Step 8: Run the tests to verify they pass**

  Run: `vendor/bin/phpunit --testsuite unit && WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'PublishGateTest|PublicationLoggerTest'`
  Expected: PASS.

- [ ] **Step 9: Lint and hand off**

  Run: `composer lint`.
  Suggested message: `feat(gate): add side-effect-free publish gate decision service`.

## Acceptance criteria

- Every row of the roadmap §3.1A mode table is a passing data-provider case.
- `evaluate()` and `describe()` write nothing and fire no `sit_cwm_publish_gate_*` action.
- An `enforce` mode configured for a type that is later removed from the workflow resolves to `off`.
