"""``lifedash-healthcheck`` — one-shot readiness check for deploys and monitoring.

Checks: config loads · PostgreSQL reachable · session time zone is UTC ·
no pending migrations · free disk space · lifedash-mcp · lifedash-push.
Prints one JSON object; exit code 0 = ok, 1 = warn (degraded but serving), 2 = fail.

CheckMK can run it as a local check via ``--checkmk``.
"""
from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import urlopen

from . import __version__
from .config import REPO_ROOT, Config, ConfigError

OK, WARN, FAIL = "ok", "warn", "fail"
_RANK = {OK: 0, WARN: 1, FAIL: 2}
_DOWN_SYSTEMD = {"inactive", "failed", "deactivating", "activating"}


def migration_files(root: Path = REPO_ROOT) -> list[str]:
    return sorted(p.stem for p in (root / "database" / "pgsql").glob("*.sql"))


def check_disk(path: Path, warn_pct: float = 15.0, fail_pct: float = 5.0) -> dict:
    usage = shutil.disk_usage(path)
    free_pct = usage.free / usage.total * 100
    status = FAIL if free_pct < fail_pct else WARN if free_pct < warn_pct else OK
    return {"name": "disk", "status": status, "detail": f"{free_pct:.1f}% free on {path}"}


def check_database(cfg: Config) -> list[dict]:
    # Imported lazily so config/disk checks still report when psycopg is missing.
    from . import db

    try:
        with db.connection(cfg, "lifedash-healthcheck") as conn:
            version = conn.execute("SHOW server_version").fetchone()[0]
            tz = conn.execute("SHOW TimeZone").fetchone()[0]
            has_table = conn.execute("SELECT to_regclass('migrations') IS NOT NULL").fetchone()[0]
            ran = [r[0] for r in conn.execute("SELECT name FROM migrations").fetchall()] if has_table else []
    except Exception as exc:  # noqa: BLE001 — report any driver/network failure as a check result
        return [{"name": "database", "status": FAIL, "detail": f"{type(exc).__name__}: {str(exc).strip()[:200]}"}]

    pending = sorted(set(migration_files()) - set(ran))
    return [
        {"name": "database", "status": OK, "detail": f"PostgreSQL {version}"},
        {"name": "db_timezone", "status": OK if tz == "UTC" else FAIL, "detail": tz},
        {
            "name": "migrations",
            "status": OK if not pending else WARN,
            "detail": f"{len(ran)} applied" if not pending else f"pending: {', '.join(pending)}",
        },
    ]


def git_revision(root: Path = REPO_ROOT) -> str:
    """Short commit recorded by deploy. The service user does not run git."""
    return _read_revision(root / "REVISION")


def _read_revision(path: Path) -> str:
    try:
        text = path.read_text(encoding="utf-8")
    except OSError:
        return ""
    line = text.strip().splitlines()[0].strip() if text.strip() else ""
    if len(line) < 7 or len(line) > 40 or any(ch not in "0123456789abcdef" for ch in line):
        return ""
    return line


def registry_tool_count() -> int | None:
    try:
        from .mcp_server import TOOLS
    except Exception:  # noqa: BLE001 — a broken import is a missing count, not a crash
        return None
    return len(TOOLS)


def systemd_is_active(unit: str) -> str:
    try:
        proc = subprocess.run(
            ["systemctl", "is-active", unit],
            capture_output=True,
            text=True,
            timeout=3,
        )
    except (OSError, subprocess.SubprocessError):
        return "unknown"
    lines = [line.strip().lower() for line in (proc.stdout or "").splitlines() if line.strip()]
    if lines:
        return lines[-1]
    err = (proc.stderr or "").lower()
    if "not been booted" in err or "failed to connect to bus" in err:
        return "unavailable"
    return "unknown"


def parse_listen_hosts(text: str, port: int) -> list[str]:
    """Local addresses from ``ss -ltnH`` output that are bound to ``port``."""
    hosts: list[str] = []
    for line in text.splitlines():
        parts = line.split()
        if len(parts) < 4:
            continue
        host, got = _split_host_port(parts[3])
        if got == port and host:
            hosts.append(host)
    return hosts


def listen_hosts(port: int) -> list[str]:
    try:
        proc = subprocess.run(
            ["ss", "-ltnH", f"sport = :{port}"],
            capture_output=True,
            text=True,
            timeout=3,
        )
    except (OSError, subprocess.SubprocessError):
        return []
    return parse_listen_hosts(proc.stdout or "", port)


def fetch_healthz(url: str, timeout: float = 2.0) -> tuple[int | None, Any]:
    try:
        with urlopen(url, timeout=timeout) as res:
            raw = res.read(4096).decode("utf-8", errors="replace")
            code = res.status
    except HTTPError as exc:
        raw = exc.read(4096).decode("utf-8", errors="replace")
        return exc.code, raw[:180]
    except (URLError, TimeoutError, OSError) as exc:
        reason = exc.reason if isinstance(exc, URLError) else exc
        return None, str(reason)[:180]
    try:
        return code, json.loads(raw) if raw else {}
    except json.JSONDecodeError:
        return code, raw[:180]


def assess_mcp(
    *,
    systemd: str,
    healthz_status: int | None,
    healthz_body: Any,
    hosts: list[str],
    tools: int | None,
    version: str,
    revision: str,
    plain_http: bool = True,
    port: int = 8765,
) -> dict:
    state = (systemd or "").strip().lower() or "unknown"
    parts: list[tuple[str, str]] = [
        _systemd_part(state),
        _healthz_part(healthz_status, healthz_body, state),
        _listen_part(hosts, port, state, expect="0.0.0.0", plain_http=plain_http),
        _tools_part(tools, healthz_body),
        _version_part(version, revision),
    ]
    return {
        "name": "lifedash-mcp",
        "status": _worst(status for status, _detail in parts),
        "detail": "; ".join(detail for _status, detail in parts),
    }


def assess_push(*, systemd: str, hosts: list[str], port: int = 8766) -> dict:
    state = (systemd or "").strip().lower() or "unknown"
    parts = [_systemd_part(state), _listen_part(hosts, port, state, expect="127.0.0.1")]
    return {"name": "lifedash-push", "status": _worst(status for status, _detail in parts), "detail": "; ".join(detail for _status, detail in parts)}


def check_mcp(port: int = 8765, *, plain_http: bool = True) -> dict:
    code, body = fetch_healthz(f"http://127.0.0.1:{port}/healthz")
    return assess_mcp(
        systemd=systemd_is_active("lifedash-mcp.service"),
        healthz_status=code,
        healthz_body=body,
        hosts=listen_hosts(port),
        tools=registry_tool_count(),
        version=__version__,
        revision=git_revision(),
        plain_http=plain_http,
        port=port,
    )


def check_push(port: int = 8766) -> dict:
    return assess_push(
        systemd=systemd_is_active("lifedash-push.service"),
        hosts=listen_hosts(port),
        port=port,
    )


def check_services(cfg: Config | None = None) -> list[dict]:
    if cfg is None:
        try:
            cfg = Config.load()
        except ConfigError:
            cfg = None
    mcp_port = cfg.int("MCP_PORT", 8765) if cfg is not None else 8765
    push_port = cfg.int("PUSH_PORT", 8766) if cfg is not None else 8766
    plain_http = plain_http_allowed(cfg) if cfg is not None else True
    return [check_mcp(mcp_port, plain_http=plain_http), check_push(push_port)]


def plain_http_allowed(cfg: Config) -> bool:
    """Same rule as the MCP server: a saved flag wins, otherwise MCP_ALLOW_PLAIN_HTTP."""
    default = cfg.bool("MCP_ALLOW_PLAIN_HTTP", True)
    try:
        from .mcp_server import effective_plain_http, read_plain_http_flag

        stored = read_plain_http_flag(cfg)
    except Exception:  # noqa: BLE001 — an unread flag keeps the config value
        return default
    return effective_plain_http(stored, default)


def run(cfg: Config | None = None) -> dict:
    checks: list[dict] = []
    if cfg is None:
        try:
            cfg = Config.load()
            checks.append({"name": "config", "status": OK, "detail": str(cfg.source or "environment only")})
        except ConfigError as exc:
            checks.append({"name": "config", "status": FAIL, "detail": str(exc)})
    if cfg is not None:
        if cfg.get("DB_DRIVER", "pgsql") != "pgsql":
            checks.append({"name": "database", "status": FAIL, "detail": "DB_DRIVER must be pgsql for the Python services"})
        else:
            checks.extend(check_database(cfg))
    checks.append(check_disk(REPO_ROOT))
    checks.extend(check_services(cfg))
    overall = max((c["status"] for c in checks), key=_RANK.__getitem__)
    return {"status": overall, "checks": checks}


def to_checkmk(result: dict) -> str:
    """CheckMK local-check line: <state> <service> <perfdata> <text>."""
    state = _RANK[result["status"]]
    text = "; ".join(f"{c['name']}={c['status']}" + (f" ({c['detail']})" if c["status"] != OK else "") for c in result["checks"])
    return f"{state} lifedash - {text}"


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="lifedash-healthcheck", description=__doc__)
    ap.add_argument("--checkmk", action="store_true", help="print a CheckMK local-check line instead of JSON")
    args = ap.parse_args(argv)

    result = run()
    print(to_checkmk(result) if args.checkmk else json.dumps(result, indent=2))
    return 0 if args.checkmk else _RANK[result["status"]]


def _worst(statuses: Any) -> str:
    return max(statuses, key=_RANK.__getitem__)


def _systemd_part(state: str) -> tuple[str, str]:
    if state == "active":
        return OK, "systemd active"
    if state in _DOWN_SYSTEMD:
        return FAIL, f"systemd {state}"
    return WARN, f"systemd {state}"


def _healthz_part(code: int | None, body: Any, systemd: str) -> tuple[str, str]:
    down = FAIL if systemd == "active" else WARN
    if code == 200 and isinstance(body, dict) and body.get("ok") is True:
        service = body.get("service")
        if service not in (None, "lifedash-mcp"):
            return WARN, f"healthz 200 service {service}"
        return OK, "healthz 200"
    if code is None:
        reason = body if isinstance(body, str) and body else "unreachable"
        return down, f"healthz unreachable ({reason})"
    return down, f"healthz {code}"


_ALL_INTERFACES = {"0.0.0.0", "*", "::"}


def _listen_part(hosts: list[str], port: int, systemd: str, *, expect: str, plain_http: bool = True) -> tuple[str, str]:
    if not hosts:
        status = FAIL if systemd == "active" else WARN
        return status, f"listen none :{port}"
    shown = ", ".join(_format_listen(host, port) for host in hosts)
    if expect == "0.0.0.0":
        if any(host not in _ALL_INTERFACES for host in hosts):
            return WARN, f"listen {shown} (not 0.0.0.0)"
        # 0.0.0.0 is the tunnel bind. It is a problem only when plain HTTP is off.
        if not plain_http:
            return WARN, f"listen {shown} (plain HTTP disabled)"
        return OK, f"listen {shown}"
    if any(host != expect for host in hosts):
        return WARN, f"listen {shown} (not {expect})"
    return OK, f"listen {shown}"


def _tools_part(tools: int | None, body: Any) -> tuple[str, str]:
    running = body.get("tool_count") if isinstance(body, dict) else None
    if tools is None:
        return WARN, "tools unknown"
    if isinstance(running, int) and not isinstance(running, bool) and running != tools:
        return WARN, f"{tools} tools (running {running})"
    return OK, f"{tools} tools"


def _version_part(version: str, revision: str) -> tuple[str, str]:
    version = version.strip()
    revision = revision.strip()
    if version and revision:
        return OK, f"{version} {revision}"
    if version:
        return WARN, f"{version} revision unknown"
    if revision:
        return WARN, f"revision {revision}"
    return WARN, "version unknown"


def _format_listen(host: str, port: int) -> str:
    if ":" in host:
        return f"[{host}]:{port}"
    return f"{host}:{port}"


def _split_host_port(addr: str) -> tuple[str, int | None]:
    if addr.startswith("["):
        end = addr.find("]")
        if end < 0:
            return addr, None
        host = addr[1:end]
        rest = addr[end + 1 :]
        if not rest.startswith(":"):
            return host, None
        try:
            return host, int(rest[1:])
        except ValueError:
            return host, None
    host, sep, raw_port = addr.rpartition(":")
    if not sep:
        return addr, None
    try:
        return host, int(raw_port)
    except ValueError:
        return host, None


if __name__ == "__main__":
    sys.exit(main())
