# 04: Publication activity actions + formatter + timeline copy

**Goal:** the activity layer can store, expose and render the four new actions (E7). Nothing writes them yet; that starts in 05.

**Prerequisites:** 02. **Roadmap:** §3.1B "four new action types … each recording the workflow status at that moment". **Decisions:** E7, E14.

**Files:**
- Modify: `includes/Activity/ActivityLogger.php`
- Modify: `includes/REST/ActivityFormatter.php`
- Modify: `includes/REST/ActivityController.php` (item schema, around line 381)
- Modify: `src/components/ActivityItem.jsx` (`sentenceTemplate()` cases, around line 120)
- Test: `tests/php/integration/ActivityLoggerTest.php`, `tests/php/integration/REST/ActivityControllerTest.php`, `tests/php/integration/REST/SchemaContractTest.php`, `tests/js/ActivityTimeline.test.jsx` (all extended)

**Interfaces:**
- Consumes: `ActivityLogger::log()`, `ActivityEntry::get_context()`.
- Produces:
  - `ActivityLogger::PUBLICATION_ACTIONS = array( 'post_published', 'post_unpublished', 'publish_gate_refused', 'publish_gate_bypassed' )`, appended to `ACTIONS`.
  - `ActivityLogger::CONTEXT_KEYS = array( 'workflow_status', 'gate_mode', 'layer', 'source', 'reason' )`.
  - `ActivityLogger::log_publication_event( int $post_id, string $action, string $old_status, string $new_status, array $context, ?int $user_id = null ): int`. It returns `0` for an action outside `PUBLICATION_ACTIONS`, drops context keys outside `CONTEXT_KEYS`, and `sanitize_key()`s every value.
  - The formatter item gains `workflow_status`, `workflow_status_label` and `gate_reason` (all `string|null`).

---

- [ ] **Step 1: Write the failing logger tests** (append to `ActivityLoggerTest.php`)

```php
public function test_publication_actions_are_registered() {
	foreach ( ActivityLogger::PUBLICATION_ACTIONS as $action ) {
		$this->assertTrue( $this->activity->is_valid_action( $action ), $action );
	}
}

public function test_log_publication_event_stores_status_columns_and_whitelisted_context() {
	$post_id = self::factory()->post->create();
	$id      = $this->activity->log_publication_event(
		$post_id, 'post_published', 'draft', 'publish',
		array( 'workflow_status' => 'review', 'gate_mode' => 'off', 'source' => 'rest', 'evil' => '<b>x</b>' ),
		7
	);
	$entry = $this->activity->get( $id );
	$this->assertSame( 'draft', $entry->get_old_value() );
	$this->assertSame( 'publish', $entry->get_new_value() );
	$this->assertSame( 7, $entry->get_user_id() );
	$this->assertSame( array( 'workflow_status' => 'review', 'gate_mode' => 'off', 'source' => 'rest' ), $entry->get_context() );
}

public function test_log_publication_event_rejects_non_publication_actions() {
	$this->assertSame( 0, $this->activity->log_publication_event( self::factory()->post->create(), 'comment_added', 'draft', 'publish', array() ) );
}

public function test_log_publication_event_sanitizes_context_values() {
	$id = $this->activity->log_publication_event( self::factory()->post->create(), 'publish_gate_refused', 'draft', 'publish', array( 'layer' => 'Save<script>' ) );
	$this->assertSame( 'savescript', $this->activity->get( $id )->get_context()['layer'] );
}
```

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter ActivityLoggerTest`
  Expected: FAIL, undefined constant / method.

- [ ] **Step 3: Implement in `ActivityLogger`**

  - Append the four slugs to `ACTIONS` and update the constant's docblock: "Action slugs accepted in v1.1 (D8 + E7)".
  - Add `PUBLICATION_ACTIONS` and `CONTEXT_KEYS`.
  - `log_publication_event()` builds `array( 'old_value' => $old_status, 'new_value' => $new_status, 'context' => $clean )` through the existing `with_user()` and calls `log()`. Mark it `@internal Callers must authorize first.`, like its siblings.

  Run the Step 2 command. Expected: PASS.

- [ ] **Step 4: Write the failing formatter/REST tests**

  In `ActivityControllerTest.php`:
  - Update the key-list assertion at line ~220 to append `'workflow_status', 'workflow_status_label', 'gate_reason'`.
  - Add:

```php
public function test_publication_entry_exposes_post_status_labels_and_workflow_status() {
	$post_id = $this->managed_post(); // Existing helper or factory + enabled type.
	$this->activity->log_publication_event( $post_id, 'post_published', 'auto-draft', 'publish', array( 'workflow_status' => 'review', 'gate_mode' => 'log_only' ), self::fixture_user( 'admin' ) );
	$this->activity->log_publication_event( $post_id, 'publish_gate_bypassed', 'draft', 'publish', array( 'workflow_status' => 'review', 'reason' => 'log_only' ), self::fixture_user( 'admin' ) );
	wp_set_current_user( self::fixture_user( 'admin' ) );
	$data = $this->get_activity( $post_id ); // Newest first.

	$this->assertSame( 'Published without approval', $data[0]['action_label'] );
	$this->assertSame( 'log_only', $data[0]['gate_reason'] );
	$this->assertSame( 'Published', $data[1]['action_label'] );
	$this->assertSame( 'New', $data[1]['old_label'] );
	$this->assertSame( 'Published', $data[1]['new_label'] );      // get_post_status_object( 'publish' )->label
	$this->assertSame( 'review', $data[1]['workflow_status'] );
	$this->assertSame( 'Review', $data[1]['workflow_status_label'] );
	$this->assertNull( $data[1]['gate_reason'] );
}

public function test_legacy_entries_have_null_publication_fields() {
	$post_id = $this->managed_post();
	$this->activity->log_status_change( $post_id, 'draft', 'writing', self::fixture_user( 'admin' ) );
	wp_set_current_user( self::fixture_user( 'admin' ) );
	$item = $this->get_activity( $post_id )[0];
	$this->assertNull( $item['workflow_status'] );
	$this->assertNull( $item['workflow_status_label'] );
	$this->assertNull( $item['gate_reason'] );
}

public function test_action_filter_accepts_publication_actions() {
	// GET …/activity?action=post_published is a valid enum value, not a 400.
}
```

  Write the body of the last test in the file's existing request style. Assert status 200 and that only `post_published` rows come back.

  In `SchemaContractTest.php`, add the three properties to the expected activity item property list, each with type `array( 'string', 'null' )`.

- [ ] **Step 5: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'ActivityControllerTest|SchemaContractTest'`
  Expected: FAIL on the missing keys and labels.

- [ ] **Step 6: Implement**

  - `ActivityFormatter`:
    - add the four E14 labels to `action_label()`'s `$core`;
    - add `const PUBLICATION_ACTIONS = ActivityLogger::PUBLICATION_ACTIONS;` (or reference the logger constant directly);
    - in `value_label()`, for those actions return `$this->post_status_label( $value )`.
  - New `private function post_status_label( string $status ): string`:
    - `new` and `auto-draft` → `__( 'New', … )`;
    - otherwise `get_post_status_object( $status )->label`, falling back to the slug.
  - `format()` adds:
    - `'workflow_status'` → the context value when it is a non-empty string, else `null`;
    - `'workflow_status_label'` → `$this->statuses->label()` of it, else `null`;
    - `'gate_reason'` → the context `reason`, else `null`.

    Update the `@return` array shape.
  - `ActivityController` item schema: add the three properties, `'type' => array( 'string', 'null' )`, `'context' => array( 'view', 'edit' )`, `'readonly' => true`, with descriptions.

- [ ] **Step 7: Run the tests to verify they pass**

  Run the Step 5 command. Expected: PASS.

- [ ] **Step 8: Write the failing Jest tests** (append to `tests/js/ActivityTimeline.test.jsx`)

  Render `ActivityItem` with the fixtures below. Assert the rendered text, with `actor` = `Dana`:

  | `action` | fixture extras | expected text |
  |---|---|---|
  | `post_published` | `workflow_status_label: 'Review'` | `Dana published this while it was at Review` |
  | `post_published` | `user: null, user_id: 0, old_value: 'future', workflow_status_label: 'Approved'` | `System published this on schedule while it was at Approved` |
  | `post_unpublished` | `new_label: 'Draft'` | `Dana unpublished this (now Draft)` |
  | `publish_gate_refused` | `new_label: 'Published', workflow_status_label: 'Writing'` | `Publishing by Dana was blocked: it was at Writing, not approved` |
  | `publish_gate_bypassed` | `gate_reason: 'capability', workflow_status_label: 'Draft'` | `Dana published this without approval (it was at Draft)` |
  | `publish_gate_bypassed` | `gate_reason: 'error'` | `Dana published this without the approval check (internal error)` |

  Also assert a missing `workflow_status_label` degrades to the action label: the existing `default:` branch behaviour.

- [ ] **Step 9: Run them to verify they fail**

  Run: `npm run test:unit -- tests/js/ActivityTimeline.test.jsx`
  Expected: FAIL (the default branch renders only the label).

- [ ] **Step 10: Implement** the new `case`s in `sentenceTemplate()`

  Follow the existing pattern:
  - `sprintf()` over `__()` with `/* translators: */` comments;
  - placeholders `<actor />`, `<to />`, plus a new `<wfstatus />` tag.

  Fill `<wfstatus />` in the same `createInterpolateElement` map that fills `<to />`, from `entry.workflow_status_label` via `decodeText`. The scheduled variant is chosen when `entry.user_id === 0 && entry.old_value === 'future'`.

  Run the Step 9 command. Expected: PASS.

- [ ] **Step 11: Lint, rebuild, hand off**

  Run: `composer lint && npm run lint:js && npm run build`. Expected: clean, and `assets/build/` updated.
  Suggested message: `feat(activity): add publication and gate actions with workflow-status context`.

## Acceptance criteria

- The four actions can be logged, filtered by in REST, and render a readable sentence that names the workflow status at the time.
- 1.0 entries are unchanged, apart from three new `null` fields.
