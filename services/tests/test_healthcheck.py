import io
import json
import logging
import os
from pathlib import Path

import pytest

from lifedash import healthcheck, log
from lifedash.config import Config
from lifedash.db import conninfo


def cfg(**values: str) -> Config:
    return Config(values=values)


def test_conninfo_socket_peer_auth_has_no_password_or_sslmode():
    info = conninfo(cfg(DB_PGSQL_SOCKET_DIR="/var/run/postgresql", DB_NAME="lifedash", DB_USER="lifedash"))
    assert info["host"] == "/var/run/postgresql"
    assert "password" not in info and "sslmode" not in info
    assert info["options"] == "-c TimeZone=UTC"


def test_conninfo_tcp():
    info = conninfo(cfg(DB_HOST="10.0.0.5", DB_PORT="6543", DB_PASS="s3cret", DB_SSLMODE="require"))
    assert (info["host"], info["port"], info["password"], info["sslmode"]) == ("10.0.0.5", 6543, "s3cret", "require")


def test_migration_files_are_found():
    names = healthcheck.migration_files()
    assert names[:2] == ["0001_baseline", "0002_fk_and_hot_path_indexes"]


def test_overall_status_is_worst_check(monkeypatch):
    monkeypatch.setattr(healthcheck, "check_database", lambda c: [{"name": "database", "status": "ok", "detail": ""}])
    monkeypatch.setattr(healthcheck, "check_disk", lambda p: {"name": "disk", "status": "warn", "detail": "12% free"})
    result = healthcheck.run(cfg(DB_DRIVER="pgsql"))
    assert result["status"] == "warn"
    assert healthcheck.to_checkmk(result).startswith("1 lifedash - ")


def test_non_pgsql_driver_fails(monkeypatch):
    monkeypatch.setattr(healthcheck, "check_disk", lambda p: {"name": "disk", "status": "ok", "detail": ""})
    result = healthcheck.run(cfg(DB_DRIVER="sqlite"))
    assert result["status"] == "fail"


def test_unreachable_database_is_a_fail_result_not_an_exception():
    result = healthcheck.check_database(cfg(DB_HOST="127.0.0.1", DB_PORT="1", DB_CONNECT_TIMEOUT="2"))
    assert result[0]["status"] == "fail"


def test_json_logging_includes_extras():
    buf = io.StringIO()
    logger = log.setup("INFO", stream=buf)
    logger.info("synced", extra={"rows": 12})
    line = json.loads(buf.getvalue().strip())
    assert line["msg"] == "synced" and line["rows"] == 12 and line["level"] == "info"
    logging.getLogger().handlers.clear()


@pytest.mark.skipif(not os.environ.get("LIFEDASH_TEST_PG_DB"), reason="set LIFEDASH_TEST_PG_* to run against PostgreSQL")
def test_live_database_check():
    c = cfg(
        DB_HOST=os.environ.get("LIFEDASH_TEST_PG_HOST", "127.0.0.1"),
        DB_NAME=os.environ["LIFEDASH_TEST_PG_DB"],
        DB_USER=os.environ.get("LIFEDASH_TEST_PG_USER", "lifedash"),
        DB_PASS=os.environ.get("LIFEDASH_TEST_PG_PASS", ""),
        DB_SSLMODE="disable",
    )
    checks = {r["name"]: r for r in healthcheck.check_database(c)}
    assert checks["database"]["status"] == "ok"
    assert checks["db_timezone"]["detail"] == "UTC"
