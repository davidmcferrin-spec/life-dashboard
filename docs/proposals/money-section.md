# Money section — proposal

This is a plan, not something the app does yet. Nothing in this document is built by the change that added it.

## Summary

The dashboard already keeps accounts, transactions, and a net-worth history in Postgres (the money work called L5). This proposal extends that same database. It does not add a second database and it does not keep Google Sheets as the place the numbers live.

A one-time import would copy **The McFerrin Budget** into the home server. After that, Postgres is the source of truth. New screens would show the snowball, the weekly food cap, sinking funds, bills due soon, the current Baby Step, retirement against a 15% target, and the credit score. A finance assistant would talk to the existing MCP service with a token that can see money and nothing else.

Two privacy rules apply to every table, import, log, and screen:

- Full account numbers are never stored. Last 4 digits only, and only when we already have them as a mask.
- One subscription is always labeled **Creator subscription**. Its real name is never stored and never shown.

All of this data stays on the home server.

## What is already there

Migration `0007_money.sql` created three tables:

| Table | What it holds |
| --- | --- |
| `money_accounts` | Name, kind (checking, savings, credit, cash, investment, other), balance, archived flag. One name per person. |
| `money_transactions` | Date, signed amount (negative means money out), payee, a category written as plain text, note, a dedupe key, and where the row came from (manual, import, or ai). |
| `money_snapshots` | One net-worth number per day. |

Credit-card balances are stored as a positive amount owed, and net worth subtracts them. The dashboard already has a **Spending** widget and a **Net worth** widget. There is a Monarch-style CSV import, a weekly review that writes a page in the vault, and one MCP tool, `money_summary`. API paths under `/api/v1/money/` already accept a signed-in browser session or a bearer token, and the `money:read` / `money:write` scopes already exist.

What is missing, and what this proposal adds: a category list, a monthly budget, debts, sinking funds, Baby Step status, credit-score history, retirement inputs, last-4 on accounts, and the widgets and MCP tools below.

## 1. Postgres schema

New columns and tables follow the same rules as the rest of the app: identity ids, timestamps in UTC, 0/1 flags as small integers, and a `lifedash_notify` trigger on any table a widget displays so an open dashboard refreshes. Amounts stay `DOUBLE PRECISION`, matching the columns from migration 0007. Screens round to cents. A later tidy-up could move them to exact decimal cents; that is not part of this import.

Full account numbers, routing numbers, and card numbers do not have a column. `last4` is `CHAR(4)` and must be four digits or empty.

### Accounts (extend `money_accounts`)

Add:

| Column | Type | Notes |
| --- | --- | --- |
| `institution` | `VARCHAR(80)` | Bank or card name, optional. |
| `last4` | `CHAR(4)` | Optional. Check: four digits. |
| `credit_limit` | `DOUBLE PRECISION` | Only used when `kind` is `credit`. Used for utilization. |

Existing unique key `(user_id, name)` stays. No new index is required beyond that.

### Categories (new `money_categories`)

Transactions today store the category as text. Keep that column so the current Spending widget keeps working. New budget rows point at a real category row, and a transaction write fills both the text and the id.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | identity | Primary key. |
| `user_id` | bigint | References `users`. Cascade delete. |
| `name` | `VARCHAR(80)` | Unique per person. |
| `kind` | `VARCHAR(16)` | `income`, `expense`, or `transfer`. |
| `weekly_cap` | `DOUBLE PRECISION` | Optional. Food is `250`. |
| `archived_at` | timestamp | |
| `created_at` | timestamp | UTC. |

Unique `(user_id, name)`. Index `(user_id)`.

`money_transactions` gains a nullable `category_id` referencing `money_categories`. The existing `category` text stays and is set from the category name.

### Monthly budgets (new `money_budget_lines`)

One planned amount per category per month.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | identity | Primary key. |
| `user_id` | bigint | |
| `year` | smallint | |
| `month` | smallint | 1–12. |
| `category_id` | bigint | References `money_categories`. |
| `amount` | `DOUBLE PRECISION` | Planned amount for that month. Positive. |
| `due_day` | smallint | Optional, 1–31. Powers "bills due in the next 10 days." |

Unique `(user_id, year, month, category_id)`. Index `(user_id, year, month)`.

### Debts (new `money_debts`)

The snowball list. Tesla is one row whose target payoff date is about December 2027. This is separate from `money_accounts` so a loan can exist even when there is no linked account, and so payoff order and APR have a home.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | identity | Primary key. |
| `user_id` | bigint | |
| `account_id` | bigint | Optional link to a `credit` account. |
| `name` | `VARCHAR(80)` | Unique per person. "Tesla" is the display name. |
| `last4` | `CHAR(4)` | Optional. |
| `balance` | `DOUBLE PRECISION` | Amount still owed. Positive. |
| `apr_percent` | `DOUBLE PRECISION` | `6.99` means 6.99% a year. |
| `minimum_payment` | `DOUBLE PRECISION` | |
| `payoff_order` | smallint | 1 is the first debt in the snowball. |
| `target_payoff_on` | date | Optional. Tesla ≈ `2027-12-31`. |
| `due_day` | smallint | Optional, 1–31. |
| `archived_at` | timestamp | |
| `updated_at` | timestamp | |

Unique `(user_id, name)`. Index `(user_id, payoff_order)`.

### Sinking funds (new `money_sinking_funds`)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | identity | Primary key. |
| `user_id` | bigint | |
| `name` | `VARCHAR(80)` | Unique per person. |
| `target_amount` | `DOUBLE PRECISION` | |
| `balance` | `DOUBLE PRECISION` | Saved so far. |
| `due_on` | date | Optional. |
| `archived_at` | timestamp | |
| `updated_at` | timestamp | |

Unique `(user_id, name)`. Index `(user_id, due_on)`.

Names we expect from the sheet, to confirm when the CSV is opened: Dec 2026 Disney, Disneyland Apr 2027, mini-split AC, escrow, Oct 2027 trip, cushion. The importer uses whatever name is on the sheet. It does not rename them.

### Net worth snapshots (extend `money_snapshots`)

The daily total already exists (`as_of`, `net_worth`, unique per person per day). Add:

| Column | Type | Notes |
| --- | --- | --- |
| `breakdown` | `JSONB` | Optional map of label → signed amount, for example `{"Checking": 4200, "Tesla": -18000}`. Default `{}`. |

The trend chart keeps using `net_worth`. The breakdown is extra detail for the day, not a second source of truth.

### Baby Steps (new `money_baby_steps`)

One row per person.

| Column | Type | Notes |
| --- | --- | --- |
| `user_id` | bigint | Primary key. |
| `step` | smallint | 1 through 7. |
| `note` | text | Optional short status. |
| `updated_at` | timestamp | |

The seven steps, in order:

1. Starter emergency fund.
2. Debt snowball (Tesla lives here until it is gone).
3. Full emergency fund of 3–6 months of expenses.
4. Retirement at 15% of income.
5. College for kids, if that applies.
6. Pay off the house.
7. Build wealth and give.

The row records where things stand. It does not compute the step by itself.

### Credit score history (new `money_credit_scores`)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | identity | Primary key. |
| `user_id` | bigint | |
| `as_of` | date | |
| `score` | smallint | 300–850. |
| `utilization_percent` | `DOUBLE PRECISION` | Optional number copied from the sheet. |

Unique `(user_id, as_of)`. Index `(user_id, as_of DESC)`.

The widget prefers a computed utilization when credit limits exist: balances on `kind = credit` accounts divided by the sum of their `credit_limit`. The stored percent is the fallback when limits are missing.

### Retirement inputs (new `money_retirement`)

One row per person.

| Column | Type | Notes |
| --- | --- | --- |
| `user_id` | bigint | Primary key. |
| `contribution_rate` | `DOUBLE PRECISION` | Fraction of income. `0.12` means 12%. The widget compares it with `0.15`. |
| `annual_income` | `DOUBLE PRECISION` | Optional. |
| `employer_match_rate` | `DOUBLE PRECISION` | Optional fraction. |
| `updated_at` | timestamp | |

The 15% target is a fixed display rule, not a stored secret.

## 2. One-time import from the Google Sheet

Sheet: [The McFerrin Budget](https://docs.google.com/spreadsheets/d/1qfs9xUbOCuRNFXfvPsni0OSeyvIa5KqgvHWXfMC1kw0).

This plan was written without opening that sheet. Every column list below is an assumption. Before the first real import, open each tab, compare the header row with this list, and adjust the mapping. Do not guess past a header that does not match.

Do not connect a Google API. Export is manual and local:

1. In the sheet, download each tab as CSV (one file per tab).
2. Copy the files to the home server. They never go into git.
3. Run a one-time importer on the server, the same kind of local script as the SQLite import and the Monarch CSV import that already exists.
4. Check the row counts on screen.
5. Postgres is then the source of truth. The sheet is an archive. Delete the CSVs from the server once the counts look right.

The importer refuses a file if any cell looks like a full account number (a long digit string that is not a date and not a last-4 mask). It stores last 4 only when the column is clearly a mask such as `1234` or `****1234`.

### Creator subscription

The importer asks once per run: which payee name on this file is the creator subscription? The answer stays in memory for that run. Every matching payee or description is replaced with the exact text `Creator subscription` before anything is written. The original name is not stored in a table, not written to the import log, and not written to `audit_events`. The answer is not saved in a config file. A later import asks again.

### Tabs

**Transactions** → `money_transactions` (and `money_accounts` / `money_categories` if a name is new).

Assumed columns: Date, Account, Amount, Description or Payee, Category, Note. Confirm whether Amount is already signed (negative = out) or split into Debit and Credit. Dates may be `YYYY-MM-DD` or `M/D/YYYY`.

Dedupe key, same rule as the MCP tool below: date + amount + account + description. A second identical charge on the same day updates the first row. If the sheet has its own id column, use that instead and ignore this rule. That choice should be confirmed against the header.

**2026, 2027, 2028** → `money_budget_lines`.

Assumed shape: one row per category, with a column per month (Jan–Dec), plus an optional due-day column. A wide sheet becomes twelve lines per category for that year. If a tab is actually one row per month, map that instead. Confirm before import.

**Debt Snowball** → `money_debts`.

Assumed columns: Name, Balance, APR, Minimum payment, Payoff order, Target payoff date, Last 4. Tesla's target date should land near December 2027. Confirm the APR is a percent (`6.99`) and not a fraction (`0.0699`).

**Sinking Funds** → `money_sinking_funds`.

Assumed columns: Name, Target, Balance (saved so far), Due date.

**Net Worth** → `money_snapshots`, including `breakdown` when extra columns are per-account balances.

Assumed columns: Date, Net worth, then one column per account or debt.

**Credit Score** → `money_credit_scores`.

Assumed columns: Date, Score, Utilization.

**Investing & Retirement** → `money_retirement` for the contribution rate, income, and match.

Positions and trades stay on the existing read-only investing path (Robinhood CSV, the scorecard widget). This import does not place trades and does not invent brokerage rows from a notes column. Confirm the tab is inputs (rate, income, match) and not a transaction log.

**Notes & Assumptions** → one vault page titled "Budget notes", using the pages feature that already exists.

It is not parsed into balances. If the tab is only scratch work, skip it. A page is searchable later; a new table is not needed.

## 3. MCP tools for a finance assistant

These extend `lifedash-mcp`. They do not add a second server and they do not give the assistant a database login.

### Transport

The service already speaks MCP over HTTP on `127.0.0.1:8765` (protocol `2025-03-26`). Cloudflare Tunnel can forward `/mcp` to that port. Each tool call is turned into a request to the PHP API (`/api/v1/...`) with two headers:

- `Authorization: Bearer <token>`
- `X-Lifedash-Tool: <tool name>`

That is the same path `money_summary` uses today. `money_summary` stays.

| Tool | What it does | API shape |
| --- | --- | --- |
| `add_transactions` | Bulk upsert. Each item is date, account, amount, description, category, optional note. | `POST /api/v1/money/transactions/bulk` |
| `list_transactions` | Filters: `from`, `to`, category, account. | `GET /api/v1/money/transactions` (those filters already exist in part; extend them) |
| `get_budget` | One month: planned lines and spent so far. | `GET /api/v1/money/budget?year=&month=` |
| `set_budget_line` | Set the planned amount for one month and category. | `POST /api/v1/money/budget` |
| `get_debts` | Snowball order, balance, APR, minimum, target date. | `GET /api/v1/money/debts` |
| `update_debt` | Balance, APR, minimum, payoff order. | `POST /api/v1/money/debts/{id}` |
| `get_sinking_funds` | Target, balance, due date, percent of the way there. | `GET /api/v1/money/sinking-funds` |
| `update_sinking_fund` | Target, balance, due date. | `POST /api/v1/money/sinking-funds/{id}` |
| `add_net_worth_snapshot` | One day: total, optional breakdown. | `POST /api/v1/money/snapshots` |
| `add_credit_score` | Date, score, optional utilization. | `POST /api/v1/money/credit-scores` |
| `get_dashboard_summary` | The widget numbers in one payload: net worth, Baby Step, debts, food this week vs $250, month budget vs actual, sinking funds, bills due in 10 days, retirement rate vs 15%, credit score, utilization. | `GET /api/v1/money/dashboard` |

`add_transactions` builds `dedupe_key` from date + amount (to the cent) + account name + description (trimmed, lowercased). The existing unique key `(user_id, dedupe_key)` then updates the same row on a retry. Two genuinely identical charges on the same day collapse into one row. If that is wrong for how the sheet is kept, the import section's own-id exception applies here too.

### Auth

Create the token on **Settings → Agents**. Scopes: `money:read` and `money:write` only. Do not use a token that can also read pages, habits, files, or investing. Reads require `money:read`. Writes require `money:write`. The plaintext is shown once, same as other API tokens. Revoke it from that page if a laptop is lost.

### Audit

Bearer writes already insert an `audit_events` row: actor `ai`, the tool name from `X-Lifedash-Tool`, and the path. These new writes do the same. Money reads should be audited too (tool name and filter only, not the rows), so a stolen token's browsing shows up. Audit detail must not contain a full account number or the real creator-subscription name. The alias is applied before the row is stored, so the payee that gets saved is already `Creator subscription`.

### Reaching the assistant from outside the house

The dashboard UI stays on the LAN, reached remotely with UniFi Teleport (Tailscale is the optional extra already in setup). That is the right path for a phone or laptop you control.

A finance assistant that runs in the cloud is not on that network. Two options:

**Tailscale.** Excellent for machines you own. An ACL can limit who may open the MCP port. A cloud assistant cannot join the tailnet on its own. Publishing the port with Tailscale Funnel puts it on the public internet, which is a wider opening than this job needs. Use Tailscale for yourself. Do not use it as the assistant's front door.

**Cloudflare Tunnel, MCP only, with Cloudflare Access.** This matches what the server is already designed to do: the public hostname exposes `/healthz` and `/mcp`, and the UI is a different name that is not on the tunnel. `cloudflared` connects outbound, so the router does not gain a new open port. Put Cloudflare Access in front of `/mcp` and require a service token (`CF-Access-Client-Id` and `CF-Access-Client-Secret`) before the request is allowed to reach the app. The app bearer token is a second check.

Recommend the tunnel-plus-Access path for the assistant.

Treat that path as internet-reachable. A leaked Access service token plus a leaked bearer token is enough to call the tools. Hardening that goes with the recommendation:

- `lifedash-mcp` keeps listening on `127.0.0.1` only.
- The tunnel ingress lists `/mcp` and `/healthz` and nothing else. The UI hostname is not in that ingress.
- Access service token in front, app token behind it. They are different secrets.
- The app token is money scopes only, with an expiry (90 days is a reasonable start) and the existing revoke button.
- Rate limit about 60 requests a minute per token, in the MCP process, so a leaked token cannot pull the whole ledger in a tight loop.
- Tool responses include last 4 at most. They never include the real creator-subscription name.
- Confirm Cloudflare is not storing request bodies in logs. Access should see the path, not the JSON.
- The assistant is not given `DATABASE_URL` or a shell on the server. It sees only the tool result for that call.
- Money rows are not copied to a hosted notebook, a second cloud database, or the Google Sheet on an ongoing basis.

## 4. Widget layout

The grid is 12 columns wide, the same GridStack layout the dashboard already uses. These sit with the existing Spending and Net worth widgets. Sizes below are a starting layout (width × height in grid cells).

| Widget | Size | What it shows |
| --- | --- | --- |
| Net worth trend | 8 × 4 | Line of `money_snapshots.net_worth`. The existing Net worth widget can grow into this rather than a second copy. |
| Current Baby Step | 4 × 4 | Step number and the plain-language name from the list above, plus the optional note. |
| Debt snowball | 6 × 6 | Debts in `payoff_order`. Balance, APR, minimum, and payoff date. Tesla shows its target near December 2027 beside any estimate from balance, APR, and minimum. |
| Food this week | 3 × 3 | Sum of this week's Food outflows against the $250 `weekly_cap`. Over the cap is the same red the todo list uses for overdue. |
| Budget vs actual | 6 × 5 | This month, each budget line against transactions in that category. |
| Sinking funds | 6 × 5 | A bar per fund: saved / target, and the due date. Expected funds: Dec 2026 Disney, Disneyland Apr 2027, mini-split AC, escrow, Oct 2027 trip, cushion. |
| Bills due | 4 × 4 | Budget lines and debts whose `due_day` falls in the next 10 days, including the wrap at month end. Name and amount. |
| Retirement | 4 × 3 | `contribution_rate` next to the 15% target. |
| Credit | 4 × 3 | Latest score, and utilization (computed from limits when we have them, otherwise the stored percent). |

Narrow screens already collapse the grid to one column. These widgets follow that rule. Editing the layout stays on the dashboard's existing **Edit layout** control.

## What this does not change

- No new database.
- No Google API client and no ongoing sync back to the sheet.
- No brokerage trades. Investing stays read-only.
- No full account numbers, anywhere.
- The creator subscription's real name is not a value the app is willing to store.
