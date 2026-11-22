from lifedash.mcp_server import TOOLS, handle_message, resolve


VAULT_TOOLS = (
    "get_backlinks",
    "list_child_pages",
    "set_page_parent",
    "favorite_page",
    "unfavorite_page",
    "recent_pages",
    "resolve_wikilink",
    "upload_page_image",
    "read_page",
    "patch_page",
    "archive_page",
    "unarchive_page",
    "list_folder_contents",
    "list_changed_pages",
)


def test_vault_tools_are_advertised_and_resolve():
    names = [tool["name"] for tool in TOOLS]
    for name in VAULT_TOOLS:
        assert name in names
    assert resolve("get_backlinks", {"page_id": 4}) == ("GET", "/api/v1/pages/4/backlinks", None)
    assert resolve("list_child_pages", {"page_id": 4}) == ("GET", "/api/v1/pages/4/children", None)
    assert resolve("set_page_parent", {"page_id": 4, "parent_id": None}) == (
        "POST",
        "/api/v1/pages/4/parent",
        {"parent_id": None},
    )
    assert resolve("favorite_page", {"page_id": 4}) == (
        "POST",
        "/api/v1/pages/4/favorite",
        {"favorite": True},
    )
    assert resolve("unfavorite_page", {"page_id": 4}) == (
        "POST",
        "/api/v1/pages/4/favorite",
        {"favorite": False},
    )
    method, path, body = resolve("recent_pages", {"limit": 5})
    assert (method, body) == ("GET", None)
    assert path == "/api/v1/pages/recent?limit=5"
    method, path, body = resolve("upload_page_image", {
        "filename": "shot.png",
        "content_base64": "aGVsbG8=",
        "alt": "Shot",
    })
    assert (method, path) == ("POST", "/api/v1/pages/images")
    assert body["filename"] == "shot.png"
    assert body["alt"] == "Shot"
    method, path, body = resolve("resolve_wikilink", {"link": "[[Home|house]]"})
    assert method == "GET"
    assert path.startswith("/api/v1/pages/resolve?q=")
    assert "Home" in path
    assert body is None

    assert resolve("read_page", {"page_id": 4}) == resolve("get_page", {"page_id": 4})
    assert resolve("read_page", {"page_id": 4}) == ("GET", "/api/v1/pages/4", None)
    method, path, body = resolve("get_page", {"title": "Home IPTV"})
    assert method == "GET"
    assert path.startswith("/api/v1/pages/lookup?")
    assert "Home" in path
    assert body is None
    method, path, body = resolve("read_page", {"slug": "home-iptv"})
    assert method == "GET"
    assert "slug=home-iptv" in path

    method, path, body = resolve("patch_page", {
        "title": "Daily log",
        "mode": "append",
        "text": "done",
    })
    assert (method, path) == ("POST", "/api/v1/pages/patch")
    assert body == {"title": "Daily log", "mode": "append", "text": "done"}
    method, path, body = resolve("patch_page", {
        "page_id": 9,
        "mode": "replace_section",
        "heading": "Log",
        "text": "fresh",
        "position": "end",
    })
    assert body["heading"] == "Log"
    assert body["page_id"] == 9

    assert resolve("archive_page", {"page_id": 9}) == ("POST", "/api/v1/pages/archive", {"page_id": 9})
    assert resolve("unarchive_page", {"slug": "old-note"}) == (
        "POST",
        "/api/v1/pages/unarchive",
        {"slug": "old-note"},
    )
    method, path, body = resolve("list_folder_contents", {"folder": "Home IPTV"})
    assert method == "GET"
    assert path.startswith("/api/v1/pages/folders/contents?")
    assert "Home" in path
    assert body is None
    method, path, body = resolve("list_changed_pages", {"since": "2026-11-01", "include_archived": True})
    assert method == "GET"
    assert "updated_since=2026-11-01" in path
    assert "include_archived=true" in path
    assert body is None
    method, path, body = resolve("search_pages", {"q": "Home IPTV", "folder": "Health", "updated_since": "2026-11-01"})
    assert "q=Home" in path or "q=Home+IPTV" in path or "Home" in path
    assert "folder=Health" in path
    assert "updated_since=2026-11-01" in path

    def fetch(method, path, body, authorization, tool):
        if path == "/api/v1/agent-prompt":
            return 200, {"prompt": "ok"}
        return 500, {"error": "unexpected"}

    reply = handle_message(
        {"jsonrpc": "2.0", "id": 1, "method": "tools/list"},
        "Bearer ld_ok",
        fetch,
    )
    listed = [tool["name"] for tool in reply["result"]["tools"]]
    assert listed == names
    assert "nextCursor" not in reply["result"]
    for name in VAULT_TOOLS:
        assert name in listed
