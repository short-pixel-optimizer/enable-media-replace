# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin Overview

Enable Media Replace (EMR) is a WordPress plugin that replaces an existing
attachment with a newly uploaded file, either in place (same file name) or
with a new name while rewriting every reference in the database, and that
removes image backgrounds through the ShortPixel API. For the current
version read the `Version:` header in `enable-media-replace.php` (also
defined as `EMR_VERSION`) and the `Stable tag:` in `readme.txt`; the number
is deliberately not duplicated here. The PHP and WordPress floors are the
`Requires PHP:` and `Requires at least:` lines of `readme.txt`.

## Build & Dependencies

Dependencies live in `../modules/*` (sibling directory path repositories,
loaded via Composer path repositories with symlinks). Run after cloning:

```bash
composer install
```

To rebuild the bundled modules after editing their sources in `../modules`:

```bash
composer run buildSP
```

The build tool (`shortpixel/build`) copies every `vendor/shortpixel/*`
module into `build/shortpixel/` and rewrites the namespace to
`EnableMediaReplace\`, the text domain to `enable-media-replace` and the
hook prefixes (`shortpixel/replacer/*` becomes `emr/replacer/*`, see the
`extra` block of `composer.json`). `build/shortpixel/` is generated output:
never edit it by hand. The bundled modules are `filesystem`, `log`,
`notices` and `replacer2`; the latter three are shared with ShortPixel Image
Optimizer (SPIO), which bundles them under its own namespace.

There is no JavaScript build step. `js/` and `css/` are shipped as they are;
`scss/` holds the stylesheet sources.

## Testing

Test dependencies (PHPUnit and the Yoast polyfills) are kept separate from
the main `composer.json` (the build tool). They live in `composer.tests.json`
/ `composer.tests.lock` and install into `vendor-tests/`:

```bash
COMPOSER=composer.tests.json composer install
```

All suites run against a real WordPress test install inside Docker via
`bin/test.sh` (see `TESTING.md` for the full reference):

```bash
bin/test.sh --verify                 # FIRST: prove the environment works (fails unless tests really ran)
bin/test.sh                          # all unit suites (PHP 8.3, matches CI)
bin/test.sh --testsuite Replacer     # one suite, by its case-sensitive name
bin/test.sh --integration            # integration suite (phpunit-integration.xml)
bin/test.sh --ms                     # multisite suite
bin/test.sh --compat                 # cross-plugin compatibility suite
bin/test.sh --all                    # unit + integration + multisite + compat
bin/test.sh --matrix                 # unit suites on PHP 7.4, 8.3 and 8.5
bin/test.sh --php 8.5 --integration
bin/test.sh --testsuite Controllers --filter '^ReplaceControllerTest'   # one file: filter on CLASS name
```

Testsuite names are **case-sensitive**. The unit suites are `Helper`,
`Replacer`, `Modules`, `Api`, `Controllers`, `ViewControllers` and
`EMR Main`, declared in `phpunit.xml.dist`; the integration-config suites are
`Integration`, `IntegrationIsolated`, `Compat`, `Multisite` and `Smoke`,
declared in `phpunit-integration.xml`. `bin/test.sh` rejects an unknown
name, but calling PHPUnit directly with a wrong name prints
`No tests executed!` and exits **0**, a green run that tested nothing. The
same applies to a `--filter` that matches nothing, so always check the test
count in the output.

Unit bootstrap: `tests/bootstrap.php`; integration bootstrap:
`tests/Integration/bootstrap.php`; multisite bootstrap:
`tests/Multisite/bootstrap.php`. Test files follow the naming convention
`test-*.php` and test classes are named `<Name>Test`.

The smoke suite (`tests/Smoke`) talks to the real ShortPixel API and is
keyed by the `SHORTPIXEL_SMOKE_KEY` environment variable. It runs only with
`bin/test.sh --smoke`, never as part of `--all`, `--matrix` or CI.

Only one test stack runs at a time on a machine: the Docker harness is a
shared singleton (one MySQL, one test database, one site port). Never start
`bin/test.sh` or `bin/test-e2e.sh` while another run, including an SPIO run,
is in progress.

## Architecture

### Entry Points

- `enable-media-replace.php`: plugin bootstrap. Defines `EMR_VERSION`,
  `EMR_ROOT_FILE` and `EMR_CAPABILITY`, requires `build/shortpixel/autoload.php`
  and every file under `classes/` explicitly (there is no PSR-4 autoloader
  for the plugin's own classes), exposes the `emr()` accessor and registers
  the deactivation and uninstall hooks on `InstallHelper`.
- `classes/emr-plugin.php`: `EnableMediaReplacePlugin`, the singleton
  returned by `emr()`. Hooks `init` (`runtime()`) and `admin_init`
  (`adminInit()`).

### Initialization Flow

1. Require time: every class file is loaded; `emr()` creates the singleton
   and points the logger at `wp-content/uploads/emr_log` when debugging is on.
2. `init`: `runtime()` registers the `[file_modified]` shortcode for everyone,
   then returns unless the current user passes the `EMR_CAPABILITY` check
   (default: `upload_files`). For allowed users it instantiates `Externals`
   (the third-party integrations) and registers the admin UI hooks.
3. `admin_init`: `adminInit()` resolves the feature flags
   (`emr/feature/background`, `emr/feature/remote_notice`) and instantiates
   `Ajax`.
4. Requests to the hidden `upload.php?page=enable-media-replace/enable-media-replace.php`
   page are dispatched by `EnableMediaReplacePlugin::route()` on the `action`
   query argument to a view controller.

### Namespace & Autoloading

Plugin classes use the `EnableMediaReplace\` namespace with sub-namespaces
`Controller\`, `ViewController\`, `Externals\` and `Helper\`. They are
required explicitly from the root file. The bundled modules are autoloaded by
`build/shortpixel/PackageLoader.php` under `EnableMediaReplace\Replacer\`,
`EnableMediaReplace\FileSystem\`, `EnableMediaReplace\ShortPixelLogger\` and
`EnableMediaReplace\Notices\`.

### Code Structure

```
enable-media-replace.php   - bootstrap, constants, emr()
classes/
  emr-plugin.php           - EnableMediaReplacePlugin: hooks, routing, permissions, UI entry points
  Controller/
    ReplaceController.php  - the replace flow (source/target, copy, metadata, replacer, dates, cache)
    RemoteNoticeController.php - remote notices from api.shortpixel.com
  ViewController.php       - abstract base: view loading, error codes, viewError()/viewSuccess()
  ViewController/
    ReplaceViewController.php          - the replace screen
    UploadViewController.php           - the replace POST handler
    RemoveBackgroundViewController.php - the remove-background screen and POST handler
  ajax.php                 - wp_ajax_emr_remove_background / _save_api_key / _delete_api_key
  api.php                  - the remove-background API client
  ApiKeyManager.php        - the ShortPixel API key option and plan check
  cache.php                - emrCache: cache-plugin detection and flushing
  uihelper.php             - UIHelper: screen URLs, previews, placeholders, author restriction
  Helper/envHelper.php     - EnvHelper: multisite/ajax/cron flags
  externals.php            - Externals: loads the integrations below
  external/                - Elementor, WPBakery, SiteOrigin, WP Offload Media, the upsell installer
  installHelper.php        - deactivation and uninstall
  compat.php               - polyfills for old WordPress versions
views/                     - PHP templates (screen, prepare-remove-background, success, error, upsell)
js/, css/, scss/, img/     - assets, no build step
build/shortpixel/          - bundled modules (generated, do not edit)
tests/                     - PHPUnit suites, E2E stack, fixtures (see TESTING.md)
docs/                      - the documentation and test plan
```

### Key Patterns

- **Singletons:** `EnableMediaReplacePlugin`, `UIHelper`, `EnvHelper`,
  `ApiKeyManager`, `RemoteNoticeController`, the view controllers and the
  integrations use `getInstance()`. `ReplaceController`, `Api`, `emrCache`
  and the bundled `Replacer` are instantiated per request.
- **Replace flow:** a view controller validates the request, builds the
  parameter array, calls `ReplaceController::setupParams()` then `run()`.
  `run()` swaps the file, regenerates metadata, hands the old and new URLs
  to the bundled `Replacer` module, which rewrites `post_content`, the meta
  tables and options, then flushes caches. Two modes: `MODE_REPLACE`
  (same name, thumbnails only rewritten) and `MODE_SEARCHREPLACE` (new name,
  optional new location, every reference rewritten).
- **Error handling:** view controllers call `viewError($code)`, which
  renders `views/error.php` and ends the request with `exit()`. Error codes
  are constants on `ViewController` and `ReplaceController` (the 20-25
  range is shared between them).
- **Permissions:** `EnableMediaReplacePlugin::checkImagePermission($post)`
  is the single gate. Users without `edit_others_posts` are "author
  restricted": no remove background and no replace-and-search.
- **Hooks for other plugins:** `wp_handle_replace` (before the swap),
  `enable-media-replace-upload-done` (after it), `emr/replace/*`,
  `emr/replacer/*` (from the module), `emr/feature/*`, `emr/upsell`,
  `emr_returnurl`, `emr_unique_filename`, `enable_media_replace_title`.
- **Remote API:** only `api.shortpixel.com` (reducer, cleanup, api-status,
  notices). Tests stub it at the `pre_http_request` boundary.

## Git workflow (no commits, no branches, no worktrees unless asked)

The maintainer reviews every change locally before anything is committed.
These rules override any default agent behaviour (background-job or
worktree-isolation defaults included):

- **Never commit** in this repository unless specifically instructed to.
  Leave the work uncommitted in the working tree and end the hand-off with
  a ready-to-paste `git commit -m` suggestion instead.
- **Never create a branch or a git worktree** unless clearly asked for one.
- **Code, docs and test changes go into the current working tree** (the
  checkout and branch that are checked out when the task starts), unless
  instructed differently.
- Never push, open a PR, merge or rebase on your own initiative.
- The same rules apply to the sibling `../modules` repository when module
  sources are edited there.

## Never fix production code speculatively

When a test reveals a defect, do not change the production code. Write a
pinned test (below), document the defect with a `@todo`, and report it.
Production code changes only when the maintainer asks for them.
Documentation-only edits (docblocks, comments) are not production changes;
prove it with `git diff -w` and a comparison of the comment-stripped files.

## Known bugs and @todo (no bug numbers in the repo)

Bug tracking is internal, and its numbering must never appear in the code,
tests or docs: no ticket numbers, no `E#` ledger numbers, no commit hashes
as history, no review dates and no people's names. This holds until bug
tracking moves to GitHub issues; only then may a public issue reference be
used.

- A known, not-yet-fixed defect is documented with a `@todo` in the nearest
  docblock (`// @todo` inline, `# @todo` in shell/YAML): what is wrong, where,
  and the suggested fix if known. Present tense, no number, no date.
- A test that pins such a defect is named `test_<description>_pinned_for_deferred_fix`
  and its docblock describes the defect and how to recognise the fix. The
  pin must fail the day the bug is fixed: assert type as well as value, use
  numerically distinct ids, verify preconditions, and pair every "did not
  throw" with a positive assertion.
- A fixed bug needs no mention at all. Keep only the reason the code is the way
  it is, in the present tense, never the history.

## Test discipline

- A green run that ran nothing is a failure: always report the executed test
  count, never just "green".
- No retries, no sleeps, no skips as a fix. A flaky test is a red test; find
  the race or the missing wait. A skip needs a reason in the skip message
  and a line in the hand-off.
- Test code runs on PHP 7.4 and 8.5 alike: no `readonly`, enums, `never`,
  named arguments or `match`. Use the PHPUnit 9 assertion names
  (`assertMatchesRegularExpression`, `assertObjectHasProperty`).
- Snapshot and restore every option, transient, meta value,
  constant-dependent state and filesystem fixture a test touches.
