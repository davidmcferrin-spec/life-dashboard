#!/usr/bin/env bash
# Mode-only dirt must not block a deploy, and permission fixes must not create it.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=deploy/lib/prepare-workdir.sh
source "$root/deploy/lib/prepare-workdir.sh"
# shellcheck source=deploy/bin/lifedash-perms
source "$root/deploy/bin/lifedash-perms"

fail() { echo "FAIL: $*" >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
repo=$tmp/repo
mkdir -p "$repo/public" "$repo/deploy/bin" "$repo/storage"

echo plain > "$repo/plain.txt"
printf '#!/bin/sh\necho ok\n' > "$repo/deploy/bin/tool.sh"
chmod +x "$repo/deploy/bin/tool.sh"
echo asset > "$repo/public/asset.txt"
echo keep > "$repo/storage/.gitkeep"

git -C "$repo" init -q -b main
git -C "$repo" config user.email t@example.com
git -C "$repo" config user.name test
git -C "$repo" add -A
git -C "$repo" commit -q -m init

chmod +x "$repo/plain.txt"
lifedash_prepare_workdir "$repo"
[[ $(git -C "$repo" config --get core.fileMode) == false ]] || fail "core.fileMode was not set false"
[[ -z $(git -C "$repo" -c core.fileMode=true status --porcelain --untracked-files=no) ]] || fail "mode-only diff was kept"
[[ ! -x $repo/plain.txt ]] || fail "mode-only file was not restored to 644"
[[ -x $repo/deploy/bin/tool.sh ]] || fail "100755 script lost its executable bit"
grep -qx plain "$repo/plain.txt" || fail "mode cleanup changed file content"

echo more >> "$repo/plain.txt"
if lifedash_prepare_workdir "$repo"; then
    fail "content change should abort the pull"
fi
grep -q more "$repo/plain.txt" || fail "content change was discarded"

git -C "$repo" config core.fileMode true
git -C "$repo" checkout HEAD -- plain.txt
echo extra >> "$repo/public/asset.txt"
chmod +x "$repo/storage/.gitkeep"
if lifedash_prepare_workdir "$repo"; then
    fail "content change alongside a mode change should abort"
fi
grep -q extra "$repo/public/asset.txt" || fail "mixed content change was discarded"
[[ -x $repo/storage/.gitkeep ]] || fail "aborted deploy still reset a mode-only file"

git -C "$repo" config core.fileMode true
git -C "$repo" checkout HEAD -- .
lifedash_apply_code_modes "$repo"
dirt=$(lifedash_tracked_dirt "$repo")
[[ -z $dirt ]] || fail "permission chmod dirtied tracked files: $dirt"
[[ -x $repo/deploy/bin/tool.sh ]] || fail "permission chmod cleared an executable script"
[[ ! -x $repo/plain.txt ]] || fail "permission chmod marked a 644 file executable"

echo ok
