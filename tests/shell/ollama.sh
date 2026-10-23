#!/usr/bin/env bash
# Loopback bind and the venv-only pull command.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=deploy/lib/ollama.sh
source "$root/deploy/lib/ollama.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }

dropin=$(lifedash_ollama_dropin)
lifedash_ollama_bind_ok "$dropin" || fail "drop-in must bind 127.0.0.1:11434"
printf '%s\n' "$dropin" | grep -q '0.0.0.0' && fail "drop-in must not publish 0.0.0.0"
lifedash_ollama_bind_ok 'Environment=OLLAMA_HOST=0.0.0.0:11434' && fail "a public bind must be rejected"

lifedash_ollama_model_ok 'llama3.2:3b' || fail "a tagged model name is valid"
lifedash_ollama_model_ok 'nomic-embed-text' || fail "an untagged model name is valid"
lifedash_ollama_model_ok '../evil' && fail "a path is not a model name"
lifedash_ollama_model_ok '' && fail "an empty model name is rejected"

args=$(lifedash_ollama_args ollama-pull 'qwen2.5:7b' /srv/lifedash/venv/bin/python)
printf '%s\n' "$args" | grep -q 'lifedash.ollama_models' || fail "pull uses the venv module"
printf '%s\n' "$args" | grep -qx 'pull' || fail "pull is the module command"
lifedash_ollama_args ollama-pull 'qwen2.5:7b' /usr/bin/python3 && fail "system python is rejected"
lifedash_ollama_args ollama-remove 'llama3.2:1b' /srv/lifedash/venv/bin/python >/dev/null || fail "remove is accepted"

if grep -R -n '11434' "$root/deploy/bin/lifedash-cloudflared" "$root/deploy/systemd/cloudflared.service" >/dev/null 2>&1; then
    fail "the tunnel must not mention the Ollama port"
fi

echo ok
