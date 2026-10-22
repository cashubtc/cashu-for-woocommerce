#!/usr/bin/env bash
# Run composer on the host when PHP is installed, otherwise in the wp-env cli
# container (needs `npm run wp-env:start` first).

set -euo pipefail

cd "$(dirname "$0")/.."

if command -v php >/dev/null; then
	exec composer "$@"
fi

exec npx wp-env run cli --env-cwd="wp-content/plugins/$(basename "$PWD")" -- composer "$@"
