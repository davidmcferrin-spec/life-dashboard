"""``lifedash-mcp`` — MCP endpoint on port 8765.

Cloudflare Tunnel sends ``/mcp`` here. Each tool call is an HTTP request to the
PHP ``/api/v1`` API with the caller's bearer token and ``X-Lifedash-Tool``.
This process does not hold a token of its own and does not place trades.
"""
from __future__ import annotations

import hashlib
import json
import logging
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from .config import Config
from .log import setup as setup_logging

LOG = logging.getLogger("lifedash.mcp")
PROTOCOL = "2025-03-26"

Fetch = Callable[[str, str, dict[str, Any] | None, str, str], tuple[int, Any]]

TOOLS: list[dict[str, Any]] = [
    {
        "name": "list_metrics",
        "description": "List metrics (habits, scales, numbers, durations).",
        "inputSchema": {"type": "object", "properties": {"kind": {"type": "string"}}, "additionalProperties": False},
    },
    {
        "name": "create_metric",
        "description": "Create a metric. kind is habit, scale, number, or duration.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "kind": {"type": "string"},
                "unit": {"type": "string"},
                "key": {"type": "string"},
            },
            "required": ["name", "kind"],
            "additionalProperties": False,
        },
    },
    {
        "name": "record_datapoint",
        "description": "Record a metric value. Pass dedupe_key so a retry updates the same row. source is set to ai.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "metric_id": {"type": "integer"},
                "value": {"type": "number"},
                "date": {"type": "string"},
                "ts": {"type": "string"},
                "note": {"type": "string"},
                "dedupe_key": {"type": "string"},
            },
            "required": ["metric_id", "value"],
            "additionalProperties": False,
        },
    },
    {
        "name": "query_series",
        "description": "Bucketed series for one metric.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "metric_id": {"type": "integer"},
                "bucket": {"type": "string"},
                "agg": {"type": "string"},
                "from": {"type": "string"},
                "to": {"type": "string"},
            },
            "required": ["metric_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_habits",
        "description": "Habit streaks and whether each is done today.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "toggle_habit",
        "description": "Flip a habit for a local date (default today).",
        "inputSchema": {
            "type": "object",
            "properties": {"metric_id": {"type": "integer"}, "date": {"type": "string"}},
            "required": ["metric_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_widgets",
        "description": "Dashboard widgets.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "widget_data",
        "description": "What a widget is currently showing, including the latest YouTube, Reddit, and X cards.",
        "inputSchema": {
            "type": "object",
            "properties": {"widget_id": {"type": "integer"}},
            "required": ["widget_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_widget",
        "description": "Read one widget, including its config. Use this before changing sources.",
        "inputSchema": {
            "type": "object",
            "properties": {"widget_id": {"type": "integer"}},
            "required": ["widget_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_widget_types",
        "description": "Widget types and their config fields.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "save_widget",
        "description": (
            "Create a widget, or update it when widget_id is set. "
            "youtube, reddit, and x use config.sources with one channel, subreddit, or account per line. "
            "On update, send the full config from get_widget so the other settings stay."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "widget_id": {"type": "integer"},
                "type": {"type": "string"},
                "title": {"type": "string"},
                "config": {"type": "object"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "life_today",
        "description": "Sleep, steps, exercise, weight, and travel for today.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "life_ingest",
        "description": "Write life entries or Health Connect records.",
        "inputSchema": {"type": "object", "properties": {"entries": {"type": "array"}, "records": {"type": "array"}}},
    },
    {
        "name": "search_pages",
        "description": "Search the markdown vault, or list recent pages when q is empty. folder_id or folder (a path like Travel/2026) limits the search. folder_id 0 is the root.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "q": {"type": "string"},
                "folder_id": {"type": "integer"},
                "folder": {"type": "string"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "get_page",
        "description": "Read one vault page, including backlinks.",
        "inputSchema": {
            "type": "object",
            "properties": {"page_id": {"type": "integer"}},
            "required": ["page_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "write_page",
        "description": "Create a vault page, or update it when page_id is set. Body is Markdown; use [[wikilinks]]. folder_id or folder (a path like Travel/2026) places it. folder_id null, or folder \"root\", is the root. Omitting both leaves the folder unchanged on update.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "page_id": {"type": "integer"},
                "title": {"type": "string"},
                "body": {"type": "string"},
                "kind": {"type": "string"},
                "folder_id": {"type": ["integer", "null"]},
                "folder": {"type": "string"},
            },
            "required": ["title"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_folders",
        "description": "Vault folders as a tree. Pages without a folder are at the root.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "create_folder",
        "description": "Create a vault folder. parent_id nests it. Omit parent_id for the root.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "parent_id": {"type": ["integer", "null"]},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "rename_folder",
        "description": "Rename a vault folder.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "folder_id": {"type": "integer"},
                "name": {"type": "string"},
            },
            "required": ["folder_id", "name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "move_folder",
        "description": "Move a vault folder. parent_id null places it at the root.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "folder_id": {"type": "integer"},
                "parent_id": {"type": ["integer", "null"]},
            },
            "required": ["folder_id", "parent_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "delete_folder",
        "description": "Delete a vault folder. It must be empty unless move_contents is true, which moves pages and child folders to the parent.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "folder_id": {"type": "integer"},
                "move_contents": {"type": "boolean"},
            },
            "required": ["folder_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "move_page",
        "description": "Move a vault page into a folder. folder_id null, or folder \"root\", places it at the root. folder is a path like Travel/2026.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "page_id": {"type": "integer"},
                "folder_id": {"type": ["integer", "null"]},
                "folder": {"type": "string"},
            },
            "required": ["page_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "money_summary",
        "description": "Spending and net worth for a date range. Dates are YYYY-MM-DD.",
        "inputSchema": {
            "type": "object",
            "properties": {"from": {"type": "string"}, "to": {"type": "string"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "list_accounts",
        "description": "Money accounts and balances.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "save_account",
        "description": (
            "Create a money account, or update it when account_id is set. "
            "kind is checking, savings, credit, cash, investment, or other. "
            "credit_limit is the card limit. available_credit is stored when sent; "
            "otherwise reads return credit_limit minus balance. last4 is four digits."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "account_id": {"type": "integer"},
                "name": {"type": "string"},
                "kind": {"type": "string"},
                "balance": {"type": "number"},
                "credit_limit": {"type": "number"},
                "available_credit": {"type": "number"},
                "last4": {"type": "string"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "archive_account",
        "description": "Archive a money account. Transactions stay.",
        "inputSchema": {
            "type": "object",
            "properties": {"account_id": {"type": "integer"}},
            "required": ["account_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "add_transactions",
        "description": "Upsert transactions. Same date, amount to the cent, account, and description update one row. Do not send full account numbers.",
        "inputSchema": {
            "type": "object",
            "properties": {"transactions": {"type": "array"}},
            "required": ["transactions"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_transactions",
        "description": "Transactions in a date range. Amount is signed: negative is money spent. Optional category and account name filters.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "from": {"type": "string"},
                "to": {"type": "string"},
                "category": {"type": "string"},
                "account": {"type": "string"},
                "limit": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "add_transaction",
        "description": (
            "Add a transaction on an account. Amount is signed: negative is money spent. "
            "Pass dedupe_key so a retry updates the same row."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "account_id": {"type": "integer"},
                "amount": {"type": "number"},
                "date": {"type": "string"},
                "payee": {"type": "string"},
                "category": {"type": "string"},
                "note": {"type": "string"},
                "dedupe_key": {"type": "string"},
            },
            "required": ["account_id", "amount"],
            "additionalProperties": False,
        },
    },
    {
        "name": "import_money",
        "description": "Import a Monarch-style money CSV. Columns are Date, Amount, Merchant, Category, Account.",
        "inputSchema": {
            "type": "object",
            "properties": {"csv": {"type": "string"}},
            "required": ["csv"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_budget",
        "description": "Planned budget lines and amount spent for one month.",
        "inputSchema": {
            "type": "object",
            "properties": {"year": {"type": "integer"}, "month": {"type": "integer"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "set_budget_line",
        "description": "Set the planned amount for one category in one month. due_day is optional, 1-31.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "year": {"type": "integer"},
                "month": {"type": "integer"},
                "category": {"type": "string"},
                "amount": {"type": "number"},
                "due_day": {"type": "integer"},
            },
            "required": ["year", "month", "category", "amount"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_debts",
        "description": "Debts in snowball order: balance, APR, minimum, target date, and an estimated payoff date.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "create_debt",
        "description": (
            "Create a debt. last4 is four digits or a mask like ****1234. "
            "A last4 that matches exactly one credit account links them. "
            "account_id links a specific account. Do not send a full account number."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "last4": {"type": "string"},
                "balance": {"type": "number"},
                "credit_limit": {"type": "number"},
                "apr_percent": {"type": "number"},
                "minimum_payment": {"type": "number"},
                "due_day": {"type": "integer"},
                "payoff_order": {"type": "integer"},
                "notes": {"type": "string"},
                "account_id": {"type": "integer"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_debt",
        "description": (
            "Update a debt. Any of name, last4, balance, credit_limit, apr or apr_percent, "
            "minimum or minimum_payment, due_day, payoff_order, notes, and account_id. "
            "account_id null unlinks the debt."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "debt_id": {"type": "integer"},
                "name": {"type": "string"},
                "last4": {"type": "string"},
                "balance": {"type": "number"},
                "credit_limit": {"type": "number"},
                "apr": {"type": "number"},
                "apr_percent": {"type": "number"},
                "minimum": {"type": "number"},
                "minimum_payment": {"type": "number"},
                "due_day": {"type": "integer"},
                "payoff_order": {"type": "integer"},
                "notes": {"type": "string"},
                "account_id": {"type": "integer"},
            },
            "required": ["debt_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "archive_debt",
        "description": "Archive a debt. It leaves the snowball list. The row stays.",
        "inputSchema": {
            "type": "object",
            "properties": {"debt_id": {"type": "integer"}},
            "required": ["debt_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "delete_debt",
        "description": "Delete a debt only when nothing else points at it. Otherwise archive it.",
        "inputSchema": {
            "type": "object",
            "properties": {"debt_id": {"type": "integer"}},
            "required": ["debt_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_sinking_funds",
        "description": "Sinking funds with target, balance, due date, and percent saved.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "create_sinking_fund",
        "description": "Create a sinking fund. balance defaults to 0. due_on is YYYY-MM-DD.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "target_amount": {"type": "number"},
                "balance": {"type": "number"},
                "due_on": {"type": "string"},
                "notes": {"type": "string"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_sinking_fund",
        "description": "Update a sinking fund's target, balance, due date, or notes.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "sinking_fund_id": {"type": "integer"},
                "target": {"type": "number"},
                "balance": {"type": "number"},
                "due_on": {"type": "string"},
                "notes": {"type": "string"},
            },
            "required": ["sinking_fund_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "archive_sinking_fund",
        "description": "Archive a sinking fund. It leaves the active list. The row stays.",
        "inputSchema": {
            "type": "object",
            "properties": {"sinking_fund_id": {"type": "integer"}},
            "required": ["sinking_fund_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "set_category_cap",
        "description": (
            "Set a weekly and/or monthly spending cap for a category, starting on effective_from (YYYY-MM-DD). "
            "Earlier weeks keep the older cap. A weekly-only change does not clear the monthly cap."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "category": {"type": "string"},
                "weekly_cap": {"type": "number"},
                "monthly_cap": {"type": "number"},
                "effective_from": {"type": "string"},
            },
            "required": ["category", "effective_from"],
            "additionalProperties": False,
        },
    },
    {
        "name": "add_net_worth_snapshot",
        "description": "Record one day's net worth. breakdown is an optional map of label to signed amount.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "date": {"type": "string"},
                "net_worth": {"type": "number"},
                "breakdown": {"type": "object"},
            },
            "required": ["date"],
            "additionalProperties": False,
        },
    },
    {
        "name": "add_credit_score",
        "description": "Record a credit score for a date, with optional utilization percent.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "date": {"type": "string"},
                "score": {"type": "integer"},
                "utilization": {"type": "number"},
            },
            "required": ["date", "score"],
            "additionalProperties": False,
        },
    },
    {
        "name": "weekly_review",
        "description": (
            "This week's money review. write_page defaults to false and returns the review without writing a vault page. "
            "Pass write_page true to save it as a review page."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"write_page": {"type": "boolean"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "get_audit_log",
        "description": (
            "Audit trail. Filters: since, until, actor (ai, user, system, integration; source is the same filter), "
            "tool, entity (debt, account, transaction, budget, sinking_fund, todo, todo_category), entity_id, limit. "
            "A money-only token sees money entities. A todos-only token sees todos. audit:read sees every entity. "
            "Rows do not include notes, titles, payees, or full account numbers."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "since": {"type": "string"},
                "until": {"type": "string"},
                "actor": {"type": "string"},
                "source": {"type": "string"},
                "tool": {"type": "string"},
                "entity": {"type": "string"},
                "entity_id": {"type": "integer"},
                "limit": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "get_dashboard_summary",
        "description": "Net worth, Baby Step, debts, food this week versus the cap, this month's budget, sinking funds, bills due in 10 days, retirement rate versus 15%, and credit score.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "list_todos",
        "description": (
            "Todos on lists you own. category is the list name. status is open, done, or all. "
            "due_before and due_after are inclusive YYYY-MM-DD. priority is 1-5. "
            "Archived tasks stay out."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "category": {"type": "string"},
                "status": {"type": "string", "enum": ["open", "done", "all"]},
                "due_before": {"type": "string"},
                "due_after": {"type": "string"},
                "priority": {"type": "integer"},
                "limit": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "create_todo",
        "description": (
            "Add a todo to a list. category is the list name (Work, Personal, Home, or one you created). "
            "An unknown category is an error. due_date is YYYY-MM-DD. priority is 1-5, 1 highest."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "title": {"type": "string"},
                "category": {"type": "string"},
                "due_date": {"type": "string"},
                "notes": {"type": "string"},
                "priority": {"type": "integer"},
            },
            "required": ["title"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_todo",
        "description": "Update a todo's title, category (moves it to that list), due_date, notes, or priority.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "todo_id": {"type": "integer"},
                "title": {"type": "string"},
                "category": {"type": "string"},
                "due_date": {"type": "string"},
                "notes": {"type": "string"},
                "priority": {"type": "integer"},
            },
            "required": ["todo_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "complete_todo",
        "description": "Mark a todo done. reopen true puts it back to open.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "todo_id": {"type": "integer"},
                "reopen": {"type": "boolean"},
            },
            "required": ["todo_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_todo_categories",
        "description": "Todo lists you own. The dashboard calls these categories.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "create_todo_category",
        "description": "Create a todo list. New tasks use that name as category.",
        "inputSchema": {
            "type": "object",
            "properties": {"name": {"type": "string"}},
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "search",
        "description": (
            "Search every stored dataset. A hit means the text contains the query letters. "
            "include and exclude are dataset names: pages, feeds, metrics, todos, bookmarks, "
            "clipboards, money, invest, widgets, files, stocks, cloudpad, youtube, reddit, x. Omit both to search all. "
            "Hits labeled similar are extra and only appear when embeddings exist."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "q": {"type": "string"},
                "include": {"type": "array", "items": {"type": "string"}},
                "exclude": {"type": "array", "items": {"type": "string"}},
            },
            "required": ["q"],
            "additionalProperties": False,
        },
    },
    {
        "name": "invest_scorecard",
        "description": "Positions and unrealized result. Do not place trades.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "dashboard",
        "description": (
            "Read or write a dashboard path the signed-in user can use. method is GET or POST. "
            "Examples: /metrics/4/update, /bookmarks, /todos, /flights, /packages, /stocks, /clipboards/1/save. "
            "Files, favicons, Dropbox, S3, public share links, CloudPad, tokens, and trades are closed."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "method": {"type": "string", "enum": ["GET", "POST"]},
                "path": {"type": "string"},
                "query": {"type": "object"},
                "body": {"type": "object"},
            },
            "required": ["method", "path"],
        },
    },
    {
        "name": "list_tracked_subreddits",
        "description": "Subreddits this person tracks, with sort and keyword filters.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "add_tracked_subreddit",
        "description": "Track a subreddit. sort is hot, new, or top. include and exclude are optional comma-separated words.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "sort": {"type": "string"},
                "include": {"type": "string"},
                "exclude": {"type": "string"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "remove_tracked_subreddit",
        "description": "Stop tracking a subreddit and delete the posts saved from it.",
        "inputSchema": {
            "type": "object",
            "properties": {"name": {"type": "string"}},
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_reddit_posts",
        "description": "Posts saved locally. Filter by subreddit, since (YYYY-MM-DD), min_score, keyword, and limit.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "subreddit": {"type": "string"},
                "since": {"type": "string"},
                "min_score": {"type": "integer"},
                "keyword": {"type": "string"},
                "limit": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "get_reddit_post_comments",
        "description": "Top-level comments for a stored post. Fetched when asked, not stored.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "post_id": {"type": "string"},
                "limit": {"type": "integer"},
            },
            "required": ["post_id"],
            "additionalProperties": False,
        },
    },
]


def _query(args: dict[str, Any], keys: tuple[str, ...]) -> str:
    query = {key: args[key] for key in keys if args.get(key) not in (None, "")}
    return ("?" + urlencode(query)) if query else ""


def _pick(args: dict[str, Any], keys: tuple[str, ...]) -> dict[str, Any]:
    return {key: args[key] for key in keys if key in args and args[key] is not None}


_LEGACY = ("bookmarks", "todos", "flights", "packages", "stocks", "clipboards")
_CLOSED = ("/favicon", "/capture", "/shares/link", "/files", "/storage", "/cloudpad", "/tokens", "/trade", "/order")


def _api_path(path: str, query: Any = None) -> str:
    """Map a tool path onto /api/v1 or an older JSON API. Closed areas stay closed."""
    raw = path.strip().split("?", 1)[0]
    if ".." in raw or "\\" in raw or raw.startswith("//"):
        raise ValueError("Path must stay inside the dashboard API.")
    if not raw.startswith("/"):
        raw = "/" + raw
    if raw.startswith("/api/v1/") or raw == "/api/v1":
        full = raw
    elif raw.startswith("/api/"):
        full = raw
    else:
        segment = raw.strip("/").split("/", 1)[0]
        full = ("/api" if segment in _LEGACY else "/api/v1") + raw
    if any(ch not in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_./-" for ch in full):
        raise ValueError("Path has unsupported characters.")
    lowered = full.lower()
    if any(part in lowered for part in _CLOSED):
        raise ValueError("That path is not available to an agent.")
    if not full.startswith("/api/v1"):
        segment = full[len("/api/"):].split("/", 1)[0]
        if segment not in _LEGACY:
            raise ValueError("That path is not available to an agent.")
    suffix = ""
    if query:
        if not isinstance(query, dict):
            raise ValueError("query must be an object.")
        clean = {str(key): str(value) for key, value in query.items() if value is not None and str(value) != ""}
        if clean:
            suffix = "?" + urlencode(clean)
    return full + suffix


def resolve(name: str, arguments: dict[str, Any] | None) -> tuple[str, str, dict[str, Any] | None]:
    """Map a tool call to an /api/v1 method, path, and optional JSON body."""
    args = arguments or {}
    if name == "list_metrics":
        query = ("?" + urlencode({"kind": args["kind"]})) if args.get("kind") else ""
        return "GET", "/api/v1/metrics" + query, None
    if name == "create_metric":
        body = {k: args[k] for k in ("name", "kind", "unit", "key") if k in args}
        return "POST", "/api/v1/metrics", body
    if name == "record_datapoint":
        body = {k: args[k] for k in ("value", "date", "ts", "note", "dedupe_key") if k in args}
        return "POST", f"/api/v1/metrics/{int(args['metric_id'])}/datapoints", body
    if name == "query_series":
        query = {k: args[k] for k in ("bucket", "agg", "from", "to") if k in args}
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", f"/api/v1/metrics/{int(args['metric_id'])}/series" + suffix, None
    if name == "list_habits":
        return "GET", "/api/v1/habits", None
    if name == "toggle_habit":
        body = {"date": args["date"]} if args.get("date") else {}
        return "POST", f"/api/v1/habits/{int(args['metric_id'])}/toggle", body
    if name == "list_widgets":
        return "GET", "/api/v1/widgets", None
    if name == "widget_data":
        return "GET", f"/api/v1/widgets/{int(args['widget_id'])}/data", None
    if name == "get_widget":
        return "GET", f"/api/v1/widgets/{int(args['widget_id'])}", None
    if name == "list_widget_types":
        return "GET", "/api/v1/widget-types", None
    if name == "save_widget":
        body = _pick(args, ("title", "config"))
        if args.get("widget_id"):
            return "POST", f"/api/v1/widgets/{int(args['widget_id'])}/update", body
        body["type"] = args["type"]
        return "POST", "/api/v1/widgets", body
    if name == "life_today":
        return "GET", "/api/v1/life/today", None
    if name == "life_ingest":
        body = {k: args[k] for k in ("entries", "records") if k in args}
        return "POST", "/api/v1/life/ingest", body
    if name == "search_pages":
        query = {}
        if args.get("q"):
            query["q"] = args["q"]
        if "folder_id" in args and args["folder_id"] is not None:
            query["folder_id"] = int(args["folder_id"])
        elif "folder_id" in args and args["folder_id"] is None:
            query["folder_id"] = 0
        if args.get("folder"):
            query["folder"] = args["folder"]
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/pages" + suffix, None
    if name == "get_page":
        return "GET", f"/api/v1/pages/{int(args['page_id'])}", None
    if name == "write_page":
        body = {k: args[k] for k in ("title", "body", "kind", "folder_id", "folder") if k in args}
        if args.get("page_id"):
            return "POST", f"/api/v1/pages/{int(args['page_id'])}/update", body
        return "POST", "/api/v1/pages", body
    if name == "list_folders":
        return "GET", "/api/v1/pages/folders", None
    if name == "create_folder":
        body = {"name": args["name"]}
        if "parent_id" in args:
            body["parent_id"] = args["parent_id"]
        return "POST", "/api/v1/pages/folders", body
    if name == "rename_folder":
        return "POST", f"/api/v1/pages/folders/{int(args['folder_id'])}", {"name": args["name"]}
    if name == "move_folder":
        return "POST", f"/api/v1/pages/folders/{int(args['folder_id'])}/move", {"parent_id": args.get("parent_id")}
    if name == "delete_folder":
        body = {"move_contents": bool(args["move_contents"])} if "move_contents" in args else {}
        return "POST", f"/api/v1/pages/folders/{int(args['folder_id'])}/delete", body
    if name == "move_page":
        body = {k: args[k] for k in ("folder_id", "folder") if k in args}
        return "POST", f"/api/v1/pages/{int(args['page_id'])}/move", body
    if name == "money_summary":
        return "GET", "/api/v1/money/summary" + _query(args, ("from", "to")), None
    if name == "list_accounts":
        return "GET", "/api/v1/money/accounts", None
    if name == "save_account":
        body = {k: args[k] for k in ("name", "kind", "balance", "credit_limit", "available_credit", "last4") if k in args}
        if args.get("account_id"):
            return "POST", f"/api/v1/money/accounts/{int(args['account_id'])}/update", body
        return "POST", "/api/v1/money/accounts", body
    if name == "archive_account":
        return "POST", f"/api/v1/money/accounts/{int(args['account_id'])}/archive", {}
    if name == "add_transactions":
        return "POST", "/api/v1/money/transactions/bulk", {"transactions": args["transactions"]}
    if name == "list_transactions":
        return "GET", "/api/v1/money/transactions" + _query(args, ("from", "to", "category", "account", "limit")), None
    if name == "add_transaction":
        return "POST", "/api/v1/money/transactions", _pick(args, ("account_id", "amount", "date", "payee", "category", "note", "dedupe_key"))
    if name == "import_money":
        return "POST", "/api/v1/money/import", {"csv": args["csv"]}
    if name == "get_budget":
        query = {}
        if args.get("year") is not None:
            query["year"] = int(args["year"])
        if args.get("month") is not None:
            query["month"] = int(args["month"])
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/money/budget" + suffix, None
    if name == "set_budget_line":
        body = {k: args[k] for k in ("year", "month", "category", "amount", "due_day") if k in args}
        return "POST", "/api/v1/money/budget", body
    if name == "get_debts":
        return "GET", "/api/v1/money/debts", None
    if name == "create_debt":
        body = {k: args[k] for k in (
            "name", "last4", "balance", "credit_limit", "apr_percent", "minimum_payment",
            "due_day", "payoff_order", "notes", "account_id",
        ) if k in args}
        return "POST", "/api/v1/money/debts", body
    if name == "update_debt":
        body = {k: args[k] for k in (
            "name", "last4", "balance", "credit_limit", "apr", "apr_percent", "minimum",
            "minimum_payment", "due_day", "payoff_order", "notes", "account_id",
        ) if k in args}
        return "POST", f"/api/v1/money/debts/{int(args['debt_id'])}", body
    if name == "archive_debt":
        return "POST", f"/api/v1/money/debts/{int(args['debt_id'])}/archive", {}
    if name == "delete_debt":
        return "POST", f"/api/v1/money/debts/{int(args['debt_id'])}/delete", {}
    if name == "get_sinking_funds":
        return "GET", "/api/v1/money/sinking-funds", None
    if name == "create_sinking_fund":
        body = {k: args[k] for k in ("name", "target_amount", "balance", "due_on", "notes") if k in args}
        return "POST", "/api/v1/money/sinking-funds", body
    if name == "update_sinking_fund":
        body = {k: args[k] for k in ("target", "balance", "due_on", "notes") if k in args}
        return "POST", f"/api/v1/money/sinking-funds/{int(args['sinking_fund_id'])}", body
    if name == "archive_sinking_fund":
        return "POST", f"/api/v1/money/sinking-funds/{int(args['sinking_fund_id'])}/archive", {}
    if name == "set_category_cap":
        body = {k: args[k] for k in ("category", "weekly_cap", "monthly_cap", "effective_from") if k in args}
        return "POST", "/api/v1/money/category-caps", body
    if name == "add_net_worth_snapshot":
        body = {k: args[k] for k in ("date", "net_worth", "breakdown") if k in args}
        return "POST", "/api/v1/money/snapshots", body
    if name == "add_credit_score":
        body = {k: args[k] for k in ("date", "score", "utilization") if k in args}
        return "POST", "/api/v1/money/credit-scores", body
    if name == "weekly_review":
        write = bool(args["write_page"]) if "write_page" in args else False
        return "POST", "/api/v1/money/weekly-review", {"write_page": write}
    if name == "get_audit_log":
        query = {}
        for key in ("since", "until", "actor", "tool", "entity"):
            if args.get(key) not in (None, ""):
                query[key] = args[key]
        if args.get("source") and "actor" not in query:
            query["actor"] = args["source"]
        if args.get("entity_id") is not None:
            query["entity_id"] = int(args["entity_id"])
        if args.get("limit") is not None:
            query["limit"] = int(args["limit"])
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/audit/log" + suffix, None
    if name == "list_todos":
        return "GET", "/api/v1/todos/items" + _query(args, ("category", "status", "due_before", "due_after", "priority", "limit")), None
    if name == "create_todo":
        body = {k: args[k] for k in ("title", "category", "due_date", "notes", "priority") if k in args}
        return "POST", "/api/v1/todos/items", body
    if name == "update_todo":
        body = {k: args[k] for k in ("title", "category", "due_date", "notes", "priority") if k in args}
        return "POST", f"/api/v1/todos/items/{int(args['todo_id'])}", body
    if name == "complete_todo":
        body = {"reopen": bool(args["reopen"])} if "reopen" in args else {}
        return "POST", f"/api/v1/todos/items/{int(args['todo_id'])}/complete", body
    if name == "list_todo_categories":
        return "GET", "/api/v1/todos/categories", None
    if name == "create_todo_category":
        return "POST", "/api/v1/todos/categories", {"name": args["name"]}
    if name == "get_dashboard_summary":
        return "GET", "/api/v1/money/dashboard", None
    if name == "invest_scorecard":
        return "GET", "/api/v1/invest/scorecard", None
    if name == "dashboard":
        method = str(args.get("method") or "").upper()
        if method not in ("GET", "POST"):
            raise ValueError("method must be GET or POST.")
        path = _api_path(str(args.get("path") or ""), args.get("query"))
        if method == "GET":
            return method, path, None
        raw_body = args.get("body")
        if raw_body is None:
            raw_body = {}
        if not isinstance(raw_body, dict):
            raise ValueError("body must be an object.")
        return method, path, raw_body
    if name == "list_tracked_subreddits":
        return "GET", "/api/v1/reddit/subs", None
    if name == "add_tracked_subreddit":
        body = {k: args[k] for k in ("name", "sort", "include", "exclude") if k in args}
        return "POST", "/api/v1/reddit/subs", body
    if name == "remove_tracked_subreddit":
        return "POST", "/api/v1/reddit/subs/remove", {"name": args["name"]}
    if name == "get_reddit_posts":
        query = {}
        for key in ("subreddit", "since", "keyword"):
            if args.get(key):
                query[key] = args[key]
        if "min_score" in args and args["min_score"] is not None:
            query["min_score"] = int(args["min_score"])
        if args.get("limit"):
            query["limit"] = int(args["limit"])
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/reddit/posts" + suffix, None
    if name == "get_reddit_post_comments":
        query = ("?limit=" + str(int(args["limit"]))) if args.get("limit") else ""
        return "GET", f"/api/v1/reddit/posts/{args['post_id']}/comments" + query, None
    if name == "search":
        query = {"q": args["q"]}
        if args.get("include"):
            query["include"] = ",".join(str(name) for name in args["include"])
        if args.get("exclude"):
            query["exclude"] = ",".join(str(name) for name in args["exclude"])
        return "GET", "/api/v1/search?" + urlencode(query), None
    raise ValueError(f"Unknown tool {name}")


def _result(text: str, is_error: bool = False) -> dict[str, Any]:
    return {"content": [{"type": "text", "text": text}], "isError": is_error}


class RequestLimiter:
    """Sliding window of tool calls, keyed by a hash of the bearer token.

    The raw token is not stored and is not written to the log.
    """

    def __init__(self, limit: int = 60, window: float = 60.0) -> None:
        self.limit = limit
        self.window = window
        self._lock = threading.Lock()
        self._hits: dict[str, list[float]] = {}

    def allow(self, authorization: str, now: float | None = None) -> bool:
        key = hashlib.sha256(authorization.encode("utf-8")).hexdigest()
        moment = time.monotonic() if now is None else now
        with self._lock:
            hits = [stamp for stamp in self._hits.get(key, []) if moment - stamp < self.window]
            if len(hits) >= self.limit:
                self._hits[key] = hits
                return False
            hits.append(moment)
            self._hits[key] = hits
            return True


def handle_message(
    message: dict[str, Any],
    authorization: str | None,
    fetch: Fetch,
    limiter: RequestLimiter | None = None,
) -> dict[str, Any] | None:
    """One JSON-RPC message. Notifications (no id) return None."""
    method = message.get("method")
    msg_id = message.get("id")
    if method == "notifications/initialized" or (method or "").startswith("notifications/"):
        return None

    def reply(result: Any = None, error: dict[str, Any] | None = None) -> dict[str, Any]:
        payload: dict[str, Any] = {"jsonrpc": "2.0", "id": msg_id}
        if error is not None:
            payload["error"] = error
        else:
            payload["result"] = result
        return payload

    if method == "initialize":
        return reply({
            "protocolVersion": PROTOCOL,
            "capabilities": {"tools": {}},
            "serverInfo": {"name": "lifedash", "version": "0.1.0"},
        })
    if method == "ping":
        return reply({})
    if method == "tools/list":
        if not authorization:
            return reply(error={"code": -32001, "message": "Authorization: Bearer is required."})
        return reply({"tools": TOOLS})
    if method == "tools/call":
        if not authorization:
            return reply(error={"code": -32001, "message": "Authorization: Bearer is required."})
        gate = limiter if limiter is not None else _PROCESS_LIMITER
        if not gate.allow(authorization):
            return reply(error={"code": -32029, "message": "Rate limit: 60 requests per minute for this token."})
        params = message.get("params") or {}
        name = str(params.get("name") or "")
        try:
            http_method, path, body = resolve(name, params.get("arguments") or {})
        except (KeyError, TypeError, ValueError) as exc:
            return reply(_result(str(exc), is_error=True))
        status, payload = fetch(http_method, path, body, authorization, name)
        text = payload if isinstance(payload, str) else json.dumps(payload, ensure_ascii=False)
        return reply(_result(text, is_error=status >= 400))
    return reply(error={"code": -32601, "message": f"Method not found: {method}"})


def urllib_fetch(api_base: str) -> Fetch:
    def fetch(method: str, path: str, body: dict[str, Any] | None, authorization: str, tool: str) -> tuple[int, Any]:
        data = None if body is None else json.dumps(body).encode("utf-8")
        req = Request(
            api_base.rstrip("/") + path,
            data=data,
            method=method,
            headers={
                "Accept": "application/json",
                "Authorization": authorization,
                "X-Lifedash-Tool": tool,
                **({"Content-Type": "application/json"} if data is not None else {}),
            },
        )
        try:
            with urlopen(req, timeout=30) as res:
                raw = res.read().decode("utf-8")
                status = res.status
        except HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            status = exc.code
        except URLError as exc:
            return 502, {"error": f"API unreachable: {exc.reason}"}
        try:
            parsed = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            parsed = raw
        return status, parsed

    return fetch


_PROCESS_LIMITER = RequestLimiter()


def make_handler(fetch: Fetch, limiter: RequestLimiter | None = None) -> type[BaseHTTPRequestHandler]:
    gate = limiter or RequestLimiter()

    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def do_POST(self) -> None:  # noqa: N802
            path = self.path.split("?", 1)[0]
            if path not in ("/mcp", "/mcp/", "/"):
                self._send(404, {"error": "not found"})
                return
            length = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(length) if length else b""
            try:
                message = json.loads(raw.decode("utf-8") or "{}")
            except json.JSONDecodeError:
                self._send(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "Parse error"}})
                return
            auth = self.headers.get("Authorization")
            if isinstance(message, list):
                replies = [r for item in message if isinstance(item, dict) and (r := handle_message(item, auth, fetch, gate)) is not None]
                self._send(200, replies)
                return
            if not isinstance(message, dict):
                self._send(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": "Invalid request"}})
                return
            reply = handle_message(message, auth, fetch, gate)
            if reply is None:
                self._send(202, None)
                return
            self._send(200, reply)

        def do_GET(self) -> None:  # noqa: N802
            if self.path.split("?", 1)[0] in ("/healthz", "/mcp"):
                self._send(200, {"ok": True, "service": "lifedash-mcp"})
                return
            self._send(404, {"error": "not found"})

        def log_message(self, fmt: str, *args: Any) -> None:
            LOG.info("%s - " + fmt, self.address_string(), *args)

        def _send(self, status: int, payload: Any) -> None:
            body = b"" if payload is None else json.dumps(payload).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            if body:
                self.wfile.write(body)

    return Handler


def main() -> None:
    config = Config.load()
    setup_logging(config.get("LOG_LEVEL", "INFO"))
    host = config.get("MCP_HOST", "0.0.0.0")
    port = int(config.get("MCP_PORT", "8765"))
    api_base = config.get("MCP_API_BASE", "http://127.0.0.1")
    server = ThreadingHTTPServer((host, port), make_handler(urllib_fetch(api_base)))
    LOG.info("lifedash-mcp listening on http://%s:%s/mcp → %s", host, port, api_base)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.shutdown()


if __name__ == "__main__":
    main()
