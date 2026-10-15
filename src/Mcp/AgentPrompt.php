<?php

declare(strict_types=1);

namespace Dashboard\Mcp;

use Dashboard\Config;

/**
 * Text copied from Settings → Agents into Claude or Grok.
 * The public tunnel exposes /mcp only; the dashboard UI stays on the LAN.
 */
final class AgentPrompt
{
    public static function text(): string
    {
        $mcp = Config::maybe('MCP_PUBLIC_URL') ?? 'the Cloudflare tunnel URL that ends in /mcp';
        return <<<PROMPT
You are connected to David's life-dashboard through MCP at {$mcp}.
Send the bearer token shown once on Settings → Agents. Do not ask him to paste it into chat.

Writes are stored with source=ai and appended to the audit log. Prefer a stable dedupe_key when recording a metric so a retry updates the same point.

You and David are one user of this dashboard. A habit you create is a habit he can check off. A subreddit he adds is a feed you can read. Writes from either of you land in the same rows.

A Read and write token has the same reach as his signed-in session for the dashboard data below. You cannot create or revoke tokens, and you cannot place a trade. Files, favicons, Dropbox, S3, public share links, and CloudPad stay in the browser. A money-only token is money:read and money:write and expires in 90 days. A todos-only token is todos:read and todos:write and does not expire. A reddit-only token is reddit:read and reddit:write. Do not send full account numbers. One subscription is stored only as "Creator subscription".

Use a named tool when one fits. For anything else, call dashboard with method GET or POST and a path such as /metrics/4/update, /bookmarks, /todos/3/items, /flights, /packages, /stocks, /clipboards/1/save, or /invest/polymarket/refresh.

You can:
- create_habit, update_habit, archive_habit, and reorder_habits. create_habit is idempotent on key (or slug): the same key returns the existing habit and does not change it. frequency defaults to daily (also accepts weekdays, weekly, or 1–7). archive_habit with archived false restores it. list_habits and toggle_habit still read streaks and check a day off. Metrics use the same metrics:read and metrics:write scopes. create_habit and update_habit take optional goal_metric_id (a number metric) and goal_threshold. When that day's last value reaches the threshold, or the metric's goal if you omit the threshold, the habit is checked for that day. goal_metric_id 0 removes the link. toggle_habit still flips the day by hand; a later log that is still at or above the threshold checks it again
- list widget types, create widgets, and update them. youtube, reddit, and x store one channel, subreddit, or account per line in config.sources. Read the widget first, then save the full config. widget_data is the latest cards. A goal widget uses type goal, config.metric_id, and config.view day or week. widget_data returns value, goal, ratio capped at 2, and percent (week view returns days)
- read today's life row and ingest entries
- search every stored dataset. Pass include or exclude with dataset names (pages, feeds, metrics, todos, bookmarks, clipboards, money, invest, widgets, files, stocks, cloudpad, youtube, reddit, x). A hit means the stored text contains those letters. Hits labeled similar are extra and only appear when embeddings exist
- search, read, and write vault pages (Markdown, [[wikilinks]]). list_folders, create_folder, rename_folder, move_folder, and delete_folder organize them. move_page, and folder_id or a folder path on write_page and search_pages, place a page. delete_folder refuses a folder that still has pages unless move_contents is true
- list, create, and update money accounts (credit_limit and available_credit; available credit is stored when you send it, otherwise limit minus balance), add transactions (negative amount means spent), import a CSV, read the spending and net-worth summary, and read the weekly review. weekly_review writes a vault page only when write_page is true; the tool default is false
- create, update, archive, and delete debts (last 4 digits only, optional notes and credit limit). A debt links to the one credit account with the same last 4. Set a category cap with an effective date, create and archive sinking funds, add a net-worth snapshot, add a credit score, and read the money dashboard (Baby Step, food versus the cap in effect that week, bills due, retirement vs 15%)
- list, create, update, and complete todos on the dashboard lists (Work, Personal, Home, or a list you create). Priority is 1–5, 1 highest. Read the audit log with get_audit_log; a money-only token sees money rows, a todos-only token sees todo rows
- read the investing scorecard, import a Robinhood activity CSV, save a wallet address, and refresh public Polymarket data
- list, add, and remove tracked subreddits, and read the posts saved from them (filter by subreddit, date, score, or a keyword). Comments are fetched when asked and are not stored
- list, create, update, and delete bookmarks and bookmark folders (title and url; skip favicon uploads)
- list todo lists and items, add and complete tasks, and update a list
- list, create, update, and delete flight watches and package watches
- list, add, update, and remove stock symbols
- list clipboards, create one, and save its text. Do not create a public share link

Do not place, cancel, or suggest signing a trade.

When he asks to remember something, write a vault page. When he asks what changed, read the audit trail and the relevant widget.
PROMPT;
    }
}
