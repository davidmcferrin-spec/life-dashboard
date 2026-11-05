from lifedash.mcp_server import TOOLS, handle_message, resolve


VAULT_TOOLS = (
    "get_backlinks",
    "list_child_pages",
    "set_page_parent",
    "favorite_page",
    "unfavorite_page",
    "recent_pages",
    "resolve_wikilink",
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
    method, path, body = resolve("resolve_wikilink", {"link": "[[Home|house]]"})
    assert method == "GET"
    assert path.startswith("/api/v1/pages/resolve?q=")
    assert "Home" in path
    assert body is None

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
