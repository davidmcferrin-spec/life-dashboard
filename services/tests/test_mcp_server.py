import json

from lifedash.mcp_server import TOOLS, RequestLimiter, handle_message, resolve


def test_tools_cover_the_platform_and_never_a_trade():
    names = [tool["name"] for tool in TOOLS]
    assert "invest_scorecard" in names
    assert "life_ingest" in names
    assert "write_page" in names
    assert "search" in names
    assert "add_transaction" in names
    assert "save_account" in names
    assert "save_widget" in names
    assert "dashboard" in names
    assert not any("order" in name or "trade" in name for name in names)


def test_resolve_record_and_page_update():
    method, path, body = resolve("record_datapoint", {"metric_id": 4, "value": 3, "dedupe_key": "day:2026-10-06"})
    assert (method, path) == ("POST", "/api/v1/metrics/4/datapoints")
    assert body["dedupe_key"] == "day:2026-10-06"
    method, path, body = resolve("write_page", {"page_id": 9, "title": "Notes", "body": "[[Home]]", "folder": "Travel/2026"})
    assert (method, path, body["title"]) == ("POST", "/api/v1/pages/9/update", "Notes")
    assert body["folder"] == "Travel/2026"
    method, path, body = resolve("search_pages", {"q": "pack", "folder_id": 3})
    assert method == "GET"
    assert "q=pack" in path
    assert "folder_id=3" in path
    method, path, body = resolve("list_folders", {})
    assert (method, path, body) == ("GET", "/api/v1/pages/folders", None)
    method, path, body = resolve("create_folder", {"name": "Travel", "parent_id": None})
    assert (method, path, body) == ("POST", "/api/v1/pages/folders", {"name": "Travel", "parent_id": None})
    method, path, body = resolve("rename_folder", {"folder_id": 2, "name": "Trips"})
    assert (method, path, body) == ("POST", "/api/v1/pages/folders/2", {"name": "Trips"})
    method, path, body = resolve("move_folder", {"folder_id": 2, "parent_id": None})
    assert (method, path, body) == ("POST", "/api/v1/pages/folders/2/move", {"parent_id": None})
    method, path, body = resolve("delete_folder", {"folder_id": 2, "move_contents": True})
    assert (method, path, body) == ("POST", "/api/v1/pages/folders/2/delete", {"move_contents": True})
    method, path, body = resolve("move_page", {"page_id": 9, "folder_id": 2})
    assert (method, path, body) == ("POST", "/api/v1/pages/9/move", {"folder_id": 2})


def test_money_and_widget_writes():
    method, path, body = resolve("add_transaction", {"account_id": 2, "amount": -12.5, "payee": "Cafe", "dedupe_key": "cafe-1"})
    assert (method, path) == ("POST", "/api/v1/money/transactions")
    assert body["amount"] == -12.5
    method, path, body = resolve("save_account", {"account_id": 2, "name": "Checking", "balance": 40})
    assert (method, path, body["name"]) == ("POST", "/api/v1/money/accounts/2/update", "Checking")
    method, path, body = resolve("save_widget", {"widget_id": 7, "config": {"sources": "news\nprogramming"}})
    assert method == "POST"
    assert path == "/api/v1/widgets/7/update"
    assert body["config"]["sources"] == "news\nprogramming"


def test_dashboard_reaches_the_api_and_blocks_tokens_and_trades():
    method, path, body = resolve("dashboard", {"method": "post", "path": "/invest/polymarket/refresh", "body": {}})
    assert (method, path, body) == ("POST", "/api/v1/invest/polymarket/refresh", {})
    method, path, body = resolve("dashboard", {"method": "GET", "path": "/api/v1/money/accounts"})
    assert (method, path, body) == ("GET", "/api/v1/money/accounts", None)
    method, path, body = resolve("dashboard", {"method": "POST", "path": "/bookmarks", "body": {"title": "Notes", "url": "https://example.com"}})
    assert (method, path) == ("POST", "/api/bookmarks")
    assert body["title"] == "Notes"
    method, path, body = resolve("dashboard", {"method": "GET", "path": "/clipboards/4"})
    assert (method, path, body) == ("GET", "/api/clipboards/4", None)
    for blocked in ("/tokens", "/invest/orders", "../etc/passwd", "/files", "/cloudpad", "/bookmarks/2/favicon", "/clipboards/1/shares/link"):
        try:
            resolve("dashboard", {"method": "POST", "path": blocked, "body": {}})
        except ValueError:
            continue
        raise AssertionError(blocked)


def test_reddit_tools_stay_on_the_reddit_api():
    method, path, body = resolve("list_tracked_subreddits", {})
    assert (method, path, body) == ("GET", "/api/v1/reddit/subs", None)
    method, path, body = resolve("add_tracked_subreddit", {"name": "r/programming", "sort": "new", "include": "php"})
    assert (method, path) == ("POST", "/api/v1/reddit/subs")
    assert body["name"] == "r/programming"
    assert body["sort"] == "new"
    method, path, body = resolve("remove_tracked_subreddit", {"name": "programming"})
    assert (method, path, body) == ("POST", "/api/v1/reddit/subs/remove", {"name": "programming"})
    method, path, body = resolve("get_reddit_posts", {"subreddit": "programming", "since": "2026-10-01", "min_score": 10, "keyword": "php", "limit": 5})
    assert method == "GET"
    assert body is None
    assert path.startswith("/api/v1/reddit/posts?")
    assert "subreddit=programming" in path
    assert "min_score=10" in path
    assert "keyword=php" in path
    method, path, body = resolve("get_reddit_post_comments", {"post_id": "abc123", "limit": 5})
    assert (method, path, body) == ("GET", "/api/v1/reddit/posts/abc123/comments?limit=5", None)


def test_search_forwards_include_and_exclude():
    method, path, body = resolve("search", {"q": "Apple", "include": ["pages", "feeds"], "exclude": ["money"]})
    assert method == "GET"
    assert body is None
    assert "q=Apple" in path
    assert "include=pages%2Cfeeds" in path
    assert "exclude=money" in path


def test_money_tools_resolve_onto_the_money_api():
    method, path, body = resolve("add_transactions", {"transactions": [{"amount": -1}]})
    assert (method, path, body) == ("POST", "/api/v1/money/transactions/bulk", {"transactions": [{"amount": -1}]})
    method, path, body = resolve("list_transactions", {"from": "2026-10-01", "category": "Food", "limit": 5})
    assert method == "GET"
    assert body is None
    assert path.startswith("/api/v1/money/transactions?")
    assert "from=2026-10-01" in path
    assert "category=Food" in path
    assert "limit=5" in path
    method, path, body = resolve("get_budget", {"year": 2026, "month": 10})
    assert (method, path, body) == ("GET", "/api/v1/money/budget?year=2026&month=10", None)
    method, path, body = resolve("set_budget_line", {"year": 2026, "month": 10, "category": "Food", "amount": 400, "due_day": 15})
    assert (method, path) == ("POST", "/api/v1/money/budget")
    assert body["category"] == "Food"
    method, path, body = resolve("get_debts", {})
    assert (method, path, body) == ("GET", "/api/v1/money/debts", None)
    method, path, body = resolve("update_debt", {"debt_id": 4, "balance": 10, "apr": 6.99, "minimum": 50})
    assert (method, path) == ("POST", "/api/v1/money/debts/4")
    assert "debt_id" not in body
    assert body["apr"] == 6.99
    method, path, body = resolve("get_sinking_funds", {})
    assert (method, path, body) == ("GET", "/api/v1/money/sinking-funds", None)
    method, path, body = resolve("update_sinking_fund", {"sinking_fund_id": 3, "target": 100, "balance": 40, "due_on": "2026-12-01"})
    assert (method, path) == ("POST", "/api/v1/money/sinking-funds/3")
    assert body["due_on"] == "2026-12-01"
    method, path, body = resolve("add_net_worth_snapshot", {"date": "2026-10-01", "net_worth": 1000})
    assert (method, path) == ("POST", "/api/v1/money/snapshots")
    method, path, body = resolve("add_credit_score", {"date": "2026-10-01", "score": 740, "utilization": 22})
    assert (method, path) == ("POST", "/api/v1/money/credit-scores")
    method, path, body = resolve("get_dashboard_summary", {})
    assert (method, path, body) == ("GET", "/api/v1/money/dashboard", None)
    names = [tool["name"] for tool in TOOLS]
    for name in (
        "add_transactions", "list_transactions", "get_budget", "set_budget_line", "get_debts",
        "update_debt", "get_sinking_funds", "update_sinking_fund", "add_net_worth_snapshot",
        "add_credit_score", "get_dashboard_summary", "money_summary",
    ):
        assert name in names


def test_rate_limit_stops_the_next_call_without_fetching():
    calls = {"n": 0}

    def fetch(method, path, body, authorization, tool):
        calls["n"] += 1
        return 200, {"ok": True}

    limiter = RequestLimiter(limit=2, window=60)
    auth = "Bearer secret-token"

    def call(msg_id, token):
        return handle_message(
            {"jsonrpc": "2.0", "id": msg_id, "method": "tools/call", "params": {"name": "list_metrics", "arguments": {}}},
            token,
            fetch,
            limiter,
        )

    assert call(1, auth)["result"]["isError"] is False
    assert call(2, auth)["result"]["isError"] is False
    blocked = call(3, auth)
    assert blocked["error"]["code"] == -32029
    assert calls["n"] == 2
    assert call(4, "Bearer other-token")["result"]["isError"] is False
    assert calls["n"] == 3
    for i in range(5):
        listed = handle_message(
            {"jsonrpc": "2.0", "id": 10 + i, "method": "tools/list"},
            auth,
            fetch,
            limiter,
        )
        assert "tools" in listed["result"]
    assert calls["n"] == 3


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
