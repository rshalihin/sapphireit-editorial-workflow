# 13: Notification messages, dedup and delivery

**Goal:** turn workflow actions into plain-text mail.
- Only for events the site enabled.
- One mail per recipient, in the recipient's language.
- Comment text only for people allowed to read activity.
- No double send on a double save.

The module touches nothing outside `includes/Notifications/` except its container wiring. Removing the `notifications` boot entry removes the feature (roadmap: "self-contained and removable").

**Prerequisites:** 12. **Roadmap:** §3.1C and its three acceptance lines. **Decisions:** E11, E12, E13, E14.

**Files:**
- Create: `includes/Notifications/MessageBuilder.php`
- Create: `includes/Notifications/Deduplicator.php`
- Create: `includes/Notifications/Notifier.php`
- Modify: `includes/Core/Plugin.php` (factories `notifications.messages`, `notifications.dedup`, `notifications`; `BOOT_PHASES['core']`)
- Test: `tests/php/integration/Notifications/MessageBuilderTest.php`, `DeduplicatorTest.php`, `NotifierTest.php` (new)

**Interfaces:**
- Consumes:
  - `RecipientResolver::resolve()` (12);
  - `Settings::is_notification_enabled()` (02);
  - `NotificationEvents::for_status()` (02);
  - `PermissionManager::can_view_activity( int $post_id, ?int $user_id )`;
  - `StatusManager::label()`;
  - the 1.0 actions `sit_cwm_status_changed( $post_id, $from, $to, $user_id )`, `sit_cwm_reviewer_assigned( $post_id, $reviewer_id, $previous_id, $user_id )`, `sit_cwm_comment_added( $post_id, $activity_id, $message, $user_id )`.
- Produces:
  - `MessageBuilder::__construct( PermissionManager $permissions )`; `MessageBuilder::build( string $event, int $post_id, int $recipient_id, int $actor_id, array $args = array() ): array{subject: string, body: string}`. `$args` keys: `message` (comment HTML) for `comment_added`.
  - `Deduplicator::WINDOW = 120`; `Deduplicator::PREFIX = 'sit_cwm_mail_'`; `Deduplicator::claim( string $event, int $post_id, int $recipient_id, string $fingerprint ): bool`.
  - `Notifier::__construct( Settings $settings, RecipientResolver $recipients, MessageBuilder $messages, Deduplicator $dedup )`; `register(): void` hooks the three actions at priority 20.
  - Handlers `on_status_changed()`, `on_reviewer_assigned()`, `on_comment_added()`.

### Fixed mail body copy

`%1$s` is the actor's display name (`System` for 0) and `%2$s` is the post title (tags stripped, entities decoded) in every first line.

| Event | First line |
|---|---|
| `reviewer_assigned` | `%1$s asked you to review “%2$s”.` |
| `review_requested` | `%1$s submitted “%2$s” for review.` |
| `changes_requested` | `%1$s asked for changes to “%2$s”.` |
| `approved` | `%1$s approved “%2$s”.` |
| `comment_added`, may see activity | `%1$s commented on “%2$s”:` then a blank line, then the comment as plain text (`wp_strip_all_tags( wp_specialchars_decode( $message ) )`) |
| `comment_added`, may not | `%1$s added a workflow comment to “%2$s”.` |

Then a blank line, then `Open it: %s` (`get_edit_post_link( $post_id, 'raw' )`), a blank line, and the footer `You are receiving this because email notifications are turned on for %s.` (site name). The site name is `wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )`. Subjects are the E14 strings.

---

- [ ] **Step 1: Write the failing message tests** `MessageBuilderTest.php`

```php
public function test_approved_message_subject_and_body() {
	update_option( 'blogname', 'Acme & Co' );
	$post_id = self::factory()->post->create( array( 'post_title' => 'Q3 <b>launch</b>', 'post_author' => self::fixture_user( 'author' ) ) );
	$m = $this->builder->build( 'approved', $post_id, self::fixture_user( 'author' ), self::fixture_user( 'editor' ) );
	$this->assertSame( '[Acme & Co] Approved: Q3 launch', $m['subject'] );
	$this->assertStringStartsWith( 'Editor Fixture approved “Q3 launch”.', $m['body'] );
	$this->assertStringContainsString( 'Open it: ' . get_edit_post_link( $post_id, 'raw' ), $m['body'] );
	$this->assertStringNotContainsString( '<', $m['body'] );
}

/** Roadmap C: no comment body without sit_cwm_view_activity. */
public function test_comment_body_only_for_recipients_who_may_view_activity() {
	$post_id = $this->fixture_post();
	$with    = $this->builder->build( 'comment_added', $post_id, self::fixture_user( 'reviewer' ), self::fixture_user( 'editor' ), array( 'message' => '<p>Cut the <em>intro</em>.</p>' ) );
	$this->assertStringContainsString( 'Cut the intro.', $with['body'] );

	$no_view = $this->create_user_with_caps( array( 'edit_posts', 'edit_others_posts', 'read' ), 'subscriber' ); // Can read, lacks sit_cwm_view_activity.
	$without = $this->builder->build( 'comment_added', $post_id, $no_view, self::fixture_user( 'editor' ), array( 'message' => '<p>Secret</p>' ) );
	$this->assertStringNotContainsString( 'Secret', $without['body'] );
	$this->assertStringContainsString( 'added a workflow comment to', $without['body'] );
}

public function test_message_is_built_in_the_recipients_locale() {
	// update_user_meta( $recipient, 'locale', 'de_DE' ); hook 'gettext' for the plugin domain to prefix "[de] "
	// when determine_locale() === 'de_DE'; assert subject starts with "[de] ". Then determine_locale() is back to the site locale.
}

public function test_system_actor_is_named_system() { /* actor 0 → body starts with "System " */ }

public function test_message_filter_can_override_and_bad_return_falls_back() {
	// sit_cwm_notification_message returning [ 'subject' => 'X', 'body' => 'Y' ] → used; returning 'nope' → original.
}
```

- [ ] **Step 2: Write the failing dedup tests** `DeduplicatorTest.php`

```php
public function test_second_claim_within_window_is_refused() {
	$d = new Deduplicator();
	$this->assertTrue( $d->claim( 'approved', 5, 7, 'approved' ) );
	$this->assertFalse( $d->claim( 'approved', 5, 7, 'approved' ) );
	$this->assertTrue( $d->claim( 'approved', 5, 8, 'approved' ) ); // Other recipient.
	$this->assertTrue( $d->claim( 'comment_added', 5, 7, md5( 'b' ) ) );
}

public function test_claim_expires_after_window() {
	// set_transient TTL assertion: get_option( '_transient_timeout_' . key ) - time() is within (0, 120].
}
```

- [ ] **Step 3: Write the failing notifier tests** `NotifierTest.php`

  Capture mail with `tests_retrieve_phpmailer_instance()` (MockPHPMailer from the WP test library; reset it in `set_up`). Drive events through the **real** `WorkflowManager` from the container, so the 1.0 actions fire as in production. Enable events via `Settings::update()`.

```php
/** Roadmap C: exactly one mail to the correct recipient. */
public function test_each_event_sends_exactly_one_mail_to_the_correct_recipient() {
	// For each of the five events: enable only that event, perform the action as the fixture actor,
	// assert count( mailer->mock_sent ) === 1 and the "to" address is the expected fixture user's email.
}

/** Roadmap C: disabling an event stops its mail. */
public function test_disabled_event_sends_nothing() {
	$this->enable( array( 'approved' => false, 'review_requested' => true ) );
	$this->move_to_approved_as_editor();
	$this->assertCount( 0, $this->sent() );
}

public function test_default_settings_send_nothing_for_any_event() { /* fresh option: run all five actions → 0 mails */ }

/** Roadmap C: "a double save does not double send". */
public function test_same_event_twice_within_window_sends_once() {
	// do_action( 'sit_cwm_status_changed', $id, 'needs_changes', 'approved', $editor ) twice → 1 mail.
}

public function test_two_different_comments_both_send() { /* two add_comment() calls with different text → 2 mails */ }

public function test_reviewer_cleared_sends_nothing() { /* WorkflowManager::assign_reviewer( $id, 0 ) → 0 mails */ }

public function test_wp_mail_failure_does_not_break_the_transition() {
	// add_filter( 'pre_wp_mail', '__return_false' ); transition still returns true; sit_cwm_notification_sent fired with $sent false.
}

public function test_notifier_exception_does_not_break_the_transition() {
	// sit_cwm_notification_recipients throws → transition() still returns true; no mail.
}

public function test_sent_action_fires_per_recipient() { /* comment by editor → 2 sit_cwm_notification_sent calls */ }
```

- [ ] **Step 4: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter 'MessageBuilderTest|DeduplicatorTest|NotifierTest'`
  Expected: FAIL, classes not found.

- [ ] **Step 5: Implement `Deduplicator`**

  - Key: `PREFIX . md5( "$event|$post_id|$recipient_id|$fingerprint" )`.
  - `claim()`: `false !== get_transient( $key )` → return false; else `set_transient( $key, 1, self::WINDOW )` and return true.
  - Docblock: not atomic across parallel requests; good enough for the double-save case it targets (E13).

- [ ] **Step 6: Implement `MessageBuilder`**

  - `$switched = switch_to_user_locale( $recipient_id );` then build the subject and body from the copy above with `__()`/`sprintf()` and `/* translators: */` comments; `if ( $switched ) { restore_previous_locale(); }` in a `finally`.
  - Title: `wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES )`, with the fallback `__( '(no title)', … )`.
  - Actor name: `get_userdata( $actor_id )->display_name`, or `__( 'System', … )` for 0, or `__( 'Someone', … )` for a deleted user. These match the timeline's wording in `ActivityItem.jsx`.
  - Apply `sit_cwm_notification_message` and accept the result only if it is an array with string `subject` and `body`.

- [ ] **Step 7: Implement `Notifier`** (`final`, `implements Bootable`)

  - `register()`: the three `add_action()`s at 20 (after the 1.0 core work at 10), with arg counts 4, 4 and 4.
  - **`on_status_changed( $post_id, $from, $to, $user_id )`:** `$event = NotificationEvents::for_status( (string) $to )`; return when null; then `dispatch( $event, (int) $post_id, (int) $user_id, array( 'fingerprint' => (string) $to ) )`.
  - **`on_reviewer_assigned( $post_id, $reviewer_id, $previous_id, $user_id )`:** return when `(int) $reviewer_id <= 0`; else `dispatch( 'reviewer_assigned', …, array( 'reviewer_id' => (int) $reviewer_id, 'fingerprint' => (string) $reviewer_id ) )`.
  - **`on_comment_added( $post_id, $activity_id, $message, $user_id )`:** `dispatch( 'comment_added', …, array( 'message' => (string) $message, 'fingerprint' => md5( (string) $message ) ) )`.
  - **`dispatch( string $event, int $post_id, int $actor_id, array $args ): void`**, inside `try/catch ( Throwable )` that swallows; mail must never break a workflow action:
    1. return unless `settings->is_notification_enabled( $event )`;
    2. `foreach ( recipients->resolve( $event, $post_id, $actor_id, $args['reviewer_id'] ?? 0 ) as $recipient )`:
       - `if ( ! dedup->claim( $event, $post_id, $recipient, $args['fingerprint'] ) ) continue;`
       - `$m = messages->build( … )`;
       - `$sent = wp_mail( get_userdata( $recipient )->user_email, $m['subject'], $m['body'] )`;
       - `do_action( 'sit_cwm_notification_sent', $event, $post_id, $recipient, (bool) $sent )`.
  - Mail goes out synchronously; there is no queue (YAGNI for 1.1). Bulk actions therefore send one mail per affected post per recipient. Note that in the class docblock: digests are Pro.

- [ ] **Step 8: Wire it in `Plugin`**

  - Factories `notifications.messages`, `notifications.dedup` and `notifications`.
  - Append `notifications` to `BOOT_PHASES['core']`, last.
  - `PluginTest`: the three actions have `Notifier` callbacks at 20.

- [ ] **Step 9: Run the tests to verify they pass**

  Run the Step 4 command, then the full integration suite.
  Expected: PASS. Existing suites send no mail, because every event defaults off.

- [ ] **Step 10: Lint and hand off**

  Run: `composer lint`.
  Suggested message: `feat(notifications): send opt-in plain-text workflow emails with dedup and access-aware comment text`.

## Acceptance criteria

All three roadmap §3.1C acceptance lines are passing tests:
- one mail per correct recipient;
- no comment body for a recipient without `sit_cwm_view_activity`;
- disabling an event stops its mail.

Also: a mail failure or exception never changes the outcome of the workflow action that triggered it.
