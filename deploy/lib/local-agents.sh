# Hermes Agent and OpenCode on the existing loopback Ollama. Sourced, not executed.
# shellcheck shell=bash
#
# Model: qwen2.5-coder:7b, published as lifedash-coder with num_ctx 65536.
# Qwen2.5-Coder is tool-capable and its native window is 128k, so 64k fits.
# The Ollama default tag is about 4.7 GiB of Q4 weights. A 64k cache for this
# 7B is about 4 GiB more. 12 GiB of VRAM keeps both on the GPU. 8 GiB of VRAM,
# or 16 GiB of RAM with no GPU, is the same bar this host already uses for
# qwen2.5:7b; the 64k cache then spills some layers into system RAM.
# gemma4:31b and qwen2.5-coder:32b need about 24 GiB for the weights and
# closer to 40 GiB to hold a 64k dense cache on the GPU, so they are not
# the default here.
#
# Context is pinned on lifedash-coder only. Do not set OLLAMA_CONTEXT_LENGTH
# on the Ollama service: that would also enlarge the dashboard chat and
# embedding models. Hermes sends ollama_num_ctx as well; OpenCode uses the
# OpenAI-compatible endpoint, which honors the Modelfile num_ctx.

lifedash_agent_base_model() { printf '%s\n' 'qwen2.5-coder:7b'; }
lifedash_agent_model() { printf '%s\n' 'lifedash-coder'; }
lifedash_agent_context() { printf '%s\n' '65536'; }

lifedash_agent_modelfile() {
    printf 'FROM %s\nPARAMETER num_ctx %s\n' "$(lifedash_agent_base_model)" "$(lifedash_agent_context)"
}

# Pull only when the dashboard hardware plan already chose its large chat
# model (8 GiB VRAM, or 16 GiB RAM on CPU). A smaller box keeps the config
# and skips the download.
lifedash_agent_should_pull() {
    local plan=$1
    [[ $plan == *'"chat_model": "qwen2.5:7b"'* || $plan == *'"chat_model":"qwen2.5:7b"'* ]]
}

lifedash_hermes_config() {
    cat <<EOF
# lifedash-managed-model
# Rewritten by lifedash-local-agents-ensure. The API key stays in .env.
model:
  default: $(lifedash_agent_model)
  provider: custom
  base_url: http://127.0.0.1:11434/v1
  context_length: $(lifedash_agent_context)
  ollama_num_ctx: $(lifedash_agent_context)
gateway:
  api_server:
    enabled: true
    host: 127.0.0.1
    port: 8642
EOF
}

lifedash_hermes_key_ok() {
    [[ $1 =~ ^[A-Za-z0-9]{32,128}$ ]]
}

# Print an existing API_SERVER_KEY, or fail when the file has none.
lifedash_hermes_existing_key() {
    local file=$1 line key
    [[ -f $file ]] || return 1
    while IFS= read -r line || [[ -n $line ]]; do
        case $line in
            API_SERVER_KEY=*)
                key=${line#API_SERVER_KEY=}
                lifedash_hermes_key_ok "$key" || return 1
                printf '%s\n' "$key"
                return 0
                ;;
        esac
    done < "$file"
    return 1
}

lifedash_hermes_env() {
    local key=$1
    lifedash_hermes_key_ok "$key" || return 1
    printf 'API_SERVER_ENABLED=true\nAPI_SERVER_HOST=127.0.0.1\nAPI_SERVER_PORT=8642\nAPI_SERVER_KEY=%s\n' "$key"
}

lifedash_opencode_config() {
    local model context
    model=$(lifedash_agent_model)
    context=$(lifedash_agent_context)
    cat <<EOF
{
  "\$schema": "https://opencode.ai/config.json",
  "provider": {
    "ollama": {
      "npm": "@ai-sdk/openai-compatible",
      "name": "Ollama",
      "options": {
        "baseURL": "http://127.0.0.1:11434/v1"
      },
      "models": {
        "$model": {
          "name": "Life-Dashboard coder",
          "limit": {
            "context": $context,
            "output": 8192
          }
        }
      }
    }
  },
  "model": "ollama/$model"
}
EOF
}

# Install both CLIs, point them at loopback Ollama, and start the gateway.
# A failed upstream installer does not stop deploy. Ollama itself is untouched.
lifedash_local_agents_ensure() {
    local app=${1:-/srv/lifedash/app}
    local hermes_home=/var/lib/lifedash/hermes
    local opencode_home=/var/lib/lifedash/opencode
    local hermes_bin=$hermes_home/.local/bin/hermes
    local opencode_bin=$opencode_home/.opencode/bin/opencode
    local envfile=$hermes_home/.env
    local key python plan
    [[ $EUID -eq 0 ]] || {
        echo "local agents: run as root" >&2
        return 1
    }
    if ! id lifedash >/dev/null 2>&1; then
        echo "local agents: the lifedash user is missing, so Hermes was not installed" >&2
        return 0
    fi
    install -d -o lifedash -g lifedash -m 0750 "$hermes_home"
    install -d -o lifedash -g lifedash -m 0755 "$opencode_home"
    install -d -m 0755 /etc/lifedash
    if ! key=$(lifedash_hermes_existing_key "$envfile"); then
        key=$(openssl rand -hex 32)
    fi
    lifedash_hermes_config > "$hermes_home/config.yaml"
    lifedash_hermes_env "$key" > "$envfile"
    lifedash_opencode_config > /etc/lifedash/opencode.json
    chmod 0640 "$hermes_home/config.yaml"
    chmod 0600 "$envfile"
    chmod 0644 /etc/lifedash/opencode.json
    chown lifedash:lifedash "$hermes_home/config.yaml" "$envfile"
    unset key

    if [[ ! -x $hermes_bin ]]; then
        if ! command -v curl >/dev/null 2>&1; then
            echo "hermes: curl is not installed, so the installer did not run"
        else
            echo "hermes: installing from https://hermes-agent.nousresearch.com/install.sh"
            if ! curl -fsSL https://hermes-agent.nousresearch.com/install.sh \
                | env HOME="$hermes_home" HERMES_HOME="$hermes_home" DEBIAN_FRONTEND=noninteractive \
                    bash -s -- --non-interactive --skip-browser --skip-computer-use; then
                echo "hermes: the installer failed"
            fi
        fi
    fi
    if [[ ! -x $opencode_bin ]]; then
        if ! command -v curl >/dev/null 2>&1; then
            echo "opencode: curl is not installed, so the installer did not run"
        else
            echo "opencode: installing from https://opencode.ai/install"
            if ! curl -fsSL https://opencode.ai/install \
                | env HOME="$opencode_home" DEBIAN_FRONTEND=noninteractive \
                    bash -s -- --no-modify-path; then
                echo "opencode: the installer failed"
            fi
        fi
    fi

    # The installer keeps an existing config.yaml. Write again so a re-run
    # still pins the Ollama endpoint after the installer has created the tree.
    lifedash_hermes_config > "$hermes_home/config.yaml"
    chmod 0640 "$hermes_home/config.yaml"
    chown -R lifedash:lifedash "$hermes_home" "$opencode_home"
    chmod 0750 "$hermes_home"
    chmod 0600 "$envfile" 2>/dev/null || true
    chmod 0755 "$opencode_home"

    install -m 0755 "$app/deploy/bin/lifedash-hermes" /usr/local/bin/hermes
    install -m 0755 "$app/deploy/bin/lifedash-opencode" /usr/local/bin/opencode
    install -m 0644 "$app/deploy/systemd/lifedash-hermes-gateway.service" /etc/systemd/system/lifedash-hermes-gateway.service
    install -m 0644 "$app/deploy/systemd/lifedash-agent-model.service" /etc/systemd/system/lifedash-agent-model.service
    install -m 0644 "$app/deploy/systemd/lifedash-agent-model.timer" /etc/systemd/system/lifedash-agent-model.timer
    systemctl daemon-reload
    if [[ -x $hermes_bin ]]; then
        if systemctl enable --now lifedash-hermes-gateway.service; then
            systemctl try-restart lifedash-hermes-gateway.service || true
            echo "hermes: gateway on 127.0.0.1:8642"
        else
            echo "hermes: gateway did not start — systemctl status lifedash-hermes-gateway"
        fi
    else
        echo "hermes: gateway not started (the hermes command is missing)"
    fi
    if [[ -x $opencode_bin ]]; then
        echo "opencode: installed ($(basename "$opencode_bin") on PATH via /usr/local/bin/opencode)"
    else
        echo "opencode: the command is missing"
    fi
    systemctl enable lifedash-agent-model.timer >/dev/null
    systemctl start --no-block lifedash-agent-model.timer >/dev/null || true
    systemctl start --no-block lifedash-agent-model.service || true
    python=/srv/lifedash/venv/bin/python
    if [[ -x $python ]]; then
        plan=$(PYTHONPATH="$app/services" "$python" -m lifedash.ollama_models plan 2>/dev/null || true)
        if lifedash_agent_should_pull "$plan"; then
            echo "local agents: pulling $(lifedash_agent_base_model) in the background"
        else
            echo "local agents: hardware is below the 7B tier, so $(lifedash_agent_model) was not queued"
        fi
    fi
}
