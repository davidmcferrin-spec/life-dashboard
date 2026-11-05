"""Platform bundle tools stay on the one public tool list."""

from lifedash.mcp_server import TOOLS, resolve
from lifedash.platform_tools import resolve_platform


def test_platform_tools_are_in_the_one_list():
    names = [tool["name"] for tool in TOOLS]
    for name in (
        "grants_status",
        "brief_me",
        "brief_history",
        "graph_query",
        "graph_neighbors",
        "graph_link",
        "health_status",
        "health_readiness",
        "health_sleep",
        "health_workouts",
        "health_metrics",
        "health_sync",
        "health_log_workout",
        "health_log_meal",
        "health_log_body",
        "home_status",
        "mail_status",
        "hermes_stop",
    ):
        assert names.count(name) == 1
    assert "neo4j" not in " ".join(names)
    proposal = next(tool for tool in TOOLS if tool["name"] == "propose_envelope_assignment")
    assert "proposal" in proposal["description"].lower()
    assert "does not transfer" in proposal["description"].lower()
    assert "moves_money" not in proposal["inputSchema"]["properties"]


def test_platform_routes():
    assert resolve("grants_status", {}) == ("GET", "/api/v1/grants/mine", None)
    assert resolve("brief_me", {}) == ("POST", "/api/v1/brief", {})
    assert resolve("health_status", {})[1] == "/api/v1/health/status"
    assert resolve("health_sync", {}) == ("POST", "/api/v1/health/sync", {})
    assert resolve("mail_status", {})[1] == "/api/v1/mail/status"
    method, path, body = resolve("health_log_workout", {"title": "Walk", "minutes": 30, "day": "2026-11-05"})
    assert (method, path, body) == (
        "POST",
        "/api/v1/health/workouts",
        {"title": "Walk", "minutes": 30, "day": "2026-11-05"},
    )
    method, path, body = resolve("health_log_body", {"weight_lb": 180})
    assert body == {"weight_lb": 180}
    assert resolve("graph_neighbors", {"kind": "page", "ref": 4})[1] == "/api/v1/graph/neighbors?kind=page&ref=4"
    assert resolve("hermes_stop", {"run_id": "run_1"}) == ("POST", "/api/v1/agents/hermes/runs/run_1/stop", {})
    assert resolve_platform("list_bills", {}) is None


def test_platform_tools_reject_extra_fields():
    import pytest

    with pytest.raises(ValueError):
        resolve("health_log_meal", {"note": "eggs", "provider": "samsung"})
    with pytest.raises(ValueError):
        resolve("health_sync", {"token": "secret"})
    with pytest.raises(ValueError):
        resolve("graph_link", {"from_kind": "page", "from_ref": 1, "to_kind": "bill", "to_ref": 2, "transfer": True})
    with pytest.raises(ValueError):
        resolve("hermes_stop", {"run_id": "ok", "command": "kill"})
    with pytest.raises(ValueError):
        resolve("brief_me", {"balance": 10})
