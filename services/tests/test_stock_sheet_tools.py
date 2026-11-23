"""Stock sheet tools stay on the one public tool list and the stocks API."""

import pytest

from lifedash.mcp_server import TOOLS, oauth_scopes, resolve


def test_stock_sheet_tools_are_registered():
    names = [tool["name"] for tool in TOOLS]
    expected = [
        "get_stock_watchlist",
        "get_commodities",
        "get_play_account",
        "get_sheet_sync_status",
        "refresh_sheet_sync",
    ]
    for name in expected:
        assert names.count(name) == 1
    described = {tool["name"]: tool["description"] for tool in TOOLS if tool["name"] in expected}
    assert "stocks:read" in described["get_stock_watchlist"]
    assert "stocks:read" in described["get_commodities"]
    assert "stocks:read" in described["get_play_account"]
    assert "stocks:read" in described["get_sheet_sync_status"]
    assert "service account" in described["get_sheet_sync_status"]
    assert "stocks:write" in described["refresh_sheet_sync"]
    assert "read only" in described["refresh_sheet_sync"].lower() or "Read only" in described["refresh_sheet_sync"]
    assert "stocks:read" in oauth_scopes()
    assert "stocks:write" in oauth_scopes()
    brief = next(tool for tool in TOOLS if tool["name"] == "brief_me")
    assert "portfolio" in brief["description"].lower()
    assert "open handoffs" in brief["description"]


def test_stock_sheet_tools_resolve_onto_the_stocks_api():
    assert resolve("get_stock_watchlist", {}) == ("GET", "/api/v1/stocks/sheet/watchlist", None)
    assert resolve("get_commodities", {}) == ("GET", "/api/v1/stocks/sheet/commodities", None)
    assert resolve("get_play_account", {}) == ("GET", "/api/v1/stocks/sheet/play", None)
    assert resolve("get_sheet_sync_status", {}) == ("GET", "/api/v1/stocks/sheet/status", None)
    assert resolve("refresh_sheet_sync", {}) == ("POST", "/api/v1/stocks/sheet/refresh", {})


def test_stock_sheet_tools_reject_extra_fields():
    with pytest.raises(ValueError):
        resolve("refresh_sheet_sync", {"write": True})
    with pytest.raises(ValueError):
        resolve("get_stock_watchlist", {"symbol": "SPY"})
    with pytest.raises(ValueError):
        resolve("get_play_account", {"pnl": 10})
