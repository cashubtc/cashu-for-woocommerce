#!/usr/bin/env bash
#
# Start a Cloudflare quick tunnel that exposes the wp-env dev site over HTTPS,
# point WP's siteurl/home at the tunnel URL while it's running, and revert
# on exit so the local site keeps working after Ctrl-C.
#
# Bring up:    npm run wp-env:tunnel
# Tear down:   Ctrl-C in this terminal
# Safety net:  npm run wp-env:tunnel-reset (resets siteurl/home if the script
#              died uncleanly and left WP pointing at a dead tunnel URL).

set -euo pipefail

LOCAL_URL="${CASHU_LOCAL_URL:-http://localhost:8888}"
LOG_FILE="$(mktemp -t cashu-tunnel.XXXXXX.log)"
TUNNEL_PID=""
TUNNEL_URL=""

cleanup() {
  set +e
  trap - EXIT INT TERM

  if [[ -n "$TUNNEL_URL" ]]; then
    echo
    echo "→ restoring WP_SITEURL/WP_HOME constants to ${LOCAL_URL}"
    npx wp-env run cli -- wp config set WP_SITEURL "${LOCAL_URL}" --type=constant >/dev/null 2>&1 || true
    npx wp-env run cli -- wp config set WP_HOME    "${LOCAL_URL}" --type=constant >/dev/null 2>&1 || true
  fi

  if [[ -n "$TUNNEL_PID" ]] && kill -0 "$TUNNEL_PID" 2>/dev/null; then
    echo "→ stopping cloudflared (pid $TUNNEL_PID)"
    kill "$TUNNEL_PID" 2>/dev/null || true
    wait "$TUNNEL_PID" 2>/dev/null || true
  fi

  rm -f "$LOG_FILE"
  echo "✓ tunnel down"
}
trap cleanup EXIT INT TERM

command -v cloudflared >/dev/null || {
  echo "cloudflared not found — run: brew install cloudflared" >&2
  exit 1
}

echo "→ checking wp-env is up at ${LOCAL_URL}"
if ! curl -fsS -o /dev/null --max-time 5 "${LOCAL_URL}"; then
  echo "wp-env does not appear to be running at ${LOCAL_URL}" >&2
  echo "start it first:  npm run wp-env:start" >&2
  exit 1
fi

echo "→ starting cloudflared quick tunnel"
cloudflared tunnel --no-autoupdate --url "${LOCAL_URL}" >"$LOG_FILE" 2>&1 &
TUNNEL_PID=$!

# Wait up to ~30s for the trycloudflare URL to appear in the log.
for _ in $(seq 1 60); do
  TUNNEL_URL="$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG_FILE" | head -1 || true)"
  [[ -n "$TUNNEL_URL" ]] && break
  if ! kill -0 "$TUNNEL_PID" 2>/dev/null; then
    echo "cloudflared exited before producing a URL — log follows:" >&2
    cat "$LOG_FILE" >&2
    exit 1
  fi
  sleep 0.5
done

if [[ -z "$TUNNEL_URL" ]]; then
  echo "timed out waiting for cloudflared tunnel URL" >&2
  cat "$LOG_FILE" >&2
  exit 1
fi

echo "→ tunnel URL: ${TUNNEL_URL}"
echo "→ pointing WP_SITEURL/WP_HOME constants at the tunnel"
# wp-env hardcodes WP_SITEURL / WP_HOME as constants in wp-config.php; those
# beat the DB siteurl/home options, so we have to edit the constants directly.
npx wp-env run cli -- wp config set WP_SITEURL "${TUNNEL_URL}" --type=constant >/dev/null
npx wp-env run cli -- wp config set WP_HOME    "${TUNNEL_URL}" --type=constant >/dev/null

cat <<EOF

✓ tunnel up
  Public URL:   ${TUNNEL_URL}
  WP_SITEURL:   ${TUNNEL_URL} (auto-reverted on exit)
  Tunnel log:   ${LOG_FILE}

  Open the storefront on your phone / cashu wallet at:
    ${TUNNEL_URL}

  Ctrl-C to stop. WP_SITEURL will be restored to ${LOCAL_URL}.

EOF

# Foreground the tunnel so Ctrl-C reaches us via the trap.
wait "$TUNNEL_PID"
