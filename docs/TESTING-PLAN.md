# Enable Media Replace: documentation and test plan

This plan is the Phase 1 deliverable. Nothing else in the repository has been
changed. It describes what the code is, what is documented, how the ShortPixel
Image Optimizer (SPIO) harness will be ported, which suites will be written, in
what order, and what needs a decision before the next phase starts.

Repository paths used below:

| Name | Path | Branch |
|---|---|---|
| EMR | `enable-media-replace` (this repository) | `tests` |
| SPIO (read-only reference) | `../shortpixel-image-optimiser` | `updates` |
| modules (shared module sources, the `plugin-modules` repository) | `../modules` | `docs` |

## 0. Environment facts that shape the later phases

1. **The replacer module source and EMR's bundled copy are in sync.** After
   the `docs` branch was rebased on the module repository's `master`,
   `modules/replacer2/src` differs from `build/shortpixel/replacer2/src` only
   by the namespace, text-domain and hook-prefix rewrite. All three copies
   (source, EMR build, SPIO build) declare the same 25 functions in
   `Replacer.php`. The rebuild in Phase 2.3 is therefore expected to change
   only docblocks; the diff check will prove it.
2. **`composer run buildSP` resolves `../modules/*`.** The module clone is
   at `../modules`, which is what `composer.json` (and SPIO's) points at.
   There is no `composer.lock` and no `vendor/` in EMR; the first build
   creates both (both gitignored).
3. **`.gitignore` no longer hides the harness.** The old file ignored `.*`
   and `bin`, which would have kept `.github/`, `.docker/` and `bin/test.sh`
   untracked. It has been replaced with SPIO's layout adapted to EMR.
4. **SPIO's Docker stacks must be down while EMR's run.** The PHPUnit stacks
   share no ports (MySQL is unpublished) and E2E uses 8031 against SPIO's
   8030, but rule 7 applies regardless: one stack at a time on this machine.
5. **Docker image tags are `spio-tests:php7.4`, `php8.3`, `php8.5`.** The
   compose file builds `spio-tests:php${PHP_VERSION}` from the `--php` value.
   These images already exist locally from SPIO and are reused as-is.
6. **There is no local PHP.** Every PHP command (composer, phpunit, the build
   tool) runs inside the Docker image. `composer run buildSP` in Phase 2.3
   runs through a one-off `docker run` of the `spio-tests:php8.3` image with
   the repositories mounted, so Phase 2 does not depend on Phase 3. From
   Phase 3 on, `bin/test.sh --shell` is the way in.

## 1. Code inspection summary

### 1.1 Size

| Area | Files | Lines | Functions |
|---|---|---|---|
| Root plugin file `enable-media-replace.php` | 1 | 97 | 1 |
| `classes/` (plugin code) | 21 | 4,344 | 174 |
| `views/` | 6 | 945 | 0 |
| `js/` | 4 | 710 | n/a |
| `build/shortpixel/replacer2` (bundled module) | 15 | 1,887 | 83 |
| `build/shortpixel/filesystem` | 3 | 1,758 | 72 |
| `build/shortpixel/log` | 3 | 695 | 37 |
| `build/shortpixel/notices` | 2 | 765 | 42 |
| `build/shortpixel/PackageLoader.php` | 1 | 94 | 8 |
| `modules/replacer2/src` (module source) | 15 | 1,887 | 83 |

### 1.2 Entry point and bootstrap

`enable-media-replace.php` defines `EMR_VERSION`, `EMR_ROOT_FILE` and
`EMR_CAPABILITY` (default `false`; may be a capability string or a two-element
array `[general_cap, own_files_cap]`), requires `build/shortpixel/autoload.php`
and every class file explicitly (no PSR-4 autoloading for `classes/`), exposes
the `emr()` accessor returning the `EnableMediaReplacePlugin` singleton, and
registers deactivation and uninstall hooks on `InstallHelper`.

`EnableMediaReplacePlugin` (`classes/emr-plugin.php`, 29 methods) hooks `init`
and `admin_init`. On `init` it registers the `[file_modified]` shortcode, then
returns early unless the current user passes the `EMR_CAPABILITY` check
(falls back to `upload_files`). Only then does it instantiate `Externals` and
register the admin UI: hidden submenu page under `upload.php` routed by
`route()` on the `action` query argument (`media_replace`,
`media_replace_upload`, `emr_prepare_remove`, `do_background_replace`), the
media row action, the attachment edit meta box, the attachment-fields button,
the "Revised"/"Replaced By" sub-box, script and style registration, the
cache-busting filter on `wp_get_attachment_image_src` after a replace, and
the feature flags (`emr/feature/background`, `emr/feature/remote_notice`).
`checkImagePermission()` is the single permission gate used by every
controller and view.

### 1.3 `classes/` layout

| File | Class | Role |
|---|---|---|
| `emr-plugin.php` | `EnableMediaReplacePlugin` | plugin singleton, hooks, routing, permissions, UI entry points |
| `Controller/ReplaceController.php` | `Controller\ReplaceController` | the replace flow: source/target setup, file copy, metadata, replacer run, dates, cache flush |
| `Controller/RemoteNoticeController.php` | `Controller\RemoteNoticeController` | fetches `api.shortpixel.com/v2/notices.php`, stores as persistent notices |
| `ViewController.php` | `ViewController` (abstract) | view loading, error codes 1-9 / 20-25 / 31, `viewError()` and `viewSuccess()` (both `exit()`) |
| `ViewController/ReplaceViewController.php` | `ReplaceViewController` | the replace screen (`views/screen.php`) |
| `ViewController/UploadViewController.php` | `UploadViewController` | the POST handler: nonce, caps, form parsing, date modes, upload validation, runs `ReplaceController`, saves settings |
| `ViewController/RemoveBackgroundViewController.php` | `RemoveBackGroundViewController` | the remove-background screen and its POST handler |
| `ajax.php` | `Ajax` | `wp_ajax_emr_remove_background`, `wp_ajax_emr_save_api_key`, `wp_ajax_emr_delete_api_key` |
| `api.php` | `Api` | remove-background request to the free or authenticated reducer, retry loop with `sleep()`, cleanup call, transient hand-off, download |
| `ApiKeyManager.php` | `ApiKeyManager` | option `emr_shortpixel_api_key`, 20-char validation, `api-status.php` call, Unlimited-plan check |
| `cache.php` | `emrCache` | detects ten cache plugins and flushes them after a replace |
| `uihelper.php` | `UIHelper` | URLs for the screens, preview sizing, placeholders, `is_author_restricted()`, `isBackgroundRemovable()`, feature notice |
| `Helper/envHelper.php` | `Helper\EnvHelper` | multisite / main-site / network-admin / ajax / json / cron flags |
| `externals.php` | `Externals` | loads the four EMR-side integrations, filters the replace-type options |
| `external/elementor.php` | `Externals\Elementor` | adds an Elementor query to `emr/replacer/custom_replace_query`, clears the Elementor CSS cache |
| `external/wpbakery.php` | `Externals\WpBakery` | adds a urlencoded query (WPBakery and Visual Composer) |
| `external/siteorigin.php` | `Externals\SiteOrigin` | adds `widget_siteorigin-panels-builder` to `emr/replacer/option_fields` (a filter the replacer never applies) |
| `external/wp-offload.php` | `Externals\WPOffload` | WP Offload Media: remote removal, local-path translation, movable check, scaled-path fix, original-path update, offload prevention during metadata generation |
| `external/upsell_installer.php` | two global functions | `wp_ajax_emr_plugin_install` and `wp_ajax_emr_plugin_activate` for SPIO, SPAI, FastPixel and Envira |
| `installHelper.php` | `InstallHelper` | deactivation resets notices; uninstall deletes `enable_media_replace`, `emr_news`, `emr_url_cache` |
| `compat.php` | three global functions | polyfills for `wp_delete_attachment_files`, `wp_delete_file_from_directory`, `wp_get_upload_dir` (WP < 4.9.7 / 4.5) |

Dead or legacy pieces worth documenting rather than removing: the PHP 5.6
`Unserialize` polyfill in the replacer (only the `PHP_VERSION_ID >= 70000`
branch can run on the 7.4 floor), the `compat.php` polyfills (the readme's
minimum WP is 4.9.7, so only `wp_get_upload_dir` is strictly dead), the
`function_exists('wp_get_original_image_path')` guards (WP 5.3+), and
`views/do-replace-background.php` (superseded by
`RemoveBackGroundViewController::loadPost()` and no longer included from
anywhere).

### 1.4 Views, scripts, styles

| View | Loaded by | Content |
|---|---|---|
| `views/screen.php` | `ReplaceViewController::load()` | the replace form: `ID`, `replace_type` (`replace` / `replace_and_search`), `timestamp_replace` (1/2/3), `custom_date`, `custom_date_formatted`, `custom_hour`, `custom_minute`, `new_title`, `new_location`, `location_dir`, `remove_after_progress`, nonce `emr_nonce` for `media_replace_upload`; hooks `emr_before_replace_type_options`, `emr_display_replace_type_options`, `emr_after_search_type_options`, `emr_enable_replace_and_search`, `emr_after_replace_type_options`; includes `upsell.php` |
| `views/prepare-remove-background.php` | `RemoveBackGroundViewController::load()` | preview, background type/colour/transparency, API-key form, nonce `emr_nonce` for `media_remove_background`; includes `upsell.php` |
| `views/success.php` | `ViewController::viewSuccess()` | success screen with countdown redirect (`emr_success.js`), SPIO upsell line |
| `views/error.php` | `ViewController::viewError()` | error message and description |
| `views/upsell.php` | included by the two screens | SPIO / SPAI / FastPixel / Envira install and activate buttons, gated by `emr/upsell` |
| `views/do-replace-background.php` | nothing | legacy POST handler, see 1.3 |

Scripts: `js/emr_admin.js` (replace form: drag and drop, type checks, size
check, datepicker, localised through `emr_options`), `js/remove_bg.js`
(remove-background AJAX and API-key AJAX, localised through `emrObject`),
`js/upsell.js` (install/activate AJAX, `emr_upsell`), `js/emr_success.js`
(countdown redirect, `emr_success_options`). All are plain jQuery; there is
no build step for JS.

### 1.5 Bundled modules

| Module | EMR namespace | Also bundled by SPIO? | Notes |
|---|---|---|---|
| `replacer2` | `EnableMediaReplace\Replacer\` | yes, as `ShortPixel\Replacer\` | identical apart from the namespace, text domain and hook-prefix rewrite |
| `filesystem` | `EnableMediaReplace\FileSystem\` | **no**: SPIO has its own, larger `FileModel`/`DirectoryModel`/`FileSystemController` under `class/` | EMR's copies are the module versions (835 / 594 / 329 lines) |
| `log` | `EnableMediaReplace\ShortPixelLogger\` | yes (same module, SPIO namespace) | SPIO has no tests for it |
| `notices` | `EnableMediaReplace\Notices\` | yes | SPIO tests it only through its own controller |

`build/shortpixel/PackageLoader.php` is the generated autoloader. The
`filesystem` module imports the logger as
`EnableMediaReplace\ShortpixelLogger\...` (lowercase `p`) in all three files;
the autoloader matches namespace prefixes case-sensitively, so this only works
because the logger is always loaded earlier by `EnableMediaReplacePlugin::get()`.
That goes into the documentation as a `@todo` and into the ledger.

### 1.6 Replacer module internals (`modules/replacer2/src`)

| File | Role |
|---|---|
| `Replacer.php` | `setSource/Target(Meta)`, `addURLArray()`, `replace()` (builds the relative URL arrays for main file and every size, balances them, runs the posts query, the meta tables, then `emr/replacer/custom_replace_query` runs), `replaceContent()` (string / serialized / JSON / array / object recursion with `allowed_classes => false` and incomplete-class bail-out), `findNearestSize()`, `fileIsRestricted()` |
| `Classes/Setup.php`, `Finder.php`, `MetaData.php`, `Data.php`, `Url.php`, `Updater.php`, `Utils.php` | a newer, partly wired API (`Setup()->forSearch()->MetaData()`, `Finder->posts()/postmeta()`, `Updater->updatePost()`); `Replacer::replace()` does not use it yet. `Utils` is empty and outside the namespace |
| `Modules/Elementor.php` | adds the `elementor` query (escaped slashes); duplicates EMR's own `classes/external/elementor.php`, both register under the same `elementor` key, so the second registration wins |
| `Modules/WpBakery.php` | adds the `wpbakery` urlencoded query; same duplication with `classes/external/wpbakery.php` |
| `Modules/YoastSeo.php` | deletes `yoast_indexable` rows whose `twitter_image`/`open_graph_image` match the base or file URL |
| `Modules/Breakdance.php` | loads and saves the Breakdance tree through its own functions instead of SQL |
| `Modules/SmartSlider.php` | returns from the constructor before registering anything (an unconditional `return false;` after the constant check), so it is inert |
| `Libraries/Unserialize/*` | the `allowed_classes` polyfill |

Hooks the module fires, with EMR's prefix: `emr/replacer/replace_urls`,
`emr/replacer/custom_replace_query`, `emr/replacer/replacement_tables`,
`emr/replacer/load_meta_value`, `emr/replacer/save_meta_value`,
`emr/replacer/content`, `emr/file/basedir_check`.

### 1.7 EMR-side integrations

Detection and effect are documented in Phase 6; the inventory is:

| Integration | Where | Detected by |
|---|---|---|
| Elementor | `classes/external/elementor.php` and the module | `ELEMENTOR_VERSION`, override filter `emr/externals/elementor_is_active` |
| WPBakery / Visual Composer | `classes/external/wpbakery.php` and the module | `did_action('vc_plugins_loaded')` or `VCV_VERSION`, override `emr/externals/urlencode_is_active` |
| SiteOrigin Page Builder | `classes/external/siteorigin.php` | `SITEORIGIN_PANELS_VERSION` |
| WP Offload Media | `classes/external/wp-offload.php` | `as3cf_init` action and `Media_Library_Item` class |
| Yoast SEO | module | `WPSEO_VERSION` |
| Breakdance | module | `has_action('breakdance_loaded')` and four `\Breakdance\Data\*` functions |
| Smart Slider 3 | module (inert) | `NEXTEND_SMARTSLIDER_3` |
| Cache plugins (W3TC, WP Super Cache, WP Engine, WP Fastest Cache, SiteGround, LiteSpeed, FastPixel, WP Rocket, Breeze, Cache Enabler) | `classes/cache.php` | function / class / constant / action probes |
| SPIO | `wp-offload.php` (hands the URL-to-path filter to SPIO when active), `views/upsell.php`, `upsell_installer.php` | `is_plugin_active('shortpixel-image-optimiser/wp-shortpixel.php')` |
| SPAI, FastPixel, Envira | upsell only | `get_plugins()` and `is_plugin_active()` |

### 1.8 AJAX surface

| Action | Handler | Checks |
|---|---|---|
| `wp_ajax_emr_remove_background` | `Ajax::remove_background()` | nonce `emr_remove_background` verified against `$_POST['action']`; then `Api::request()` which checks `checkImagePermission()` |
| `wp_ajax_emr_save_api_key` | `Ajax::save_api_key()` | `manage_options`, nonce `emr_save_api_key` |
| `wp_ajax_emr_delete_api_key` | `Ajax::delete_api_key()` | `manage_options`, nonce `emr_save_api_key` |
| `wp_ajax_emr_plugin_install` | `emr_plugin_install()` | `check_admin_referer('emr-plugin-install')`, `install_plugins` |
| `wp_ajax_emr_plugin_activate` | `emr_activate()` | `check_admin_referer('emr-plugin-activate')`, `activate_plugins` |

`Ajax` is instantiated twice: once at the bottom of `ajax.php` at require time
and once in `adminInit()`, so each endpoint is registered twice.

### 1.9 Remote endpoints

| Endpoint | Caller | When |
|---|---|---|
| `http://api.shortpixel.com/v2/free-reducer.php` | `Api::request()` | remove background without an API key |
| `https://api.shortpixel.com/v2/reducer.php` | `Api::request()` | remove background with a key |
| `https://api.shortpixel.com/v2/cleanup.php` | `Api::purgeRemoteCache()` | first keyed request |
| `https://api.shortpixel.com/v2/api-status.php` | `ApiKeyManager::callApi()` | key save, and every keyed remove-background request |
| the `LosslessURL` returned by the reducer | `Api::handleDownload()` via `FileSystemController::downloadFile()` | after the user confirms |
| `https://api.shortpixel.com/v2/notices.php` | `RemoteNoticeController` | once a day, on the media screens |
| `downloads.wordpress.org/plugin/*.zip` | `emr_plugin_install()` | upsell install |

### 1.10 Remove-background feature and key handling

Flow: media row or edit screen -> `emr_prepare_remove` screen -> AJAX
`emr_remove_background` posts the background options -> `Api::request()`
saves the options into `enable_media_replace`, polls the reducer (status `1`
means retry: recursion with `sleep()`, up to 10 attempts free / 30 keyed),
stores the result URL in a transient keyed by `wp_hash(nonce . url)` ->
the screen posts `key` and `ID` to `do_background_replace` ->
`RemoveBackGroundViewController::loadPost()` downloads the file to a temp
path and runs a `MODE_REPLACE` / `TIME_UPDATEMODIFIED` replacement with the
same filename. The key is stored in plain text in the `emr_shortpixel_api_key`
option and masked for display. Users without `edit_others_posts` are
"author restricted": no remove background and no replace-and-search.

### 1.11 Multisite-aware code

`EnvHelper` flags, and one guard in `ReplaceController::getNewTargetLocation()`:
on the main site of a multisite a new location under `sites/` is rejected
with `ERROR_DIRECTORY_SECURITY`. Everything else relies on WordPress's
per-site `wp_upload_dir()`. There is no network admin page and no
`switch_to_blog()` usage. The readme notes the SPIO recommendation is hidden
on secondary sites of a network-activated install; no such code exists in
the current views (candidate for the ledger).

### 1.12 WP-CLI

None.

### 1.13 Existing tests

`tests/bootstrap.php` (old WP test library bootstrap, echoes on load),
`tests/test-file.php`, `test-functions.php`, `test-replacer.php`,
`test-targetfile.php`, `test-sample.php`, `tests/externals/test-elementor.php`,
`test-wpbakery.php`, and two fixture images in `tests/assets/`. They target
`EnableMediaReplace\emrFile`, a `Replacer` with a constructor taking a post id,
`emr_get_match_url`, and `setUpBeforeClass()` without a return type. None of
them can run against the current code. What each covered is listed in Phase 3
so it is not lost.

## 2. Documentation gap analysis

Counted with a script: a function or class counts as documented when a
`/** ... */` block immediately precedes its declaration. Inline `//` comments
do not count.

### 2.1 EMR code

| File | Functions | Documented | Class doc |
|---|---|---|---|
| `enable-media-replace.php` | 1 | 0 | n/a (file header exists) |
| `classes/emr-plugin.php` | 29 | 9 | no |
| `classes/Controller/ReplaceController.php` | 17 | 5 | no |
| `classes/Controller/RemoteNoticeController.php` | 4 | 0 | no |
| `classes/ViewController.php` | 5 | 0 | no |
| `classes/ViewController/UploadViewController.php` | 7 | 0 | no |
| `classes/ViewController/ReplaceViewController.php` | 3 | 0 | no |
| `classes/ViewController/RemoveBackgroundViewController.php` | 6 | 0 | no |
| `classes/ajax.php` | 5 | 0 | no |
| `classes/api.php` | 6 | 1 | yes |
| `classes/ApiKeyManager.php` | 9 | 2 | no |
| `classes/cache.php` | 13 | 1 | no |
| `classes/uihelper.php` | 21 | 2 | no |
| `classes/Helper/envHelper.php` | 3 | 0 | no |
| `classes/externals.php` | 9 | 0 | no |
| `classes/external/elementor.php` | 6 | 0 | no |
| `classes/external/wpbakery.php` | 5 | 0 | no |
| `classes/external/siteorigin.php` | 3 | 0 | no |
| `classes/external/wp-offload.php` | 16 | 1 | no |
| `classes/external/upsell_installer.php` | 2 | 1 | n/a |
| `classes/installHelper.php` | 2 | 0 | no |
| `classes/compat.php` | 3 | 0 | n/a (block comments exist) |
| **Total** | **175** | **22 (13%)** | **1 of 19 classes** |

`views/`: `screen.php` carries a header from the original author; the other
five have no header block.

### 2.2 Replacer module source (`modules/replacer2/src`)

| File | Functions | Documented |
|---|---|---|
| `Replacer.php` | 25 | 3 (class doc exists) |
| `Classes/Setup.php` | 7 | 0 |
| `Classes/Finder.php` | 3 | 0 |
| `Classes/MetaData.php` | 3 | 1 |
| `Classes/Data.php` | 4 | 0 |
| `Classes/Updater.php` | 1 | 0 |
| `Classes/Url.php` | 2 | 0 |
| `Classes/Utils.php` | 0 | 0 |
| `Modules/Breakdance.php` | 8 | 0 |
| `Modules/Elementor.php` | 6 | 0 |
| `Modules/SmartSlider.php` | 6 | 0 |
| `Modules/WpBakery.php` | 5 | 0 |
| `Modules/YoastSeo.php` | 4 | 0 |
| `Libraries/Unserialize/*` | 9 | 9 (third-party, already documented) |
| **Total** | **83** | **13**, of which 9 are the third-party library |

**The replacer module is undocumented in its source, in EMR's bundled copy and
in SPIO's bundled copy.** There is nothing to copy from SPIO for it. Phase 2.3
writes it once in the source.

### 2.3 Other bundled modules (for reference, not in scope for docblocks)

`filesystem` 72 functions / 31 documented, `log` 37 / 8, `notices` 42 / 11.
These live in the module repository too and are not part of this engagement's
documentation scope; their tests in Phase 4 use the code as the spec and note
gaps in the hand-off.

### 2.4 What can be reused from SPIO

- Harness files, listed file by file in section 3.
- `tests/Model/test-Replacer.php` (committed, 5 tests; the stash version adds
  2 pins): ports with the namespace swap.
- `tests/Model/test-DirectoryModel.php` (35 tests) and most of
  `test-FileModel.php` (41 tests, 3 target methods EMR's module lacks): port
  with the namespace swap because EMR's filesystem module has the same
  public surface, with reflection-based property names to re-check.
- 21 of the 33 tests in `tests/Controller/test-FileSystemController.php`
  (the rest target SPIO-only methods).
- The module-level halves of `tests/Compat/test-Compat{Breakdance,Elementor,
  WpBakery,YoastSeo}.php` and the stash-only `test-CompatBeaverBuilder.php`:
  detection, filter registration, `addX()` argument shape, `loadContent`.
  The end-to-end halves drive SPIO's queue and need rewriting against
  `ReplaceController`.
- The content-shape seeding and object-blob assertions of
  `tests/Integration/test-ConversionReplacement.php`, and the replacer-level
  pins from `test-ChangeFilename.php` (two of which will flip on EMR because
  the current replacer already selects the owner id for meta rows and clears
  the per-owner cache; they are written as positive tests).
- The `CLAUDE.md` / `AGENTS.md` / `copilot-instructions.md` / `TESTING.md`
  structure and wording for the non-negotiable rules.
- Nothing for the log and notices modules, `test-EMRIntegration.php` (it
  tests SPIO's reaction, not EMR), or the multisite and smoke suites.

## 3. Proposed test environment

One command, `bin/test.sh`, runs PHPUnit inside Docker against a real
WordPress test install with MySQL 8. No local PHP is needed.

### 3.1 Files ported from SPIO, and what changes in each

| SPIO file | EMR file | Changes |
|---|---|---|
| `bin/test.sh` | same | suite lists (see 3.3), `--verify` suite name, env var `EMR_PARTNER_PLUGINS`, partner slug list, log file prefix; `--filter` stays a pass-through to PHPUnit as in SPIO; add the `No tests executed!` grep to the local per-suite loop so the zero-test guard is not CI-only |
| `bin/test-e2e.sh`, `bin/lib/retry.sh` | same | `SITE_URL` 8031, volume names `emr-e2e_*`, provision path `wp-content/plugins/enable-media-replace/tests/E2E/provision/provision.sh`, `EMR_PLAYWRIGHT_VERSION`, lock file name, `emr_retry` |
| `bin/install-wp-tests.sh`, `bin/.env.sample` | same | none (generic) |
| `.docker/Dockerfile.tests` | same | ini file name only; image contents identical on purpose so the `spio-tests:php*` tags are shared |
| `docker-compose.tests.yml` | same | image stays `spio-tests:php${PHP_VERSION}`; mount comment; no port changes needed (MySQL unpublished) |
| `docker-compose.e2e.yml` | same | `name: emr-e2e`, port `8031`, `WP_HOME`/`WP_SITEURL`, constants `EMR_E2E`, `EMR_E2E_TOKEN`, plugin mount path, Apache `ports.conf` listening on 8031 |
| `composer.tests.json` + `composer.tests.lock` | same | package name only; lock copied verbatim (same dependency set) |
| `phpunit.xml.dist` | same | the EMR suite layout from 3.3; replaces the current single-suite file |
| `phpunit-integration.xml` | same | `Integration`, `IntegrationIsolated`, `Compat`, `Multisite`, `Smoke` with EMR exclusions |
| `tests/bootstrap.php` | same | loads `enable-media-replace.php` on `plugins_loaded` (as required; SPIO uses `muplugins_loaded`), defines `SHORTPIXEL_DEBUG` with a file target inside the container, never echoes; partner-plugin list and deprecation filters kept; the SPIO settings/table setup removed |
| `tests/Integration/bootstrap.php` | same | partner activation kept; GD pin kept; requires EMR helpers |
| `tests/Integration/Helpers/MockShortPixelApi.php` | `MockEmrApi.php` | rewritten for EMR's endpoints: `free-reducer.php` and `reducer.php` (status 1 then 2, `LosslessURL`, quota-exceeded message, malformed body, WP_Error), `cleanup.php`, `api-status.php` (`Code` 2 with `Unlimited` true/false, wrong key), `notices.php`, and the result download URL; request log and knobs kept |
| `tests/Integration/Helpers/SPIO_IntegrationHelpers.php`, `SPIO_IntegrationTestCase.php`, `SPIO_AjaxTestCase.php` | `EMR_*` | fixture upload helpers and the reset-statics pattern kept; queue/settings helpers dropped; the fail-fast `pre_http_request` at priority 9999 moves into the base integration case so it applies to every integration test, not only AJAX ones |
| `tests/Integration/Helpers/WpCliStub.php` | dropped | EMR has no CLI |
| `tests/Multisite/` | own bootstrap `tests/Multisite/bootstrap.php` setting `WP_MULTISITE=1` before the unit bootstrap, as the brief asks; selectable with `--ms` | new tests (Phase 7) |
| `tests/Compat/` | same | ported halves plus new tests (Phase 6) |
| `tests/partner-plugins/` | same, gitignored | the four SPIO zips copied in Phase 3 (Breakdance, WPBakery, WPML, WPML Media) |
| `tests/Smoke/` | same | one `test-RealRemoveBackgroundSmoke.php`, keyed by `SHORTPIXEL_SMOKE_KEY`, mock unregistered, never in `--all`/`--matrix`/CI |
| `tests/E2E/` | same | Playwright 1.63.0, `workers: 1`, `retries: 0`; mu-plugins `emr-e2e-mock-api.php` and `emr-e2e-support.php` (REST namespace `emr-e2e/v1`); provisioning with `PLUGIN_SLUG=enable-media-replace`; new specs (Phase 8) |
| `.github/workflows/phpunit.yml`, `e2e.yml`, `smoke.yml` | same | triggers: push on `tests`, PRs to `updates` and `master`; suite loops; `e2e.yml` and `smoke.yml` manual dispatch only; zero-test guard added to every PHPUnit job, not only the unit matrix |
| `.github/dependabot.yml` | same | path and branch |
| `TESTING.md`, `CLAUDE.md`, `AGENTS.md`, `.github/copilot-instructions.md` | same | rewritten for EMR (Phase 2.2 and 3.5) |
| `.gitignore` | same | already replaced with SPIO's layout adapted to EMR: `vendor`, `vendor-tests`, `composer.lock`, partner zips, E2E artifacts; `.*` and `bin` removed |

Note on the case-insensitive file system: SPIO tracks the folder as
`tests/E2E/`. EMR uses the same spelling so the two repositories behave alike.

### 3.2 The command

```
bin/test.sh                      # all unit suites, PHP 8.3, WP latest
bin/test.sh --testsuite Replacer # one suite, name is case-sensitive
bin/test.sh --filter '^ReplacerTest'   # one class (file paths do not work)
bin/test.sh --php 7.4 --wp 6.4
bin/test.sh --matrix             # 7.4, 8.3, 8.5
bin/test.sh --integration | --compat | --ms | --all
bin/test.sh --smoke              # needs SHORTPIXEL_SMOKE_KEY, never in CI
bin/test.sh --verify             # proves the environment runs real tests
bin/test.sh --clean | --shell
```

### 3.3 Suites (case-sensitive names)

Unit, `phpunit.xml.dist`, bootstrap `tests/bootstrap.php`:

| Suite | Directory | Scope |
|---|---|---|
| `Helper` | `tests/Helper` | `UIHelper`, `EnvHelper`, `emrCache`, `InstallHelper`, `compat.php` |
| `Replacer` | `tests/Replacer` | the bundled replacer module: `Replacer`, `Classes/*`, `Modules/*` detection, `Unserialize` |
| `Modules` | `tests/Modules` | the bundled `filesystem`, `log`, `notices` modules and `PackageLoader` |
| `Api` | `tests/Api` | `Api`, `ApiKeyManager`, `RemoteNoticeController`, HTTP mocked |
| `Controllers` | `tests/Controller` | `ReplaceController`, `Ajax`, `Externals`, `external/*` with the third party absent |
| `ViewControllers` | `tests/ViewController` | the three view controllers and the abstract base |
| `EMR Main` | `tests/` (excluding the above and the integration-config folders) | `enable-media-replace.php`, `EnableMediaReplacePlugin`, `test-PluginLoaded.php` |

Integration, `phpunit-integration.xml`, bootstrap `tests/Integration/bootstrap.php`:

| Suite | Directory |
|---|---|
| `Integration` | `tests/Integration` minus the isolated file |
| `IntegrationIsolated` | `tests/Integration/test-ConstantsAndFilters.php` (tests that `define()` `EMR_CAPABILITY` and friends) |
| `Compat` | `tests/Compat` |
| `Multisite` | `tests/Multisite` |
| `Smoke` | `tests/Smoke` |

### 3.4 PHP matrix and WP knob

`--php` accepts `7.4`, `8.3`, `8.5` (image `spio-tests:php<version>`, built
from `.docker/Dockerfile.tests` on first use). `--wp` accepts `latest` or a
version; the WP core and test library are cached per version in the
`wp-tests-cache` volume. SPIO's floor is WP 5.9; EMR's readme says 4.9.7, but
the test library install only works from 5.9 upwards, so the CI integration
job runs `latest` and `5.9`.

### 3.5 What `--verify` proves

It runs the `EMR Main` suite and passes only if PHPUnit exits 0 **and** prints
`OK (N tests` or `OK, but`. Together with the suite-name validation (unknown
name -> exit 1 with the list of valid names) and the `No tests executed!`
grep, it proves that the container builds, the WP test install exists, the
plugin loads under the test bootstrap, and at least `test-PluginLoaded.php`
really executed.

## 4. Suite ladder

Each suite below lists scope, tooling, the definition of done, the estimated
size, the risks, and what is ported versus written new.

### 4.1 Unit suites (Phase 4), bottom up

Order: `Helper` -> `Replacer` -> `Modules` -> `Api` -> `Controllers` ->
`ViewControllers` -> `EMR Main`.

| Suite | Scope | Ported | New | Est. tests | Risks |
|---|---|---|---|---|---|
| `Helper` | every public method of `UIHelper` (URLs, SVG size fix, placeholder HTML, preview scaling, `findImageSizeByMax`, `isBackgroundRemovable`, author restriction), `EnvHelper` flags, `emrCache` detection and the `emr/cache/flush` action, `InstallHelper` option deletion, `compat.php` polyfills (callable with the real functions present) | none | all | 60-80 | `UIHelper` singleton must be reset by reflection; placeholder HTML tests assert structure, not exact markup |
| `Replacer` | `replaceContent()` across string / serialized / JSON / array / object / WP_Error / incomplete class / URL-as-key, the `allowed_classes` hardening, `replace()` balancing and nearest-size logic with synthetic metadata, `thumbnails_only`, the empty-search early return, the `custom_replace_query` dispatch and per-run settings, `Finder` / `Updater` / `Setup`, module detection with the third party absent, `Unserialize` | SPIO `test-Replacer.php` (5 + 2 stash pins), replacer-level pins from `test-ChangeFilename.php` and `test-ConversionReplacement.php` | the rest | 70-90 | pins that are already fixed in EMR's bundled copy must be written as positive tests; `define()` of `NEXTEND_SMARTSLIDER_3` leaks across tests, goes into `IntegrationIsolated` or a `@runInSeparateProcess` |
| `Modules` | `FileModel`, `DirectoryModel`, `FileSystemController` (the 21 portable tests plus `downloadFile` with a mocked HTTP response), `ShortPixelLogger` (level gating, file target, `debugIsActive`), `NoticeController` / `NoticeModel` (add, persist, dismiss, reset, `getNoticeByID`), `PackageLoader` | SPIO `test-FileModel.php`, `test-DirectoryModel.php`, `test-FileSystemController.php` with the namespace swap | log, notices, loader | 120-140 | reflection property names differ between SPIO's and the module's `FileModel`; logger writes to `/tmp` inside the container |
| `Api` | `ApiKeyManager` (length check, save/verify against mocked `api-status.php`, masked key, delete, https-to-http fallback), `Api::request()` paths (no id, no permission, free vs keyed endpoint, status 1 retry, status 2 success with transient, quota exceeded, plan expired, WP_Error, malformed body, attempt limit), `handleSuccess/handleDownload`, `RemoteNoticeController` (transient, notice creation per type, `none` sentinel) | none | all | 50-60 | `Api::request()` calls `sleep()` on retries: the test asserts the counter path with the retry count forced through the mock so no real sleep occurs, or pins the behaviour and measures nothing time-based |
| `Controllers` | `ReplaceController`: `setupParams` for both modes, `setupTarget` (overwrite, rename, new location, target exists, same path, directory target), `getNewTargetLocation` traversal and multisite guard, `getSourceUrl`, `getTargetURL`, `updateDate` for the three time modes, `removeCurrent`, `run()` with a real attachment on the test install; `Ajax` nonce and capability branches; `Externals` filters; `external/*` inactive paths and override filters | none (SPIO has no equivalent) | all | 70-90 | `run()` touches the real uploads folder: every test uploads its own fixture and removes it; `exit()` paths must not be hit |
| `ViewControllers` | `ViewController::viewError()` message table and `loadView` (captured with output buffering, `exit` avoided by testing the message resolution through a subclass), `UploadViewController::getPost()` date modes and invalid custom date, `getUpload()` validation, `updateSettings()`, `ReplaceViewController` view data (defaults, author restriction, `emr/replace/default_type`, movable), `RemoveBackGroundViewController` view data | none | all | 40-50 | the controllers call `exit()` on errors; tests reach the decision through the protected methods and through integration tests for the full request |
| `EMR Main` | constants, `emr()` accessor, `runtime()` capability gating (`EMR_CAPABILITY` string, array, false), `checkImagePermission` matrix, menu registration and hiding, `route()` dispatch, `convertdate`, `add_media_action`, `attachment_editor`, meta boxes, `get_modified_date` shortcode, `attempt_uncache_image`, `useFeature` | none | all | 50-60 | `EMR_CAPABILITY` is a constant: only the default is testable in-process; the other shapes go to `IntegrationIsolated` with `@runInSeparateProcess` |

Definition of done per suite: every public method has at least one test of its
documented behaviour, one boundary and one error path where one exists; the
suite is green on `--matrix`; the count is reported.

### 4.2 Integration (Phase 5)

Scope: the real runtime, with only `api.shortpixel.com` and
`downloads.wordpress.org` stubbed at the `pre_http_request` boundary.

- Replace flow for each mode from an uploaded fixture to the persisted
  attachment: `MODE_REPLACE` same type, `MODE_REPLACE` different type (MIME
  update), `MODE_SEARCHREPLACE` same name, new name, new name plus new
  location, target exists error, traversal error; metadata and thumbnails
  regenerated; URLs rewritten in `post_content` (classic, Gutenberg, srcset),
  postmeta (string, serialized array, serialized object left intact), options,
  termmeta, usermeta, commentmeta; `-scaled` originals; SVG and PDF
  replacements; title and excerpt update from image meta.
- Date handling: `TIME_UPDATEALL`, `TIME_UPDATEMODIFIED`, `TIME_CUSTOM`
  with timezone offsets and the invalid-date error.
- `_emr_replace_author` set and removed, "Revised" output.
- AJAX endpoints with the `EMR_AjaxTestCase`: nonce missing, wrong, right;
  capability missing; success and error JSON shapes for the three `emr_*`
  endpoints and the two upsell endpoints (install stubbed at the download).
- Remove background end to end against the mock: options persisted, transient
  created, download, replace, success view data.
- Install / upgrade / uninstall: activation, deactivation resets notices,
  uninstall deletes the three options and the API key is reported if it
  survives (candidate ledger item).
- Notices: remote notice creation and dedupe, feature notice persistence.
- The EMR side of the SPIO contract: `wp_handle_replace` fires with
  `post_id` before the swap, `enable-media-replace-upload-done` fires with
  `(target_url, source_url, post_id)` after it, `wp_handle_upload` is applied
  with `sideload`, and `emr/converter/prevent-offload(-off)` bracket the
  metadata generation. SPIO's `test-EMRIntegration.php` is the reference for
  the argument shapes.
- Ported: the seeding and assertion patterns of SPIO's
  `test-ConversionReplacement.php` and `test-ChangeFilename.php`, rewritten to
  drive `ReplaceController` instead of SPIO's queue.

Estimated size: 90-120 tests. Done when every row above has a test, the suite
is green on 7.4 and 8.3 with WP `latest` and `5.9`, and the fail-fast filter
caught no stray request.

### 4.3 Compat (Phase 6)

Order by user impact: SPIO coexistence, Elementor, WPBakery, Yoast SEO,
WP Offload Media Lite, WooCommerce, Polylang, Breakdance (zip), WPML (zip),
WPML Media (zip), SiteOrigin, Smart Slider 3, Beaver Builder Lite, Bricks
(zip), Envira Lite (upsell).

| Integration | Source | Ported from SPIO after the swap |
|---|---|---|
| Elementor | free, downloaded | `test-CompatElementor.php`: the 2 module-level tests; the 3 pipeline tests rewritten on `ReplaceController` |
| WPBakery | `js_composer.zip` | `test-CompatWpBakery.php`: 2 ported, 2 rewritten |
| Yoast SEO | free | `test-CompatYoastSeo.php`: 2 ported, 3 rewritten, `ensureYoastIndexableTable()` reused |
| Breakdance | `breakdance-*.zip` | `test-CompatBreakdance.php` (stash version): module-level tests ported; the "Breakdance alone corrupts" pin is expected to flip and is written as a positive test; end-to-end rewritten |
| Beaver Builder Lite | free (stash adds the slug) | stash `test-CompatBeaverBuilder.php`: 2 ported, 2 rewritten |
| WP Offload Media Lite | free | new: `WPOffload` hooks with the plugin loaded but no provider configured; the remote paths with a stub item class, declared in the docblock |
| SiteOrigin | free | new |
| Smart Slider 3 | free | new: documents that the module is inert; the SPIO "deliberately disabled" test ports |
| WPML, WPML Media | zips | new: replace with a translated duplicate present (the readme mentions Polylang blocking deletes; WPML's behaviour is the same code path) |
| Polylang | free | new |
| WooCommerce | free | new: product gallery meta rewritten |
| Bricks | zip, when you add it | new; self-skipping until then |
| SPIO | free | new: upsell hidden when active, the URL-to-path filter not registered by EMR when SPIO is active, a replace with SPIO active fires SPIO's hooks once |
| Envira Lite | free | new: upsell state and the install/activate endpoints with the real zip served from the stub |

Every compat test runs with the third party active and asserts the inactive
path in the unit suites. Self-skip messages name the zip to drop in. Each
hand-off lists which tests skipped for a missing zip.

Estimated size: 80-110 tests. CI downloads the free plugins; zip-backed tests
self-skip in CI as in SPIO.

### 4.4 Multisite (Phase 7)

Own bootstrap with `WP_MULTISITE=1`, `--ms`, the `Multisite` suite. Cover:
per-site uploads directories for a replace on a subsite, `switch_to_blog()`
round trips (the replacer's queries use the switched `$wpdb` prefix), the
`sites/` guard in `getNewTargetLocation()` on the main site and on a subsite,
network-activated versus per-site activation (menu, row action, upsell
visibility), super admin versus site admin versus author for
`checkImagePermission()` and the AJAX endpoints, `EnvHelper` flags. Estimated
25-35 tests. Ported: nothing (SPIO's are all about its own tables).

### 4.5 E2E (Phase 8)

SPIO's Playwright stack on port 8031, `retries: 0`, `workers: 1`, Chromium
plus Firefox, WebKit `continue-on-error` as in SPIO. Hermetic: `fixtures.ts`
blocks `shortpixel.com`, `gravatar.com`, `s.w.org`, `downloads.wordpress.org`;
the mu-plugin stubs `api.wordpress.org` and every `api.shortpixel.com`
endpoint with knobs in options. Deterministic seed: a fixed set of
attachments and posts created through the support REST API.

Journeys, in order: replace from the media list row action; replace from the
edit-attachment screen; `replace` mode; `replace_and_search` with rename;
with new location; each date mode; success screen with countdown and
redirect to the edit screen with `emr_replaced=1` and the thumbnails box;
each error screen (wrong type, nonce, target exists, traversal); upsell
screen states (SPIO absent / installed / active) with the install stubbed;
remove background: screen, API-key save and delete, remove with the mock,
confirm and replace, quota-exceeded and plan-expired states. Completion
signals: the success screen heading, the `emr_replaced` redirect, the AJAX
response rendering the preview image; never a timer. Visual snapshots only
for the replace screen and the remove-background screen. Estimated 25-35
specs across 7-8 files.

### 4.6 Smoke (opt-in)

`tests/Smoke/test-RealRemoveBackgroundSmoke.php`: one keyed and one free
remove-background call against the real API with a small fixture, plus a
wrong-key `api-status.php` call. Skips without `SHORTPIXEL_SMOKE_KEY`. Built
and verified against the mock path; run for real only when you hand over a
key.

## 5. Conventions

- Files `tests/<Suite>/test-<Subject>.php`, one class per file named
  `<Subject>Test`, extending `WP_UnitTestCase` (unit), `EMR_IntegrationTestCase`
  or `EMR_AjaxTestCase` (integration, compat, multisite).
- Methods `test_<description>` in snake case describing the behaviour.
- Pins: `test_<description>_pinned_for_deferred_fix`, docblock explains the
  defect in present tense and how to recognise the fix, asserts type and
  value, uses numerically distinct ids, verifies preconditions, pairs every
  "did not throw" with a positive assertion. A pin fails the day the bug is
  fixed.
- Fixtures under `tests/fixtures/` (the two images from `tests/assets/` move
  there). Every test uploads its own copy and removes it in `tear_down()`.
- Snapshot and restore every option, transient, post meta, constant-dependent
  state and filesystem fixture a test touches; singletons reset by reflection
  in `set_up()`.
- Suite selection by name (`--testsuite Replacer`), single class by
  `--filter '^ReplacerTest'`. File paths do not work with PHPUnit's filter.
- PHP 7.4-compatible test code: no `readonly`, enums, `never`, named
  arguments, `match`; PHPUnit 9 assertion names
  (`assertMatchesRegularExpression`, `assertObjectHasProperty`).
- No bug numbers, dates, hashes or names in tracked files. An open defect is a
  `@todo` in the nearest docblock. The `E#` ledger lives in the hand-off and
  in the gitignored `vendor-tests/EMR-bugs-E-asana.txt`.
- `build/` is never edited by hand. Module docblocks go to
  `modules/replacer2/src` and are rebuilt.

## 6. Order, checkpoints and subagent split

Every phase starts with a short plan, ends with the hand-off from section 9
of the brief, and waits for approval. Subagents run with `model: "opus"`,
receive the rules, the files, the suite to run and the hand-off format, and
never run tests concurrently with each other or with the main session.

| Phase | Work | Subagents | Checkpoint |
|---|---|---|---|
| 2 | Documentation: 2.1 EMR docblocks area by area (root, Helper, Controllers, ViewControllers, externals, views), 2.2 `CLAUDE.md`, `AGENTS.md`, `copilot-instructions.md`, `TESTING.md` skeleton, PHP floor to 7.4, 2.3 replacer module docblocks on `docs`, then the rebuild through a one-off `docker run` and the diff check | 3 in parallel for 2.1 (no tests run): A root + Helper + `cache` + `compat`; B Controllers + ViewControllers + `api` + `ApiKeyManager` + `ajax`; C externals + views. 2.3 in the main session | documented counts before/after, `git diff -w` and declaration grep proof, ledger, module diff output, the SPIO rebuild command |
| 3 | Harness port, old tests removed, `test-PluginLoaded.php`, CI, `TESTING.md` complete | 1 subagent ports the files (no tests); the main session runs `--verify` and the default run | pasted output of `--verify` and of `bin/test.sh` with count > 0, deletions listed |
| 4 | Unit suites in the order of 4.1 | one subagent per suite, sequential (each runs its own suite); the main session runs the regression and `--matrix` before the hand-off | per area: tests, assertions, runtime, pins; end: totals, `--matrix`, `--verify` |
| 5 | Integration | 2 sequential subagents: replace flows and dates; AJAX, remove background, lifecycle, notices, SPIO contract | same |
| 6 | Compat | one subagent per plugin group, sequential; the hand-off lists self-skips | same |
| 7 | Multisite | 1 subagent | same |
| 8 | E2E | 1 subagent for the stack port, 1 for the specs, sequential; the E2E stack is only up while the PHPUnit stack is down | same plus the `e2e.yml` run plan |

What each old test file covered, to be re-covered in Phase 4 and listed in
the Phase 3 hand-off: `test-file.php` (file path parsing and directory
resolution of the old `emrFile`, now `FileModel`), `test-functions.php` (URL
matching, query-string stripping, size suffix removal, scheme removal: now
internal to `Replacer::getRelativeURLS()` and `replaceContent()`),
`test-replacer.php` (string, WP_Error, object, array, mixed, serialized,
JSON, metadata and URL-as-key replacement, problematic real-world examples:
the `Replacer` suite), `test-targetfile.php` (overwrite, rename, file without
month path: the `Controllers` suite), `tests/externals/*` (Elementor slash
escaping and filter registration, WPBakery urlencoding: `Controllers` and
`Compat`), `test-sample.php` (nothing).

## 7. Candidate defects seen during inspection

Observations only. They become `E#` ledger lines once a test or the
documentation confirms them; none is numbered here and none is changed.

- `Replacer::handleMetaData()` reuses the column-name variable `$main_id` as
  the row value inside the row loop, so from the second matching row of a meta
  table the cache key is read from a wrong index (present in the module source
  and in both bundled copies).
- The `filesystem` module imports `ShortpixelLogger` with a lowercase `p` in
  three files; it resolves only because the logger is loaded earlier.
- `Ajax` is instantiated twice, registering each endpoint twice.
- `ReplaceController::setupTarget()` has `$this->replaceType == self::MODE_REPLACE;`
  (a comparison, not an assignment) on the "same path" branch.
- `ReplaceController::getTargetURL()` tests `strpos($url, '://') >= 0`, which
  is always true.
- `EnableMediaReplacePlugin::checkImagePermission()` compares
  `intval($post_id) > ! 0`.
- `emr_plugin_install()` leaves `$download_url` undefined for an unknown
  plugin slug, and the final error response has a bare string instead of a
  `message` key.
- `WPOffload::isFileMovable()` returns `null` when the item exists but is not
  an object.
- `RemoteNoticeController::get_remote_notices()` stores the string `'true'`
  in the transient after a successful fetch, so notices are only displayed on
  the request that fetched them.
- `Modules\SmartSlider` returns unconditionally before registering its hook.
- `Modules\Elementor` and `Externals\Elementor` (and the WPBakery pair)
  register the same `custom_replace_query` key twice.
- `SiteOrigin` adds to `emr/replacer/option_fields`, a filter nothing applies.
- The uninstall routine does not delete `emr_shortpixel_api_key` or the
  `emr_remote_notice` transient.
- `views/error.php` resolves one string with the `shortpixel-image-optimiser`
  text domain (`ERROR_DIRECTORY_SECURITY` description).
- `Api::request()` hard-codes `plugin_version => 'EMR4.2.0'`.
- `EnableMediaReplacePlugin::get_modified_date()` is marked by its own
  `@todo` as returning the wrong timezone.
