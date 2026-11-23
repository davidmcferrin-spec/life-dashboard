"""MCP tools for the shared handoff board.

The sender is the API key name. These tools do not take a from_agent field.
"""

from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlencode

_STATUSES = ("open", "claimed", "in_progress", "blocked", "done", "cancelled")
_PRIORITIES = ("low", "normal", "high")
_CLOSE = ("done", "cancelled")
_STAMP = re.compile(r"^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$")

HANDOFF_TOOLS: list[dict[str, Any]] = [
    {
        "name": "create_handoff",
        "description": (
            "Open a handoff for another bot, or for anyone when to_agent is omitted. "
            "from_agent is this API key's name. body is markdown. "
            "priority is low, normal, or high. due_at is a date or timestamp. "
            "tags, linked_page_ids, linked_urls, and trip_id are optional. Requires handoffs:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "title": {"type": "string"},
                "body": {"type": "string"},
                "to_agent": {"type": ["string", "null"]},
                "priority": {"type": "string"},
                "due_at": {"type": ["string", "null"]},
                "tags": {"type": "array", "items": {"type": "string"}},
                "linked_page_ids": {"type": "array", "items": {"type": "integer"}},
                "linked_urls": {"type": "array", "items": {"type": "string"}},
                "trip_id": {"type": ["integer", "null"]},
            },
            "required": ["title"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_handoffs",
        "description": (
            "Handoffs for this user. Filters: to_agent, from_agent, status, tag, "
            "mine (true uses this key's name against to_agent and claimed_by), "
            "open_only, and updated_since. Requires handoffs:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "to_agent": {"type": "string"},
                "from_agent": {"type": "string"},
                "status": {"type": "string"},
                "tag": {"type": "string"},
                "mine": {"type": "boolean"},
                "open_only": {"type": "boolean"},
                "updated_since": {"type": "string"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "get_handoff",
        "description": "One handoff and its append-only thread. Requires handoffs:read.",
        "inputSchema": {
            "type": "object",
            "properties": {"handoff_id": {"type": "integer"}},
            "required": ["handoff_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "claim_handoff",
        "description": (
            "Claim an open handoff as this key. claimed_by becomes the key name and an open card moves to claimed. "
            "note is optional. Requires handoffs:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "handoff_id": {"type": "integer"},
                "note": {"type": "string"},
            },
            "required": ["handoff_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_handoff_status",
        "description": (
            "Set status to open, claimed, in_progress, blocked, done, or cancelled. "
            "note is stored on the thread with this key's name. Requires handoffs:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "handoff_id": {"type": "integer"},
                "status": {"type": "string"},
                "note": {"type": "string"},
            },
            "required": ["handoff_id", "status"],
            "additionalProperties": False,
        },
    },
    {
        "name": "comment_handoff",
        "description": "Append a note to the thread. The status stays as it is. Requires handoffs:write.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "handoff_id": {"type": "integer"},
                "body": {"type": "string"},
            },
            "required": ["handoff_id", "body"],
            "additionalProperties": False,
        },
    },
    {
        "name": "close_handoff",
        "description": (
            "Close a handoff as done, or cancelled when status is cancelled. "
            "note is optional. Requires handoffs:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "handoff_id": {"type": "integer"},
                "status": {"type": "string"},
                "note": {"type": "string"},
            },
            "required": ["handoff_id"],
            "additionalProperties": False,
        },
    },
]


def resolve_handoff(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "create_handoff":
        unknown = set(args) - {"title", "body", "to_agent", "priority", "due_at", "tags", "linked_page_ids", "linked_urls", "trip_id"}
        if unknown:
            raise ValueError("create_handoff accepts title, body, to_agent, priority, due_at, tags, linked_page_ids, linked_urls, and trip_id.")
        title = _text(args.get("title"), "title", 200, required=True)
        body: dict[str, Any] = {"title": title}
        if "body" in args:
            body["body"] = _text(args.get("body"), "body", 20000, required=False)
        if "to_agent" in args:
            body["to_agent"] = _agent(args.get("to_agent"))
        if "priority" in args and args.get("priority") not in (None, ""):
            priority = args.get("priority")
            if priority not in _PRIORITIES:
                raise ValueError("priority must be low, normal, or high.")
            body["priority"] = priority
        if "due_at" in args and args.get("due_at") not in (None, ""):
            body["due_at"] = _stamp(args.get("due_at"), "due_at")
        if "tags" in args:
            body["tags"] = _strings(args.get("tags"), "tags", 12, 32)
        if "linked_page_ids" in args:
            body["linked_page_ids"] = _ids(args.get("linked_page_ids"), "linked_page_ids")
        if "linked_urls" in args:
            body["linked_urls"] = _strings(args.get("linked_urls"), "linked_urls", 20, 500)
        if "trip_id" in args and args.get("trip_id") is not None:
            trip_id = args.get("trip_id")
            if isinstance(trip_id, bool) or not isinstance(trip_id, int) or trip_id < 1:
                raise ValueError("trip_id must be a trip.")
            body["trip_id"] = trip_id
        return "POST", "/api/v1/handoffs", body
    if name == "list_handoffs":
        unknown = set(args) - {"to_agent", "from_agent", "status", "tag", "mine", "open_only", "updated_since"}
        if unknown:
            raise ValueError("list_handoffs accepts to_agent, from_agent, status, tag, mine, open_only, and updated_since.")
        query: dict[str, str] = {}
        if args.get("to_agent") not in (None, ""):
            query["to_agent"] = _agent(args.get("to_agent"), required=True) or ""
        if args.get("from_agent") not in (None, ""):
            query["from_agent"] = _agent(args.get("from_agent"), required=True) or ""
        if args.get("status") not in (None, ""):
            status = args.get("status")
            if status not in _STATUSES:
                raise ValueError("status must be open, claimed, in_progress, blocked, done, or cancelled.")
            query["status"] = status
        if args.get("tag") not in (None, ""):
            tag = args.get("tag")
            if not isinstance(tag, str) or tag.strip() == "" or len(tag) > 32:
                raise ValueError("tag must be text.")
            query["tag"] = tag.strip()
        if "mine" in args:
            if not isinstance(args.get("mine"), bool):
                raise ValueError("mine must be true or false.")
            if args["mine"]:
                query["mine"] = "1"
        if "open_only" in args:
            if not isinstance(args.get("open_only"), bool):
                raise ValueError("open_only must be true or false.")
            if args["open_only"]:
                query["open_only"] = "1"
        if args.get("updated_since") not in (None, ""):
            query["updated_since"] = _stamp(args.get("updated_since"), "updated_since")
        path = "/api/v1/handoffs"
        if query:
            path += "?" + urlencode(query)
        return "GET", path, None
    if name == "get_handoff":
        if set(args) != {"handoff_id"}:
            raise ValueError("get_handoff accepts only handoff_id.")
        return "GET", f"/api/v1/handoffs/{_id(args.get('handoff_id'))}", None
    if name == "claim_handoff":
        unknown = set(args) - {"handoff_id", "note"}
        if unknown or "handoff_id" not in args:
            raise ValueError("claim_handoff accepts handoff_id and note.")
        body = {}
        if args.get("note") not in (None, ""):
            body["note"] = _text(args.get("note"), "note", 4000, required=False)
        return "POST", f"/api/v1/handoffs/{_id(args.get('handoff_id'))}/claim", body
    if name == "update_handoff_status":
        unknown = set(args) - {"handoff_id", "status", "note"}
        if unknown or "handoff_id" not in args or "status" not in args:
            raise ValueError("update_handoff_status accepts handoff_id, status, and note.")
        status = args.get("status")
        if status not in _STATUSES:
            raise ValueError("status must be open, claimed, in_progress, blocked, done, or cancelled.")
        body = {"status": status}
        if args.get("note") not in (None, ""):
            body["note"] = _text(args.get("note"), "note", 4000, required=False)
        return "POST", f"/api/v1/handoffs/{_id(args.get('handoff_id'))}/status", body
    if name == "comment_handoff":
        if set(args) - {"handoff_id", "body"} or "handoff_id" not in args or "body" not in args:
            raise ValueError("comment_handoff accepts handoff_id and body.")
        return "POST", f"/api/v1/handoffs/{_id(args.get('handoff_id'))}/comment", {
            "body": _text(args.get("body"), "body", 4000, required=True),
        }
    if name == "close_handoff":
        unknown = set(args) - {"handoff_id", "status", "note"}
        if unknown or "handoff_id" not in args:
            raise ValueError("close_handoff accepts handoff_id, status, and note.")
        body = {}
        if args.get("status") not in (None, ""):
            status = args.get("status")
            if status not in _CLOSE:
                raise ValueError("status must be done or cancelled.")
            body["status"] = status
        if args.get("note") not in (None, ""):
            body["note"] = _text(args.get("note"), "note", 4000, required=False)
        return "POST", f"/api/v1/handoffs/{_id(args.get('handoff_id'))}/close", body
    if name.startswith("handoff") or name.endswith("_handoff"):
        raise ValueError(f"Unknown tool {name}")
    return None


def _id(value: Any) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise ValueError("handoff_id must be a positive id.")
    return value


def _text(value: Any, field: str, limit: int, required: bool) -> str:
    if value is None or value == "":
        if required:
            raise ValueError(f"{field} is required.")
        return ""
    if not isinstance(value, str):
        raise ValueError(f"{field} must be text.")
    if len(value) > limit:
        raise ValueError(f"{field} must be {limit} characters or less.")
    return value


def _agent(value: Any, required: bool = False) -> str | None:
    if value is None or value == "":
        if required:
            raise ValueError("Agent name is required.")
        return None
    if not isinstance(value, str):
        raise ValueError("Agent name must be text.")
    name = " ".join(value.split())
    if name == "" or len(name) > 60 or any(ord(ch) < 32 for ch in name):
        raise ValueError("Agent name must be 60 characters or less, on one line.")
    return name


def _stamp(value: Any, field: str) -> str:
    if not isinstance(value, str) or _STAMP.fullmatch(value.strip()) is None:
        raise ValueError(f"{field} must be a date or timestamp.")
    return value.strip()


def _strings(value: Any, field: str, limit: int, item_limit: int) -> list[str]:
    if value is None:
        return []
    if not isinstance(value, list):
        raise ValueError(f"{field} must be a list.")
    if len(value) > limit:
        raise ValueError(f"{field} has too many entries.")
    out: list[str] = []
    for item in value:
        if not isinstance(item, str):
            raise ValueError(f"{field} entries must be text.")
        if len(item) > item_limit:
            raise ValueError(f"{field} has an entry that is too long.")
        out.append(item)
    return out


def _ids(value: Any, field: str) -> list[int]:
    if value is None:
        return []
    if not isinstance(value, list) or len(value) > 20:
        raise ValueError(f"{field} must be a list of page ids.")
    out: list[int] = []
    for item in value:
        if isinstance(item, bool) or not isinstance(item, int) or item < 1:
            raise ValueError(f"{field} must be a list of page ids.")
        out.append(item)
    return out
