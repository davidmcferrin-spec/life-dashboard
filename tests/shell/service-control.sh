#!/usr/bin/env bash
# cloudflared unit choice: an already-running tunnel is left alone.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=deploy/lib/deploy-guard.sh
source "$root/deploy/lib/deploy-guard.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }

# active, ours, token -> install|skip
[[ $(lifedash_cloudflared_action 1 0 0) == skip ]] || fail "a running foreign tunnel must be left alone"
[[ $(lifedash_cloudflared_action 1 0 1) == skip ]] || fail "a saved token must not replace a running foreign tunnel"
[[ $(lifedash_cloudflared_action 0 0 1) == install ]] || fail "a token with no running tunnel installs this app's unit"
[[ $(lifedash_cloudflared_action 1 1 0) == install ]] || fail "this app's running unit is refreshed"
[[ $(lifedash_cloudflared_action 0 1 0) == install ]] || fail "this app's installed unit is refreshed"
[[ $(lifedash_cloudflared_action 0 0 0) == skip ]] || fail "nothing to install when no token and no unit"

echo "ok"
