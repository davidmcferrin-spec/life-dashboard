"""MCP tools for the homelab apps.

Each app has its own scope. read, write, and * do not grant them.
Secrets stay in Settings → Homelab and are not tool results.
"""

from __future__ import annotations

from typing import Any
from urllib.parse import quote, urlencode

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}
_SERVICES = ("sonarr", "radarr", "prowlarr", "qbittorrent")
_FILTERS = (
    "all",
    "downloading",
    "seeding",
    "completed",
    "paused",
    "stopped",
    "active",
    "inactive",
    "resumed",
    "stalled",
    "stalled_uploading",
    "stalled_downloading",
    "errored",
)


def _tool(name: str, description: str, schema: dict[str, Any] | None = None) -> dict[str, Any]:
    return {"name": name, "description": description, "inputSchema": schema or _EMPTY}


def _obj(properties: dict[str, Any], required: list[str] | None = None) -> dict[str, Any]:
    schema: dict[str, Any] = {
        "type": "object",
        "properties": properties,
        "additionalProperties": False,
    }
    if required:
        schema["required"] = required
    return schema


def _settings_schema(service: str) -> dict[str, Any]:
    props: dict[str, Any] = {
        "enabled": {"type": "boolean"},
        "base_url": {"type": "string"},
    }
    if service == "qbittorrent":
        props["username"] = {"type": "string"}
        props["password"] = {"type": "string"}
    else:
        props["api_key"] = {"type": "string"}
    return _obj(props)


def _add_schema(remote: str) -> dict[str, Any]:
    return _obj(
        {
            "title": {"type": "string"},
            remote: {"type": "integer"},
            "quality_profile_id": {"type": "integer"},
            "root_folder": {"type": "string"},
            "search": {"type": "boolean"},
        },
        ["title", remote, "quality_profile_id", "root_folder"],
    )


_READ = (
    " Requires {scope}:read. The base URL and secrets stay in Settings → Homelab and are not returned."
)
_WRITE = (
    " Requires {scope}:write. The call is written to the audit log. "
    "The base URL and secrets stay in Settings → Homelab and are not returned."
)


def _d(scope: str, text: str, write: bool = False) -> str:
    tail = _WRITE if write else _READ
    return text + tail.format(scope=scope)


HOMELAB_TOOLS: list[dict[str, Any]] = []

for _service, _label, _remote in (
    ("sonarr", "Sonarr", "tvdb_id"),
    ("radarr", "Radarr", "tmdb_id"),
):
    _noun = "series" if _service == "sonarr" else "movie"
    _library = "series" if _service == "sonarr" else "movies"
    HOMELAB_TOOLS.extend(
        [
            _tool(f"{_service}_settings", _d(_service, f"Masked {_label} settings: enabled, base URL, and whether the secret is saved.")),
            _tool(
                f"{_service}_save_settings",
                _d(_service, f"Save {_label} settings. Leave api_key blank to keep the saved key. The key is stored encrypted.", True),
                _settings_schema(_service),
            ),
            _tool(f"{_service}_test", _d(_service, f"Check that {_label} answers and return its version.", True)),
            _tool(f"{_service}_status", _d(_service, f"{_label} system status: version, instance name, and OS.")),
            _tool(f"{_service}_queue", _d(_service, f"{_label} download queue.")),
            _tool(
                f"{_service}_calendar",
                _d(_service, f"{_label} calendar. start and end are YYYY-MM-DD. Omit them for the next 14 days."),
                _obj({"start": {"type": "string"}, "end": {"type": "string"}}),
            ),
            _tool(f"{_service}_missing", _d(_service, f"{_label} wanted or missing items.")),
            _tool(f"{_service}_library", _d(_service, f"{_label} library. The list is capped; total is the full count.")),
            _tool(
                f"{_service}_lookup",
                _d(_service, f"Look up a {_noun} in {_label}. Use the returned id when adding."),
                _obj({"term": {"type": "string"}}, ["term"]),
            ),
            _tool(f"{_service}_options", _d(_service, f"{_label} quality profiles and root folders. Pick ids from this list when adding.")),
            _tool(f"{_service}_health", _d(_service, f"{_label} health checks.")),
            _tool(
                f"{_service}_add",
                _d(_service, f"Add a {_noun} to {_label}. quality_profile_id and root_folder come from {_service}_options. search true starts a search.", True),
                _add_schema(_remote),
            ),
            _tool(
                f"{_service}_search",
                _d(_service, f"Tell {_label} to search for one {_noun} that is already in the library.", True),
                _obj({"id": {"type": "integer"}}, ["id"]),
            ),
            _tool(
                f"{_service}_delete",
                _d(
                    _service,
                    f"Delete a {_noun} from {_label}. Refused unless confirm is true. delete_files true also deletes the files on disk and is refused unless confirm is true.",
                    True,
                ),
                _obj(
                    {"id": {"type": "integer"}, "confirm": {"type": "boolean"}, "delete_files": {"type": "boolean"}},
                    ["id", "confirm"],
                ),
            ),
        ]
    )

HOMELAB_TOOLS.extend(
    [
        _tool("prowlarr_settings", _d("prowlarr", "Masked Prowlarr settings: enabled, base URL, and whether the API key is saved.")),
        _tool(
            "prowlarr_save_settings",
            _d("prowlarr", "Save Prowlarr settings. Leave api_key blank to keep the saved key.", True),
            _settings_schema("prowlarr"),
        ),
        _tool("prowlarr_test", _d("prowlarr", "Check that Prowlarr answers and return its version.", True)),
        _tool("prowlarr_status", _d("prowlarr", "Prowlarr system status: version, instance name, and OS.")),
        _tool("prowlarr_indexers", _d("prowlarr", "Prowlarr indexers and whether each one is enabled.")),
        _tool("prowlarr_health", _d("prowlarr", "Prowlarr health checks.")),
        _tool(
            "prowlarr_search",
            _d("prowlarr", "Search across Prowlarr indexers. This reads the indexers; it does not download."),
            _obj({"query": {"type": "string"}}, ["query"]),
        ),
        _tool("prowlarr_apps", _d("prowlarr", "Prowlarr application sync status: Sonarr, Radarr, and any other connected app.")),
        _tool("qbittorrent_settings", _d("qbittorrent", "Masked qBittorrent settings: enabled, base URL, username, and whether the password is saved.")),
        _tool(
            "qbittorrent_save_settings",
            _d("qbittorrent", "Save qBittorrent WebUI settings. Leave password blank to keep the saved one.", True),
            _settings_schema("qbittorrent"),
        ),
        _tool("qbittorrent_test", _d("qbittorrent", "Log in to qBittorrent and return its version.", True)),
        _tool("qbittorrent_status", _d("qbittorrent", "qBittorrent version and transfer speeds.")),
        _tool(
            "qbittorrent_torrents",
            _d("qbittorrent", "qBittorrent torrent list. filter is a WebUI filter such as downloading, seeding, paused, or stopped. category limits the list."),
            _obj({"filter": {"type": "string", "enum": list(_FILTERS)}, "category": {"type": "string"}, "hash": {"type": "string"}}),
        ),
        _tool("qbittorrent_categories", _d("qbittorrent", "qBittorrent categories and their save paths.")),
        _tool(
            "qbittorrent_pause",
            _d("qbittorrent", "Pause a torrent. qBittorrent 5 calls this stop; older servers still accept pause.", True),
            _obj({"hash": {"type": "string"}}, ["hash"]),
        ),
        _tool(
            "qbittorrent_resume",
            _d("qbittorrent", "Resume a torrent. qBittorrent 5 calls this start; older servers still accept resume.", True),
            _obj({"hash": {"type": "string"}}, ["hash"]),
        ),
        _tool(
            "qbittorrent_recheck",
            _d("qbittorrent", "Recheck a torrent's data.", True),
            _obj({"hash": {"type": "string"}}, ["hash"]),
        ),
        _tool(
            "qbittorrent_add",
            _d("qbittorrent", "Add a torrent by magnet link or http URL, with an optional category.", True),
            _obj({"urls": {"type": "string"}, "category": {"type": "string"}}, ["urls"]),
        ),
        _tool(
            "qbittorrent_delete",
            _d(
                "qbittorrent",
                "Delete a torrent. Refused unless confirm is true. delete_files true also deletes the downloaded files and is refused unless confirm is true.",
                True,
            ),
            _obj(
                {"hash": {"type": "string"}, "confirm": {"type": "boolean"}, "delete_files": {"type": "boolean"}},
                ["hash", "confirm"],
            ),
        ),
    ]
)


def resolve_homelab(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name in {f"{service}_settings" for service in _SERVICES}:
        _empty(name, args)
        return "GET", f"/api/v1/{name.split('_', 1)[0]}/settings", None
    if name in {f"{service}_test" for service in _SERVICES}:
        _empty(name, args)
        return "POST", f"/api/v1/{name.split('_', 1)[0]}/test", {}
    if name in {f"{service}_save_settings" for service in _SERVICES}:
        service = name.split("_", 1)[0]
        return "POST", f"/api/v1/{service}/settings", _settings_body(service, args)
    if name in {"sonarr_status", "radarr_status", "prowlarr_status"}:
        _empty(name, args)
        return "GET", f"/api/v1/{name.split('_', 1)[0]}/status", None
    if name in {"sonarr_queue", "radarr_queue"}:
        _empty(name, args)
        return "GET", f"/api/v1/{name.split('_', 1)[0]}/queue", None
    if name in {"sonarr_calendar", "radarr_calendar"}:
        service = name.split("_", 1)[0]
        _only(name, args, {"start", "end"})
        return "GET", f"/api/v1/{service}/calendar" + _query(args, ("start", "end")), None
    if name in {"sonarr_missing", "radarr_missing"}:
        _empty(name, args)
        return "GET", f"/api/v1/{name.split('_', 1)[0]}/missing", None
    if name == "sonarr_library":
        _empty(name, args)
        return "GET", "/api/v1/sonarr/series", None
    if name == "radarr_library":
        _empty(name, args)
        return "GET", "/api/v1/radarr/movies", None
    if name in {"sonarr_lookup", "radarr_lookup"}:
        service = name.split("_", 1)[0]
        term = _text(args, "term", name)
        return "GET", f"/api/v1/{service}/lookup?term=" + quote(term, safe=""), None
    if name in {"sonarr_options", "radarr_options"}:
        _empty(name, args)
        return "GET", f"/api/v1/{name.split('_', 1)[0]}/options", None
    if name in {"sonarr_health", "radarr_health", "prowlarr_health"}:
        _empty(name, args)
        return "GET", f"/api/v1/{name.split('_', 1)[0]}/health", None
    if name in {"sonarr_add", "radarr_add"}:
        service = "sonarr" if name == "sonarr_add" else "radarr"
        noun = "series" if service == "sonarr" else "movies"
        return "POST", f"/api/v1/{service}/{noun}", _add_body(name, args)
    if name in {"sonarr_search", "radarr_search"}:
        service = name.split("_", 1)[0]
        noun = "series" if service == "sonarr" else "movie"
        item_id = _int(args, "id", name)
        _only(name, args, {"id"})
        return "POST", f"/api/v1/{service}/{noun}/{item_id}/search", {}
    if name in {"sonarr_delete", "radarr_delete"}:
        service = name.split("_", 1)[0]
        noun = "series" if service == "sonarr" else "movie"
        item_id = _int(args, "id", name)
        confirm, delete_files = _destructive(name, args, {"id", "confirm", "delete_files"})
        return "POST", f"/api/v1/{service}/{noun}/{item_id}/delete", {"confirm": confirm, "delete_files": delete_files}
    if name == "prowlarr_indexers":
        _empty(name, args)
        return "GET", "/api/v1/prowlarr/indexers", None
    if name == "prowlarr_search":
        query = _text(args, "query", name)
        return "GET", "/api/v1/prowlarr/search?query=" + quote(query, safe=""), None
    if name == "prowlarr_apps":
        _empty(name, args)
        return "GET", "/api/v1/prowlarr/apps", None
    if name == "qbittorrent_status":
        _empty(name, args)
        return "GET", "/api/v1/qbittorrent/status", None
    if name == "qbittorrent_torrents":
        _only(name, args, {"filter", "category", "hash"})
        if "filter" in args and args["filter"] not in _FILTERS:
            raise ValueError("filter is not a qBittorrent torrent filter.")
        if "hash" in args and str(args["hash"]).strip() != "":
            return "GET", "/api/v1/qbittorrent/torrents/" + _hash(args["hash"]), None
        return "GET", "/api/v1/qbittorrent/torrents" + _query(args, ("filter", "category")), None
    if name == "qbittorrent_categories":
        _empty(name, args)
        return "GET", "/api/v1/qbittorrent/categories", None
    if name in {"qbittorrent_pause", "qbittorrent_resume", "qbittorrent_recheck"}:
        action = name.split("_", 1)[1]
        torrent = _hash(_only_hash(name, args))
        return "POST", f"/api/v1/qbittorrent/torrents/{torrent}/{action}", {}
    if name == "qbittorrent_add":
        _only(name, args, {"urls", "category"})
        urls = args.get("urls")
        if not isinstance(urls, str) or urls.strip() == "":
            raise ValueError("urls must be a magnet link or an http URL.")
        body: dict[str, Any] = {"urls": urls.strip()}
        if "category" in args:
            if not isinstance(args["category"], str):
                raise ValueError("category must be a string.")
            body["category"] = args["category"]
        return "POST", "/api/v1/qbittorrent/torrents/add", body
    if name == "qbittorrent_delete":
        torrent = _hash(args.get("hash"))
        confirm, delete_files = _destructive(name, args, {"hash", "confirm", "delete_files"})
        return "POST", f"/api/v1/qbittorrent/torrents/{torrent}/delete", {
            "confirm": confirm,
            "delete_files": delete_files,
        }
    return None


def _settings_body(service: str, args: dict[str, Any]) -> dict[str, Any]:
    allowed = {"enabled", "base_url", "username", "password"} if service == "qbittorrent" else {"enabled", "base_url", "api_key"}
    _only(f"{service}_save_settings", args, allowed)
    body: dict[str, Any] = {}
    if "enabled" in args:
        if not isinstance(args["enabled"], bool):
            raise ValueError("enabled must be true or false.")
        body["enabled"] = args["enabled"]
    if "base_url" in args:
        if not isinstance(args["base_url"], str):
            raise ValueError("base_url must be a string.")
        body["base_url"] = args["base_url"]
    if service == "qbittorrent":
        for key in ("username", "password"):
            if key in args:
                if not isinstance(args[key], str):
                    raise ValueError(f"{key} must be a string.")
                body[key] = args[key]
    elif "api_key" in args:
        if not isinstance(args["api_key"], str):
            raise ValueError("api_key must be a string.")
        body["api_key"] = args["api_key"]
    return body


def _add_body(name: str, args: dict[str, Any]) -> dict[str, Any]:
    service = "sonarr" if name == "sonarr_add" else "radarr"
    remote = "tvdb_id" if service == "sonarr" else "tmdb_id"
    _only(name, args, {"title", remote, "quality_profile_id", "root_folder", "search"})
    title = args.get("title")
    if not isinstance(title, str) or title.strip() == "":
        raise ValueError("title is required.")
    remote_id = args.get(remote)
    if isinstance(remote_id, bool) or not isinstance(remote_id, int) or remote_id < 1:
        raise ValueError(f"{remote} must be a positive id from lookup.")
    profile = args.get("quality_profile_id")
    if isinstance(profile, bool) or not isinstance(profile, int) or profile < 1:
        raise ValueError("quality_profile_id must be a positive id from options.")
    folder = args.get("root_folder")
    if not isinstance(folder, str) or folder.strip() == "":
        raise ValueError("root_folder must be a path from options.")
    body: dict[str, Any] = {
        "title": title.strip(),
        "remote_id": remote_id,
        "quality_profile_id": profile,
        "root_folder": folder.strip(),
    }
    if "search" in args:
        if not isinstance(args["search"], bool):
            raise ValueError("search must be true or false.")
        body["search"] = args["search"]
    return body


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")


def _only(name: str, args: dict[str, Any], allowed: set[str]) -> None:
    unknown = set(args) - allowed
    if unknown:
        raise ValueError(name + " does not accept " + ", ".join(sorted(unknown)) + ".")


def _text(args: dict[str, Any], key: str, name: str) -> str:
    _only(name, args, {key})
    value = args.get(key)
    if not isinstance(value, str) or value.strip() == "":
        raise ValueError(f"{key} is required.")
    return value.strip()


def _int(args: dict[str, Any], key: str, name: str) -> int:
    value = args.get(key)
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise ValueError(f"{key} must be a positive integer.")
    return value


def _query(args: dict[str, Any], keys: tuple[str, ...]) -> str:
    picked: dict[str, str] = {}
    for key in keys:
        if key not in args or args[key] in (None, ""):
            continue
        if not isinstance(args[key], str):
            raise ValueError(f"{key} must be a string.")
        picked[key] = args[key]
    if not picked:
        return ""
    return "?" + urlencode(picked)


def _hash(value: Any) -> str:
    if not isinstance(value, str):
        raise ValueError("hash must be a torrent id.")
    torrent = value.strip()
    if torrent == "" or "/" in torrent or " " in torrent:
        raise ValueError("hash must be a torrent id.")
    return quote(torrent, safe="")


def _only_hash(name: str, args: dict[str, Any]) -> str:
    _only(name, args, {"hash"})
    value = args.get("hash")
    if not isinstance(value, str):
        raise ValueError("hash must be a torrent id.")
    return value


def _destructive(name: str, args: dict[str, Any], allowed: set[str]) -> tuple[bool, bool]:
    _only(name, args, allowed)
    if not isinstance(args.get("confirm"), bool):
        raise ValueError("confirm must be true or false.")
    delete_files = False
    if "delete_files" in args:
        if not isinstance(args["delete_files"], bool):
            raise ValueError("delete_files must be true or false.")
        delete_files = args["delete_files"]
    if args["confirm"] is not True:
        if delete_files:
            raise ValueError("Deleting files needs confirm set to true.")
        raise ValueError("Deleting needs confirm set to true.")
    return True, delete_files
