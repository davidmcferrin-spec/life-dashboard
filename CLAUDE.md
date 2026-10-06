# CLAUDE.md — life-dashboard

AI assistant context file. Keep this up to date as the project evolves.

## What This Is

Self-hosted live dashboard for David (broadcast engineer, travels ~60%). Evolved from
`personal-dashboard` (Dreamhost + SQLite) into a home-server app that will add life tracking,
an AI second brain, and an MCP server so Claude and Grok can read, update and build widgets.

Home server: **Minisforum AI X1 Pro 470 · Ubuntu Server 26.04 LTS · Apache (event) → PHP-FPM 8.5 ·
PostgreSQL 18 · Python 3 services**. UI on the LAN over HTTPS (`dash.<domain>`, Let's Encrypt via
Cloudflare DNS-01, local DNS record on the UniFi UDM); remote access via UniFi Teleport. Public
Cloudflare Tunnel exposes only `/healthz` and `/mcp`. Tailscale is optional (`--with-tailscale`).

## Current Phase — life-dashboard (L-phases)

L-phases are numbered separately from the legacy personal-dashboard Phases 1–8 below.

**L0 — Foundation ✅ (Sept 2026)**
- [x] `DB_DRIVER=pgsql` in `Database` (Unix-socket peer auth via `DB_PGSQL_SOCKET_DIR`, session TZ forced UTC, bool params → 0/1)
- [x] PostgreSQL migrations in `database/pgsql/NNNN_name.sql` (baseline = legacy 001–042 final state); advisory-locked runner in `Schema::runPgsqlPending()`
- [x] Web requests no longer auto-migrate on pgsql (`AUTO_MIGRATE=false`); `lifedash-deploy` migrates
- [x] SQLite-only `datetime()` queries replaced with `Support\SqlTime` cutoffs (also fixed the flight poll window, which compared APP_TIMEZONE wall clock to UTC)
- [x] `scripts/migrate-sqlite-to-pgsql.php` + `DB\SqliteToPgsqlImporter` (preflight → single-transaction import → sequence reset → count verify)
- [x] `scripts/sql-lint-pgsql.php` + `DB\SqlStatementExtractor` — PREPAREs every SQL string in the codebase against PostgreSQL
- [x] `GET /healthz` (unauthenticated, up/down only); `/settings/system` shows PostgreSQL version/size/pending migrations
- [x] HTTPS on the LAN: `deploy/apache/lifedash.conf.template` rendered by `lifedash-render-vhost` (:443 UI, :80 redirects all but loopback/tunnel); settings persisted in `/etc/lifedash/provision.conf`
- [x] `lifedash-perms` (ownership model) + `lifedash-env set KEY VALUE`; setup.sh syncs `APP_URL`/`SESSION_SECURE` with HTTP/HTTPS mode
- [x] First real run on the box (26.04.1, PHP 8.5.4, PG 18.6, huge pages on, TPM present) surfaced two permission bugs, fixed: Apache 403 (no o+x on /srv/lifedash) and storage/ subdirs from git owned by root
- [x] `setup.sh` (repo root): one-command install — place app, build/encrypt `.env`, provision, DB init (fresh or SQLite import), verify; `--dry-run`, `--yes`
- [x] `deploy/` kit: `provision.sh`, Apache vhost, FPM pool, PG tuning + huge pages, systemd timers (replace crontab), `lifedash-{deploy,env,backup,job,tunnel-setup}`
- [x] Secrets via `systemd-creds` → `/run/lifedash/app.env`; `.env` is a symlink to it on the server
- [x] Python package `services/lifedash` (config parity with PHP `Config`, psycopg db, JSON logging, `lifedash-healthcheck`)
- [x] Tests: PHPUnit unit + PostgreSQL integration, pytest, `tests/smoke/smoke_http.py`; GitHub Actions CI

**L1 — Core platform + habits/mood ✅ (Sept 2026)**
- [x] Migration `0003_metrics_widgets_realtime`: `metrics`, `datapoints`, `dashboard_widgets`, `widget_revisions`; trigger `lifedash_notify()` → `pg_notify('lifedash_events', {table, op, id, user_id[, metric_id]})`
- [x] `Tracking\{MetricService, DatapointService, HabitService, Services}`; `Support\Clock` (UTC storage, `APP_TIMEZONE` local dates, injectable "now" for tests)
- [x] `Widgets\WidgetTypeRegistry` (strict config schemas — reused by L2 MCP), `WidgetService` (grid CRUD, versioned config + rollback, layout save, one-time seed from legacy prefs), `WidgetDataService` (server-side widget payloads)
- [x] `/api/v1` (`Api\V1\ApiV1`): metrics, datapoints, series, habits, widget types, widgets, revisions/rollback, layout, realtime token
- [x] Dashboard rebuilt on GridStack 12.6.0 (vendored): Edit layout → drag/resize/add/configure/remove; history tab restores old configs; 1 column < 700 px (edit disabled there)
- [x] Native widgets: habits, mood, stat, chart (SVG line/bar), gauge, table, streak (`lifedash-renderers.js`); legacy widgets are singleton `legacy.*` types wrapping `templates/partials/widgets/*.php`
- [x] `/settings/trackers` — create/edit/reorder/archive habits and other metrics; mood (1–5) auto-created per user
- [x] Realtime: `services/lifedash/push.py` (`lifedash-push.service`, 127.0.0.1:8766, Apache `/ws`) — HMAC ticket as first frame, per-user fan-out, resync after DB reconnect; browser falls back to 60 s polling
- [x] Old widget visibility/columns/height settings removed (grid is the source of truth); `dashboard-widgets.js` deleted

**L2 — MCP v1 ✅**
- [x] `api_tokens` (SHA-256, scopes, prefix) and `audit_events` (actor, tool, resource) with `lifedash_notify`
- [x] `/api/v1` accepts a browser session (CSRF) or `Authorization: Bearer`; scopes inferred from the path; bearer writes use `source=ai`
- [x] `services/lifedash/mcp_server.py` (`lifedash-mcp`, `127.0.0.1:8765`, tunnel path `/mcp`) forwards tools to `/api/v1` with `X-Lifedash-Tool`
- [x] `GET /api/v1/agent-prompt` and `/settings/agents` (token shown once, prompt copy, audit trail)

**L3 — Life tracking ✅**
- [x] Seeded metrics `sleep_hours`, `steps`, `exercise_min` (seconds), `weight`, `travel_day`
- [x] `POST /api/v1/life/ingest`, Health Connect shape, Home Assistant webhook `/api/v1/life/hook/{token}`
- [x] `life` widget and `/settings/life`

**L4 — Second brain ✅**
- [x] `pages`, `page_revisions`, `page_links`, `page_embeddings` (JSON vectors; full-text via `to_tsvector`)
- [x] `/brain` editor (Markdown, wikilinks, callouts, tree, backlinks, revision restore) and `/brain/graph` (nodes light up from `audit_events`)
- [x] Page MCP tools. Optional `OLLAMA_URL` embeds on save. Agents may also `POST …/embedding`

**L5 — Money ✅** · **L6 — Investing (read-only) ✅** · **L7 — Custom widgets ✅**
- [x] Accounts, signed transactions, Monarch-style CSV, spending + net-worth widgets, weekly review page
- [x] Positions, Robinhood activity CSV, public Polymarket data-API refresh, scorecard widget. No trade placement
- [x] Widget type `custom`: sandboxed srcdoc, `window.__DATA__.metrics`

## Conventions (life-dashboard)

- **New schema changes go in `database/pgsql/NNNN_snake_case.sql`** (next free number, one transaction per file). Never edit an applied file. The legacy `Schema::migrations()` array is frozen (SQLite path only).
- PostgreSQL types: `BIGINT GENERATED BY DEFAULT AS IDENTITY` ids, `SMALLINT` 0/1 flags (app compares with ints), `TIMESTAMP(0)` UTC with default `(now() AT TIME ZONE 'UTC')`, `TEXT`/`VARCHAR(n)`; use `JSONB` for new structured columns.
- No SQLite-only SQL (`datetime()`, `INSERT OR IGNORE`, `PRAGMA`) in app code. Compute time cutoffs with `SqlTime`. Run `php scripts/sql-lint-pgsql.php` before committing SQL changes; mark intentional driver-specific SQL with `// sql-lint:ignore`.
- Time storage: `CURRENT_TIMESTAMP` columns are UTC; `user_flight_watches.departure_at` is APP_TIMEZONE wall clock.
- Python services read the same `.env` with the same parsing rules (`lifedash.config`); file values win over process env (matches PHP).
- **Tracking data** goes through `Tracking\*` services, never raw SQL from templates/handlers. Every datapoint carries `source` (manual|widget|api|ai|integration|import); integrations must set a stable `dedupe_key` so re-syncs upsert. Habits are forced to `dedupe_key = day:<local_date>`.
- **Dates vs timestamps:** `datapoints.ts` is UTC; `datapoints.local_date` is the `APP_TIMEZONE` calendar day and is what streaks, buckets and "today" use. A date-only entry for today is stamped "now", past dates at local noon.
- **New widget type:** add it to `WidgetTypeRegistry::types()` (fields = the config schema), a payload branch in `WidgetDataService::forWidget()` that always includes `metric_ids`, and a renderer in `lifedash-renderers.js`. Legacy-style server-rendered widgets use a `legacy.<name>` singleton type + `templates/partials/widgets/<name>.php`.
- **Widget config is versioned:** change it only through `WidgetService::update()` / `rollback()` (writes `widget_revisions`); positions (`saveLayout`) do not create revisions.
- **Realtime:** anything that should refresh open dashboards must change a table with the `lifedash_notify` trigger (metrics, datapoints, dashboard_widgets); add the trigger to new tables that widgets display. Ticket format is pinned by `tests/Unit/RealtimeTokenTest.php` and `services/tests/test_push.py` (same golden vector).
- New UI styles for the grid/widgets go in `public/assets/css/lifedash.css` (plain CSS with theme tokens) — Tailwind is prebuilt, so classes not already in `tailwind.css` won't exist. Bump `$assetVer` in `templates/partials/head.php` when shipping JS/CSS changes.
- Scheduled work = a `lifedash-job` case + a `deploy/systemd/lifedash-job-<name>.timer`; logs go to `storage/logs/<name>.log` (listed in `SystemHealthService::JOBS`).

## Testing

```bash
vendor/bin/phpunit --testsuite unit
LIFEDASH_TEST_PG_DB=lifedash_test LIFEDASH_TEST_PG_PASS=... vendor/bin/phpunit   # DB name must end in _test (wiped)
php scripts/sql-lint-pgsql.php
cd services && LIFEDASH_TEST_PG_DB=... LIFEDASH_TEST_PG_PASS=... pytest -q   # DB vars enable the live push round-trip
python3 tests/smoke/smoke_http.py --base URL --user U --password-env PW [--write]   # --write also exercises /api/v1
shellcheck setup.sh deploy/provision.sh deploy/bin/*
```

## Legacy phases (personal-dashboard)

**Phase 1 + 2 + 3 complete**
- Auth, DB, Router, Config, Bootstrap ✅
- Dashboard layout: timezones, search, widgets, news feed ✅
- RSS cron fetcher ✅
- Weather API endpoint ✅

**Phase 4 complete**
- [x] Cloud clipboards v1 (widget + `/settings/clipboards`, live sync)
- [x] Flight watches — OpenSky default, FlightAware optional (`FLIGHT_PROVIDER`)
- [x] System status pinger (TCP/HTTP checks)
- [x] Stocks widget (Alpha Vantage, user symbols)

**Phase 5 complete**
- [x] Settings UI (`/settings`) — search engine, feed toggles, widget visibility, widget columns (auto/1–6), widget height, widget order
- [x] Token management UI (`/settings/tokens`) — create/revoke/regenerate/expiry
- [x] Bookmark manager (folders + links on settings page)
- [x] `GET/POST /api/preferences` (search engine + theme sync)
- [x] Appearance: light / dark / system (CSS variables, nav switcher + settings)
- [x] Optional broadcast week (EPOCH) under timezone clocks (`show_broadcast_week`, default off)

**Phase 6 complete**
- [x] Package tracking — UPS/FedEx/DHL live APIs; USPS/Amazon/OnTrac/LaserShip link-only
- [x] Focus clock — `/clock`, `/settings/clock`, `Alt+Shift+C` from dashboard, tab-visible alarm + 30 min prefs poll
- [x] Cloud files — local tier + optional Dropbox / S3 (`/settings/clipboards?tab=files`)
- [x] Share link purge — daily cron removes expired public links after `SHARE_LINK_PURGE_HOURS` grace (`cron/prune_share_links.php`)

**Phase 7 complete**
- [x] Todo lists — grid widget, `/todos` full page, `/settings/todos` list + share manager

**Phase 8 complete**
- [x] CloudPad — Excalidraw canvas on clipboards **CloudPad** tab + `/cloudpad/canvas/{id}`, control lock, sharing, PNG export

## Todo lists

- Tables: `user_todo_lists`, `user_todo_items`, `todo_list_user_shares`
- Default lists per user: **Work**, **Personal**, **Home** (all pinned to dashboard tabs by default) — seeded once (`defaults_seeded_todos` / migrate `seedDefaultTodoLists`)
- Item status: `open` → `completed` → `archived` (archived hidden from widget; recoverable on `/todos`)
- Optional **due date** per task (`due_date` DATE); urgency on open tasks only — ≤2 days: orange row; today/overdue: red row + 🚩; sorted by due date then sort order
- Widget: checkbox completes; **click title** opens edit modal (title, due date, notes, links, assign); list owners get full fields; assignees can edit their task title/notes/links
- Completed tab: **Mark incomplete** returns task to `open`
- Optional details per task on full page only: notes + links (progressive disclosure — **Add details**)
- Share list with dashboard users (`read` = view + complete assigned tasks; `write` = full edit)
- Assign tasks to list owner or share recipients
- Widget: list **tabs** (up to 4 pinned via `/settings/todos`, else first 4 lists), open tasks, **+** opens add modal; **View all** → `/todos`
- Limits: `TODO_MAX_LISTS_PER_USER` (10), 100 open tasks/list, `TODO_WIDGET_MAX_ITEMS` (8), `TODO_WIDGET_MAX_TABS` (4)
- API: `GET/POST /api/todos/*`, `GET /api/todos/widget`, `GET /api/todos/assigned`

## Focus clock

- Pref: `user_preferences.focus_clock` (JSON: timezone, `timezone_auto`, format, font, colors, scale, alarm)
- Page: `GET /clock` — top nav retained; large clock in remaining viewport; uses `DashboardTime` + `server-time.js` (5 min resync)
- Shortcut: **Alt+Shift+C** on dashboard (not in search input); **Escape** or **Alt+Shift+C** on `/clock` → dashboard
- Settings: `/settings/clock` (sidebar **Focus Clock**); dashboard timezone bar stays on `/settings`; custom ring `POST /api/clock/alarm-sound`
- On `/clock`, nav **Settings** → `/settings/clock`; red alarm bell in nav when alarm enabled (polled every 30 min + on tab focus)
- `show_seconds` preference for focus clock display
- `timezone_auto` — use browser IANA zone via `Intl` (`focus-clock-tz.js`); manual dropdown kept as fallback
- Alarm: `/clock` only (visible tab); no catch-up; dismiss + snooze 5 min; `clock-alarm.js` on `/clock` (+ settings for preview)
- Alarm sounds: built-in tone, **shared library** (`alarm_sounds` table + `storage/uploads/alarm/shared/`), or **personal** (`storage/uploads/alarm/user/{userId}.ext`); `GET /api/clock/alarm-sounds`, stream `GET /api/clock/alarm-sound/mine` or `/{id}`; uploads default to shared (`scope=shared|mine`)
- Font: DSEG7 Classic woff2 from [npm `dseg@0.46.0`](https://www.npmjs.com/package/dseg) in `public/assets/fonts/dseg7/` (not GitHub raw — repo ships sources only); mono fonts via Google Fonts on `/clock`

## Package tracking

- Table: `user_package_watches` (max 10 active per user, `PACKAGE_MAX_PER_USER`)
- Cron: `cron/fetch_packages.php` every **2 hours** (`lifedash-job-packages.timer`); log `storage/logs/packages.log`; visible in `/settings/system`
- Poll: active in-transit watches only (`PACKAGE_POLL_MINUTES`, default 120); **link-only carriers are not API-polled**
- Live providers: `UpsPackageProvider`, `FedExPackageProvider`, `DhlPackageProvider` (OAuth/file-cached tokens like OpenSky)
- Link-only: `LinkOnlyPackageProvider` for **USPS, Amazon, OnTrac, LaserShip** — status label + carrier deep link; optional `external_url` override (useful for Amazon orders)
- Auto-detect (`carrier=auto`): UPS `1Z…`, Amazon `TBA…`, LaserShip `1LS`/`LX…`, OnTrac `C`/`D`+digits, USPS 20–22 digit / `420` IMpb (checked before FedEx numeric heuristic)
- UI: dashboard widget + `/settings/packages` manager; legacy `/packages` redirects
- Env (live only): `UPS_CLIENT_*`, `FEDEX_CLIENT_*`, `DHL_API_KEY`; `PACKAGES_SANDBOX` / `UPS_USE_SANDBOX` / `FEDEX_USE_SANDBOX` for dev
- Delivered packages auto-complete; rows purged after `PACKAGE_PURGE_DAYS` (default 7)
- Default seed: one inactive “Replace with your tracking #” row per user (`defaults_seeded_packages`)

## Flight watches

- Table: `user_flight_watches`
- Poll window: from **24h before `departure_at`** until complete (`cron/fetch_flights.php`, `FLIGHT_POLL_MINUTES`)
- Provider: `Dashboard\Flight\FlightStatusProvider` — `OpenSkyFlightProvider` | `FlightAwareFlightProvider`
- UI: dashboard widget + `/settings/flights` manager; legacy `/flights` redirects
- Defaults (editable/deletable): AA100, UA500, DL200 with future departure dates
- ADS-B only on OpenSky — not airline gate/delay unless FlightAware is configured

## Cloud clipboards

- Table: `user_clipboards`; share tables `clipboard_share_links`, `clipboard_user_shares`
- `/settings/clipboards` (**Boards** | **Files** | **CloudPad** tabs) + pinned dashboard widget; legacy `/clipboards` redirects
- Plain text boards with versioned sync, public share links, and in-app user shares — covers scratchpad-style notes (no separate `notes` table)
- **Edit | Preview** — storage stays plain text; client-side Markdown preview (headings, lists, code, links) on settings page, dashboard widget, and public share links (`clipboard-markdown.js`)
- Public share links with expiry are purged from the DB `SHARE_LINK_PURGE_HOURS` (default 24) after `expires_at`; cron `cron/prune_share_links.php` daily
- **Files tab** on same page — private file storage (see below)

## Cloud files (clipboards → Files tab)

**Local tier** (always available):
- Tables: `user_files`, `file_share_links`, `file_user_shares`
- Storage: `storage/uploads/files/{userId}/{storageId}.{ext}` — streamed via PHP, not public static URLs
- Types: JPEG, PNG, GIF, WebP, ZIP, EXE, MSI (MIME + magic-byte validation)
- Limits: `FILES_MAX_BYTES` (50 MB default), `FILES_MAX_PER_USER` (25), `FILES_QUOTA_BYTES` (500 MB)

**Cloud tier** (optional — one active provider per user):
- Providers: Dropbox OAuth (PKCE, per-user app credentials on Files tab), S3-compatible (per-user credentials form)
- Tables: `user_storage_connections`, `user_storage_nodes`, `storage_node_share_links`, `storage_node_user_shares`
- Default folder: `/PersonalDashboard` (`CLOUD_ROOT_FOLDER_NAME`); explorer UI with breadcrumbs on Files tab
- Upload defaults to cloud when connected; local tier remains as fallback
- Cloud paths are chrooted to the root folder server-side (`CloudPathGuard`)
- On connect: prompt to migrate local files (share links re-pointed to cloud nodes)
- On disconnect: revoke encrypted tokens only; files stay in provider; share links pause until reconnect
- Dropbox: each user saves app key/secret encrypted (`user_preferences`, key `dropbox_app_credentials`); admin UI `/settings/cloud-storage` sets shared folder name + quota only
- Env: `CLOUD_ROOT_FOLDER_NAME`, `CLOUD_FILES_QUOTA_BYTES`; OAuth callback `{APP_URL}/api/storage/oauth/callback/dropbox`
- API: `GET/POST /api/storage/*` (browse, upload, folder, download proxy, shares, migrate-local, disconnect)

**Sharing (both tiers):**
- Private by default; owner can create public download links or share with dashboard users (download only)
- Expired public link rows purged daily (`cron/prune_share_links.php`, `SHARE_LINK_PURGE_HOURS` after `expires_at`)
- Only the uploader can delete; shared users see files under **Shared with me**
- Public share: `/share/file/{token}` — resolves local or cloud link; download `GET /api/share/file/{token}/download`
- Local API: `GET/POST /api/files/*`; multipart upload `POST /api/files/upload`

## CloudPad

Separate from cloud clipboards — infinite canvas (Excalidraw) for sketch, flow, brainstorm. No dashboard widget (settings tab + full-screen editor only).

- Tables: `user_cloudpad_canvases`, `cloudpad_share_links`, `cloudpad_user_shares`
- UI: **CloudPad** tab on `/settings/clipboards?tab=cloudpad` (inline embed) · full-screen `/cloudpad/canvas/{id}` · legacy `/settings/cloudpad` redirects to clipboards tab
- Max `CLOUDPAD_MAX_CANVASES` (default 10); self-hosted Excalidraw bundle (`npm run build:cloudpad`), versioned JSON save, control lock, sharing panel
- Limits: `CLOUDPAD_MAX_CONTENT_BYTES` (3 MB default); control lock `CLOUDPAD_CONTROL_LOCK_SECONDS` (90s stale); lock timestamps stored/compared in UTC (`gmdate`)
- Sharing: in-app read/write; **public links read-only only** (`/share/cloudpad/{token}`)
- Control lock: take / request / grant / deny / release / heartbeat; owner force-take; only holder may save
- Near-time: poll control + `content_version` (~3s); poll-only banners (no push)
- Export: client PNG download + `POST /api/files/upload` (Files tab on cloud clipboards)
- API: `GET/POST /api/cloudpad/*`, `GET /api/share/cloudpad/{token}`
- Build: `npm run build:cloudpad` (esbuild → `public/assets/js/*.bundle.js` + `public/assets/excalidraw-assets/`); styles ship inside the bundle; set `window.EXCALIDRAW_ASSET_PATH = '/assets/'` before load (Excalidraw appends `excalidraw-assets/` itself)

## RSS news feeds

- Tables: `feed_sources`, `user_feeds`, `feed_cache`
- Cron: `cron/fetch_feeds.php` every 30 min — polls **all** `feed_sources` (built-in + every user's custom feeds); log `storage/logs/feeds.log`
- Cache prune: `RSS_CACHE_MAX_AGE_DAYS` (default 14) deletes rows where `COALESCE(published_at, fetched_at)` is older; count-based prune also keeps ~30 rows/source
- API: `GET /api/feeds?scope=dashboard|reader` — cron-populated cache, no runtime fetch
  - **dashboard** — articles from sources pinned on dashboard (`user_feeds.enabled = 1`)
  - **reader** — all built-in sources + this user's custom feeds
- **Dashboard widget** — shown only when user has ≥1 dashboard pin; category/source filters (shared with `/news` via `sessionStorage`); Manage → `/settings/feeds`, View all → `/news`
- **News reader** (`/news`) — all sources + same category/source filters; filter chips from API `sources` array
- Settings: `/settings/feeds` — pin built-in or custom sources to dashboard (grouped by category); add custom feeds up to `RSS_MAX_CUSTOM_FEEDS`; sidebar link **News reader →**
- New users: `seedDashboardPins()` inserts pins for built-in `is_default = 1` sources; new custom feeds pin on add
- Categories on `feed_sources.category`: `tech`, `developer`, `mobile`, `hardware`, `enterprise`, `microsoft`, `broadcast`, `news`, `ev`, `ai`, `custom`; provider = `source_slug` / `source_name`
- Built-in seeds in `Schema::seedFeedSources()` — run `php scripts/migrate.php` after deploy (`repairFeedCategories()` updates existing rows)
- **Mainstream news** (CBS, ABC, NBC, Fox, CNN, Newsweek, Washington Examiner, WSJ, BBC World): `is_default = 0` — available on `/news`
- **Microsoft** (Windows Central, Petri, Thurrott, Neowin, Ask Woody, Scott Hanselman): `is_default = 0`
- **Enterprise IT** (TechRepublic, IT Pro, TechRadar Pro): IT Pro + TechRadar Pro default on dashboard; TechRepublic opt-in
- **Developer** (XDA Developers, DevNet Academy): XDA default; DevNet opt-in
- **Mobile** (SamMobile, MacRumors): default on dashboard
- **PC Hardware** (Tom's Hardware, AnandTech): Tom's default; AnandTech archive-only, opt-in
- **Tesla/EVs** (Not a Tesla App, Teslarati, Electrek, InsideEVs): `is_default = 0` — opt-in on `/settings/feeds` (Tesla blog has no public RSS)
- **AI/Development** (Anthropic News, Pragmatic Engineer, GitHub Blog, Cursor Changelog, GetStream AI News, xAI News): `is_default = 0`; Anthropic/xAI/Cursor use community proxy feeds where publishers ship no native RSS
- Default dashboard seed: tech + developer + mobile + hardware + enterprise (IT Pro, TechRadar Pro) + broadcast + NewsNation

## Bookmarks

- Tables: `bookmark_folders`, `bookmarks` (`favicon_ext` + stored file under `storage/uploads/bookmark-icons/{userId}/`)
- UI: dashboard widget + `/settings/bookmarks` manager; legacy form POST routes retained
- API: `GET /api/bookmarks`, `POST /api/bookmarks/create`, folder CRUD, `GET /api/bookmarks/{id}/favicon`, `GET …/favicon/candidates`, `POST …/favicon/fetch`, `POST …/favicon/upload`, `GET /api/bookmarks/capture-token`, `POST /api/bookmarks/capture-from-page`
- Favicon resolution (public URLs): **server fetch** (SSRF-safe, HTML `<link rel="icon">` discovery) → **browser fetch** (same discovery client-side) → **DuckDuckGo/Google lookup** → **open icon URL in new tab** (save + upload) → manual upload
- Local/LAN URLs: server skipped (SSRF); browser fetch first; on failure **open `/favicon.ico` etc. in new tab** with candidate links; bookmarklet; manual upload
- Missing icons backfilled automatically when the bookmarks settings page loads
- Capture token: `user_preferences.bookmark_capture_token` (30-day JSON blob); embedded in per-user bookmarklet href
- Max `BOOKMARK_FAVICON_MAX_BYTES` (default 1 MB)
- Pref: `show_bookmark_icons` on `/settings` (dashboard widget only)

## Ping targets

- Table: `user_ping_targets` (max 10 per user)
- Cron: `cron/ping_targets.php`
- Defaults: 8.8.8.8, 1.1.1.1, google.com, dreamhost.com
- UI: dashboard widget + `/settings/pings` manager

## Settings IA

Shared chrome: `templates/settings/_shell-top.php`, `_nav.php`, `_shell-bottom.php`.

**User settings** (left sidebar, all users):
- `/settings` — Dashboard (theme, search engine, bookmark icons, timezone bar); widget layout is edited on the dashboard itself (**Edit layout**)
- `/settings/trackers` — Habits & trackers (metrics: habits, mood/scales, numbers, durations)
- `/settings/clock` — Focus clock style + alarm
- `/settings/stocks`, `/settings/bookmarks`, `/settings/feeds`, `/settings/tokens`
- `/settings/flights`, `/settings/packages`, `/settings/clipboards` (Boards · Files · CloudPad tabs), `/settings/todos`, `/settings/weather`, `/settings/pings`

**Administration** (sidebar section, `is_admin` only):
- `/settings/system` — cron health + logs
- `/settings/cloud-storage` — cloud file defaults (root folder name, quota)
- `/settings/users` — account management (`last_login_at`, `last_active_at` per user)

**Activity timestamps:** `last_login_at` on sign-in (password or auto-login token); `last_active_at` throttled to every 15 min on authenticated requests. Shown on `/settings` (self) and `/settings/users` (admin).

Dashboard top nav: **Settings** + Sign Out only (admin links live in settings sidebar).
Each dashboard widget **Manage** link → matching `/settings/*` page. Dashboard **Edit layout** → drag ⋮⋮, resize from edges/corners, ⚙ configure (+ History tab to restore), ✕ remove (click twice), **+ Add widget**.
Legacy URLs `/flights`, `/packages`, `/clipboards` redirect to settings routes.

## Stocks

- Table: `user_stock_symbols` (max 10 per user; `pin_to_dashboard` for widget display)
- Widget: up to 10 pinned symbols (`STOCK_WIDGET_MAX_SYMBOLS`); compact single-line rows; if none pinned, first 10 by sort order
- Cron: `cron/fetch_stocks.php` (13s between symbols; ~25 API calls/day on free tier)
- Defaults: SPY, QQQ, DIA, AAPL, MSFT, GOOGL, AMZN, NVDA (one-time seed per user via `user_preferences`); all 8 pinned on seed
- Settings: `/settings/stocks` — “Show on dashboard” checkbox; `POST /api/stocks/{id}/update` with `pin_to_dashboard`
- Migrate: `seedStocksDashboardPins()` pins up to widget max for existing users with no pins

## Widget default seeds (per user)

- Flags in `user_preferences`: `defaults_seeded_flights`, `defaults_seeded_stocks`, `defaults_seeded_pings`, `defaults_seeded_clipboards`, `defaults_seeded_packages`
- Seeded once per user on first use or account creation; clearing all rows does not re-insert demos
- `php scripts/migrate.php` backfills flags for existing users

## Key Technical Decisions

| Decision | Choice | Reason |
|---|---|---|
| Database | PostgreSQL 18 (SQLite kept as legacy import source) | JSONB, LISTEN/NOTIFY, pgvector for later phases; peer auth, no password |
| Routing | Front controller via `public/index.php` | Clean URLs, single entry point |
| Auth | Session + HMAC hash + auto-login tokens | Secure, no external deps |
| CSS | Tailwind CLI → committed `public/assets/css/tailwind.css` + `dashboard.css` | Build on dev machine only; server never runs npm |
| RSS | PHP job (systemd timer) → `feed_cache` → JSON API | No runtime latency on page load |
| Scheduling | systemd timers + `lifedash-job@.service` | Replaces Dreamhost crontab; hardened, logged, `Persistent=` catch-up |
| Secrets | `systemd-creds` (TPM2) → tmpfs | Stolen disk yields ciphertext; no boot prompt (unattended while travelling) |
| Remote access | LAN HTTPS + UniFi Teleport (UI, SSH) · Cloudflare Tunnel (`/healthz`, `/mcp` only) | UI never public; Claude.ai/Grok need a public MCP URL; Cloudflare-registered domain serves both cert (DNS-01) and tunnel |
| TLS | certbot + `dns-cloudflare`, token in `/etc/lifedash/cloudflare.ini` | Valid cert for a LAN-only name; no public A record needed |
| Widget grid | GridStack 12.6.0, vendored in `public/assets/vendor/` | MIT, no build step, touch support; server stores x/y/w/h on a 12-column grid |
| Realtime | PG `LISTEN/NOTIFY` → Python `websockets` service behind Apache `/ws` | No broker to run; triggers make every writer (PHP, Python, AI) push automatically |
| Charts | Hand-rolled SVG in `lifedash-renderers.js` | Tiny, themeable, no dependency; L7 custom widgets can bring their own |
| Weather | OWM free tier + 10-min file cache | Stays under 1000 calls/day limit |
| Flights | OpenSky OAuth2 + optional FlightAware | Free ADS-B; pivot via env |
| Packages | Direct UPS/FedEx/DHL APIs | Privacy-first; USPS/Amazon/OnTrac/LaserShip link-only |
| HTTP client | PHP streams (`Support\HttpClient`) | No curl extension required |

## Home server deployment

```text
/srv/lifedash/app        # git checkout (root:lifedash, read-only to the app)
/srv/lifedash/app/storage  # writable by lifedash (logs, cache, sessions, uploads)
/srv/lifedash/venv       # Python services venv
/etc/lifedash/           # app.env.cred (encrypted), backup.env, restic.pass
/run/lifedash/app.env    # decrypted secrets (tmpfs) ← /srv/lifedash/app/.env symlink
/var/backups/lifedash/   # nightly + pre-deploy pg_dumps
```

- PHP-FPM pool `lifedash` (`/run/php/lifedash.sock`) runs as `lifedash`; Apache vhost rendered from `deploy/apache/lifedash.conf.template` (`lifedash-render-vhost`)
- Service: `lifedash-push` (realtime WebSocket, `Restart=always`; installed/restarted by `provision.sh` and `lifedash-deploy`)
- Timers: `lifedash-job-{feeds,flights,pings}` 30/30/15 min, `packages` 2 h, `stocks` 06/12/18 CT, `shares`/`prune` daily, `lifedash-backup` 02:30 CT
- Install/re-provision: `sudo ./setup.sh` (re-runs reuse `/etc/lifedash/provision.conf`) · Deploy: `sudo lifedash-deploy` · secrets: `sudo lifedash-env edit` · tunnel: `sudo lifedash-tunnel-setup <host>`
- Legacy Dreamhost layout/crontab: see personal-dashboard repo history (`load-app.php` still supports it)

## Database Tables

`users`, `autologin_tokens`, `user_preferences`, `bookmark_folders`, `bookmarks`,
`feed_sources`, `user_feeds`, `feed_cache`,
`user_clipboards`, `clipboard_share_links`, `clipboard_user_shares`,
`user_files`, `file_share_links`, `file_user_shares`,
`user_storage_connections`, `user_storage_nodes`, `storage_node_share_links`, `storage_node_user_shares`,
`system_settings`,
`user_flight_watches`, `user_package_watches`, `user_ping_targets`, `user_stock_symbols`,
`alarm_sounds`,
`user_cloudpad_canvases`, `cloudpad_share_links`, `cloudpad_user_shares`,
`user_todo_lists`, `user_todo_items`, `todo_list_user_shares`,
`metrics`, `datapoints`, `dashboard_widgets`, `widget_revisions`,
`migrations`

PostgreSQL schema source of truth: `database/pgsql/*.sql` (applied names recorded in `migrations.name`).

## GitHub

Repo: `davidmcferrin-spec/life-dashboard` (private) — cloned from `personal-dashboard` with full history (Sept 2026).
