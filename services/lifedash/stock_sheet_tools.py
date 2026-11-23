"""MCP tools for the Stocks Google Sheet.

The sheet is read only. These tools do not write back to it and do not
compute a portfolio from it. The service account key stays in Settings.
"""

from __future__ import annotations

from typing import Any

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}

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
    if name == "refresh_sheet_sync":
        _empty(name, args)
        return "POST", "/api/v1/stocks/sheet/refresh", {}
    return None


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")
