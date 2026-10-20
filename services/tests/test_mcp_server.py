import json
import threading
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer

from lifedash.mcp_server import (
    TOOLS,
    RequestLimiter,
    handle_message,
    listen_host,
    make_handler,
    negotiate_protocol,
    resolve,
)


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
    assert "create_habit" in names
    assert "reorder_habits" in names
    # import_order records a grocery receipt. It does not place a trade.
    assert not any("trade" in name or (name.endswith("_order") and name != "import_order") for name in names)


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


def test_habit_tools_resolve_onto_the_habits_api():
    method, path, body = resolve("create_habit", {
        "name": "Water", "key": "water", "icon": "💧", "frequency": "daily", "sort_order": 0,
    })
    assert (method, path) == ("POST", "/api/v1/habits")
    assert body == {"name": "Water", "key": "water", "icon": "💧", "frequency": "daily", "sort_order": 0}
    method, path, body = resolve("update_habit", {"metric_id": 4, "name": "Lunch", "target": 5})
    assert (method, path, body) == ("POST", "/api/v1/habits/4/update", {"name": "Lunch", "target": 5})
    method, path, body = resolve("update_habit", {"metric_id": 13, "goal_metric_id": 17, "goal_threshold": 3000})
    assert (method, path, body) == (
        "POST",
        "/api/v1/habits/13/update",
        {"goal_metric_id": 17, "goal_threshold": 3000},
    )
    method, path, body = resolve("create_habit", {"name": "Water", "goal_metric_id": 17, "goal_threshold": 3000})
    assert body["goal_metric_id"] == 17
    assert body["goal_threshold"] == 3000
    method, path, body = resolve("update_habit", {"metric_id": 13, "goal_metric_id": 0})
    assert body == {"goal_metric_id": 0}
    schema = {tool["name"]: tool for tool in TOOLS}
    assert "goal_metric_id" in schema["create_habit"]["inputSchema"]["properties"]
    assert "goal_threshold" in schema["update_habit"]["inputSchema"]["properties"]
    method, path, body = resolve("archive_habit", {"metric_id": 4, "archived": False})
    assert (method, path, body) == ("POST", "/api/v1/habits/4/archive", {"archived": False})
    method, path, body = resolve("reorder_habits", {"ids": [3, 1, 2]})
    assert (method, path, body) == ("POST", "/api/v1/habits/reorder", {"ids": [3, 1, 2]})


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
    schema = {tool["name"]: tool for tool in TOOLS}
    assert "metric_ids" in schema["widget_data"]["description"]
    assert "config.logs" in schema["save_widget"]["description"]
    assert "movement:YYYY-MM-DD" in schema["widget_data"]["description"]


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
    for blocked in ("/tokens", "/invest/orders", "../etc/passwd", "/files", "/cloudpad", "/bookmarks/2/favicon", "/clipboards/1/shares/link", "/system/deploy", "/api/v1/system/deploy"):
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
    method, path, body = resolve("create_debt", {"name": "Visa", "last4": "4242", "balance": 10, "notes": "card"})
    assert (method, path) == ("POST", "/api/v1/money/debts")
    assert body["last4"] == "4242"
    method, path, body = resolve("archive_debt", {"debt_id": 4})
    assert (method, path, body) == ("POST", "/api/v1/money/debts/4/archive", {})
    method, path, body = resolve("delete_debt", {"debt_id": 4})
    assert (method, path, body) == ("POST", "/api/v1/money/debts/4/delete", {})
    method, path, body = resolve("set_category_cap", {"category": "Food", "weekly_cap": 275, "effective_from": "2026-10-19"})
    assert (method, path) == ("POST", "/api/v1/money/category-caps")
    assert body["weekly_cap"] == 275
    method, path, body = resolve("create_sinking_fund", {"name": "Tires", "target_amount": 400, "notes": "winter"})
    assert (method, path) == ("POST", "/api/v1/money/sinking-funds")
    method, path, body = resolve("archive_sinking_fund", {"sinking_fund_id": 3})
    assert (method, path, body) == ("POST", "/api/v1/money/sinking-funds/3/archive", {})
    method, path, body = resolve("weekly_review", {})
    assert (method, path, body) == ("POST", "/api/v1/money/weekly-review", {"write_page": False})
    method, path, body = resolve("weekly_review", {"write_page": True})
    assert body["write_page"] is True
    method, path, body = resolve("get_audit_log", {"since": "2026-10-01", "source": "ai", "entity": "debt", "entity_id": 4, "limit": 10})
    assert method == "GET"
    assert body is None
    assert path.startswith("/api/v1/audit/log?")
    assert "actor=ai" in path
    assert "entity=debt" in path
    assert "entity_id=4" in path
    method, path, body = resolve("list_todos", {"category": "Work", "status": "open", "priority": 1, "due_before": "2026-10-20"})
    assert method == "GET"
    assert "category=Work" in path
    assert "status=open" in path
    method, path, body = resolve("create_todo", {"title": "Call", "category": "Work", "priority": 2})
    assert (method, path) == ("POST", "/api/v1/todos/items")
    assert body["title"] == "Call"
    method, path, body = resolve("update_todo", {"todo_id": 8, "notes": "later"})
    assert (method, path) == ("POST", "/api/v1/todos/items/8")
    assert "todo_id" not in body
    method, path, body = resolve("complete_todo", {"todo_id": 8, "reopen": True})
    assert (method, path, body) == ("POST", "/api/v1/todos/items/8/complete", {"reopen": True})
    method, path, body = resolve("list_todo_categories", {})
    assert (method, path, body) == ("GET", "/api/v1/todos/categories", None)
    method, path, body = resolve("create_todo_category", {"name": "Travel"})
    assert (method, path, body) == ("POST", "/api/v1/todos/categories", {"name": "Travel"})
    method, path, body = resolve("add_shopping_items", {"list_id": 3, "items": ["2 lb chicken", {"name": "milk"}]})
    assert (method, path) == ("POST", "/api/v1/shopping/items")
    assert body["list_id"] == 3
    assert body["items"][0] == "2 lb chicken"
    method, path, body = resolve("check_shopping_item", {"item_id": 9, "bought": False})
    assert (method, path, body) == ("POST", "/api/v1/shopping/items/9/check", {"bought": False})
    method, path, body = resolve("clear_bought", {"list_id": 3})
    assert (method, path, body) == ("POST", "/api/v1/shopping/lists/3/clear-bought", {})
    method, path, body = resolve("list_shopping_items", {"list_id": 3, "clear_hours": 12})
    assert method == "GET"
    assert "list_id=3" in path and "clear_hours=12" in path
    method, path, body = resolve("log_call", {
        "direction": "inbound",
        "source_call_id": "bland-1",
        "return_needed": False,
        "debt": {"reference_last4": "4391", "verdict": "suspicious"},
    })
    assert (method, path) == ("POST", "/api/v1/calls")
    assert body["direction"] == "inbound"
    assert body["return_needed"] is False
    assert body["debt"]["verdict"] == "suspicious"
    method, path, body = resolve("create_call", {"direction": "outbound", "phone": "2565550199"})
    assert (method, path) == ("POST", "/api/v1/calls")
    method, path, body = resolve("update_call", {"call_id": 4, "notes": "left voicemail", "debt": None})
    assert (method, path) == ("POST", "/api/v1/calls/4")
    assert body["debt"] is None
    assert "call_id" not in body
    method, path, body = resolve("set_call_status", {"call_id": 4, "status": "returned"})
    assert (method, path, body) == ("POST", "/api/v1/calls/4/status", {"status": "returned"})
    method, path, body = resolve("link_call_todo", {"call_id": 4, "todo_id": 8})
    assert (method, path, body) == ("POST", "/api/v1/calls/4/todo", {"todo_id": 8})
    method, path, body = resolve("list_calls", {"status": "needs_return", "return_needed": True})
    assert method == "GET"
    assert "status=needs_return" in path and "return_needed=True" in path
    method, path, body = resolve("search_calls", {"q": "Northwind"})
    assert (method, path, body) == ("GET", "/api/v1/calls/query?q=Northwind", None)
    method, path, body = resolve("lookup_caller", {"phone": "2565550199"})
    assert path.startswith("/api/v1/calls/lookup")
    method, path, body = resolve("call_stats", {})
    assert (method, path, body) == ("GET", "/api/v1/calls/stats", None)
    method, path, body = resolve("upsert_vetted_agency", {"name": "Northwind", "verdict": "suspicious"})
    assert (method, path) == ("POST", "/api/v1/calls/agencies")
    assert body["verdict"] == "suspicious"
    names = [tool["name"] for tool in TOOLS]
    for name in (
        "add_transactions", "list_transactions", "get_budget", "set_budget_line", "get_debts",
        "create_debt", "update_debt", "archive_debt", "delete_debt",
        "get_sinking_funds", "create_sinking_fund", "update_sinking_fund", "archive_sinking_fund",
        "set_category_cap", "add_net_worth_snapshot",
        "add_credit_score", "get_dashboard_summary", "money_summary", "weekly_review", "get_audit_log",
        "list_todos", "create_todo", "update_todo", "complete_todo", "list_todo_categories", "create_todo_category",
        "list_shopping_lists", "create_shopping_list", "list_shopping_items", "add_shopping_items",
        "update_shopping_item", "check_shopping_item", "remove_shopping_item", "clear_bought",
        "log_call", "create_call", "get_call", "update_call", "set_call_status", "link_call_todo",
        "list_calls", "search_calls", "call_stats", "lookup_caller", "list_contacts", "upsert_contact",
        "list_vetted_agencies", "upsert_vetted_agency",
        "deploy_status", "deploy_update", "deploy_log",
        "list_pantry", "get_product_by_upc", "upsert_product", "list_products",
        "add_pantry_items", "consume_pantry_item", "split_or_move_lot", "open_lot",
        "toss_lot", "adjust_lot", "expiring_items", "low_stock", "pantry_stats",
        "import_order", "list_recipes", "upsert_recipe", "what_can_i_make",
    ):
        assert name in names


def test_pantry_tools_map_onto_the_pantry_api():
    method, path, body = resolve("list_pantry", {"location": "fridge", "expiring_within_days": 3})
    assert method == "GET"
    assert path.startswith("/api/v1/pantry/lots?")
    assert "location=fridge" in path and "expiring_within_days=3" in path
    method, path, body = resolve("get_product_by_upc", {"upc": "012345678905", "external": True})
    assert (method, path, body) == ("GET", "/api/v1/pantry/products/upc/012345678905?external=1", None)
    method, path, body = resolve("get_product_by_upc", {"upc": "012345678905"})
    assert path.endswith("?external=0")
    method, path, body = resolve("low_stock", {})
    assert (method, path, body) == ("GET", "/api/v1/pantry/low-stock", None)
    method, path, body = resolve("low_stock", {"add_to_shopping_list": True, "list_id": 1})
    assert (method, path, body) == ("POST", "/api/v1/pantry/low-stock", {"list_id": 1})
    method, path, body = resolve("split_or_move_lot", {"lot_id": 4, "location": "freezer", "quantity": 2})
    assert (method, path) == ("POST", "/api/v1/pantry/lots/4/split")
    assert body["location"] == "freezer"
    method, path, body = resolve("toss_lot", {"lot_id": 4})
    assert (method, path, body) == ("POST", "/api/v1/pantry/lots/4/toss", {})
    method, path, body = resolve("toss_lot", {"lot_ids": [4, 5]})
    assert (method, path, body) == ("POST", "/api/v1/pantry/purge", {"lot_ids": [4, 5]})
    method, path, body = resolve("what_can_i_make", {})
    assert (method, path, body) == ("GET", "/api/v1/pantry/what-can-i-make", None)


def test_deploy_tools_send_only_the_confirm_sha():
    method, path, body = resolve("deploy_status", {})
    assert (method, path, body) == ("GET", "/api/v1/system/deploy/status", None)
    method, path, body = resolve("deploy_log", {})
    assert (method, path, body) == ("GET", "/api/v1/system/deploy/log", None)
    job = "ab" * 16
    method, path, body = resolve("deploy_log", {"job_id": job})
    assert (method, path, body) == ("GET", "/api/v1/system/deploy/log?job_id=" + job, None)
    sha = "cd" * 20
    method, path, body = resolve("deploy_update", {"confirm_sha": sha, "dry_run": True})
    assert (method, path, body) == ("POST", "/api/v1/system/deploy", {"confirm_sha": sha, "dry_run": True})
    for bad in ({"confirm_sha": "main"}, {"confirm_sha": sha.upper()}, {"confirm_sha": sha, "ref": "main"}):
        try:
            resolve("deploy_update", bad)
        except (ValueError, KeyError):
            continue
        raise AssertionError(bad)
    try:
        resolve("deploy_log", {"job_id": "../etc/passwd"})
    except ValueError:
        return
    raise AssertionError("job id")


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
    assert init["result"]["protocolVersion"] == "2025-03-26"
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


def test_tools_list_requires_a_bearer_and_ignores_session_and_protocol():
    assert listen_host(None) == "0.0.0.0"
    assert listen_host("") == "0.0.0.0"
    assert listen_host("0.0.0.0") == "0.0.0.0"
    assert listen_host("127.0.0.1") == "0.0.0.0"
    assert listen_host("10.1.1.5") == "0.0.0.0"
    assert negotiate_protocol({"protocolVersion": "2024-11-05"}) == "2024-11-05"
    assert negotiate_protocol({"protocolVersion": "1999-01-01"}) == "2025-03-26"

    def fetch(*_args):
        raise AssertionError("tools/list must not call the API")

    denied = handle_message({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, None, fetch)
    assert denied["error"]["code"] == -32001
    assert "result" not in denied

    def listed(token, protocol):
        if protocol:
            init = handle_message(
                {"jsonrpc": "2.0", "id": 2, "method": "initialize", "params": {"protocolVersion": protocol, "capabilities": {}}},
                token,
                fetch,
            )
            assert init["result"]["protocolVersion"] == protocol
        reply = handle_message({"jsonrpc": "2.0", "id": 3, "method": "tools/list"}, token, fetch)
        return [tool["name"] for tool in reply["result"]["tools"]]

    full = [tool["name"] for tool in TOOLS]
    assert listed("Bearer money-only", None) == full
    assert listed("Bearer full-token", "2025-06-18") == full
    assert "create_habit" in full
    assert "create_debt" in full
    assert "list_todos" in full

    handler = make_handler(fetch, RequestLimiter())
    server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        def post(payload, headers):
            req = urllib.request.Request(
                f"http://127.0.0.1:{port}/mcp",
                data=json.dumps(payload).encode(),
                headers={"Content-Type": "application/json", **headers},
                method="POST",
            )
            with urllib.request.urlopen(req) as res:
                return res.status, json.loads(res.read().decode())

        status, body = post({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, {})
        assert status == 200
        assert body["error"]["code"] == -32001

        status, body = post(
            {"jsonrpc": "2.0", "id": 2, "method": "initialize", "params": {"protocolVersion": "2025-11-25"}},
            {},
        )
        assert body["result"]["protocolVersion"] == "2025-11-25"

        status, body = post(
            {"jsonrpc": "2.0", "id": 3, "method": "tools/list"},
            {"Authorization": "Bearer anything", "Mcp-Session-Id": "stale"},
        )
        assert [tool["name"] for tool in body["result"]["tools"]] == full

        try:
            urllib.request.urlopen(urllib.request.Request(f"http://127.0.0.1:{port}/mcp", method="GET"))
            raise AssertionError("GET /mcp must not look like an SSE stream")
        except urllib.error.HTTPError as exc:
            assert exc.code == 405
            assert exc.headers.get("Allow") == "POST"

        with urllib.request.urlopen(f"http://127.0.0.1:{port}/healthz") as res:
            assert res.status == 200
            body = json.loads(res.read().decode())
            assert body["service"] == "lifedash-mcp"
            assert body["ok"] is True
            assert body["tool_count"] == len(TOOLS)
            assert body["listen"] == "0.0.0.0:8765"
            assert body["version"]
            assert "revision" in body
    finally:
        server.shutdown()
        server.server_close()
