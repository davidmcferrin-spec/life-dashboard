#!/usr/bin/env bash
# =============================================================================
# setup.sh — one-command install of life-dashboard on a fresh Ubuntu 26.04 server
#
#   git clone https://github.com/davidmcferrin-spec/life-dashboard.git
#   cd life-dashboard
#   sudo ./setup.sh
#
# What it does, in order:
#   1. Places the app at /srv/lifedash/app (cloned from THIS checkout, so root
#      needs no GitHub credentials for the first install)
#   2. Builds the app .env (or uses --env-file), encrypts it, deletes the plaintext
#   3. Runs deploy/provision.sh (packages, PostgreSQL, Apache, PHP-FPM, HTTPS
#      certificate, timers, backups, firewall, cloudflared)
#   4. Initialises the database: fresh admin user, or import from personal-dashboard
#   5. Verifies /healthz and the Python health check
#
# Options:
#   --env-file FILE          use an existing .env (e.g. your Dreamhost one, DB block edited)
#   --import-sqlite FILE     import a personal-dashboard SQLite database
#   --uploads DIR            copy personal-dashboard storage/uploads from DIR
#   --skip-orphans           importer: drop rows whose parent was deleted
#   --truncate-long          importer: cut text longer than its column
#   --fresh                  skip import; create a new admin user
#   --yes                    non-interactive: accept defaults, never prompt
#   --dry-run                print what would happen; change nothing
#
# HTTPS for the dashboard (asked interactively on first run if not given):
#   --ui-host HOST           e.g. dash.yourdomain.com (domain must be on Cloudflare)
#   --acme-email EMAIL       Let's Encrypt contact
#   --cf-token-file FILE     Cloudflare API token (Zone → DNS → Edit, that zone only)
#   --allow-cidr CIDR        extra source range for the firewall, repeatable
#                            (the UniFi Teleport client subnet)
#   --skip-tls               HTTP only for now
#
# Passed through to provision.sh: --lan-cidr, --with-tailscale, --skip-firewall,
#   --skip-cloudflared
#
# Safe to re-run: an existing install is updated (git pull), existing secrets
# are kept unless --env-file is given, and the database is only initialised
# when it has no users.
# =============================================================================
set -euo pipefail

TARGET=${LIFEDASH_APP_DIR:-/srv/lifedash/app}
VENV=/srv/lifedash/venv
APP_USER=lifedash
CRED=/etc/lifedash/app.env.cred

ENV_FILE=""
IMPORT_SQLITE=""
UPLOADS_DIR=""
IMPORT_FLAGS=()
FRESH=false
ASSUME_YES=false
DRY_RUN=false
PROVISION_ARGS=()
UI_HOST=""
ACME_EMAIL=""
CF_TOKEN_FILE=""
ALLOW_CIDRS=()
SKIP_TLS=false

usage() { sed -n '2,46p' "$0" | sed 's/^# \{0,1\}//'; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --env-file)       ENV_FILE=$2; shift 2 ;;
        --import-sqlite)  IMPORT_SQLITE=$2; shift 2 ;;
        --uploads)        UPLOADS_DIR=$2; shift 2 ;;
        --skip-orphans|--truncate-long) IMPORT_FLAGS+=("$1"); shift ;;
        --fresh)          FRESH=true; shift ;;
        --yes|-y)         ASSUME_YES=true; shift ;;
        --dry-run)        DRY_RUN=true; shift ;;
        -h|--help)        usage; exit 0 ;;
        --ui-host)        UI_HOST=${2,,}; shift 2 ;;
        --acme-email)     ACME_EMAIL=$2; shift 2 ;;
        --cf-token-file)  CF_TOKEN_FILE=$2; shift 2 ;;
        --allow-cidr)     ALLOW_CIDRS+=("$2"); shift 2 ;;
        --skip-tls)       SKIP_TLS=true; PROVISION_ARGS+=("$1"); shift ;;
        --lan-cidr)       PROVISION_ARGS+=("$1" "$2"); shift 2 ;;
        --with-tailscale|--skip-tailscale|--skip-firewall|--skip-cloudflared) PROVISION_ARGS+=("$1"); shift ;;
        *) echo "unknown option: $1 (see --help)" >&2; exit 64 ;;
    esac
done

# --- output helpers ----------------------------------------------------------
if [[ -t 1 ]]; then B=$'\e[1m'; G=$'\e[32m'; Y=$'\e[33m'; R=$'\e[31m'; N=$'\e[0m'; else B=""; G=""; Y=""; R=""; N=""; fi
STAGE=0
stage() { STAGE=$((STAGE + 1)); echo; echo "${B}━━ ${STAGE}. $*${N}"; }
ok()    { echo "  ${G}✓${N} $*"; }
note()  { echo "  ${Y}!${N} $*"; }
die()   { echo "${R}✗ $*${N}" >&2; exit 1; }

# run CMD… — execute, or just print it under --dry-run
run() {
    if $DRY_RUN; then
        printf '  [dry-run]'; printf ' %q' "$@"; echo
    else
        "$@"
    fi
}

interactive() { ! $ASSUME_YES && [[ -t 0 ]]; }

# ask "Question" default → echoes answer
ask() {
    local q=$1 def=${2:-} ans
    if ! interactive; then echo "$def"; return; fi
    read -r -p "  $q${def:+ [$def]}: " ans </dev/tty
    echo "${ans:-$def}"
}

confirm() {
    local q=$1 def=${2:-y} ans
    if ! interactive; then [[ $def == y ]]; return; fi
    read -r -p "  $q [$( [[ $def == y ]] && echo Y/n || echo y/N )]: " ans </dev/tty
    ans=${ans:-$def}
    [[ ${ans,,} == y* ]]
}

# --- 0. preflight ------------------------------------------------------------
stage "Preflight"
[[ $EUID -eq 0 ]] || $DRY_RUN || die "run with sudo: sudo ./setup.sh"
SRC=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
[[ -f $SRC/deploy/provision.sh ]] || die "run setup.sh from a life-dashboard checkout"
# shellcheck disable=SC1091
. /etc/os-release
[[ ${ID:-} == ubuntu ]] || die "Ubuntu required (found ${ID:-unknown})"
[[ ${VERSION_ID:-} == 26.04 ]] || note "built for Ubuntu 26.04; this is ${VERSION_ID:-unknown}"
[[ -z $ENV_FILE || -f $ENV_FILE ]] || die "--env-file not found: $ENV_FILE"
[[ -z $IMPORT_SQLITE || -f $IMPORT_SQLITE ]] || die "--import-sqlite not found: $IMPORT_SQLITE"
[[ -z $UPLOADS_DIR || -d $UPLOADS_DIR ]] || die "--uploads is not a directory: $UPLOADS_DIR"
[[ -z $CF_TOKEN_FILE || -s $CF_TOKEN_FILE ]] || die "--cf-token-file missing or empty: $CF_TOKEN_FILE"
$FRESH && [[ -n $IMPORT_SQLITE ]] && die "--fresh and --import-sqlite are mutually exclusive"
command -v git >/dev/null || run apt-get install -y -q git
ok "source checkout: $SRC"
$DRY_RUN && note "dry run — nothing will be changed"

# --- 1. place the app at /srv/lifedash/app -----------------------------------
stage "App code → $TARGET"
UPSTREAM=$(git -C "$SRC" remote get-url origin 2>/dev/null || true)
BRANCH=$(git -C "$SRC" rev-parse --abbrev-ref HEAD 2>/dev/null || echo main)

if [[ $SRC == "$TARGET" ]]; then
    ok "running from $TARGET already"
elif [[ -d $TARGET/.git ]]; then
    ok "existing install found — updating from $SRC ($BRANCH)"
    run git -c safe.directory="$TARGET" -C "$TARGET" fetch --quiet "$SRC" "$BRANCH"
    run git -c safe.directory="$TARGET" -C "$TARGET" merge --ff-only --quiet FETCH_HEAD \
        || die "$TARGET has diverged from this checkout — resolve it there, then re-run"
elif [[ -e $TARGET && -n $(ls -A "$TARGET" 2>/dev/null) ]]; then
    die "$TARGET exists and is not a git checkout — move it aside and re-run"
else
    run install -d -m 0755 "$(dirname "$TARGET")"
    # Clone from the local checkout: no GitHub credentials needed for root.
    run git clone --quiet --branch "$BRANCH" "$SRC" "$TARGET"
    if [[ -n $UPSTREAM ]]; then
        run git -c safe.directory="$TARGET" -C "$TARGET" remote set-url origin "$UPSTREAM"
        ok "cloned; origin → $UPSTREAM"
    else
        ok "cloned (no upstream remote in the source checkout)"
    fi
fi
run chmod +x "$TARGET/setup.sh" "$TARGET/deploy/provision.sh" "$TARGET"/deploy/bin/*

# --- 2a. HTTPS settings ---------------------------------------------------------
stage "HTTPS for the dashboard"
TOKEN_TMP=""
PREV_UI_HOST=""
if [[ -f /etc/lifedash/provision.conf ]]; then
    PREV_UI_HOST=$(sed -n 's/^UI_HOST=//p' /etc/lifedash/provision.conf | tr -d "'\"")
fi
if $SKIP_TLS; then
    note "skipped (--skip-tls) — dashboard will be HTTP only"
elif [[ -z $UI_HOST && -n $PREV_UI_HOST && -f /etc/lifedash/cloudflare.ini ]]; then
    ok "keeping $PREV_UI_HOST (from the previous run)"
    UI_HOST=$PREV_UI_HOST
else
    if [[ -z $UI_HOST ]]; then
        echo "  The dashboard gets a real HTTPS certificate for a name on your Cloudflare domain."
        echo "  The name only needs a LOCAL DNS record on the UDM — nothing public."
        UI_HOST=$(ask "Dashboard hostname, e.g. dash.yourdomain.com (blank = HTTP only for now)" "")
        UI_HOST=${UI_HOST,,}
    fi
    if [[ -z $UI_HOST ]]; then
        note "no hostname — HTTP only. Re-run later with --ui-host to add HTTPS."
        SKIP_TLS=true
        PROVISION_ARGS+=(--skip-tls)
    else
        [[ -n $ACME_EMAIL ]] || ACME_EMAIL=$(ask "Email for Let's Encrypt expiry notices" "")
        [[ -n $ACME_EMAIL ]] || die "an email is required for Let's Encrypt (or use --skip-tls)"
        if [[ -z $CF_TOKEN_FILE && ! -f /etc/lifedash/cloudflare.ini ]]; then
            interactive || die "--cf-token-file is required when running non-interactively"
            echo "  Cloudflare API token: dash.cloudflare.com → My Profile → API Tokens → Create →"
            echo "  'Edit zone DNS' template, limited to your domain's zone. Input is hidden."
            read -r -s -p "  Token: " token </dev/tty; echo
            [[ -n $token ]] || die "empty token"
            TOKEN_TMP=$(mktemp /run/lifedash-cftoken.XXXXXX)
            chmod 600 "$TOKEN_TMP"
            printf '%s\n' "$token" > "$TOKEN_TMP"
            unset token
            CF_TOKEN_FILE=$TOKEN_TMP
        fi
        PROVISION_ARGS+=(--ui-host "$UI_HOST" --acme-email "$ACME_EMAIL")
        [[ -n $CF_TOKEN_FILE ]] && PROVISION_ARGS+=(--cf-token-file "$CF_TOKEN_FILE")
        ok "HTTPS for $UI_HOST"
    fi
fi

if [[ ${#ALLOW_CIDRS[@]} -eq 0 ]] && interactive && ! grep -q "^ALLOW_CIDRS=..*" /etc/lifedash/provision.conf 2>/dev/null; then
    echo "  Remote access via UniFi Teleport usually arrives from its own subnet. Check the IP"
    echo "  your phone gets when connected to Teleport (WiFiman app) and enter its range."
    extra=$(ask "Extra subnet(s) to allow, space-separated CIDRs (blank = none)" "")
    read -r -a ALLOW_CIDRS <<< "$extra"
fi
for c in "${ALLOW_CIDRS[@]}"; do
    PROVISION_ARGS+=(--allow-cidr "$c")
done

# --- 2. secrets ---------------------------------------------------------------
stage "App .env (encrypted at rest)"
GENERATED_ENV=""
cleanup() {
    [[ -n $GENERATED_ENV && -f $GENERATED_ENV ]] && shred -u "$GENERATED_ENV" 2>/dev/null
    [[ -n $TOKEN_TMP && -f $TOKEN_TMP ]] && shred -u "$TOKEN_TMP" 2>/dev/null
    true
}
trap cleanup EXIT

if [[ -n $ENV_FILE ]]; then
    ok "using $ENV_FILE"
    PROVISION_ARGS+=(--env-file "$ENV_FILE")
elif [[ -f $CRED ]]; then
    ok "existing encrypted secrets kept ($CRED) — edit later with: sudo lifedash-env edit"
else
    note "no .env yet — building one from .env.example"
    GENERATED_ENV=$(mktemp /root/lifedash-env.XXXXXX)
    chmod 600 "$GENERATED_ENV"
    cp "$TARGET/.env.example" "$GENERATED_ENV" 2>/dev/null || cp "$SRC/.env.example" "$GENERATED_ENV"

    echo "  Migrating from personal-dashboard? Reuse its APP_SECRET — it encrypts stored"
    echo "  Dropbox/S3 credentials and signs existing sessions and auto-login tokens."
    secret=$(ask "Existing APP_SECRET (blank = generate new)" "")
    if [[ -z $secret ]]; then
        secret=$(openssl rand -hex 32)
        ok "generated a new APP_SECRET"
    elif [[ ! $secret =~ ^[A-Za-z0-9]{32,}$ ]]; then
        die "APP_SECRET looks wrong (expected 32+ alphanumeric characters)"
    fi
    app_url=$(ask "Dashboard URL" "https://${UI_HOST:-$(hostname -s)}")
    app_tz=$(ask "Time zone" "America/Chicago")

    sed -i -e "s|^APP_SECRET=.*|APP_SECRET=$secret|" \
           -e "s|^APP_URL=.*|APP_URL=$app_url|" \
           -e "s|^APP_TIMEZONE=.*|APP_TIMEZONE=$app_tz|" "$GENERATED_ENV"
    unset secret

    if interactive && confirm "Open the .env in ${EDITOR:-nano} to add API keys now? (can do later)" n; then
        "${EDITOR:-nano}" "$GENERATED_ENV" </dev/tty >/dev/tty
    fi
    PROVISION_ARGS+=(--env-file "$GENERATED_ENV")
fi

# --- 3. provision ---------------------------------------------------------------
stage "Provision server (deploy/provision.sh)"
run "$TARGET/deploy/provision.sh" "${PROVISION_ARGS[@]}"
cleanup; GENERATED_ENV=""

# Keep APP_URL / SESSION_SECURE consistent with how the UI is actually served:
# secure cookies only work over HTTPS, so HTTP-only mode must not require them.
if ! $DRY_RUN && [[ -f $CRED ]]; then
    if [[ -n $UI_HOST && $SKIP_TLS == false && -d /etc/letsencrypt/live/$UI_HOST ]]; then
        "$TARGET/deploy/bin/lifedash-env" set APP_URL "https://$UI_HOST" >/dev/null
        "$TARGET/deploy/bin/lifedash-env" set SESSION_SECURE true >/dev/null
        ok "APP_URL=https://$UI_HOST, secure session cookies on"
    else
        lan_ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<NF;i++) if ($i=="src") print $(i+1); exit}')
        "$TARGET/deploy/bin/lifedash-env" set APP_URL "http://${lan_ip:-localhost}" >/dev/null
        "$TARGET/deploy/bin/lifedash-env" set SESSION_SECURE false >/dev/null
        note "HTTP only: SESSION_SECURE=false so logins work; HTTPS re-enables it automatically"
    fi
fi
if [[ -n $ENV_FILE ]] && ! $DRY_RUN; then
    if confirm "Securely delete the plaintext $ENV_FILE now that it is encrypted?" y; then
        shred -u "$ENV_FILE" && ok "deleted $ENV_FILE"
    else
        note "remember to delete $ENV_FILE"
    fi
fi

# --- 4. database ---------------------------------------------------------------
stage "Database"
as_app() { run runuser -u "$APP_USER" -- "$@"; }

users=0
if ! $DRY_RUN; then
    users=$(runuser -u postgres -- psql -Atqd lifedash -c \
        "SELECT CASE WHEN to_regclass('users') IS NULL THEN 0 ELSE (SELECT COUNT(*) FROM users) END" 2>/dev/null || echo 0)
fi

if [[ $users -gt 0 ]]; then
    ok "database already has $users user(s) — applying pending migrations only"
    as_app php "$TARGET/scripts/migrate.php" </dev/null
else
    mode=fresh
    if [[ -n $IMPORT_SQLITE ]]; then
        mode=import
    elif ! $FRESH && interactive; then
        choice=$(ask "Empty database: (1) create a new admin user, or (2) import personal-dashboard SQLite" "1")
        if [[ $choice == 2 ]]; then
            mode=import
            IMPORT_SQLITE=$(ask "Path to dashboard.db (copied from Dreamhost)" "/root/dashboard.db")
            [[ -f $IMPORT_SQLITE ]] || die "not found: $IMPORT_SQLITE"
            if [[ -z $UPLOADS_DIR ]]; then
                UPLOADS_DIR=$(ask "Path to copied storage/uploads (blank = skip)" "")
            fi
        fi
    fi

    if [[ $mode == import ]]; then
        # The importer runs as lifedash; give it a readable copy.
        src_copy=/var/lib/lifedash/import-$(date +%s).db
        run install -m 0640 -o root -g "$APP_USER" "$IMPORT_SQLITE" "$src_copy"
        echo "  Dry-run import (read-only checks)…"
        as_app php "$TARGET/scripts/migrate-sqlite-to-pgsql.php" --sqlite="$src_copy" --dry-run "${IMPORT_FLAGS[@]}" \
            || die "preflight found problems (above). Re-run with --skip-orphans / --truncate-long if appropriate."
        if confirm "Preflight OK. Import now?" y; then
            as_app php "$TARGET/scripts/migrate-sqlite-to-pgsql.php" --sqlite="$src_copy" "${IMPORT_FLAGS[@]}"
            as_app php "$TARGET/scripts/migrate.php" </dev/null
            ok "imported $(basename "$IMPORT_SQLITE")"
        fi
        run rm -f "$src_copy"
        if [[ -n $UPLOADS_DIR ]]; then
            [[ -d $UPLOADS_DIR ]] || die "uploads dir not found: $UPLOADS_DIR"
            run cp -a "$UPLOADS_DIR/." "$TARGET/storage/uploads/"
            run chown -R "$APP_USER:$APP_USER" "$TARGET/storage/uploads"
            ok "copied uploads from $UPLOADS_DIR"
        fi
    else
        if interactive || $DRY_RUN; then
            echo "  Creating the admin user (prompts follow)…"
            as_app php "$TARGET/scripts/migrate.php"
        else
            as_app php "$TARGET/scripts/migrate.php" </dev/null || true
            note "schema created; no admin yet — run interactively: sudo -u $APP_USER php $TARGET/scripts/migrate.php"
        fi
    fi
fi

# --- 5. verify -----------------------------------------------------------------
stage "Verify"
if $DRY_RUN; then
    note "skipped (dry run)"
else
    if curl -fsS --max-time 10 http://127.0.0.1/healthz >/dev/null; then ok "/healthz"; else note "/healthz failed — see /srv/lifedash/app/storage/logs/php-error.log"; fi
    if runuser -u "$APP_USER" -- "$VENV/bin/lifedash-healthcheck" >/dev/null; then
        ok "lifedash-healthcheck"
    else
        note "lifedash-healthcheck reported warn/fail: sudo -u $APP_USER $VENV/bin/lifedash-healthcheck"
    fi
    ok "$(systemctl list-timers 'lifedash-*' --no-legend | wc -l) scheduled timers active"
fi

echo
echo "${B}Done.${N}"
if [[ -n $UI_HOST && $SKIP_TLS == false && -d /etc/letsencrypt/live/$UI_HOST ]]; then
    LAN_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<NF;i++) if ($i=="src") print $(i+1); exit}')
    echo "  Open: https://$UI_HOST"
    echo "  UDM:  add a local DNS record  $UI_HOST → ${LAN_IP:-<server LAN IP>}  (if you haven't yet)"
else
    echo "  Open: http://$(hostname -I 2>/dev/null | awk '{print $1}')/   (HTTP only — re-run with --ui-host for HTTPS)"
fi
echo "  Updates later:   sudo lifedash-deploy     (pulls from GitHub — root needs repo read access)"
echo "  Secrets:         sudo lifedash-env edit"
echo "  Offsite backups: see README → Server setup → Offsite backups"
