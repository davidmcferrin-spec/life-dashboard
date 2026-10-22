"""Spotify MCP tools. They forward to /api/v1 and never see a token."""

from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlencode

TASTE = (
    "Spotify recommendations and audio-features endpoints are unavailable to new apps. "
    "Build from search, play history, top items, liked songs, and the taste profile."
)
WRITE = "Requires spotify:write. A token write is refused until Allow MCP updates is on."
PLAYBACK = "Requires spotify:playback. A token playback command is refused until Allow MCP updates is on."


def _obj(properties: dict[str, Any], required: list[str] | None = None) -> dict[str, Any]:
    schema: dict[str, Any] = {"type": "object", "properties": properties, "additionalProperties": False}
    if required:
        schema["required"] = required
    return schema


def _tool(name: str, description: str, properties: dict[str, Any] | None = None, required: list[str] | None = None) -> dict[str, Any]:
    return {"name": name, "description": description, "inputSchema": _obj(properties or {}, required)}


SPOTIFY_TOOLS: list[dict[str, Any]] = [
    _tool("spotify_status", "Connected Spotify account, scopes, and the last sync. No tokens. Requires spotify:read."),
    _tool("list_spotify_playlists", "Synced playlists, including local drafts and how many edits are waiting to push. Requires spotify:read."),
    _tool(
        "get_spotify_playlist",
        "One playlist, its synced tracks, staged changes, and the preview after those changes. Requires spotify:read.",
        {"playlist_id": {"type": "integer"}},
        ["playlist_id"],
    ),
    _tool(
        "search_spotify",
        "Live Spotify search for tracks, artists, or albums. " + TASTE + " Requires spotify:read.",
        {
            "q": {"type": "string"},
            "type": {"type": "string", "description": "track, artist, album, or a comma-separated mix. Default track."},
        },
        ["q"],
    ),
    _tool(
        "get_spotify_track",
        "One track from the live Spotify catalog. Requires spotify:read.",
        {"track_id": {"type": "string"}},
        ["track_id"],
    ),
    _tool(
        "list_play_history",
        "Plays stored from polling recently-played. " + TASTE + " Requires spotify:read.",
        {"from": {"type": "string"}, "to": {"type": "string"}, "limit": {"type": "integer"}},
    ),
    _tool(
        "list_saved_tracks",
        "Liked songs stored on the server. " + TASTE + " Requires spotify:read.",
        {"limit": {"type": "integer"}},
    ),
    _tool(
        "list_top_items",
        "Top artists or tracks by time range, refreshed daily. " + TASTE + " Requires spotify:read.",
        {
            "kind": {"type": "string", "description": "artist or track"},
            "time_range": {"type": "string", "description": "short_term, medium_term, or long_term"},
        },
    ),
    _tool("get_now_playing", "What is playing now, from Spotify. Requires spotify:read."),
    _tool("list_spotify_devices", "Spotify Connect devices: id, name, type, and which one is active. Requires spotify:read."),
    _tool("list_devices", "Spotify Connect devices: id, name, type, and which one is active. Requires spotify:read."),
    _tool(
        "list_playlist_changes",
        "Staged edits that have not been pushed, or that failed. Requires spotify:read.",
        {"playlist_id": {"type": "integer"}},
        ["playlist_id"],
    ),
    _tool("get_taste_profile", "The taste profile document. " + TASTE + " Requires spotify:read."),
    _tool("list_spotify_prompts", "Playlist prompt library. Requires spotify:read."),
    _tool(
        "get_spotify_prompt",
        "One playlist prompt. Requires spotify:read.",
        {"prompt_id": {"type": "integer"}},
        ["prompt_id"],
    ),
    _tool(
        "create_spotify_playlist",
        "Stage a new playlist locally. Push it to create it on Spotify. Set ai true, or send prompt text, to make an AI playlist. A regular create is not an AI playlist. " + WRITE,
        {
            "name": {"type": "string"},
            "description": {"type": "string"},
            "public": {"type": "boolean"},
            "ai": {"type": "boolean"},
            "prompt": {"type": "string"},
            "refresh_schedule": {"type": "string"},
            "source_prompt_id": {"type": "integer"},
        },
        ["name"],
    ),
    _tool(
        "update_spotify_playlist",
        "Stage a name, description, or public flag. Refuses a playlist you do not own. " + WRITE,
        {
            "playlist_id": {"type": "integer"},
            "name": {"type": "string"},
            "description": {"type": "string"},
            "public": {"type": "boolean"},
        },
        ["playlist_id"],
    ),
    _tool(
        "add_playlist_tracks",
        "Stage spotify:track URIs onto a playlist you own. " + WRITE,
        {
            "playlist_id": {"type": "integer"},
            "uris": {"type": "array", "items": {"type": "string"}},
            "position": {"type": "integer"},
        },
        ["playlist_id", "uris"],
    ),
    _tool(
        "remove_playlist_tracks",
        "Stage removal of spotify:track URIs from a playlist you own. " + WRITE,
        {
            "playlist_id": {"type": "integer"},
            "uris": {"type": "array", "items": {"type": "string"}},
        },
        ["playlist_id", "uris"],
    ),
    _tool(
        "reorder_playlist_tracks",
        "Stage a reorder against the current preview of a playlist you own. It fails on push if Spotify's track list changed. " + WRITE,
        {
            "playlist_id": {"type": "integer"},
            "range_start": {"type": "integer"},
            "insert_before": {"type": "integer"},
            "length": {"type": "integer"},
        },
        ["playlist_id", "range_start", "insert_before"],
    ),
    _tool(
        "replace_playlist_tracks",
        "Stage a full replacement of tracks on a playlist you own. " + WRITE,
        {
            "playlist_id": {"type": "integer"},
            "uris": {"type": "array", "items": {"type": "string"}},
        },
        ["playlist_id", "uris"],
    ),
    _tool(
        "discard_playlist_changes",
        "Drop staged edits for one playlist, or all of them when playlist_id is omitted. " + WRITE,
        {"playlist_id": {"type": "integer"}},
    ),
    _tool(
        "push_playlist_changes",
        "Push staged edits for one playlist you own, or every owned playlist when playlist_id is omitted. Followed playlists are skipped. " + WRITE,
        {"playlist_id": {"type": "integer"}},
    ),
    _tool(
        "sync_spotify",
        "Pull from Spotify for one playlist, or the whole library when playlist_id is omitted. " + WRITE,
        {"playlist_id": {"type": "integer"}},
    ),
    _tool(
        "add_spotify_prompt",
        "Add a prompt for an AI playlist created here. target_playlist_id is refused for any other playlist. " + WRITE,
        {
            "name": {"type": "string"},
            "prompt": {"type": "string"},
            "target_playlist_id": {"type": "integer"},
            "refresh_schedule": {"type": "string"},
            "notes": {"type": "string"},
            "active": {"type": "boolean"},
        },
        ["name", "prompt"],
    ),
    _tool(
        "update_spotify_prompt",
        "Update a prompt. target_playlist_id must be an AI playlist created here. " + WRITE,
        {
            "prompt_id": {"type": "integer"},
            "name": {"type": "string"},
            "prompt": {"type": "string"},
            "target_playlist_id": {"type": "integer"},
            "refresh_schedule": {"type": "string"},
            "notes": {"type": "string"},
            "active": {"type": "boolean"},
        },
        ["prompt_id"],
    ),
    _tool(
        "remove_spotify_prompt",
        "Delete a prompt. " + WRITE,
        {"prompt_id": {"type": "integer"}},
        ["prompt_id"],
    ),
    _tool(
        "mark_prompt_run",
        "Record that a prompt was run. This does not call a model. " + WRITE,
        {"prompt_id": {"type": "integer"}},
        ["prompt_id"],
    ),
    _tool(
        "update_taste_profile",
        "Replace the taste profile. " + TASTE + " " + WRITE,
        {"profile": {"type": "object"}},
        ["profile"],
    ),
    _tool(
        "play",
        "Start playback of a context URI, track URIs, or a synced playlist id. " + PLAYBACK,
        {
            "context_uri": {"type": "string"},
            "uris": {"type": "array", "items": {"type": "string"}},
            "playlist_id": {"type": "integer"},
            "device_id": {"type": "string"},
            "offset": {"type": "integer"},
        },
    ),
    _tool("pause", "Pause playback. " + PLAYBACK, {"device_id": {"type": "string"}}),
    _tool("next", "Skip to the next track. " + PLAYBACK, {"device_id": {"type": "string"}}),
    _tool("previous", "Return to the previous track. " + PLAYBACK, {"device_id": {"type": "string"}}),
    _tool(
        "seek",
        "Seek to position_ms. " + PLAYBACK,
        {"position_ms": {"type": "integer"}, "device_id": {"type": "string"}},
        ["position_ms"],
    ),
    _tool(
        "set_volume",
        "Set volume_percent from 0 to 100. " + PLAYBACK,
        {"volume_percent": {"type": "integer"}, "device_id": {"type": "string"}},
        ["volume_percent"],
    ),
    _tool(
        "set_shuffle",
        "Turn shuffle on or off. " + PLAYBACK,
        {"state": {"type": "boolean"}, "device_id": {"type": "string"}},
        ["state"],
    ),
    _tool(
        "set_repeat",
        "Set repeat to off, track, or context. " + PLAYBACK,
        {"state": {"type": "string"}, "device_id": {"type": "string"}},
        ["state"],
    ),
    _tool(
        "add_to_queue",
        "Queue a spotify:track URI. " + PLAYBACK,
        {"uri": {"type": "string"}, "device_id": {"type": "string"}},
        ["uri"],
    ),
    _tool(
        "transfer_playback",
        "Move playback to a Spotify Connect device. Omit play to keep the current playback state. " + PLAYBACK,
        {"device_id": {"type": "string"}, "play": {"type": "boolean"}},
        ["device_id"],
    ),
]

_NAMES = {tool["name"] for tool in SPOTIFY_TOOLS}


def resolve_spotify(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name not in _NAMES:
        return None
    if name == "spotify_status":
        _empty(name, args)
        return "GET", "/api/v1/spotify/status", None
    if name == "list_spotify_playlists":
        _empty(name, args)
        return "GET", "/api/v1/spotify/playlists", None
    if name == "get_spotify_playlist":
        return "GET", f"/api/v1/spotify/playlists/{_id(args, 'playlist_id')}", None
    if name == "search_spotify":
        _unknown(name, args, {"q", "type"})
        q = args.get("q")
        if not isinstance(q, str) or not q.strip():
            raise ValueError("q is required.")
        query = {"q": q.strip()}
        if args.get("type"):
            query["type"] = str(args["type"])
        return "GET", "/api/v1/spotify/search?" + urlencode(query), None
    if name == "get_spotify_track":
        _unknown(name, args, {"track_id"})
        track_id = args.get("track_id")
        if not isinstance(track_id, str) or re.fullmatch(r"[A-Za-z0-9]+", track_id) is None:
            raise ValueError("track_id must be a Spotify id.")
        return "GET", "/api/v1/spotify/tracks/" + track_id, None
    if name == "list_play_history":
        _unknown(name, args, {"from", "to", "limit"})
        return "GET", "/api/v1/spotify/history" + _query(args, ("from", "to", "limit")), None
    if name == "list_saved_tracks":
        _unknown(name, args, {"limit"})
        return "GET", "/api/v1/spotify/saved" + _query(args, ("limit",)), None
    if name == "list_top_items":
        _unknown(name, args, {"kind", "time_range"})
        return "GET", "/api/v1/spotify/top" + _query(args, ("kind", "time_range")), None
    if name == "get_now_playing":
        _empty(name, args)
        return "GET", "/api/v1/spotify/player", None
    if name in {"list_spotify_devices", "list_devices"}:
        _empty(name, args)
        return "GET", "/api/v1/spotify/devices", None
    if name == "list_playlist_changes":
        return "GET", f"/api/v1/spotify/playlists/{_id(args, 'playlist_id')}/changes", None
    if name == "get_taste_profile":
        _empty(name, args)
        return "GET", "/api/v1/spotify/taste", None
    if name == "list_spotify_prompts":
        _empty(name, args)
        return "GET", "/api/v1/spotify/prompts", None
    if name == "get_spotify_prompt":
        return "GET", f"/api/v1/spotify/prompts/{_id(args, 'prompt_id')}", None
    if name == "create_spotify_playlist":
        _unknown(name, args, {"name", "description", "public", "ai", "prompt", "refresh_schedule", "source_prompt_id"})
        return "POST", "/api/v1/spotify/playlists", _pick(args, ("name", "description", "public", "ai", "prompt", "refresh_schedule", "source_prompt_id"))
    if name == "update_spotify_playlist":
        _unknown(name, args, {"playlist_id", "name", "description", "public"})
        playlist_id = _id(args, "playlist_id")
        return "POST", f"/api/v1/spotify/playlists/{playlist_id}/update", _pick(args, ("name", "description", "public"))
    if name == "add_playlist_tracks":
        _unknown(name, args, {"playlist_id", "uris", "position"})
        return "POST", f"/api/v1/spotify/playlists/{_id(args, 'playlist_id')}/tracks", _uris(args)
    if name == "remove_playlist_tracks":
        _unknown(name, args, {"playlist_id", "uris"})
        return "POST", f"/api/v1/spotify/playlists/{_id(args, 'playlist_id')}/tracks/remove", {"uris": _uri_list(args)}
    if name == "reorder_playlist_tracks":
        _unknown(name, args, {"playlist_id", "range_start", "insert_before", "length"})
        body = {"range_start": _nonneg(args, "range_start"), "insert_before": _nonneg(args, "insert_before")}
        if "length" in args:
            body["length"] = _nonneg(args, "length")
        return "POST", f"/api/v1/spotify/playlists/{_id(args, 'playlist_id')}/tracks/reorder", body
    if name == "replace_playlist_tracks":
        _unknown(name, args, {"playlist_id", "uris"})
        return "POST", f"/api/v1/spotify/playlists/{_id(args, 'playlist_id')}/tracks/replace", {"uris": _uri_list(args)}
    if name == "discard_playlist_changes":
        _unknown(name, args, {"playlist_id"})
        if "playlist_id" in args and args["playlist_id"] is not None:
            return "POST", f"/api/v1/spotify/playlists/{_id(args, 'playlist_id')}/discard", {}
        return "POST", "/api/v1/spotify/discard", {}
    if name == "push_playlist_changes":
        _unknown(name, args, {"playlist_id"})
        return "POST", "/api/v1/spotify/push", _optional_playlist(args)
    if name == "sync_spotify":
        _unknown(name, args, {"playlist_id"})
        return "POST", "/api/v1/spotify/sync", _optional_playlist(args)
    if name == "add_spotify_prompt":
        _unknown(name, args, {"name", "prompt", "target_playlist_id", "refresh_schedule", "notes", "active"})
        return "POST", "/api/v1/spotify/prompts", _pick(args, ("name", "prompt", "target_playlist_id", "refresh_schedule", "notes", "active"))
    if name == "update_spotify_prompt":
        _unknown(name, args, {"prompt_id", "name", "prompt", "target_playlist_id", "refresh_schedule", "notes", "active"})
        prompt_id = _id(args, "prompt_id")
        return "POST", f"/api/v1/spotify/prompts/{prompt_id}/update", _pick(args, ("name", "prompt", "target_playlist_id", "refresh_schedule", "notes", "active"))
    if name == "remove_spotify_prompt":
        return "POST", f"/api/v1/spotify/prompts/{_id(args, 'prompt_id')}/remove", {}
    if name == "mark_prompt_run":
        return "POST", f"/api/v1/spotify/prompts/{_id(args, 'prompt_id')}/run", {}
    if name == "update_taste_profile":
        _unknown(name, args, {"profile"})
        profile = args.get("profile")
        if not isinstance(profile, (dict, str)):
            raise ValueError("profile must be an object or text.")
        return "POST", "/api/v1/spotify/taste", {"profile": profile}
    if name == "play":
        _unknown(name, args, {"context_uri", "uris", "playlist_id", "device_id", "offset"})
        return "POST", "/api/v1/spotify/playback/play", _pick(args, ("context_uri", "uris", "playlist_id", "device_id", "offset"))
    if name in {"pause", "next", "previous"}:
        _unknown(name, args, {"device_id"})
        return "POST", f"/api/v1/spotify/playback/{name}", _pick(args, ("device_id",))
    if name == "seek":
        _unknown(name, args, {"position_ms", "device_id"})
        body = {"position_ms": _nonneg(args, "position_ms")}
        body.update(_pick(args, ("device_id",)))
        return "POST", "/api/v1/spotify/playback/seek", body
    if name == "set_volume":
        _unknown(name, args, {"volume_percent", "device_id"})
        body = {"volume_percent": _nonneg(args, "volume_percent")}
        body.update(_pick(args, ("device_id",)))
        return "POST", "/api/v1/spotify/playback/volume", body
    if name == "set_shuffle":
        _unknown(name, args, {"state", "device_id"})
        if not isinstance(args.get("state"), bool):
            raise ValueError("state must be true or false.")
        return "POST", "/api/v1/spotify/playback/shuffle", _pick(args, ("state", "device_id"))
    if name == "set_repeat":
        _unknown(name, args, {"state", "device_id"})
        if args.get("state") not in {"off", "track", "context"}:
            raise ValueError("state must be off, track, or context.")
        return "POST", "/api/v1/spotify/playback/repeat", _pick(args, ("state", "device_id"))
    if name == "add_to_queue":
        _unknown(name, args, {"uri", "device_id"})
        uri = args.get("uri")
        if not isinstance(uri, str) or re.fullmatch(r"spotify:track:[A-Za-z0-9]+", uri) is None:
            raise ValueError("uri must be a spotify:track URI.")
        return "POST", "/api/v1/spotify/playback/queue", _pick(args, ("uri", "device_id"))
    if name == "transfer_playback":
        _unknown(name, args, {"device_id", "play"})
        device = args.get("device_id")
        if not isinstance(device, str) or not device:
            raise ValueError("device_id is required.")
        return "POST", "/api/v1/spotify/playback/transfer", _pick(args, ("device_id", "play"))
    return None


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")


def _unknown(name: str, args: dict[str, Any], allowed: set[str]) -> None:
    extra = set(args) - allowed
    if extra:
        raise ValueError(f"{name} does not accept {', '.join(sorted(extra))}.")


def _id(args: dict[str, Any], key: str) -> int:
    value = args.get(key)
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise ValueError(f"{key} must be an id.")
    return value


def _nonneg(args: dict[str, Any], key: str) -> int:
    value = args.get(key)
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        raise ValueError(f"{key} must be zero or greater.")
    return value


def _uri_list(args: dict[str, Any]) -> list[str]:
    uris = args.get("uris")
    if not isinstance(uris, list) or not uris:
        raise ValueError("uris must be a list of spotify:track URIs.")
    for uri in uris:
        if not isinstance(uri, str) or re.fullmatch(r"spotify:track:[A-Za-z0-9]+", uri) is None:
            raise ValueError("Each track must be a spotify:track URI.")
    return uris


def _uris(args: dict[str, Any]) -> dict[str, Any]:
    body: dict[str, Any] = {"uris": _uri_list(args)}
    if "position" in args and args["position"] is not None:
        body["position"] = _nonneg(args, "position")
    return body


def _optional_playlist(args: dict[str, Any]) -> dict[str, Any]:
    if "playlist_id" in args and args["playlist_id"] is not None:
        return {"playlist_id": _id(args, "playlist_id")}
    return {}


def _pick(args: dict[str, Any], keys: tuple[str, ...]) -> dict[str, Any]:
    return {key: args[key] for key in keys if key in args and args[key] is not None}


def _query(args: dict[str, Any], keys: tuple[str, ...]) -> str:
    query = {key: args[key] for key in keys if args.get(key) not in (None, "")}
    return ("?" + urlencode(query)) if query else ""
