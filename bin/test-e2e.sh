#!/usr/bin/env bash
#
# Browser end-to-end test runner for EMR (Enable Media Replace) — wraps docker-compose.e2e.yml so
# a REAL served WordPress (with the plugin + test-support mu-plugins) and a
# Playwright runner come up with one command, identically on every machine
# and in CI. Companion to bin/test.sh (PHPUnit); the two stacks are fully
# independent (separate compose file, database, volumes, images).
#
# Usage:
#     bin/test-e2e.sh                          # provision (idempotent) + run EVERY project (chromium, firefox, webkit, visual)
#     bin/test-e2e.sh --grep "replace"         # only tests whose title matches
#     bin/test-e2e.sh specs/smoke.spec.ts      # one spec file (paths relative to tests/E2E)
#     bin/test-e2e.sh --project chromium       # one browser project: chromium | firefox | webkit | visual (repeatable)
#     bin/test-e2e.sh --project visual --update-snapshots   # refresh the screenshot baselines after an intended UI change
#     bin/test-e2e.sh --wp 6.5                 # against WordPress 6.5 instead of latest (fresh volumes!)
#     bin/test-e2e.sh --pull-only              # just pull the images (with retry) — CI's first step
#     bin/test-e2e.sh --provision-only         # bring the site up + seed it, run nothing
#     bin/test-e2e.sh --headed                 # run natively on the host with a visible browser
#     bin/test-e2e.sh --ui                     # Playwright UI mode, natively on the host
#     bin/test-e2e.sh --report                 # open the last HTML report (host)
#     bin/test-e2e.sh --shell                  # shell inside the Playwright container
#     bin/test-e2e.sh --wp-shell               # WP-CLI shell inside the wpcli container
#     bin/test-e2e.sh --logs                   # tail the WordPress container logs
#     bin/test-e2e.sh --down                   # stop the stack (keeps volumes)
#     bin/test-e2e.sh --clean                  # stop + wipe volumes (DB, WP core, node_modules)
#
# Any other argument is passed straight to `playwright test`.
#
# The site stays up after a run at http://localhost:8031 (admin / password)
# so you can poke at the exact state a test left behind. SPIO's own E2E
# stack uses 8030 and the compose project spio-e2e; the two never share a
# container, a volume or a port. Only one test stack runs at a time on a
# machine all the same (see TESTING.md, "One stack at a time").
#
# Prerequisite: Docker Desktop / Docker Engine + compose plugin. For
# --headed / --ui only: Node.js on the host (npm install runs on demand).
#
# First run: ~2-4 min (image pulls + npm install into a volume). Later
# runs: seconds of provisioning + the tests themselves.

set -euo pipefail

cd "$(dirname "$0")/.."

# Docker Desktop on macOS doesn't always put its CLI on PATH for non-login
# shells; fall back to the app bundle so the script "just works".
if ! command -v docker >/dev/null 2>&1 && [ -x /Applications/Docker.app/Contents/Resources/bin/docker ]; then
    export PATH="/Applications/Docker.app/Contents/Resources/bin:$PATH"
fi

COMPOSE=(docker compose -f docker-compose.e2e.yml)
E2E_DIR="tests/E2E"

# emr_retry <attempts> <delay> <cmd...>: exponential-backoff retry helper.
# shellcheck source=bin/lib/retry.sh
. "bin/lib/retry.sh"

# Pull every image of the stack up front, with retries. Registry pulls from
# CI runners sporadically fail mid-handshake ("connection reset by peer"
# fetching the Docker Hub auth token);
# `compose up` would abort on that, while a retried `pull` shrugs it off.
# 5 attempts × doubling delay from 10s = up to ~2.5 min of patience.
pull_images() {
    echo "==> Pulling images (with retry)..."
    # --quiet on CI keeps the log readable; locally the progress bars are useful.
    emr_retry 5 10 "${COMPOSE[@]}" pull ${CI:+--quiet}
}
SITE_URL="http://localhost:8031"
PROVISION_SCRIPT="wp-content/plugins/enable-media-replace/tests/E2E/provision/provision.sh"

# --wp <version>: switch the wordpress image tag. Different core versions
# must not share the e2e-wp-core volume, so this implies --clean first.
export E2E_WP_TAG="${E2E_WP_TAG:-php8.3-apache}"

# The Playwright runner image must carry the browsers of the exact
# @playwright/test version the suite installs, so its tag is read from
# tests/E2E/package.json instead of being pinned in two places: a version
# bump (by hand or by Dependabot) is a single edit.
EMR_PLAYWRIGHT_VERSION=$(grep -o '"@playwright/test": *"[^"]*"' "$E2E_DIR/package.json" | cut -d'"' -f4)
if ! printf '%s' "$EMR_PLAYWRIGHT_VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    echo "!!! $E2E_DIR/package.json must pin @playwright/test to an exact version (found \"$EMR_PLAYWRIGHT_VERSION\"): the Playwright image tag is derived from it."
    exit 1
fi
export EMR_PLAYWRIGHT_VERSION

MODE="run"
PW_ARGS=()
while [ $# -gt 0 ]; do
    case "$1" in
        --clean)          MODE="clean"; shift ;;
        --down)           MODE="down"; shift ;;
        --pull-only)      MODE="pull"; shift ;;
        --provision-only) MODE="provision"; shift ;;
        --shell)          MODE="shell"; shift ;;
        --wp-shell)       MODE="wp-shell"; shift ;;
        --logs)           MODE="logs"; shift ;;
        --report)         MODE="report"; shift ;;
        --headed)         MODE="native"; PW_ARGS+=("--headed"); shift ;;
        --ui)             MODE="native"; PW_ARGS+=("--ui"); shift ;;
        --wp)
            export E2E_WP_TAG="$2-php8.3-apache"
            WP_SWITCH=1
            shift 2
            ;;
        --wp=*)
            export E2E_WP_TAG="${1#--wp=}-php8.3-apache"
            WP_SWITCH=1
            shift
            ;;
        *)
            PW_ARGS+=("$1")
            shift
            ;;
    esac
done

case "$MODE" in
    clean)
        echo "==> Stopping the E2E stack and wiping its volumes (DB, WP core, node_modules)..."
        "${COMPOSE[@]}" down -v --remove-orphans
        echo "==> Done. Next run re-pulls nothing but re-provisions from scratch."
        exit 0
        ;;
    down)
        "${COMPOSE[@]}" down --remove-orphans
        exit 0
        ;;
    pull)
        pull_images
        exit 0
        ;;
    logs)
        exec "${COMPOSE[@]}" logs -f wordpress
        ;;
    report)
        if ! command -v npx >/dev/null 2>&1; then
            echo "!!! --report needs Node.js on the host; the report is at $E2E_DIR/artifacts/html-report/index.html"
            exit 1
        fi
        cd "$E2E_DIR" && exec npx playwright show-report artifacts/html-report
        ;;
esac

if [ "${WP_SWITCH:-0}" = "1" ]; then
    echo "==> WordPress version switch requested ($E2E_WP_TAG): recreating the stack with fresh volumes..."
    "${COMPOSE[@]}" down -v --remove-orphans
fi

# --- Images ---------------------------------------------------------------
pull_images

# --- Keep the WordPress core volume in step with the image ------------------
# The official image copies WordPress into the wp-core volume only when that
# volume is EMPTY, so a pulled core update never reaches an existing local
# stack: it keeps serving the old core while CI provisions the new one, and
# the admin CSS of the two versions lays text out differently, failing every
# visual baseline. Compare the two and start the site over when they
# differ (node_modules are kept).
image_wp=$(docker run --rm --entrypoint sh "wordpress:$E2E_WP_TAG" -c "grep -o \"wp_version = '[^']*'\" /usr/src/wordpress/wp-includes/version.php" 2>/dev/null | cut -d"'" -f2)
volume_wp=""
if docker volume inspect emr-e2e_e2e-wp-core >/dev/null 2>&1; then
    volume_wp=$(docker run --rm -v emr-e2e_e2e-wp-core:/wp:ro --entrypoint sh "wordpress:$E2E_WP_TAG" -c "grep -o \"wp_version = '[^']*'\" /wp/wp-includes/version.php" 2>/dev/null | cut -d"'" -f2)
fi
if [ -n "$image_wp" ] && [ -n "$volume_wp" ] && [ "$image_wp" != "$volume_wp" ]; then
    echo "==> WordPress $volume_wp in the local volume, $image_wp in the image: re-provisioning the site (DB + core) so it matches CI..."
    "${COMPOSE[@]}" down --remove-orphans
    docker volume rm emr-e2e_e2e-wp-core emr-e2e_e2e-mysql
fi

# --- Bring the site up -------------------------------------------------
echo "==> Starting MySQL + WordPress ($E2E_WP_TAG)..."
"${COMPOSE[@]}" up -d --wait wordpress

# --- Provision (idempotent) -------------------------------------------
echo "==> Provisioning the WordPress install..."
"${COMPOSE[@]}" run --rm wpcli sh "$PROVISION_SCRIPT"

case "$MODE" in
    provision)
        echo "==> Site ready at $SITE_URL (admin / password)."
        exit 0
        ;;
    wp-shell)
        exec "${COMPOSE[@]}" run --rm wpcli bash
        ;;
    shell)
        exec "${COMPOSE[@]}" run --rm playwright bash
        ;;
    native)
        if ! command -v npx >/dev/null 2>&1; then
            echo "!!! --headed / --ui run Playwright natively and need Node.js on the host (brew install node)."
            exit 1
        fi
        cd "$E2E_DIR"
        # Same lockfile check as the Docker run below; a reinstall also
        # fetches the browsers that match the new @playwright/test.
        if [ ! -d node_modules/@playwright/test ] || ! cmp -s package-lock.json node_modules/.emr-installed-lock.json; then
            echo "==> Installing Playwright on the host (first native run, or package-lock.json changed)..."
            npm ci --no-audit --no-fund
            npx playwright install chromium firefox webkit
            cp package-lock.json node_modules/.emr-installed-lock.json
        fi
        # Screenshot assertions are no-ops here (E2E_IN_DOCKER unset): host
        # fonts/rendering differ from the Linux image the baselines come from.
        echo "==> Running Playwright natively against $SITE_URL"
        # ${arr[@]+"${arr[@]}"} — safe expansion of a possibly-empty array
        # under `set -u` on macOS's bash 3.2.
        E2E_BASE_URL="$SITE_URL" exec npx playwright test ${PW_ARGS[@]+"${PW_ARGS[@]}"}
        ;;
esac

# --- Run the suite in the Playwright container ---------------------------
echo "==> Running Playwright in Docker..."
STATUS=0
"${COMPOSE[@]}" run --rm playwright sh -c '
    set -e
    # Reinstall whenever package-lock.json differs from the copy saved at the
    # last install: the volume outlives version bumps, and an old
    # @playwright/test inside a newer image looks for browsers it lacks.
    if [ ! -d node_modules/@playwright/test ] || ! cmp -s package-lock.json node_modules/.emr-installed-lock.json; then
        echo "==> Installing npm dependencies into the e2e-node-modules volume (first run, or package-lock.json changed)..."
        npm ci --no-audit --no-fund
        cp package-lock.json node_modules/.emr-installed-lock.json
    fi
    npx playwright test "$@"
' sh ${PW_ARGS[@]+"${PW_ARGS[@]}"} || STATUS=$?

echo ""
echo "==> Report: $E2E_DIR/artifacts/html-report/index.html   (bin/test-e2e.sh --report)"
echo "==> Site still up at $SITE_URL (admin / password) — bin/test-e2e.sh --down to stop it."
exit $STATUS
