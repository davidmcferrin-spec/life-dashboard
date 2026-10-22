# Ollama on 127.0.0.1:11434. Sourced by deploy-guard. Do not execute.
# shellcheck shell=bash

# The official unit is left in place. This drop-in is what forces loopback.
lifedash_ollama_dropin() {
    printf '%s\n' '[Service]' 'Environment=OLLAMA_HOST=127.0.0.1:11434'
}

lifedash_ollama_bind_ok() {
    local text=$1
    [[ $text == *127.0.0.1:11434* ]] || return 1
    [[ $text == *0.0.0.0* ]] && return 1
    return 0
}

lifedash_ollama_model_ok() {
    [[ $1 =~ ^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$ ]]
}

# Arguments for the venv interpreter. A bare or system python is rejected.
lifedash_ollama_args() {
    local action=$1 model=$2 python=$3 cmd
    case $action in
        ollama-pull) cmd=pull ;;
        ollama-remove) cmd=remove ;;
        *) return 1 ;;
    esac
    lifedash_ollama_model_ok "$model" || return 1
    case $python in
        */venv/bin/python) ;;
        *) return 1 ;;
    esac
    printf '%s\n' "$python" -m lifedash.ollama_models "$cmd" "$model"
}

# Install when the binary is missing, keep the bind on loopback, and start
# the model pull without waiting for it. A failed download does not stop deploy.
lifedash_ollama_ensure() {
    local app=${1:-/srv/lifedash/app}
    local dropin=/etc/systemd/system/ollama.service.d/lifedash.conf
    local python=/srv/lifedash/venv/bin/python
    local envline plan
    [[ $EUID -eq 0 ]] || {
        echo "ollama: run as root" >&2
        return 1
    }
    install -d -o lifedash -g lifedash -m 0750 /var/lib/lifedash/ollama
    if ! command -v ollama >/dev/null 2>&1; then
        echo "ollama: installing from https://ollama.com/install.sh"
        if ! command -v curl >/dev/null 2>&1; then
            echo "ollama: curl is not installed, so the official installer did not run" >&2
            return 0
        fi
        if ! curl -fsSL https://ollama.com/install.sh | sh; then
            echo "ollama: the official installer failed" >&2
            return 0
        fi
    fi
    if [[ ! -e /etc/systemd/system/ollama.service && ! -e /lib/systemd/system/ollama.service && ! -e /usr/lib/systemd/system/ollama.service ]]; then
        echo "ollama: the service unit is missing after install" >&2
        return 0
    fi
    install -d -m 0755 /etc/systemd/system/ollama.service.d
    lifedash_ollama_dropin > "$dropin"
    chmod 0644 "$dropin"
    if ! lifedash_ollama_bind_ok "$(<"$dropin")"; then
        echo "ollama: refusing a listen address outside this machine" >&2
        return 1
    fi
    install -m 0644 "$app/deploy/systemd/lifedash-ollama-models.service" /etc/systemd/system/lifedash-ollama-models.service
    install -m 0644 "$app/deploy/systemd/lifedash-ollama-models.timer" /etc/systemd/system/lifedash-ollama-models.timer
    systemctl daemon-reload
    systemctl enable --now ollama.service
    envline=$(systemctl show ollama.service -p Environment --value || true)
    case $envline in
        *0.0.0.0*)
            echo "ollama: refusing a listen address outside this machine" >&2
            systemctl stop ollama.service >/dev/null 2>&1 || true
            return 1
            ;;
    esac
    if [[ -x $python ]]; then
        plan=$(PYTHONPATH="$app/services" "$python" -m lifedash.ollama_models plan || true)
        if [[ -n $plan ]]; then
            echo "ollama: $plan"
        fi
    fi
    systemctl enable lifedash-ollama-models.timer >/dev/null
    systemctl start --no-block lifedash-ollama-models.timer >/dev/null || true
    systemctl start --no-block lifedash-ollama-models.service
    echo "ollama: model pull started in the background"
}
