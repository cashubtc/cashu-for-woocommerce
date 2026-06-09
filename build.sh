#!/bin/bash
set -euo pipefail

pkg="cashu-for-woocommerce.zip" # plugin name

# Build packages
composer install
npm ci --include=dev
npm run build

# Scrub macOS .DS_Store files from the source tree before zipping. The plugin
# has no production composer deps so vendor/ is intentionally NOT bundled —
# bootstrap PSR-4 autoload in cashu-for-woocommerce.php covers Cashu\WC\* →
# src/. Skipping vendor/ also avoids wp.org's "missing_composer_json_file"
# warning that fires when vendor/ ships without composer.json beside it.
find assets src -name '.DS_Store' -delete 2>/dev/null || true

# Create plugin
rm -f "${pkg}"
echo "Creating zip file..."
zip -rq "${pkg}" assets src cashu-for-woocommerce.php uninstall.php license.txt readme.txt -x="src/ts/*"
echo "Done"
