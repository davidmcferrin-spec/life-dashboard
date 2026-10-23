#!/usr/bin/env python3
"""
HTTP smoke test for life-dashboard (stdlib only — runs on the server or a laptop).

Logs in, loads every page and read-only API, and (with --write) creates,
updates and deletes one throwaway record per widget. Any HTTP 5xx, PHP error
text, or unexpected status is a failure.

Usage:
  python3 tests/smoke/smoke_http.py --base http://127.0.0.1 --user david --password '...'
  python3 tests/smoke/smoke_http.py --base https://dash.example --user david --password-env SMOKE_PASS --write

Exit code 0 = all checks passed, 1 = failures, 2 = could not log in.
"""
from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import re
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass, field
from typing import Any

PAGES = [
    "/", "/news", "/todos", "/clock", "/flights",
    "/settings", "/settings/bookmarks", "/settings/clipboards", "/settings/clock",
    "/settings/cloud-storage", "/settings/feeds", "/settings/flights", "/settings/packages",
    "/settings/pings", "/settings/stocks", "/settings/system", "/settings/todos",
    "/settings/tokens", "/settings/users", "/settings/weather", "/settings/trackers",
]

READ_APIS = [
    "/api/time", "/api/preferences", "/api/bookmarks", "/api/clipboards",
    "/api/clock/alarm-sounds", "/api/cloudpad", "/api/feeds?scope=dashboard",
    "/api/feeds?scope=reader", "/api/files", "/api/files/shared-with-me",
    "/api/flights", "/api/packages", "/api/pings", "/api/stocks",
    "/api/storage/status", "/api/storage/shared-with-me", "/api/todos",
    "/api/todos/assigned", "/api/todos/widget", "/api/admin/cloud-storage",
    "/api/v1/metrics", "/api/v1/habits", "/api/v1/widget-types", "/api/v1/widgets",
    "/api/v1/realtime/token",
]

# Text that means PHP leaked an error into the response body.
PHP_ERROR_RE = re.compile(r"(Fatal error|Uncaught |SQLSTATE\[|Warning: |Parse error)", re.I)


@dataclass
class Result:
    passed: int = 0
    failures: list[str] = field(default_factory=list)

    def ok(self) -> None:
        self.passed += 1

    def fail(self, msg: str) -> None:
        self.failures.append(msg)


class Client:
    def __init__(self, base: str, timeout: float, insecure: bool = False) -> None:
        self.base = base.rstrip("/")
        self.timeout = timeout
        self.jar = http.cookiejar.CookieJar()
        handlers: list = [urllib.request.HTTPCookieProcessor(self.jar), _NoRedirect()]
        if insecure:
            # Only for testing before the real certificate exists.
            ctx = ssl.create_default_context()
            ctx.check_hostname = False
            ctx.verify_mode = ssl.CERT_NONE
            handlers.append(urllib.request.HTTPSHandler(context=ctx))
        self.opener = urllib.request.build_opener(*handlers)
        self.csrf = ""

    def request(self, method: str, path: str, body: Any = None, form: dict | None = None) -> tuple[int, str]:
        headers = {"Accept": "application/json, text/html"}
        data = None
        if form is not None:
            data = urllib.parse.urlencode(form).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        elif body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        if method == "POST" and self.csrf:
            headers["X-CSRF-Token"] = self.csrf
        req = urllib.request.Request(self.base + path, data=data, method=method, headers=headers)
        try:
            with self.opener.open(req, timeout=self.timeout) as resp:
                return resp.status, resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def refresh_csrf(self, html: str) -> None:
        m = re.search(r'name="_csrf"\s+value="([^"]+)"', html) or re.search(
            r'<meta[^>]+name="csrf-token"[^>]+content="([^"]+)"', html
        )
        if m:
            self.csrf = m.group(1)


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Surface 3xx to the caller so login success/failure is observable."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401
        return None

    def http_error_302(self, req, fp, code, msg, headers):
        return fp

    http_error_301 = http_error_303 = http_error_307 = http_error_308 = http_error_302


def login(c: Client, user: str, password: str) -> bool:
    status, html = c.request("GET", "/login")
    if status != 200:
        print(f"GET /login returned {status}", file=sys.stderr)
        return False
    c.refresh_csrf(html)
    status, _ = c.request("POST", "/login", form={"_csrf": c.csrf, "identifier": user, "password": password})
    if status not in (302, 303):
        return False
    status, html = c.request("GET", "/")
    if status != 200:
        return False
    c.refresh_csrf(html)
    return True


def check(res: Result, label: str, status: int, body: str, expect: tuple[int, ...] = (200,)) -> None:
    if status >= 500 or status not in expect:
        res.fail(f"{label}: HTTP {status} {body[:200]!r}")
    elif PHP_ERROR_RE.search(body):
        res.fail(f"{label}: PHP error text in body: {PHP_ERROR_RE.search(body).group(0)}")
    else:
        res.ok()


def created_id(body: str) -> int | None:
    try:
        payload = json.loads(body)
    except json.JSONDecodeError:
        return None
    for value in payload.values():
        if isinstance(value, dict) and "id" in value:
            return int(value["id"])
    return None


def write_checks(c: Client, res: Result) -> None:
    """Create → update → delete one throwaway record per feature."""
    cases = [
        # (create path, body, update path fmt or None, update body, delete path fmt)
        ("/api/todos/create", {"name": "smoke-list"}, "/api/todos/{id}/update", {"name": "smoke-list-2"}, "/api/todos/{id}/delete"),
        ("/api/clipboards/create", {"name": "smoke-board"}, "/api/clipboards/{id}/settings", {"name": "smoke-board-2"}, "/api/clipboards/{id}/delete"),
        ("/api/bookmarks/create", {"title": "smoke", "url": "https://example.com", "fetch_favicon": False}, "/api/bookmarks/{id}/update", {"title": "smoke-2", "url": "https://example.com"}, "/api/bookmarks/{id}/delete"),
        ("/api/flights/create", {"ident": "SMK123", "departure_at": "2030-01-01 09:00:00"}, "/api/flights/{id}/update", {"ident": "SMK124"}, "/api/flights/{id}/delete"),
        ("/api/packages/create", {"tracking_number": "1Z999AA10123456784", "carrier": "auto", "label": "smoke"}, "/api/packages/{id}/update", {"label": "smoke-2"}, "/api/packages/{id}/delete"),
        ("/api/pings/create", {"label": "smoke", "target": "1.1.1.1"}, "/api/pings/{id}/update", {"label": "smoke-2", "target": "1.1.1.1"}, "/api/pings/{id}/delete"),
        ("/api/stocks/create", {"symbol": "SMKX"}, "/api/stocks/{id}/update", {"pin_to_dashboard": False}, "/api/stocks/{id}/delete"),
        ("/api/cloudpad/create", {"name": "smoke-canvas"}, "/api/cloudpad/{id}/update", {"name": "smoke-canvas-2"}, "/api/cloudpad/{id}/delete"),
    ]
    for create_path, body, update_path, update_body, delete_path in cases:
        status, text = c.request("POST", create_path, body=body)
        check(res, f"POST {create_path}", status, text, (200, 201))
        rid = created_id(text)
        if rid is None:
            res.fail(f"POST {create_path}: no id in response {text[:160]!r}")
            continue

        if create_path == "/api/todos/create":
            s, t = c.request("POST", f"/api/todos/{rid}/items/create", body={"title": "smoke-task", "due_date": "2030-01-02"})
            check(res, "POST /api/todos/{id}/items/create", s, t, (200, 201))
            item = created_id(t)
            if item is not None:
                s, t = c.request("POST", f"/api/todos/items/{item}/update", body={"status": "completed"})
                check(res, "POST /api/todos/items/{id}/update", s, t)
                s, t = c.request("GET", f"/api/todos/{rid}/items")
                check(res, "GET /api/todos/{id}/items", s, t)

        if create_path == "/api/clipboards/create":
            s, t = c.request("POST", f"/api/clipboards/{rid}/save", body={"content": "# smoke", "version": 1})
            check(res, "POST /api/clipboards/{id}/save", s, t, (200, 409))

        if update_path:
            s, t = c.request("POST", update_path.format(id=rid), body=update_body)
            check(res, f"POST {update_path}", s, t)

        s, t = c.request("POST", delete_path.format(id=rid), body={})
        check(res, f"POST {delete_path}", s, t, (200, 204))


def v1_write_checks(c: Client, res: Result) -> None:
    """L1 platform: metric → habit toggle → widget create/configure/rollback/layout → cleanup.

    Metrics cannot be deleted (datapoints hang off them); the smoke habit is archived.
    """
    s, t = c.request("POST", "/api/v1/metrics", body={"name": "smoke habit", "kind": "habit"})
    check(res, "POST /api/v1/metrics", s, t, (201,))
    metric = created_id(t)
    if metric is None:
        res.fail(f"POST /api/v1/metrics: no id in {t[:160]!r}")
        return

    for label in ("toggle on", "toggle off"):
        s, t = c.request("POST", f"/api/v1/habits/{metric}/toggle", body={})
        check(res, f"POST /api/v1/habits/{{id}}/toggle ({label})", s, t)

    s, t = c.request("POST", "/api/v1/widgets", body={"type": "streak", "config": {"metric_id": metric}})
    check(res, "POST /api/v1/widgets", s, t, (201,))
    widget = created_id(t)
    if widget is not None:
        s, t = c.request("GET", f"/api/v1/widgets/{widget}/data")
        check(res, "GET /api/v1/widgets/{id}/data", s, t)
        s, t = c.request("POST", f"/api/v1/widgets/{widget}/update", body={"title": "smoke streak"})
        check(res, "POST /api/v1/widgets/{id}/update", s, t)
        s, t = c.request("GET", f"/api/v1/widgets/{widget}/revisions")
        check(res, "GET /api/v1/widgets/{id}/revisions", s, t)
        s, t = c.request("POST", f"/api/v1/widgets/{widget}/rollback", body={"version": 1})
        check(res, "POST /api/v1/widgets/{id}/rollback", s, t)
        s, t = c.request("POST", f"/api/v1/widgets/{widget}/update", body={"config": {"metric_id": 0}})
        check(res, "POST /api/v1/widgets/{id}/update (invalid → 422)", s, t, (422,))
        s, t = c.request("POST", "/api/v1/layout", body={"items": [{"id": widget, "x": 0, "y": 200, "w": 3, "h": 3}]})
        check(res, "POST /api/v1/layout", s, t)
        s, t = c.request("POST", f"/api/v1/widgets/{widget}/delete", body={})
        check(res, "POST /api/v1/widgets/{id}/delete", s, t)

    s, t = c.request("POST", f"/api/v1/metrics/{metric}/archive", body={"archived": True})
    check(res, "POST /api/v1/metrics/{id}/archive", s, t)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--base", required=True)
    ap.add_argument("--user", required=True)
    pw = ap.add_mutually_exclusive_group(required=True)
    pw.add_argument("--password")
    pw.add_argument("--password-env", help="read the password from this environment variable")
    ap.add_argument("--write", action="store_true", help="also run create/update/delete checks")
    ap.add_argument("--timeout", type=float, default=15.0)
    ap.add_argument("--insecure", action="store_true", help="skip TLS verification (self-signed test certs only)")
    args = ap.parse_args()

    password = args.password if args.password is not None else os.environ.get(args.password_env, "")
    c = Client(args.base, args.timeout, args.insecure)
    res = Result()

    status, body = c.request("GET", "/healthz")
    check(res, "GET /healthz", status, body)
    if status == 200 and '"ok"' not in body:
        res.fail(f"GET /healthz: unexpected body {body[:80]!r}")

    if not login(c, args.user, password):
        print("Login failed — check --user/--password and that the site is up.", file=sys.stderr)
        return 2

    for path in PAGES:
        status, body = c.request("GET", path)
        check(res, f"GET {path}", status, body, (200, 302))
    for path in READ_APIS:
        status, body = c.request("GET", path)
        check(res, f"GET {path}", status, body, (200, 403))
    if args.write:
        write_checks(c, res)
        v1_write_checks(c, res)

    print(f"passed: {res.passed}  failed: {len(res.failures)}")
    for f in res.failures:
        print(f"  FAIL {f}")
    return 0 if not res.failures else 1


if __name__ == "__main__":
    sys.exit(main())
