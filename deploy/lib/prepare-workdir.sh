#!/usr/bin/env bash
# Shared by lifedash-deploy. Source this file; do not execute it.
# Sets core.fileMode false, discards mode-only diffs, and refuses content edits
# so `git pull --ff-only` is not blocked by a local chmod.

lifedash_prepare_workdir() {
    local dir=${1:?directory}
    if [[ ! -e $dir/.git ]]; then
        echo "lifedash-deploy: $dir is not a git checkout" >&2
        return 1
    fi

    local -a git=(git -C "$dir" -c "safe.directory=$dir")

    # fileMode false hides mode-only diffs, so this list is real content.
    # An abort sets the config and leaves file contents in place.
    local content
    content=$("${git[@]}" -c core.fileMode=false diff HEAD --name-only)
    if [[ -n $content ]]; then
        "${git[@]}" config core.fileMode false
        echo "lifedash-deploy: refusing to pull; tracked files have local content changes:" >&2
        echo "$content" >&2
        return 1
    fi

    local line path
    local -a mode_paths=()
    while IFS= read -r line; do
        [[ $line == *"mode change"* ]] || continue
        path=${line#* => }
        path=${path#* }
        [[ -n $path ]] && mode_paths+=("$path")
    done < <("${git[@]}" -c core.fileMode=true diff HEAD --summary)

    # checkout only rewrites the executable bit while core.fileMode is true.
    "${git[@]}" config core.fileMode true
    if ((${#mode_paths[@]} > 0)); then
        "${git[@]}" checkout HEAD -- "${mode_paths[@]}"
    fi
    "${git[@]}" config core.fileMode false
}
