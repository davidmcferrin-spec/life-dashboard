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


def _quiet_services(monkeypatch, rows=None):
    monkeypatch.setattr(
        healthcheck,
        "check_services",
        lambda c=None: rows
        if rows is not None
        else [
            {"name": "lifedash-mcp", "status": "ok", "detail": ""},
            {"name": "lifedash-push", "status": "ok", "detail": ""},
        ],
    )


def test_overall_status_is_worst_check(monkeypatch):
    monkeypatch.setattr(healthcheck, "check_database", lambda c: [{"name": "database", "status": "ok", "detail": ""}])
    monkeypatch.setattr(healthcheck, "check_disk", lambda p: {"name": "disk", "status": "warn", "detail": "12% free"})
    _quiet_services(monkeypatch)
    result = healthcheck.run(cfg(DB_DRIVER="pgsql"))
    assert result["status"] == "warn"
    assert healthcheck.to_checkmk(result).startswith("1 lifedash - ")


def test_non_pgsql_driver_fails(monkeypatch):
    monkeypatch.setattr(healthcheck, "check_disk", lambda p: {"name": "disk", "status": "ok", "detail": ""})
    _quiet_services(monkeypatch)
    result = healthcheck.run(cfg(DB_DRIVER="sqlite"))
    assert result["status"] == "fail"


def test_run_includes_mcp_and_push(monkeypatch):
    monkeypatch.setattr(healthcheck, "check_database", lambda c: [{"name": "database", "status": "ok", "detail": ""}])
    monkeypatch.setattr(healthcheck, "check_disk", lambda p: {"name": "disk", "status": "ok", "detail": ""})
    _quiet_services(
        monkeypatch,
        [
            {"name": "lifedash-mcp", "status": "ok", "detail": "systemd active"},
            {"name": "lifedash-push", "status": "warn", "detail": "listen 0.0.0.0:8766 (not 127.0.0.1)"},
        ],
    )
    result = healthcheck.run(cfg(DB_DRIVER="pgsql"))
    names = [row["name"] for row in result["checks"]]
    assert names[-2:] == ["lifedash-mcp", "lifedash-push"]
    assert result["status"] == "warn"


def test_parse_listen_hosts_reads_the_local_address():
    text = (
        "LISTEN 0 128 127.0.0.1:8765 0.0.0.0:*\n"
        "LISTEN 0 128 0.0.0.0:8765 0.0.0.0:*\n"
        "LISTEN 0 128 [::1]:8766 [::]:*\n"
    )
    assert healthcheck.parse_listen_hosts(text, 8765) == ["127.0.0.1", "0.0.0.0"]
    assert healthcheck.parse_listen_hosts(text, 8766) == ["::1"]


def test_registry_tool_count_matches_the_mcp_registry():
    from lifedash.mcp_server import TOOLS

    assert healthcheck.registry_tool_count() == len(TOOLS) > 0


def _healthy_mcp(**overrides):
    facts = {
        "systemd": "active",
        "healthz_status": 200,
        "healthz_body": {"ok": True, "service": "lifedash-mcp", "tool_count": 65},
        "hosts": ["0.0.0.0"],
        "tools": 65,
        "version": "0.1.0",
        "revision": "abc1234",
    }
    facts.update(overrides)
    return healthcheck.assess_mcp(**facts)


def test_mcp_all_interfaces_active_is_ok():
    row = _healthy_mcp()
    assert row == {
        "name": "lifedash-mcp",
        "status": "ok",
        "detail": "systemd active; healthz 200; listen 0.0.0.0:8765; 65 tools; 0.1.0 abc1234",
    }


def test_mcp_loopback_listen_is_a_warning():
    row = _healthy_mcp(hosts=["127.0.0.1"])
    assert row["status"] == "warn"
    assert "listen 127.0.0.1:8765 (not 0.0.0.0)" in row["detail"]
    both = _healthy_mcp(hosts=["0.0.0.0", "127.0.0.1"])
    assert both["status"] == "warn"
    assert "not 0.0.0.0" in both["detail"]


def test_mcp_ipv6_loopback_is_not_all_interfaces():
    row = _healthy_mcp(hosts=["::1"])
    assert row["status"] == "warn"
    assert "listen [::1]:8765 (not 0.0.0.0)" in row["detail"]


def test_mcp_inactive_unit_fails():
    row = _healthy_mcp(systemd="inactive", healthz_status=None, healthz_body="connection refused", hosts=[])
    assert row["status"] == "fail"
    assert row["detail"].startswith("systemd inactive; healthz unreachable (connection refused); listen none :8765")


def test_mcp_healthz_down_while_active_fails():
    row = _healthy_mcp(healthz_status=None, healthz_body="timed out")
    assert row["status"] == "fail"
    assert "healthz unreachable (timed out)" in row["detail"]


def test_mcp_running_tool_count_drift_warns():
    row = _healthy_mcp(healthz_body={"ok": True, "service": "lifedash-mcp", "tool_count": 42})
    assert row["status"] == "warn"
    assert "65 tools (running 42)" in row["detail"]


def test_mcp_missing_revision_warns():
    row = _healthy_mcp(revision="")
    assert row["status"] == "warn"
    assert row["detail"].endswith("0.1.0 revision unknown")


def test_mcp_without_systemd_does_not_fail_closed():
    row = _healthy_mcp(systemd="unknown", healthz_status=None, healthz_body="connection refused", hosts=[])
    assert row["status"] == "warn"


def test_systemd_unavailable_when_the_host_has_no_systemd(monkeypatch):
    class Proc:
        stdout = ""
        stderr = "System has not been booted with systemd as init system (PID 1).\nFailed to connect to bus: Host is down\n"
        returncode = 1

    monkeypatch.setattr(healthcheck.subprocess, "run", lambda *args, **kwargs: Proc())
    assert healthcheck.systemd_is_active("lifedash-mcp.service") == "unavailable"


def test_push_loopback_active_is_ok():
    row = healthcheck.assess_push(systemd="active", hosts=["127.0.0.1"])
    assert row == {"name": "lifedash-push", "status": "ok", "detail": "systemd active; listen 127.0.0.1:8766"}


def test_push_public_listen_warns_and_inactive_fails():
    public = healthcheck.assess_push(systemd="active", hosts=["0.0.0.0"])
    assert public["status"] == "warn"
    assert "not 127.0.0.1" in public["detail"]
    down = healthcheck.assess_push(systemd="failed", hosts=[])
    assert down["status"] == "fail"
    assert "systemd failed" in down["detail"]
    assert "listen none :8766" in down["detail"]


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
