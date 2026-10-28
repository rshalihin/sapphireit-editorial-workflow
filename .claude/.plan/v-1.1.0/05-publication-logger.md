# 05: Publication logger (closes G2)

**Goal:** every time managed content goes live or stops being live, the activity log says who did it, when, and at what workflow status, **in every gate mode, including `off`**. This phase alone closes roadmap gap G2.

**Prerequisites:** 04. **Roadmap:** §3.1B, plus its acceptance: "a post published while at `review` produces an activity entry naming the actor, the time and the workflow status at publication, and does so with the gate `off`". **Decisions:** E6, E7.

**Files:**
- Create: `includes/Publishing/RequestSource.php`
- Create: `includes/Publishing/PublicationLogger.php`
- Modify: `includes/Core/Plugin.php` (factory + `BOOT_PHASES['core']`)
- Test: `tests/php/integration/Publishing/PublicationLoggerTest.php` (new), `tests/php/integration/PluginTest.php` (extend)

**Interfaces:**
- Consumes: `ActivityLogger::log_publication_event()` (04), `PostRepository::get_status()`, `Settings::is_post_type_enabled()`, `Settings::gate_modes()` (02).
- Produces:
  - `RequestSource::CRON|CLI|REST|AJAX|ADMIN|OTHER` (values `cron`, `cli`, `rest`, `ajax`, `admin`, `other`).
  - `RequestSource::detect(): string`. Checks run in that order: `wp_doing_cron()`, `defined( 'WP_CLI' ) && WP_CLI`, `defined( 'REST_REQUEST' ) && REST_REQUEST`, `wp_doing_ajax()`, `is_admin()`.
  - `PublicationLogger::__construct( ActivityLogger $activity, PostRepository $posts, Settings $settings )`.
  - `PublicationLogger::register(): void`, which hooks `transition_post_status` at 10 with 3 args.
  - `PublicationLogger::handle_transition( $new_status, $old_status, $post ): void`.

> `gate_mode` in the context is read here from `Settings::gate_modes()` (raw), not from `PublishGate::mode_for()`, which does not exist until 06. Phase 06 switches this one call to `PublishGate::mode_for()` (noted there), so the logged mode includes the filter.

---

- [ ] **Step 1: Write the failing tests** `tests/php/integration/Publishing/PublicationLoggerTest.php`

  Extend `TestCase` and use `CreatesWorkflowPosts`. In `set_up()`, build `new PublicationLogger( $this->activity, $this->posts, new Settings() )` and call `register()`. In `tear_down()`, `remove_action()` it.

```php
public function test_publishing_at_review_with_gate_off_logs_actor_time_and_workflow_status() {
	$author  = self::fixture_user( 'author' );
	$post_id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $author ) );
	$this->posts->set_status( $post_id, 'review' );
	wp_set_current_user( $author );

	$before = gmdate( 'Y-m-d H:i:s', time() - 1 );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );

	$entries = $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) );
	$this->assertCount( 1, $entries );
	$this->assertSame( $author, $entries[0]->get_user_id() );
	$this->assertSame( 'draft', $entries[0]->get_old_value() );
	$this->assertGreaterThanOrEqual( $before, $entries[0]->get_created_at() );
	$this->assertSame( 'review', $entries[0]->get_context()['workflow_status'] );
	$this->assertSame( 'off', $entries[0]->get_context()['gate_mode'] );
}

public function test_unpublishing_and_trashing_log_post_unpublished() {
	$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	wp_trash_post( $post_id );
	$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'post_unpublished' ) );
	$this->assertCount( 2, $rows );
	$this->assertSame( 'trash', $rows[0]->get_new_value() ); // Newest first.
	$this->assertSame( 'draft', $rows[1]->get_new_value() );
}

public function test_updating_a_live_post_logs_nothing() {
	$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	$count   = $this->activity->count_for_post( $post_id );
	wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Edited' ) );
	$this->assertSame( $count, $this->activity->count_for_post( $post_id ) );
}

public function test_scheduled_publication_is_logged_as_system_from_future() {
	$post_id = self::factory()->post->create( array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
	wp_set_current_user( 0 );
	wp_publish_post( $post_id );
	$entry = $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) )[0];
	$this->assertSame( 0, $entry->get_user_id() );
	$this->assertSame( 'future', $entry->get_old_value() );
}

public function test_unmanaged_post_types_are_not_logged() {
	register_post_type( 'sit_cwm_unmanaged', array( 'public' => true ) );
	$post_id = self::factory()->post->create( array( 'post_type' => 'sit_cwm_unmanaged', 'post_status' => 'draft' ) );
	wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
	$this->assertSame( 0, $this->activity->count_for_post( $post_id ) );
	unregister_post_type( 'sit_cwm_unmanaged' );
}

public function test_revisions_and_autosaves_are_ignored() {
	$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	wp_save_post_revision( $post_id );
	$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ) );
}

public function test_insert_directly_as_publish_logs_from_new() {
	$post_id = wp_insert_post( array( 'post_title' => 'Direct', 'post_status' => 'publish' ) );
	$this->assertSame( 'new', $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) )[0]->get_old_value() );
}
```

  Also add a `RequestSource` assertion: in the integration run (no REST/cron/CLI), `detect()` returns `'other'`. Not every branch is unit-testable, so don't force the others.

  In `PluginTest.php`, assert `$plugin->container()->get( 'publication_logger' )` is a `PublicationLogger`, and that `has_action( 'transition_post_status', array( $logger, 'handle_transition' ) )` is `10` after boot.

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'PublicationLoggerTest|PluginTest'`
  Expected: FAIL, class not found.

- [ ] **Step 3: Implement `RequestSource`**

  `final class` with the six constants and `public static function detect(): string`, in the order given above. Write a docblock on why the order matters: a cron request run from WP-CLI is cron.

- [ ] **Step 4: Implement `PublicationLogger`** (`final`, `implements Bootable`)

  `handle_transition()` does the following:
  - Return unless `$post instanceof WP_Post`, the statuses are strings, and `$new_status !== $old_status`.
  - Return unless `settings->is_post_type_enabled( $post->post_type )`.
  - Return for `wp_is_post_revision()` / `wp_is_post_autosave()`.
  - Into publish → `post_published`. Out of publish → `post_unpublished`. Otherwise return.
  - Context: `workflow_status` = `$this->posts->get_status( $post->ID )`, `gate_mode` = `$this->settings->gate_modes()[ $post->post_type ] ?? 'off'`, `source` = `RequestSource::detect()`.
  - User: `get_current_user_id()`.
  - Wrap the body in `try/catch ( Throwable )` and do nothing on failure. Logging must never break a save; same fail-open principle as E5.

- [ ] **Step 5: Wire it in `Plugin`**

  - Factory: `'publication_logger'` → `new PublicationLogger( $c->get( 'activity_logger' ), $c->get( 'post_repository' ), $c->get( 'settings' ) )`.
  - Append `'publication_logger'` to `BOOT_PHASES['core']` after `workflow_manager`.

- [ ] **Step 6: Run the tests to verify they pass**

  Run the Step 2 command, then the full integration suite.
  Expected: PASS. Existing suites that publish posts now also get publication rows. Any test asserting an exact activity count on a post it publishes needs its expectation adjusted; review each such change, don't blanket-update.

- [ ] **Step 7: Lint and hand off**

  Run: `composer lint`.
  Suggested message: `feat(activity): log publication and unpublication of managed content in every gate mode`.

## Acceptance criteria

- Roadmap §3.1B acceptance holds with no gate configured.
- Scheduled publication is attributed to the system (user 0) with `old_value` `future`.
- No row for unmanaged types, revisions or in-place updates of live posts.
