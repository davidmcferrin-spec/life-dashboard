"""Fetch enabled digest sources and store new or edited entries.

One source that is blocked, empty, or unreadable is marked needs_attention.
The rest of the run continues.
"""

from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from .config import REPO_ROOT
from .digest_brief import BRIEF_BUDGET, OllamaAsk, chat_target, plan_briefs
from .digest_http import FetchResult, UrlRejected, fetch_with_backoff, urllib_transport
from .digest_brief import excerpt
from .digest_parse import content_hash, date_phrases, peel_trailing_date, plan_changes, read_page, snapshot_text, _prefer_date

SEED_FLAG = "defaults_seeded_digest"
RETENTION_KEY = "digest_snapshot_days"
DEFAULT_SNAPSHOT_DAYS = 180
Fetch = Callable[[str, str | None, str | None], FetchResult]


def load_seeds() -> list[dict[str, str]]:
    path = Path(__file__).with_name("digest_seeds.json")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, list):
        return []
    seeds = []
    for row in data:
        if not isinstance(row, dict):
            continue
        product = str(row.get("product") or "")
        label = str(row.get("label") or "")
        url = str(row.get("url") or "")
        if product and label and url:
            seeds.append({"product": product, "label": label, "url": url})
    return seeds


def repair_stored(
    title: str,
    published_on: str | None,
    summary: str,
    brief: str,
    brief_kind: str,
) -> dict[str, str | None] | None:
    """Pull a glued date out of a stored note and rebuild the brief when it copied that date."""
    cleaned, found, token = peel_trailing_date(summary)
    published = _prefer_date(published_on if published_on else None, found)
    brief_cleaned, brief_found, brief_token = peel_trailing_date(brief)
    if brief_found:
        published = _prefer_date(published, brief_found)
    phrases = [token, brief_token]
    if found:
        phrases.extend(date_phrases(found))
    if brief_found:
        phrases.extend(date_phrases(brief_found))
    copied = any(phrase and phrase.casefold() in brief.casefold() for phrase in phrases)
    new_brief = brief
    new_kind = brief_kind
    if copied:
        new_brief = excerpt(cleaned or brief_cleaned)
        new_kind = "excerpt" if new_brief else ""
    same_summary = cleaned == summary
    same_date = (published or None) == (published_on or None)
    if same_summary and same_date and new_brief == brief and new_kind == brief_kind:
        return None
    return {
        "summary": cleaned,
        "published_on": published,
        "brief": new_brief,
        "brief_kind": new_kind,
        "content_hash": content_hash(f"{title}\n{published or ''}\n{cleaned}"),
    }


def run_source(source: dict[str, Any], fetch: Fetch) -> dict[str, Any]:
    """Decide the source row and entry inserts/updates. fetch raises nothing."""
    url = str(source.get("url") or "")
    try:
        page = fetch(url, source.get("etag"), source.get("last_modified"))
    except UrlRejected as exc:
        page = FetchResult(error=str(exc))
    except TimeoutError:
        page = FetchResult(error="The request timed out.")
    except OSError:
        page = FetchResult(error="The host could not be reached.")
    except Exception:
        page = FetchResult(error="The fetch failed.")
    if page.error and page.status == 0:
        return _attention(source, page.error, page)
    if page.status == 304:
        return {
            "status": "ok",
            "error": None,
            "parse_mode": source.get("parse_mode"),
            "content_hash": source.get("content_hash"),
            "etag": page.header("ETag") or source.get("etag"),
            "last_modified": page.header("Last-Modified") or source.get("last_modified"),
            "ops": [],
        }

    def fetch_feed(feed_url: str) -> dict[str, Any]:
        try:
            result = fetch(feed_url, None, None)
        except Exception:
            return {"error": "The feed could not be fetched.", "status": 0}
        return {"status": result.status, "body": result.body, "content_type": result.content_type, "error": result.error}

    parsed = read_page(page.status, page.body, page.content_type, url, source.get("content_hash"), fetch_feed)
    existing = source.get("entries") if isinstance(source.get("entries"), dict) else {}
    ops = plan_changes({str(key): str(value) for key, value in existing.items()}, parsed["entries"]) if parsed["status"] == "ok" else []
    mode = parsed["parse_mode"] if parsed["parse_mode"] else source.get("parse_mode")
    result: dict[str, Any] = {
        "status": parsed["status"],
        "error": parsed["error"],
        "parse_mode": mode,
        "content_hash": parsed["content_hash"],
        "etag": page.header("ETag") or source.get("etag"),
        "last_modified": page.header("Last-Modified") or source.get("last_modified"),
        "ops": ops,
    }
    if parsed["status"] == "ok":
        text = snapshot_text(parsed["entries"])
        if text:
            result["snapshot"] = text
    return result


def _attention(source: dict[str, Any], error: str, page: FetchResult) -> dict[str, Any]:
    return {
        "status": "needs_attention",
        "error": error,
        "parse_mode": source.get("parse_mode"),
        "content_hash": source.get("content_hash"),
        "etag": source.get("etag"),
        "last_modified": source.get("last_modified"),
        "ops": [],
    }


class DigestSync:
    def __init__(
        self,
        conn: Any,
        fetch: Fetch | None = None,
        sleep: Callable[[float], None] | None = None,
        ask: OllamaAsk | None = None,
    ) -> None:
        self.conn = conn
        self._transport = fetch
        self._sleep = sleep or (lambda seconds: __import__("time").sleep(seconds))
        self._ask = ask
        self._brief_left = BRIEF_BUDGET

    def tick(self) -> int:
        checked = 0
        for user_id in self._users():
            self._lock(user_id)
            try:
                self._seed(user_id)
                self._repair(user_id)
                for source_id in self._enabled(user_id):
                    self._check_source(source_id)
                    checked += 1
                self._prune_snapshots(user_id)
            finally:
                self._unlock(user_id)
        return checked

    def check_user(self, user_id: int) -> int:
        self._lock(user_id)
        try:
            self._seed(user_id)
            self._repair(user_id)
            ids = self._enabled(user_id)
            for source_id in ids:
                self._check_source(source_id)
            self._prune_snapshots(user_id)
            return len(ids)
        finally:
            self._unlock(user_id)

    def _users(self) -> list[int]:
        rows = self.conn.execute("SELECT id FROM users ORDER BY id").fetchall()
        return [int(row[0]) for row in rows]

    def _enabled(self, user_id: int) -> list[int]:
        rows = self.conn.execute(
            "SELECT id FROM digest_sources WHERE user_id = %s AND enabled = 1 ORDER BY id",
            (user_id,),
        ).fetchall()
        return [int(row[0]) for row in rows]

    def _seed(self, user_id: int) -> None:
        row = self.conn.execute(
            "SELECT pref_value FROM user_preferences WHERE user_id = %s AND pref_key = %s",
            (user_id, SEED_FLAG),
        ).fetchone()
        if row is not None:
            return
        for seed in load_seeds():
            self.conn.execute(
                """
                INSERT INTO digest_sources (user_id, product, label, url)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (user_id, url) DO NOTHING
                """,
                (user_id, seed["product"], seed["label"], seed["url"]),
            )
        self.conn.execute(
            """
            INSERT INTO user_preferences (user_id, pref_key, pref_value)
            VALUES (%s, %s, '1')
            ON CONFLICT (user_id, pref_key) DO NOTHING
            """,
            (user_id, SEED_FLAG),
        )

    def _repair(self, user_id: int) -> None:
        rows = self.conn.execute(
            """
            SELECT id, title, published_on, summary, brief, brief_kind, content_hash
            FROM digest_entries WHERE user_id = %s
            """,
            (user_id,),
        ).fetchall()
        for row in rows:
            published = None if row[2] in (None, "") else str(row[2])[:10]
            fixed = repair_stored(str(row[1] or ""), published, str(row[3] or ""), str(row[4] or ""), str(row[5] or ""))
            if fixed is None:
                continue
            self.conn.execute(
                """
                UPDATE digest_entries
                SET summary = %s, published_on = %s, brief = %s, brief_kind = %s, content_hash = %s
                WHERE id = %s AND content_hash = %s
                """,
                (
                    fixed["summary"], fixed["published_on"], fixed["brief"], fixed["brief_kind"], fixed["content_hash"],
                    int(row[0]), str(row[6]),
                ),
            )

    def _check_source(self, source_id: int) -> None:
        row = self.conn.execute(
            """
            SELECT id, user_id, label, url, etag, last_modified, parse_mode, content_hash
            FROM digest_sources WHERE id = %s AND enabled = 1
            """,
            (source_id,),
        ).fetchone()
        if row is None:
            return
        entries = {
            str(item[0]): str(item[1])
            for item in self.conn.execute(
                "SELECT entry_key, content_hash FROM digest_entries WHERE source_id = %s",
                (source_id,),
            ).fetchall()
        }
        source = {
            "url": row[3],
            "etag": row[4],
            "last_modified": row[5],
            "parse_mode": row[6],
            "content_hash": row[7],
            "entries": entries,
        }
        try:
            result = run_source(source, self._fetch)
        except Exception:
            result = _attention(source, "The fetch failed.", FetchResult())
        self._store(int(row[0]), int(row[1]), str(row[2]), result)

    def _fetch(self, url: str, etag: str | None, modified: str | None) -> FetchResult:
        if self._transport is not None:
            return self._transport(url, etag, modified)
        return fetch_with_backoff(urllib_transport, url, etag, modified, self._sleep)

    def _store(self, source_id: int, user_id: int, label: str, result: dict[str, Any]) -> None:
        for op in result["ops"]:
            entry = op["entry"]
            if op["op"] == "insert":
                self.conn.execute(
                    """
                    INSERT INTO digest_entries
                        (user_id, source_id, entry_key, title, published_on, url, summary, content_hash, version_text, version_sort)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    ON CONFLICT (source_id, entry_key) DO NOTHING
                    """,
                    (
                        user_id, source_id, entry["entry_key"], entry["title"], entry["published_on"],
                        entry["url"], entry["summary"], entry["content_hash"],
                        entry.get("version_text"), entry.get("version_sort"),
                    ),
                )
            else:
                self.conn.execute(
                    """
                    UPDATE digest_entries
                    SET title = %s, published_on = %s, url = %s, summary = %s,
                        content_hash = %s, version_text = %s, version_sort = %s,
                        edited_at = (now() AT TIME ZONE 'UTC'),
                        brief = '', brief_kind = ''
                    WHERE source_id = %s AND entry_key = %s AND content_hash <> %s
                    """,
                    (
                        entry["title"], entry["published_on"], entry["url"], entry["summary"],
                        entry["content_hash"], entry.get("version_text"), entry.get("version_sort"),
                        source_id, entry["entry_key"], entry["content_hash"],
                    ),
                )
        snapshot = result.get("snapshot")
        if isinstance(snapshot, str) and snapshot.strip():
            self._save_snapshot(user_id, source_id, snapshot)
        self.conn.execute(
            """
            UPDATE digest_sources
            SET etag = %s, last_modified = %s, last_checked_at = (now() AT TIME ZONE 'UTC'),
                status = %s, last_error = %s, parse_mode = %s, content_hash = %s
            WHERE id = %s
            """,
            (
                result.get("etag"), result.get("last_modified"), result["status"], result.get("error"),
                result.get("parse_mode"), result.get("content_hash"), source_id,
            ),
        )
        detail = result.get("error") or result["status"]
        mode = result.get("parse_mode") or ""
        self._note(f"{label}: {detail}" + (f" ({mode}, {len(result['ops'])} changes)" if result["status"] == "ok" else ""))
        self._fill_briefs(source_id)

    def _fill_briefs(self, source_id: int) -> None:
        rows = self.conn.execute(
            """
            SELECT id, title, summary FROM digest_entries
            WHERE source_id = %s AND brief_kind = ''
            ORDER BY id
            """,
            (source_id,),
        ).fetchall()
        if not rows:
            return
        if self._ask is None:
            self._ask = self._ollama_ask()
        updates, self._brief_left = plan_briefs(list(rows), self._ask, self._brief_left)
        for entry_id, text, kind in updates:
            self.conn.execute(
                """
                UPDATE digest_entries
                SET brief = %s, brief_kind = %s
                WHERE id = %s AND brief_kind = ''
                """,
                (text, kind, entry_id),
            )

    def _ollama_ask(self) -> OllamaAsk:
        row = self.conn.execute(
            "SELECT base_url, chat_model FROM ollama_settings WHERE id = 1"
        ).fetchone()
        base_url = str(row[0]) if row else ""
        chat_model = str(row[1]) if row else ""
        base, model = chat_target(base_url, chat_model, _plan_chat())
        return OllamaAsk(base, model)

    def _save_snapshot(self, user_id: int, source_id: int, text: str) -> None:
        body = text[:200_000]
        digest = content_hash(body)
        latest = self.conn.execute(
            "SELECT content_hash FROM digest_snapshots WHERE source_id = %s ORDER BY captured_on DESC, id DESC LIMIT 1",
            (source_id,),
        ).fetchone()
        if latest is not None and str(latest[0]) == digest:
            return
        self.conn.execute(
            """
            INSERT INTO digest_snapshots (user_id, source_id, captured_on, content_hash, body)
            VALUES (%s, %s, CAST((now() AT TIME ZONE 'UTC') AS date), %s, %s)
            ON CONFLICT (source_id, captured_on) DO UPDATE
            SET content_hash = EXCLUDED.content_hash, body = EXCLUDED.body
            WHERE digest_snapshots.content_hash <> EXCLUDED.content_hash
            """,
            (user_id, source_id, digest, body),
        )

    def _prune_snapshots(self, user_id: int) -> None:
        self.conn.execute(
            """
            DELETE FROM digest_snapshots
            WHERE user_id = %s
              AND captured_on < (CAST((now() AT TIME ZONE 'UTC') AS date) - %s)
            """,
            (user_id, self._retention_days(user_id)),
        )

    def _retention_days(self, user_id: int) -> int:
        row = self.conn.execute(
            "SELECT pref_value FROM user_preferences WHERE user_id = %s AND pref_key = %s",
            (user_id, RETENTION_KEY),
        ).fetchone()
        if row is None or not str(row[0]).isdigit():
            return DEFAULT_SNAPSHOT_DAYS
        days = int(row[0])
        if days < 1 or days > 3650:
            return DEFAULT_SNAPSHOT_DAYS
        return days

    def _lock(self, user_id: int) -> None:
        self.conn.execute("SELECT pg_advisory_lock(81021, %s)", (user_id,))

    def _unlock(self, user_id: int) -> None:
        self.conn.execute("SELECT pg_advisory_unlock(81021, %s)", (user_id,))

    def _note(self, message: str) -> None:
        stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
        line = f"[{stamp}] {message}"
        sys.stderr.write(line + "\n")
        path = REPO_ROOT / "storage" / "logs" / "digest.log"
        try:
            path.parent.mkdir(parents=True, exist_ok=True)
            with path.open("a", encoding="utf-8") as handle:
                handle.write(line + "\n")
        except OSError:
            return


def _plan_chat() -> str:
    from .ollama_models import state_dir

    path = state_dir() / "plan.json"
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return ""
    model = data.get("chat_model") if isinstance(data, dict) else ""
    return model if isinstance(model, str) else ""


def main(argv: list[str] | None = None) -> None:
    from .config import Config
    from .db import connect

    args = list(sys.argv[1:] if argv is None else argv)
    command = args[0] if args else "tick"
    conn = connect(Config.load(), application_name="lifedash-digest")
    sync = DigestSync(conn)
    try:
        if command == "tick":
            count = sync.tick()
        elif command == "check":
            if len(args) < 2 or not args[1].isdigit() or int(args[1]) < 1:
                raise SystemExit(2)
            count = sync.check_user(int(args[1]))
        else:
            raise SystemExit(2)
    finally:
        conn.close()
    sync_note = f"checked {count} sources"
    sys.stderr.write(sync_note + "\n")


if __name__ == "__main__":
    main()
