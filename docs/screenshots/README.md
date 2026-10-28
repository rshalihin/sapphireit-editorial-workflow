# Screenshots

These images are **generated, not hand-taken**, so they stay honest as the UI
changes. They are captured by `tests/e2e/screenshots.spec.js` driving a real
WordPress through Playwright.

## Regenerating them

```sh
npm run screenshots
npm run screenshots -- --base-url=http://127.0.0.1/cwm-e2e
```

**The spec deletes every post on the target site, before and after.** Point it
at a scratch site, never at anything you care about. `DEVELOPMENT.md` explains
how to provision one:

```sh
php bin/setup-e2e-site.php --install \
  --path=G:/laragon/www/cwm-e2e --url=http://127.0.0.1/cwm-e2e --db=cwm_e2e
```

It seeds six posts spread across the workflow (one per status, plus a second in
Review), a reviewer named Dana Okafor, due dates and a workflow comment. On the
post at Review it then records a publish while the gate was off and a publish
the gate blocked once set to Enforce. It photographs each surface at a fixed
1440×900 viewport so the crops stay comparable between runs.

It needs the test-only mu-plugin `tests/e2e/fixtures/sit-cwm-e2e-helpers.php`
(the setup script links it in) and the Classic Editor plugin installed.

## The files

The files are named for the WordPress.org listing: `screenshot-N.png` is
caption N of `readme.txt`'s Screenshots section, so they upload to SVN
`assets/` as they are.

| File | Shows | Captured from |
|---|---|---|
| `screenshot-1.png` | The editor sidebar with the "Publishing is locked" notice: the content is not approved, so the gate blocks publishing. | The block editor as Dana (an editor, no bypass), clipped to the sidebar panel. |
| `screenshot-2.png` | The activity timeline: content published while it was at Review, and a publish the gate blocked. | The same sidebar's `.sit-cwm-activity` region. |
| `screenshot-3.png` | The settings screen with the Publish gate and Email notifications sections. | **Editorial Workflow → Settings**, full page. |
| `screenshot-4.png` | The admin dashboard: every managed post with status, reviewer, due date, author, type and last activity. | **Editorial Workflow → Dashboard**. |
| `screenshot-5.png` | The **Editorial Workflow** meta box in the classic editor. | `post.php` with the Classic Editor plugin briefly activated, clipped to `#sit-cwm-workflow`. |

## Notes

- The capture waits for every fetch the sidebar makes (`aria-busy="false"` on
  the timeline, no spinner on the reviewer field) before shooting, so the
  images never show loading skeletons. If you add a new async control, add its
  settled state to that wait or it will be photographed mid-load.
- `screenshot-1.png` clips the page to the panel's box rather than screenshotting
  the element, because the panel's box extends past the viewport and an element
  screenshot pads the overflow with blank space.
- When the captions in `readme.txt` change, change the shots in the spec to
  match, in the same order, and delete any file whose number no longer has a
  caption.
