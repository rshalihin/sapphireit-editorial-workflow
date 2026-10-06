# 18 — Settings page

**Goal:** a minimal, sanitized settings screen — post types the workflow applies
to, and the uninstall data preference. Nothing more in v1.0.
**Prerequisites:** 08 (Settings store).
**Dev plan reference:** Phase 1 (Admin/Settings), Phase 23.

## Files to create

```
admin/Settings.php
tests/php/integration/SettingsPageTest.php
```

## Tasks

1. **Menu:** `add_submenu_page()` under `sit-cwm-dashboard`, title
   `__( 'Settings', 'sit-cwm' )`, capability `sit_cwm_manage_workflows`.
2. **Registration:** use the Settings API properly —
   `register_setting( 'sit_cwm_settings_group', 'sit_cwm_settings', [ 'type' => 'object', 'sanitize_callback' => [ $this, 'sanitize' ], 'default' => $settings->defaults(), 'show_in_rest' => false ] )`,
   plus `add_settings_section()` / `add_settings_field()`. Do **not** hand-roll
   an `admin_post_` handler when the Settings API already nonces and caps the
   form — fewer places to get wrong.
   - `show_in_rest => false`: settings are admin-only in v1.0; exposing them via
     `/wp/v2/settings` would widen the attack surface for no gain.
3. **Fields**
   - *Enabled post types* — checkboxes over
     `get_post_types( [ 'show_ui' => true ], 'objects' )`, excluding
     `attachment`, `wp_block`, `wp_template*`, `revision`, `nav_menu_item`.
   - *Delete data on uninstall* — single checkbox, default off, with a plain
     warning that it permanently removes activity history.
4. **`sanitize( $input ): array`** — the one place settings input is trusted-ized:
   intersect post types against the real list, cast the boolean, merge over
   defaults, drop unknown keys entirely. Return the merged array; never return
   `$input` as-is. Add `add_settings_error()` messages for rejected values.
5. **Rendering:** every echo escaped (`esc_attr`, `esc_html`,
   `checked()`, `esc_html__`). `settings_fields()` emits the nonce; the form
   posts to `options.php`.
6. **Disabling a post type** does not delete existing meta or activity — it just
   stops the workflow applying. State that in help text, and note it in
   `uninstall.php`'s behaviour section of the docs.
7. **Contextual help tab** summarizing the status flow and who can do what
   (`get_current_screen()->add_help_tab()`), linking to WORKFLOW.md.

## Acceptance criteria

- Saving with a forged/absent nonce fails (Settings API default behaviour —
  asserted, not assumed).
- A user without `sit_cwm_manage_workflows` gets no menu item and a
  `wp_die()` on direct URL access.
- Submitting `post_types[]=nonexistent_cpt` stores nothing for that value.
- Submitting a scalar where an array is expected does not fatal and leaves the
  stored option valid.
- Unchecking `page` removes the workflow meta from `/wp/v2/pages` schema and
  makes `/sit-cwm/v1/posts/<page id>/workflow` return 404, while the stored meta
  rows remain in the database.
