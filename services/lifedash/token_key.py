"""Spotify token key file, outside the web root. Same rules as PHP TokenKey."""

from __future__ import annotations

import os
import secrets
from pathlib import Path

from .config import Config


def usable(value: str | None) -> str | None:
    if value is None:
        return None
    text = value.strip()
    if text == "" or text.startswith("CHANGE_ME"):
        return None
    return text


def material(path: Path, explicit: str | None, app_secret: str | None, accounts_exist: bool) -> str:
    existing = _read(path)
    if existing is not None:
        return existing
    chosen = usable(explicit)
    if chosen is None and accounts_exist:
        chosen = usable(app_secret)
    if chosen is None:
        chosen = secrets.token_hex(32)
    _write_new(path, chosen)
    return _read(path) or chosen


def material_from(config: Config, path: Path | None = None, accounts_exist: bool = False) -> str:
    root = Path(__file__).resolve().parents[2]
    target = path or (root / "storage" / "keys" / "spotify-token.key")
    return material(
        target,
        config.maybe("SPOTIFY_TOKEN_KEY"),
        config.maybe("APP_SECRET"),
        accounts_exist,
    )


def _read(path: Path) -> str | None:
    try:
        text = path.read_text(encoding="utf-8").strip()
    except OSError:
        return None
    if text == "" or "\n" in text or len(text) > 500:
        return None
    return text


def _write_new(path: Path, secret: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    flags = os.O_CREAT | os.O_EXCL | os.O_WRONLY
    try:
        fd = os.open(path, flags, 0o600)
    except FileExistsError:
        return
    try:
        os.write(fd, (secret + "\n").encode("utf-8"))
    finally:
        os.close(fd)
