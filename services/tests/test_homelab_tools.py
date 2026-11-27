from lifedash.homelab_tools import resolve_homelab
from lifedash.mcp_server import TOOLS, oauth_scopes, resolve


def test_homelab_tools_are_registered_with_explicit_scopes():
    names = [tool["name"] for tool in TOOLS]
    expected = [
        "sonarr_status",
        "sonarr_add",
        "sonarr_delete",
        "radarr_library",
        "radarr_delete",
        "prowlarr_search",
        "prowlarr_apps",
        "qbittorrent_status",
        "qbittorrent_pause",
        "qbittorrent_delete",
    ]
    for name in expected:
        assert name in names
    described = {tool["name"]: tool["description"] for tool in TOOLS if tool["name"] in expected}
    assert "sonarr:read" in described["sonarr_status"]
    assert "confirm is true" in described["sonarr_delete"]
    assert "audit" in described["sonarr_delete"]
    assert "qbittorrent:write" in described["qbittorrent_delete"]
    assert "sonarr:read" in oauth_scopes()
    assert "qbittorrent:write" in oauth_scopes()
    assert names.count("sonarr_status") == 1


def test_homelab_tools_resolve_and_encode_ids():
    assert resolve("sonarr_status", {}) == ("GET", "/api/v1/sonarr/status", None)
    assert resolve("sonarr_library", {}) == ("GET", "/api/v1/sonarr/series", None)
    assert resolve("radarr_library", {}) == ("GET", "/api/v1/radarr/movies", None)
    assert resolve("prowlarr_search", {"query": "a b"})[1].startswith("/api/v1/prowlarr/search?query=")
    method, path, body = resolve(
        "sonarr_add",
        {"title": "Example", "tvdb_id": 42, "quality_profile_id": 1, "root_folder": "/tv", "search": True},
    )
    assert (method, path) == ("POST", "/api/v1/sonarr/series")
    assert body["remote_id"] == 42
    assert body["search"] is True
    assert resolve("qbittorrent_torrents", {"filter": "downloading"}) == (
        "GET",
        "/api/v1/qbittorrent/torrents?filter=downloading",
        None,
    )
    assert resolve("qbittorrent_pause", {"hash": "abc:def"}) == (
        "POST",
        "/api/v1/qbittorrent/torrents/abc%3Adef/pause",
        {},
    )


def test_destructive_homelab_tools_require_confirm():
    for name in ("sonarr_delete", "radarr_delete"):
        try:
            resolve_homelab(name, {"id": 4, "confirm": False, "delete_files": True})
        except ValueError as exc:
            assert "confirm" in str(exc)
        else:
            raise AssertionError(name)
    try:
        resolve("qbittorrent_delete", {"hash": "abc", "confirm": False, "delete_files": True})
    except ValueError as exc:
        assert "confirm" in str(exc)
    else:
        raise AssertionError("delete was sent")
    method, path, body = resolve("qbittorrent_delete", {"hash": "abc:def", "confirm": True, "delete_files": True})
    assert path == "/api/v1/qbittorrent/torrents/abc%3Adef/delete"
    assert body == {"confirm": True, "delete_files": True}
    method, path, body = resolve("sonarr_delete", {"id": 9, "confirm": True})
    assert path == "/api/v1/sonarr/series/9/delete"
    assert body["confirm"] is True
    assert body["delete_files"] is False


def test_unraid_power_requires_confirm_and_encodes_ids():
    try:
        resolve("unraid_docker_action", {"id": "srv:abc", "action": "stop", "confirm": False})
    except ValueError as exc:
        assert "confirm" in str(exc)
    else:
        raise AssertionError("stop was sent")
    try:
        resolve("unraid_array", {"state": "STOP", "confirm": False})
    except ValueError as exc:
        assert "confirm" in str(exc)
    else:
        raise AssertionError("array stop was sent")
    method, path, body = resolve("unraid_docker_action", {"id": "srv:abc", "action": "restart", "confirm": True})
    assert method == "POST"
    assert path == "/api/v1/unraid/docker/srv%3Aabc/restart"
    assert body["confirm"] is True
    method, path, body = resolve("unraid_vm_action", {"id": "vm:1", "action": "start", "confirm": False})
    assert path == "/api/v1/unraid/vms/vm%3A1/start"
    assert body["confirm"] is False
    assert resolve("plex_refresh", {"key": "2"}) == ("POST", "/api/v1/plex/libraries/2/refresh", {})
    assert "unraid:write" in __import__("lifedash.mcp_server", fromlist=["oauth_scopes"]).oauth_scopes()


def test_qbittorrent_settings_accept_each_auth_mode():
    method, path, body = resolve(
        "qbittorrent_save_settings",
        {"enabled": True, "base_url": "http://tower:8080", "auth": "none"},
    )
    assert (method, path) == ("POST", "/api/v1/qbittorrent/settings")
    assert body == {"enabled": True, "base_url": "http://tower:8080", "auth": "none"}
    method, path, body = resolve(
        "qbittorrent_save_settings",
        {"auth": "token", "token": "qbt_exampletokenvalue12345678"},
    )
    assert body["auth"] == "token"
    assert body["token"] == "qbt_exampletokenvalue12345678"
    method, path, body = resolve(
        "qbittorrent_save_settings",
        {"auth": "password", "username": "david", "password": "secret-value"},
    )
    assert body["auth"] == "password"
    assert body["username"] == "david"
    method, path, body = resolve(
        "jellyfin_save_settings",
        {"enabled": True, "base_url": "http://tower:8096", "user": "Ada"},
    )
    assert path == "/api/v1/jellyfin/settings"
    assert body["user"] == "Ada"
    try:
        resolve("qbittorrent_save_settings", {"auth": "cookie"})
    except ValueError as exc:
        assert "auth" in str(exc)
    else:
        raise AssertionError("unknown auth was accepted")
