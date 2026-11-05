"""Name resolution for Hermes, OpenCode, and OpenClaw."""

import pytest

from lifedash.agent_tools import resolve_agent
from lifedash.mcp_server import TOOLS, resolve


def test_agent_tools_are_in_the_catalog():
    names = [tool["name"] for tool in TOOLS]
    for name in (
        "hermes_status",
        "hermes_list_sessions",
        "hermes_start_session",
        "hermes_send",
        "hermes_poll",
        "opencode_status",
        "openclaw_status",
        "openclaw_ask",
    ):
        assert name in names
    assert "opencode_run" not in names
    assert "opencode_ask" not in names
    assert names.count("list_bills") == 1


def test_hermes_and_openclaw_routes():
    assert resolve("hermes_status", {}) == ("GET", "/api/v1/agents/hermes/status", None)
    assert resolve("hermes_list_sessions", {}) == ("GET", "/api/v1/agents/hermes/sessions", None)
    method, path, body = resolve("hermes_start_session", {"title": "Pantry"})
    assert (method, path, body) == ("POST", "/api/v1/agents/hermes/sessions", {"title": "Pantry"})
    method, path, body = resolve("hermes_send", {"message": "Hello", "session_id": "api_1"})
    assert (method, path, body) == (
        "POST",
        "/api/v1/agents/hermes/runs",
        {"message": "Hello", "session_id": "api_1"},
    )
    assert resolve("hermes_poll", {"run_id": "run_1"}) == ("GET", "/api/v1/agents/hermes/runs/run_1", None)
    assert resolve("opencode_status", {})[1] == "/api/v1/agents/opencode/status"
    method, path, body = resolve("openclaw_ask", {"prompt": "Reply with ok"})
    assert (method, path, body) == ("POST", "/api/v1/agents/openclaw/ask", {"prompt": "Reply with ok"})
    assert resolve_agent("list_bills", {}) is None


def test_agent_tools_reject_shell_and_bad_ids():
    with pytest.raises(ValueError):
        resolve("hermes_send", {"message": "hi", "command": "rm -rf /"})
    with pytest.raises(ValueError):
        resolve("hermes_poll", {"run_id": "../etc/passwd"})
    with pytest.raises(ValueError):
        resolve("hermes_send", {"message": "hi", "session_id": "bad id"})
    with pytest.raises(ValueError):
        resolve("openclaw_ask", {"prompt": "ok", "model": "other"})
    with pytest.raises(ValueError):
        resolve("opencode_status", {"command": "bash"})
    with pytest.raises(ValueError):
        resolve("hermes_status", {"extra": 1})
    with pytest.raises(ValueError):
        resolve("openclaw_ask", {"prompt": ""})
    with pytest.raises(ValueError):
        resolve("openclaw_ask", {"prompt": "x" * 4001})


def test_default_tools_list_returns_every_tool_without_a_cursor():
    from lifedash.mcp_server import handle_message

    def fetch(method, path, body, authorization, tool):
        assert path == "/api/v1/agent-prompt"
        return 200, {"prompt": "ok"}

    full = [tool["name"] for tool in TOOLS]
    for params in (None, {}, {"cursor": None}):
        message = {"jsonrpc": "2.0", "id": 1, "method": "tools/list"}
        if params is not None:
            message["params"] = params
        reply = handle_message(message, "Bearer token", fetch)
        result = reply["result"]
        names = [tool["name"] for tool in result["tools"]]
        assert "nextCursor" not in result
        assert "error" not in reply
        assert names == full
        for name in ("hermes_send", "brief_me", "health_status", "list_bills", "ollama_status"):
            assert name in names
    init = handle_message(
        {
            "jsonrpc": "2.0",
            "id": 2,
            "method": "initialize",
            "params": {"protocolVersion": "2026-07-28"},
        },
        "Bearer token",
        fetch,
    )
    assert init["result"]["protocolVersion"] == "2026-07-28"
    assert init["result"]["capabilities"]["tools"]["listChanged"] is False
    bad = handle_message(
        {"jsonrpc": "2.0", "id": 3, "method": "tools/list", "params": {"cursor": "2"}},
        "Bearer token",
        fetch,
    )
    assert bad["error"]["code"] == -32602
    assert "result" not in bad


def test_explicit_cursor_pages_the_same_registry():
    from lifedash.mcp_server import TOOL_PAGE, handle_message

    def fetch(method, path, body, authorization, tool):
        assert path == "/api/v1/agent-prompt"
        return 200, {"prompt": "ok"}

    names = []
    cursor = "0"
    pages = 0
    while cursor is not None:
        reply = handle_message(
            {"jsonrpc": "2.0", "id": 4, "method": "tools/list", "params": {"cursor": cursor}},
            "Bearer token",
            fetch,
        )
        result = reply["result"]
        batch = [tool["name"] for tool in result["tools"]]
        assert 0 < len(batch) <= TOOL_PAGE
        if pages == 0:
            assert result["nextCursor"] == str(TOOL_PAGE)
            assert "hermes_send" not in batch
        names.extend(batch)
        cursor = result.get("nextCursor")
        pages += 1
    assert pages > 1
    assert names == [tool["name"] for tool in TOOLS]
    for name in ("hermes_send", "brief_me", "health_status"):
        assert name in names
