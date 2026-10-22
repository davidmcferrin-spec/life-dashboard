#!/usr/bin/env bash
# Checks used by lifedash-deploy and the spool runner.
# A confirm sha is compared to origin/main after fetch. It is never a ref argument.
# shellcheck shell=bash

# Fixed refspec so a stale refs/remotes/origin/main cannot survive the fetch.
# timeout bounds a hung remote. 124 is timeout's exit; 137 is the follow-up SIGKILL.
lifedash_fetch_origin_main() {
    local app=$1
    local -a cmd
    cmd=(git -C "$app" -c "safe.directory=$app" fetch origin '+refs/heads/main:refs/remotes/origin/main')
    if command -v timeout >/dev/null 2>&1; then
        GIT_TERMINAL_PROMPT=0 timeout -k 5 15 "${cmd[@]}"
        return
    fi
    GIT_TERMINAL_PROMPT=0 "${cmd[@]}"
}

lifedash_require_origin_main() {
    local app=$1 sha=$2 branch origin status
    [[ $sha =~ ^[0-9a-f]{40}$ ]] || {
        echo "confirm-sha must be 40 lowercase hex characters" >&2
        return 1
    }
    status=0
    lifedash_fetch_origin_main "$app" >&2 || status=$?
    if [[ $status -eq 124 || $status -eq 137 ]]; then
        echo "fetch of origin main timed out" >&2
        return 1
    fi
    if [[ $status -ne 0 ]]; then
        echo "fetch of origin main failed" >&2
        return 1
    fi
    branch=$(git -C "$app" -c safe.directory="$app" rev-parse --abbrev-ref HEAD)
    [[ $branch == "main" ]] || {
        echo "refusing deploy: branch is ${branch} (main only)" >&2
        return 1
    }
    origin=$(git -C "$app" -c safe.directory="$app" rev-parse --verify origin/main)
    [[ $origin =~ ^[0-9a-f]{40}$ ]] || {
        echo "origin/main is not a commit" >&2
        return 1
    }
    [[ $origin == "$sha" ]] || {
        echo "refusing deploy: origin/main is ${origin}" >&2
        return 1
    }
    return 0
}

# A request with a token id is an MCP deploy. Those run only when the flag is 1.
# An empty token id is an admin session and is allowed either way.
# Anything other than the digit 1 is off, including an empty or unreadable flag.
lifedash_mcp_request_allowed() {
    local token_id=${1-}
    local flag=${2:-0}
    if [[ -z $token_id ]]; then
        return 0
    fi
    [[ $token_id =~ ^[0-9]+$ && $flag == 1 ]]
}

# Prints 1 or 0. Unreadable database, missing table, and any value other than 1 are 0.
lifedash_read_mcp_updates_flag() {
    local db=${1:-lifedash}
    local value
    [[ $db =~ ^[a-z][a-z0-9_]*$ ]] || { echo 0; return 0; }
    if ! command -v runuser >/dev/null 2>&1 || ! command -v psql >/dev/null 2>&1; then
        echo 0
        return 0
    fi
    value=$(runuser -u postgres -- psql -d "$db" -v ON_ERROR_STOP=1 -Atqc "SELECT enabled FROM server_flags WHERE flag_key = 'mcp_updates'" 2>/dev/null || true)
    if [[ $value == 1 ]]; then
        echo 1
    else
        echo 0
    fi
}

# 3770: the php-fpm group can create request files, setgid keeps them in that group,
# and the sticky bit stops one user deleting another's file. Root still reads them.
lifedash_install_spool() {
    local spool=${1:-/var/lib/lifedash/deploy}
    local group=${2:-lifedash}
    install -d -o root -g "$group" -m 3770 "$spool/incoming"
    install -d -o root -g "$group" -m 0750 "$spool/state" "$spool/state/jobs"
}

# install when this app owns the unit or a dashboard token is waiting and nothing else is running.
# skip when another cloudflared is already active, so a deploy does not replace it.
lifedash_cloudflared_action() {
    local active=$1 ours=$2 token=$3
    if [[ $active == 1 && $ours != 1 ]]; then
        echo skip
        return 0
    fi
    if [[ $token == 1 || $ours == 1 ]]; then
        echo install
        return 0
    fi
    echo skip
}

lifedash_cloudflared_is_ours() {
    local frag
    frag=$(systemctl show -p FragmentPath --value cloudflared 2>/dev/null || true)
    [[ -n $frag && -f $frag ]] || return 1
    grep -q 'lifedash-cloudflared' "$frag"
}

# Spool directories and units added after the first provision. Idempotent.
# enable --now starts a path unit that enable alone leaves inactive.
lifedash_install_runtime_units() {
    local app=${1:-/srv/lifedash/app}
    local group=${2:-lifedash}
    local unit src active=0 ours=0 token=0 action
    [[ -d $app/deploy/systemd ]] || return 0

    lifedash_install_spool /var/lib/lifedash/deploy "$group"
    lifedash_install_spool /var/lib/lifedash/service "$group"

    for unit in \
        lifedash-service.service \
        lifedash-service.path \
        lifedash-spotify.service \
        lifedash-spotify.timer
    do
        src=$app/deploy/systemd/$unit
        [[ -f $src ]] || continue
        install -m 0644 "$src" /etc/systemd/system/"$unit"
    done

    systemctl is-active --quiet cloudflared && active=1
    lifedash_cloudflared_is_ours && ours=1
    [[ -s /etc/lifedash/cloudflared.token ]] && token=1
    action=$(lifedash_cloudflared_action "$active" "$ours" "$token")
    if [[ $action == install && -f $app/deploy/systemd/cloudflared.service ]]; then
        install -m 0644 "$app/deploy/systemd/cloudflared.service" /etc/systemd/system/cloudflared.service
    fi

    systemctl daemon-reload
    systemctl enable --now lifedash-deploy.path >/dev/null
    systemctl enable --now lifedash-service.path >/dev/null
    systemctl enable --now lifedash-spotify.timer >/dev/null
    if [[ $action == install ]]; then
        systemctl enable --now cloudflared >/dev/null
    fi
    systemctl try-restart lifedash-service.path
    systemctl try-restart lifedash-deploy.path
}

# php-fpm must be able to create a request, and the path unit must be watching.
lifedash_service_control_ready() {
    local group=${1:-lifedash}
    local incoming=/var/lib/lifedash/service/incoming
    local state=/var/lib/lifedash/service/state
    [[ -d $incoming && -d $state ]] || return 1
    runuser -u "$group" -- test -w "$incoming" || return 1
    systemctl is-active --quiet lifedash-service.path || return 1
}
