# Agents

Self-hosted life dashboard. PHP renders pages, vanilla JS and plain CSS run in the browser, PostgreSQL stores data, and `services/` is the Python package (MCP, sync, realtime).

## Map

- `public/` — web root. `public/index.php` is the front controller. Vault client is `public/assets/js/brain.js`. Shared widget CSS is `public/assets/css/lifedash.css`. Feature sheets (`vault.css`, `vault-editor.css`, and the rest of `Stylesheets::app()`) load after it. Edit those in place; no build.
- `templates/` — PHP pages. The vault page is `templates/brain.php` (`#brain-title`).
- `src/` — PHP, namespace `Dashboard\`. Vault domain is `src/Knowledge/` (`PageService`, folders, wikilinks, redline). JSON API is `src/Api/`.
- `services/` — Python package `lifedash` (`mcp_server.py`, sync workers, realtime push). Tests live in `services/tests/`.
- `resources/` — Tailwind source (`resources/css/tailwind.css`). Commit the built file `public/assets/css/tailwind.css` after `npm run build:css`.
- `database/pgsql/` — versioned SQL migrations.
- `tests/` — PHPUnit under `tests/Unit` and `tests/Integration`. Vault JS checks are `tests/js/`.
- `scripts/` — migrate, seed, SQL lint. `deploy/` and `cron/` are for the home server.

## Run and test

```bash
composer install
vendor/bin/phpunit --testsuite unit
node --test tests/js/*.cjs
```

PostgreSQL integration tests need `LIFEDASH_TEST_PG_DB` ending in `_test`. Python: `cd services && pip install -e ".[dev]" && pytest -q`. Run `npm run build:css` only after a Tailwind class change.

More detail is in `README.md`. Cloud-agent setup is in `.cursor/CLOUD.md`.

## Plaid

Bank balances use Plaid. Keys are not environment variables. On the [Plaid dashboard](https://dashboard.plaid.com/developers/keys), create a key for Sandbox while trying the connection, then Development or Production when the institution should be the real one. On Money, enter the client id, the secret, and that environment, then click Link and sign in at the bank. Map each Plaid account onto a dashboard account before Refresh. The access token stays encrypted and is not shown again. `cron/sync_plaid.php` (`lifedash-job plaid`) repeats the sync every 3 hours.

The Stocks page also pulls a Google Sheet (Watchlist, Commodities, Play Account). The sheet is read-only. On Settings → Stocks sheet, paste a service-account JSON key and the spreadsheet id. Both stay encrypted, and the key is masked. Until the key is saved, sync reports `configured: false` and skips. When it is connected, `GET /stocks` returns the full sheet instead of the seeded symbol list. `cron/sync_stock_sheet.php` (`lifedash-job stocksheet`) runs on weekdays from 8:30 through 15:30 America/Chicago. Scopes are the existing `stocks:read` and `stocks:write`.

## MCP

`lifedash-mcp` listens on `0.0.0.0:8765` unless `MCP_BIND=loopback`. Plain HTTP off returns 403 and does not move that socket. `tools/list` with no cursor returns every registered tool. A cursor asks for one page. The list is not filtered by scope. A call still needs a valid key, that key's scopes, and the user id on the key. Session-only routes and `system:deploy` stay as they were. Those calls are written to `audit_events`. Settings → AI connections (`/settings/agents`) is where a person sets a key's scopes and revokes it. Settings → Audit (`/settings/audit`) is read-only. `get_audit_log` reads the same log. Token create, edit, and revoke stay on a signed-in browser session.
