import pytest

from lifedash.handoff_tools import resolve_handoff
from lifedash.mcp_server import TOOLS, oauth_scopes, resolve


def test_handoff_tools_are_registered_and_scoped():
    names = [tool["name"] for tool in TOOLS]
    expected = [
        "create_handoff",
        "list_handoffs",
        "get_handoff",
        "claim_handoff",
        "update_handoff_status",
        "comment_handoff",
        "close_handoff",
    ]
    for name in expected:
        assert name in names
    described = {tool["name"]: tool["description"] for tool in TOOLS if tool["name"] in expected}
    assert "handoffs:write" in described["create_handoff"]
    assert "handoffs:read" in described["list_handoffs"]
    assert "mine" in described["list_handoffs"]
    assert "handoffs:read" in oauth_scopes()
    assert "handoffs:write" in oauth_scopes()
    brief = next(tool for tool in TOOLS if tool["name"] == "brief_me")
    assert "open handoffs" in brief["description"]


def test_handoff_tools_resolve_onto_the_handoff_api():
    method, path, body = resolve("create_handoff", {
        "title": "Ship the board",
        "body": "Notes",
        "to_agent": None,
        "priority": "high",
        "due_at": "2026-11-23",
        "tags": ["ops"],
        "linked_page_ids": [4],
        "linked_urls": ["https://example.com/ops"],
    })
    assert method == "POST"
    assert path == "/api/v1/handoffs"
    assert body["title"] == "Ship the board"
    assert body["to_agent"] is None
    assert body["priority"] == "high"
    assert body["tags"] == ["ops"]
    method, path, body = resolve("list_handoffs", {
        "to_agent": "Penny",
        "from_agent": "Chief",
        "status": "open",
        "tag": "ops",
        "mine": True,
        "open_only": True,
        "updated_since": "2026-11-22",
    })
    assert method == "GET" and body is None
    assert "to_agent=Penny" in path
    assert "from_agent=Chief" in path
    assert "status=open" in path
    assert "tag=ops" in path
    assert "mine=1" in path
    assert "open_only=1" in path
    assert "updated_since=2026-11-22" in path
    assert resolve("list_handoffs", {"mine": False}) == ("GET", "/api/v1/handoffs", None)
    assert resolve("get_handoff", {"handoff_id": 8}) == ("GET", "/api/v1/handoffs/8", None)
    assert resolve("claim_handoff", {"handoff_id": 8, "note": "Mine"}) == (
        "POST",
        "/api/v1/handoffs/8/claim",
        {"note": "Mine"},
    )
    assert resolve("update_handoff_status", {"handoff_id": 8, "status": "blocked", "note": "Waiting"}) == (
        "POST",
        "/api/v1/handoffs/8/status",
        {"status": "blocked", "note": "Waiting"},
    )
    assert resolve("comment_handoff", {"handoff_id": 8, "body": "Noted"}) == (
        "POST",
        "/api/v1/handoffs/8/comment",
        {"body": "Noted"},
    )
    assert resolve("close_handoff", {"handoff_id": 8, "status": "cancelled"}) == (
        "POST",
        "/api/v1/handoffs/8/close",
        {"status": "cancelled"},
    )
    assert resolve_handoff("list_metrics", {}) is None


def test_handoff_tools_reject_bad_arguments():
    with pytest.raises(ValueError, match="status"):
        resolve("update_handoff_status", {"handoff_id": 1, "status": "later"})
    with pytest.raises(ValueError, match="handoff_id"):
        resolve("get_handoff", {"handoff_id": True})
    with pytest.raises(ValueError, match="mine"):
        resolve("list_handoffs", {"mine": "yes"})
    with pytest.raises(ValueError, match="priority"):
        resolve("create_handoff", {"title": "X", "priority": "urgent"})
    with pytest.raises(ValueError, match="title"):
        resolve("create_handoff", {})
