import json
import socket
import threading
import time
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer
from unittest.mock import patch

from lifedash.mcp_server import (
    TOOLS,
    PlainHttpGate,
    PublicBind,
    RequestLimiter,
    _TRANSPORT,
    authorization_server_document,
    current_transport,
    effective_plain_http,
    handle_message,
    listen_host,
    make_handler,
    metadata_url,
    negotiate_protocol,
    plain_http_denied_message,
    presented_bearer,
    protected_resource_document,
    request_transport,
    resolve,
    urllib_fetch,
    watch_plain_http,
    www_authenticate,
)


def assert_no_tool_names(payload):
    blob = payload if isinstance(payload, str) else json.dumps(payload)
    for tool in TOOLS:
        assert tool["name"] not in blob


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
    method, path, body = resolve("page_version_diff", {"page_id": 9, "version": 2})
    assert (method, path, body) == ("GET", "/api/v1/pages/9/revisions/2", None)
    assert "page_version_diff" in [tool["name"] for tool in TOOLS]


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
        "list_pantry", "list_pantry_units", "get_product_by_upc", "upsert_product", "list_products",
        "add_pantry_items", "consume_pantry_item", "split_or_move_lot", "open_lot",
        "toss_lot", "adjust_lot", "expiring_items", "low_stock", "pantry_stats",
        "import_order", "list_recipes", "upsert_recipe", "update_recipe",
        "update_pantry_category", "update_pantry_product", "what_can_i_make",
        "list_meal_components", "add_meal_component", "update_meal_component", "remove_meal_component",
        "add_meal_pairing", "remove_meal_pairing", "list_meal_pairings", "suggest_meal",
        "plan_meal_prep", "log_meal_prep",
        "spotify_status", "list_spotify_playlists", "get_spotify_playlist", "search_spotify",
        "get_spotify_track", "list_play_history", "list_saved_tracks", "list_top_items",
        "get_now_playing", "list_spotify_devices", "list_devices", "list_playlist_changes", "get_taste_profile",
        "list_spotify_prompts", "get_spotify_prompt", "create_spotify_playlist", "update_spotify_playlist",
        "add_playlist_tracks", "remove_playlist_tracks", "reorder_playlist_tracks", "replace_playlist_tracks",
        "discard_playlist_changes", "push_playlist_changes", "sync_spotify", "add_spotify_prompt",
        "update_spotify_prompt", "remove_spotify_prompt", "mark_prompt_run", "update_taste_profile",
        "get_queue", "play", "resume", "pause", "next", "previous", "seek", "set_volume", "set_shuffle", "set_repeat",
        "add_to_queue", "transfer_playback", "save_tracks", "remove_saved_tracks",
        "delete_spotify_playlist", "set_player_enabled",
        "set_track_metadata", "get_track_metadata", "list_active_seasons", "plan_mixtape",
    ):
        assert name in names


def test_pantry_tools_map_onto_the_pantry_api():
    method, path, body = resolve("list_pantry_units", {})
    assert (method, path, body) == ("GET", "/api/v1/pantry/units", None)
    adjust = next(tool for tool in TOOLS if tool["name"] == "adjust_lot")
    assert "slice" in adjust["description"] and "serving" in adjust["description"]
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
    method, path, body = resolve("consume_pantry_item", {"lot_id": 4, "quantity": 0.5})
    assert (method, path, body) == ("POST", "/api/v1/pantry/consume", {"lot_id": 4, "quantity": 0.5})
    method, path, body = resolve("update_recipe", {"recipe_id": 3, "name": "Soup", "notes": None, "ingredients": []})
    assert (method, path, body) == ("POST", "/api/v1/pantry/recipes/3", {"name": "Soup", "notes": None, "ingredients": []})
    method, path, body = resolve("list_recipes", {"meal_prep": True})
    assert method == "GET" and body is None and "meal_prep=1" in path
    method, path, body = resolve("upsert_recipe", {
        "name": "Jar", "meal_prep": True, "keeps_days": 3, "protein_g": 32.5, "servings": 1,
    })
    assert body["meal_prep"] is True and body["keeps_days"] == 3 and body["protein_g"] == 32.5
    method, path, body = resolve("plan_meal_prep", {"servings": 3, "recipe_id": 4})
    assert method == "GET" and body is None and path.startswith("/api/v1/pantry/meals/prep?")
    assert "servings=3" in path and "recipe_id=4" in path
    method, path, body = resolve("log_meal_prep", {"servings": 3, "picks": [1, 2], "name": "Week jars"})
    assert (method, path) == ("POST", "/api/v1/pantry/meals/prep")
    assert body["servings"] == 3 and body["picks"] == [1, 2] and body["name"] == "Week jars"
    plan = next(tool for tool in TOOLS if tool["name"] == "plan_meal_prep")
    log = next(tool for tool in TOOLS if tool["name"] == "log_meal_prep")
    assert "preview" in plan["description"].lower()
    assert "Allow MCP updates" in log["description"]
    method, path, body = resolve("update_pantry_category", {"category": "Dairy", "fridge_days": 21, "opened_days": None})
    assert (method, path, body) == ("POST", "/api/v1/pantry/categories", {"category": "Dairy", "fridge_days": 21, "opened_days": None})
    method, path, body = resolve(
        "update_pantry_product",
        {"product_id": 8, "opened_life_days": 3, "shelf_life_fridge_days": None},
    )
    assert (method, path) == ("POST", "/api/v1/pantry/products/8")
    assert body == {"opened_life_days": 3, "shelf_life_fridge_days": None}


def test_meal_builder_tools_map_onto_the_pantry_api():
    method, path, body = resolve("list_meal_components", {"category": "Protein", "on_hand": True})
    assert method == "GET"
    assert path.startswith("/api/v1/pantry/meals/components?")
    assert "category=protein" in path and "on_hand=1" in path
    method, path, body = resolve("list_meal_components", {})
    assert (method, path, body) == ("GET", "/api/v1/pantry/meals/components", None)
    method, path, body = resolve(
        "add_meal_component",
        {"name": "Rice", "category": "base", "source": "David", "product_id": None, "notes": None},
    )
    assert (method, path) == ("POST", "/api/v1/pantry/meals/components")
    assert body["source"] == "david"
    assert body["product_id"] is None
    assert body["notes"] is None
    method, path, body = resolve("update_meal_component", {"component_id": 4, "name": "Brown rice", "product_id": None})
    assert (method, path) == ("POST", "/api/v1/pantry/meals/components/4")
    assert body == {"name": "Brown rice", "product_id": None}
    method, path, body = resolve("remove_meal_component", {"component_id": 4})
    assert (method, path, body) == ("POST", "/api/v1/pantry/meals/components/4/remove", {})
    method, path, body = resolve(
        "add_meal_pairing",
        {"component_a": "Rice", "component_b_id": 2, "score": None, "note": "weeknight", "source": "research"},
    )
    assert (method, path) == ("POST", "/api/v1/pantry/meals/pairings")
    assert body["score"] is None
    assert body["component_a"] == "Rice"
    assert body["source"] == "research"
    method, path, body = resolve("remove_meal_pairing", {"component_a_id": 2, "component_b": "Rice"})
    assert (method, path) == ("POST", "/api/v1/pantry/meals/pairings/remove")
    assert body["component_a_id"] == 2 and body["component_b"] == "Rice"
    method, path, body = resolve("list_meal_pairings", {"component_id": 4})
    assert (method, path, body) == ("GET", "/api/v1/pantry/meals/components/4/pairings", None)
    suggest = next(tool for tool in TOOLS if tool["name"] == "suggest_meal")
    assert "also_uses" in suggest["description"]
    assert "component_id" in suggest["description"]
    assert "matching_recipes" in suggest["description"]
    method, path, body = resolve("suggest_meal", {"picks": [2, 4], "on_hand_only": False})
    assert method == "GET" and body is None
    assert path.startswith("/api/v1/pantry/meals?")
    assert "picks=2%2C4" in path or "picks=2,4" in path
    assert "on_hand_only=0" in path
    for call, message in (
        (("add_meal_component", {"name": "X", "category": "grain", "source": "david"}), "Category must be"),
        (("add_meal_pairing", {"component_a_id": 1, "component_b_id": 2, "source": "web"}), "Source must be"),
        (("add_meal_pairing", {"component_a_id": 1, "component_b_id": 2, "score": 9, "source": "david"}), "Score must be"),
        (("suggest_meal", {"picks": ["rice"]}), "picks must be"),
    ):
        try:
            resolve(*call)
        except ValueError as exc:
            assert message in str(exc)
            continue
        raise AssertionError(message)


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
    assert_no_tool_names(blocked)
    # Each allowed call checks the token, then performs the tool. The blocked call does neither.
    assert calls["n"] == 4
    assert call(4, "Bearer other-token")["result"]["isError"] is False
    assert calls["n"] == 6
    for i in range(5):
        listed = handle_message(
            {"jsonrpc": "2.0", "id": 10 + i, "method": "tools/list"},
            auth,
            fetch,
            limiter,
        )
        assert "tools" in listed["result"]
    # Listing checks the token and is not part of the tool-call limit.
    assert calls["n"] == 11


def test_initialize_and_tool_call_forward_the_bearer_token():
    seen = {}

    def fetch(method, path, body, authorization, tool):
        seen.update(method=method, path=path, authorization=authorization, tool=tool)
        return 200, {"metrics": []}

    denied_init = handle_message({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}, None, fetch)
    assert denied_init["error"]["code"] == -32001
    assert denied_init.http_status == 401
    assert seen == {}

    init = handle_message({"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}, "Bearer ld_test", fetch)
    assert init["result"]["serverInfo"]["name"] == "lifedash"
    assert init["result"]["protocolVersion"] == "2025-03-26"
    assert handle_message({"jsonrpc": "2.0", "method": "notifications/initialized"}, None, fetch)["error"]["code"] == -32001
    assert handle_message({"jsonrpc": "2.0", "method": "notifications/initialized"}, "Bearer ld_test", fetch) is None

    denied = handle_message({"jsonrpc": "2.0", "id": 2, "method": "tools/call", "params": {"name": "list_metrics"}}, None, fetch)
    assert denied["error"]["code"] == -32001
    assert_no_tool_names(denied)

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
    assert listen_host("127.0.0.1", None) == "0.0.0.0"
    assert listen_host("127.0.0.1", "all") == "0.0.0.0"
    assert listen_host("10.1.1.5", "loopback") == "127.0.0.1"
    assert listen_host(None, "localhost") == "127.0.0.1"
    assert listen_host(None, None, False) == "127.0.0.1"
    assert listen_host("10.1.1.5", "all", False) == "127.0.0.1"
    assert listen_host(None, "loopback", True) == "127.0.0.1"
    assert listen_host(None, "loopback", False) == "127.0.0.1"
    assert listen_host("127.0.0.1", None, True) == "0.0.0.0"
    assert negotiate_protocol({"protocolVersion": "2024-11-05"}) == "2024-11-05"
    assert negotiate_protocol({"protocolVersion": "1999-01-01"}) == "2025-03-26"

    accepted = {"Bearer money-only", "Bearer full-token"}
    probes = {"n": 0}

    def fetch(method, path, body, authorization, tool):
        if authorization not in accepted:
            return 401, {"error": "Invalid or expired token."}
        if path == "/api/v1/agent-prompt":
            probes["n"] += 1
            assert tool == "auth"
            return 200, {"prompt": "ok"}
        raise AssertionError(path)

    denied = handle_message({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, None, fetch)
    assert denied["error"]["code"] == -32001
    assert denied.http_status == 401
    assert "result" not in denied
    assert_no_tool_names(denied)
    assert probes["n"] == 0

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
    # A narrow token still receives the whole registry. PHP enforces scope on the call.
    assert listed("Bearer money-only", None) == full
    assert listed("Bearer full-token", "2025-06-18") == full
    assert probes["n"] == 3
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
            try:
                with urllib.request.urlopen(req) as res:
                    return res.status, dict(res.headers), json.loads(res.read().decode())
            except urllib.error.HTTPError as exc:
                return exc.code, dict(exc.headers), json.loads(exc.read().decode())

        status, headers, body = post({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}, {})
        assert status == 401
        assert body["error"]["code"] == -32001
        assert headers["WWW-Authenticate"] == "Bearer"
        assert_no_tool_names(body)

        status, headers, body = post(
            {"jsonrpc": "2.0", "id": 2, "method": "initialize", "params": {"protocolVersion": "2025-11-25"}},
            {},
        )
        assert status == 401
        assert body["error"]["code"] == -32001
        assert_no_tool_names(body)

        status, headers, body = post(
            {"jsonrpc": "2.0", "id": 2, "method": "initialize", "params": {"protocolVersion": "2025-11-25"}},
            {"Authorization": "Bearer full-token"},
        )
        assert status == 200
        assert body["result"]["protocolVersion"] == "2025-11-25"

        status, headers, body = post(
            {"jsonrpc": "2.0", "id": 3, "method": "tools/list"},
            {"Authorization": "Bearer anything", "Mcp-Session-Id": "stale"},
        )
        assert status == 401
        assert body["error"]["message"] == "Invalid or expired token."
        assert_no_tool_names(body)

        status, headers, body = post(
            {"jsonrpc": "2.0", "id": 4, "method": "tools/list"},
            {"Authorization": "Bearer full-token", "Mcp-Session-Id": "stale"},
        )
        assert status == 200
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


def test_oauth_discovery_and_upstream_401():
    public = "https://mcp.example.com/mcp"
    issuer = "https://dash.example.com"
    assert metadata_url(public) == "https://mcp.example.com/.well-known/oauth-protected-resource/mcp"
    assert www_authenticate("") == "Bearer"
    assert 'resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"' in www_authenticate(public)
    doc = protected_resource_document(public, issuer)
    assert doc["resource"] == public
    assert doc["authorization_servers"] == [issuer]
    assert "pantry:read" in doc["scopes_supported"]
    assert "spotify:read" in doc["scopes_supported"]
    assert "spotify:write" in doc["scopes_supported"]
    assert "spotify:playback" in doc["scopes_supported"]
    server_doc = authorization_server_document(issuer)
    assert server_doc["token_endpoint"] == "https://dash.example.com/oauth/token"
    assert server_doc["code_challenge_methods_supported"] == ["S256"]

    def fetch(method, path, body, authorization, tool):
        assert authorization == "Bearer ldoa_expired"
        return 401, {"error": "Invalid or expired token."}

    handler = make_handler(
        fetch,
        RequestLimiter(),
        mcp_public_url=public,
        issuer=issuer,
    )
    server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        req = urllib.request.Request(
            f"http://127.0.0.1:{port}/mcp",
            data=json.dumps({"jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": {"name": "list_metrics", "arguments": {}}}).encode(),
            headers={"Content-Type": "application/json", "Authorization": "Bearer ldoa_expired"},
            method="POST",
        )
        try:
            urllib.request.urlopen(req)
            raise AssertionError("upstream 401 must stay HTTP 401")
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode()
            assert exc.code == 401
            assert exc.headers.get("WWW-Authenticate") == www_authenticate(public)
            body = json.loads(raw)
            assert body["error"]["message"] == "Invalid or expired token."
            assert_no_tool_names(raw)
        with urllib.request.urlopen(f"http://127.0.0.1:{port}/.well-known/oauth-protected-resource/mcp") as res:
            body = json.loads(res.read().decode())
            assert body["authorization_servers"] == [issuer]
        with urllib.request.urlopen(f"http://127.0.0.1:{port}/.well-known/oauth-authorization-server") as res:
            body = json.loads(res.read().decode())
            assert body["registration_endpoint"] == issuer + "/oauth/register"
    finally:
        server.shutdown()
        server.server_close()


def test_plain_http_stays_on_until_the_override_says_off():
    assert effective_plain_http(None, True) is True
    assert effective_plain_http(None, False) is False
    assert effective_plain_http(0, True) is False
    assert effective_plain_http(1, False) is True
    assert PlainHttpGate(True, lambda: None).allowed() is True
    assert PlainHttpGate(False, lambda: None).allowed() is False
    assert PlainHttpGate(True, lambda: 0).allowed() is False
    assert PlainHttpGate(False, lambda: 1).allowed() is True

    def down():
        raise RuntimeError("db down")

    assert PlainHttpGate(True, down).allowed() is True
    assert PlainHttpGate(False, down).allowed() is False

    assert request_transport("10.1.1.8", "https", '{"scheme":"https"}') == "http"
    assert request_transport("127.0.0.1", "https") == "https"
    assert request_transport("::1", "HTTPS") == "https"
    assert request_transport("127.0.0.1", "http, https") == "http"
    assert request_transport("127.0.0.1", None, '{"scheme":"https"}') == "https"
    assert request_transport("127.0.0.1", None) == "http"
    public = "https://mcp.example.com/mcp"
    assert public in plain_http_denied_message(public)
    assert "MCP_PUBLIC_URL is not set" in plain_http_denied_message("")

    seen = {}

    def fetch(method, path, body, authorization, tool):
        seen["transport"] = current_transport()
        seen["calls"] = seen.get("calls", 0) + 1
        return 200, {"ok": True}

    def serve(allow):
        handler = make_handler(
            fetch,
            RequestLimiter(),
            mcp_public_url=public,
            issuer="https://dash.example.com",
            allow_plain_http=allow,
        )
        server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
        port = server.server_address[1]
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        return server, port

    def post(port, payload, headers):
        req = urllib.request.Request(
            f"http://127.0.0.1:{port}/mcp",
            data=json.dumps(payload).encode(),
            headers={"Content-Type": "application/json", **headers},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req) as res:
                return res.status, json.loads(res.read().decode())
        except urllib.error.HTTPError as exc:
            return exc.code, json.loads(exc.read().decode())

    init = {"jsonrpc": "2.0", "id": 7, "method": "initialize", "params": {"protocolVersion": "2025-03-26"}}
    call = {"jsonrpc": "2.0", "id": 8, "method": "tools/call", "params": {"name": "list_metrics", "arguments": {}}}

    server, port = serve(lambda: True)
    try:
        status, body = post(port, init, {"Authorization": "Bearer ld_test"})
        assert status == 200
        assert body["result"]["protocolVersion"] == "2025-03-26"
        status, body = post(port, call, {"Authorization": "Bearer ld_test"})
        assert status == 200
        assert seen["transport"] == "http"
    finally:
        server.shutdown()
        server.server_close()

    seen.clear()
    server, port = serve(lambda: False)
    try:
        status, body = post(port, init, {})
        assert status == 403
        assert body["id"] == 7
        assert body["error"]["code"] == -32003
        assert public in body["error"]["message"]
        assert "calls" not in seen

        status, body = post(port, call, {"Authorization": "Bearer ld_test", "X-Forwarded-Proto": "https"})
        assert status == 200
        assert seen["transport"] == "https"

        with urllib.request.urlopen(f"http://127.0.0.1:{port}/healthz") as res:
            assert res.status == 200
        try:
            urllib.request.urlopen(f"http://127.0.0.1:{port}/.well-known/oauth-protected-resource/mcp")
            raise AssertionError("plain HTTP discovery must fail when the switch is off")
        except urllib.error.HTTPError as exc:
            assert exc.code == 403
            body = json.loads(exc.read().decode())
            assert public in body["error"]["message"]
        req = urllib.request.Request(
            f"http://127.0.0.1:{port}/.well-known/oauth-protected-resource/mcp",
            headers={"X-Forwarded-Proto": "https"},
        )
        with urllib.request.urlopen(req) as res:
            assert res.status == 200
    finally:
        server.shutdown()
        server.server_close()

    bare = make_handler(fetch, allow_plain_http=lambda: False)
    server = ThreadingHTTPServer(("127.0.0.1", 0), bare)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        status, body = post(port, init, {})
        assert status == 403
        assert "MCP_PUBLIC_URL is not set" in body["error"]["message"]
    finally:
        server.shutdown()
        server.server_close()


def _listen_v4(port: int) -> set[str]:
    found: set[str] = set()
    with open("/proc/net/tcp", encoding="ascii") as handle:
        next(handle)
        for line in handle:
            fields = line.split()
            local, state = fields[1], fields[3]
            if state != "0A":
                continue
            ip_hex, port_hex = local.split(":")
            if int(port_hex, 16) != port:
                continue
            found.add(socket.inet_ntoa(bytes.fromhex(ip_hex)[::-1]))
    return found


def _wait_host(bind: PublicBind, host: str) -> None:
    deadline = time.monotonic() + 2
    while time.monotonic() < deadline:
        if bind.host() == host:
            return
        time.sleep(0.05)
    assert bind.host() == host


def test_plain_http_off_drops_the_public_listener_and_keeps_loopback():
    public = "https://mcp.example.com/mcp"
    seen = {}

    def fetch(method, path, body, authorization, tool):
        seen["transport"] = current_transport()
        return 200, {"ok": True}

    running: dict[str, PublicBind] = {}
    handler = make_handler(
        fetch,
        RequestLimiter(),
        listen=lambda: running["bind"].host(),
        port=lambda: running["bind"].port,
        mcp_public_url=public,
        issuer="https://dash.example.com",
        allow_plain_http=lambda: running["bind"].host() == "0.0.0.0",
    )
    bind = PublicBind(0, handler, loopback_only=False, plain_http=True)
    running["bind"] = bind
    bind.start()
    try:
        port = bind.port
        assert bind.host() == "0.0.0.0"
        assert _listen_v4(port) == {"0.0.0.0"}
        with urllib.request.urlopen(f"http://127.0.0.1:{port}/healthz") as res:
            body = json.loads(res.read().decode())
        assert body["listen"] == f"0.0.0.0:{port}"

        assert bind.apply(False) == "127.0.0.1"
        assert _listen_v4(port) == {"127.0.0.1"}
        with urllib.request.urlopen(f"http://127.0.0.1:{port}/healthz") as res:
            body = json.loads(res.read().decode())
        assert body["listen"] == f"127.0.0.1:{port}"

        init = {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {}}

        def post(headers):
            req = urllib.request.Request(
                f"http://127.0.0.1:{port}/mcp",
                data=json.dumps(init).encode(),
                headers={"Content-Type": "application/json", "Authorization": "Bearer ld_test", **headers},
                method="POST",
            )
            try:
                with urllib.request.urlopen(req) as res:
                    return res.status, json.loads(res.read().decode())
            except urllib.error.HTTPError as exc:
                return exc.code, json.loads(exc.read().decode())

        status, body = post({})
        assert status == 403
        assert public in body["error"]["message"]
        status, body = post({"X-Forwarded-Proto": "https"})
        assert status == 200
        assert seen["transport"] == "https"

        assert bind.apply(True) == "0.0.0.0"
        assert _listen_v4(port) == {"0.0.0.0"}
        status, body = post({})
        assert status == 200
    finally:
        bind.close()


def test_plain_http_watcher_moves_the_listener_without_a_restart():
    def fetch(method, path, body, authorization, tool):
        return 200, {"ok": True}

    flag = {"on": True}
    handler = make_handler(fetch, allow_plain_http=lambda: flag["on"])
    bind = PublicBind(0, handler, loopback_only=False, plain_http=True)
    bind.start()
    stop = threading.Event()
    watcher = threading.Thread(
        target=watch_plain_http,
        args=(bind, lambda: flag["on"], stop, 0.05),
        daemon=True,
    )
    watcher.start()
    try:
        assert bind.host() == "0.0.0.0"
        flag["on"] = False
        _wait_host(bind, "127.0.0.1")
        assert _listen_v4(bind.port) == {"127.0.0.1"}
        with urllib.request.urlopen(f"http://127.0.0.1:{bind.port}/healthz") as res:
            assert res.status == 200
        flag["on"] = True
        _wait_host(bind, "0.0.0.0")
        assert _listen_v4(bind.port) == {"0.0.0.0"}
    finally:
        stop.set()
        watcher.join(timeout=2)
        bind.close()


def test_loopback_bind_stays_loopback_when_plain_http_is_on():
    def fetch(method, path, body, authorization, tool):
        return 200, {}

    handler = make_handler(fetch)
    fixed = PublicBind(0, handler, loopback_only=True, plain_http=True)
    assert fixed.address_for(True) == "127.0.0.1"
    fixed.start()
    try:
        assert fixed.apply(True) == "127.0.0.1"
        assert _listen_v4(fixed.port) == {"127.0.0.1"}
        assert fixed.apply(False) == "127.0.0.1"
        assert _listen_v4(fixed.port) == {"127.0.0.1"}
    finally:
        fixed.close()


def test_a_failed_flag_read_does_not_reopen_the_public_listener():
    def fetch(method, path, body, authorization, tool):
        return 200, {}

    state = {"row": 0, "fail": False}

    def reader():
        if state["fail"]:
            raise OSError("db down")
        return state["row"]

    gate = PlainHttpGate(True, reader, ttl=0)
    handler = make_handler(fetch, allow_plain_http=gate.allowed)
    bind = PublicBind(0, handler, loopback_only=False, plain_http=gate.allowed())
    bind.start()
    try:
        assert bind.host() == "127.0.0.1"
        state["fail"] = True
        assert bind.apply(gate.allowed()) == "127.0.0.1"
        assert "0.0.0.0" not in _listen_v4(bind.port)
    finally:
        bind.close()


def test_forwarded_call_carries_the_transport_header():
    captured = {}

    class Resp:
        status = 200

        def read(self):
            return b"{}"

        def __enter__(self):
            return self

        def __exit__(self, *args):
            return False

    def fake_urlopen(req, timeout=30):
        captured["header"] = req.get_header("X-lifedash-transport")
        return Resp()

    token = _TRANSPORT.set("https")
    try:
        with patch("lifedash.mcp_server.urlopen", fake_urlopen):
            status, body = urllib_fetch("http://127.0.0.1")("GET", "/api/v1/metrics", None, "Bearer x", "list_metrics")
    finally:
        _TRANSPORT.reset(token)
    assert status == 200
    assert body == {}
    assert captured["header"] == "https"


def test_spotify_writes_and_playback_follow_plain_http():
    seen = []

    def fetch(method, path, body, authorization, tool):
        seen.append((method, path, tool))
        return 200, {"ok": True}

    public = "https://mcp.example.com/mcp"
    handler = make_handler(fetch, mcp_public_url=public, allow_plain_http=lambda: False)
    server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        def post(name, headers):
            req = urllib.request.Request(
                f"http://127.0.0.1:{port}/mcp",
                data=json.dumps({
                    "jsonrpc": "2.0",
                    "id": 1,
                    "method": "tools/call",
                    "params": {"name": name, "arguments": {}},
                }).encode(),
                headers={"Content-Type": "application/json", "Authorization": "Bearer ldoa_spotify", **headers},
                method="POST",
            )
            try:
                with urllib.request.urlopen(req) as res:
                    return res.status, json.loads(res.read().decode())
            except urllib.error.HTTPError as exc:
                return exc.code, json.loads(exc.read().decode())

        for name in ("play", "sync_spotify"):
            status, body = post(name, {})
            assert status == 403
            assert public in body["error"]["message"]
        assert seen == []

        status, body = post("play", {"X-Forwarded-Proto": "https"})
        assert status == 200
        status, body = post("sync_spotify", {"X-Forwarded-Proto": "https"})
        assert status == 200
        assert seen == [
            ("GET", "/api/v1/agent-prompt", "auth"),
            ("POST", "/api/v1/spotify/playback/play", "play"),
            ("GET", "/api/v1/agent-prompt", "auth"),
            ("POST", "/api/v1/spotify/sync", "sync_spotify"),
        ]
    finally:
        server.shutdown()
        server.server_close()


def test_spotify_tools_map_onto_the_spotify_api_and_name_the_missing_endpoints():
    names = [tool["name"] for tool in TOOLS]
    spotify = [
        "spotify_status", "list_spotify_playlists", "get_spotify_playlist", "search_spotify",
        "get_spotify_track", "list_play_history", "list_saved_tracks", "list_top_items",
        "get_now_playing", "list_spotify_devices", "list_devices", "list_playlist_changes", "get_taste_profile",
        "list_spotify_prompts", "get_spotify_prompt", "create_spotify_playlist", "update_spotify_playlist",
        "add_playlist_tracks", "remove_playlist_tracks", "reorder_playlist_tracks", "replace_playlist_tracks",
        "discard_playlist_changes", "push_playlist_changes", "sync_spotify", "add_spotify_prompt",
        "update_spotify_prompt", "remove_spotify_prompt", "mark_prompt_run", "update_taste_profile",
        "get_queue", "play", "resume", "pause", "next", "previous", "seek", "set_volume", "set_shuffle", "set_repeat",
        "add_to_queue", "transfer_playback", "save_tracks", "remove_saved_tracks",
        "delete_spotify_playlist", "set_player_enabled",
        "set_track_metadata", "get_track_metadata", "list_active_seasons", "plan_mixtape",
    ]
    assert len(spotify) == 49
    assert len(names) == len(set(names))
    for name in spotify:
        assert names.count(name) == 1
    described = {tool["name"]: tool["description"] for tool in TOOLS}
    for name in ("search_spotify", "list_play_history", "list_saved_tracks", "list_top_items", "get_taste_profile", "update_taste_profile"):
        assert "recommendations" in described[name]
        assert "audio-features" in described[name]
    assert "token" not in described["spotify_status"].lower() or "No tokens" in described["spotify_status"]

    method, path, body = resolve("search_spotify", {"q": "north", "type": "track"})
    assert method == "GET" and path.startswith("/api/v1/spotify/search?")
    method, path, body = resolve("plan_mixtape", {"uris": ["spotify:track:abc"], "prompt_id": 4})
    assert method == "POST" and path == "/api/v1/spotify/mixtape/plan"
    assert body["uris"] == ["spotify:track:abc"] and body["prompt_id"] == 4
    method, path, body = resolve("list_active_seasons", {})
    assert (method, path, body) == ("GET", "/api/v1/spotify/seasons/active", None)
    method, path, body = resolve("discard_playlist_changes", {})
    assert (method, path, body) == ("POST", "/api/v1/spotify/discard", {})
    method, path, body = resolve("discard_playlist_changes", {"playlist_id": 3})
    assert (method, path) == ("POST", "/api/v1/spotify/playlists/3/discard")
    method, path, body = resolve("set_volume", {"volume_percent": 40, "device_id": "device1"})
    assert (method, path, body) == ("POST", "/api/v1/spotify/playback/volume", {"volume_percent": 40, "device_id": "device1"})
    method, path, body = resolve("play", {"playlist_id": 8})
    assert (method, path, body) == ("POST", "/api/v1/spotify/playback/play", {"playlist_id": 8})
    method, path, body = resolve("play", {"device_id": "desk"})
    assert (method, path, body) == ("POST", "/api/v1/spotify/playback/play", {"device_id": "desk"})
    method, path, body = resolve("resume", {})
    assert (method, path, body) == ("POST", "/api/v1/spotify/playback/play", {})
    method, path, body = resolve("resume", {"device_id": "desk"})
    assert (method, path, body) == ("POST", "/api/v1/spotify/playback/play", {"device_id": "desk"})
    method, path, body = resolve("get_queue", {})
    assert (method, path, body) == ("GET", "/api/v1/spotify/queue", None)
    method, path, body = resolve("save_tracks", {"uris": ["spotify:track:abc"]})
    assert (method, path, body) == ("POST", "/api/v1/spotify/saved", {"uris": ["spotify:track:abc"]})
    method, path, body = resolve("remove_saved_tracks", {"uris": ["spotify:track:abc"]})
    assert (method, path, body) == ("POST", "/api/v1/spotify/saved/remove", {"uris": ["spotify:track:abc"]})
    method, path, body = resolve("delete_spotify_playlist", {"playlist_id": 4})
    assert (method, path, body) == ("POST", "/api/v1/spotify/playlists/4/delete", {})
    method, path, body = resolve("set_player_enabled", {"enabled": False})
    assert (method, path, body) == ("POST", "/api/v1/spotify/player", {"enabled": False})
    method, path, body = resolve("sync_spotify", {})
    assert (method, path, body) == ("POST", "/api/v1/spotify/sync", {})
    method, path, body = resolve("list_devices", {})
    assert (method, path, body) == ("GET", "/api/v1/spotify/devices", None)
    method, path, body = resolve("list_spotify_devices", {})
    assert (method, path, body) == ("GET", "/api/v1/spotify/devices", None)
    assert "Omit play" in described["transfer_playback"]
    method, path, body = resolve("transfer_playback", {"device_id": "device1"})
    assert (method, path, body) == ("POST", "/api/v1/spotify/playback/transfer", {"device_id": "device1"})
    method, path, body = resolve("transfer_playback", {"device_id": "device1", "play": False})
    assert body == {"device_id": "device1", "play": False}
    method, path, body = resolve("transfer_playback", {"device_id": "device1", "play": True})
    assert path == "/api/v1/spotify/playback/transfer"
    assert body["play"] is True


def test_public_url_is_reread_on_each_request():
    current = {"public": "https://mcp-one.example/mcp", "issuer": "https://dash-one.example"}

    def fetch(method, path, body, authorization, tool):
        return 200, {}

    handler = make_handler(
        fetch,
        mcp_public_url=lambda: current["public"],
        issuer=lambda: current["issuer"],
    )
    server = ThreadingHTTPServer(("127.0.0.1", 0), handler)
    port = server.server_address[1]
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        def resource():
            with urllib.request.urlopen(f"http://127.0.0.1:{port}/.well-known/oauth-protected-resource/mcp") as res:
                return json.loads(res.read().decode())["resource"]

        assert resource() == "https://mcp-one.example/mcp"
        current["public"] = "https://mcp-two.example/mcp"
        current["issuer"] = "https://dash-two.example"
        assert resource() == "https://mcp-two.example/mcp"
    finally:
        server.shutdown()
        server.server_close()


def test_jsonrpc_accepts_only_tokens_the_api_accepts():
    """PHP accepts the bearer. A static API token and an OAuth access token both count."""
    api = "Bearer ld_agent"
    oauth = "Bearer ldoa_connector"
    accepted = {api, oauth}
    calls = []

    def fetch(method, path, body, authorization, tool):
        calls.append((method, path, authorization, tool))
        if authorization not in accepted:
            return 401, {"error": "Invalid or expired token."}
        if path == "/api/v1/agent-prompt":
            return 200, {"prompt": "ok"}
        if path == "/api/v1/metrics":
            kind = "oauth" if authorization == oauth else "api"
            return 200, {"metrics": [], "kind": kind}
        return 500, {"error": "unexpected " + path}

    def rpc(method, params=None, msg_id=1):
        message = {"jsonrpc": "2.0", "id": msg_id, "method": method}
        if params is not None:
            message["params"] = params
        return message

    listing = rpc("tools/list")
    calling = rpc("tools/call", {"name": "list_metrics", "arguments": {}})
    full = [tool["name"] for tool in TOOLS]
    assert presented_bearer("Bearer ld_agent") == "ld_agent"
    assert presented_bearer("bearer ldoa_connector") == "ldoa_connector"
    assert presented_bearer(None) is None
    assert presented_bearer("") is None
    assert presented_bearer("Bearer") is None
    assert presented_bearer("Bearer ") is None
    assert presented_bearer("Bearer\t") is None

    for header in (None, "", "Bearer", "Bearer ", "Bearer\t", "Basic abc"):
        calls.clear()
        for message in (listing, calling, rpc("initialize", {}), rpc("ping")):
            reply = handle_message(message, header, fetch)
            assert reply.http_status == 401
            assert reply["error"] == {"code": -32001, "message": "Authorization: Bearer is required."}
            assert "result" not in reply
            assert_no_tool_names(reply)
        assert calls == []

    for header in ("Bearer anything", "Bearer ld_nope", "Bearer ldoa_nope"):
        for message in (listing, calling):
            calls.clear()
            reply = handle_message(message, header, fetch)
            assert reply.http_status == 401
            assert reply["error"] == {"code": -32001, "message": "Invalid or expired token."}
            assert_no_tool_names(reply)
            assert calls == [("GET", "/api/v1/agent-prompt", header, "auth")]

    for header, kind in ((api, "api"), (oauth, "oauth")):
        calls.clear()
        listed = handle_message(listing, header, fetch)
        assert listed.http_status == 200
        assert [tool["name"] for tool in listed["result"]["tools"]] == full
        ping = handle_message(rpc("ping"), header, fetch)
        assert ping["result"] == {}
        called = handle_message(calling, header, fetch)
        assert called["result"]["isError"] is False
        text = json.loads(called["result"]["content"][0]["text"])
        assert text == {"metrics": [], "kind": kind}
        assert ("GET", "/api/v1/metrics", header, "list_metrics") in calls

    def lowercase(method, path, body, authorization, tool):
        assert authorization == "bearer ld_agent"
        if path == "/api/v1/agent-prompt":
            return 200, {"prompt": "ok"}
        return 200, {"metrics": [], "kind": "api"}

    lowered = handle_message(calling, "bearer ld_agent", lowercase)
    assert lowered["result"]["isError"] is False

    def down(method, path, body, authorization, tool):
        return 503, {"error": "db down", "tools": full}

    failed = handle_message(listing, api, down)
    assert failed["error"]["code"] == -32002
    assert failed.http_status == 502
    assert_no_tool_names(failed)
    assert "db down" not in json.dumps(failed)

    def forbidden(method, path, body, authorization, tool):
        return 403, {"error": "forbidden", "tools": full}

    blocked = handle_message(calling, oauth, forbidden)
    assert blocked.http_status == 403
    assert "result" not in blocked
    assert_no_tool_names(blocked)

    public = "https://mcp.example.com/mcp"
    issuer = "https://dash.example.com"
    handler = make_handler(fetch, mcp_public_url=public, issuer=issuer)
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
            try:
                with urllib.request.urlopen(req) as res:
                    raw = res.read().decode()
                    return res.status, raw
            except urllib.error.HTTPError as exc:
                return exc.code, exc.read().decode()

        def open_get(path):
            with urllib.request.urlopen(f"http://127.0.0.1:{port}{path}") as res:
                return res.status, json.loads(res.read().decode())

        before = len(calls)
        for path in (
            "/.well-known/oauth-protected-resource",
            "/.well-known/oauth-protected-resource/mcp",
            "/.well-known/oauth-authorization-server",
        ):
            status, body = open_get(path)
            assert status == 200
        assert len(calls) == before
        status, body = open_get("/.well-known/oauth-authorization-server")
        assert body["registration_endpoint"] == issuer + "/oauth/register"
        assert body["token_endpoint"] == issuer + "/oauth/token"
        status, body = open_get("/healthz")
        assert status == 200
        assert body["ok"] is True
        assert_no_tool_names(body)

        for header in ({}, {"Authorization": "Bearer"}, {"Authorization": "Bearer "}, {"Authorization": "Bearer anything"}):
            for payload in (listing, calling):
                status, raw = post(payload, header)
                assert status == 401
                assert_no_tool_names(raw)
                parsed = json.loads(raw)
                assert "result" not in parsed
                assert "tools" not in parsed

        for header in ({"Authorization": api}, {"Authorization": oauth}):
            status, raw = post(listing, header)
            assert status == 200
            assert [tool["name"] for tool in json.loads(raw)["result"]["tools"]] == full
            status, raw = post(calling, header)
            assert status == 200
            assert json.loads(raw)["result"]["isError"] is False
    finally:
        server.shutdown()
        server.server_close()
