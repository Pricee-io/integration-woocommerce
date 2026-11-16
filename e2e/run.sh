#!/usr/bin/env bash
# End-to-end tests of the plugin in a real, throwaway WordPress + WooCommerce:
# starts its own database and shop containers (never the dev ones), then
#
#   1. installs the last release (E2E_FROM_REF) and configures it like a
#      merchant, then upgrades to the current code (the working tree) and
#      checks the plugin is still active with its settings kept;
#   2. sends real signed HTTP calls to the webhook and checks the prices;
#   3. saves the settings and runs a sync from the admin in a browser
#      (Playwright), against a fake Pricee API running in the shop
#      container, and checks what it got;
#   4. deletes the plugin and checks nothing of it is left.
#
# Usage: e2e/run.sh [WordPress image tag] [WooCommerce version]
#   e.g. e2e/run.sh php8.2-apache 9.9.5
# Env: E2E_PORT (8083), E2E_FROM_REF, E2E_KEEP=1 to leave the containers
#      running for debugging.
set -euo pipefail

WP_TAG="${1:-php8.3-apache}"
WC_VERSION="${2:-}"
PORT="${E2E_PORT:-8083}"
FROM_REF="${E2E_FROM_REF:-1.8.0}"
# Same version as the playwright package in e2e/package.json
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v1.49.1-noble"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NAME="pricee-woo-e2e-${PORT}"
DB="${NAME}-db"
SHOP="${NAME}-shop"
SHOP_URL="http://localhost:${PORT}"
ADMIN_USER="e2e"
ADMIN_PASSWD="pricee_e2e_2026"
WORK="$(mktemp -d)"

step() { printf '\n== %s\n' "$*"; }
in_shop() { docker exec --user www-data "$SHOP" "$@"; }
# The shop's settings: wp-config.php reads them from the environment
SHOP_ENV=(-e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_NAME=wordpress -e WORDPRESS_DB_USER=wordpress -e WORDPRESS_DB_PASSWORD=wordpress
    -e WORDPRESS_CONFIG_EXTRA="define('PRICEE_API_BASE_URL', 'http://127.0.0.1:8090/api/v1/');")
# WP-CLI from its own image, on the shop's files and network, as the web server user
wp() { docker run --rm --volumes-from "$SHOP" --network "$NAME" --user 33:33 -e HOME=/tmp "${SHOP_ENV[@]}" wordpress:cli wp "$@"; }

cleanup() {
    if [ "${E2E_KEEP:-0}" = "1" ]; then
        echo "Containers kept: $SHOP (${SHOP_URL}/wp-admin/, ${ADMIN_USER} / ${ADMIN_PASSWD}) and $DB"
    else
        docker rm -f "$SHOP" "$DB" >/dev/null 2>&1 || true
        docker network rm "$NAME" >/dev/null 2>&1 || true
    fi
    rm -rf "$WORK"
}
trap cleanup EXIT

deploy_plugin() {
    docker exec "$SHOP" rm -rf /var/www/html/wp-content/plugins/pricee
    docker cp "$1/pricee" "$SHOP:/var/www/html/wp-content/plugins/pricee"
    docker exec "$SHOP" chown -R www-data:www-data /var/www/html/wp-content/plugins/pricee
}

step "Starting WordPress ${WP_TAG} on port ${PORT}"
docker rm -f "$SHOP" "$DB" >/dev/null 2>&1 || true
docker network rm "$NAME" >/dev/null 2>&1 || true
docker network create "$NAME" >/dev/null
docker run -d --name "$DB" --network "$NAME" \
    -e MARIADB_DATABASE=wordpress -e MARIADB_USER=wordpress -e MARIADB_PASSWORD=wordpress -e MARIADB_ROOT_PASSWORD=root \
    mariadb:11.4 >/dev/null
for i in $(seq 1 60); do
    docker exec "$DB" mariadb-admin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1 && break
    [ "$i" = 60 ] && { docker logs "$DB"; exit 1; }
    sleep 2
done

# The plugin reaches the fake Pricee API through PRICEE_API_BASE_URL
docker run -d --name "$SHOP" --network "$NAME" -p "127.0.0.1:${PORT}:80" "${SHOP_ENV[@]}" "wordpress:${WP_TAG}" >/dev/null
for i in $(seq 1 60); do
    docker exec "$SHOP" test -f /var/www/html/wp-config.php 2>/dev/null && break
    [ "$i" = 60 ] && { docker logs "$SHOP"; exit 1; }
    sleep 2
done
for i in $(seq 1 30); do
    wp core is-installed >/dev/null 2>&1 || wp core install --url="$SHOP_URL" --title="Pricee E2E" \
        --admin_user="$ADMIN_USER" --admin_password="$ADMIN_PASSWD" --admin_email=e2e@example.com --skip-email >/dev/null 2>&1 && break
    [ "$i" = 30 ] && { wp core is-installed; exit 1; }
    sleep 3
done
wp rewrite structure '/%postname%/' --hard >/dev/null
wp plugin install woocommerce ${WC_VERSION:+--version="$WC_VERSION"} --activate
wp option update woocommerce_currency EUR >/dev/null
wp option update woocommerce_calc_taxes no >/dev/null
echo "WordPress $(wp core version), WooCommerce $(wp plugin get woocommerce --field=version)"
docker exec "$SHOP" mkdir -p /tmp/e2e
docker cp "$ROOT/e2e/shop.php" "$SHOP:/tmp/e2e/shop.php"
docker cp "$ROOT/e2e/fake-api.php" "$SHOP:/tmp/e2e/fake-api.php"
docker exec "$SHOP" chown -R www-data:www-data /tmp/e2e

step "Upgrade from ${FROM_REF} to the current code"
mkdir -p "$WORK/old"
git -C "$ROOT" archive "$FROM_REF" pricee | tar -x -C "$WORK/old"
deploy_plugin "$WORK/old"
wp plugin activate pricee
in_shop php /tmp/e2e/shop.php configure
# WordPress updates a plugin by replacing its files: the current working
# tree, so uncommitted changes are tested too
deploy_plugin "$ROOT"
in_shop php /tmp/e2e/shop.php check-upgrade

step "Webhook over HTTP"
in_shop php /tmp/e2e/shop.php webhook

step "Settings and sync from the admin"
docker exec -d --user www-data -e PRICEE_E2E_WEBSITE_URL="$SHOP_URL" "$SHOP" php -S 127.0.0.1:8090 /tmp/e2e/fake-api.php
for i in $(seq 1 20); do
    docker exec "$SHOP" curl -s -o /dev/null http://127.0.0.1:8090/_state && break
    [ "$i" = 20 ] && { echo "Fake Pricee API did not start"; exit 1; }
    sleep 1
done
in_shop php /tmp/e2e/shop.php sync-fixtures

# In Playwright's image (its own Node and Chromium, nothing to install on the
# host), on the host network so that localhost:<port> is the shop
mkdir -p "$ROOT/e2e/output"
docker run --rm --network host --ipc=host --user "$(id -u):$(id -g)" -e HOME=/tmp \
    -v "$ROOT/e2e:/e2e:ro" -v "$ROOT/e2e/output:/output" \
    -e SHOP_URL="$SHOP_URL" -e ADMIN_USER="$ADMIN_USER" -e ADMIN_PASSWD="$ADMIN_PASSWD" \
    -e SYNC_CATEGORY="Pricee E2E" -e SYNC_PRODUCTS=55 \
    "$PLAYWRIGHT_IMAGE" \
    sh -c 'cp /e2e/package.json /e2e/package-lock.json /e2e/sync.mjs /tmp/ && cd /tmp && npm ci --silent --no-audit --no-fund && node sync.mjs'
in_shop php /tmp/e2e/shop.php check-sync

step "Deleting the plugin"
wp plugin deactivate pricee
wp plugin uninstall pricee
in_shop php /tmp/e2e/shop.php check-uninstall

step "All end-to-end tests passed on WordPress ${WP_TAG}"
