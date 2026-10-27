# AGENTS.md

Entry point for AI coding agents (Codex, Cursor, Copilot, Claude Code, and
anything else that reads this file).

**Read `CLAUDE.md` first** (architecture, commands, conventions), then
`TESTING.md` (the full test reference). This file only carries the handful
of rules that are expensive to get wrong, so it stays short enough not to
drift.

## Verify your environment before you change anything

```bash
bin/test.sh --verify
```

Runs the smallest suite and requires a real passing result. It exits non-zero
unless tests actually executed, so it cannot report success on a broken setup.
Docker must be running; nothing else is needed on the host: no PHP, MySQL,
Composer, WP-CLI or SVN.

## Running tests

```bash
bin/test.sh                                              # all unit suites (PHP 8.3, matches CI)
bin/test.sh --testsuite Replacer                         # one suite
bin/test.sh --testsuite Replacer --filter '^ReplacerTest' # one file, by CLASS name
bin/test.sh --integration                                # integration suite
bin/test.sh --ms                                         # multisite suite
bin/test.sh --compat                                     # cross-plugin compatibility suite
bin/test.sh --all                                        # unit + integration + multisite + compat
```

Three traps, all of which look like success if you are not watching:

1. **Testsuite names are case-sensitive.** The unit suites are `Helper`,
   `Replacer`, `Modules`, `Api`, `Controllers`, `ViewControllers`,
   `EMR Main`. `bin/test.sh` rejects an unknown name, but a bare
   `vendor-tests/bin/phpunit --testsuite replacer` prints
   `No tests executed!` and exits **0**.
2. **`--filter` takes a CLASS name, not a file path.** PHPUnit cannot map
   `test-Foo.php` to `FooTest`. A filter that matches nothing is also a silent
   exit 0.
3. **Always check the test count in the output.** `OK (0 tests)` or
   `No tests executed!` means your change was never exercised.

One test stack at a time on a machine. Never start a run while another run
(including a ShortPixel Image Optimizer run) is in progress.

## Non-negotiable rules

- **Never commit, branch, create a worktree, push, rebase, merge or open a
  pull request** unless explicitly asked. Work lands uncommitted in the
  current working tree; end the hand-off with a `git commit -m` suggestion.
- **Never fix production code speculatively.** A defect found by a test is
  pinned (`test_<description>_pinned_for_deferred_fix`), documented with a
  `@todo`, and reported. Only comment-only edits are documentation.
- **No bug numbers in the repo.** Bug tracking is internal; never write
  ticket numbers, `E#` numbers, commit hashes as history, dates or names into
  code, tests or docs. A known open defect is a `@todo` in the nearest
  docblock (what, where, suggested fix). A fixed bug is not mentioned at all.
- **A green run that ran nothing is a failure.** Report the executed test
  count. No retries, no sleeps, no skips as a fix.
- **Document before you test.** The docblocks are the spec the tests are
  checked against.

## Do not edit

- `build/shortpixel/`: bundled, generated modules. Edit the sources in the
  sibling `../modules/*` repository, then rebuild (`composer run buildSP`).
- `vendor/`, `vendor-tests/`: installed dependencies, gitignored.

## Conventions

- Plugin classes are namespaced `EnableMediaReplace\` and required
  explicitly from `enable-media-replace.php`; bundled modules are autoloaded
  from `build/shortpixel/`.
- Test files are `tests/**/test-<Name>.php`; test classes are `<Name>Test`;
  test methods are `test_<description>`.
- Most controllers and helpers are singletons: use `getInstance()`.
- The plugin version lives in `enable-media-replace.php` and `readme.txt`;
  do not copy it elsewhere.
- Test code must run on PHP 7.4 and 8.5 alike.
