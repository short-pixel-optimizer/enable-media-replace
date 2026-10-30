# Running the Enable Media Replace tests

This document describes how to run the PHPUnit and browser test suites
locally and how they map to the GitHub Actions CI. Nothing here is shipped to
WordPress.org; this file lives in the repository for the dev team.

The harness is a port of the ShortPixel Image Optimizer (SPIO) one: same
Docker images, same `bin/test.sh` interface, same CI shape. The plan behind
the suite layout is `docs/TESTING-PLAN.md`; the short rules for agents are in
`CLAUDE.md`.

## What's in the suite

The suite lives under `tests/` and is split into PHPUnit testsuites across two
configuration files.

Unit suites, declared in `phpunit.xml.dist`, bootstrap `tests/bootstrap.php`:

| Testsuite         | Path                         | Covers                                                        |
|-------------------|------------------------------|---------------------------------------------------------------|
| `Helper`          | `tests/Helper/`              | `UIHelper`, `EnvHelper`, `emrCache`, `InstallHelper`, `compat.php` |
| `Replacer`        | `tests/Replacer/`            | the bundled replacer module (`build/shortpixel/replacer2`)     |
| `Modules`         | `tests/Modules/`             | the bundled `filesystem`, `log`, `notices` modules and the package loader |
| `Api`             | `tests/Api/`                 | `Api`, `ApiKeyManager`, `RemoteNoticeController` (HTTP mocked) |
| `Controllers`     | `tests/Controller/`          | `ReplaceController`, `Ajax`, `Externals`, `external/*`         |
| `ViewControllers` | `tests/ViewController/`      | the view controllers and their abstract base                   |
| `EMR Main`        | `tests/` (excluding the above and the integration folders) | the root plugin file and `EnableMediaReplacePlugin` |

Integration-config suites, declared in `phpunit-integration.xml`, bootstrap
`tests/Integration/bootstrap.php` (multisite: `tests/Multisite/bootstrap.php`):

| Testsuite             | Path                         | Covers                                                  |
|-----------------------|------------------------------|---------------------------------------------------------|
| `Integration`         | `tests/Integration/`         | the replace flows against the real WordPress runtime     |
| `IntegrationIsolated` | one file in `tests/Integration/` | tests that `define()` constants and must run alone   |
| `Compat`              | `tests/Compat/`              | third-party plugins active                               |
| `Multisite`           | `tests/Multisite/`           | `WP_MULTISITE=1`                                         |
| `Smoke`               | `tests/Smoke/`               | the real ShortPixel API, opt-in, never in CI             |

`Integration` is the default testsuite of `phpunit-integration.xml`, so a
plain `phpunit -c phpunit-integration.xml` never pulls in `Compat`,
`Multisite` or `Smoke` by accident.

The tests run against a real WordPress test environment (`WP_UnitTestCase`),
not a mocking library, so they need MySQL and a checkout of the WordPress test
framework. The Docker harness provides both.

Test files follow the convention `test-<Name>.php`; test classes are named
`<Name>Test`; test methods `test_<description>`; pinned defects
`test_<description>_pinned_for_deferred_fix`.

## Recommended: run locally via Docker (OS-agnostic)

**Prerequisite:** Docker Desktop (macOS / Windows) or Docker Engine with the
`docker compose` plugin (Linux). Nothing else: no local PHP, MySQL, Composer,
WP-CLI or SVN.

### Quick start

```bash
# First: prove the environment works. Requires a real passing test run, so
# it cannot report success on a broken setup. Run this before anything else.
bin/test.sh --verify

# Default: every unit testsuite on PHP 8.3 (matches the CI baseline)
bin/test.sh

# Specific testsuite (names are case-sensitive)
bin/test.sh --testsuite Replacer
bin/test.sh --testsuite "EMR Main"

# Specific test method
bin/test.sh --filter test_replaces_a_plain_string

# Single test file: filter on its CLASS name (see note below)
bin/test.sh --testsuite Replacer --filter '^ReplacerTest'
```

`--verify` runs the `EMR Main` suite and passes only if PHPUnit exits 0
**and** prints `OK (N tests` or `OK, but`. Together with the suite-name
validation (an unknown name exits 1 and lists the valid names) and the
`No tests executed!` check, it proves that the container builds, the WP test
install exists, the plugin loads under the test bootstrap, and at least one
test really executed. `--verify`, `--matrix` and `--clean` must be the first
argument; `--php` and `--wp` may follow them (`bin/test.sh --verify --php 7.4`).

> **Note: running a single file.** Passing a file path directly
> (`bin/test.sh tests/Replacer/test-Replacer.php`) does NOT work: PHPUnit 9
> derives the expected class name from the file name, and the WP-convention
> `test-Foo.php` -> `FooTest` naming never matches ("Class test-Replacer
> could not be found"). Use `--filter <ClassName>` instead; adding
> `--testsuite` narrows the scan and speeds it up. `--filter` is a
> substring/regex match: `ReplacerTest` also catches `ReplacerTestCase`-style
> names; anchor it (`--filter '^ReplacerTest'`) when you need exactly one
> class.

### PHP version matrix

The setup supports PHP 7.4 (the plugin's minimum), 8.3 (current mainstream)
and 8.5 (upcoming). Each version gets its own Docker image tag
(`spio-tests:php7.4`, `spio-tests:php8.3`, `spio-tests:php8.5`, shared with
SPIO on purpose), so switching between versions is cache-warm after the
first build per version.

```bash
# One specific PHP version
bin/test.sh --php 7.4
bin/test.sh --php 8.5 --testsuite Api

# All three sequentially, same as CI's matrix strategy
bin/test.sh --matrix

# Combine matrix with a filter
bin/test.sh --matrix --filter test_replaces_serialized_meta
```

### Integration suite

The integration suite (`tests/Integration/`, `phpunit-integration.xml`) runs
the real replace flow against a WordPress test install: a fixture is
uploaded, `ReplaceController` swaps the file, metadata is regenerated, the
bundled replacer rewrites post content, meta tables and options, and the
test asserts the persisted state. Only the outbound HTTP to
`api.shortpixel.com` (remove background, api-status, notices) and
`downloads.wordpress.org` (the upsell installer) is stubbed, at the
`pre_http_request` boundary. Any other outbound request fails the test, so a
forgotten stub cannot reach the network. It runs in its own phpunit
invocation so the fast unit signal and the slow integration signal stay
separated.

One special case: `tests/Integration/test-ConstantsAndFilters.php`
`define()`s `EMR_CAPABILITY` (a string, an array, `false`) and similar
wp-config-style constants. A constant cannot be undefined, so it would
poison every test that runs after it in the same PHP process, for example
every permission check that expects the default `upload_files`. The file is
excluded from the main `Integration` suite and runs as the separate
`IntegrationIsolated` testsuite, with `@runInSeparateProcess` where one
constant shape must not meet another. `bin/test.sh --integration` makes both
phpunit invocations automatically, and so does the CI `integration` job.

The suite also covers the EMR side of the SPIO contract without SPIO
installed: `wp_handle_replace` fires with the `post_id` before the swap,
`enable-media-replace-upload-done` fires with `(target_url, source_url,
post_id)` after it, and `emr/converter/prevent-offload(-off)` bracket the
metadata generation.

```bash
# Integration suite only (Integration + IntegrationIsolated)
bin/test.sh --integration
bin/test.sh --integration --php 7.4
bin/test.sh --integration --filter test_replace_and_search

# Integration suite on all three PHP versions
bin/test.sh --matrix --integration
```

### Real-API smoke tests

The smoke suite (`tests/Smoke/`) unregisters the HTTP mock and calls the
**live** ShortPixel API through EMR's own client classes: a valid-key and a
wrong-key `api-status.php` verification, then one keyed and one free
remove-background request and one keyed and one free upscale request (the
`upscale` factor instead of `bg_remove`). It catches contract drift a mock
can't see. It
needs a valid API key and consumes real credits, so it is never part of
`--integration`, `--all`, `--matrix` or the push/PR CI runs; without the key
every test self-skips.

The API fetches the image from the site URL, which is `example.org` inside
the harness and unreachable from outside, so by default the two reducer
tests prove the response envelope only (the API refuses, EMR maps the
refusal). Point `SHORTPIXEL_SMOKE_IMAGE_URL` at a publicly reachable image
and the keyed test runs the whole path: request, result URL, download of
the result. `EMR_SMOKE_USE_MOCK=1` runs the same tests against `MockEmrApi`
to verify the suite itself without quota (that is how it is verified here).

```bash
SHORTPIXEL_SMOKE_KEY=<your API key> bin/test.sh --smoke
SHORTPIXEL_SMOKE_KEY=<key> SHORTPIXEL_SMOKE_IMAGE_URL=https://example.com/photo.jpg bin/test.sh --smoke
EMR_SMOKE_USE_MOCK=1 bin/test.sh --smoke     # self-check, no key, no quota
```

CI: `.github/workflows/smoke.yml` runs the same suite on manual dispatch only
(Actions -> "EMR Real-API Smoke Tests" -> "Run workflow"), with the
`SHORTPIXEL_SMOKE_KEY` repository secret. Without the secret the job prints a
warning and every test skips. There is no schedule.

### Cross-plugin compatibility tests

The compat suite (`tests/Compat/`) runs EMR's integrations against the REAL
partner plugins, activated natively in the test install. The free ones are
downloaded from wordpress.org (latest stable, zips cached in the
`wp-tests-cache` volume):

| Slug | Plugin |
|---|---|
| `elementor` | Elementor |
| `wordpress-seo` | Yoast SEO |
| `polylang` | Polylang |
| `woocommerce` | WooCommerce |
| `amazon-s3-and-cloudfront` | WP Offload Media Lite |
| `siteorigin-panels` | SiteOrigin Page Builder |
| `smart-slider-3` | Smart Slider 3 |
| `beaver-builder-lite-version` | Beaver Builder Lite |
| `shortpixel-image-optimiser` | ShortPixel Image Optimizer (coexistence and upsell) |
| `envira-gallery-lite` | Envira Gallery Lite (upsell) |

The commercial ones have no public download: drop their zips into
`tests/partner-plugins/` (gitignored) and `--compat` extracts and activates
them too. Today that is Breakdance (`breakdance-*.zip`), WPBakery
(`js_composer.zip`), WPML (`sitepress-multilingual-cms*.zip`) and WPML Media
Translation (`wpml-media-translation*.zip`). The commercial THEME, Bricks,
goes into `tests/partner-themes/` (gitignored, `bricks*.zip`): `--compat`
extracts it into `wp-content/themes/` and `tests/bootstrap.php` makes it the
active theme for the run when it is there. To update one, replace its zip:
the harness re-extracts whenever the zip is newer than the extracted copy.
Without the zip, the tests for that plugin self-skip, and the skip message
names the zip to drop in.

```bash
bin/test.sh --compat
bin/test.sh --compat --filter '^CompatElementorTest'
```

How it works:

- `--compat` downloads + extracts the partner plugins into the test
  install's `wp-content/plugins/`, then runs phpunit with
  `EMR_PARTNER_PLUGINS=1` and the `Compat` testsuite.
- `tests/bootstrap.php` activates the partners via a
  `pre_option_active_plugins` filter (real WP core plugin loading);
  `tests/Integration/bootstrap.php` fires their activation hooks once so
  their installers create the tables they need (DDL auto-commits, so the
  tables survive per-test rollbacks).
- Plain `--integration` / `--all` unit passes never load the partner
  plugins: the env variable gates everything, so the standard suites are
  unaffected.
- The suite runs on PHP 8.3 or 8.5 with WP latest. PHP 7.4 and pinned WP
  versions exit early with a skip note: partner plugin floors (WP Offload
  Media Lite needs PHP 8.1+, current partner releases require modern WP),
  not EMR's.
- Each test also self-skips when its partner plugin isn't loaded, so an
  accidental plain-phpunit run of the suite is harmless.
- **CI downloads only the free plugins.** The zips can't live in the public
  repository, so on CI every zip-backed test self-skips. Run `--compat`
  locally with the zips in place before a release.

Every compat test runs with the third party active; the inactive path of the
same integration is asserted in the `Controllers` unit suite.

### Multisite tests

The multisite suite (`tests/Multisite/`) runs against a NETWORK WordPress
test install and covers EMR's multisite-specific surface: per-site uploads
directories (`uploads/sites/N/`) for a replace on a subsite,
`switch_to_blog()` round trips (the replacer's queries use the switched
`$wpdb` prefix), the `sites/` guard in
`ReplaceController::getNewTargetLocation()` on the main site and on a
subsite, network versus per-site activation, and super admin versus site
admin versus author for `checkImagePermission()` and the AJAX endpoints.

```bash
bin/test.sh --ms
bin/test.sh --ms --filter '^MultisiteReplaceTest'
```

How it works:

- `--ms` runs phpunit with `phpunit-integration.xml`, the `Multisite`
  testsuite and its own bootstrap, `--bootstrap tests/Multisite/bootstrap.php`,
  which sets `WP_MULTISITE=1` before loading the shared bootstrap. That makes
  the WP test-lib bootstrap (re)install the test database as a multisite
  network; no separate config file or cache dir is needed, since the install
  is rebuilt on every run anyway.
- The suite uses the integration helpers (HTTP mock and the
  `EMR_IntegrationTestCase` base class) plus `tests/Multisite/MultisiteHelpers.php`
  (trait `EMR_MultisiteHelpers`, not collected by PHPUnit): the single-site
  skip, subsite creation with its uploads folder, `enterSite()`/`leaveSite()`
  switching with singleton resets, and a tear-down that unwinds any
  `switch_to_blog()`, points the debug log back at the main site and
  removes the `sites/N` folders. Attachments uploaded on a subsite must not
  go on the base class's deletion list: that list is processed on the main
  site, where the same id names a different post.
- Every test self-skips on a single-site install, so selecting the suite
  without the multisite bootstrap yields skips, not failures.

### WordPress version

Tests run against the latest WordPress by default. `--wp <version>` pins a
specific version (pass the tag WordPress publishes: `5.9`, not `5.9.0`).
Each WP version keeps its own cache dirs inside the `wp-tests-cache` volume,
so switching versions is cache-warm after the first install.

```bash
bin/test.sh --wp 5.9                          # unit suites on WP 5.9
bin/test.sh --wp 5.9 --php 7.4 --integration  # old WP + old PHP combo
```

WP 5.9 is the oldest version the WordPress test library installs on this
setup. EMR's readme floor is lower, but older versions cannot be tested here.

CI mirrors this: pushes run the integration suite on WP latest across PHP
7.4/8.3/8.5, plus WP 5.9 on PHP 7.4 and 8.3. Pull requests run PHP 8.3 / WP
latest, plus the same WP 5.9 combos. Every run also includes the `compat` job
(PHP 8.3 and 8.5, WP latest) and the `multisite` job (PHP 8.3, WP latest).

### Everything in one go

```bash
# Unit + integration + multisite + compat suites, one command
# (PHP 8.3 / WP latest)
bin/test.sh --all

# The full local sweep: all four passes on PHP 7.4, 8.3 AND 8.5
# (the compat pass self-skips on 7.4: partner plugin floors)
bin/test.sh --matrix --all

# Unit + integration on a pinned combo (compat pass skips off-latest)
bin/test.sh --all --wp 5.9 --php 7.4
```

All passes always run: a unit failure doesn't hide the integration or compat
result (or vice versa); failures are aggregated in the final verdict. Smoke
is never part of `--all` or `--matrix`.

### Debug workflow

```bash
# Drop into an interactive bash shell inside the PHP container.
# Handy for repeated iteration without booting a fresh container per run.
bin/test.sh --shell

# From inside the shell:
vendor-tests/bin/phpunit --testsuite Replacer
vendor-tests/bin/phpunit --testsuite Replacer --filter '^ReplacerTest'      # one file (by class)
vendor-tests/bin/phpunit --filter 'ReplacerTest::test_replaces_json'        # one method
vendor-tests/bin/phpunit -c phpunit-integration.xml                         # Integration
vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite IntegrationIsolated
WP_MULTISITE=1 vendor-tests/bin/phpunit -c phpunit-integration.xml \
  --bootstrap tests/Multisite/bootstrap.php --testsuite Multisite
```

Inside the shell there is no zero-test guard: check the test count yourself.

### Cache / reset

```bash
# Nuke all containers, volumes, and per-version PHP images.
# Next run rebuilds everything from scratch (~3-5 min for one version).
bin/test.sh --clean
```

Caches persisted between runs:

- **`vendor-tests/`** lives on the host via the bind mount, so the test-deps
  install (`COMPOSER=composer.tests.json composer install`) runs only when
  `vendor-tests/autoload.php` is missing.
- **`/tmp/wordpress-tests-lib`** and **`/tmp/wordpress`** persist in the
  `wp-tests-cache` named Docker volume, so the ~3-minute WordPress
  test-framework SVN checkout only happens once (per `--clean` cycle). The
  downloaded partner-plugin zips are cached there too.
- **PHP images**: Docker layer cache. Each PHP version keeps its own image
  tag (`spio-tests:php7.4` / `spio-tests:php8.3` / `spio-tests:php8.5`);
  switching PHP versions doesn't invalidate the others. The images are
  shared with SPIO, so `--clean` here also removes the ones SPIO uses (they
  are rebuilt on the next run of either plugin).

### Timing expectations

Measured on SPIO; EMR numbers to be filled in.

<!-- @todo replace with EMR measurements once the unit and integration suites exist. -->

| Operation                                              | First run                                    | Subsequent runs |
|--------------------------------------------------------|----------------------------------------------|-----------------|
| Full suite on one PHP version                          | 3-5 min (image pull + WP-tests SVN checkout) | ~20-60 s        |
| Full matrix (all 3 PHP versions)                       | 10-15 min                                    | ~1-3 min        |
| Single testsuite                                       | (setup + ~10 s)                              | ~10 s           |
| Integration suite on one PHP version                   | (setup + ~1 min)                             | ~1 min          |
| `--matrix --all` (unit + integration x 3 PHP versions) | 15-20 min                                    | ~6-8 min        |

## One stack at a time

The harness is a shared singleton on a machine: one MySQL, one
`wordpress_test` database, one E2E site port, and the PHP images are shared
with SPIO. Two runs at once corrupt each other's database state and produce
failures that have nothing to do with the code. So:

- never start two `bin/test.sh` invocations at once (not even different
  suites or PHP versions);
- never run while an SPIO run is in progress; bring SPIO's stacks down first;
- never run the PHPUnit stack and the E2E stack together;
- agents running tests do so one at a time, never in parallel with each other
  or with the main session.

## Alternative: run locally without Docker

If Docker isn't an option, you can install the dependencies directly on your
host. This is the setup the GitHub Actions runner uses under the hood.

### System requirements

- PHP 8.3 (or one of 7.4 / 8.5 for cross-version testing) with the `gd`,
  `mbstring`, `mysqli`, `xml` and `zip` extensions (`gd` is required: the
  integration suite generates real attachment metadata).
- Composer 2.
- MySQL 8.0 (any 5.7+ works but 8.0 matches CI).
- WP-CLI (the `wp-cli.phar` binary somewhere on `$PATH` as `wp`).
- SVN (`subversion` package), required by `bin/install-wp-tests.sh` to check
  out the WordPress test framework.
- `mysql` client, required by the same script to create the test database.

### macOS install (Homebrew)

```bash
brew install php@8.3 composer mysql svn wp-cli
brew services start mysql
```

### Linux install (Debian / Ubuntu)

```bash
sudo apt-get install php8.3-cli php8.3-gd php8.3-mbstring php8.3-mysql php8.3-xml \
                     php8.3-zip composer mysql-server subversion
# WP-CLI:
curl -sSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
chmod +x /usr/local/bin/wp
sudo service mysql start
```

### Windows

Native Windows install is discouraged because `bin/install-wp-tests.sh` is a
Bash script and depends on GNU-style tools (`grep -oP`, `sed -i`, `svn`).
Use **WSL2** with a Debian/Ubuntu instance and follow the Linux instructions
above, OR use the Docker path.

### One-time bootstrap

```bash
# Install the test dependencies (PHPUnit + polyfills). Test deps live in
# composer.tests.json / composer.tests.lock and install into vendor-tests/;
# the main composer.json is the module BUILD tool and is not needed for
# running tests.
COMPOSER=composer.tests.json composer install

# Install the WordPress test framework (~3 min, SVN checkout).
# Adjust the DB creds to match your local MySQL setup.
bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
```

The install script:

1. Downloads the latest WordPress into `/tmp/wordpress` via WP-CLI.
2. Creates a `wordpress_test` MySQL database.
3. Runs `wp core install` to seed WordPress.
4. SVN-checks-out the WordPress unit-test framework into `/tmp/wordpress-tests-lib`.
5. Copies + patches `wp-tests-config.php` with the DB credentials.

### Running tests

```bash
# All unit testsuites
vendor-tests/bin/phpunit

# Specific testsuite
vendor-tests/bin/phpunit --testsuite Replacer
vendor-tests/bin/phpunit --testsuite "EMR Main"

# Specific test method
vendor-tests/bin/phpunit --filter test_replaces_a_plain_string

# Specific file: filter on its class name (file paths don't work with
# the test-Foo.php naming, see the note in the Quick start section)
vendor-tests/bin/phpunit --testsuite Replacer --filter '^ReplacerTest'

# Integration, then the isolated file
vendor-tests/bin/phpunit -c phpunit-integration.xml
vendor-tests/bin/phpunit -c phpunit-integration.xml --testsuite IntegrationIsolated
```

Called directly, PHPUnit has no zero-test guard: a wrong suite name or an
empty filter prints `No tests executed!` and exits 0. Always read the count.

## Running against the CI reference

The CI configuration lives in `.github/workflows/`:

`phpunit.yml` ("EMR PHPUnit Tests") runs on every push to `updates` or
`tests` and on pull requests into `updates` or `master`; any branch can be
run by hand (Actions -> "Run workflow"). Pull requests whose source branch is
`updates` or `tests` in this repository are skipped, because the push already
tested that commit. Stale runs of the same pull request are cancelled; every
pushed commit keeps its own run. Four jobs, all on `ubuntu-latest` with MySQL 8.0 as a service
container on port 3306 and PHP from `shivammathur/setup-php@v2`:

| Job | Matrix | What it runs |
|---|---|---|
| `build` | PHP 7.4 / 8.3 / 8.5, WP latest | a `--list-suites` / `--list-tests` sanity step, then each unit suite (`Helper`, `Replacer`, `Modules`, `Api`, `Controllers`, `ViewControllers`, `EMR Main`) in its own phpunit invocation, so per-suite verdicts stay visible when an earlier suite fails, with a grand-total summary |
| `integration` | PRs: 8.3/latest, 7.4/5.9, 8.3/5.9. Pushes and manual runs: 7.4, 8.3, 8.5 on latest plus 7.4/5.9 and 8.3/5.9 | `phpunit -c phpunit-integration.xml`, then `--testsuite IntegrationIsolated` |
| `multisite` | PHP 8.3, WP latest | `WP_MULTISITE=1 phpunit -c phpunit-integration.xml --bootstrap tests/Multisite/bootstrap.php --testsuite Multisite` |
| `compat` | PHP 8.3 / 8.5, WP latest | downloads the free partner plugins, then `EMR_PARTNER_PLUGINS=1 phpunit -c phpunit-integration.xml --testsuite Compat`; zip-backed tests self-skip |

Every PHPUnit step carries a zero-test guard: the output is captured with
`tee`, and the step fails when PHPUnit exits non-zero, when the log contains
`No tests executed!`, or when it has no `OK (` / `Tests:` summary line (an
`exit()` mid-run). A green job always ran real tests.

`smoke.yml` and `e2e.yml` run on manual dispatch only, never on push or PR.

The Docker-based local setup (`.docker/Dockerfile.tests` +
`docker-compose.tests.yml` + `bin/test.sh`) is designed to match this
environment: same PHP versions, same MySQL image, same
`bin/install-wp-tests.sh` script. If a test passes locally via `bin/test.sh`,
it should pass on CI.

## Browser end-to-end tests (Playwright)

The PHPUnit suites never load a browser, so EMR's screens (the replace
form, the success and error screens, the remove-background and upscale
previews, the API-key box, the upsell installer) and their JavaScript
(`js/`) have no
coverage there. The E2E suite closes that gap: a REAL served WordPress with
EMR installed, driven by [Playwright](https://playwright.dev), asserting what
a user sees and failing on any uncaught JS error. It is ported from SPIO's
E2E stack.

```bash
bin/test-e2e.sh                          # provision + run every project (chromium, firefox, webkit, visual)
bin/test-e2e.sh --project chromium       # one engine: chromium | firefox | webkit | visual (repeatable)
bin/test-e2e.sh --project visual --update-snapshots   # refresh screenshot baselines after an intended UI change
bin/test-e2e.sh --grep "replace"         # subset by title
bin/test-e2e.sh specs/smoke.spec.ts      # one spec (paths relative to tests/E2E)
bin/test-e2e.sh --headed                 # visible browser, natively on the host (needs Node.js)
bin/test-e2e.sh --ui                     # Playwright UI mode, natively on the host
bin/test-e2e.sh --report                 # open the last HTML report
bin/test-e2e.sh --provision-only         # just bring the site up at http://localhost:8031
bin/test-e2e.sh --pull-only              # pull the images with retry (CI's first step)
bin/test-e2e.sh --wp 6.5                 # against an older WordPress (fresh volumes)
bin/test-e2e.sh --down                   # stop the stack (keeps the volumes)
bin/test-e2e.sh --clean                  # stop + wipe the DB / core / node_modules volumes
```

The site stays up after a run (admin / password) so you can look at the
state a test left behind. Bring it down with `--down` when you are done:
only one test stack runs at a time on a machine (see "One stack at a time").

**Stack** (`docker-compose.e2e.yml`, compose project `emr-e2e`, fully
separate from the PHPUnit stack and from SPIO's E2E stack: its own database,
volumes `emr-e2e_*` and port):

| Service | What |
|---|---|
| `mysql-e2e` | MySQL 8.0, database `wordpress_e2e` |
| `wordpress` | official `wordpress:php8.3-apache` image; the repo is bind-mounted as `wp-content/plugins/enable-media-replace`; served on **http://localhost:8031** (SPIO's stack uses 8030) |
| `wpcli` | one-shot provisioning (`tests/E2E/provision/provision.sh`): core install, theme, EMR activation, seed. Runs as uid 33 (Apache's www-data) so the seed's uploads stay writable for the site |
| `playwright` | `mcr.microsoft.com/playwright`, its tag derived by `bin/test-e2e.sh` from the exact `@playwright/test` version in `tests/E2E/package.json` (bumping that line is the whole upgrade; `node_modules` are reinstalled whenever `package-lock.json` changes); shares the wordpress container's network, so http://localhost:8031 works inside it too; `tests/fixtures` is mounted at `/fixtures` |

**Test-support mu-plugins** (`tests/E2E/mu-plugins/`, inert unless
`EMR_E2E` is defined, which only `docker-compose.e2e.yml` does):

- `emr-e2e-mock-api.php`: the PHPUnit `MockEmrApi` ported to a live
  install. Answers every `api.shortpixel.com` endpoint EMR calls
  (`free-reducer.php`, `reducer.php`, `cleanup.php`, `api-status.php`,
  `notices.php`, the `/f/<token>` result downloads) and wordpress.org
  (`api.wordpress.org`: empty JSON success; `downloads.wordpress.org/plugin/<slug>.zip`:
  a zip built on the fly with a code-free stub plugin, so the upsell
  installer installs and activates something real; other wordpress.org
  URLs: an empty feed). Knobs, round counters and the request log live in
  options; result images in `uploads/emr-e2e-mock/`. The knobs are the
  PHPUnit mock's (`forceStatusCode`, `waitingRounds`, `errorMessage`,
  `quotaExceeded`, `malformedBody`, `wpErrorMessage`, `apiStatusCode`,
  `apiStatusMessage`, `unlimited`, `notices`, `resultFixture`) plus
  `downloadsFail`; the file header documents each.
- `emr-e2e-support.php`: REST namespace `emr-e2e/v1`, every route guarded
  by the `X-EMR-E2E-TOKEN` header. Routes: `reset`, `seed`, `option`,
  `fixture` (a file from `tests/fixtures/` or a GD-drawn image of any size,
  as an attachment), `image` (GD-drawn bytes for a browser upload), `post`,
  `post/<id>`, `attachment/<id>`, `key`, `feature` (forces
  `emr/feature/background` / `emr/feature/upscale` /
  `emr/feature/remote_notice`), `user`, `mock`,
  `mock/reset`, `mock/requests`. It also turns off the core, plugin and
  theme update checks, pings and the dashboard news widget.

**Seed and reset.** Every test starts from the same state: the `emr`
fixture calls `reset` before each test. Reset deletes every attachment
(with its files), post, page and suite-created user, the EMR options
(`enable_media_replace`, `emr_shortpixel_api_key`, the
`EnableMediaReplace-notices` notices option), the `emr_remote_notice` and
remove-background result transients, the feature overrides, the mock state,
the stub plugins the upsell installer installed (recognised by a marker in
their header), and the year/month and `emr-e2e-*` folders under uploads.
Then it seeds: `emr-seed.jpg` (800x600, so WordPress makes the thumbnail,
medium and medium_large sizes) and a published post that links the full
image and embeds its medium size. `emr.seed` holds their ids and URLs.

**Layout** (`tests/E2E/`): `playwright.config.ts` (serial, one worker,
zero retries: every spec shares one install), `fixtures.ts` (the
console-error tripwire, hermetic routing, the `emr` support client),
`helpers/` (`wp-admin.ts` login and admin URLs, `media-list.ts` the media
list row actions and the edit-attachment meta box, `emr.ts` the support
client, EMR URLs and the screen objects, `journeys.ts` the shared journey
steps), `specs/` (`auth.setup.ts` logs in
once; `smoke.spec.ts` proves the harness).

**Writing E2E specs**

- Import `test`/`expect` from `../fixtures`, never from `@playwright/test`:
  that is what arms the tripwire, the hermetic routing and the reset.
- Use the screen objects in `helpers/emr.ts` (`ReplaceScreen`,
  `SuccessScreen`, `ErrorScreen`, `RemoveBackgroundScreen`, `UpscaleScreen`
  (the same form and buttons plus the factor radios), `Upsell`) and
  `helpers/media-list.ts` (`MediaList`, `EditAttachment`) rather than raw
  selectors. The replace form's file input is `#upload-file`
  (`name="userfile"`); there is no "new file name" field: in
  `replace_and_search` mode the new name is the uploaded file's name.
- Files for the browser: `fixtureFile('test-image.jpg')` (from
  `tests/fixtures/`) or `await emr.generatedFile(1024, 768, { format: 'png' })`.
- Completion signals are DOM states and server state, never a timer: the
  success screen heading, the redirect to the edit screen with
  `emr_replaced=1`, the preview `<img>` the remove-background script
  inserts, then `emr.attachment(id)` / `emr.postContent(id)` for the truth
  in the database. No `waitForTimeout`, no retries.
- The success screen redirects by itself after a countdown
  (`js/emr_success.js`); assert the heading first, then wait for the URL.
- The remove-background preview shows the mock's result URL
  (`https://api.shortpixel.com/f/<token>`) in an `<img>`; `fixtures.ts`
  answers that one request with the stashed bytes, every other shortpixel.com
  request is aborted. A pending answer (`waitingRounds`) costs real seconds:
  `Api::request()` sleeps 3 s (with a key) or counter + 3 s (free) per round.
- The plan-expired state is a stored key plus `unlimited: 'false'`: set the
  key with `emr.setApiKey(...)` (a save through the screen refuses a
  non-Unlimited key), then the knob.
- Never `waitForURL` for a page that reloads the SAME URL (the API-key box
  reloads after a save or removal): it resolves immediately. Wait for a DOM
  state of the new document, or use `withSelfReload(page, action)`.
- A spec that expects JS errors (a pin for a known bug) opts out with
  `test.use({ allowConsoleErrors: true })` and asserts on `consoleErrors`.
  Known defects are listed in `KNOWN_JS_DEFECTS` (`helpers/emr.ts`);
  `expectOnlyKnownErrors(consoleErrors, [...])` accepts those and nothing
  else. The remove-background screen throws one on every load
  (`emr_admin.js` `showReplaceOptions()` on a missing `.location_option`,
  pinned in `smoke.spec.ts`), so every remove-background spec needs it; the
  upscale screen enqueues the same script and the upscale specs accept the
  same error.
- Node-side HTTP to the container sends `Connection: close`
  (`NO_KEEPALIVE_HEADERS` in `helpers/emr.ts`): Apache closes idle
  keep-alive sockets after 5 s, and Node, unlike a browser, does not retry
  the resulting "socket hang up". Pass the header to any direct
  `request.get()`/`post()` you add.
- **The suite never needs the internet.** Browser side, `BLOCKED_HOSTS` in
  `fixtures.ts` aborts shortpixel.com, wordpress.org, gravatar.com and
  s.w.org; server side, the mock answers shortpixel.com and wordpress.org
  and the support plugin turns the update checks off. To find a new outside
  dependency, add `dns: [127.0.0.1]` to the `wordpress` service, run, and
  revert the line.
- Pinned tests follow the PHPUnit rules (`_pinned_for_deferred_fix`, a
  sentinel that proves the flow ran, how to recognise the fix in the
  docblock).
- Artifacts (traces, screenshots, videos, HTML report) land in
  `tests/E2E/artifacts/` (gitignored); on CI they are uploaded on every run.
- `bin/test-e2e.sh` passes arguments straight to Playwright, whose
  `--project` takes several values: put a spec path BEFORE `--project`
  (`bin/test-e2e.sh specs/x.spec.ts --project webkit`).

**Browser projects** (`playwright.config.ts`): the functional suite runs
once per engine (`chromium`, `firefox`, `webkit`) at 1366x768. A test must
pass on all three; an engine difference is either a real EMR cross-browser
bug (pin it) or a test assumption to fix. Playwright's Linux WebKit can die
mid-navigation on a starved machine ("WebKit encountered an internal
error"), so on CI WebKit reports without gating (`continue-on-error`) and
videos are off; Chromium and Firefox gate.

**Visual regression** (`specs/visual.spec.ts`, project `visual`, Chromium
only): baselines for the replace screen and the remove-background screen in
`tests/E2E/snapshots/visual.spec.ts/`, compared only inside the Playwright
image (`E2E_IN_DOCKER`; no-ops on a native run). Capture EMR's own container,
not the full page; `helpers/screenshot.css` hides the admin bar, menu and
footer. The budget is absolute (`maxDiffPixels: 100`). `bin/test-e2e.sh`
re-provisions the site when the WordPress core in the volume differs from
the pulled image, because a core change moves the admin CSS. Refresh
intended changes with `bin/test-e2e.sh --project visual --update-snapshots`.

**CI**: `.github/workflows/e2e.yml` runs the identical Docker stack on
`ubuntu-latest`, on manual dispatch only, one job per engine (`visual` rides
in the Chromium job, `fail-fast: false`). Each job fails on a non-zero exit
and on a log without an `N passed` line or with `0 passed`. Artifacts are
uploaded per engine.

## Writing new tests

- **Location and names:** `tests/<Suite>/test-<Subject>.php`, one class per
  file named `<Subject>Test`, methods `test_<description>` in snake case
  describing the behaviour.
- **Base classes:** unit tests extend `WP_UnitTestCase` (not raw
  `PHPUnit\Framework\TestCase`): EMR relies on real WordPress state (real
  `wpdb`, real filters, real uploads). Integration, compat and multisite
  tests extend `EMR_IntegrationTestCase`, or `EMR_AjaxTestCase` for the
  `wp_ajax_emr_*` endpoints (nonce, capability, JSON shape). Both live in
  `tests/Integration/Helpers/`, along with the fixture upload helpers.
- **Fixtures:** under `tests/fixtures/` (`test-image.jpg`,
  `codeispoetry.png`, and whatever a test adds). Never replace the committed
  fixture in place: every test uploads its own copy and removes the
  attachment and its files in `tear_down()`.
- **HTTP mock:** `MockEmrApi` intercepts `pre_http_request` for
  `api.shortpixel.com` and `downloads.wordpress.org`. Its knobs cover the
  reducer endpoints shared by remove background and upscale
  (`free-reducer.php`, `reducer.php`: status 1
  then 2, a `LosslessURL` result, quota exceeded, malformed body,
  `WP_Error`), `cleanup.php`, `api-status.php` (`Code` 2 with `Unlimited`
  true/false, wrong key), `notices.php` and the result download URL, and it
  keeps a request log for asserting what the plugin sent. The base
  integration case adds a fail-fast `pre_http_request` filter at priority
  9999, so any request the mock doesn't answer fails the test instead of
  reaching the network. Knobs are set with `setKnob( name, value )` (`forceStatusCode`, `waitingRounds`, `errorMessage`, `quotaExceeded`, `malformedBody`, `wpErrorMessage`, `apiStatusCode`, `apiStatusMessage`, `unlimited`, `notices`, `resultFixture`); the request log is read with `getRequests()`, `lastRequest()` and `requestsTo( pathFragment )`; `stashImage()` registers result bytes under a download URL; `reset()` clears everything.
- **Snapshot and restore** every option, transient, post meta, user and
  filesystem fixture a test touches: snapshot in `set_up()`, restore in
  `tear_down()`. Singletons (`UIHelper`, `EnvHelper`, `ApiKeyManager`,
  `RemoteNoticeController`, the view controllers) are reset by reflection in
  `set_up()`. Filters a test adds are removed in `tear_down()`.
- **Constants:** a test that must `define()` a constant (`EMR_CAPABILITY`,
  `SHORTPIXEL_DEBUG`, a partner plugin's marker) goes into
  `tests/Integration/test-ConstantsAndFilters.php` (the `IntegrationIsolated`
  suite) or runs with `@runInSeparateProcess`.
- **Pinned tests:** a test that pins a known, not-yet-fixed defect is named
  `test_<description>_pinned_for_deferred_fix`. Its docblock describes the
  defect in the present tense, where it lives (file and function) and how to
  recognise the fix. The pin must fail the day the bug is fixed: assert type
  as well as value, use numerically distinct ids, verify preconditions, and
  pair every "did not throw" with a positive assertion. The production code
  gets a `@todo` in the nearest docblock; it is never changed to make a test
  pass.
- **PHP 7.4-compatible test code:** no `readonly`, enums, `never`, named
  arguments or `match`; the same file runs on 7.4 and 8.5.
- **PHPUnit 9 assertion names:** `assertMatchesRegularExpression`,
  `assertObjectHasProperty` (provided by the Yoast polyfills), never the
  removed or renamed variants.
- **No bug numbers, ever:** bug tracking is internal and its numbering stays
  out of the repository (no ticket or ledger numbers, commit hashes as
  history, dates or names in test names, docblocks or assertion messages).
  See the "Known bugs and @todo" section of `CLAUDE.md`.
- **Report the count:** a green run that ran nothing is a failure. A skip
  needs a reason in its message; no retries, no sleeps.

## Known traps

- **Testsuite names are case-sensitive.** `--testsuite replacer` matches
  nothing. `bin/test.sh` rejects an unknown name, but PHPUnit called directly
  prints `No tests executed!` and exits **0**.
- **Filter by class name, not file path.** `test-Foo.php` never matches
  PHPUnit 9's expected class name; use `--filter '^FooTest'`. A filter that
  matches nothing is also `No tests executed!` with exit 0.
- **`exit()` mid-test kills PHPUnit with exit code 0.** The run ends after
  a few dots with no `Tests:` / `OK` summary. In EMR the culprits are
  `ViewController::viewError()` (renders the error view and exits), the
  success redirect, `wp_die()` and `wp_send_json*()` in the AJAX handlers.
  Reach the decision through the protected methods, or use
  `EMR_AjaxTestCase`, which turns the die into an exception. Running with
  `--debug` shows which test the process died in. CI treats
  a missing summary line as a failure.
- **DDL inside a test commits the transaction.** `CREATE`, `ALTER`, `DROP`
  and `TRUNCATE` auto-commit in MySQL, so everything the test wrote before
  them survives the per-test rollback and leaks into later tests. Create
  tables in a bootstrap, before the first test transaction, or clean up by
  hand.
- **Constants defined in tests leak.** A `define()` lives until the process
  ends and cannot be undone. Constant-dependent tests go into
  `IntegrationIsolated` or `@runInSeparateProcess`.
- **`@dataProvider` + `markTestSkipped()` in the provider can fatal.** A
  provider that skips, or returns objects PHPUnit has to serialize (closures,
  `WP_Post`, `wpdb`), fails with a serialization error instead of a skip,
  especially with process isolation. Keep providers to scalars and arrays;
  skip inside the test method.
- **Partner plugins have side effects.**
  - Breakdance's activation is not idempotent (its `SERIAL` column change
    adds a new unique index every run until MySQL's 64-key limit), so the
    integration bootstrap drops its icons table before activating it.
  - On PHP 8.5 Breakdance's icon loader emits ~2,500 identical `fgetcsv()`
    deprecations while it loads. The bootstrap swallows `E_DEPRECATED`
    raised from inside partner plugin directories only, under
    `EMR_PARTNER_PLUGINS`; EMR's own deprecations still show.
  - WP Offload Media creates its tables lazily behind a static flag that the
    test process trips early, so the integration bootstrap forces both
    tables into existence up front; without that every item lookup prints a
    missing-table error.
  - Some activation hooks call home: WPBakery's runs `wp_update_plugins()`
    against api.wordpress.org. A failed request raises a warning, the
    bootstrap's error handler throws, and the run ends before the first
    test with no summary line. The integration bootstrap answers
    api.wordpress.org with an empty success for the whole compat process.
  - The very first `--compat` run against a fresh database prints
    "Table ... wc_webhooks doesn't exist" and "... shortpixel_postmeta
    doesn't exist" while the WP test library empties the posts table: the
    partner tables are created by the activation hooks that run right
    after. The second run is clean; the messages are not failures.
  - A compat run leaves the partner plugins extracted in the shared test
    install (`wp-content/plugins/`, `wp-content/themes/`). They are not
    activated outside `--compat`, but a later integration run sees their
    folders on disk: never assert that a plugin folder is absent, assert
    that a request left it unchanged. `bin/test.sh --clean` removes them.
  - In production the WP Offload Media integration never subscribes in
    time (as3cf fires `as3cf_init` before EMR builds `WPOffload`); the
    compat tests call `WPOffload::init()` by hand to reach the intended
    state, and one pin documents the ordering.
- **WP 5.9 is the oldest version the test library installs.** EMR's readme
  floor is lower; versions below 5.9 can't be tested by this harness. On 5.9
  non-image attachments have no metadata (`''` instead of an array, no
  `filesize` key, which core adds in 6.0), so assertions on metadata are
  version-aware; and 5.9 core is not PHP 8-clean, so deprecations raised
  from core files are swallowed by the bootstrap's error handler (they would
  otherwise print into AJAX responses and break their JSON). Deprecations
  from the plugin's own code stay visible.
- **Core update checks run inside `WP_Upgrader::run()`.** The integration
  baseline answers `api.wordpress.org` with an empty 200 so
  `wp_version_check()` and friends are a no-op; without that, 5.9 raises a
  warning when the request is refused. Every other host is still blocked.
- **`WP_Ajax_UnitTestCase` masks `E_WARNING`.** Warnings raised inside an
  AJAX handler are silent on PHP 8 unless the test raises `error_reporting`
  for the duration of the request.
- **Notices and warnings fail tests, by design.** PHPUnit's own conversion is
  inert once a bootstrap error handler exists, so `tests/bootstrap.php`
  converts them itself. `expectNotice()` / `expectWarning()` work; a stray
  notice in production code is a finding to pin, not noise to silence.

## Troubleshooting

**"Could not find bin/install-wp-tests.sh" or SVN errors on first run**
The Docker approach installs SVN in the container. If running natively,
install `subversion` (Homebrew / apt) and retry.

**"Class 'WP_UnitTestCase' not found"**
The WordPress test framework isn't installed. Run `bin/install-wp-tests.sh`
(native) or `bin/test.sh` (Docker, installs automatically on first run).

**`No tests executed!`**
A testsuite name with the wrong case, a filter on a file path, or a filter
that matches nothing. Check the names in the tables at the top of this file.

**Tests pass locally but fail on CI (or vice versa)**
Run `bin/test.sh --matrix` locally to check across PHP versions. Most
cross-env failures are PHP-version-specific deprecations (PHP 8.5 surfaces
things 8.3 doesn't), a zip-backed compat test that runs locally but skips on
CI, or a stale test cache. Try `bin/test.sh --clean` and rerun.

**Strange database state, random failures, "table doesn't exist"**
Another stack is running against the same database: an SPIO run, a second
`bin/test.sh`, or the E2E stack. Stop everything, run one stack at a time.

**Docker on macOS: "no matching manifest for linux/arm64"**
All the images used (`php:*-cli`, `mysql:8.0`, `composer:2`) publish arm64
tags natively. If you see this, Docker Desktop needs updating, or a specific
PHP version tag doesn't ship arm64 yet; fall back to `--php 8.3`.

**Docker: "port 3306 already in use"**
The MySQL service in `docker-compose.tests.yml` does NOT publish its port to
the host, so this shouldn't happen. If it does, another Compose file is
claiming that port; check your local overrides.

**Docker: `ERROR 2026 (HY000): TLS/SSL error: self-signed certificate in certificate chain`**
MySQL 8 auto-generates a self-signed TLS cert at startup and the MariaDB
client (installed via Debian's `default-mysql-client`) rejects it. The
Dockerfile ships a `/usr/local/bin/mysql` wrapper that always passes
`--skip-ssl` to the underlying binary. Safe because the mysql traffic never
leaves Docker's internal network. If you still see this error, your image is
stale:

```bash
bin/test.sh --clean
bin/test.sh
```

**A compat test skips with "drop ... into tests/partner-plugins/" (or `tests/partner-themes/`)**
The commercial plugin's (or the Bricks theme's) zip is missing. Copy it into
the named folder and rerun `--compat`. On CI this skip is expected.

**Some tests are labeled `pinned_for_deferred_fix`: should I care?**
Those tests pin known, not-yet-fixed defects: they assert the current
(wrong) behaviour and go red once the defect is fixed. Each docblock
describes the defect and where it lives. Don't "fix" a pinned test by
changing its assertion; that defeats the sentinel. When the fix lands, turn
the pin into a positive test and remove the `@todo` from the production
code.

**PHPUnit prints a few dots then abruptly ends with no summary (exit code 0)**
Something in the code under test called `exit()` mid-test, see Known traps.
Common culprits in EMR: `ViewController::viewError()`, the success redirect
in the upload view controller, `wp_die()` and `wp_send_json*()` in
`classes/ajax.php`.
