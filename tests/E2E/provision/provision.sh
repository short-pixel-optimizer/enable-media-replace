#!/bin/sh
#
# Provision the E2E WordPress install. Runs INSIDE the `wpcli` container of
# docker-compose.e2e.yml (as www-data, working dir /var/www/html) — never on
# the host. Idempotent: safe to run before every test session.
#
# Steps: wait for the DB → core install (first run only) → site URL, no
# search-engine pings, pretty permalinks → default theme → activate EMR
# (it creates no tables; deactivation only clears its notices) → apply the
# baseline seed (tests/E2E/provision/seed.php).

set -eu

PLUGIN_SLUG="enable-media-replace"
PLUGIN_DIR="wp-content/plugins/$PLUGIN_SLUG"
SITE_URL="${E2E_URL:-http://localhost:8031}"

cd /var/www/html

if [ ! -f wp-config.php ]; then
    echo "!!! wp-config.php missing — start the wordpress service first (it generates the file on first boot)."
    exit 1
fi

echo "==> Waiting for the database..."
# Probe with PHP's mysqli rather than `wp db check`: the cli image ships
# MariaDB's client, which rejects MySQL 8's self-signed TLS cert (the same
# problem .docker/Dockerfile.tests works around with a --skip-ssl shim).
db_ready() {
    php -r 'exit(@mysqli_connect(getenv("WORDPRESS_DB_HOST"), getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME")) ? 0 : 1);'
}
i=0
until db_ready >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 60 ]; then
        echo "!!! Database not reachable after 120s"
        exit 1
    fi
    sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
    echo "==> Installing WordPress core..."
    wp core install \
        --url="$SITE_URL" \
        --title="EMR E2E" \
        --admin_user=admin \
        --admin_password=password \
        --admin_email=admin@example.com \
        --skip-email
else
    echo "==> WordPress already installed."
fi

echo "==> Site options..."
wp option update siteurl "$SITE_URL" >/dev/null
wp option update home "$SITE_URL" >/dev/null
wp option update blog_public 0 >/dev/null
wp option update timezone_string "Europe/Bucharest" >/dev/null
wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || wp rewrite structure '/%postname%/' >/dev/null

echo "==> Theme..."
# A bundled default theme; whichever this core version ships.
for theme in twentytwentyfive twentytwentyfour twentytwentythree; do
    if wp theme is-installed "$theme" >/dev/null 2>&1; then
        wp theme activate "$theme" >/dev/null 2>&1 || true
        break
    fi
done

echo "==> Activating $PLUGIN_SLUG..."
if ! wp plugin is-active "$PLUGIN_SLUG" >/dev/null 2>&1; then
    wp plugin activate "$PLUGIN_SLUG"
fi

echo "==> Applying the baseline seed..."
wp eval-file "$PLUGIN_DIR/tests/E2E/provision/seed.php"

echo "==> Provisioned: $SITE_URL (admin / password)"
