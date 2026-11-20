# Browser end-to-end tests (Playwright)

A real WordPress with Enable Media Replace installed, served by Docker on
**http://localhost:8031** (admin / password), driven by Playwright. Run it
from the repository root:

```bash
bin/test-e2e.sh --project chromium       # provision (idempotent) + run one engine
bin/test-e2e.sh specs/smoke.spec.ts --project firefox
bin/test-e2e.sh --down                   # stop the stack when you are done
```

The full reference (every mode, the stack, the mu-plugins, the rules for
writing specs, visual baselines, CI) is the "Browser end-to-end tests"
section of [TESTING.md](../../TESTING.md).

## Layout

| Path | What |
|---|---|
| `playwright.config.ts` | projects `setup`, `chromium`, `firefox`, `webkit`, `visual`; one worker, zero retries |
| `fixtures.ts` | `test` / `expect` for every spec: console-error tripwire, hermetic routing, the `emr` support client (reset before each test) |
| `helpers/wp-admin.ts` | login, plain admin URLs |
| `helpers/media-list.ts` | Media Library list row actions, the edit-attachment meta boxes |
| `helpers/emr.ts` | support REST client, EMR page URLs, screen objects (replace, success, error, remove background, upscale, upsell) |
| `helpers/journeys.ts` | shared journey steps: a second logged-in user, submit the replace form, success screen + redirect, fetch a file, a padded JPEG of an exact size |
| `helpers/bulk-page.ts` | the Media > Bulk page object: panels, stats, prepare / start / pause / resume / stop / finish / restore, the tick driver |
| `helpers/screenshot.css` | screenshot-only CSS for the `visual` project |
| `specs/` | `auth.setup.ts` (logs in once), `smoke.spec.ts` (proves the harness), the journey specs, `editors.spec.ts` (Replace from the block and classic editor media modal), `bulk.spec.ts` (the bulk page) |
| `mu-plugins/emr-e2e-mock-api.php` | answers api.shortpixel.com and wordpress.org at the WordPress HTTP layer |
| `mu-plugins/emr-e2e-support.php` | REST API `emr-e2e/v1` (reset, seed, fixtures, posts, attachments, key, features, users, mock knobs, bulk status, queue backdate); the classic-editor switch (option `emr_e2e_classic_editor`) |
| `provision/` | WP-CLI provisioning and the seed |
| `docker/apache/` | Apache listening on 8031 as well as 80 |

The mu-plugins are inert unless `EMR_E2E` is defined; only
`docker-compose.e2e.yml` defines it. Never copy them to a real site.

## Rules in short

- Import `test` and `expect` from `../fixtures`.
- Wait on DOM states and server state, never on a timer; no retries, no
  `.only`, no `test.skip` without a reason.
- Every test starts from the seed: `emr.seed.attachment_id` is
  `emr-seed.jpg` (800x600), `emr.seed.post_id` a post that references it.
