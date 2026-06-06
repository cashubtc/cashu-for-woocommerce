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

# Regenerate autoload without dev deps before zipping. The zip only bundles
# vendor/autoload.php + vendor/composer/, NOT the actual dev packages. Without
# this step Composer's autoload_files.php still references dev-only packages
# (mockery, phpunit) as files-to-load on bootstrap, and production sites fatal
# with "Failed opening required '.../mockery/.../helpers.php'". Restore the
# dev autoload on exit so the local working tree stays usable for tests.
restore_dev_autoload() { composer dump-autoload --no-scripts >/dev/null 2>&1 || true; }
trap restore_dev_autoload EXIT
composer dump-autoload --no-dev --classmap-authoritative --no-scripts

# Create plugin
rm -f "${pkg}"
echo "Creating zip file..."
zip -rq "${pkg}" assets languages src vendor/autoload.php vendor/composer cashu-for-woocommerce.php license.txt readme.txt -x="src/ts/*"
echo "Done"
