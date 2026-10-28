# 12: Notification recipients

**Goal:** a pure service that answers "who should get mail for this event on this post", applying every safety rule in E13. No mail is sent in this phase.

**Prerequisites:** 02 (`NotificationEvents`). **Roadmap:** §3.1C, "each event sends exactly one mail to the correct recipient". **Decisions:** E12, E13.

**Files:**
- Create: `includes/Notifications/RecipientResolver.php`
- Modify: `includes/Core/Plugin.php` (factory `notifications.recipients`; not bootable)
- Test: `tests/php/integration/Notifications/RecipientResolverTest.php` (new)

**Interfaces:**
- Consumes: `PostRepository::get_reviewer_id()`, `PermissionManager::can_read_post( int $post_id, ?int $user_id )`, `NotificationEvents` constants.
- Produces:
  - `RecipientResolver::__construct( PostRepository $posts, PermissionManager $permissions )`
  - `RecipientResolver::resolve( string $event, int $post_id, int $actor_id, int $reviewer_id = 0 ): int[]`, returned in a stable order (author before reviewer). `$reviewer_id` is only used for `reviewer_assigned`, where it is the newly assigned reviewer; other events read the stored reviewer.

---

- [ ] **Step 1: Write the failing tests** `RecipientResolverTest.php`

  Extend `TestCase` and use `CreatesWorkflowPosts`. The fixture post is authored by `author` with reviewer `reviewer`, set via `$this->posts->set_reviewer_id()`.

```php
/**
 * @dataProvider data_routing
 */
public function test_routing( string $event, string $actor_key, array $expected_keys ) {
	$post_id  = $this->fixture_post();
	$expected = array_map( array( self::class, 'fixture_user' ), $expected_keys );
	$this->assertSame( $expected, $this->resolver->resolve( $event, $post_id, self::fixture_user( $actor_key ), self::fixture_user( 'reviewer' ) ) );
}

public function data_routing() {
	return array(
		'assigned → reviewer'            => array( 'reviewer_assigned', 'editor', array( 'reviewer' ) ),
		'review requested → reviewer'    => array( 'review_requested', 'author', array( 'reviewer' ) ),
		'changes requested → author'     => array( 'changes_requested', 'reviewer', array( 'author' ) ),
		'approved → author'              => array( 'approved', 'editor', array( 'author' ) ),
		'comment by reviewer → author'   => array( 'comment_added', 'reviewer', array( 'author' ) ),
		'comment by author → reviewer'   => array( 'comment_added', 'author', array( 'reviewer' ) ),
		'comment by third party → both'  => array( 'comment_added', 'editor', array( 'author', 'reviewer' ) ),
		'self-assignment → nobody'       => array( 'reviewer_assigned', 'reviewer', array() ),
		'author approves own → nobody'   => array( 'approved', 'author', array() ),
	);
}

public function test_review_requested_without_reviewer_goes_to_nobody() {
	$post_id = $this->fixture_post();
	$this->posts->set_reviewer_id( $post_id, 0 );
	$this->assertSame( array(), $this->resolver->resolve( 'review_requested', $post_id, self::fixture_user( 'author' ) ) );
}

/** Review Focus 5. */
public function test_recipients_exclude_actor_missing_unreadable_and_emailless_users() {
	$post_id = $this->fixture_post();
	$no_mail = self::factory()->user->create( array( 'role' => 'editor' ) );
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->users, array( 'user_email' => '' ), array( 'ID' => $no_mail ) );
	clean_user_cache( $no_mail );
	$outsider = self::fixture_user( 'subscriber' ); // Cannot read a draft.
	add_filter( 'sit_cwm_notification_recipients', function ( $ids ) use ( $no_mail, $outsider ) {
		return array_merge( $ids, array( $no_mail, $outsider, 999999, 'x', -3, self::fixture_user( 'editor' ) ) );
	} );
	// Actor = editor, so the editor must be removed even though the filter re-added them.
	$this->assertSame( array( self::fixture_user( 'author' ) ), $this->resolver->resolve( 'approved', $post_id, self::fixture_user( 'editor' ) ) );
}

/** Review Focus 5. */
public function test_reviewer_cleared_by_user_deletion_resolves_no_recipients() {
	$this->assertSame( array(), $this->resolver->resolve( 'reviewer_assigned', $this->fixture_post(), 0, 0 ) );
}

public function test_duplicates_are_collapsed() {
	$post_id = $this->fixture_post();
	$this->posts->set_reviewer_id( $post_id, self::fixture_user( 'author' ) ); // Author reviews own post (allowed when they hold the cap).
	$this->assertCount( 1, $this->resolver->resolve( 'comment_added', $post_id, self::fixture_user( 'editor' ) ) );
}

public function test_unknown_event_or_missing_post_resolves_nobody() {
	$this->assertSame( array(), $this->resolver->resolve( 'slack', $this->fixture_post(), 0 ) );
	$this->assertSame( array(), $this->resolver->resolve( 'approved', 999999, 0 ) );
}

public function test_filter_receives_event_post_and_actor() { /* assert filter args ( array, 'approved', $post_id, $actor ) */ }
```

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter RecipientResolverTest`
  Expected: FAIL, class not found.

- [ ] **Step 3: Implement `RecipientResolver`** (`final`)

  1. `$post = get_post( $post_id )`. Return `array()` if it is missing or `! in_array( $event, NotificationEvents::all(), true )`.
  2. Base list from the E12 table:
     - `reviewer_assigned` → `array( $reviewer_id )`;
     - `review_requested` → `array( posts->get_reviewer_id( $post_id ) )`;
     - `changes_requested` / `approved` → `array( (int) $post->post_author )`;
     - `comment_added` → `array( (int) $post->post_author, posts->get_reviewer_id( $post_id ) )`.
  3. `apply_filters( 'sit_cwm_notification_recipients', $ids, $event, $post_id, $actor_id )`, with a docblock that says it runs **before** the safety filters, so it can add people but never people who may not see the post. A non-array result → the unfiltered base list.
  4. Normalise:
     - keep only `is_int()` or numeric-string values, `(int)` them, keep those `> 0`;
     - `array_unique` while preserving first-seen order;
     - remove `$actor_id`.
  5. Keep only users where `get_userdata()` is a `WP_User` and `is_email( $user->user_email )`.
  6. Keep only users passing `permissions->can_read_post( $post_id, $id )`.
  7. `array_values()`.

  Run the Step 2 command. Expected: PASS.

- [ ] **Step 4: Wire the factory** `notifications.recipients`, then lint and hand off

  Run: `composer lint`.
  Suggested message: `feat(notifications): resolve notification recipients with actor, access and email safety filters`.

## Acceptance criteria

- The E12 routing holds for all five events. The actor, nonexistent users, users without a valid email and users who cannot read the post never appear, whatever an extension adds.
- No recipients when no reviewer is assigned for `review_requested`, or for the reviewer-cleared signal (`$reviewer_id` 0).
