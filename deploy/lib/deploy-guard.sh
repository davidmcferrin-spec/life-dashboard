#!/usr/bin/env bash
# Checks used by lifedash-deploy and the spool runner.
# A confirm sha is compared to origin/main after fetch. It is never a ref argument.
# shellcheck shell=bash

lifedash_require_origin_main() {
    local app=$1 sha=$2 branch origin
    [[ $sha =~ ^[0-9a-f]{40}$ ]] || {
        echo "confirm-sha must be 40 lowercase hex characters" >&2
        return 1
    }
    git -C "$app" -c safe.directory="$app" fetch origin main >&2 || {
        echo "fetch of origin main failed" >&2
        return 1
    }
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

lifedash_install_spool() {
    local spool=${1:-/var/lib/lifedash/deploy}
    local group=${2:-lifedash}
    install -d -o root -g "$group" -m 3770 "$spool/incoming"
    install -d -o root -g "$group" -m 0750 "$spool/state" "$spool/state/jobs"
}
