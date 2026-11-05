#!/usr/bin/env bash
# Hermes and OpenCode stay on loopback Ollama, with a 64k coding model.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=deploy/lib/local-agents.sh
source "$root/deploy/lib/local-agents.sh"
# shellcheck source=deploy/lib/ollama.sh
source "$root/deploy/lib/ollama.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }

model=$(lifedash_agent_model)
base=$(lifedash_agent_base_model)
context=$(lifedash_agent_context)
[[ $model == lifedash-coder ]] || fail "coding model alias"
[[ $base == qwen2.5-coder:7b ]] || fail "base model"
[[ $context -ge 64000 ]] || fail "context must be at least 64k"

modelfile=$(lifedash_agent_modelfile)
printf '%s\n' "$modelfile" | grep -qx "FROM $base" || fail "modelfile must start from the base model"
printf '%s\n' "$modelfile" | grep -qx "PARAMETER num_ctx $context" || fail "modelfile must pin num_ctx"

large='{"chat_model": "qwen2.5:7b", "vram_mib": 8192}'
small='{"chat_model": "llama3.2:3b", "vram_mib": 4096}'
lifedash_agent_should_pull "$large" || fail "the 7B tier should pull"
lifedash_agent_should_pull "$small" && fail "a smaller plan must not pull"
lifedash_agent_should_pull '' && fail "an empty plan must not pull"

hermes=$(lifedash_hermes_config)
printf '%s\n' "$hermes" | grep -q 'provider: custom' || fail "hermes provider"
printf '%s\n' "$hermes" | grep -q 'base_url: http://127.0.0.1:11434/v1' || fail "hermes ollama url"
printf '%s\n' "$hermes" | grep -q "default: $model" || fail "hermes model"
printf '%s\n' "$hermes" | grep -q "context_length: $context" || fail "hermes context"
printf '%s\n' "$hermes" | grep -q "ollama_num_ctx: $context" || fail "hermes num_ctx"
printf '%s\n' "$hermes" | grep -q 'host: 127.0.0.1' || fail "gateway must bind loopback"
printf '%s\n' "$hermes" | grep -q '0.0.0.0' && fail "hermes config must not publish 0.0.0.0"
printf '%s\n' "$hermes" | grep -q 'OLLAMA_CONTEXT_LENGTH' && fail "do not set a server-wide context"

opencode=$(lifedash_opencode_config)
printf '%s\n' "$opencode" | python3 -m json.tool >/dev/null || fail "opencode config must be json"
printf '%s\n' "$opencode" | grep -q 'http://127.0.0.1:11434/v1' || fail "opencode ollama url"
printf '%s\n' "$opencode" | grep -q "\"$model\"" || fail "opencode model"
printf '%s\n' "$opencode" | grep -q "\"context\": $context" || fail "opencode context"
printf '%s\n' "$opencode" | grep -q "ollama/$model" || fail "opencode default model"
printf '%s\n' "$opencode" | grep -q '0.0.0.0' && fail "opencode config must not publish 0.0.0.0"

openclaw=$(lifedash_openclaw_config)
printf '%s\n' "$openclaw" | python3 -m json.tool >/dev/null || fail "openclaw config must be json"
printf '%s\n' "$openclaw" | grep -q 'http://127.0.0.1:11434"' || fail "openclaw uses the native ollama url"
printf '%s\n' "$openclaw" | grep -q '/v1' && fail "openclaw must not use the OpenAI-compatible url"
printf '%s\n' "$openclaw" | grep -q "\"$model\"" || fail "openclaw model"
printf '%s\n' "$openclaw" | grep -q "ollama/$model" || fail "openclaw default model"
printf '%s\n' "$openclaw" | grep -q "\"contextTokens\": $context" || fail "openclaw context"
printf '%s\n' "$openclaw" | grep -q '"bind": "loopback"' || fail "openclaw gateway must be loopback"
printf '%s\n' "$openclaw" | grep -q '0.0.0.0' && fail "openclaw config must not publish 0.0.0.0"
printf '%s\n' "$openclaw" | grep -q 'install-daemon' && fail "openclaw config must not install a daemon"

key=$(openssl rand -hex 32)
envfile=$(mktemp)
printf 'API_SERVER_KEY=%s\n' "$key" > "$envfile"
got=$(lifedash_hermes_existing_key "$envfile") || fail "existing key should be kept"
[[ $got == "$key" ]] || fail "key round trip"
lifedash_hermes_existing_key /no/such/hermes.env && fail "a missing env file has no key"
printf 'API_SERVER_KEY=short\n' > "$envfile"
lifedash_hermes_existing_key "$envfile" && fail "a short key is rejected"
rm -f "$envfile"
lifedash_hermes_key_ok "$key" || fail "generated key shape"
lifedash_hermes_env "$key" | grep -q "API_SERVER_KEY=$key" || fail "env must carry the key"
lifedash_hermes_env "$key" | grep -q 'API_SERVER_HOST=127.0.0.1' || fail "env host"
lifedash_hermes_env 'nope' && fail "env must reject a bad key"

dropin=$(lifedash_ollama_dropin)
printf '%s\n' "$dropin" | grep -q 'OLLAMA_HOST=127.0.0.1:11434' || fail "ollama drop-in stays on loopback"
printf '%s\n' "$dropin" | grep -q 'OLLAMA_CONTEXT_LENGTH' && fail "ollama drop-in must not set a global context"

unit=$root/deploy/systemd/lifedash-hermes-gateway.service
grep -q 'API_SERVER_HOST=127.0.0.1' "$unit" || fail "gateway unit must bind loopback"
grep -q '0.0.0.0' "$unit" && fail "gateway unit must not publish 0.0.0.0"
grep -q 'User=lifedash' "$unit" || fail "gateway runs as lifedash"

grep -q 'lifedash-local-agents-ensure' "$root/setup.sh" || fail "setup.sh must install the agents"
grep -q 'lifedash-local-agents-ensure' "$root/deploy/provision.sh" || fail "provision must install the agents"
grep -q 'lifedash-local-agents-ensure' "$root/deploy/bin/lifedash-deploy" || fail "deploy must install the agents"
grep -q 'lifedash-ollama-ensure' "$root/setup.sh" || fail "setup.sh must still install ollama"

pool=$root/deploy/php/lifedash-pool.conf
basedir=$(sed -n 's/^php_admin_value\[open_basedir\][[:space:]]*=[[:space:]]*//p' "$pool")
case ":${basedir}:" in
    *:/var/lib/lifedash/hermes:*) ;;
    *) fail "open_basedir must allow the hermes home" ;;
esac
case ":${basedir}:" in
    *:/var/lib/lifedash/opencode:*) ;;
    *) fail "open_basedir must allow the opencode home" ;;
esac
case ":${basedir}:" in
    *:/var/lib/lifedash/openclaw:*) ;;
    *) fail "open_basedir must allow the openclaw home" ;;
esac
case ":${basedir}:" in
    *:/etc/lifedash:*) ;;
    *) fail "open_basedir must allow the agent config dir" ;;
esac
grep -q 'install-cli.sh' "$root/deploy/lib/local-agents.sh" || fail "ensure must install openclaw"
grep -q -- '--no-onboard' "$root/deploy/lib/local-agents.sh" || fail "openclaw install skips onboarding"
if grep -E '(^|[[:space:]])--onboard([[:space:]]|$)' "$root/deploy/lib/local-agents.sh"; then
    fail "do not pass --onboard"
fi
grep -q 'install-daemon' "$root/deploy/lib/local-agents.sh" && fail "do not install the openclaw daemon"
[[ -f $root/deploy/bin/lifedash-openclaw ]] || fail "missing openclaw wrapper"
grep -q 'OPENCLAW_HOME=/var/lib/lifedash/openclaw' "$root/deploy/bin/lifedash-openclaw" || fail "wrapper pins the openclaw home"

page=$root/templates/hermes.php
grep -q 'lifedash-coder' "$page" || fail "the chat page names the model"
grep -q '65536' "$page" || fail "the chat page names the context"
for tunnel in "$root/deploy/bin/lifedash-cloudflared" "$root/deploy/systemd/cloudflared.service"; do
    [[ -f "$tunnel" ]] || fail "missing $tunnel"
    if grep -q '8642' "$tunnel"; then
        fail "the tunnel must not mention the Hermes port"
    fi
done

echo ok
