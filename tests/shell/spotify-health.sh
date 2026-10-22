#!/usr/bin/env bash
# Venv permissions, the revision file, and the Spotify interpreter path.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=deploy/bin/lifedash-perms
source "$root/deploy/bin/lifedash-perms"
# shellcheck source=deploy/lib/deploy-guard.sh
source "$root/deploy/lib/deploy-guard.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }

pool=$root/deploy/php/lifedash-pool.conf
grep -q '/srv/lifedash/venv' "$pool" || fail "open_basedir must allow the venv"
grep -q '/var/lib/lifedash/service' "$pool" || fail "open_basedir must allow the service spool"
grep -q '/usr/bin' "$pool" || fail "open_basedir must allow the venv python symlink target"

if lifedash_spotify_args spotify-pull 4 "" python3 >/dev/null; then
    fail "bare python3 must be rejected"
fi
if lifedash_spotify_args spotify-pull 4 "" /usr/bin/python3 >/dev/null; then
    fail "system python3 must be rejected"
fi
mapfile -t args < <(lifedash_spotify_args spotify-pull 4 9 /tmp/app/venv/bin/python)
[[ ${args[0]} == /tmp/app/venv/bin/python ]] || fail "venv python was not selected"
[[ ${args[3]} == pull ]] || fail "pull command was not built"
[[ ${args[5]} == 4 ]] || fail "user id was not passed"
[[ ${args[7]} == 9 ]] || fail "playlist id was not passed"
if lifedash_spotify_args spotify-pull 0 "" /tmp/app/venv/bin/python >/dev/null; then
    fail "user id 0 must be rejected"
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

venv=$tmp/venv
mkdir -p "$venv/bin" "$venv/lib"
target=$tmp/system-python
printf '#!/bin/sh\nexit 0\n' > "$target"
chmod 0755 "$target"
ln -s "$target" "$venv/bin/python"
printf '#!/bin/sh\nexit 0\n' > "$venv/bin/lifedash-mcp"
echo 'module' > "$venv/lib/pkg.py"
lifedash_expose_venv "$venv" "$(id -gn)"
[[ $(stat -c %a "$venv") == 750 ]] || fail "venv directory is not 750"
[[ $(stat -c %a "$venv/bin/lifedash-mcp") == 750 ]] || fail "venv script is not executable by the group"
[[ $(stat -c %a "$venv/lib/pkg.py") == 640 ]] || fail "venv package is not group-readable"
[[ $(stat -c %a "$target") == 755 ]] || fail "chmod followed the python symlink"

repo=$tmp/repo
mkdir -p "$repo"
git -C "$repo" init -q -b main
git -C "$repo" config user.email t@example.com
git -C "$repo" config user.name test
echo app > "$repo/README"
git -C "$repo" add README
git -C "$repo" commit -q -m init
lifedash_write_revision "$repo"
[[ -f $repo/REVISION ]] || fail "REVISION was not written"
rev=$(git -C "$repo" rev-parse --short=12 HEAD)
grep -qx "$rev" "$repo/REVISION" || fail "REVISION does not match HEAD"
[[ -z $(git -C "$repo" status --porcelain --untracked-files=no) ]] || fail "REVISION dirtied tracked files"

echo "ok"
