#!/usr/bin/env bash
# =============================================================================
# deploy/provision.sh — one-time (and re-runnable) server setup for life-dashboard
#
# Target: Ubuntu Server 26.04 LTS on the Minisforum AI X1 Pro 470.
# Run from the checkout at /srv/lifedash/app:
#
#   sudo /srv/lifedash/app/deploy/provision.sh [options]
#
# Options (remembered in /etc/lifedash/provision.conf, so re-runs need none):
#   --ui-host HOST         dashboard name, e.g. dash.example.com — enables HTTPS on :443
#                          with a Let's Encrypt certificate (Cloudflare DNS-01)
#   --acme-email EMAIL     Let's Encrypt account / expiry-notice address
#   --cf-token-file FILE   file holding a Cloudflare API token (Zone → DNS → Edit, this zone
#                          only); copied to /etc/lifedash/cloudflare.ini, FILE is left alone
#   --lan-cidr CIDR        LAN allowed to reach SSH/HTTP/HTTPS (default: auto-detected)
#   --allow-cidr CIDR      extra source range, repeatable — e.g. the UniFi Teleport subnet
#   --with-tailscale       also install Tailscale (off by default; Teleport covers remote access)
#   --skip-firewall        leave UFW alone
#   --skip-cloudflared     do not install cloudflared
#   --skip-tls             HTTP only, even if --ui-host is set (development)
#   --env-file FILE        encrypt FILE as the app .env (e.g. your Dreamhost .env, edited)
#
# Idempotent: every step checks current state first, so re-running after a
# failure or an upgrade is safe. Each step logs what it changed.
# =============================================================================
set -euo pipefail

APP_DIR=/srv/lifedash/app
BASE_DIR=/srv/lifedash
VENV=$BASE_DIR/venv
APP_USER=lifedash
DB_NAME=lifedash

PROVISION_CONF=/etc/lifedash/provision.conf
CF_INI=/etc/lifedash/cloudflare.ini

# Defaults, then remembered settings from a previous run, then command-line flags.
UI_HOST=""
ACME_EMAIL=""
LAN_CIDR=""
ALLOW_CIDRS=""
WITH_TS=false
SKIP_TLS=false
if [[ -f $PROVISION_CONF ]]; then
    # shellcheck source=/dev/null
    . "$PROVISION_CONF"
fi
SKIP_FW=false
SKIP_CF=false
ENV_FILE=""
CF_TOKEN_FILE=""
CLI_UI_HOST=false
CLI_SKIP_TLS=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ui-host)          UI_HOST=${2,,}; CLI_UI_HOST=true; shift 2 ;;
        --acme-email)       ACME_EMAIL=$2; shift 2 ;;
        --cf-token-file)    CF_TOKEN_FILE=$2; shift 2 ;;
        --lan-cidr)         LAN_CIDR=$2; shift 2 ;;
        --allow-cidr)       ALLOW_CIDRS="${ALLOW_CIDRS:+$ALLOW_CIDRS }$2"; shift 2 ;;
        --with-tailscale)   WITH_TS=true; shift ;;
        --skip-tailscale)   WITH_TS=false; shift ;;   # kept for older command lines
        --skip-tls)         SKIP_TLS=true; CLI_SKIP_TLS=true; shift ;;
        --skip-firewall)    SKIP_FW=true; shift ;;
        --skip-cloudflared) SKIP_CF=true; shift ;;
        --env-file)         ENV_FILE=$2; shift 2 ;;
        -h|--help)          sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 64 ;;
    esac
done
# A hostname given now re-enables TLS even if an earlier run was HTTP-only.
if $CLI_UI_HOST && ! $CLI_SKIP_TLS; then
    SKIP_TLS=false
fi

STEP=0
step() { STEP=$((STEP + 1)); echo; echo "==> [$STEP] $*"; }
info() { echo "    $*"; }
warn() { echo "    ! $*" >&2; }
die()  { echo "ERROR: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
step "Preflight"
[[ $EUID -eq 0 ]] || die "run with sudo"
# shellcheck disable=SC1091
. /etc/os-release
[[ ${ID:-} == ubuntu ]] || die "Ubuntu required (found ${ID:-unknown})"
[[ ${VERSION_ID:-} == 26.04 ]] || warn "tested for Ubuntu 26.04; this is ${VERSION_ID:-unknown}"
SCRIPT_APP_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
[[ $SCRIPT_APP_DIR == "$APP_DIR" ]] || die "clone the repo to $APP_DIR (script is running from $SCRIPT_APP_DIR)"
command -v systemctl >/dev/null || die "systemd required"
CIDR_RE='^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$'
for c in $LAN_CIDR $ALLOW_CIDRS; do
    [[ $c =~ $CIDR_RE ]] || die "not an IPv4 CIDR: $c"
done
if [[ -n $UI_HOST ]]; then
    [[ $UI_HOST =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]] || die "invalid --ui-host: $UI_HOST"
    if ! $SKIP_TLS; then
        [[ -n $ACME_EMAIL ]] || die "--acme-email is required with --ui-host (Let's Encrypt account)"
        [[ -n $CF_TOKEN_FILE || -f $CF_INI ]] || die "--cf-token-file is required the first time (Cloudflare DNS token)"
        [[ -z $CF_TOKEN_FILE || -s $CF_TOKEN_FILE ]] || die "token file empty or missing: $CF_TOKEN_FILE"
    fi
fi
install -d -m 0700 /etc/lifedash
# Remember non-secret settings for re-runs (the token lives only in $CF_INI).
{
    echo "# Written by deploy/provision.sh — settings reused on re-run"
    printf 'UI_HOST=%q\nACME_EMAIL=%q\nLAN_CIDR=%q\nALLOW_CIDRS=%q\nWITH_TS=%q\nSKIP_TLS=%q\n' \
        "$UI_HOST" "$ACME_EMAIL" "$LAN_CIDR" "$ALLOW_CIDRS" "$WITH_TS" "$SKIP_TLS"
} > "$PROVISION_CONF"
chmod 0600 "$PROVISION_CONF"
info "ok: $PRETTY_NAME, kernel $(uname -r)"
info "UI: ${UI_HOST:-<none — HTTP only>}  TLS: $([[ -n $UI_HOST ]] && ! $SKIP_TLS && echo yes || echo no)  Tailscale: $WITH_TS"

# -----------------------------------------------------------------------------
step "Packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q \
    apache2 \
    php-fpm php-cli php-pgsql php-sqlite3 php-mbstring php-xml php-curl php-intl php-zip php-gd \
    composer \
    postgresql postgresql-contrib \
    python3 python3-venv python3-dev \
    git curl ca-certificates gnupg jq unzip \
    restic rclone \
    certbot python3-certbot-dns-cloudflare \
    ufw fail2ban unattended-upgrades \
    tpm2-tools

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PG_VER=$(find /usr/lib/postgresql -mindepth 1 -maxdepth 1 -type d -printf "%f\n" | sort -V | tail -1)
info "PHP $PHP_VER, PostgreSQL $PG_VER"

# pgvector is needed from Phase 4 (semantic search). Non-fatal if the archive lacks it.
if apt-get install -y -q "postgresql-$PG_VER-pgvector"; then
    info "pgvector installed"
else
    warn "postgresql-$PG_VER-pgvector not in the Ubuntu archive — add the PGDG repo before Phase 4"
fi

# -----------------------------------------------------------------------------
step "App user and directories"
if ! id "$APP_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "$BASE_DIR" --shell /usr/sbin/nologin "$APP_USER"
    info "created system user $APP_USER"
fi
# Fresh clones are root:root 0755 — apply the real model (Apache needs o+x on the
# path and read on public/; the app needs storage/ writable, including subdirs from git).
# Refuses to continue if the chmod model dirties a tracked file.
bash "$APP_DIR/deploy/bin/lifedash-perms"
info "permissions applied (code root:lifedash read-only, storage/ writable, public/ readable by Apache)"
install -d -m 0700 /etc/lifedash /var/backups/lifedash
install -d -m 0755 /var/lib/lifedash

# -----------------------------------------------------------------------------
step "PostgreSQL $PG_VER (tuning + huge pages)"
PG_CONF_DIR=/etc/postgresql/$PG_VER/main
PG_DATA=/var/lib/postgresql/$PG_VER/main
[[ -d $PG_CONF_DIR ]] || die "cluster config not found at $PG_CONF_DIR"

RAM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
SB_MB=$(( RAM_MB / 4 )); (( SB_MB > 8192 )) && SB_MB=8192
EC_MB=$(( RAM_MB / 2 ))
render_pg_conf() {
    sed -e "s/@@RAM_GB@@/$(( RAM_MB / 1024 ))/" \
        -e "s/@@SHARED_BUFFERS@@/${SB_MB}MB/" \
        -e "s/@@EFFECTIVE_CACHE@@/${EC_MB}MB/" \
        -e "s/@@HUGE_PAGES@@/$1/" \
        "$APP_DIR/deploy/postgresql/lifedash.conf.template" > "$PG_CONF_DIR/conf.d/lifedash.conf"
}
install -d "$PG_CONF_DIR/conf.d"
render_pg_conf on

# Ask PostgreSQL itself how many 2 MB pages it needs (server must be stopped for -C).
systemctl stop "postgresql@$PG_VER-main"
NEEDED=$(runuser -u postgres -- "/usr/lib/postgresql/$PG_VER/bin/postgres" -D "$PG_DATA" \
    -c config_file="$PG_CONF_DIR/postgresql.conf" -C shared_memory_size_in_huge_pages 2>/dev/null || true)
if [[ ! $NEEDED =~ ^[0-9]+$ ]]; then
    NEEDED=$(( SB_MB / 2 + SB_MB / 20 + 64 ))
    warn "postgres -C failed; estimated $NEEDED huge pages"
fi
HUGEPAGES=$(( NEEDED + 32 ))   # small margin for extensions
echo "vm.nr_hugepages = $HUGEPAGES" > /etc/sysctl.d/60-lifedash-hugepages.conf
sysctl -q -p /etc/sysctl.d/60-lifedash-hugepages.conf
info "vm.nr_hugepages=$HUGEPAGES (allocated: $(awk '/HugePages_Total/ {print $2}' /proc/meminfo))"

if ! systemctl start "postgresql@$PG_VER-main"; then
    warn "PostgreSQL failed to start with huge_pages=on (memory fragmented?) — falling back to huge_pages=try"
    warn "Reboot later so the kernel can reserve the pages, then re-run this script."
    render_pg_conf try
    systemctl start "postgresql@$PG_VER-main"
fi
HP_STATUS=$(runuser -u postgres -- psql -Atqc "SHOW huge_pages_status" 2>/dev/null || echo unknown)
info "huge_pages_status=$HP_STATUS"
[[ $HP_STATUS == on ]] || warn "huge pages NOT in use — expect the Linux 7.0 PostgreSQL slowdown until fixed"

if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_roles WHERE rolname='$APP_USER'" | grep -q 1; then
    runuser -u postgres -- createuser --no-superuser --no-createdb --no-createrole "$APP_USER"
    info "created role $APP_USER (peer auth over the Unix socket — no password)"
fi
if ! runuser -u postgres -- psql -Atqc "SELECT 1 FROM pg_database WHERE datname='$DB_NAME'" | grep -q 1; then
    runuser -u postgres -- createdb --owner="$APP_USER" --encoding=UTF8 "$DB_NAME"
    info "created database $DB_NAME"
fi
runuser -u postgres -- psql -q -d "$DB_NAME" -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements"

# -----------------------------------------------------------------------------
step "PHP-FPM $PHP_VER pool"
install -m 0644 "$APP_DIR/deploy/php/lifedash-pool.conf" "/etc/php/$PHP_VER/fpm/pool.d/lifedash.conf"
install -m 0644 "$APP_DIR/deploy/php/99-lifedash.ini"    "/etc/php/$PHP_VER/fpm/conf.d/99-lifedash.ini"
install -d "/etc/systemd/system/php$PHP_VER-fpm.service.d"
install -m 0644 "$APP_DIR/deploy/systemd/php-fpm-lifedash.conf" "/etc/systemd/system/php$PHP_VER-fpm.service.d/lifedash.conf"
systemctl daemon-reload
"php-fpm$PHP_VER" -t 2>&1 | sed 's/^/    /'

# -----------------------------------------------------------------------------
step "TLS certificate (Let's Encrypt via Cloudflare DNS)"
TLS_READY=false
if [[ -z $UI_HOST || $SKIP_TLS == true ]]; then
    info "skipped (no --ui-host, or --skip-tls) — UI will be HTTP only"
else
    if [[ -n $CF_TOKEN_FILE ]]; then
        umask 077
        printf 'dns_cloudflare_api_token = %s\n' "$(tr -d '[:space:]' < "$CF_TOKEN_FILE")" > "$CF_INI"
        chmod 0600 "$CF_INI"
        info "Cloudflare token stored in $CF_INI (0600)"
    fi
    # DNS-01: the name never needs a public A record, so the LAN IP stays private.
    if certbot certonly --non-interactive --agree-tos --email "$ACME_EMAIL" \
            --dns-cloudflare --dns-cloudflare-credentials "$CF_INI" \
            --dns-cloudflare-propagation-seconds 30 \
            --keep-until-expiring --cert-name "$UI_HOST" -d "$UI_HOST" >/tmp/lifedash-certbot.log 2>&1; then
        TLS_READY=true
        info "certificate: /etc/letsencrypt/live/$UI_HOST (auto-renews via certbot.timer)"
    else
        warn "certbot failed — see /tmp/lifedash-certbot.log. Falling back to HTTP only; fix and re-run."
        warn "Common causes: token lacks Zone→DNS→Edit on this zone, or the domain is not on Cloudflare yet."
    fi
    install -d /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/lifedash-reload-apache.sh <<'HOOK'
#!/bin/sh
# Pick up renewed certificates without dropping connections.
systemctl reload apache2
HOOK
    chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/lifedash-reload-apache.sh
    systemctl enable --now certbot.timer >/dev/null 2>&1 || true
fi

# -----------------------------------------------------------------------------
step "Apache (event MPM + proxy_fcgi$($TLS_READY && echo ' + TLS'))"
a2dismod -q "php$PHP_VER" mpm_prefork 2>/dev/null || true
a2enmod -q mpm_event proxy proxy_fcgi proxy_http proxy_wstunnel setenvif rewrite headers remoteip expires deflate http2 macro >/dev/null
$TLS_READY && a2enmod -q ssl >/dev/null
rm -f /etc/apache2/sites-available/lifedash.conf.tmp
if $TLS_READY; then
    "$APP_DIR/deploy/bin/lifedash-render-vhost" "$UI_HOST" "$APP_DIR/deploy/apache/lifedash.conf.template" \
        > /etc/apache2/sites-available/lifedash.conf.tmp
else
    "$APP_DIR/deploy/bin/lifedash-render-vhost" "" "$APP_DIR/deploy/apache/lifedash.conf.template" \
        > /etc/apache2/sites-available/lifedash.conf.tmp
fi
mv -f /etc/apache2/sites-available/lifedash.conf.tmp /etc/apache2/sites-available/lifedash.conf
a2dissite -q 000-default default-ssl >/dev/null 2>&1 || true
a2ensite -q lifedash >/dev/null
sed -i 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf
echo "ServerName ${UI_HOST:-$(hostname -f 2>/dev/null || hostname)}" > /etc/apache2/conf-available/lifedash-servername.conf
a2enconf -q lifedash-servername >/dev/null
apache2ctl configtest

# -----------------------------------------------------------------------------
step "Secrets (systemd-creds)"
install -m 0644 "$APP_DIR/deploy/systemd/lifedash-secrets.service" /etc/systemd/system/
ln -sfn "$APP_DIR/deploy/bin/lifedash-env" /usr/local/sbin/lifedash-env
if [[ -n $ENV_FILE ]]; then
    "$APP_DIR/deploy/bin/lifedash-env" import "$ENV_FILE" >/dev/null || die "could not encrypt $ENV_FILE"
    info "encrypted $ENV_FILE → /etc/lifedash/app.env.cred (delete the plaintext: shred -u $ENV_FILE)"
fi
if tpm2_getcap properties-fixed >/dev/null 2>&1; then
    info "TPM2 present — credentials bound to this machine's TPM + host key"
else
    warn "no TPM2 visible — credentials use the host key only (enable fTPM in BIOS for stronger binding)"
fi
# .env is a symlink into tmpfs; the real file never touches the disk.
if [[ -e $APP_DIR/.env && ! -L $APP_DIR/.env ]]; then
    die "$APP_DIR/.env is a plain file — encrypt it with --env-file $APP_DIR/.env, then remove it"
fi
ln -sfn /run/lifedash/app.env "$APP_DIR/.env"
systemctl daemon-reload
if [[ -f /etc/lifedash/app.env.cred ]]; then
    systemctl enable --now lifedash-secrets.service
    info "secrets decrypted to /run/lifedash/app.env"
else
    warn "no /etc/lifedash/app.env.cred yet — create it: sudo lifedash-env import /path/to/.env  (template: .env.example)"
fi

# -----------------------------------------------------------------------------
step "Python services venv"
[[ -x $VENV/bin/python ]] || python3 -m venv "$VENV"
"$VENV/bin/pip" install --quiet --upgrade pip
"$VENV/bin/pip" install --quiet -e "$APP_DIR/services"
info "$("$VENV/bin/python" --version), lifedash $("$VENV/bin/python" -c 'import lifedash; print(lifedash.__version__)')"

# -----------------------------------------------------------------------------
step "Scheduled jobs + backups (systemd timers)"
install -m 0644 "$APP_DIR"/deploy/systemd/lifedash-job@.service "$APP_DIR"/deploy/systemd/lifedash-job-*.timer \
    "$APP_DIR"/deploy/systemd/lifedash-backup.service "$APP_DIR"/deploy/systemd/lifedash-backup.timer \
    /etc/systemd/system/
ln -sfn "$APP_DIR/deploy/bin/lifedash-backup" /usr/local/sbin/lifedash-backup
ln -sfn "$APP_DIR/deploy/bin/lifedash-deploy" /usr/local/sbin/lifedash-deploy
ln -sfn "$APP_DIR/deploy/bin/lifedash-tunnel-setup" /usr/local/sbin/lifedash-tunnel-setup
[[ -f /etc/lifedash/backup.env ]] || install -m 0600 "$APP_DIR/deploy/backup.env.example" /etc/lifedash/backup.env.example
systemctl daemon-reload
for t in feeds flights packages pings stocks shares prune media reddit; do
    systemctl enable --now "lifedash-job-$t.timer" >/dev/null
done
systemctl enable --now lifedash-backup.timer >/dev/null
info "timers: $(systemctl list-timers 'lifedash-*' --no-legend | wc -l) active"

# -----------------------------------------------------------------------------
step "Realtime push and MCP"
install -m 0644 "$APP_DIR/deploy/systemd/lifedash-push.service" /etc/systemd/system/
install -m 0644 "$APP_DIR/deploy/systemd/lifedash-mcp.service" /etc/systemd/system/
systemctl daemon-reload
if [[ -f /etc/lifedash/app.env.cred ]]; then
    systemctl enable lifedash-push.service >/dev/null
    systemctl restart lifedash-push.service
    info "lifedash-push: $(systemctl is-active lifedash-push.service)"
    systemctl enable lifedash-mcp.service >/dev/null
    systemctl restart lifedash-mcp.service
    info "lifedash-mcp: $(systemctl is-active lifedash-mcp.service)"
else
    systemctl enable lifedash-push.service >/dev/null
    systemctl enable lifedash-mcp.service >/dev/null
    warn "lifedash-push and lifedash-mcp enabled but not started (no secrets yet)"
fi

# -----------------------------------------------------------------------------
step "Self-update spool"
# shellcheck source=deploy/lib/deploy-guard.sh
source "$APP_DIR/deploy/lib/deploy-guard.sh"
lifedash_install_spool /var/lib/lifedash/deploy "$APP_USER"
install -m 0644 "$APP_DIR/deploy/systemd/lifedash-deploy.service" \
    "$APP_DIR/deploy/systemd/lifedash-deploy@.service" \
    "$APP_DIR/deploy/systemd/lifedash-deploy.path" /etc/systemd/system/
ln -sfn "$APP_DIR/deploy/bin/lifedash-deploy-from-spool" /usr/local/sbin/lifedash-deploy-from-spool
systemctl daemon-reload
systemctl enable --now lifedash-deploy.path >/dev/null
info "lifedash-deploy.path: $(systemctl is-active lifedash-deploy.path)"

# -----------------------------------------------------------------------------
step "CheckMK local check (if agent installed)"
if [[ -d /usr/lib/check_mk_agent/local ]]; then
    cat > /usr/lib/check_mk_agent/local/lifedash <<EOF
#!/bin/sh
exec runuser -u $APP_USER -- $VENV/bin/lifedash-healthcheck --checkmk
EOF
    chmod 0755 /usr/lib/check_mk_agent/local/lifedash
    info "installed /usr/lib/check_mk_agent/local/lifedash"
else
    info "no CheckMK agent — skipped"
fi

# -----------------------------------------------------------------------------
step "Tailscale (optional)"
if ! $WITH_TS; then
    info "not requested — remote access is via UniFi Teleport (use --with-tailscale to add it)"
else
    if command -v tailscale >/dev/null; then
        info "already installed ($(tailscale version | head -1))"
    else
        curl -fsSL https://tailscale.com/install.sh | sh
    fi
    if ! tailscale status >/dev/null 2>&1; then
        warn "Tailscale not logged in: run 'sudo tailscale up', then re-run this script"
    elif $TLS_READY; then
        # Apache owns :443; the tailnet is for SSH/admin. Reach the UI via https://$UI_HOST.
        info "tailnet up (SSH/admin); UI stays on https://$UI_HOST"
    else
        tailscale serve --bg --https=443 http://127.0.0.1:80 >/dev/null
        info "UI over tailnet: https://$(tailscale status --json | jq -r '.Self.DNSName' | sed 's/\.$//')"
    fi
fi

# -----------------------------------------------------------------------------
step "cloudflared"
if $SKIP_CF; then
    info "skipped"
elif command -v cloudflared >/dev/null; then
    info "already installed ($(cloudflared --version 2>&1 | head -1))"
else
    install -d -m 0755 /usr/share/keyrings
    curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg -o /usr/share/keyrings/cloudflare-main.gpg
    echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared any main" \
        > /etc/apt/sources.list.d/cloudflared.list
    apt-get update -q && apt-get install -y -q cloudflared
    info "installed — create the tunnel with: sudo lifedash-tunnel-setup <hostname>"
fi

# -----------------------------------------------------------------------------
step "Firewall (UFW) + fail2ban + unattended upgrades"
if $SKIP_FW; then
    info "firewall skipped"
else
    if [[ -z $LAN_CIDR ]]; then
        IFACE=$(ip -4 route show default | awk '{print $5; exit}')
        LAN_CIDR=$(ip -4 route show dev "$IFACE" proto kernel scope link | awk '{print $1; exit}')
    fi
    [[ $LAN_CIDR =~ ^[0-9.]+/[0-9]+$ ]] || die "could not detect LAN CIDR — pass --lan-cidr 192.168.1.0/24"
    sed -i "s|^LAN_CIDR=.*|LAN_CIDR=$(printf '%q' "$LAN_CIDR")|" "$PROVISION_CONF"
    ufw --force default deny incoming >/dev/null
    ufw --force default allow outgoing >/dev/null
    for src in $LAN_CIDR $ALLOW_CIDRS; do
        for port in 22 80 443; do
            ufw allow from "$src" to any port "$port" proto tcp comment "lifedash: $port from $src" >/dev/null
        done
    done
    if $WITH_TS && ip link show tailscale0 >/dev/null 2>&1; then
        ufw allow in on tailscale0 comment 'lifedash: tailnet' >/dev/null
    elif ! $WITH_TS; then
        ufw delete allow in on tailscale0 >/dev/null 2>&1 || true   # left by an earlier default install
    fi
    ufw --force enable >/dev/null
    info "UFW: deny inbound except 22/80/443 from: $LAN_CIDR${ALLOW_CIDRS:+ $ALLOW_CIDRS}$($WITH_TS && echo ' + tailscale0')"
    [[ -n $ALLOW_CIDRS ]] || warn "no --allow-cidr given: if UniFi Teleport clients get their own subnet, add it or remote access will be blocked"
fi
systemctl enable --now fail2ban >/dev/null
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null

# -----------------------------------------------------------------------------
step "Start services"
# Refuses to continue if the chmod model dirties a tracked file.
bash "$APP_DIR/deploy/bin/lifedash-perms"
systemctl enable --now postgresql >/dev/null
systemctl restart "php$PHP_VER-fpm"
systemctl reload apache2 || systemctl restart apache2
if curl -fsS --max-time 10 http://127.0.0.1/healthz >/dev/null; then
    info "/healthz ok"
else
    warn "/healthz failed — check /var/log/apache2/lifedash_error.log and $APP_DIR/storage/logs/php-error.log"
fi

echo
echo "================================================================"
echo " Provisioning complete."
echo "================================================================"
if [[ ! -f /etc/lifedash/app.env.cred ]]; then
    echo " NEXT: create secrets → sudo lifedash-env import /path/to/.env"
fi
LAN_IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1;i<NF;i++) if ($i=="src") print $(i+1); exit}')
cat <<EOF
 Then (first time, interactive — creates the admin user or imports data):
   sudo -u $APP_USER php $APP_DIR/scripts/migrate.php
   # or import your Dreamhost data — see README "Migrating from personal-dashboard"
 Verify:
   curl -s http://127.0.0.1/healthz
   sudo -u $APP_USER $VENV/bin/lifedash-healthcheck
EOF
if $TLS_READY; then
    cat <<EOF
 Dashboard: https://$UI_HOST
   → on the UDM add a local DNS record:  $UI_HOST  →  ${LAN_IP:-<server LAN IP>}
     (Settings → Routing → DNS, or Policy Engine → DNS, depending on UniFi OS version)
EOF
else
    echo " Dashboard: http://${LAN_IP:-<server-ip>}/  (HTTP only — set --ui-host/--acme-email/--cf-token-file for HTTPS)"
fi
cat <<EOF
 Offsite backups: copy /etc/lifedash/backup.env.example → backup.env, fill in, then
   sudo lifedash-backup init
 Public MCP endpoint (Phase L2), when ready:
   sudo lifedash-tunnel-setup mcp.<your-domain>
EOF
