import json

from lifedash.mcp_server import TOOLS, handle_message, resolve


def test_tools_cover_the_platform_and_never_a_trade():
    names = [tool["name"] for tool in TOOLS]
    assert "invest_scorecard" in names
    assert "life_ingest" in names
    assert "write_page" in names
    assert "search" in names
    assert not any("order" in name or "trade" in name for name in names)


def test_resolve_record_and_page_update():
    method, path, body = resolve("record_datapoint", {"metric_id": 4, "value": 3, "dedupe_key": "day:2026-10-06"})
    assert (method, path) == ("POST", "/api/v1/metrics/4/datapoints")
    assert body["dedupe_key"] == "day:2026-10-06"
    method, path, body = resolve("write_page", {"page_id": 9, "title": "Notes", "body": "[[Home]]"})
    assert (method, path, body["title"]) == ("POST", "/api/v1/pages/9/update", "Notes")


def test_search_forwards_include_and_exclude():
    method, path, body = resolve("search", {"q": "Apple", "include": ["pages", "feeds"], "exclude": ["money"]})
    assert method == "GET"
    assert body is None
    assert "q=Apple" in path
    assert "include=pages%2Cfeeds" in path
    assert "exclude=money" in path


def test_initialize_and_tool_call_forward_the_bearer_token():
    seen = {}

    def fetch(method, path, body, authorization, tool):
        seen.update(method=method, path=path, authorization=authorization, tool=tool)
        return 200, {"metrics": []}

    init = handle_message({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}, None, fetch)
    assert init["result"]["serverInfo"]["name"] == "lifedash"
    assert handle_message({"jsonrpc": "2.0", "method": "notifications/initialized"}, None, fetch) is None

    denied = handle_message({"jsonrpc": "2.0", "id": 2, "method": "tools/call", "params": {"name": "list_metrics"}}, None, fetch)
    assert denied["error"]["code"] == -32001

    ok = handle_message(
        {"jsonrpc": "2.0", "id": 3, "method": "tools/call", "params": {"name": "list_metrics", "arguments": {}}},
        "Bearer ld_test",
        fetch,
    )
    assert seen == {"method": "GET", "path": "/api/v1/metrics", "authorization": "Bearer ld_test", "tool": "list_metrics"}
    text = json.loads(ok["result"]["content"][0]["text"])
    assert text["metrics"] == []
    assert ok["result"]["isError"] is False
