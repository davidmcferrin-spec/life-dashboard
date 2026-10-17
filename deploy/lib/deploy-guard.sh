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

lifedash_install_spool() {
    local spool=${1:-/var/lib/lifedash/deploy}
    local group=${2:-lifedash}
    install -d -o root -g "$group" -m 3770 "$spool/incoming"
    install -d -o root -g "$group" -m 0750 "$spool/state" "$spool/state/jobs"
}
