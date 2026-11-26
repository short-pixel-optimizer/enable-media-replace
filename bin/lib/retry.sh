# Shared shell helper: retry a command with exponential backoff.
#
# Sourced by bin/test-e2e.sh. Kept in its own file so it can be tested in
# isolation (see the self-test at the bottom, `bash bin/lib/retry.sh --self-test`).
#
# Why: registry pulls from GitHub Actions runners occasionally die with
# "connection reset by peer" while fetching the Docker Hub auth token.
# A pull is idempotent and
# resumable, so retrying with backoff turns that into a non-event.

# emr_retry <attempts> <initial-delay-seconds> <command> [args...]
#
# Runs the command until it succeeds or <attempts> is exhausted, sleeping
# <delay> seconds between tries and doubling the delay each time.
# Returns the command's last exit status.
emr_retry() {
    local attempts="$1"
    local delay="$2"
    shift 2

    local n=1
    local status=0
    while true; do
        # The status must be read INSIDE the else branch: after the `if`
        # statement completes, $? is the if's own status (0), not the
        # command's — the self-test below caught exactly that mistake.
        if "$@"; then
            return 0
        else
            status=$?
        fi
        if [ "$n" -ge "$attempts" ]; then
            echo "!!! Failed after $n attempt(s): $*" >&2
            return "$status"
        fi
        echo "==> Attempt $n/$attempts failed (exit $status): $* — retrying in ${delay}s..." >&2
        sleep "$delay"
        delay=$((delay * 2))
        n=$((n + 1))
    done
}

# --- self-test -----------------------------------------------------------
# bash bin/lib/retry.sh --self-test
# Only when EXECUTED directly (never when sourced — the sourcing script's
# positional parameters would otherwise leak in here).
if [ "${BASH_SOURCE[0]}" = "$0" ] && [ "${1:-}" = "--self-test" ]; then
    set -eu
    counter_file="$(mktemp)"
    echo 0 > "$counter_file"

    # Succeeds on the 3rd call.
    _flaky() {
        local n
        n=$(cat "$counter_file")
        n=$((n + 1))
        echo "$n" > "$counter_file"
        [ "$n" -ge 3 ]
    }

    if emr_retry 5 0 _flaky 2>/dev/null; then
        [ "$(cat "$counter_file")" = "3" ] && echo "ok: succeeded on attempt 3"
    else
        echo "FAIL: flaky command should have succeeded within 5 attempts" >&2
        exit 1
    fi

    # Gives up with the command's exit status after <attempts>.
    echo 0 > "$counter_file"
    if emr_retry 2 0 false 2>/dev/null; then
        echo "FAIL: 'false' must not succeed" >&2
        exit 1
    else
        echo "ok: gave up after 2 attempts with exit $?"
    fi

    # A command that fails for good never runs more than <attempts> times.
    echo 0 > "$counter_file"
    _count() { local n; n=$(cat "$counter_file"); echo $((n + 1)) > "$counter_file"; return 1; }
    emr_retry 4 0 _count 2>/dev/null || true
    if [ "$(cat "$counter_file")" = "4" ]; then
        echo "ok: exactly 4 attempts made"
    else
        echo "FAIL: expected 4 attempts, got $(cat "$counter_file")" >&2
        exit 1
    fi

    rm -f "$counter_file"
    echo "retry.sh self-test passed"
fi
