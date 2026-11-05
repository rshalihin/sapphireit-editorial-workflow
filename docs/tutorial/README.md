# Tutorial: videos and written guide

`npm run tutorial` drives a real WordPress through the plugin's main flows and
writes two things from the same run, so they always match:

- **`video/<chapter>.webm`**: one captioned video per chapter, 1280×720, with
  a drawn cursor, click rings and caption banners. Headless browsers record
  none of these by themselves. These files are not committed (`video/` is
  gitignored).
- **`images/*.png`**: the stills that [GUIDE.md](GUIDE.md) embeds. These are
  committed with the guide.

| Chapter | Video | Stills |
|---|---|---|
| 1. Set up the workflow | `01-setup.webm` | 01–04 |
| 2. Write and submit for review | `02-write-and-submit.webm` | 05–07 |
| 3. Review, approve and publish | `03-review-and-publish.webm` | 08–11 |
| 4. Track work on the dashboard | `04-dashboard-and-report.webm` | 12–14 |
| 5. Declare AI use and confirm review | `05-ai-use-and-attestation.webm` | 15–16 |

The spec is `tests/e2e/tutorial.spec.js`, with its helpers in
`tests/e2e/tutorial/`. The runner is `bin/record-tutorial.js`.

## Regenerating

> **Destructive.** The run deletes every post and the whole activity log on its
> target site. Run it only against the scratch site, never your development site.

1. **Scratch site.** The runner targets `http://127.0.0.1/cwm-e2e/` by default.
   If that site does not exist yet, create it with Laragon PHP 8.x (the build
   with mysqli):

   ```bash
   php bin/setup-e2e-site.php --install --path=G:/laragon/www/cwm-e2e --url=http://127.0.0.1/cwm-e2e --db=cwm_e2e
   ```

2. **Mail capture.** Chapter 1 turns on every email notification, so later
   chapters send mail. The test-only mu-plugin
   (`tests/e2e/fixtures/sit-cwm-e2e-helpers.php`) catches `wp_mail()` so nothing
   leaves the machine. Install it, or refresh an old copy, with:

   ```bash
   php bin/setup-e2e-site.php --path=G:/laragon/www/cwm-e2e
   ```

   Without it the run stops at the start with "Mail capture mu-plugin missing",
   before any mail is sent.

3. **Fresh build.** Run `npm run build` so the recording shows the current branch.
4. **Record:**

   ```bash
   npm run tutorial                               # all five chapters
   npm run tutorial -- --grep 04-dashboard        # one chapter
   npm run tutorial -- --headed                   # watch it live
   npm run tutorial -- --base-url=http://127.0.0.1/cwm-e2e --user=admin --pass=password
   ```

   A full run takes about ten minutes.

Any chapter can run on its own, because each run seeds the site again
(personas, four background posts, a quarter of publication history, default
settings). Chapter 3 run alone builds its starting point off camera: the post at
Review with Dana as reviewer. Its timeline then shows "admin" for those steps
instead of chapter 2's on-camera history, and chapter 4's dashboard shows four
posts instead of five. **Do one full run for the final cut.**

## Reviewing a recording

Watch each `video/*.webm` in Chrome. If a caption goes by too fast, pass a
longer `hold` to that `caption()` call in the spec and re-run only that chapter.
By default a caption stays up for 250 ms per word, and never less than 2.5
seconds.

## Post-production (optional, Clipchamp)

1. Import the five webm files into Clipchamp, which comes with Windows 11.
2. Add a title card.
3. Record a voiceover per chapter (**Record & create → Audio**).
4. Export as **1080p MP4**. The 720p source scales up cleanly because the
   captions are already burned in.
5. Upload to YouTube or the product site
   (sapphireitlabs.com/editorial-workflow), and publish `GUIDE.md` with its
   images on the docs page.
