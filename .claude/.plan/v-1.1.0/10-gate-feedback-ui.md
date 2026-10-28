# 10: Gate feedback: REST hint, editor notice, publish-button refresh

**Goal:** people understand why Publish turned into Submit for Review, and the editor catches up the moment content is approved.
- The workflow REST payload reports the gate state (E10).
- The shared `WorkflowPanel` (sidebar + classic meta box) shows one plain-language notice.
- After a status change in the block editor, the post entity is re-fetched, so the `wp:action-publish` link (phase 08) and the Publish button update without a page reload.

**Prerequisites:** 08, 09. **Roadmap:** §3.1A "The user gets a correct UI with no JavaScript written for it". The editor does adapt by itself, but only on load; the refresh closes the gap after an in-session approval. **Decisions:** E10, E14.

**Files:**
- Modify: `includes/REST/WorkflowController.php` (constructor, `workflow_response()`, `get_item_schema()`)
- Modify: `includes/Core/Plugin.php` (`rest.workflow` factory gains `publish_gate`)
- Create: `src/components/PublishGateNotice.jsx`
- Create: `src/hooks/useRefreshEditorPost.js`
- Modify: `src/components/WorkflowPanel.jsx` (render the notice; new optional prop `onStatusChanged`)
- Modify: `src/sidebar/Sidebar.jsx` (pass `onStatusChanged={ refreshEditorPost }`)
- Modify: `src/sidebar/sidebar.scss` (spacing for `.sit-cwm-gate-notice` only, if needed)
- Test: `tests/php/integration/REST/WorkflowControllerTest.php`, `tests/php/integration/REST/SchemaContractTest.php`, `tests/js/PublishGateNotice.test.jsx` (new), `tests/js/useRefreshEditorPost.test.js` (new), `tests/js/ClassicPanel.test.jsx` (extend)

**Interfaces:**
- Consumes: `PublishGate::describe( int $post_id, int $user_id ): array{mode, outcome}` (06); `useWorkflow().updateStatus` (existing).
- Produces:
  - REST field `publish_gate: { mode: string, outcome: string }` on every workflow response.
  - `<PublishGateNotice gate={ workflow.publish_gate } postStatus={ workflow.post_status } />`, which returns `null` when nothing should be said.
  - `useRefreshEditorPost(): () => Promise<void>`.
  - `WorkflowPanel` prop `onStatusChanged?: () => void`, called after a successful `updateStatus`.

---

- [ ] **Step 1: Write the failing PHP tests**

  `WorkflowControllerTest.php`:

```php
public function test_workflow_response_reports_gate_state() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	wp_set_current_user( self::fixture_user( 'author' ) );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => self::fixture_user( 'author' ) ) );
	$data = $this->get_workflow( $post_id );
	$this->assertSame( array( 'mode' => 'enforce', 'outcome' => 'refused' ), $data['publish_gate'] );
}

public function test_gate_state_updates_in_the_transition_response() {
	// Editor moves review → approved via POST …/workflow; response publish_gate.outcome === 'allowed'.
}

public function test_gate_state_is_not_applicable_when_off() { /* default settings → mode off, outcome not_applicable */ }
```

  `SchemaContractTest.php`: the workflow item schema has `publish_gate`, an `object` with `mode` (enum = `PublishGate::MODES`) and `outcome` (enum = the four `GateDecision::OUTCOME_*`).

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'WorkflowControllerTest|SchemaContractTest'`
  Expected: FAIL, missing key.

- [ ] **Step 3: Implement**

  - `WorkflowController::__construct()` gains a final parameter, `PublishGate $gate`.
  - `workflow_response()` adds `'publish_gate' => $this->gate->describe( $post_id, get_current_user_id() )` to the array it builds from `WorkflowManager::get_workflow()`. The controller only relays; the gate decides.
  - Add the schema property with `readonly: true`, `context: [ 'view', 'edit' ]`.
  - Update the `rest.workflow` factory.

  Run the Step 2 command. Expected: PASS.

- [ ] **Step 4: Write the failing Jest tests**

  `tests/js/PublishGateNotice.test.jsx` (the strings are E14):

  | `gate` | `postStatus` | expected |
  |---|---|---|
  | `{ mode: 'off', outcome: 'not_applicable' }` | `draft` | renders nothing |
  | `{ mode: 'enforce', outcome: 'allowed' }` | `draft` | renders nothing |
  | `{ mode: 'enforce', outcome: 'refused' }` | `draft` | info notice `Publishing is locked until this content is approved.` |
  | `{ mode: 'log_only', outcome: 'bypassed' }` | `draft` | warning notice `This content is not approved yet. Publishing it now will be recorded as a bypass of the review.` |
  | `{ mode: 'enforce', outcome: 'bypassed' }` | `pending` | warning notice `This content is not approved yet. You can still publish it, and that will be recorded as a bypass of the review.` |
  | `{ mode: 'enforce', outcome: 'refused' }` | `publish` | renders nothing (live post, carve-out) |
  | `undefined` | `draft` | renders nothing (older server, no field) |

  Assert the notices are not dismissible and carry the class `sit-cwm-gate-notice`.

  `tests/js/useRefreshEditorPost.test.js`: mock `@wordpress/data`. Calling the returned function:
  - calls `invalidateResolution( 'getEntityRecord', [ 'postType', 'post', 42 ] )`;
  - then `resolveSelect( coreStore ).getEntityRecord( 'postType', 'post', 42 )`, for the current editor post type/id;
  - resolves to `undefined`;
  - never rejects; a rejected re-fetch is swallowed, because the button refreshing late is harmless.

  `tests/js/ClassicPanel.test.jsx`: with `publish_gate: { mode: 'enforce', outcome: 'refused' }` in the mocked workflow, the classic panel shows the locked notice. `onStatusChanged` is not required there.

  Also extend the existing `WorkflowPanel` / `TransitionActions` tests: after a successful transition, `onStatusChanged` is called once; after a failed one (409), it is not.

- [ ] **Step 5: Run them to verify they fail**

  Run: `npm run test:unit -- tests/js/PublishGateNotice.test.jsx tests/js/useRefreshEditorPost.test.js tests/js/ClassicPanel.test.jsx tests/js/TransitionActions.test.jsx`
  Expected: FAIL, modules not found.

- [ ] **Step 6: Implement the JS**

  - **`PublishGateNotice.jsx`:**
    - `Notice` from `@wordpress/components`, `isDismissible={ false }`, `className="sit-cwm-gate-notice"`;
    - `status` `info` for refused, `warning` for bypassed;
    - strings via `__()` with the plugin text domain;
    - the early-return rules come from the table above.
  - **`useRefreshEditorPost.js`:**
    - reads `getCurrentPostType()`/`getCurrentPostId()` from `editorStore`;
    - `useDispatch( coreStore ).invalidateResolution`;
    - `resolveSelect` from `@wordpress/data`;
    - `try/catch` around the re-fetch.

    Put a comment on why: the block editor reads `wp:action-publish` from the post entity's `_links`, which the server now computes from the gate (phase 08).
  - **`WorkflowPanel.jsx`:**
    - render `<PublishGateNotice gate={ workflow.publish_gate } postStatus={ workflow.post_status } />` directly under the status badge;
    - wrap `updateStatus` so that on a result without `error`, `onStatusChanged?.()` runs.
  - **`Sidebar.jsx`:** `const refreshEditorPost = useRefreshEditorPost();` and pass it. The classic mount passes nothing, because the classic Publish button is rendered by PHP.

- [ ] **Step 7: Run the tests to verify they pass**

  Run the Step 5 command, then the whole Jest suite: `npm run test:unit`.
  Expected: PASS.

- [ ] **Step 8: Lint, build, translations check, hand off**

  Run: `composer lint && npm run lint:js && npm run lint:css && npm run build`.
  Expected: clean, and `assets/build/sidebar.js`/`classic.js` rebuilt with unchanged `*.asset.php` dependencies, apart from `wp-core-data` if newly pulled in. Run `AssetCompatTest`; it must still pass against WP 6.8.

  Suggested message: `feat(gate): surface gate state in the workflow panel and refresh the publish button after approval`.

## Acceptance criteria

- The panel says exactly one E14 sentence when the gate restricts or records, and nothing otherwise.
- In the block editor, approving content from the sidebar makes the Publish button available without a reload. This is verified in E2E in phase 15.
- An older client or server pairing (field absent) degrades to no notice.
