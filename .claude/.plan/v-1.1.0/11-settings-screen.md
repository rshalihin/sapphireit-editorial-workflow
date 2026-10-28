# 11: Settings screen: Publish gate + Email notifications sections

**Goal:** site owners can choose a gate mode per enabled content type and toggle each email event. The page uses the same Settings API form, nonce and capability as 1.0. No new admin page and no restyle (E15).

**Prerequisites:** 02. Code-wise it is independent of 03–10, but the copy refers to their behaviour. **Roadmap:** §3.1A "configurable per post type", §3.1C "per-event site-level toggles". **Decisions:** E1, E12, E14.

**Files:**
- Modify: `admin/Settings.php` (two sections, two fields, `sanitize()`, help tab)
- Test: `tests/php/integration/Admin/SettingsPageTest.php` (extend)

**Interfaces:**
- Consumes:
  - `Settings::defaults()`, `gate_modes()`, `is_notification_enabled()`, `enabled_post_types()`, `selectable_post_types()`;
  - `PublishGate::MODES`;
  - `NotificationEvents::all()` / `labels()`.
- Produces:
  - section ids `sit_cwm_publish_gate` and `sit_cwm_notifications`;
  - field ids `sit_cwm_publish_gate_modes` and `sit_cwm_notification_events`;
  - form names:
    - `sit_cwm_settings[publish_gate][<post_type>]`, a `<select>`;
    - `sit_cwm_settings[notifications][<event>]`, a checkbox with value `1`.

---

- [ ] **Step 1: Write the failing tests** (append to `SettingsPageTest.php`)

```php
public function test_sections_and_fields_are_registered() {
	$this->page->register_settings();
	global $wp_settings_sections, $wp_settings_fields;
	$this->assertArrayHasKey( 'sit_cwm_publish_gate', $wp_settings_sections[ SettingsPage::MENU_SLUG ] );
	$this->assertArrayHasKey( 'sit_cwm_notifications', $wp_settings_sections[ SettingsPage::MENU_SLUG ] );
	$this->assertArrayHasKey( 'sit_cwm_publish_gate_modes', $wp_settings_fields[ SettingsPage::MENU_SLUG ]['sit_cwm_publish_gate'] );
}

public function test_gate_field_renders_one_select_per_enabled_type_with_current_value() {
	( new Settings() )->update( array( 'publish_gate' => array( 'page' => 'log_only' ) ) );
	ob_start(); $this->page->render_publish_gate_field(); $html = ob_get_clean();
	$this->assertStringContainsString( 'name="sit_cwm_settings[publish_gate][post]"', $html );
	$this->assertStringContainsString( 'name="sit_cwm_settings[publish_gate][page]"', $html );
	$this->assertMatchesRegularExpression( '/<option value="log_only"\s+selected/', $html ); // selected() output on page.
	$this->assertStringContainsString( '<label for="sit-cwm-gate-post">', $html );          // Accessible label per select.
}

public function test_notifications_field_renders_every_event_unchecked_by_default() {
	ob_start(); $this->page->render_notifications_field(); $html = ob_get_clean();
	foreach ( NotificationEvents::all() as $event ) {
		$this->assertStringContainsString( 'name="sit_cwm_settings[notifications][' . $event . ']"', $html );
	}
	$this->assertStringNotContainsString( 'checked', $html );
}

public function test_sanitize_keeps_valid_modes_and_toggles() {
	$clean = $this->page->sanitize( array(
		'post_types'    => array( 'post', 'page' ),
		'publish_gate'  => array( 'post' => 'enforce', 'page' => 'off' ),
		'notifications' => array( 'approved' => '1' ),
	) );
	$this->assertSame( array( 'post' => 'enforce', 'page' => 'off' ), $clean['publish_gate'] );
	$this->assertTrue( $clean['notifications']['approved'] );
	$this->assertFalse( $clean['notifications']['comment_added'] ); // Unchecked boxes submit nothing → false.
}

public function test_sanitize_drops_bad_modes_and_unknown_types_with_a_settings_error() {
	$clean = $this->page->sanitize( array( 'post_types' => array( 'post' ), 'publish_gate' => array( 'post' => 'block', 'nonexistent_cpt' => 'enforce' ) ) );
	$this->assertSame( array(), $clean['publish_gate'] );
	$this->assertNotEmpty( get_settings_errors( Settings::OPTION ) );
}

public function test_sanitize_scalar_gate_value_keeps_stored_modes() {
	( new Settings() )->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) );
	$clean = $this->page->sanitize( array( 'post_types' => array( 'post' ), 'publish_gate' => 'enforce' ) );
	$this->assertSame( array( 'post' => 'enforce' ), $clean['publish_gate'] );
}

public function test_sanitize_keeps_modes_for_types_disabled_in_the_same_save() {
	// post_types => ['post'], publish_gate => ['page' => 'enforce'] (page still selectable) → kept, inert (E1).
}

public function test_sanitize_keeps_stored_modes_for_types_not_shown_on_the_form() {
	// Stored post_types ['post'], gate { post: enforce, page: enforce }; form saves post => log_only → page stays enforce.
	// Next save re-enables page without a page select → page comes back as enforce. A shown type omitted from input is not carried.
}

public function test_sanitize_ignores_unknown_notification_events() { /* notifications[slack]=1 → key absent */ }

public function test_saving_without_manage_cap_still_fails() { /* existing option_page_capability test still green for the new keys */ }
```

- [ ] **Step 2: Run them to verify they fail**

  Run: `WP_PHPUNIT__TESTS_CONFIG=tests/php/wp-tests-config.php vendor/bin/phpunit --testsuite integration --filter SettingsPageTest`
  Expected: FAIL, undefined methods.

- [ ] **Step 3: Implement in `admin/Settings.php`**

  - Constants: `SECTION_GATE = 'sit_cwm_publish_gate'`, `SECTION_NOTIFICATIONS = 'sit_cwm_notifications'`.
  - `register_settings()` adds, after the existing section:
    - `add_settings_section( SECTION_GATE, __( 'Publish gate', … ), array( $this, 'render_gate_section' ), MENU_SLUG )`, plus the field `sit_cwm_publish_gate_modes` with title `Require approval to publish`;
    - `add_settings_section( SECTION_NOTIFICATIONS, __( 'Email notifications', … ), array( $this, 'render_notifications_section' ), MENU_SLUG )`, plus the field `sit_cwm_notification_events` with title `Send email when`.
  - **`render_gate_section()` copy** (escape with `esc_html__`):
    - `Decide whether content has to be approved in the workflow before it can be published. Off changes nothing. Log only lets people publish but records every publish that skipped approval. Enforce blocks publishing until the content is approved; administrators can still publish, and that is recorded.`
    - A second `<p class="description">`: `Every publish and unpublish of workflow content is recorded in its activity history in all three modes. Changes written straight to the database by other code are not covered.`
  - **`render_publish_gate_field()`:**
    - one row per `enabled_post_types()`, labelled by `get_post_type_object( $t )->labels->name`;
    - `<label for="sit-cwm-gate-{type}">`, then `<select id="sit-cwm-gate-{type}" name="sit_cwm_settings[publish_gate][{type}]">` with options `off`/`log_only`/`enforce` labelled `Off`/`Log only`/`Enforce` and `selected()`;
    - when no types are enabled, a single `<p>`: `Enable a content type above first.`
  - **`render_notifications_section()` copy:** `Send an email when these workflow events happen. Nobody is emailed about their own actions, and comment text is only included for people allowed to read the activity history.`
  - **`render_notifications_field()`:** a `<fieldset>` with a `<legend class="screen-reader-text">`, then one checkbox per `NotificationEvents::labels()` with `checked( $settings->is_notification_enabled( $event ) )`.
  - **`sanitize()`:**
    - `$clean['publish_gate'] = $this->sanitize_gate_modes( $input['publish_gate'] ?? array(), $current['publish_gate'] )`;
    - `$clean['notifications'] = $this->sanitize_notifications( $input['notifications'] ?? array() )`.
  - **`sanitize_gate_modes()`:**
    - not an array → error `sit_cwm_invalid_publish_gate` and return `$current`;
    - else keep entries whose `sanitize_key`'d key is in `selectable_post_types()` and whose value is in `PublishGate::MODES`;
    - then carry over stored modes for selectable types that are **not** in `enabled_post_types()` (no select was rendered for them) and were not submitted, so a disabled type's mode is kept, inert (E1), and comes back when the type is re-enabled, like its other workflow data;
    - when anything was dropped, error `sit_cwm_unknown_publish_gate`: `Publish gate settings for unknown content types or modes were ignored.`
  - **`sanitize_notifications()`:** start from `array_fill_keys( NotificationEvents::all(), false )`, set `true` for submitted known keys whose value `rest_sanitize_boolean()`s true, and ignore unknown keys.
  - Update `sanitize()`'s `@return` shape.
  - **Help tab** (`help_content()`): add two short paragraphs mirroring the section copy.

- [ ] **Step 4: Run the tests to verify they pass**

  Run the Step 2 command. Expected: PASS.

- [ ] **Step 5: Lint and hand off**

  Run: `composer lint`.
  Suggested message: `feat(settings): add publish gate and email notification sections to the settings screen`.

## Acceptance criteria

- Owners can set each enabled type to Off / Log only / Enforce and toggle five events. Everything renders escaped, with a label per control.
- Forged, scalar or unknown input never fatals and never stores an invalid value. The existing nonce and capability behaviour is unchanged.
