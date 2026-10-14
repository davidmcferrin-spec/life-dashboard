"""``lifedash-mcp`` — MCP endpoint on 127.0.0.1:8765.

Cloudflare Tunnel sends ``/mcp`` here. Each tool call is an HTTP request to the
PHP ``/api/v1`` API with the caller's bearer token and ``X-Lifedash-Tool``.
This process does not hold a token of its own and does not place trades.
"""
from __future__ import annotations

import json
import logging
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Callable
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen

from .config import Config
from .log import setup as setup_logging

LOG = logging.getLogger("lifedash.mcp")
PROTOCOL = "2025-03-26"

Fetch = Callable[[str, str, dict[str, Any] | None, str, str], tuple[int, Any]]

TOOLS: list[dict[str, Any]] = [
    {
        "name": "list_metrics",
        "description": "List metrics (habits, scales, numbers, durations).",
        "inputSchema": {"type": "object", "properties": {"kind": {"type": "string"}}, "additionalProperties": False},
    },
    {
        "name": "create_metric",
        "description": "Create a metric. kind is habit, scale, number, or duration.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "kind": {"type": "string"},
                "unit": {"type": "string"},
                "key": {"type": "string"},
            },
            "required": ["name", "kind"],
            "additionalProperties": False,
        },
    },
    {
        "name": "record_datapoint",
        "description": "Record a metric value. Pass dedupe_key so a retry updates the same row. source is set to ai.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "metric_id": {"type": "integer"},
                "value": {"type": "number"},
                "date": {"type": "string"},
                "ts": {"type": "string"},
                "note": {"type": "string"},
                "dedupe_key": {"type": "string"},
            },
            "required": ["metric_id", "value"],
            "additionalProperties": False,
        },
    },
    {
        "name": "query_series",
        "description": "Bucketed series for one metric.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "metric_id": {"type": "integer"},
                "bucket": {"type": "string"},
                "agg": {"type": "string"},
                "from": {"type": "string"},
                "to": {"type": "string"},
            },
            "required": ["metric_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_habits",
        "description": "Habit streaks and whether each is done today.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "toggle_habit",
        "description": "Flip a habit for a local date (default today).",
        "inputSchema": {
            "type": "object",
            "properties": {"metric_id": {"type": "integer"}, "date": {"type": "string"}},
            "required": ["metric_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_widgets",
        "description": "Dashboard widgets.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "widget_data",
        "description": "What a widget is currently showing.",
        "inputSchema": {
            "type": "object",
            "properties": {"widget_id": {"type": "integer"}},
            "required": ["widget_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "life_today",
        "description": "Sleep, steps, exercise, weight, and travel for today.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "life_ingest",
        "description": "Write life entries or Health Connect records.",
        "inputSchema": {"type": "object", "properties": {"entries": {"type": "array"}, "records": {"type": "array"}}},
    },
    {
        "name": "search_pages",
        "description": "Search the markdown vault, or list recent pages when q is empty.",
        "inputSchema": {
            "type": "object",
            "properties": {"q": {"type": "string"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "get_page",
        "description": "Read one vault page, including backlinks.",
        "inputSchema": {
            "type": "object",
            "properties": {"page_id": {"type": "integer"}},
            "required": ["page_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "write_page",
        "description": "Create a vault page, or update it when page_id is set. Body is Markdown; use [[wikilinks]].",
        "inputSchema": {
            "type": "object",
            "properties": {
                "page_id": {"type": "integer"},
                "title": {"type": "string"},
                "body": {"type": "string"},
                "kind": {"type": "string"},
            },
            "required": ["title"],
            "additionalProperties": False,
        },
    },
    {
        "name": "money_summary",
        "description": "Spending and net worth. Read only aside from what the user asked to record elsewhere.",
        "inputSchema": {
            "type": "object",
            "properties": {"from": {"type": "string"}, "to": {"type": "string"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "search",
        "description": (
            "Search every stored dataset. A hit means the text contains the query letters. "
            "include and exclude are dataset names: pages, feeds, metrics, todos, bookmarks, "
            "clipboards, money, invest, widgets, files, stocks, cloudpad, youtube, reddit, x. Omit both to search all. "
            "Hits labeled similar are extra and only appear when embeddings exist."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "q": {"type": "string"},
                "include": {"type": "array", "items": {"type": "string"}},
                "exclude": {"type": "array", "items": {"type": "string"}},
            },
            "required": ["q"],
            "additionalProperties": False,
        },
    },
    {
        "name": "invest_scorecard",
        "description": "Read-only positions and unrealized result. Do not place trades.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "list_tracked_subreddits",
        "description": "Subreddits this person tracks, with sort and keyword filters.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "add_tracked_subreddit",
        "description": "Track a subreddit. sort is hot, new, or top. include and exclude are optional comma-separated words.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "sort": {"type": "string"},
                "include": {"type": "string"},
                "exclude": {"type": "string"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "remove_tracked_subreddit",
        "description": "Stop tracking a subreddit and delete the posts saved from it.",
        "inputSchema": {
            "type": "object",
            "properties": {"name": {"type": "string"}},
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "get_reddit_posts",
        "description": "Posts saved locally. Filter by subreddit, since (YYYY-MM-DD), min_score, keyword, and limit.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "subreddit": {"type": "string"},
                "since": {"type": "string"},
                "min_score": {"type": "integer"},
                "keyword": {"type": "string"},
                "limit": {"type": "integer"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "get_reddit_post_comments",
        "description": "Top-level comments for a stored post. Fetched when asked, not stored.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "post_id": {"type": "string"},
                "limit": {"type": "integer"},
            },
            "required": ["post_id"],
            "additionalProperties": False,
        },
    },
]


def resolve(name: str, arguments: dict[str, Any] | None) -> tuple[str, str, dict[str, Any] | None]:
    """Map a tool call to an /api/v1 method, path, and optional JSON body."""
    args = arguments or {}
    if name == "list_metrics":
        query = ("?" + urlencode({"kind": args["kind"]})) if args.get("kind") else ""
        return "GET", "/api/v1/metrics" + query, None
    if name == "create_metric":
        body = {k: args[k] for k in ("name", "kind", "unit", "key") if k in args}
        return "POST", "/api/v1/metrics", body
    if name == "record_datapoint":
        body = {k: args[k] for k in ("value", "date", "ts", "note", "dedupe_key") if k in args}
        return "POST", f"/api/v1/metrics/{int(args['metric_id'])}/datapoints", body
    if name == "query_series":
        query = {k: args[k] for k in ("bucket", "agg", "from", "to") if k in args}
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", f"/api/v1/metrics/{int(args['metric_id'])}/series" + suffix, None
    if name == "list_habits":
        return "GET", "/api/v1/habits", None
    if name == "toggle_habit":
        body = {"date": args["date"]} if args.get("date") else {}
        return "POST", f"/api/v1/habits/{int(args['metric_id'])}/toggle", body
    if name == "list_widgets":
        return "GET", "/api/v1/widgets", None
    if name == "widget_data":
        return "GET", f"/api/v1/widgets/{int(args['widget_id'])}/data", None
    if name == "life_today":
        return "GET", "/api/v1/life/today", None
    if name == "life_ingest":
        body = {k: args[k] for k in ("entries", "records") if k in args}
        return "POST", "/api/v1/life/ingest", body
    if name == "search_pages":
        query = ("?" + urlencode({"q": args["q"]})) if args.get("q") else ""
        return "GET", "/api/v1/pages" + query, None
    if name == "get_page":
        return "GET", f"/api/v1/pages/{int(args['page_id'])}", None
    if name == "write_page":
        body = {k: args[k] for k in ("title", "body", "kind") if k in args}
        if args.get("page_id"):
            return "POST", f"/api/v1/pages/{int(args['page_id'])}/update", body
        return "POST", "/api/v1/pages", body
    if name == "money_summary":
        query = {k: args[k] for k in ("from", "to") if k in args}
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/money/summary" + suffix, None
    if name == "invest_scorecard":
        return "GET", "/api/v1/invest/scorecard", None
    if name == "list_tracked_subreddits":
        return "GET", "/api/v1/reddit/subs", None
    if name == "add_tracked_subreddit":
        body = {k: args[k] for k in ("name", "sort", "include", "exclude") if k in args}
        return "POST", "/api/v1/reddit/subs", body
    if name == "remove_tracked_subreddit":
        return "POST", "/api/v1/reddit/subs/remove", {"name": args["name"]}
    if name == "get_reddit_posts":
        query = {}
        for key in ("subreddit", "since", "keyword"):
            if args.get(key):
                query[key] = args[key]
        if "min_score" in args and args["min_score"] is not None:
            query["min_score"] = int(args["min_score"])
        if args.get("limit"):
            query["limit"] = int(args["limit"])
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/reddit/posts" + suffix, None
    if name == "get_reddit_post_comments":
        query = ("?limit=" + str(int(args["limit"]))) if args.get("limit") else ""
        return "GET", f"/api/v1/reddit/posts/{args['post_id']}/comments" + query, None
    if name == "search":
        query = {"q": args["q"]}
        if args.get("include"):
            query["include"] = ",".join(str(name) for name in args["include"])
        if args.get("exclude"):
            query["exclude"] = ",".join(str(name) for name in args["exclude"])
        return "GET", "/api/v1/search?" + urlencode(query), None
    raise ValueError(f"Unknown tool {name}")


def _result(text: str, is_error: bool = False) -> dict[str, Any]:
    return {"content": [{"type": "text", "text": text}], "isError": is_error}


def handle_message(message: dict[str, Any], authorization: str | None, fetch: Fetch) -> dict[str, Any] | None:
    """One JSON-RPC message. Notifications (no id) return None."""
    method = message.get("method")
    msg_id = message.get("id")
    if method == "notifications/initialized" or (method or "").startswith("notifications/"):
        return None

    def reply(result: Any = None, error: dict[str, Any] | None = None) -> dict[str, Any]:
        payload: dict[str, Any] = {"jsonrpc": "2.0", "id": msg_id}
        if error is not None:
            payload["error"] = error
        else:
            payload["result"] = result
        return payload

    if method == "initialize":
        return reply({
            "protocolVersion": PROTOCOL,
            "capabilities": {"tools": {}},
            "serverInfo": {"name": "lifedash", "version": "0.1.0"},
        })
    if method == "ping":
        return reply({})
    if method == "tools/list":
        if not authorization:
            return reply(error={"code": -32001, "message": "Authorization: Bearer is required."})
        return reply({"tools": TOOLS})
    if method == "tools/call":
        if not authorization:
            return reply(error={"code": -32001, "message": "Authorization: Bearer is required."})
        params = message.get("params") or {}
        name = str(params.get("name") or "")
        try:
            http_method, path, body = resolve(name, params.get("arguments") or {})
        except (KeyError, TypeError, ValueError) as exc:
            return reply(_result(str(exc), is_error=True))
        status, payload = fetch(http_method, path, body, authorization, name)
        text = payload if isinstance(payload, str) else json.dumps(payload, ensure_ascii=False)
        return reply(_result(text, is_error=status >= 400))
    return reply(error={"code": -32601, "message": f"Method not found: {method}"})


def urllib_fetch(api_base: str) -> Fetch:
    def fetch(method: str, path: str, body: dict[str, Any] | None, authorization: str, tool: str) -> tuple[int, Any]:
        data = None if body is None else json.dumps(body).encode("utf-8")
        req = Request(
            api_base.rstrip("/") + path,
            data=data,
            method=method,
            headers={
                "Accept": "application/json",
                "Authorization": authorization,
                "X-Lifedash-Tool": tool,
                **({"Content-Type": "application/json"} if data is not None else {}),
            },
        )
        try:
            with urlopen(req, timeout=30) as res:
                raw = res.read().decode("utf-8")
                status = res.status
        except HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            status = exc.code
        except URLError as exc:
            return 502, {"error": f"API unreachable: {exc.reason}"}
        try:
            parsed = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            parsed = raw
        return status, parsed

    return fetch


def make_handler(fetch: Fetch) -> type[BaseHTTPRequestHandler]:
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def do_POST(self) -> None:  # noqa: N802
            path = self.path.split("?", 1)[0]
            if path not in ("/mcp", "/mcp/", "/"):
                self._send(404, {"error": "not found"})
                return
            length = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(length) if length else b""
            try:
                message = json.loads(raw.decode("utf-8") or "{}")
            except json.JSONDecodeError:
                self._send(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "Parse error"}})
                return
            auth = self.headers.get("Authorization")
            if isinstance(message, list):
                replies = [r for item in message if isinstance(item, dict) and (r := handle_message(item, auth, fetch)) is not None]
                self._send(200, replies)
                return
            if not isinstance(message, dict):
                self._send(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": "Invalid request"}})
                return
            reply = handle_message(message, auth, fetch)
            if reply is None:
                self._send(202, None)
                return
            self._send(200, reply)

        def do_GET(self) -> None:  # noqa: N802
            if self.path.split("?", 1)[0] in ("/healthz", "/mcp"):
                self._send(200, {"ok": True, "service": "lifedash-mcp"})
                return
            self._send(404, {"error": "not found"})

        def log_message(self, fmt: str, *args: Any) -> None:
            LOG.info("%s - " + fmt, self.address_string(), *args)

        def _send(self, status: int, payload: Any) -> None:
            body = b"" if payload is None else json.dumps(payload).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            if body:
                self.wfile.write(body)

    return Handler


def main() -> None:
    config = Config.load()
    setup_logging(config.get("LOG_LEVEL", "INFO"))
    host = "127.0.0.1"
    port = int(config.get("MCP_PORT", "8765"))
    api_base = config.get("MCP_API_BASE", "http://127.0.0.1")
    server = ThreadingHTTPServer((host, port), make_handler(urllib_fetch(api_base)))
    LOG.info("lifedash-mcp listening on http://%s:%s/mcp → %s", host, port, api_base)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        server.shutdown()


if __name__ == "__main__":
    main()
