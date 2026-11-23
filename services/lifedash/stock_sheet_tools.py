"""MCP tools for the Stocks Google Sheet.

The sheet is read only. These tools do not write back to it and do not
compute a portfolio from it. The service account key stays in Settings.
"""

from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlencode

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}
_SYMBOL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9.\-]{0,31}$")
_RANGES = ("day", "week", "month", "year")

STOCK_SHEET_TOOLS: list[dict[str, Any]] = [
    {
        "name": "get_stock_watchlist",
        "description": (
            "Watchlist rows from the connected Stocks sheet: role, symbol, price, change, thesis, and the rest of the stored columns. "
            "Blank fundamentals are null. Requires stocks:read. This is not a portfolio."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "get_commodities",
        "description": (
            "Commodity rows from the connected Stocks sheet. Each row is an ETF proxy, with proxy_for naming the spot it stands in for. "
            "Requires stocks:read."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "get_play_account",
        "description": (
            "Play Account buys from the connected Stocks sheet: date, ticker, amount invested, shares, and price at the buy. "
            "Empty until those rows exist. Requires stocks:read. This is not a profit figure."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "get_sheet_sync_status",
        "description": (
            "Whether the Stocks sheet is configured, when it last synced, and whether that pull is stale or failed. "
            "Does not return the service account key. Requires stocks:read."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "get_stock_history",
        "description": (
            "Stored price history for one symbol from the Stocks sheet. "
            "Day is the hourly price taken when the sheet sync runs. Week, month, and year are daily closes. "
            "The sheet is read only, and days that age off its rolling year stay stored. "
            "A blank or #N/A cell does not erase a stored price. Requires stocks:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "symbol": {"type": "string", "description": "Ticker, such as VOO."},
                "range": {
                    "type": "string",
                    "enum": list(_RANGES),
                    "description": "day, week, month, or year. Omit it to return all four.",
                },
            },
            "required": ["symbol"],
            "additionalProperties": False,
        },
    },
    {
        "name": "refresh_sheet_sync",
        "description": (
            "Pull the Stocks sheet now. Read only: nothing is written back to the spreadsheet. "
            "When no service account key is saved, configured is false and the stored rows stay as they are. Requires stocks:write."
        ),
        "inputSchema": _EMPTY,
    },
]


def resolve_stock_sheet(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "get_stock_watchlist":
        _empty(name, args)
        return "GET", "/api/v1/stocks/sheet/watchlist", None
    if name == "get_commodities":
        _empty(name, args)
        return "GET", "/api/v1/stocks/sheet/commodities", None
    if name == "get_play_account":
        _empty(name, args)
        return "GET", "/api/v1/stocks/sheet/play", None
    if name == "get_sheet_sync_status":
        _empty(name, args)
        return "GET", "/api/v1/stocks/sheet/status", None
    if name == "get_stock_history":
        unknown = set(args) - {"symbol", "range"}
        if unknown:
            raise ValueError("get_stock_history accepts only symbol and range.")
        symbol = args.get("symbol")
        if not isinstance(symbol, str) or _SYMBOL.fullmatch(symbol.strip()) is None:
            raise ValueError("symbol must be a ticker.")
        query: dict[str, str] = {"symbol": symbol.strip().upper()}
        if "range" in args:
            rng = args["range"]
            if not isinstance(rng, str) or rng not in _RANGES:
                raise ValueError("range must be day, week, month, or year.")
            query["range"] = rng
        return "GET", "/api/v1/stocks/sheet/history?" + urlencode(query), None
    if name == "refresh_sheet_sync":
        _empty(name, args)
        return "POST", "/api/v1/stocks/sheet/refresh", {}
    return None


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")
