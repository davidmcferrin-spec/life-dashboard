"""Pull and remove Ollama models without blocking deploy.

`defaults` writes the hardware plan, downloads the chat and embedding models
when they are missing, and records status under /var/lib/lifedash/ollama.
A second run exits while the first still holds the lock.
"""

from __future__ import annotations

import fcntl
import json
import os
import sys
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Callable

from .ollama_plan import BIND, detect, model_ok
from .ollama_tools import listen_public

DEFAULT_BASE = "http://" + BIND
Opener = Callable[..., Any]


def state_dir() -> Path:
    raw = os.environ.get("LIFEDASH_OLLAMA_DIR", "/var/lib/lifedash/ollama")
    return Path(raw)


def base_url() -> str:
    raw = os.environ.get("LIFEDASH_OLLAMA_BASE", DEFAULT_BASE).strip().rstrip("/")
    if raw.startswith(("http://127.0.0.1:", "http://localhost:", "http://[::1]:")):
        return raw
    return DEFAULT_BASE


def write_status(directory: Path, **fields: object) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    doc = {
        "state": fields.get("state", "idle"),
        "detail": fields.get("detail", ""),
        "accelerator": fields.get("accelerator", ""),
        "ram_mib": fields.get("ram_mib", 0),
        "vram_mib": fields.get("vram_mib", 0),
        "chat_model": fields.get("chat_model", ""),
        "embed_model": fields.get("embed_model", ""),
        "model": fields.get("model", ""),
        "listen": fields.get("listen", []),
        "updated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
    }
    path = directory / "status.json"
    tmp = directory / "status.json.tmp"
    tmp.write_text(json.dumps(doc) + "\n", encoding="utf-8")
    tmp.replace(path)


def write_plan(directory: Path, plan: dict[str, object]) -> None:
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / "plan.json"
    tmp = directory / "plan.json.tmp"
    tmp.write_text(json.dumps(plan) + "\n", encoding="utf-8")
    tmp.replace(path)


def _lock(directory: Path):
    directory.mkdir(parents=True, exist_ok=True)
    handle = (directory / "pull.lock").open("a", encoding="utf-8")
    try:
        fcntl.flock(handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        handle.close()
        return None
    return handle


def _log(message: str) -> None:
    print(f"ollama: {message}", flush=True)
    path = os.environ.get("LIFEDASH_OLLAMA_LOG", "")
    if not path:
        return
    try:
        with open(path, "a", encoding="utf-8") as handle:
            stamp = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
            handle.write(f"[{stamp}] {message}\n")
    except OSError:
        return


def tags(opener: Opener, base: str, timeout: float = 10) -> set[str]:
    req = urllib.request.Request(base + "/api/tags", method="GET", headers={"Accept": "application/json"})
    with opener(req, timeout=timeout) as res:
        body = json.loads(res.read().decode("utf-8"))
    names: set[str] = set()
    for item in body.get("models") or []:
        if not isinstance(item, dict):
            continue
        name = item.get("name") or item.get("model")
        if isinstance(name, str) and name:
            names.add(name)
    return names


def _present(opener: Opener, base: str, name: str) -> bool:
    try:
        installed = tags(opener, base)
    except (OSError, urllib.error.URLError, json.JSONDecodeError, TimeoutError, ValueError):
        return False
    return name in installed or any(item.startswith(name + ":") for item in installed)


def pull(opener: Opener, base: str, name: str) -> None:
    payload = json.dumps({"name": name, "stream": True}).encode("utf-8")
    req = urllib.request.Request(
        base + "/api/pull",
        data=payload,
        method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/x-ndjson"},
    )
    with opener(req, timeout=3600) as res:
        error = ""
        for raw in res:
            line = raw.decode("utf-8", errors="replace").strip()
            if not line:
                continue
            try:
                doc = json.loads(line)
            except json.JSONDecodeError:
                continue
            if doc.get("error"):
                error = str(doc["error"])
        if error:
            raise RuntimeError(error)


def remove(opener: Opener, base: str, name: str) -> None:
    payload = json.dumps({"name": name}).encode("utf-8")
    req = urllib.request.Request(
        base + "/api/delete",
        data=payload,
        method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json"},
    )
    with opener(req, timeout=60) as res:
        res.read()


def listen_hosts(text: str) -> list[str]:
    hosts: list[str] = []
    for line in text.splitlines():
        if "11434" not in line:
            continue
        for token in line.split():
            if token.endswith(":11434") or token.endswith("]:11434"):
                host = token.rsplit(":", 1)[0]
                if host.startswith("["):
                    host = host.strip("[]")
                hosts.append(host)
    return hosts


def _ss_text() -> str:
    import subprocess

    try:
        proc = subprocess.run(["ss", "-ltnH", "sport = :11434"], capture_output=True, text=True, timeout=3)
    except (OSError, subprocess.SubprocessError):
        return ""
    return proc.stdout or ""


def remember_chat(chat: str) -> None:
    """Fill an untouched chat model. A saved choice stays."""
    try:
        from .config import Config
        from .db import connect
    except Exception as exc:  # noqa: BLE001 — the pull still finishes if the DB is down
        _log(f"could not store the default chat model: {exc}")
        return
    try:
        cfg = Config.load()
        with connect(cfg, "lifedash-ollama") as conn:
            conn.execute(
                "UPDATE ollama_settings SET chat_model = %s, "
                "updated_at = (now() AT TIME ZONE 'UTC') WHERE id = 1 AND chat_model = ''",
                (chat,),
            )
    except Exception as exc:  # noqa: BLE001
        _log(f"could not store the default chat model: {exc}")


def run_defaults(opener: Opener | None = None, plan: dict[str, object] | None = None, *, store: bool = True) -> int:
    directory = state_dir()
    lock = _lock(directory)
    if lock is None:
        _log("a model pull is already running")
        return 0
    open_url = opener or urllib.request.urlopen
    chosen = plan or detect()
    chat = str(chosen["chat_model"])
    embed = str(chosen["embed_model"])
    hosts = listen_hosts(_ss_text()) if opener is None else []
    _log(
        f"accelerator {chosen['accelerator']} ram_mib={chosen['ram_mib']} "
        f"vram_mib={chosen['vram_mib']} chat={chat} embed={embed}"
    )
    write_plan(directory, chosen)
    if listen_public("\n".join(f"{host}:11434" for host in hosts)):
        write_status(directory, state="failed", detail="Ollama is listening outside this machine.", listen=hosts, **chosen)
        _log("Ollama is listening outside this machine.")
        return 1
    try:
        _ensure(open_url, base_url(), directory, chosen, [chat, embed])
        if store:
            remember_chat(chat)
    except Exception as exc:  # noqa: BLE001 — the status file is the UI
        write_status(
            directory,
            state="failed",
            detail=str(exc)[:180] or "The model pull failed.",
            listen=hosts,
            **chosen,
        )
        _log(f"The model pull failed: {exc}")
        return 1
    return 0


def run_named(kind: str, name: str, opener: Opener | None = None) -> int:
    if not model_ok(name):
        _log("model name is not valid")
        return 2
    directory = state_dir()
    lock = _lock(directory)
    if lock is None:
        _log("a model pull is already running")
        return 0
    open_url = opener or urllib.request.urlopen
    plan = _read_plan(directory)
    fields = {
        "accelerator": plan.get("accelerator", ""),
        "ram_mib": plan.get("ram_mib", 0),
        "vram_mib": plan.get("vram_mib", 0),
        "chat_model": plan.get("chat_model", ""),
        "embed_model": plan.get("embed_model", ""),
    }
    try:
        if kind == "remove":
            write_status(directory, state="pulling", detail=f"Removing {name}.", model=name, **fields)
            remove(open_url, base_url(), name)
            write_status(directory, state="ready", detail=f"Removed {name}.", model=name, **fields)
            _log(f"removed {name}")
            return 0
        write_status(directory, state="pulling", detail=f"Pulling {name}.", model=name, **fields)
        if not _present(open_url, base_url(), name):
            pull(open_url, base_url(), name)
        write_status(directory, state="ready", detail=f"{name} is installed.", model=name, **fields)
        _log(f"installed {name}")
        return 0
    except Exception as exc:  # noqa: BLE001
        write_status(directory, state="failed", detail=str(exc)[:180] or "The model pull failed.", model=name, **fields)
        _log(f"The model pull failed: {exc}")
        return 1


def _ensure(opener: Opener, base: str, directory: Path, plan: dict[str, object], names: list[str]) -> None:
    write_status(directory, state="pulling", detail="Pulling the default models.", model=names[0], **plan)
    for name in names:
        if _present(opener, base, name):
            _log(f"{name} is already installed")
            continue
        write_status(directory, state="pulling", detail=f"Pulling {name}.", model=name, **plan)
        _log(f"pulling {name}")
        pull(opener, base, name)
    write_status(directory, state="ready", detail="Ollama API answered.", **plan)


def _read_plan(directory: Path) -> dict[str, object]:
    path = directory / "plan.json"
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return data if isinstance(data, dict) else {}


def main(argv: list[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    cmd = args[0] if args else "defaults"
    if cmd == "plan":
        print(json.dumps(detect()))
        return 0
    if cmd == "defaults":
        return run_defaults()
    if cmd in ("pull", "remove") and len(args) == 2:
        return run_named(cmd, args[1])
    _log("use defaults, plan, pull MODEL, or remove MODEL")
    return 2


if __name__ == "__main__":
    sys.exit(main())
