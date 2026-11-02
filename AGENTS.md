# Agents

Self-hosted life dashboard. PHP renders pages, vanilla JS and plain CSS run in the browser, PostgreSQL stores data, and `services/` is the Python package (MCP, sync, realtime).

## Map

- `public/` — web root. `public/index.php` is the front controller. Vault client is `public/assets/js/brain.js`. Vault and widget CSS is `public/assets/css/lifedash.css` (edit it in place; no build).
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
