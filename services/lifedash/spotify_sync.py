"""Pull Spotify into Postgres and push staged playlist edits.

Stdout is one JSON object. Tokens are never written to the log.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timedelta, timezone

from psycopg.rows import dict_row

from .config import Config
from .db import connect
from .log import setup
from .spotify_crypto import key_bytes, open_blob, seal
from .spotify_http import SpotifyHttp, SpotifyHttpError, scrub
from .spotify_plan import partition_playlists, plan_push, needs_refresh

GONE = "Spotify no longer has this playlist."


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="lifedash-spotify-sync")
    parser.add_argument("command", choices=["tick", "pull", "push"])
    parser.add_argument("--user", type=int)
    parser.add_argument("--playlist", type=int)
    args = parser.parse_args(argv)
    setup()
    try:
        result = execute(args.command, args.user, args.playlist)
    except SpotifyHttpError as exc:
        result = {"ok": False, "error": scrub(str(exc))}
    except Exception as exc:
        result = {"ok": False, "error": scrub(str(exc)) or "Spotify sync failed."}
    sys.stdout.write(json.dumps(result, default=str) + "\n")
    return 0 if result.get("ok") else 1


def execute(command: str, user_id: int | None, playlist_id: int | None) -> dict:
    from .runtime_config import RuntimeSettings, read_setup
    from pathlib import Path

    from .token_key import material

    cfg = Config.load()
    runtime = RuntimeSettings(cfg, lambda: read_setup(cfg))
    client_id = _usable(runtime.maybe("SPOTIFY_CLIENT_ID"))
    secret = _usable(runtime.maybe("SPOTIFY_CLIENT_SECRET"))
    if command == "tick" and (not client_id or not secret):
        return {"ok": True, "skipped": True, "reason": "Spotify is not configured."}
    if not client_id or not secret:
        return {"ok": False, "error": "Set the Spotify client id and secret on Settings → Setup before syncing."}
    minutes = runtime.int("SPOTIFY_SYNC_MINUTES", 10)
    conn = connect(cfg, application_name="lifedash-spotify")
    conn.row_factory = dict_row
    have_accounts = False
    try:
        have_accounts = conn.execute("SELECT 1 FROM spotify_account LIMIT 1").fetchone() is not None
    except Exception:
        have_accounts = False
    root = Path(__file__).resolve().parents[2]
    token_secret = material(
        root / "storage" / "keys" / "spotify-token.key",
        runtime.maybe("SPOTIFY_TOKEN_KEY"),
        cfg.maybe("APP_SECRET"),
        have_accounts,
    )
    worker = Worker(conn, SpotifyHttp(), key_bytes(token_secret), client_id, secret, minutes)
    try:
        if command == "tick":
            return worker.tick()
        if user_id is None or user_id < 1:
            return {"ok": False, "error": "Pass --user."}
        if command == "pull":
            counts = worker.pull(user_id, playlist_id)
        else:
            counts = worker.push(user_id, playlist_id)
        return {"ok": True, "user_id": user_id, **counts}
    finally:
        conn.close()


class Worker:
    def __init__(self, conn, http: SpotifyHttp, key: bytes, client_id: str, client_secret: str, minutes: int) -> None:
        self.conn = conn
        self.http = http
        self.key = key
        self.client_id = client_id
        self.client_secret = client_secret
        self.minutes = minutes

    def tick(self) -> dict:
        rows = self.conn.execute(
            "SELECT user_id, last_sync_at FROM spotify_account WHERE status = 'connected' ORDER BY user_id"
        ).fetchall()
        ran = []
        for row in rows:
            if not self._due(row["last_sync_at"]):
                continue
            user_id = int(row["user_id"])
            self._lock(user_id)
            try:
                pulled = self._pull(user_id, None)
                pushed = self._push(user_id, None)
                ran.append({"user_id": user_id, "pull": pulled, "push": pushed})
                self._note(f"sync user={user_id} refreshed={pulled.get('refreshed', 0)} pushed={pushed.get('pushed', 0)}")
            except SpotifyHttpError as exc:
                self._fail_account(user_id, exc)
                self._note(f"sync user={user_id} error={scrub(str(exc))}")
                ran.append({"user_id": user_id, "error": scrub(str(exc))})
            finally:
                self._unlock(user_id)
        return {"ok": True, "users": ran}

    def pull(self, user_id: int, playlist_id: int | None) -> dict:
        self._lock(user_id)
        try:
            counts = self._pull(user_id, playlist_id)
            self._note(f"pull user={user_id} playlist={playlist_id or 'all'} refreshed={counts.get('refreshed', 0)}")
            return counts
        except SpotifyHttpError as exc:
            self._fail_account(user_id, exc)
            raise
        finally:
            self._unlock(user_id)

    def push(self, user_id: int, playlist_id: int | None) -> dict:
        self._lock(user_id)
        try:
            counts = self._push(user_id, playlist_id)
            self._note(f"push user={user_id} playlist={playlist_id or 'all'} pushed={counts.get('pushed', 0)} failed={counts.get('failed', 0)}")
            return counts
        except SpotifyHttpError as exc:
            self._fail_account(user_id, exc)
            raise
        finally:
            self._unlock(user_id)

    def _pull(self, user_id: int, playlist_id: int | None) -> dict:
        log_id = self._start_log(user_id, "pull")
        try:
            if playlist_id is None:
                counts = self._pull_all(user_id)
            else:
                counts = self._pull_one(user_id, playlist_id)
            self._finish_log(log_id, "ok", counts, None)
            self._touch_account(user_id, "ok", None)
            return counts
        except SpotifyHttpError as exc:
            self._finish_log(log_id, "error", {}, scrub(str(exc)))
            raise

    def _pull_all(self, user_id: int) -> dict:
        remote = self._items(user_id, "/me/playlists?limit=50")
        local_rows = self.conn.execute(
            "SELECT spotify_id, snapshot_id FROM spotify_playlists WHERE user_id = %s AND spotify_id IS NOT NULL",
            (user_id,),
        ).fetchall()
        local = {row["spotify_id"]: row["snapshot_id"] or "" for row in local_rows}
        part = partition_playlists(local, remote)
        by_id = {item.get("id"): item for item in remote if item.get("id")}
        for sid in part["refresh"]:
            self._save_remote_playlist(user_id, by_id[sid])
        for sid in part["removed"]:
            self._drop_remote(user_id, sid)
        saved = self._liked(user_id)
        history = self._recent(user_id)
        top = self._top(user_id)
        return {
            "playlists": len(remote),
            "refreshed": len(part["refresh"]),
            "removed": len(part["removed"]),
            "saved": saved,
            "history": history,
            "top": top,
        }

    def _pull_one(self, user_id: int, playlist_id: int) -> dict:
        row = self.conn.execute(
            "SELECT id, spotify_id, snapshot_id FROM spotify_playlists WHERE user_id = %s AND id = %s",
            (user_id, playlist_id),
        ).fetchone()
        if row is None:
            raise SpotifyHttpError("Playlist not found.", 404)
        if not row["spotify_id"]:
            return {"refreshed": 0, "removed": 0}
        try:
            remote = self._api(user_id, "GET", f"/playlists/{row['spotify_id']}")
        except SpotifyHttpError as exc:
            if exc.status == 404:
                self._drop_remote(user_id, row["spotify_id"])
                return {"refreshed": 0, "removed": 1}
            raise
        if (remote.get("snapshot_id") or "") != (row["snapshot_id"] or ""):
            self._save_remote_playlist(user_id, remote)
            return {"refreshed": 1, "removed": 0}
        return {"refreshed": 0, "removed": 0}

    def _save_remote_playlist(self, user_id: int, remote: dict) -> int:
        sid = remote.get("id")
        if not sid:
            raise SpotifyHttpError("Spotify playlist had no id.", 502)
        owner = remote.get("owner") if isinstance(remote.get("owner"), dict) else {}
        images = remote.get("images") or []
        image = images[0].get("url") if images and isinstance(images[0], dict) else None
        total = (remote.get("tracks") or {}).get("total")
        existing = self.conn.execute(
            "SELECT id FROM spotify_playlists WHERE user_id = %s AND spotify_id = %s",
            (user_id, sid),
        ).fetchone()
        values = (
            remote.get("name") or "Playlist",
            remote.get("description") or "",
            1 if remote.get("public") else 0,
            1 if remote.get("collaborative") else 0,
            owner.get("id"),
            owner.get("display_name"),
            remote.get("snapshot_id"),
            int(total or 0),
            image,
        )
        if existing:
            playlist_id = int(existing["id"])
            self.conn.execute(
                """UPDATE spotify_playlists
                   SET name = %s, description = %s, is_public = %s, collaborative = %s,
                       owner_id = %s, owner_name = %s, snapshot_id = %s, track_count = %s,
                       image_url = %s, last_synced_at = (now() AT TIME ZONE 'UTC'),
                       updated_at = (now() AT TIME ZONE 'UTC')
                   WHERE id = %s""",
                (*values, playlist_id),
            )
        else:
            playlist_id = int(
                self.conn.execute(
                    """INSERT INTO spotify_playlists
                       (user_id, spotify_id, name, description, is_public, collaborative, owner_id, owner_name,
                        snapshot_id, track_count, image_url, last_synced_at)
                       VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, (now() AT TIME ZONE 'UTC'))
                       RETURNING id""",
                    (user_id, sid, *values),
                ).fetchone()["id"]
            )
        items = self._items(user_id, f"/playlists/{sid}/tracks?limit=100")
        self.conn.execute("DELETE FROM spotify_playlist_tracks WHERE playlist_id = %s", (playlist_id,))
        position = 0
        for item in items:
            track = item.get("track") if isinstance(item, dict) else None
            fields = track_fields(track)
            if fields is None:
                continue
            track_id = self._upsert_track(user_id, fields)
            added_by = (item.get("added_by") or {}).get("id") if isinstance(item.get("added_by"), dict) else None
            added_at = _stamp(item.get("added_at")) if item.get("added_at") else None
            self.conn.execute(
                """INSERT INTO spotify_playlist_tracks (user_id, playlist_id, track_id, position, added_at, added_by)
                   VALUES (%s, %s, %s, %s, %s, %s)""",
                (user_id, playlist_id, track_id, position, added_at, added_by),
            )
            position += 1
        self.conn.execute(
            "UPDATE spotify_playlists SET track_count = %s WHERE id = %s",
            (position, playlist_id),
        )
        return playlist_id

    def _drop_remote(self, user_id: int, spotify_id: str) -> None:
        row = self.conn.execute(
            "SELECT id FROM spotify_playlists WHERE user_id = %s AND spotify_id = %s",
            (user_id, spotify_id),
        ).fetchone()
        if row is None:
            return
        open_changes = self.conn.execute(
            "SELECT COUNT(*) AS n FROM spotify_playlist_changes WHERE playlist_id = %s AND status IN ('pending', 'failed')",
            (row["id"],),
        ).fetchone()["n"]
        if int(open_changes) > 0:
            self.conn.execute(
                """UPDATE spotify_playlist_changes
                   SET status = 'failed', error = %s, updated_at = (now() AT TIME ZONE 'UTC')
                   WHERE playlist_id = %s AND status IN ('pending', 'failed')""",
                (GONE, row["id"]),
            )
            return
        self.conn.execute("DELETE FROM spotify_playlists WHERE id = %s", (row["id"],))

    def _liked(self, user_id: int) -> int:
        newest = self.conn.execute(
            "SELECT MAX(added_at) AS added FROM spotify_saved_tracks WHERE user_id = %s",
            (user_id,),
        ).fetchone()["added"]
        url = "/me/tracks?limit=50"
        pages = 0
        stored = 0
        total = None
        seen: list[str] = []
        while url and pages < 20:
            data = self._api(user_id, "GET", url)
            total = data.get("total", total)
            stop = False
            for item in data.get("items") or []:
                added = _stamp(item.get("added_at")) if item.get("added_at") else None
                if newest is not None and added is not None and added <= _stamp(newest):
                    stop = True
                    break
                fields = track_fields(item.get("track"))
                if fields is None or added is None:
                    continue
                track_id = self._upsert_track(user_id, fields)
                self.conn.execute(
                    """INSERT INTO spotify_saved_tracks (user_id, track_id, added_at)
                       VALUES (%s, %s, %s)
                       ON CONFLICT (user_id, track_id) DO UPDATE SET added_at = EXCLUDED.added_at""",
                    (user_id, track_id, added),
                )
                seen.append(fields["spotify_id"])
                stored += 1
            pages += 1
            url = None if stop else data.get("next")
        if isinstance(total, int) and total <= 1000 and newest is None:
            self._prune_saved(user_id, seen)
        return stored

    def _prune_saved(self, user_id: int, spotify_ids: list[str]) -> None:
        rows = self.conn.execute(
            """SELECT s.id, t.spotify_id
               FROM spotify_saved_tracks s
               JOIN spotify_tracks t ON t.id = s.track_id
               WHERE s.user_id = %s""",
            (user_id,),
        ).fetchall()
        keep = set(spotify_ids)
        for row in rows:
            if row["spotify_id"] not in keep:
                self.conn.execute("DELETE FROM spotify_saved_tracks WHERE id = %s", (row["id"],))

    def _recent(self, user_id: int) -> int:
        data = self._api(user_id, "GET", "/me/player/recently-played", query={"limit": 50})
        added = 0
        for item in data.get("items") or []:
            fields = track_fields(item.get("track"))
            played = _stamp(item.get("played_at")) if item.get("played_at") else None
            if fields is None or played is None:
                continue
            track_id = self._upsert_track(user_id, fields)
            context = item.get("context") if isinstance(item.get("context"), dict) else {}
            cur = self.conn.execute(
                """INSERT INTO spotify_play_history (user_id, track_id, played_at, context_uri)
                   VALUES (%s, %s, %s, %s)
                   ON CONFLICT (user_id, track_id, played_at) DO NOTHING""",
                (user_id, track_id, played, context.get("uri")),
            )
            added += cur.rowcount
        return added

    def _top(self, user_id: int) -> int:
        row = self.conn.execute(
            "SELECT MIN(refreshed_at) AS refreshed FROM spotify_top_items WHERE user_id = %s",
            (user_id,),
        ).fetchone()
        refreshed = row["refreshed"] if row else None
        if refreshed is not None and not _older_than(refreshed, hours=20):
            return 0
        count = 0
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        for kind, endpoint in (("artist", "artists"), ("track", "tracks")):
            for time_range in ("short_term", "medium_term", "long_term"):
                data = self._api(
                    user_id,
                    "GET",
                    f"/me/top/{endpoint}",
                    query={"time_range": time_range, "limit": 20},
                )
                self.conn.execute(
                    "DELETE FROM spotify_top_items WHERE user_id = %s AND kind = %s AND time_range = %s",
                    (user_id, kind, time_range),
                )
                for rank, item in enumerate(data.get("items") or [], start=1):
                    if not isinstance(item, dict) or not item.get("id"):
                        continue
                    payload = {"uri": item.get("uri"), "image_url": _image(item)}
                    self.conn.execute(
                        """INSERT INTO spotify_top_items
                           (user_id, kind, time_range, rank, spotify_id, name, payload, refreshed_at)
                           VALUES (%s, %s, %s, %s, %s, %s, %s::jsonb, %s)""",
                        (user_id, kind, time_range, rank, item["id"], item.get("name") or "", json.dumps(payload), now),
                    )
                    count += 1
        return count

    def _push(self, user_id: int, playlist_id: int | None) -> dict:
        log_id = self._start_log(user_id, "push")
        sql = """SELECT DISTINCT p.id, p.spotify_id, p.snapshot_id
                 FROM spotify_playlists p
                 JOIN spotify_playlist_changes c ON c.playlist_id = p.id
                 WHERE p.user_id = %s AND c.status IN ('pending', 'failed')"""
        params: list = [user_id]
        if playlist_id is not None:
            sql += " AND p.id = %s"
            params.append(playlist_id)
        sql += " ORDER BY p.id"
        rows = self.conn.execute(sql, params).fetchall()
        pushed = 0
        failed = 0
        try:
            for row in rows:
                outcome = self._push_playlist(user_id, int(row["id"]))
                pushed += outcome["pushed"]
                failed += outcome["failed"]
            counts = {"pushed": pushed, "failed": failed}
            self._finish_log(log_id, "ok", counts, None)
            return counts
        except SpotifyHttpError as exc:
            self._finish_log(log_id, "error", {"pushed": pushed, "failed": failed}, scrub(str(exc)))
            raise

    def _push_playlist(self, user_id: int, playlist_id: int) -> dict:
        pushed = 0
        failed = 0
        for _ in range(20):
            row = self.conn.execute(
                "SELECT id, spotify_id, snapshot_id FROM spotify_playlists WHERE user_id = %s AND id = %s",
                (user_id, playlist_id),
            ).fetchone()
            if row is None:
                return {"pushed": pushed, "failed": failed}
            ops = self._ops(playlist_id)
            if not ops:
                self._mark_clean(playlist_id)
                return {"pushed": pushed, "failed": failed}
            spotify_id = row["spotify_id"]
            remote_uris = self._local_uris(playlist_id)
            if spotify_id:
                try:
                    remote = self._api(user_id, "GET", f"/playlists/{spotify_id}")
                except SpotifyHttpError as exc:
                    if exc.status == 404:
                        self._fail_ops(playlist_id, GONE)
                        return {"pushed": pushed, "failed": failed + len(ops)}
                    raise
                if (remote.get("snapshot_id") or "") != (row["snapshot_id"] or ""):
                    self._save_remote_playlist(user_id, remote)
                    remote_uris = self._local_uris(playlist_id)
            actions = plan_push(spotify_id, remote_uris, ops)
            created = self._apply_actions(user_id, playlist_id, actions)
            if created == "failed":
                failed += 1
                return {"pushed": pushed, "failed": failed}
            if created == "created":
                pushed += 1
                continue
            if created == "stop":
                return {"pushed": pushed + 1, "failed": failed}
            pushed += 1
            self._mark_clean(playlist_id)
            return {"pushed": pushed, "failed": failed}
        return {"pushed": pushed, "failed": failed}

    def _apply_actions(self, user_id: int, playlist_id: int, actions: list[dict]) -> str:
        """Apply one change (the first change's actions). Returns created, pushed, failed, or stop."""
        if not actions:
            return "pushed"
        first = actions[0]["change_id"]
        group = [action for action in actions if action["change_id"] == first]
        failure = next((action for action in group if action["kind"] == "fail"), None)
        if failure is not None:
            self._set_change(first, "failed", failure.get("error"))
            return "failed"
        requests = [action for action in group if action["kind"] == "request"]
        if not requests:
            self._set_change(first, "pushed", None)
            return "pushed"
        last: dict = {}
        try:
            for action in requests:
                try:
                    last = self._api(user_id, action["method"], action["path"], action.get("body"))
                except SpotifyHttpError as exc:
                    if exc.status == 404 and action["method"] == "DELETE":
                        last = {}
                    else:
                        raise
        except SpotifyHttpError as exc:
            self._set_change(first, "failed", scrub(str(exc)))
            return "failed"
        snapshot = last.get("snapshot_id")
        if isinstance(snapshot, str) and snapshot:
            self.conn.execute("UPDATE spotify_playlists SET snapshot_id = %s WHERE id = %s", (snapshot, playlist_id))
        if requests[0]["method"] == "POST" and requests[0]["path"] == "/me/playlists" and last.get("id"):
            self.conn.execute(
                """UPDATE spotify_playlists
                   SET spotify_id = %s, snapshot_id = %s, last_synced_at = (now() AT TIME ZONE 'UTC')
                   WHERE id = %s""",
                (last["id"], last.get("snapshot_id"), playlist_id),
            )
            self._set_change(first, "pushed", None)
            return "created"
        if requests[0]["method"] == "DELETE" and "/tracks" not in requests[0]["path"]:
            self.conn.execute("DELETE FROM spotify_playlists WHERE id = %s", (playlist_id,))
            return "stop"
        self._set_change(first, "pushed", None)
        remaining = [action for action in actions if action["change_id"] != first]
        if not remaining:
            return "pushed"
        return self._apply_actions(user_id, playlist_id, remaining)

    def _ops(self, playlist_id: int) -> list[dict]:
        rows = self.conn.execute(
            """SELECT id, op, payload FROM spotify_playlist_changes
               WHERE playlist_id = %s AND status IN ('pending', 'failed')
               ORDER BY id""",
            (playlist_id,),
        ).fetchall()
        ops = []
        for row in rows:
            payload = row["payload"]
            if isinstance(payload, str):
                payload = json.loads(payload)
            ops.append({"id": int(row["id"]), "op": row["op"], "payload": payload or {}})
        return ops

    def _local_uris(self, playlist_id: int) -> list[str]:
        rows = self.conn.execute(
            """SELECT t.spotify_id
               FROM spotify_playlist_tracks pt
               JOIN spotify_tracks t ON t.id = pt.track_id
               WHERE pt.playlist_id = %s
               ORDER BY pt.position, pt.id""",
            (playlist_id,),
        ).fetchall()
        return [f"spotify:track:{row['spotify_id']}" for row in rows]

    def _items(self, user_id: int, path: str) -> list[dict]:
        items: list[dict] = []
        url: str | None = path
        pages = 0
        while url and pages < 50:
            data = self._api(user_id, "GET", url)
            items.extend(item for item in data.get("items") or [] if isinstance(item, dict))
            url = data.get("next")
            pages += 1
        return items

    def _api(self, user_id: int, method: str, path: str, body: dict | None = None, query: dict | None = None) -> dict:
        token = self._token(user_id)
        try:
            return self.http.api(method, path, token, body, query)
        except SpotifyHttpError as exc:
            if not exc.reauth:
                raise
            token = self._token(user_id, force=True)
            return self.http.api(method, path, token, body, query)

    def _token(self, user_id: int, force: bool = False) -> str:
        with self.conn.transaction():
            self.conn.execute("SELECT pg_advisory_xact_lock(hashtext(%s)::bigint)", (f"spotify:{user_id}",))
            row = self.conn.execute(
                "SELECT access_token_enc, refresh_token_enc, expires_at, status FROM spotify_account WHERE user_id = %s",
                (user_id,),
            ).fetchone()
            if row is None:
                raise SpotifyHttpError("Spotify is not connected.", 404)
            if row["status"] == "needs_reauth":
                raise SpotifyHttpError("Spotify needs to be connected again.", 409, True)
            access = open_blob(row["access_token_enc"], self.key)
            if not force and not needs_refresh(row["expires_at"], int(datetime.now(timezone.utc).timestamp())):
                return access
            refresh = open_blob(row["refresh_token_enc"], self.key)
            try:
                tokens = self.http.token(
                    {"grant_type": "refresh_token", "refresh_token": refresh},
                    self.client_id,
                    self.client_secret,
                )
            except SpotifyHttpError as exc:
                if exc.reauth:
                    self._mark_reauth(user_id)
                raise SpotifyHttpError("Spotify needs to be connected again.", 409, True) from exc
            new_access = str(tokens.get("access_token") or "")
            if not new_access:
                self._mark_reauth(user_id)
                raise SpotifyHttpError("Spotify needs to be connected again.", 409, True)
            new_refresh = str(tokens.get("refresh_token") or refresh)
            expires = datetime.now(timezone.utc) + timedelta(seconds=int(tokens.get("expires_in") or 3600))
            self.conn.execute(
                """UPDATE spotify_account
                   SET access_token_enc = %s, refresh_token_enc = %s, expires_at = %s,
                       status = 'connected', status_detail = NULL, updated_at = (now() AT TIME ZONE 'UTC')
                   WHERE user_id = %s""",
                (seal(new_access, self.key), seal(new_refresh, self.key), expires.strftime("%Y-%m-%d %H:%M:%S"), user_id),
            )
            return new_access

    def _upsert_track(self, user_id: int, fields: dict) -> int:
        row = self.conn.execute(
            """INSERT INTO spotify_tracks
               (user_id, spotify_id, name, artists, album, duration_ms, explicit, popularity, isrc, image_url)
               VALUES (%s, %s, %s, %s::jsonb, %s, %s, %s, %s, %s, %s)
               ON CONFLICT (user_id, spotify_id) DO UPDATE SET
                 name = EXCLUDED.name, artists = EXCLUDED.artists, album = EXCLUDED.album,
                 duration_ms = EXCLUDED.duration_ms, explicit = EXCLUDED.explicit,
                 popularity = EXCLUDED.popularity, isrc = EXCLUDED.isrc, image_url = EXCLUDED.image_url,
                 updated_at = (now() AT TIME ZONE 'UTC')
               RETURNING id""",
            (
                user_id,
                fields["spotify_id"],
                fields["name"],
                fields["artists"],
                fields["album"],
                fields["duration_ms"],
                fields["explicit"],
                fields["popularity"],
                fields["isrc"],
                fields["image_url"],
            ),
        ).fetchone()
        return int(row["id"])

    def _set_change(self, change_id: int, status: str, error: str | None) -> None:
        self.conn.execute(
            """UPDATE spotify_playlist_changes
               SET status = %s, error = %s, updated_at = (now() AT TIME ZONE 'UTC')
               WHERE id = %s""",
            (status, error, change_id),
        )

    def _fail_ops(self, playlist_id: int, error: str) -> None:
        self.conn.execute(
            """UPDATE spotify_playlist_changes
               SET status = 'failed', error = %s, updated_at = (now() AT TIME ZONE 'UTC')
               WHERE playlist_id = %s AND status IN ('pending', 'failed')""",
            (error, playlist_id),
        )

    def _mark_clean(self, playlist_id: int) -> None:
        self.conn.execute(
            """UPDATE spotify_playlists SET local_dirty = 0, updated_at = (now() AT TIME ZONE 'UTC')
               WHERE id = %s AND NOT EXISTS (
                 SELECT 1 FROM spotify_playlist_changes c
                 WHERE c.playlist_id = spotify_playlists.id AND c.status IN ('pending', 'failed')
               )""",
            (playlist_id,),
        )

    def _mark_reauth(self, user_id: int) -> None:
        self.conn.execute(
            """UPDATE spotify_account
               SET status = 'needs_reauth', status_detail = 'Spotify needs to be connected again.',
                   updated_at = (now() AT TIME ZONE 'UTC')
               WHERE user_id = %s""",
            (user_id,),
        )

    def _fail_account(self, user_id: int, exc: SpotifyHttpError) -> None:
        if exc.reauth:
            self._mark_reauth(user_id)
        self.conn.execute(
            """UPDATE spotify_account
               SET last_sync_at = (now() AT TIME ZONE 'UTC'), last_sync_status = 'error', last_sync_error = %s,
                   updated_at = (now() AT TIME ZONE 'UTC')
               WHERE user_id = %s""",
            (scrub(str(exc))[:500], user_id),
        )

    def _touch_account(self, user_id: int, status: str, error: str | None) -> None:
        self.conn.execute(
            """UPDATE spotify_account
               SET last_sync_at = (now() AT TIME ZONE 'UTC'), last_sync_status = %s, last_sync_error = %s,
                   updated_at = (now() AT TIME ZONE 'UTC')
               WHERE user_id = %s""",
            (status, error, user_id),
        )

    def _start_log(self, user_id: int, kind: str) -> int:
        row = self.conn.execute(
            "INSERT INTO spotify_sync_log (user_id, kind, status) VALUES (%s, %s, 'running') RETURNING id",
            (user_id, kind),
        ).fetchone()
        return int(row["id"])

    def _finish_log(self, log_id: int, status: str, counts: dict, error: str | None) -> None:
        self.conn.execute(
            """UPDATE spotify_sync_log
               SET status = %s, counts = %s::jsonb, error = %s, finished_at = (now() AT TIME ZONE 'UTC')
               WHERE id = %s""",
            (status, json.dumps(counts), error, log_id),
        )

    def _due(self, last_sync) -> bool:
        if last_sync is None:
            return True
        moment = last_sync if isinstance(last_sync, datetime) else None
        if moment is None:
            return True
        if moment.tzinfo is None:
            moment = moment.replace(tzinfo=timezone.utc)
        return datetime.now(timezone.utc) - moment >= timedelta(minutes=self.minutes)

    def _lock(self, user_id: int) -> None:
        self.conn.execute("SELECT pg_advisory_lock(hashtext(%s)::bigint)", (f"spotify-sync:{user_id}",))

    def _unlock(self, user_id: int) -> None:
        self.conn.execute("SELECT pg_advisory_unlock(hashtext(%s)::bigint)", (f"spotify-sync:{user_id}",))

    def _note(self, message: str) -> None:
        from .config import REPO_ROOT

        stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        line = f"[{stamp}] {scrub(message)}"
        sys.stderr.write(line + "\n")
        path = REPO_ROOT / "storage" / "logs" / "spotify.log"
        try:
            path.parent.mkdir(parents=True, exist_ok=True)
            with path.open("a", encoding="utf-8") as handle:
                handle.write(line + "\n")
        except OSError:
            return


def track_fields(track: object) -> dict | None:
    if not isinstance(track, dict) or not track.get("id") or track.get("type") == "episode":
        return None
    artists = [
        {"id": artist.get("id"), "name": artist.get("name")}
        for artist in track.get("artists") or []
        if isinstance(artist, dict)
    ]
    album = track.get("album") if isinstance(track.get("album"), dict) else {}
    return {
        "spotify_id": track["id"],
        "name": track.get("name") or "",
        "artists": json.dumps(artists),
        "album": album.get("name"),
        "duration_ms": int(track.get("duration_ms") or 0),
        "explicit": 1 if track.get("explicit") else 0,
        "popularity": track.get("popularity"),
        "isrc": (track.get("external_ids") or {}).get("isrc") if isinstance(track.get("external_ids"), dict) else None,
        "image_url": _image(album) or _image(track),
    }


def _image(obj: dict) -> str | None:
    images = obj.get("images") if isinstance(obj, dict) else None
    if isinstance(images, list) and images and isinstance(images[0], dict):
        return images[0].get("url")
    return None


def _stamp(value: object) -> str:
    if isinstance(value, datetime):
        moment = value if value.tzinfo else value.replace(tzinfo=timezone.utc)
        return moment.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    text = str(value).replace("Z", "+00:00")
    moment = datetime.fromisoformat(text)
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=timezone.utc)
    return moment.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


def _older_than(value: datetime, hours: int) -> bool:
    moment = value if value.tzinfo else value.replace(tzinfo=timezone.utc)
    return datetime.now(timezone.utc) - moment >= timedelta(hours=hours)


def _usable(value: str | None) -> str | None:
    if value is None:
        return None
    value = value.strip()
    if value == "" or value.startswith("CHANGE_ME"):
        return None
    return value


if __name__ == "__main__":
    raise SystemExit(main())
