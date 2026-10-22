"""Pure Spotify sync decisions. The worker and the tests share this module."""

from __future__ import annotations

from datetime import datetime, timezone

BATCH = 100
REORDER_CONFLICT = (
    "Spotify changed this playlist and the reorder no longer matches. "
    "Discard it or reorder again."
)
NOT_CREATED = "Playlist has not been created on Spotify yet."


def partition_playlists(local: dict[str, str], remote: list[dict]) -> dict[str, list[str]]:
    """local maps spotify id to snapshot id. Drafts (no spotify id) are not included."""
    seen: set[str] = set()
    refresh: list[str] = []
    for item in remote:
        sid = str(item.get("id") or "")
        if not sid or sid in seen:
            continue
        seen.add(sid)
        snap = str(item.get("snapshot_id") or "")
        if sid not in local or local.get(sid) != snap:
            refresh.append(sid)
    removed = [sid for sid in local if sid not in seen]
    return {"refresh": refresh, "removed": removed}


def needs_refresh(expires_at: object, now: int, skew: int = 60) -> bool:
    if expires_at is None:
        return True
    if isinstance(expires_at, datetime):
        moment = expires_at if expires_at.tzinfo else expires_at.replace(tzinfo=timezone.utc)
        return int(moment.timestamp()) <= now + skew
    if isinstance(expires_at, (int, float)):
        return int(expires_at) <= now + skew
    text = str(expires_at).strip()
    if text == "":
        return True
    text = text.replace("T", " ")
    if text.endswith("Z"):
        text = text[:-1]
    if "+" in text[10:]:
        text = text.split("+", 1)[0]
    try:
        moment = datetime.strptime(text[:19], "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except ValueError:
        return True
    return int(moment.timestamp()) <= now + skew


def backoff_seconds(status: int, retry_after: int | None, attempt: int) -> int | None:
    if attempt >= 4:
        return None
    if status == 429:
        if retry_after is not None and retry_after >= 0:
            return min(int(retry_after), 60)
        return min(2**attempt, 30)
    if 500 <= status <= 599:
        return min(2**attempt, 30)
    return None


def new_plays(existing: set[tuple[str, str]], incoming: list[tuple[str, str]]) -> list[tuple[str, str]]:
    seen = set(existing)
    out: list[tuple[str, str]] = []
    for pair in incoming:
        if pair in seen:
            continue
        seen.add(pair)
        out.append(pair)
    return out


def plan_push(spotify_id: str | None, remote_uris: list[str], ops: list[dict]) -> list[dict]:
    """Turn pending ops into Spotify requests, skips, or failures.

    A create with no spotify id stops the plan so the caller can store the new
    id and plan the rest. Reorder fails when the URI list it was staged against
    is no longer the list we would send.
    """
    actions: list[dict] = []
    if not spotify_id:
        for op in ops:
            if op.get("op") == "create":
                payload = op.get("payload") or {}
                actions.append(
                    {
                        "change_id": op.get("id"),
                        "kind": "request",
                        "method": "POST",
                        "path": "/me/playlists",
                        "body": {
                            "name": payload.get("name") or "Playlist",
                            "description": payload.get("description") or "",
                            "public": bool(payload.get("public")),
                        },
                    }
                )
                return actions
            actions.append({"change_id": op.get("id"), "kind": "fail", "error": NOT_CREATED})
        return actions

    present = list(remote_uris)
    for op in ops:
        kind = str(op.get("op") or "")
        payload = op.get("payload") or {}
        change_id = op.get("id")
        if kind == "create":
            actions.append({"change_id": change_id, "kind": "skip"})
            continue
        if kind == "add":
            wanted = [uri for uri in _uris(payload) if uri not in present]
            if not wanted:
                actions.append({"change_id": change_id, "kind": "skip"})
                continue
            position = payload.get("position")
            for offset in range(0, len(wanted), BATCH):
                chunk = wanted[offset : offset + BATCH]
                body: dict = {"uris": chunk}
                if position is not None and offset == 0:
                    body["position"] = int(position)
                actions.append(_request(change_id, "POST", f"/playlists/{spotify_id}/tracks", body))
            if position is None:
                present.extend(wanted)
            else:
                at = max(0, min(int(position), len(present)))
                present[at:at] = wanted
            continue
        if kind == "remove":
            drop = [uri for uri in _uris(payload) if uri in present]
            if not drop:
                actions.append({"change_id": change_id, "kind": "skip"})
                continue
            unique = list(dict.fromkeys(drop))
            for offset in range(0, len(unique), BATCH):
                chunk = unique[offset : offset + BATCH]
                actions.append(
                    _request(
                        change_id,
                        "DELETE",
                        f"/playlists/{spotify_id}/tracks",
                        {"tracks": [{"uri": uri} for uri in chunk]},
                    )
                )
            gone = set(unique)
            present = [uri for uri in present if uri not in gone]
            continue
        if kind == "reorder":
            base = list(payload.get("base_uris") or [])
            if base != present:
                actions.append({"change_id": change_id, "kind": "fail", "error": REORDER_CONFLICT})
                return actions
            actions.append(
                _request(
                    change_id,
                    "PUT",
                    f"/playlists/{spotify_id}/tracks",
                    {
                        "range_start": int(payload.get("range_start") or 0),
                        "insert_before": int(payload.get("insert_before") or 0),
                        "range_length": int(payload.get("length") or 1),
                    },
                )
            )
            present = _apply_reorder(
                present,
                int(payload.get("range_start") or 0),
                int(payload.get("length") or 1),
                int(payload.get("insert_before") or 0),
            )
            continue
        if kind == "replace":
            uris = _uris(payload)
            first, rest = uris[:BATCH], uris[BATCH:]
            actions.append(_request(change_id, "PUT", f"/playlists/{spotify_id}/tracks", {"uris": first}))
            for offset in range(0, len(rest), BATCH):
                actions.append(
                    _request(
                        change_id,
                        "POST",
                        f"/playlists/{spotify_id}/tracks",
                        {"uris": rest[offset : offset + BATCH]},
                    )
                )
            present = uris
            continue
        if kind == "rename":
            body = {}
            if "name" in payload:
                body["name"] = payload["name"]
            if "public" in payload:
                body["public"] = bool(payload["public"])
            if not body:
                actions.append({"change_id": change_id, "kind": "skip"})
            else:
                actions.append(_request(change_id, "PUT", f"/playlists/{spotify_id}", body))
            continue
        if kind == "describe":
            actions.append(
                _request(
                    change_id,
                    "PUT",
                    f"/playlists/{spotify_id}",
                    {"description": payload.get("description") or ""},
                )
            )
            continue
        if kind == "delete":
            actions.append(_request(change_id, "DELETE", f"/playlists/{spotify_id}", None))
            return actions
        actions.append({"change_id": change_id, "kind": "fail", "error": f"Unknown playlist change '{kind}'."})
        return actions
    return actions


def _uris(payload: dict) -> list[str]:
    return [str(uri) for uri in payload.get("uris") or []]


def _request(change_id: object, method: str, path: str, body: dict | None) -> dict:
    return {"change_id": change_id, "kind": "request", "method": method, "path": path, "body": body}


def _apply_reorder(uris: list[str], start: int, length: int, before: int) -> list[str]:
    items = list(uris)
    if start < 0 or length < 1 or start + length > len(items):
        return items
    moved = items[start : start + length]
    del items[start : start + length]
    if before > start:
        before -= length
    before = max(0, min(before, len(items)))
    return items[:before] + moved + items[before:]
