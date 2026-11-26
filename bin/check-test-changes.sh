#!/usr/bin/env bash
#
# Test change guard for EMR (Enable Media Replace): finds changes that weaken
# or remove EXISTING tests, so they never slip in unnoticed. New tests and
# new assertions pass freely. See "Changing existing tests" in CLAUDE.md for
# the rule this enforces.
#
# What it reports, under tests/:
#   - a deleted or renamed test file (*.php, *.ts);
#   - a removed or edited line that declares a test or asserts something
#     (function test_*, test(...), assert*(), expect(...), expectException,
#     setExpected*, toHaveScreenshot);
#   - an added skip (markTestSkipped, markTestIncomplete, test.skip,
#     test.fixme, describe.skip);
#   - a changed or deleted screenshot baseline (tests/E2E/snapshots).
#
# Usage:
#     bin/check-test-changes.sh --range <base> <head>
#         Pull request mode: one verdict for the whole difference between the
#         merge base of <base> and <head>, and <head>. Approved by the
#         environment variable TEST_CHANGES_APPROVED=true (CI sets it from the
#         `test-changes-approved` label, which only maintainers can apply).
#
#     bin/check-test-changes.sh --per-commit <base> <head>
#         Push mode: every non-merge commit in <base>..<head> is checked on
#         its own. A commit is approved by a trailer line in its message:
#             Tests-Changed: <the reason>
#
#     bin/check-test-changes.sh                 # the uncommitted work against HEAD (report only)
#
# Exit code: 0 when nothing is found or everything found is approved, 1
# otherwise, 2 on a usage error.

set -euo pipefail

PATTERN_REMOVED='function[[:space:]]+test_|(^|[^[:alnum:]_.])test(\.[a-z]+)?\(|assert[A-Za-z_]*\(|expect(\.[a-zA-Z]+)?\(|expectException|setExpected|toHaveScreenshot'
PATTERN_SKIP='markTestSkipped|markTestIncomplete|test\.(skip|fixme)\(|describe\.skip\('
TEST_CODE=( ':(glob)tests/**/*.php' ':(glob)tests/**/*.ts' )
SNAPSHOTS=( ':(glob)tests/E2E/snapshots/**' )

# Prints the findings of one diff (arguments are passed to `git diff`).
findings() {
	local out

	out=$(git diff --name-status -M --diff-filter=DR "$@" -- "${TEST_CODE[@]}" || true)
	if [ -n "$out" ]; then
		echo "Test files deleted or renamed:"
		echo "$out" | sed 's/^/    /'
	fi

	out=$(git diff --name-status --diff-filter=MD "$@" -- "${SNAPSHOTS[@]}" || true)
	if [ -n "$out" ]; then
		echo "Screenshot baselines changed or deleted:"
		echo "$out" | sed 's/^/    /'
	fi

	local file removed skipped
	while IFS= read -r file; do
		[ -n "$file" ] || continue
		removed=$(git diff -U0 "$@" -- "$file" | grep -E '^-[^-]' | grep -E -- "$PATTERN_REMOVED" || true)
		skipped=$(git diff -U0 "$@" -- "$file" | grep -E '^\+[^+]' | grep -E -- "$PATTERN_SKIP" || true)
		if [ -n "$removed" ]; then
			echo "Tests or assertions removed or edited in $file:"
			echo "$removed" | cut -c1-160 | sed 's/^/    /'
		fi
		if [ -n "$skipped" ]; then
			echo "Skips added in $file:"
			echo "$skipped" | cut -c1-160 | sed 's/^/    /'
		fi
	done < <(git diff --name-only --diff-filter=M "$@" -- "${TEST_CODE[@]}")
}

mode="${1:-}"

if [ -z "$mode" ]; then
	found=$(findings HEAD)
	if [ -z "$found" ]; then
		echo "==> Test change guard: the uncommitted work does not weaken or remove an existing test."
		exit 0
	fi
	echo "==> Test change guard: the uncommitted work changes existing tests:"
	echo "$found"
	echo
	echo "    Each of these needs one of the reasons listed in CLAUDE.md, \"Changing existing tests\"."
	exit 1
fi

if [ $# -ne 3 ]; then
	echo "Usage: bin/check-test-changes.sh [--range|--per-commit] <base> <head>" >&2
	exit 2
fi
base="$2"
head="$3"

case "$mode" in
	--range)
		merge_base=$(git merge-base "$base" "$head")
		found=$(findings "$merge_base" "$head")
		if [ -z "$found" ]; then
			echo "==> Test change guard: no existing test is weakened or removed."
			exit 0
		fi
		echo "==> Test change guard: this change set alters existing tests:"
		echo "$found"
		echo
		if [ "${TEST_CHANGES_APPROVED:-false}" = "true" ]; then
			echo "==> Approved by the test-changes-approved label."
			exit 0
		fi
		echo "!!! Not approved. A maintainer reviews the list above and applies the"
		echo "    test-changes-approved label; see CLAUDE.md, \"Changing existing tests\"."
		exit 1
		;;
	--per-commit)
		failed=0
		checked=0
		for commit in $(git rev-list --reverse --no-merges "$base..$head"); do
			checked=$((checked + 1))
			found=$(findings "$commit^" "$commit")
			[ -n "$found" ] || continue
			subject=$(git log -1 --format='%h %s' "$commit")
			reason=$(git log -1 --format=%B "$commit" | grep -E '^Tests-Changed:[[:space:]]*[^[:space:]]' | head -n 1 || true)
			echo "==> $subject"
			echo "$found"
			if [ -n "$reason" ]; then
				echo "    Approved: $reason"
			else
				echo "!!! No \"Tests-Changed: <reason>\" line in the commit message."
				failed=1
			fi
			echo
		done
		if [ "$failed" -ne 0 ]; then
			echo "!!! Test change guard failed; see CLAUDE.md, \"Changing existing tests\"."
			exit 1
		fi
		echo "==> Test change guard: $checked commit(s) checked, no unapproved change to an existing test."
		exit 0
		;;
	*)
		echo "Unknown mode: $mode" >&2
		exit 2
		;;
esac
