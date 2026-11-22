from lifedash.mcp_server import TOOLS, resolve


def test_money_ledger_tools_resolve_onto_the_money_api():
    method, path, body = resolve("get_money_summary", {})
    assert (method, path, body) == ("GET", "/api/v1/money/overview", None)
    method, path, body = resolve("refresh_accounts", {})
    assert (method, path, body) == ("POST", "/api/v1/money/accounts/refresh", {})
    method, path, body = resolve("refresh_accounts", {"account_id": 4})
    assert (method, path, body) == ("POST", "/api/v1/money/accounts/4/refresh", {})
    method, path, body = resolve("list_plaid_items", {})
    assert (method, path, body) == ("GET", "/api/v1/money/plaid/items", None)
    method, path, body = resolve("get_sync_status", {})
    assert (method, path, body) == ("GET", "/api/v1/money/plaid/status", None)
    method, path, body = resolve("set_debt_components", {"debt_id": 3, "principal": 80, "unapplied_funds": None})
    assert (method, path) == ("POST", "/api/v1/money/debts/3/components")
    assert body["principal"] == 80
    assert body["unapplied_funds"] is None
    assert "debt_id" not in body
    method, path, body = resolve("get_debt_components", {"debt_id": 3})
    assert (method, path, body) == ("GET", "/api/v1/money/debts/3/components", None)
    method, path, body = resolve("add_payment", {"bill_id": 2, "amount": 40, "status": "scheduled"})
    assert (method, path) == ("POST", "/api/v1/money/payments")
    assert body["status"] == "scheduled"
    method, path, body = resolve("update_payment", {"payment_id": 9, "status": "failed", "debt_id": None})
    assert (method, path) == ("POST", "/api/v1/money/payments/9")
    assert body["debt_id"] is None
    assert "payment_id" not in body
    method, path, body = resolve("list_payments", {"status": "pending", "bill_id": 2})
    assert method == "GET" and body is None
    assert "status=pending" in path and "bill_id=2" in path
    method, path, body = resolve("add_expected_inflow", {
        "kind": "paycheck", "source": "Work", "amount": 1000, "expected_on": "2026-11-27",
    })
    assert (method, path) == ("POST", "/api/v1/money/inflows")
    assert body["source"] == "Work"
    method, path, body = resolve("update_expected_inflow", {"inflow_id": 5, "amount": 20})
    assert (method, path) == ("POST", "/api/v1/money/inflows/5")
    assert body == {"amount": 20}
    method, path, body = resolve("list_expected_inflows", {"status": "expected"})
    assert (method, path, body) == ("GET", "/api/v1/money/inflows?status=expected", None)
    method, path, body = resolve("mark_inflow_received", {"inflow_id": 5, "transaction_id": 8, "received_on": "2026-11-22"})
    assert (method, path, body) == (
        "POST",
        "/api/v1/money/inflows/5/received",
        {"transaction_id": 8, "received_on": "2026-11-22"},
    )
    method, path, body = resolve("get_credit_utilization", {})
    assert (method, path, body) == ("GET", "/api/v1/money/utilization", None)
    method, path, body = resolve("list_benefit_pots", {})
    assert (method, path, body) == ("GET", "/api/v1/money/benefit-pots", None)
    method, path, body = resolve("save_benefit_pot", {
        "kind": "fsa", "name": "Health", "balance": 675, "plan_year": 2026, "deadline": "2026-12-31",
    })
    assert (method, path) == ("POST", "/api/v1/money/benefit-pots")
    assert body["kind"] == "fsa"
    method, path, body = resolve("set_transaction_category", {"transaction_id": 7, "category": "Groceries", "sticky": True})
    assert (method, path, body) == (
        "POST",
        "/api/v1/money/transactions/7/category",
        {"category": "Groceries", "sticky": True},
    )
    method, path, body = resolve("revert_transaction_category", {"transaction_id": 7, "drop_rule": True})
    assert (method, path, body) == (
        "POST",
        "/api/v1/money/transactions/7/category/revert",
        {"drop_rule": True},
    )
    method, path, body = resolve("set_eaten_on", {"transaction_id": 7, "eaten_on": None})
    assert (method, path, body) == ("POST", "/api/v1/money/transactions/7/eaten-on", {"eaten_on": None})
    method, path, body = resolve("get_food_week_totals", {"week_start": "2026-11-25"})
    assert (method, path, body) == ("GET", "/api/v1/money/food-week?week_start=2026-11-25", None)
    method, path, body = resolve("get_money_options", {})
    assert (method, path, body) == ("GET", "/api/v1/money/options", None)
    method, path, body = resolve("save_money_options", {"stale_hours": 12, "groceries_weekly": 150})
    assert (method, path) == ("POST", "/api/v1/money/options")
    assert body["stale_hours"] == 12
    method, path, body = resolve("add_transaction", {"account_id": 2, "amount": -4, "eaten_on": "2026-11-23"})
    assert body["eaten_on"] == "2026-11-23"
    names = [tool["name"] for tool in TOOLS]
    for name in (
        "get_money_summary", "refresh_accounts", "list_plaid_items", "get_sync_status",
        "set_debt_components", "get_debt_components",
        "add_payment", "update_payment", "list_payments",
        "add_expected_inflow", "update_expected_inflow", "list_expected_inflows", "mark_inflow_received",
        "get_credit_utilization", "list_benefit_pots", "save_benefit_pot",
        "set_transaction_category", "revert_transaction_category", "set_eaten_on",
        "get_food_week_totals", "get_money_options", "save_money_options",
    ):
        assert name in names
    summary = next(tool for tool in TOOLS if tool["name"] == "get_money_summary")
    assert "benefit pots" in summary["description"]
    refresh = next(tool for tool in TOOLS if tool["name"] == "refresh_accounts")
    assert "no live sync" in refresh["description"].lower()
