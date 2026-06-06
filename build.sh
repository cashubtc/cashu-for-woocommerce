#!/bin/bash
set -euo pipefail

pkg="cashu-for-woocommerce.zip" # plugin name

# Build packages
composer install
npm ci --include=dev
npm run build

# Compile .po -> .mo using gettext (no wp-env needed)
if compgen -G "languages/*.po" > /dev/null; then
  if command -v msgfmt >/dev/null 2>&1; then
    for po in languages/*.po; do
      msgfmt "$po" -o "${po%.po}.mo"
    done
  else
    echo "Warning: msgfmt (gettext) not found, skipping .mo generation."
    echo "Install gettext locally (brew install gettext) or rely on CI."
  fi
fi

# Scrub macOS .DS_Store files from the source tree before zipping. The plugin
# has no production composer deps so vendor/ is intentionally NOT bundled —
# bootstrap PSR-4 autoload in cashu-for-woocommerce.php covers Cashu\WC\* →
# src/. Skipping vendor/ also avoids wp.org's "missing_composer_json_file"
# warning that fires when vendor/ ships without composer.json beside it.
find assets languages src -name '.DS_Store' -delete 2>/dev/null || true

# Create plugin
rm -f "${pkg}"
echo "Creating zip file..."
zip -rq "${pkg}" assets languages src cashu-for-woocommerce.php uninstall.php license.txt readme.txt -x="src/ts/*"
echo "Done"
