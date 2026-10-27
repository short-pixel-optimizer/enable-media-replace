# Running the Enable Media Replace tests

This document describes how to run the PHPUnit and browser test suites
locally and how they map to the GitHub Actions CI. Nothing here is shipped to
WordPress.org; this file lives in the repository for the dev team.

The harness is a port of the ShortPixel Image Optimizer (SPIO) one. The
sections marked "to be written" are completed in the test-environment phase
of `docs/TESTING-PLAN.md`; the suite layout below is the one from that plan.

## What's in the suite

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

The tests run against a real WordPress test environment (`WP_UnitTestCase`),
not a mocking library, so they need MySQL and a checkout of the WordPress test
framework. The Docker harness provides both.

Test files follow the convention `test-<Name>.php`; test classes are named
`<Name>Test`; test methods `test_<description>`; pinned defects
`test_<description>_pinned_for_deferred_fix`.

## Recommended: run locally via Docker

**Prerequisite:** Docker Desktop (macOS / Windows) or Docker Engine with the
`docker compose` plugin (Linux). Nothing else: no local PHP, MySQL, Composer,
WP-CLI or SVN.

### Quick start

```bash
bin/test.sh --verify                 # first: prove the environment works
bin/test.sh                          # every unit suite on PHP 8.3 (the CI baseline)
bin/test.sh --testsuite Replacer     # one suite (names are case-sensitive)
bin/test.sh --filter test_replaces_a_plain_string
bin/test.sh --testsuite Replacer --filter '^ReplacerTest'   # one file, by CLASS name
```

> **Running a single file:** passing a file path does NOT work. PHPUnit 9
> derives the expected class name from the file name and `test-Foo.php` never
> matches `FooTest`. Use `--filter <ClassName>`, anchored with `^` when you
> need exactly one class.

### PHP version matrix

```bash
bin/test.sh --php 7.4
bin/test.sh --php 8.5 --testsuite Api
bin/test.sh --matrix                 # 7.4, 8.3 and 8.5 in sequence, like CI
```

### WordPress version

```bash
bin/test.sh --wp 6.4
bin/test.sh --wp latest              # the default
```

### Integration, compat, multisite, smoke

```bash
bin/test.sh --integration
bin/test.sh --compat                 # downloads the free partner plugins; paid ones from tests/partner-plugins/*.zip
bin/test.sh --ms
bin/test.sh --all                    # unit + integration + multisite + compat
SHORTPIXEL_SMOKE_KEY=... bin/test.sh --smoke   # real API, opt-in, never in CI
```

### Debug workflow and reset

```bash
bin/test.sh --shell                  # bash inside the PHP container
bin/test.sh --clean                  # remove containers, volumes and the per-version images
```

## One stack at a time

The harness is a shared singleton on a machine: one MySQL, one
`wordpress_test` database, one E2E site port. Run suites sequentially, never
two `bin/test.sh` invocations at once, never while an SPIO run is in
progress, and never the PHPUnit stack and the E2E stack together.

## Browser end-to-end tests (Playwright)

To be written in the E2E phase. The stack is SPIO's (`docker-compose.e2e.yml`,
`bin/test-e2e.sh`, `tests/E2E/`), on `http://localhost:8031`, with zero
retries, one worker, and every external host blocked at the browser and at
the WordPress HTTP layer.

## Running against the CI reference

To be written in the test-environment phase: the `phpunit.yml` matrix
(PHP 7.4 / 8.3 / 8.5, integration, multisite, compat jobs), `e2e.yml` and
`smoke.yml` (manual dispatch only), each with the zero-test guard.

## Writing new tests

To be written in the test-environment phase: fixtures under `tests/fixtures/`,
the integration helpers and the HTTP mock, snapshot and restore rules, and the
pinned-test convention. The short version is in `CLAUDE.md`.

## Known traps and timing

To be written in the test-environment phase, with measured timings.
