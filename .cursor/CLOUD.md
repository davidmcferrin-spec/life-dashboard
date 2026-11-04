# Cloud agents

Change the repo only. Do not deploy, and do not run `setup.sh` or `lifedash-deploy`. Production updates go through the Life-Dashboard MCP, which queues `lifedash-deploy` on the server.

## Tests

Run quietly. Report failures only.

- PHP unit: `composer install` if `vendor/` is missing, then `vendor/bin/phpunit --testsuite unit`.
- Vault JS: `node --test tests/js/*.cjs`.
- Skip PostgreSQL integration tests and `pytest` unless the change touches SQL, `services/`, or an integration test. This VM may not have a database.
- Vault styles live in `public/assets/css/vault.css` and `public/assets/css/vault-editor.css`. Do not run `npm run build:css` for those edits.

Do not create a `.env` with real credentials. Do not commit secrets.
