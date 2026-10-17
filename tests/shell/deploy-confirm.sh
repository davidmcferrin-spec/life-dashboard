#!/usr/bin/env bash
# confirm-sha is compared to origin/main after fetch. Bad arguments fail before root.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
# shellcheck source=deploy/lib/deploy-guard.sh
source "$root/deploy/lib/deploy-guard.sh"

fail() { echo "FAIL: $*" >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
origin=$tmp/origin.git
app=$tmp/app
git init -q --bare -b main "$origin"
mkdir -p "$app"
git -C "$app" init -q -b main
git -C "$app" config user.email t@example.com
git -C "$app" config user.name test
echo one > "$app/readme.txt"
git -C "$app" add readme.txt
git -C "$app" commit -qm first
git -C "$app" remote add origin "$origin"
git -C "$app" push -q -u origin main
sha=$(git -C "$app" rev-parse origin/main)

lifedash_require_origin_main "$app" "$sha" || fail "matching origin/main was refused"

other=$tmp/other
git clone -q "$origin" "$other"
git -C "$other" config user.email t@example.com
git -C "$other" config user.name test
echo two > "$other/two.txt"
git -C "$other" add two.txt
git -C "$other" commit -qm second
git -C "$other" push -q origin main
new=$(git -C "$other" rev-parse HEAD)
git -C "$app" update-ref refs/remotes/origin/main "$sha"
[[ $(git -C "$app" rev-parse origin/main) == "$sha" ]] || fail "origin/main was not rewound"
lifedash_require_origin_main "$app" "$new" || fail "a stale origin/main was not refreshed to the fetched commit"
[[ $(git -C "$app" rev-parse origin/main) == "$new" ]] || fail "fetch did not move origin/main"
if lifedash_require_origin_main "$app" "$sha" 2>"$tmp/err"; then
    fail "the pre-fetch sha was accepted"
fi
grep -q "origin/main is ${new}" "$tmp/err" || fail "refusal did not name the fetched origin"

bad=$(printf 'b%.0s' {1..40})
if lifedash_require_origin_main "$app" "$bad" 2>"$tmp/err"; then
    fail "a different sha was accepted"
fi
grep -q "origin/main is" "$tmp/err" || fail "mismatch did not name origin/main"

if lifedash_require_origin_main "$app" "main" 2>"$tmp/err"; then
    fail "a branch name was accepted as a sha"
fi
grep -q "40 lowercase" "$tmp/err" || fail "non-hex sha was not rejected"

git -C "$app" checkout -q -b side
if lifedash_require_origin_main "$app" "$sha" 2>"$tmp/err"; then
    fail "a side branch was accepted"
fi
grep -q "main only" "$tmp/err" || fail "side branch error did not say main only"
git -C "$app" checkout -q main

git -C "$app" remote set-url origin "$tmp/missing.git"
if lifedash_require_origin_main "$app" "$new" 2>"$tmp/err"; then
    fail "a missing remote was accepted"
fi
grep -q "fetch of origin main failed" "$tmp/err" || fail "fetch failure was not reported"
git -C "$app" remote set-url origin "$origin"

# shellcheck source=deploy/bin/lifedash-perms
source "$root/deploy/bin/lifedash-perms"
lifedash_apply_code_modes "$app"
shared=$(git -C "$app" -c "safe.directory=$app" config --get core.sharedRepository)
[[ $shared == group ]] || fail "sharedRepository was not group ($shared)"
refs_mode=$(stat -c %a "$app/.git/refs")
if (( (8#$refs_mode & 8#020) == 0 )); then
    fail ".git/refs is not group-writable ($refs_mode)"
fi
if (( (8#$refs_mode & 8#2000) == 0 )); then
    fail ".git/refs is not setgid ($refs_mode)"
fi
cfg_mode=$(stat -c %a "$app/.git/config")
if (( (8#$cfg_mode & 8#020) != 0 )); then
    fail ".git/config is group-writable ($cfg_mode)"
fi
hooks_mode=$(stat -c %a "$app/.git/hooks")
if (( (8#$hooks_mode & 8#020) != 0 )); then
    fail ".git/hooks is group-writable ($hooks_mode)"
fi
readme_mode=$(stat -c %a "$app/readme.txt")
if (( (8#$readme_mode & 8#020) != 0 )); then
    fail "worktree file is group-writable ($readme_mode)"
fi

deploy=$root/deploy/bin/lifedash-deploy
if "$deploy" --confirm-sha abc 2>"$tmp/err"; then
    fail "short sha should fail"
fi
grep -q "40 lowercase" "$tmp/err" || fail "short sha did not fail closed"
if grep -q "run as root" "$tmp/err"; then
    fail "short sha reached the root check"
fi

if "$deploy" --confirm-sha "$sha" --no-pull 2>"$tmp/err"; then
    fail "confirm-sha with --no-pull should fail"
fi
grep -q "cannot be combined" "$tmp/err" || fail "combined flags were not rejected"

if "$deploy" --ref main 2>"$tmp/err"; then
    fail "unknown argument should fail"
fi
grep -q "unknown argument" "$tmp/err" || fail "unknown argument was not rejected"

if "$deploy" --confirm-sha "$sha" 2>"$tmp/err"; then
    fail "a valid sha must still refuse to run without root"
fi
grep -q "run as root" "$tmp/err" || fail "valid sha did not stop at the root check"

spool=$root/deploy/bin/lifedash-deploy-from-spool
if "$spool" --job not-a-job 2>"$tmp/err"; then
    fail "bad job id should fail"
fi
grep -q "32 lowercase" "$tmp/err" || fail "bad job id was not rejected"
if "$spool" --shell 2>"$tmp/err"; then
    fail "unknown spool argument should fail"
fi
grep -q "unknown argument" "$tmp/err" || fail "spool unknown argument was not rejected"

job=$(printf 'a%.0s' {1..32})
req=$tmp/job.json
python3 - "$req" "$sha" "$job" <<'PY'
import json, sys
json.dump({
    "job_id": sys.argv[3],
    "confirm_sha": sys.argv[2],
    "token_id": 9,
    "token_name": "Claude; $(id)",
    "user_id": 3,
    "command": "rm -rf /",
    "ref": "evil",
}, open(sys.argv[1], "w"))
PY
parsed=$(python3 "$root/scripts/deploy_request.py" read "$req")
[[ $(printf '%s\n' "$parsed" | sed -n '1p') == "$sha" ]] || fail "reader did not keep the sha"
[[ $(printf '%s\n' "$parsed" | sed -n '2p') == "$job" ]] || fail "reader did not keep the job id"
[[ $(printf '%s\n' "$parsed" | sed -n '5p') == "Claude id" ]] || fail "token name was not stripped: $(printf '%s\n' "$parsed" | sed -n '5p')"
if python3 "$root/scripts/deploy_request.py" read "$req" | grep -q 'rm -rf'; then
    fail "request reader echoed a command"
fi

badjson=$tmp/bad.json
echo '{"confirm_sha":"main","job_id":"'"$job"'"}' > "$badjson"
if python3 "$root/scripts/deploy_request.py" read "$badjson"; then
    fail "a branch name in the request was accepted"
fi

state=$tmp/last.json
now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf '{"state":"succeeded","started_at":"%s"}\n' "$now" > "$state"
set +e
python3 "$root/scripts/deploy_request.py" rate "$state" 300
code=$?
set -e
[[ $code -eq 3 ]] || fail "a fresh success was not rate limited ($code)"
old=$(date -u -d '10 minutes ago' +%Y-%m-%dT%H:%M:%SZ)
printf '{"state":"rejected","started_at":"%s"}\n' "$now" > "$state"
python3 "$root/scripts/deploy_request.py" rate "$state" 300 || fail "a rejected confirm must not consume the rate limit"
printf '{"state":"succeeded","started_at":"%s"}\n' "$old" > "$state"
python3 "$root/scripts/deploy_request.py" rate "$state" 300 || fail "an old success must be allowed"

lifedash_mcp_request_allowed "" 0 || fail "an admin session was refused while MCP updates are off"
lifedash_mcp_request_allowed "" 1 || fail "an admin session was refused while MCP updates are on"
if lifedash_mcp_request_allowed 9 0; then
    fail "a token request was allowed while MCP updates are off"
fi
lifedash_mcp_request_allowed 9 1 || fail "a token request was refused while MCP updates are on"
if lifedash_mcp_request_allowed 9 ""; then
    fail "a missing flag allowed a token request"
fi
if lifedash_mcp_request_allowed 9 true; then
    fail "a non-1 flag allowed a token request"
fi

string_token=$tmp/string-token.json
python3 - "$string_token" "$sha" "$job" <<'PY'
import json, sys
json.dump({"job_id": sys.argv[3], "confirm_sha": sys.argv[2], "token_id": "9"}, open(sys.argv[1], "w"))
PY
if python3 "$root/scripts/deploy_request.py" read "$string_token"; then
    fail "a string token id was treated as a session request"
fi

fake=$tmp/fakebin
mkdir -p "$fake" "$tmp/empty"
cat > "$fake/runuser" <<'EOF'
#!/usr/bin/env bash
while [[ $# -gt 0 && $1 != -- ]]; do shift; done
[[ ${1-} == -- ]] || exit 1
shift
exec "$@"
EOF
cat > "$fake/psql" <<'EOF'
#!/usr/bin/env bash
if [[ ${FAKE_PSQL_FAIL-} == 1 ]]; then
    exit 1
fi
printf '%s' "${FAKE_PSQL_OUT-}"
EOF
chmod 0755 "$fake/runuser" "$fake/psql"
old_path=$PATH
export PATH="$fake:$PATH"
export FAKE_PSQL_OUT=
[[ $(lifedash_read_mcp_updates_flag lifedash) == 0 ]] || fail "an empty flag read was not off"
export FAKE_PSQL_OUT=1
[[ $(lifedash_read_mcp_updates_flag lifedash) == 1 ]] || fail "an enabled flag was not on"
export FAKE_PSQL_OUT=0
[[ $(lifedash_read_mcp_updates_flag lifedash) == 0 ]] || fail "a disabled flag was not off"
export FAKE_PSQL_OUT=true
[[ $(lifedash_read_mcp_updates_flag lifedash) == 0 ]] || fail "a non-1 flag was treated as on"
export FAKE_PSQL_FAIL=1
export FAKE_PSQL_OUT=1
[[ $(lifedash_read_mcp_updates_flag lifedash) == 0 ]] || fail "a failed query was treated as on"
[[ $(lifedash_read_mcp_updates_flag 'Lifedash') == 0 ]] || fail "a bad database name was queried"
# shellcheck disable=SC2123
export PATH="$tmp/empty"
[[ $(lifedash_read_mcp_updates_flag lifedash) == 0 ]] || fail "a missing database client was treated as on"
export PATH=$old_path
unset FAKE_PSQL_OUT FAKE_PSQL_FAIL

echo "ok"
