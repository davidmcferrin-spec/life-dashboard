"""PostgreSQL access for the Python services.

Uses the same DB_* keys as the PHP app. On the server the recommended setup is
Unix-socket peer authentication (``DB_PGSQL_SOCKET_DIR=/var/run/postgresql``,
no password): the ``lifedash`` OS user maps to the ``lifedash`` role, so no
database password exists anywhere on disk.

Every connection pins ``TimeZone=UTC`` to match the PHP side (TIMESTAMP columns
hold UTC wall-clock values) and tags ``application_name`` for pg_stat_activity.
"""
from __future__ import annotations

from contextlib import contextmanager
from typing import Iterator

import psycopg

from .config import Config


def conninfo(cfg: Config, application_name: str = "lifedash-py") -> dict[str, object]:
    socket_dir = cfg.maybe("DB_PGSQL_SOCKET_DIR")
    params: dict[str, object] = {
        "host": socket_dir or cfg.get("DB_HOST", "127.0.0.1"),
        "port": cfg.int("DB_PORT", 5432),
        "dbname": cfg.get("DB_NAME", "lifedash"),
        "user": cfg.get("DB_USER", "lifedash"),
        "application_name": application_name,
        "connect_timeout": cfg.int("DB_CONNECT_TIMEOUT", 5),
        "options": "-c TimeZone=UTC",
    }
    password = cfg.maybe("DB_PASS")
    if password:
        params["password"] = password
    if not socket_dir:
        params["sslmode"] = cfg.get("DB_SSLMODE", "prefer")
    return params


def connect(cfg: Config, application_name: str = "lifedash-py", autocommit: bool = True) -> psycopg.Connection:
    """Open a connection. Raises psycopg.OperationalError with the server's message on failure."""
    return psycopg.connect(**conninfo(cfg, application_name), autocommit=autocommit)


@contextmanager
def connection(cfg: Config, application_name: str = "lifedash-py") -> Iterator[psycopg.Connection]:
    conn = connect(cfg, application_name)
    try:
        yield conn
    finally:
        conn.close()
