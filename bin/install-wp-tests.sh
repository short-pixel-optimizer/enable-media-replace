#!/usr/bin/env bash
#
# Installs the WordPress unit testing framework.
#
# Usage: bin/install-wp-tests.sh <db_name> <db_user> <db_pass> <db_host> [wp_version]
#
# Example: bin/install-wp-tests.sh wordpress_test root "" 127.0.0.1 latest

set -ex

if [ $# -lt 4 ]; then
  echo "Usage: $0 <db_name> <db_user> <db_pass> <db_host> [wp_version]"
  exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=$4
WP_VERSION=${5:-latest}

# WordPress directories (should match your workflow environment vars)
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"

# Remove any previous installations
rm -rf "$WP_CORE_DIR" "$WP_TESTS_DIR"
mkdir -p "$WP_CORE_DIR"

# Download WordPress. For "latest" the actual version number is extracted
# from the download output because the SVN test-framework checkout below
# needs an explicit tag. For a pinned version the requested version IS the
# tag (WordPress tags X.0 releases as "X.0", so pass "6.0", not "6.0.0").
if [ "$WP_VERSION" == "latest" ]; then
  output=$(wp core download --version=latest --path="$WP_CORE_DIR" --force)
  TESTS_WP_VERSION=$(echo "$output" | grep -oP 'Downloading WordPress \K[0-9]+\.[0-9]+(\.[0-9]+)?')
else
  wp core download --version="$WP_VERSION" --path="$WP_CORE_DIR" --force
  TESTS_WP_VERSION="$WP_VERSION"
fi
echo "WP version to use for the test framework: $TESTS_WP_VERSION"

# Create a wp-config file for the test environment
cd "$WP_CORE_DIR"
wp config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --skip-check

# Create database if it does not exist (using MySQL client – ensure mysql client is available)
if ! mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "use $DB_NAME"; then
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e "CREATE DATABASE $DB_NAME;"
fi

# Install WordPress
wp core install --url=example.dev --title="Test Site" --admin_user=admin --admin_password=admin --admin_email=admin@example.com

# Download the testing framework
mkdir -p "$WP_TESTS_DIR"
svn checkout https://develop.svn.wordpress.org/tags/$TESTS_WP_VERSION/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
svn export https://develop.svn.wordpress.org/tags/$TESTS_WP_VERSION/wp-tests-config-sample.php "$WP_TESTS_DIR/includes/wp-tests-config-sample.php"

# Copy the wp-tests-config.php file template from the downloaded includes
cp "$WP_TESTS_DIR/includes/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

# Configure the testing file with database details and the location of the WordPress core
sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/localhost/$DB_HOST/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s|define( 'ABSPATH', dirname( __FILE__ ) . '/src/' );|define('ABSPATH', '${WP_CORE_DIR}/');|" "$WP_TESTS_DIR/wp-tests-config.php"

echo "WordPress test environment installed."
