#!/usr/bin/env bash
#
# Safety net: reset WP_SITEURL / WP_HOME constants to the local wp-env URL.
# Use this if the tunnel script died uncleanly and left WP pointing at a dead
# trycloudflare.com URL — symptoms are the storefront 502/timeout and wp-admin
# redirecting to a stale tunnel domain.
#
# wp-env writes WP_SITEURL / WP_HOME as PHP constants in wp-config.php; those
# beat the DB siteurl/home options, so we have to edit the constants directly.

set -euo pipefail

LOCAL_URL="${CASHU_LOCAL_URL:-http://localhost:8888}"

echo "→ resetting WP_SITEURL/WP_HOME constants to ${LOCAL_URL}"
npx wp-env run cli -- wp config set WP_SITEURL "${LOCAL_URL}" --type=constant
npx wp-env run cli -- wp config set WP_HOME    "${LOCAL_URL}" --type=constant
echo "✓ done"
