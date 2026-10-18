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
Review), a reviewer named Dana Okafor, due dates and a workflow comment, then
photographs each surface as a full-page shot at a fixed 1280×960 viewport so
the images stay comparable between runs.

## The files

| File | Shows | Captured from |
|---|---|---|
| `sidebar.png` | The editor sidebar: status, reviewer, due date, the transitions this user may perform, the comment box and the timeline. | The block editor, full page, with the workflow sidebar open. |
| `timeline.png` | The activity timeline, grouped by day. | The block editor, full page, scrolled to the activity timeline. |
| `dashboard.png` | The admin dashboard: every managed post with status, reviewer, due date, author, type and last activity. | **Editorial Workflow → Dashboard**, full page. |
| `bulk-actions.png` | Two rows selected, the bulk action bar, and the *Change workflow status* dialog. | The same page mid-interaction. |
| `settings.png` | The settings screen: enabled post types and the uninstall option. | **Editorial Workflow → Settings**, full page. |
| `classic-editor.png` | The **Editorial Workflow** meta box in the classic editor, on the same post as `sidebar.png`. | `post.php` with the Classic Editor plugin briefly activated (it must be installed on the target site), full page, with the workflow box scrolled into view. |

## Notes

- The capture waits for every fetch the sidebar makes (`aria-busy="false"` on
  the timeline, no spinner on the reviewer field) before shooting, so the
  images never show loading skeletons. If you add a new async control, add its
  settled state to that wait or it will be photographed mid-load.
- Every shot is a full-page screenshot at the same viewport, not an element
  capture, so the sidebar, timeline and classic editor box read as real
  surfaces in context rather than narrow panels.
- For **WordPress.org**, these become `screenshot-1.png` … `screenshot-6.png`
  in the SVN `assets/` directory, in the order listed in `readme.txt`'s
  Screenshots section: sidebar, dashboard, timeline, bulk actions, settings,
  classic editor.
  Rename them when you upload; do not rename them here.
