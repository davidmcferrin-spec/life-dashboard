"""``lifedash-healthcheck`` — one-shot readiness check for deploys and monitoring.

Checks: config loads · PostgreSQL reachable · session time zone is UTC ·
no pending migrations · free disk space. Prints one JSON object; exit code
0 = ok, 1 = warn (degraded but serving), 2 = fail.

CheckMK can run it as a local check via ``--checkmk``.
"""
from __future__ import annotations

import argparse
import json
import shutil
import sys
from pathlib import Path

from .config import REPO_ROOT, Config, ConfigError

OK, WARN, FAIL = "ok", "warn", "fail"
_RANK = {OK: 0, WARN: 1, FAIL: 2}


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


if __name__ == "__main__":
    sys.exit(main())
