#!/usr/bin/env python3
"""Read or record a deploy spool file.

The root unit is the only caller. This program does not fetch, merge, or run a shell.
"""
from __future__ import annotations

import json
import os
import re
import sys
from datetime import datetime, timezone

SHA = re.compile(r"[0-9a-f]{40}")
JOB = re.compile(r"[0-9a-f]{32}")
STAMP = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z")
NAME = re.compile(r"[^A-Za-z0-9 ._-]")
STATES = {"queued", "running", "succeeded", "failed", "rejected"}
LIMITED = {"queued", "running", "succeeded", "failed"}


def utcnow() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def clean_name(value: object) -> str:
    if not isinstance(value, str):
        return ""
    return NAME.sub("", value)[:64]


def whole_int(value: object, positive: bool) -> str:
    if isinstance(value, bool) or not isinstance(value, int):
        return ""
    if positive and value < 1:
        return ""
    if value < 0 or value > 999_999_999_999:
        return ""
    return str(value)


def token_field(data: dict) -> str:
    """Null or a missing token id is an admin session. Any other shape is refused."""
    if "token_id" not in data or data["token_id"] is None:
        return ""
    value = data["token_id"]
    if isinstance(value, bool) or not isinstance(value, int) or value < 1 or value > 999_999_999_999:
        raise SystemExit(2)
    return str(value)


def cmd_read(path: str) -> None:
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise SystemExit(2)
    sha = data.get("confirm_sha")
    job = data.get("job_id")
    if not isinstance(sha, str) or SHA.fullmatch(sha) is None:
        raise SystemExit(2)
    if not isinstance(job, str) or JOB.fullmatch(job) is None:
        raise SystemExit(2)
    token = token_field(data)
    user = whole_int(data.get("user_id"), positive=True)
    name = clean_name(data.get("token_name"))
    sys.stdout.write(f"{sha}\n{job}\n{token}\n{user}\n{name}\n")


def cmd_rate(path: str, window: str) -> None:
    seconds = int(window)
    if seconds < 1 or not os.path.isfile(path):
        raise SystemExit(0)
    with open(path, encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        raise SystemExit(0)
    if data.get("state") not in LIMITED:
        raise SystemExit(0)
    started = data.get("started_at")
    if not isinstance(started, str) or STAMP.fullmatch(started) is None:
        raise SystemExit(0)
    then = datetime.strptime(started, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    if (datetime.now(timezone.utc) - then).total_seconds() < seconds:
        raise SystemExit(3)


def atomic_write(path: str, payload: dict) -> None:
    parent = os.path.dirname(path)
    tmp = os.path.join(parent, "." + os.path.basename(path) + ".tmp")
    with open(tmp, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, separators=(",", ":"))
        handle.write("\n")
    os.chmod(tmp, 0o640)
    os.replace(tmp, path)


def cmd_write(argv: list[str]) -> None:
    if len(argv) != 10:
        raise SystemExit(2)
    job_path, last_path, state, job, sha, token, user, name, summary, update_last = argv
    if state not in STATES or JOB.fullmatch(job) is None or SHA.fullmatch(sha) is None:
        raise SystemExit(2)
    if update_last not in ("0", "1"):
        raise SystemExit(2)
    token_id = int(token) if token.isdigit() else None
    user_id = int(user) if user.isdigit() and int(user) > 0 else None
    now = utcnow()
    record = {
        "job_id": job,
        "confirm_sha": sha,
        "state": state,
        "started_at": now,
        "finished_at": now if state in ("succeeded", "failed", "rejected") else None,
        "token_id": token_id,
        "token_name": clean_name(name),
        "user_id": user_id,
        "summary": summary[:200],
    }
    if os.path.isfile(job_path):
        try:
            with open(job_path, encoding="utf-8") as handle:
                previous = json.load(handle)
            started = previous.get("started_at") if isinstance(previous, dict) else None
            if isinstance(started, str) and STAMP.fullmatch(started):
                record["started_at"] = started
        except (OSError, json.JSONDecodeError):
            pass
    atomic_write(job_path, record)
    if update_last == "1":
        atomic_write(last_path, record)


def main() -> None:
    if len(sys.argv) < 2:
        raise SystemExit(2)
    command = sys.argv[1]
    if command == "read" and len(sys.argv) == 3:
        cmd_read(sys.argv[2])
        return
    if command == "rate" and len(sys.argv) == 4:
        cmd_rate(sys.argv[2], sys.argv[3])
        return
    if command == "write":
        cmd_write(sys.argv[2:])
        return
    raise SystemExit(2)


if __name__ == "__main__":
    main()
