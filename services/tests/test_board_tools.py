import pytest

from lifedash.board_tools import resolve_board
from lifedash.mcp_server import TOOLS, oauth_scopes, resolve


def test_board_tools_are_registered_and_scoped():
    names = [tool["name"] for tool in TOOLS]
    expected = [
        "publish_digest",
        "create_trip",
        "update_trip",
        "list_trips",
        "get_trip",
        "add_contact",
        "update_contact",
        "find_contacts",
        "add_video",
        "update_video",
        "list_videos",
        "log_finding",
        "list_findings",
    ]
    for name in expected:
        assert name in names
    described = {tool["name"]: tool["description"] for tool in TOOLS if tool["name"] in expected}
    assert "digest:write" in described["publish_digest"]
    assert "trips:write" in described["create_trip"]
    assert "trips:read" in described["list_trips"]
    assert "calls:write" in described["add_contact"]
    assert "calls:read" in described["find_contacts"]
    assert "videos:read" in described["list_videos"]
    assert "findings:write" in described["log_finding"]
    assert "04 Travel" in described["create_trip"]
    for scope in ("trips:read", "trips:write", "videos:read", "videos:write", "findings:read", "findings:write"):
        assert scope in oauth_scopes()


def test_board_tools_resolve_onto_the_board_api():
    method, path, body = resolve("publish_digest", {
        "source": "Space Desk",
        "title": "Monday",
        "date": "2026-11-23",
        "items": ["Launch window", {"text": "Crew"}],
        "page_id": 4,
    })
    assert (method, path) == ("POST", "/api/v1/digests")
    assert body["source"] == "Space Desk"
    assert body["items"][0] == "Launch window"
    assert body["page_id"] == 4
    method, path, body = resolve("create_trip", {
        "name": "Disney week",
        "destination": "Orlando",
        "start_date": "2026-12-01",
        "status": "planning",
        "owner_bot": "Imagineer",
        "tags": ["disney"],
        "template": True,
    })
    assert (method, path) == ("POST", "/api/v1/trips")
    assert body["template"] is True
    assert body["owner_bot"] == "Imagineer"
    method, path, body = resolve("list_trips", {"status": "booked", "upcoming": True, "tag": "disney"})
    assert method == "GET" and body is None
    assert "status=booked" in path and "upcoming=1" in path and "tag=disney" in path
    assert resolve("get_trip", {"trip_id": 8}) == ("GET", "/api/v1/trips/8", None)
    assert resolve("update_trip", {"trip_id": 8, "status": "booked"})[0:2] == ("POST", "/api/v1/trips/8")
    method, path, body = resolve("add_contact", {
        "name": "Front desk",
        "phones": ["2565550199"],
        "emails": ["desk@example.com"],
        "org": "Hotel",
        "tags": ["travel"],
    })
    assert (method, path) == ("POST", "/api/v1/calls/contacts/add")
    assert body["name"] == "Front desk"
    assert resolve("update_contact", {"contact_id": 3, "org": "Resort"})[1] == "/api/v1/calls/contacts/3"
    method, path, body = resolve("find_contacts", {"q": "desk"})
    assert method == "GET" and "q=desk" in path and body is None
    method, path, body = resolve("add_video", {"channel": "Disney trivia", "title": "Hidden Mickeys", "status": "idea"})
    assert (method, path) == ("POST", "/api/v1/videos")
    assert body["channel"] == "Disney trivia"
    assert resolve("update_video", {"video_id": 2, "status": "voiced"})[1] == "/api/v1/videos/2"
    method, path, body = resolve("list_videos", {"channel": "Disney trivia", "status": "idea"})
    assert "channel=Disney+trivia" in path and "status=idea" in path
    method, path, body = resolve("log_finding", {
        "topic": "housing",
        "title": "Lease comparison",
        "page_title": "NY move notes",
        "append": True,
    })
    assert (method, path) == ("POST", "/api/v1/findings")
    assert body["append"] is True
    method, path, body = resolve("list_findings", {"topic": "housing", "source_bot": "Radar Rick", "since": "2026-11-01"})
    assert "topic=housing" in path and "source_bot=Radar+Rick" in path and "since=2026-11-01" in path
    method, path, body = resolve("log_call", {
        "to_number": "2565550199",
        "purpose": "Confirm",
        "duration": 90,
        "placed_by": "Dial",
    })
    assert (method, path) == ("POST", "/api/v1/calls")
    assert body["to_number"] == "2565550199"
    assert body["duration"] == 90
    assert "direction" not in body
    method, path, body = resolve("create_handoff", {"title": "Park hours", "trip_id": 8})
    assert body["trip_id"] == 8
    assert resolve_board("list_metrics", {}) is None


def test_board_tools_reject_bad_arguments():
    with pytest.raises(ValueError, match="date"):
        resolve("publish_digest", {"source": "Space Desk", "title": "Monday", "date": "Monday", "items": ["One"]})
    with pytest.raises(ValueError, match="items"):
        resolve("publish_digest", {"source": "Space Desk", "title": "Monday", "date": "2026-11-23", "items": []})
    with pytest.raises(ValueError, match="status"):
        resolve("create_trip", {"name": "NYC", "status": "later"})
    with pytest.raises(ValueError, match="template"):
        resolve("create_trip", {"name": "NYC", "template": "yes"})
    with pytest.raises(ValueError, match="q"):
        resolve("find_contacts", {"q": "a"})
    with pytest.raises(ValueError, match="status"):
        resolve("add_video", {"channel": "Disney trivia", "title": "One", "status": "filming"})
    with pytest.raises(ValueError, match="append"):
        resolve("log_finding", {"topic": "housing", "title": "Lease", "append": "yes"})
