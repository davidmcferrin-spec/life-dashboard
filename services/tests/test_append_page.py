from lifedash.mcp_server import TOOLS, resolve


def test_append_page_and_stale_write_are_described():
    described = {tool["name"]: tool["description"] for tool in TOOLS}
    assert "append_page" in described
    assert "logs and changelogs" in described["append_page"]
    assert "append_page" in described["write_page"]
    assert "stale_write" in described["write_page"]
    assert "append_page" in described["patch_page"]


def test_append_page_resolves():
    method, path, body = resolve("append_page", {"page_id": 91, "text": "- shipped", "under_heading": "Log"})
    assert (method, path) == ("POST", "/api/v1/pages/append")
    assert body == {"page_id": 91, "text": "- shipped", "under_heading": "Log"}
    method, path, body = resolve("append_page", {"page_id": 91, "text": "- end"})
    assert body == {"page_id": 91, "text": "- end"}


def test_write_page_can_send_a_version_check():
    method, path, body = resolve("write_page", {
        "page_id": 91,
        "title": "Vault Changelog",
        "body": "whole",
        "expected_version": 3,
        "expected_updated_at": "2026-11-27 04:00:00",
    })
    assert (method, path) == ("POST", "/api/v1/pages/91/update")
    assert body["expected_version"] == 3
    assert body["expected_updated_at"] == "2026-11-27 04:00:00"
    method, path, body = resolve("write_page", {"title": "Notes", "body": "fresh"})
    assert path == "/api/v1/pages"
    assert "expected_version" not in body


def test_bad_version_is_refused_before_the_request():
    try:
        resolve("write_page", {"page_id": 91, "title": "Notes", "expected_version": 0})
    except ValueError as exc:
        assert "expected_version" in str(exc)
    else:
        raise AssertionError("version 0 was accepted")
