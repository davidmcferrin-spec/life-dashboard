"""Dashboard setup values, with the environment as a fallback.

PHP stores the setup group in system_settings, encrypted with APP_SECRET
(the same AES-256-GCM blob as CredentialCrypto). A long-running process
re-reads it on a short cache so a saved form applies without a redeploy.
MCP bind is the exception: the listen socket is chosen once, at start.
"""

from __future__ import annotations

import json
import threading
import time
from typing import Callable

from .config import Config
from .spotify_crypto import key_bytes, open_blob

Reader = Callable[[], dict]


def prefer(stored: str | None, env: str | None) -> str | None:
    """A non-empty saved value wins. Otherwise the environment value, if any."""
    if stored is not None and stored.strip() != "":
        return stored.strip()
    if env is not None and env.strip() != "":
        return env.strip()
    return None


def read_setup(config: Config) -> dict:
    secret = config.maybe("APP_SECRET")
    if not secret:
        return {}
    from .db import connect

    conn = connect(config, "lifedash-runtime")
    try:
        row = conn.execute(
            "SELECT value_encrypted FROM system_settings WHERE setting_key = %s",
            ("setup",),
        ).fetchone()
    finally:
        conn.close()
    if row is None or not row[0]:
        return {}
    plain = open_blob(str(row[0]), key_bytes(secret))
    data = json.loads(plain)
    return data if isinstance(data, dict) else {}


class RuntimeSettings:
    def __init__(self, config: Config, reader: Reader, ttl: float = 2.0) -> None:
        self._config = config
        self._reader = reader
        self._ttl = ttl
        self._lock = threading.Lock()
        self._stored: dict = {}
        self._have = False
        self._at = 0.0

    def maybe(self, key: str) -> str | None:
        stored = self._lookup().get(key)
        text = stored.strip() if isinstance(stored, str) else None
        return prefer(text, self._config.maybe(key))

    def int(self, key: str, default: int) -> int:
        raw = self.maybe(key)
        if raw is None:
            return default
        try:
            return int(raw)
        except ValueError:
            return default

    def _lookup(self) -> dict:
        now = time.monotonic()
        with self._lock:
            if self._have and now - self._at < self._ttl:
                return self._stored
            try:
                stored = self._reader()
            except Exception:
                self._at = now
                if not self._have:
                    self._stored = {}
                    self._have = True
                return self._stored
            self._stored = stored if isinstance(stored, dict) else {}
            self._have = True
            self._at = now
            return self._stored
