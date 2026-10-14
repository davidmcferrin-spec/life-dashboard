# Import The McFerrin Budget

Postgres is the source of truth after this one-time copy. The Google Sheet stays an archive. The app does not call a Google API.

Sheet: [The McFerrin Budget](https://docs.google.com/spreadsheets/d/1qfs9xUbOCuRNFXfvPsni0OSeyvIa5KqgvHWXfMC1kw0).

The importer checks the header row of each file. If a header does not match, the run stops and prints the headers it found. Fix the file, or add a `map.json` (below). Do not guess past a mismatch.

Full account numbers are refused. A cell that still contains a run of 8 or more digits, after dates, money amounts, and last-4 masks are ignored, stops the import before any row from that folder is written. Last 4 is kept only from a value that is four digits or a mask such as `****1234`. An id column of 8 or more pure digits is treated as an account number; shorten or remove that column before importing.

## Export

1. Open the sheet while signed in.
2. For each tab, use **File → Download → Comma-separated values (.csv)**. Download one file per tab. Do not download the whole workbook as one xlsx.
3. Name the files so the importer can find them (case does not matter):

   | Tab | File name |
   | --- | --- |
   | Transactions | `transactions.csv` |
   | 2026, 2027, 2028 | `2026.csv`, `2027.csv`, `2028.csv` |
   | Debt Snowball | `Debt Snowball.csv` |
   | Sinking Funds | `Sinking Funds.csv` |
   | Net Worth | `Net Worth.csv` |
   | Credit Score | `Credit Score.csv` |
   | Investing & Retirement | `Investing & Retirement.csv` |
   | Notes & Assumptions | `Notes & Assumptions.csv` |

   Skip a tab by leaving its file out. Positions and trades are not imported from the retirement tab; those stay on the existing read-only investing import.

4. Copy the folder to the home server, outside the git checkout. Example: `/home/david/budget-import/`. Do not commit the CSVs.

## Run

On the server, from the app directory (`/srv/lifedash/app`):

```bash
php scripts/import-budget-csvs.php --user YOUR_USERNAME --dir /home/david/budget-import
```

The script asks once:

```text
Which payee name should be stored as "Creator subscription"?
Press Enter if none of the rows are that subscription.
```

Type the payee exactly as it appears in the CSV. The answer is used for this run only. It is not printed back, not written to a table, not written to a log, and not saved in `map.json` or the environment. Every matching payee or description is stored as the exact text `Creator subscription`. A later import asks again.

The script prints counts: transactions, accounts, budget lines, debts, sinking funds, snapshots, credit scores, retirement, notes, and how many creator-subscription cells were renamed (`aliased` is a count, not the name).

Check those counts against the sheet. When they look right, delete the CSV folder from the server:

```bash
rm -rf /home/david/budget-import
```

Postgres is then the source of truth. The dashboard widgets (Edit layout → add the money widgets) and a money-only token on Settings → Agents read this database. See `docs/deploy/cloudflare-access.md` for the assistant's public MCP path.

## If the headers do not match

The error lists the missing columns and the headers in the file. Either rename the header row to the names above (Date, Account, Amount or Debit/Credit, Description or Payee, Category, Note) or drop a `map.json` next to the CSVs:

```json
{
  "transactions": {
    "file": "transactions.csv",
    "columns": {
      "date": "Txn day",
      "account": "Wallet",
      "amount": "Signed",
      "payee": "Who"
    }
  },
  "budgets": [{ "file": "2026.csv", "year": 2026 }],
  "debts": { "file": "Debt Snowball.csv" },
  "sinking_funds": { "file": "Sinking Funds.csv" },
  "net_worth": { "file": "Net Worth.csv" },
  "credit": { "file": "Credit Score.csv" },
  "retirement": { "file": "Investing & Retirement.csv" },
  "notes": { "file": "Notes & Assumptions.csv" }
}
```

Column values are the header text in the file. A budget tab with Jan–Dec columns is read as twelve months. A tab with Category, Month, and Amount is read as one row per month. Re-run the same command after editing the map. The creator-subscription answer is not stored in this file.
