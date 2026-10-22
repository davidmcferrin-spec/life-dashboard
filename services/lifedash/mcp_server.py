"""``lifedash-mcp`` — MCP endpoint on port 8765.

Listens on 0.0.0.0 unless MCP_BIND=loopback. Cloudflare Tunnel sends ``/mcp`` here.
Each tool call is an HTTP request to the PHP ``/api/v1`` API with the caller's
bearer token and ``X-Lifedash-Tool``.
This process does not hold a token of its own and does not place trades.
The tool registry is the ``TOOLS`` list in this module: one list for every
caller, not a per-session cache and not a filter on protocol version or token scope.
"""
from __future__ import annotations

import hashlib
import json
import logging
import re
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode, urlparse
from urllib.request import Request, urlopen

from . import __version__
from .config import Config
from .log import setup as setup_logging

LOG = logging.getLogger("lifedash.mcp")
PROTOCOL = "2025-03-26"
# Echo the client's version when we know it. The tool list does not change with it.
SUPPORTED_PROTOCOLS = ("2024-11-05", "2025-03-26", "2025-06-18", "2025-11-25")

Fetch = Callable[[str, str, dict[str, Any] | None, str, str], tuple[int, Any]]

_DEBT_PROPS: dict[str, Any] = {
    "collector_name": {"type": "string"},
    "agency": {"type": "string"},
    "agency_address": {"type": "string"},
    "agency_phone": {"type": "string"},
    "website": {"type": "string"},
    "original_creditor": {"type": "string"},
    "reference_last4": {"type": "string"},
    "amount_claimed": {"type": "number"},
    "debt_date": {"type": "string"},
    "validation_requested": {"type": "boolean"},
    "verdict": {
        "type": "string",
        "enum": ["unverified", "likely_legit", "unclear", "suspicious", "known_scam"],
    },
    "scam_reasons": {"type": "string"},
    "verification_notes": {"type": "string"},
    "cfpb_complaints": {"type": "string"},
    "state_license": {"type": "string"},
    "ag_ftc_warnings": {"type": "string"},
    "evidence_urls": {"type": "array", "items": {"type": "string"}},
    "vetted_at": {"type": "string"},
    "referred_to_penny": {"type": "boolean"},
}

_CALL_PROPS: dict[str, Any] = {
    "call_at": {"type": "string"},
    "direction": {"type": "string", "enum": ["inbound", "outbound"]},
    "phone": {"type": "string"},
    "callback_number": {"type": "string"},
    "name": {"type": "string"},
    "company": {"type": "string"},
    "category": {
        "type": "string",
        "enum": ["work", "personal", "vip", "debt_collector", "spam", "urgent"],
    },
    "reason": {"type": "string"},
    "summary": {"type": "string"},
    "notes": {"type": "string"},
    "urgency": {"type": "string", "enum": ["low", "normal", "high", "urgent"]},
    "deadline": {"type": "string"},
    "return_needed": {"type": "boolean"},
    "status": {
        "type": "string",
        "enum": [
            "new", "needs_return", "returned", "waiting", "resolved",
            "spam", "ignored", "escalated_to_penny",
        ],
    },
    "duration_sec": {"type": "integer"},
    "transcript_url": {"type": "string"},
    "recording_url": {"type": "string"},
    "transcript_text": {"type": "string"},
    "has_recording": {"type": "boolean"},
    "source": {"type": "string", "enum": ["ai", "manual", "bland"]},
    "source_call_id": {"type": "string"},
    "linked_todo_id": {"type": ["integer", "null"]},
    "contact_id": {"type": ["integer", "null"]},
    "handled_by": {"type": "string", "enum": ["claire", "cos", "david"]},
    "notified_david": {"type": "boolean"},
    "notified_at": {"type": "string"},
    "debt": {
        "type": ["object", "null"],
        "properties": _DEBT_PROPS,
        "additionalProperties": False,
    },
}

_CONTACT_PROPS: dict[str, Any] = {
    "id": {"type": "integer"},
    "name": {"type": "string"},
    "phones": {"type": "array", "items": {"type": "string"}},
    "relationship": {"type": "string"},
    "vip_tier": {"type": "string"},
    "handling_rule": {"type": "string"},
    "notes": {"type": "string"},
}

_AGENCY_PROPS: dict[str, Any] = {
    "id": {"type": "integer"},
    "name": {"type": "string"},
    "phones": {"type": "array", "items": {"type": "string"}},
    "website": {"type": "string"},
    "verdict": {
        "type": "string",
        "enum": ["unverified", "likely_legit", "unclear", "suspicious", "known_scam"],
    },
    "notes": {"type": "string"},
    "evidence_urls": {"type": "array", "items": {"type": "string"}},
    "vetted_at": {"type": "string"},
}

_CALL_KEYS = tuple(_CALL_PROPS)

PANTRY_UNITS = "each, ct, head, bunch, lb, oz, g, kg, gal, qt, pt, ml, l, pkg, bag, box, can, bottle, dozen, slice, piece, serving"
PANTRY_UNIT_NOTE = " Units: " + PANTRY_UNITS + ". Plurals such as slices, pieces, and servings are accepted."


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
        "name": "create_habit",
        "description": (
            "Create a habit on the dashboard. key (or slug) is idempotent: if that key already "
            "exists, the existing habit is returned unchanged. frequency defaults to daily. "
            "Also accepts weekdays, weekly, or a number 1–7 (days per week). icon is an emoji. "
            "color is #rrggbb. Optional goal_metric_id is a number metric; goal_threshold is the "
            "day's last value that checks the habit off (omit it to use that metric's goal). "
            "An existing key is returned unchanged — use update_habit to link it."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "key": {"type": "string"},
                "slug": {"type": "string"},
                "icon": {"type": "string"},
                "color": {"type": "string"},
                "frequency": {"type": ["string", "integer"]},
                "target": {"type": ["string", "integer"]},
                "sort_order": {"type": "integer"},
                "goal_metric_id": {"type": "integer"},
                "goal_threshold": {"type": "number"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_habit",
        "description": (
            "Change a habit's name, icon, color, frequency, or sort order. The key cannot change. "
            "goal_metric_id links a number metric; the habit is checked when that day's last value "
            "reaches goal_threshold (or the metric's goal when threshold is omitted). "
            "goal_metric_id 0 removes the link. A manual toggle still works."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "metric_id": {"type": "integer"},
                "name": {"type": "string"},
                "icon": {"type": "string"},
                "color": {"type": "string"},
                "frequency": {"type": ["string", "integer"]},
                "target": {"type": ["string", "integer"]},
                "sort_order": {"type": "integer"},
                "goal_metric_id": {"type": "integer"},
                "goal_threshold": {"type": "number"},
            },
            "required": ["metric_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "archive_habit",
        "description": "Hide a habit. Pass archived false to restore it. Check-ins are kept.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "metric_id": {"type": "integer"},
                "archived": {"type": "boolean"},
            },
            "required": ["metric_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "reorder_habits",
        "description": "Set habit display order. ids is the order to show, first id first.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "ids": {"type": "array", "items": {"type": "integer"}},
            },
            "required": ["ids"],
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
        "description": (
            "What a widget is currently showing, including the latest YouTube, Reddit, and X cards. "
            "A goal widget returns view (day or week) and goals, one per metric (1–4): mode amount or count, "
            "goal, today's value, ratio capped at 2 (200%), percent, increments, dedupe_key, and pips for a count. "
            "Week view adds seven day cells on each goal. "
            "config.metric_ids lists the metrics. config.logs, keyed by metric id, stores mode, increments, and dedupe_prefix. "
            "POST /widgets/{id}/goal-log with delta and metric_id writes today's running total "
            "(water_ml uses water:YYYY-MM-DD, movement_sessions uses movement:YYYY-MM-DD); last value wins."
        ),
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
            "A goal widget uses config.metric_ids (1–4 number metrics), config.view day or week, and "
            "config.logs keyed by metric id (mode amount or count, increments, dedupe_prefix). "
            "Amount increments default to 250,500. Count increments default to 1. "
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
        "name": "list_shopping_lists",
        "description": "Shopping lists for this user, such as Groceries, Costco, or Hardware. The first call creates Groceries when none exist.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "create_shopping_list",
        "description": "Create a shopping list. The same name returns the existing list.",
        "inputSchema": {
            "type": "object",
            "properties": {"name": {"type": "string"}},
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_shopping_items",
        "description": "Items on a shopping list. Bought items older than clear_hours are removed. clear_hours 0 keeps them.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "list_id": {"type": "integer"},
                "include_bought": {"type": "boolean"},
                "clear_hours": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "add_shopping_items",
        "description": (
            "Add items to a list. Each item is a string such as '2 lb chicken', or an object with "
            "name, quantity, unit, store, category, and notes. An open item with the same name and unit "
            "gets its quantity increased. Category is guessed from the name when omitted."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "list_id": {"type": "integer"},
                "items": {"type": "array", "items": {"type": ["string", "object"]}},
            },
            "required": ["list_id", "items"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_shopping_item",
        "description": "Change an item's name, quantity, unit, store, category, or notes.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "item_id": {"type": "integer"},
                "name": {"type": "string"},
                "quantity": {"type": "number"},
                "unit": {"type": "string"},
                "store": {"type": "string"},
                "category": {"type": "string"},
                "notes": {"type": "string"},
            },
            "required": ["item_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "check_shopping_item",
        "description": "Mark a shopping item bought. Pass bought false to return it to the list.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "item_id": {"type": "integer"},
                "bought": {"type": "boolean"},
            },
            "required": ["item_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "remove_shopping_item",
        "description": "Delete one shopping item.",
        "inputSchema": {
            "type": "object",
            "properties": {"item_id": {"type": "integer"}},
            "required": ["item_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "clear_bought",
        "description": "Remove every bought item on a list. Omit list_id to clear every list.",
        "inputSchema": {
            "type": "object",
            "properties": {"list_id": {"type": "integer"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "log_call",
        "description": (
            "Log a call. The same source_call_id updates the existing row. create_call is this tool. "
            "debt is the collector record. reference_last4 is stored as the last 4 digits. "
            "Do not send a full account number or an SSN. An SSN-shaped value is rejected."
        ),
        "inputSchema": {
            "type": "object",
            "properties": _CALL_PROPS,
            "required": ["direction"],
            "additionalProperties": False,
        },
    },
    {
        "name": "create_call",
        "description": "Alias of log_call. Upserts on source_call_id and accepts every call field.",
        "inputSchema": {
            "type": "object",
            "properties": _CALL_PROPS,
            "required": ["direction"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_call",
        "description": "One call, including debt, contact, transcript, and recording fields.",
        "inputSchema": {
            "type": "object",
            "properties": {"call_id": {"type": "integer"}},
            "required": ["call_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_call",
        "description": (
            "Change any call field. Pass debt to replace the collector record, or debt null to remove it. "
            "Omitted fields stay as they are. reference_last4 is last 4 only."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"call_id": {"type": "integer"}, **_CALL_PROPS},
            "required": ["call_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "set_call_status",
        "description": "Set status. Returned, resolved, spam, and ignored clear return_needed unless you pass it.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "call_id": {"type": "integer"},
                "status": {
                    "type": "string",
                    "enum": ["new", "needs_return", "returned", "waiting", "resolved", "spam", "ignored", "escalated_to_penny"],
                },
                "return_needed": {"type": "boolean"},
            },
            "required": ["call_id", "status"],
            "additionalProperties": False,
        },
    },
    {
        "name": "link_call_todo",
        "description": "Attach a dashboard task to a call. todo_id null clears the link.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "call_id": {"type": "integer"},
                "todo_id": {"type": ["integer", "null"]},
            },
            "required": ["call_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_calls",
        "description": "Calls, open returns first. Filter by status, category, urgency, return_needed, from, to, phone, or handled_by.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "status": {"type": "string"},
                "category": {"type": "string"},
                "urgency": {"type": "string"},
                "return_needed": {"type": "boolean"},
                "from": {"type": "string"},
                "to": {"type": "string"},
                "phone": {"type": "string"},
                "handled_by": {"type": "string"},
                "limit": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "search_calls",
        "description": "Find calls by name, phone, company, reason, summary, notes, or transcript.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "q": {"type": "string"},
                "limit": {"type": "integer"},
            },
            "required": ["q"],
            "additionalProperties": False,
        },
    },
    {
        "name": "call_stats",
        "description": "Counts by category and status, plus open returns, urgent, debt, and VIP.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "lookup_caller",
        "description": "Match a phone number to a contact and that person's earlier calls.",
        "inputSchema": {
            "type": "object",
            "properties": {"phone": {"type": "string"}},
            "required": ["phone"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_contacts",
        "description": "Saved contacts: phones, relationship, VIP tier, and the handling rule for Norma.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "upsert_contact",
        "description": "Create or update a contact. Matches an id, then a phone, then a unique name.",
        "inputSchema": {
            "type": "object",
            "properties": _CONTACT_PROPS,
            "additionalProperties": False,
        },
    },
    {
        "name": "list_vetted_agencies",
        "description": "Agencies already vetted, with phones, website, verdict, and evidence URLs.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "upsert_vetted_agency",
        "description": "Create or update a vetted agency by id or name. Verdict applies to later debt logs that omit one.",
        "inputSchema": {
            "type": "object",
            "properties": _AGENCY_PROPS,
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
    {
        "name": "list_pantry_units",
        "description": "Canonical pantry units. " + PANTRY_UNITS + ". Plurals such as slices, pieces, and servings normalize to the singular.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "list_pantry",
        "description": "Pantry lots. Omit status for on hand. status any is every lot except tossed. Also filter by location (pantry, fridge, freezer), category, search, or expiring_within_days. Each lot includes use_by and freshness.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "location": {"type": "string"},
                "category": {"type": "string"},
                "status": {"type": "string"},
                "search": {"type": "string"},
                "expiring_within_days": {"type": "integer"},
                "soon_days": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "get_product_by_upc",
        "description": "Look up a UPC in the local catalog. external true also asks Open Food Facts, then UPCitemdb, and does not save a product. A miss leaves the name blank.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "upc": {"type": "string"},
                "external": {"type": "boolean"},
            },
            "required": ["upc"],
            "additionalProperties": False,
        },
    },
    {
        "name": "upsert_product",
        "description": "Create or update a catalog product. Pass product_id to edit. Shelf-life days left empty inherit the category default. units_per_package and piece_unit describe a pack, such as 11 slices. Products with no pack size are unchanged." + PANTRY_UNIT_NOTE,
        "inputSchema": {
            "type": "object",
            "properties": {
                "product_id": {"type": "integer"},
                "upc": {"type": "string"},
                "name": {"type": "string"},
                "brand": {"type": "string"},
                "category": {"type": "string"},
                "default_size": {"type": "string"},
                "default_unit": {"type": "string"},
                "units_per_package": {"type": "integer"},
                "piece_unit": {"type": "string"},
                "shelf_life_pantry_days": {"type": "integer"},
                "shelf_life_fridge_days": {"type": "integer"},
                "shelf_life_freezer_days": {"type": "integer"},
                "opened_life_days": {"type": "integer"},
                "min_on_hand": {"type": "number"},
                "image_url": {"type": "string"},
                "notes": {"type": "string"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_products",
        "description": "Product catalog, including produce with no UPC. search matches name, brand, or UPC.",
        "inputSchema": {
            "type": "object",
            "properties": {"search": {"type": "string"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "add_pantry_items",
        "description": "Add lots. Each item needs upc, product_id, or name. use_by is filled from the location shelf life unless you set it. Freezer life starts today. Fridge and pantry life starts on the purchase date. location is pantry, fridge, or freezer. When the product has a pack size, a missing unit or a package unit (pkg, box, bag, each) is packages times units_per_package, stored in the piece unit. Passing the piece unit keeps that quantity." + PANTRY_UNIT_NOTE,
        "inputSchema": {
            "type": "object",
            "properties": {
                "items": {"type": "array", "items": {"type": "object"}},
            },
            "required": ["items"],
            "additionalProperties": False,
        },
    },
    {
        "name": "consume_pantry_item",
        "description": "Consume by name, upc, or lot_id. Omit quantity to take one unit. Quantity may be a fraction, such as 0.5. Lots are taken soonest use_by first and can span lots. A counted lot stays on hand while any quantity remains and is marked used at zero. A lot with qty_level steps full or most to half, half to low, and low or nearly_empty to empty. Empty marks the lot used.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "upc": {"type": "string"},
                "lot_id": {"type": "integer"},
                "quantity": {"type": "number"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "split_or_move_lot",
        "description": "Move a lot, or split it. Quantities are the lot unit (slices or bottles after a pack is expanded), not packages. pieces is a list of {quantity, location} or {quantity, consume: true} that adds up to the lot, so one call can put 2 in the fridge and 2 in the freezer. Each piece keeps the product, unit, purchase date, and opened_at. A piece that changes place recomputes use_by: freezer life from the move date, fridge or pantry life from the purchase date, and the earlier of that date and opened life when the lot is open. An overridden use_by is kept on a whole-lot move unless reset_use_by is true.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "lot_id": {"type": "integer"},
                "location": {"type": "string"},
                "quantity": {"type": "number"},
                "reset_use_by": {"type": "boolean"},
                "pieces": {"type": "array", "items": {"type": "object"}},
            },
            "required": ["lot_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "open_lot",
        "description": "Mark a lot opened and always recompute use_by. The date is opened today plus opened life when that life is known (the product's opened days, otherwise the category default), otherwise the location life. When both exist, the earlier date wins. Freezer life starts today. Fridge and pantry life starts on the purchase date.",
        "inputSchema": {
            "type": "object",
            "properties": {"lot_id": {"type": "integer"}},
            "required": ["lot_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "toss_lot",
        "description": "Mark one or many lots tossed and log a toss event for each. Use this for a purge.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "lot_id": {"type": "integer"},
                "lot_ids": {"type": "array", "items": {"type": "integer"}},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "adjust_lot",
        "description": "Change quantity, location, unit, use_by, qty_level, store, or purchased_at. qty_level is full, most, half, low, nearly_empty, or empty. Setting use_by overrides the calculated date. Quantity 0 marks the lot used. A quantity above zero puts a used lot back on hand. Tossed and expired lots stay closed. reset_use_by recomputes from opened life and the location life, and keeps the earlier date when the lot is open." + PANTRY_UNIT_NOTE,
        "inputSchema": {
            "type": "object",
            "properties": {
                "lot_id": {"type": "integer"},
                "quantity": {"type": "number"},
                "location": {"type": "string"},
                "unit": {"type": "string"},
                "qty_level": {"type": "string"},
                "use_by": {"type": "string"},
                "store": {"type": "string"},
                "purchased_at": {"type": "string"},
                "reset_use_by": {"type": "boolean"},
            },
            "required": ["lot_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "expiring_items",
        "description": "On-hand lots due within days, including lots already past use_by. days defaults to 3.",
        "inputSchema": {
            "type": "object",
            "properties": {"days": {"type": "integer"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "low_stock",
        "description": "Products below min_on_hand, plus on-hand lots at low, nearly_empty, or empty, plus a product whose last lot was used up at empty. add_to_shopping_list adds the shortfall to list_id (default 1, Groceries) and skips a name already unchecked on that list.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "add_to_shopping_list": {"type": "boolean"},
                "list_id": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "pantry_stats",
        "description": "Quantity used and tossed per product between from and to (YYYY-MM-DD). Defaults to the last 30 days.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "from": {"type": "string"},
                "to": {"type": "string"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "import_order",
        "description": "Create lots from an order. Each line needs a name, a known UPC, or both. Lines that cannot be matched exactly are returned as unmatched and are not guessed. A product with a pack size expands a line that omits unit, or uses pkg, box, bag, or each, into piece units. A line that already uses the piece unit is not expanded." + PANTRY_UNIT_NOTE,
        "inputSchema": {
            "type": "object",
            "properties": {
                "store": {"type": "string"},
                "order_ref": {"type": "string"},
                "purchased_at": {"type": "string"},
                "items": {"type": "array", "items": {"type": "object"}},
            },
            "required": ["items"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_recipes",
        "description": "Recipes and their ingredients (a product or a category, with an optional quantity).",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "upsert_recipe",
        "description": "Create a recipe, or replace it when recipe_id is set. ingredients is a list of {product_id or category, quantity, unit}.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "recipe_id": {"type": "integer"},
                "name": {"type": "string"},
                "notes": {"type": "string"},
                "ingredients": {"type": "array", "items": {"type": "object"}},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_recipe",
        "description": "Replace a recipe's name, notes, and ingredients. ingredients is a list of {product_id or category, quantity, unit}.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "recipe_id": {"type": "integer"},
                "name": {"type": "string"},
                "notes": {"type": ["string", "null"]},
                "ingredients": {"type": "array", "items": {"type": "object"}},
            },
            "required": ["recipe_id", "name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_pantry_category",
        "description": "Edit one category's shelf-life days. pantry_days, fridge_days, freezer_days, and opened_days are a whole number from 1 to 3650, or null to clear that place. Omitted fields stay as they are. Requires pantry:write, the same scope as the other pantry writes.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "category": {"type": "string"},
                "pantry_days": {"type": ["integer", "null"]},
                "fridge_days": {"type": ["integer", "null"]},
                "freezer_days": {"type": ["integer", "null"]},
                "opened_days": {"type": ["integer", "null"]},
            },
            "required": ["category"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_pantry_product",
        "description": "Set a product's own shelf-life days. Each field is a whole number from 1 to 3650, or null so that place inherits the category. Omitted fields stay as they are. Requires pantry:write.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "product_id": {"type": "integer"},
                "shelf_life_pantry_days": {"type": ["integer", "null"]},
                "shelf_life_fridge_days": {"type": ["integer", "null"]},
                "shelf_life_freezer_days": {"type": ["integer", "null"]},
                "opened_life_days": {"type": ["integer", "null"]},
            },
            "required": ["product_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "what_can_i_make",
        "description": "Rank recipes by how much of each ingredient is on hand, then by the soonest use_by among the matching lots.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "list_meal_components",
        "description": (
            "Meal builder components. category is protein, base, sauce, side, aromatic, vegetable, or spice. "
            "on_hand true keeps components whose linked product has an active lot; false keeps the rest; omit it for both. "
            "Requires pantry:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "category": {"type": "string"},
                "on_hand": {"type": "boolean"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "add_meal_component",
        "description": (
            "Add a meal component. category is protein, base, sauce, side, aromatic, vegetable, or spice. "
            "source is david, past_meal, or research. product_id is optional and may be null. "
            "Requires pantry:write. A token write is refused until Allow MCP updates is on."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "category": {"type": "string"},
                "product_id": {"type": ["integer", "null"]},
                "notes": {"type": ["string", "null"]},
                "source": {"type": "string"},
            },
            "required": ["name", "category", "source"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_meal_component",
        "description": (
            "Change a meal component. Omitted fields stay. product_id null clears the pantry link. "
            "category and source use the same values as add_meal_component. "
            "Requires pantry:write. A token write is refused until Allow MCP updates is on."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "component_id": {"type": "integer"},
                "name": {"type": "string"},
                "category": {"type": "string"},
                "product_id": {"type": ["integer", "null"]},
                "notes": {"type": ["string", "null"]},
                "source": {"type": "string"},
            },
            "required": ["component_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "remove_meal_component",
        "description": (
            "Delete a meal component and the pairings that use it. "
            "Requires pantry:write. A token write is refused until Allow MCP updates is on."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"component_id": {"type": "integer"}},
            "required": ["component_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "add_meal_pairing",
        "description": (
            "Store that two components go together. Pass component_a_id or component_a, and component_b_id or component_b. "
            "The pair is stored once, with the lower id first, and a second call updates it. "
            "score is 1 to 5 or null. source is david, past_meal, or research, and is required when the pair is new. "
            "Requires pantry:write. A token write is refused until Allow MCP updates is on."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "component_a_id": {"type": "integer"},
                "component_a": {"type": "string"},
                "component_b_id": {"type": "integer"},
                "component_b": {"type": "string"},
                "score": {"type": ["integer", "null"]},
                "note": {"type": ["string", "null"]},
                "source": {"type": "string"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "remove_meal_pairing",
        "description": (
            "Remove one pairing. Pass component_a_id or component_a, and component_b_id or component_b. "
            "Requires pantry:write. A token write is refused until Allow MCP updates is on."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "component_a_id": {"type": "integer"},
                "component_a": {"type": "string"},
                "component_b_id": {"type": "integer"},
                "component_b": {"type": "string"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "list_meal_pairings",
        "description": "Pairings stored for one component. Requires pantry:read.",
        "inputSchema": {
            "type": "object",
            "properties": {"component_id": {"type": "integer"}},
            "required": ["component_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "suggest_meal",
        "description": (
            "For the picked component ids, return each filter category's components that pair with every pick, "
            "supporting aromatic, vegetable, and spice suggestions ranked by how many picks they pair with and then by score, "
            "and on-hand flags. made_before matches a recipe when its protein, base, sauce, and side ingredients "
            "are exactly the picks. Aromatic, vegetable, spice, and unmapped ingredients are not compared. "
            "Each match includes also_uses, a list of those ignored ingredients: name, category or null, "
            "component_id or null, and on_hand. A recipe with no filter-category ingredient is not a match. "
            "matching_recipes lists recipes whose protein, base, sauce, and side ingredients include every pick. "
            "Exact matches come first, then the fewest extra filter-category ingredients, then the most ingredients "
            "on hand, then the name. An empty pick list returns every recipe, sorted by ingredients on hand. "
            "Each matching_recipes item has exact, picks (the filter-category component ids), and ingredients "
            "with name, category or null, component_id or null, and on_hand. made_before is unchanged. "
            "An empty pick list returns every component. on_hand_only hides components that are not on hand. "
            "There is no meal log. Requires pantry:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "picks": {"type": "array", "items": {"type": "integer"}},
                "on_hand_only": {"type": "boolean"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "deploy_status",
        "description": (
            "Current commit, origin/main, commits on origin/main that are not checked out (sha and title), "
            "pending migrations, the last deploy, and mcp_updates (whether deploy_update is allowed). "
            "Fetches origin main first (15s timeout, reused for about 60s). A fetch failure is git_error; "
            "the last known origin/main is still returned. Requires system:read. Does not deploy."
        ),
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "deploy_update",
        "description": (
            "Queue an update of main to confirm_sha. confirm_sha must be the origin/main commit from "
            "deploy_status, 40 lowercase hex. Fetches origin main before that compare and refuses to "
            "queue while git_error is set. dry_run checks that and does not deploy. Returns a job id "
            "immediately; poll deploy_status and deploy_log. Requires system:deploy. Refused with "
            "'MCP updates are disabled in Settings' until an admin turns Allow MCP updates on. "
            "No other ref or command."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "confirm_sha": {"type": "string"},
                "dry_run": {"type": "boolean"},
            },
            "required": ["confirm_sha"],
            "additionalProperties": False,
        },
    },
    {
        "name": "deploy_log",
        "description": "Output of the last deploy, or of job_id (32 lowercase hex). Requires system:read.",
        "inputSchema": {
            "type": "object",
            "properties": {"job_id": {"type": "string"}},
            "additionalProperties": False,
        },
    },
]


def _query(args: dict[str, Any], keys: tuple[str, ...]) -> str:
    query = {key: args[key] for key in keys if args.get(key) not in (None, "")}
    return ("?" + urlencode(query)) if query else ""


def _pick(args: dict[str, Any], keys: tuple[str, ...]) -> dict[str, Any]:
    return {key: args[key] for key in keys if key in args and args[key] is not None}


_MEAL_CATEGORIES = ("protein", "base", "sauce", "side", "aromatic", "vegetable", "spice")
_MEAL_SOURCES = ("david", "past_meal", "research")


def _meal_category(value: Any) -> str:
    text = str(value).strip().lower()
    if text not in _MEAL_CATEGORIES:
        raise ValueError("Category must be protein, base, sauce, side, aromatic, vegetable, or spice.")
    return text


def _meal_source(value: Any) -> str:
    text = str(value).strip().lower()
    if text not in _MEAL_SOURCES:
        raise ValueError("Source must be david, past_meal, or research.")
    return text


def _meal_score(value: Any) -> Any:
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, int) or value < 1 or value > 5:
        raise ValueError("Score must be a whole number from 1 to 5, or empty.")
    return value


def _meal_kept(args: dict[str, Any], keys: tuple[str, ...]) -> dict[str, Any]:
    body = {key: args[key] for key in keys if key in args}
    if "category" in body:
        body["category"] = _meal_category(body["category"])
    if "source" in body:
        body["source"] = _meal_source(body["source"])
    if "score" in body:
        body["score"] = _meal_score(body["score"])
    return body


def _call_body(args: dict[str, Any]) -> dict[str, Any]:
    """Every call field the caller sent, including null and false. call_id stays in the path."""
    body = {key: args[key] for key in _CALL_KEYS if key in args}
    debt = body.get("debt")
    if debt is not None and not isinstance(debt, dict):
        raise ValueError("debt must be an object.")
    return body


_LEGACY = ("bookmarks", "todos", "flights", "packages", "stocks", "clipboards")
_CLOSED = ("/favicon", "/capture", "/shares/link", "/files", "/storage", "/cloudpad", "/tokens", "/trade", "/order", "/system")


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
    if name == "create_habit":
        body = _pick(args, ("name", "key", "slug", "icon", "color", "frequency", "target", "sort_order", "goal_metric_id", "goal_threshold"))
        return "POST", "/api/v1/habits", body
    if name == "update_habit":
        body = _pick(args, ("name", "icon", "color", "frequency", "target", "sort_order", "goal_metric_id", "goal_threshold"))
        return "POST", f"/api/v1/habits/{int(args['metric_id'])}/update", body
    if name == "archive_habit":
        body = {"archived": bool(args["archived"])} if "archived" in args else {}
        return "POST", f"/api/v1/habits/{int(args['metric_id'])}/archive", body
    if name == "reorder_habits":
        ids = args.get("ids")
        if not isinstance(ids, list) or not ids:
            raise ValueError("ids must be a list of habit ids.")
        return "POST", "/api/v1/habits/reorder", {"ids": [int(i) for i in ids]}
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
    if name == "list_shopping_lists":
        return "GET", "/api/v1/shopping/lists", None
    if name == "create_shopping_list":
        return "POST", "/api/v1/shopping/lists", {"name": args["name"]}
    if name == "list_shopping_items":
        return "GET", "/api/v1/shopping/items" + _query(args, ("list_id", "include_bought", "clear_hours")), None
    if name == "add_shopping_items":
        return "POST", "/api/v1/shopping/items", {"list_id": int(args["list_id"]), "items": args["items"]}
    if name == "update_shopping_item":
        body = _pick(args, ("name", "quantity", "unit", "store", "category", "notes"))
        return "POST", f"/api/v1/shopping/items/{int(args['item_id'])}", body
    if name == "check_shopping_item":
        body = {} if "bought" not in args else {"bought": bool(args["bought"])}
        return "POST", f"/api/v1/shopping/items/{int(args['item_id'])}/check", body
    if name == "remove_shopping_item":
        return "POST", f"/api/v1/shopping/items/{int(args['item_id'])}/delete", {}
    if name == "clear_bought":
        if args.get("list_id"):
            return "POST", f"/api/v1/shopping/lists/{int(args['list_id'])}/clear-bought", {}
        return "POST", "/api/v1/shopping/clear-bought", {}
    if name in ("log_call", "create_call"):
        return "POST", "/api/v1/calls", _call_body(args)
    if name == "get_call":
        return "GET", f"/api/v1/calls/{int(args['call_id'])}", None
    if name == "update_call":
        return "POST", f"/api/v1/calls/{int(args['call_id'])}", _call_body(args)
    if name == "set_call_status":
        body = {"status": args["status"]}
        if "return_needed" in args:
            body["return_needed"] = bool(args["return_needed"])
        return "POST", f"/api/v1/calls/{int(args['call_id'])}/status", body
    if name == "link_call_todo":
        todo = args.get("todo_id")
        return "POST", f"/api/v1/calls/{int(args['call_id'])}/todo", {"todo_id": todo}
    if name == "list_calls":
        return "GET", "/api/v1/calls" + _query(args, ("status", "category", "urgency", "return_needed", "from", "to", "phone", "handled_by", "limit")), None
    if name == "search_calls":
        return "GET", "/api/v1/calls/query" + _query(args, ("q", "limit")), None
    if name == "call_stats":
        return "GET", "/api/v1/calls/stats", None
    if name == "lookup_caller":
        return "GET", "/api/v1/calls/lookup" + _query(args, ("phone",)), None
    if name == "list_contacts":
        return "GET", "/api/v1/calls/contacts", None
    if name == "upsert_contact":
        return "POST", "/api/v1/calls/contacts", _pick(args, tuple(_CONTACT_PROPS))
    if name == "list_vetted_agencies":
        return "GET", "/api/v1/calls/agencies", None
    if name == "upsert_vetted_agency":
        return "POST", "/api/v1/calls/agencies", _pick(args, tuple(_AGENCY_PROPS))
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
    if name == "list_pantry_units":
        if args:
            raise ValueError("list_pantry_units takes no arguments.")
        return "GET", "/api/v1/pantry/units", None
    if name == "list_pantry":
        return "GET", "/api/v1/pantry/lots" + _query(args, ("location", "category", "status", "search", "expiring_within_days", "soon_days")), None
    if name == "get_product_by_upc":
        external = "1" if args.get("external") is True else "0"
        return "GET", "/api/v1/pantry/products/upc/" + quote(str(args["upc"]), safe="") + "?external=" + external, None
    if name == "upsert_product":
        return "POST", "/api/v1/pantry/products", _pick(args, (
            "product_id", "upc", "name", "brand", "category", "default_size", "default_unit",
            "units_per_package", "piece_unit",
            "shelf_life_pantry_days", "shelf_life_fridge_days", "shelf_life_freezer_days",
            "opened_life_days", "min_on_hand", "image_url", "notes",
        ))
    if name == "list_products":
        return "GET", "/api/v1/pantry/products" + _query(args, ("search",)), None
    if name == "add_pantry_items":
        return "POST", "/api/v1/pantry/lots", {"items": args["items"]}
    if name == "consume_pantry_item":
        return "POST", "/api/v1/pantry/consume", _pick(args, ("name", "upc", "lot_id", "quantity"))
    if name == "split_or_move_lot":
        body = _pick(args, ("location", "quantity", "reset_use_by", "pieces"))
        return "POST", f"/api/v1/pantry/lots/{int(args['lot_id'])}/split", body
    if name == "open_lot":
        return "POST", f"/api/v1/pantry/lots/{int(args['lot_id'])}/open", {}
    if name == "toss_lot":
        if args.get("lot_id") and not args.get("lot_ids"):
            return "POST", f"/api/v1/pantry/lots/{int(args['lot_id'])}/toss", {}
        ids = args.get("lot_ids") or ([int(args["lot_id"])] if args.get("lot_id") else [])
        return "POST", "/api/v1/pantry/purge", {"lot_ids": ids}
    if name == "adjust_lot":
        body = _pick(args, ("quantity", "location", "unit", "qty_level", "use_by", "store", "purchased_at", "reset_use_by"))
        return "POST", f"/api/v1/pantry/lots/{int(args['lot_id'])}", body
    if name == "expiring_items":
        return "GET", "/api/v1/pantry/expiring" + _query(args, ("days",)), None
    if name == "low_stock":
        if args.get("add_to_shopping_list") is True:
            body = {}
            if args.get("list_id"):
                body["list_id"] = int(args["list_id"])
            return "POST", "/api/v1/pantry/low-stock", body
        return "GET", "/api/v1/pantry/low-stock", None
    if name == "pantry_stats":
        return "GET", "/api/v1/pantry/stats" + _query(args, ("from", "to")), None
    if name == "import_order":
        return "POST", "/api/v1/pantry/import-order", _pick(args, ("store", "order_ref", "purchased_at", "items"))
    if name == "list_recipes":
        if args:
            raise ValueError("list_recipes takes no arguments.")
        return "GET", "/api/v1/pantry/recipes", None
    if name == "upsert_recipe":
        return "POST", "/api/v1/pantry/recipes", _pick(args, ("recipe_id", "name", "notes", "ingredients"))
    if name == "update_recipe":
        body = {key: args[key] for key in ("name", "notes", "ingredients") if key in args}
        return "POST", f"/api/v1/pantry/recipes/{int(args['recipe_id'])}", body
    if name == "update_pantry_category":
        body = {key: args[key] for key in ("category", "pantry_days", "fridge_days", "freezer_days", "opened_days") if key in args}
        return "POST", "/api/v1/pantry/categories", body
    if name == "update_pantry_product":
        body = {
            key: args[key]
            for key in ("shelf_life_pantry_days", "shelf_life_fridge_days", "shelf_life_freezer_days", "opened_life_days")
            if key in args
        }
        return "POST", f"/api/v1/pantry/products/{int(args['product_id'])}", body
    if name == "what_can_i_make":
        if args:
            raise ValueError("what_can_i_make takes no arguments.")
        return "GET", "/api/v1/pantry/what-can-i-make", None
    if name == "list_meal_components":
        query: dict[str, Any] = {}
        if args.get("category"):
            query["category"] = _meal_category(args["category"])
        if "on_hand" in args and args["on_hand"] is not None:
            if not isinstance(args["on_hand"], bool):
                raise ValueError("on_hand must be true or false.")
            query["on_hand"] = "1" if args["on_hand"] else "0"
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/pantry/meals/components" + suffix, None
    if name == "add_meal_component":
        return "POST", "/api/v1/pantry/meals/components", _meal_kept(args, ("name", "category", "product_id", "notes", "source"))
    if name == "update_meal_component":
        body = _meal_kept(args, ("name", "category", "product_id", "notes", "source"))
        return "POST", f"/api/v1/pantry/meals/components/{int(args['component_id'])}", body
    if name == "remove_meal_component":
        return "POST", f"/api/v1/pantry/meals/components/{int(args['component_id'])}/remove", {}
    if name == "add_meal_pairing":
        return "POST", "/api/v1/pantry/meals/pairings", _meal_kept(
            args, ("component_a_id", "component_a", "component_b_id", "component_b", "score", "note", "source")
        )
    if name == "remove_meal_pairing":
        return "POST", "/api/v1/pantry/meals/pairings/remove", _meal_kept(
            args, ("component_a_id", "component_a", "component_b_id", "component_b")
        )
    if name == "list_meal_pairings":
        return "GET", f"/api/v1/pantry/meals/components/{int(args['component_id'])}/pairings", None
    if name == "suggest_meal":
        query = {}
        picks = args.get("picks") or []
        if picks:
            if not isinstance(picks, list) or any(isinstance(pick, bool) or not isinstance(pick, int) or pick < 1 for pick in picks):
                raise ValueError("picks must be a list of component ids.")
            query["picks"] = ",".join(str(pick) for pick in picks)
        if "on_hand_only" in args and args["on_hand_only"] is not None:
            if not isinstance(args["on_hand_only"], bool):
                raise ValueError("on_hand_only must be true or false.")
            query["on_hand_only"] = "1" if args["on_hand_only"] else "0"
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/pantry/meals" + suffix, None
    if name == "deploy_status":
        if args:
            raise ValueError("deploy_status takes no arguments.")
        return "GET", "/api/v1/system/deploy/status", None
    if name == "deploy_log":
        unknown = set(args) - {"job_id"}
        if unknown:
            raise ValueError("deploy_log accepts only job_id.")
        job = args.get("job_id")
        if job not in (None, ""):
            if not isinstance(job, str) or re.fullmatch(r"[0-9a-f]{32}", job) is None:
                raise ValueError("job_id must be 32 lowercase hex characters.")
            return "GET", "/api/v1/system/deploy/log?job_id=" + job, None
        return "GET", "/api/v1/system/deploy/log", None
    if name == "deploy_update":
        unknown = set(args) - {"confirm_sha", "dry_run"}
        if unknown:
            raise ValueError("deploy_update accepts only confirm_sha and dry_run.")
        sha = args.get("confirm_sha")
        if not isinstance(sha, str) or re.fullmatch(r"[0-9a-f]{40}", sha) is None:
            raise ValueError("confirm_sha must be 40 lowercase hex characters.")
        body: dict[str, Any] = {"confirm_sha": sha}
        if "dry_run" in args:
            if not isinstance(args["dry_run"], bool):
                raise ValueError("dry_run must be true or false.")
            body["dry_run"] = args["dry_run"]
        return "POST", "/api/v1/system/deploy", body
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


# Same areas as PHP Scope::AREAS. The discovery document is served here so a
# connector can read it without a second hop. system:* stays explicit.
OAUTH_AREAS = (
    "metrics", "widgets", "life", "pages", "money", "invest", "search", "reddit",
    "bookmarks", "todos", "flights", "packages", "stocks", "clipboards", "audit",
    "shopping", "calls", "pantry",
)


class RpcReply(dict[str, Any]):
    """JSON-RPC object plus the HTTP status the MCP endpoint should send."""

    http_status: int = 200


def oauth_scopes() -> list[str]:
    scopes: list[str] = []
    for area in OAUTH_AREAS:
        scopes.append(f"{area}:read")
        scopes.append(f"{area}:write")
    scopes.extend(["system:read", "system:deploy"])
    return scopes


def metadata_url(mcp_public_url: str) -> str | None:
    raw = (mcp_public_url or "").strip()
    if not raw:
        return None
    parts = urlparse(raw)
    if parts.scheme not in ("https", "http") or not parts.hostname:
        return None
    origin = f"{parts.scheme}://{parts.hostname}"
    if parts.port:
        origin += f":{parts.port}"
    path = (parts.path or "").strip("/")
    if not path:
        return origin + "/.well-known/oauth-protected-resource"
    return origin + "/.well-known/oauth-protected-resource/" + path


def www_authenticate(mcp_public_url: str) -> str:
    url = metadata_url(mcp_public_url)
    if not url:
        return "Bearer"
    return f'Bearer resource_metadata="{url}"'


def protected_resource_document(resource: str, issuer: str) -> dict[str, Any]:
    return {
        "resource": resource.rstrip("/"),
        "authorization_servers": [issuer.rstrip("/")] if issuer else [],
        "scopes_supported": oauth_scopes(),
        "bearer_methods_supported": ["header"],
    }


def authorization_server_document(issuer: str) -> dict[str, Any]:
    base = issuer.rstrip("/")
    return {
        "issuer": base,
        "authorization_endpoint": base + "/oauth/authorize",
        "token_endpoint": base + "/oauth/token",
        "registration_endpoint": base + "/oauth/register",
        "revocation_endpoint": base + "/oauth/revoke",
        "response_types_supported": ["code"],
        "grant_types_supported": ["authorization_code", "refresh_token"],
        "code_challenge_methods_supported": ["S256"],
        "token_endpoint_auth_methods_supported": ["none"],
        "scopes_supported": oauth_scopes(),
    }


def listen_host(requested: str | None, bind: str | None = None) -> str:
    """All IPv4 interfaces unless MCP_BIND=loopback.

    MCP_HOST is ignored so a leftover 127.0.0.1 cannot change the bind.
    Loopback is opt-in after existing clients have moved to the tunnel.
    """
    mode = (bind or "").strip().lower()
    if mode in ("loopback", "localhost"):
        return "127.0.0.1"
    if mode not in ("", "all", "0.0.0.0", "*"):
        LOG.warning("MCP_BIND=%s is not all or loopback; listening on 0.0.0.0", mode)
    host = (requested or "").strip()
    if host not in ("", "0.0.0.0", "*"):
        LOG.warning("MCP_HOST=%s is ignored; set MCP_BIND=loopback to listen on 127.0.0.1", host)
    return "0.0.0.0"


def negotiate_protocol(params: Any) -> str:
    requested = ""
    if isinstance(params, dict):
        requested = str(params.get("protocolVersion") or "")
    if requested in SUPPORTED_PROTOCOLS:
        return requested
    return PROTOCOL


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

    def reply(result: Any = None, error: dict[str, Any] | None = None, http_status: int = 200) -> RpcReply:
        payload = RpcReply(jsonrpc="2.0", id=msg_id)
        if error is not None:
            payload["error"] = error
        else:
            payload["result"] = result
        payload.http_status = http_status
        return payload

    if method == "initialize":
        return reply({
            "protocolVersion": negotiate_protocol(message.get("params")),
            "capabilities": {"tools": {"listChanged": False}},
            "serverInfo": {"name": "lifedash", "version": __version__},
        })
    if method == "ping":
        return reply({})
    if method == "tools/list":
        if not authorization:
            return reply(error={"code": -32001, "message": "Authorization: Bearer is required."}, http_status=401)
        return reply({"tools": TOOLS})
    if method == "tools/call":
        if not authorization:
            return reply(error={"code": -32001, "message": "Authorization: Bearer is required."}, http_status=401)
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
        # PHP 401 is an auth failure. Claude's connector looks for HTTP 401,
        # not a JSON-RPC error inside HTTP 200.
        return reply(_result(text, is_error=status >= 400), http_status=401 if status == 401 else 200)
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


def health_document(listen: str = "0.0.0.0", port: int = 8765) -> dict[str, Any]:
    """GET /healthz. Tool count is the in-process registry, not a cached session."""
    from .healthcheck import git_revision

    return {
        "ok": True,
        "service": "lifedash-mcp",
        "listen": f"{listen}:{port}",
        "tool_count": len(TOOLS),
        "version": __version__,
        "revision": git_revision(),
    }


def make_handler(
    fetch: Fetch,
    limiter: RequestLimiter | None = None,
    *,
    listen: str = "0.0.0.0",
    port: int = 8765,
    mcp_public_url: str = "",
    issuer: str = "",
) -> type[BaseHTTPRequestHandler]:
    gate = limiter or RequestLimiter()
    resource = (mcp_public_url or "").strip()
    auth_headers = {
        "WWW-Authenticate": www_authenticate(resource),
        "Cache-Control": "no-store",
    }

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
            # Stateless. A leftover Mcp-Session-Id is ignored; it does not select a tool list.
            auth = self.headers.get("Authorization")
            if isinstance(message, list):
                replies = [r for item in message if isinstance(item, dict) and (r := handle_message(item, auth, fetch, gate)) is not None]
                status = 401 if any(getattr(item, "http_status", 200) == 401 for item in replies) else 200
                self._send(status, replies, auth_headers if status == 401 else None)
                return
            if not isinstance(message, dict):
                self._send(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": "Invalid request"}})
                return
            reply = handle_message(message, auth, fetch, gate)
            if reply is None:
                self._send(202, None)
                return
            status = getattr(reply, "http_status", 200)
            self._send(status, reply, auth_headers if status == 401 else None)

        def do_GET(self) -> None:  # noqa: N802
            path = self.path.split("?", 1)[0]
            if path == "/healthz":
                self._send(200, health_document(listen, port))
                return
            if path in ("/.well-known/oauth-protected-resource", "/.well-known/oauth-protected-resource/mcp"):
                if not resource or not issuer:
                    self._send(503, {"error": "MCP_PUBLIC_URL and PUBLIC_BASE_URL (or APP_URL) must be set."})
                    return
                self._send(200, protected_resource_document(resource, issuer), {"Cache-Control": "no-store"})
                return
            if path == "/.well-known/oauth-authorization-server":
                if not issuer:
                    self._send(503, {"error": "PUBLIC_BASE_URL or APP_URL must be set."})
                    return
                self._send(200, authorization_server_document(issuer), {"Cache-Control": "no-store"})
                return
            # Streamable HTTP clients GET the MCP path to open an SSE stream.
            # This server has no stream. 200 JSON here makes that probe look
            # successful and the client never finishes tools/list.
            if path in ("/mcp", "/mcp/"):
                self._send(
                    405,
                    {"error": "POST JSON-RPC to this path. No SSE stream."},
                    {"Allow": "POST"},
                )
                return
            self._send(404, {"error": "not found"})

        def log_message(self, fmt: str, *args: Any) -> None:
            LOG.info("%s - " + fmt, self.address_string(), *args)

        def _send(self, status: int, payload: Any, headers: dict[str, str] | None = None) -> None:
            body = b"" if payload is None else json.dumps(payload).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            for key, value in (headers or {}).items():
                self.send_header(key, value)
            self.end_headers()
            if body:
                self.wfile.write(body)

    return Handler


def main() -> None:
    config = Config.load()
    setup_logging(config.get("LOG_LEVEL", "INFO"))
    host = listen_host(config.maybe("MCP_HOST"), config.maybe("MCP_BIND"))
    port = int(config.get("MCP_PORT", "8765"))
    api_base = config.get("MCP_API_BASE", "http://127.0.0.1")
    mcp_public = config.maybe("MCP_PUBLIC_URL") or ""
    issuer = config.maybe("PUBLIC_BASE_URL") or config.maybe("APP_URL") or ""
    server = ThreadingHTTPServer(
        (host, port),
        make_handler(urllib_fetch(api_base), listen=host, port=port, mcp_public_url=mcp_public, issuer=issuer),
    )
    LOG.info("lifedash-mcp listening on http://%s:%s/mcp → %s (%s tools)", host, port, api_base, len(TOOLS))
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.shutdown()


if __name__ == "__main__":
    main()
