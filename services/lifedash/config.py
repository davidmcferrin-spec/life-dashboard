"""Configuration shared with the PHP app.

Parsing mirrors ``src/Config.php`` exactly so both runtimes see the same values:

* blank lines and lines starting with ``#`` are skipped
* split on the first ``=``; key and value are trimmed
* an inline comment is anything after `` #`` (space-hash) in the value
* one pair of matching surrounding quotes (single or double) is stripped
* the file wins over process environment variables; the environment is only a fallback
  for keys the file does not define (same precedence as PHP's Config::get)

Lookup order for the file: ``$LIFEDASH_ENV_FILE`` → ``/run/lifedash/app.env``
(decrypted by lifedash-secrets.service) → ``<repo>/.env``.
"""
from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping

REPO_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_ENV_FILES = (Path("/run/lifedash/app.env"), REPO_ROOT / ".env")

_TRUE = {"1", "true", "yes", "on"}


class ConfigError(RuntimeError):
    """Missing or invalid configuration."""


def parse_env_text(text: str) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        key, sep, value = line.partition("=")
        if not sep:
            continue
        key, value = key.strip(), value.strip()
        comment = value.find(" #")
        if comment != -1:
            value = value[:comment].strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
            value = value[1:-1]
        values[key] = value
    return values


def find_env_file(environ: Mapping[str, str] | None = None) -> Path | None:
    environ = os.environ if environ is None else environ
    explicit = environ.get("LIFEDASH_ENV_FILE")
    if explicit:
        path = Path(explicit)
        if not path.is_file():
            raise ConfigError(f"LIFEDASH_ENV_FILE points to a missing file: {path}")
        return path
    for path in DEFAULT_ENV_FILES:
        if path.is_file():
            return path
    return None


@dataclass(frozen=True)
class Config:
    values: Mapping[str, str]
    source: Path | None = None

    @classmethod
    def load(cls, environ: Mapping[str, str] | None = None, env_file: Path | None = None) -> "Config":
        environ = dict(os.environ if environ is None else environ)
        path = env_file if env_file is not None else find_env_file(environ)
        file_values = parse_env_text(path.read_text(encoding="utf-8")) if path else {}
        merged = {**environ, **file_values}
        return cls(values=merged, source=path)

    def get(self, key: str, default: str | None = None) -> str:
        value = self.values.get(key)
        if value is None or value == "":
            if default is not None:
                return default
            raise ConfigError(f"Required config key '{key}' is not set")
        return value

    def maybe(self, key: str) -> str | None:
        value = self.values.get(key)
        return value if value not in (None, "") else None

    def bool(self, key: str, default: bool = False) -> bool:
        value = self.values.get(key)
        return default if value in (None, "") else value.strip().lower() in _TRUE

    def int(self, key: str, default: int = 0) -> int:
        value = self.values.get(key)
        if value in (None, ""):
            return default
        try:
            return int(value)
        except ValueError as exc:
            raise ConfigError(f"Config key '{key}' must be an integer, got {value!r}") from exc
