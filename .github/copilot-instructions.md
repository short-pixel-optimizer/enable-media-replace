# Copilot instructions

This repository keeps its agent guidance in **`CLAUDE.md`** at the repo root
(architecture, commands, conventions), summarised in **`AGENTS.md`**, with
the test reference in **`TESTING.md`**. Read `CLAUDE.md` first and follow it.

The things most often got wrong here:

1. Verify the environment before changing anything: `bin/test.sh --verify`
   (needs Docker; exits non-zero unless tests really ran).
2. PHPUnit testsuite names are **case-sensitive**: the unit suites are
   `Helper`, `Replacer`, `Modules`, `Api`, `Controllers`, `ViewControllers`,
   `EMR Main`. An unknown name makes PHPUnit print `No tests executed!` and
   exit **0**, so always check the test count in the output.
3. Never commit, branch, push, rebase, merge or open a pull request unless
   explicitly asked. Work stays uncommitted in the working tree.
4. Never fix production code speculatively. A defect found by a test is
   pinned (`test_<description>_pinned_for_deferred_fix`) and documented with
   a `@todo`; it is fixed only when the maintainer asks.
5. Never edit `build/shortpixel/`: it is generated from the sibling
   `../modules/*` repository.
6. No bug numbers in code, tests or docs: bug tracking is internal. Document
   an open defect as a `@todo`; do not mention fixed bugs.
7. One test stack at a time on a machine; no retries, no sleeps, no skips as
   a fix.
