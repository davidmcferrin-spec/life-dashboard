"""MCP tools for briefs, trips, the dial book, the video slate, and findings."""

from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlencode

_DATE = re.compile(r"^\d{4}-\d{2}-\d{2}$")
_TRIP_STATUS = ("idea", "planning", "booked", "done")
_VIDEO_STATUS = ("idea", "scripted", "voiced", "edited", "scheduled", "published")

BOARD_TOOLS: list[dict[str, Any]] = [
    {
        "name": "publish_digest",
        "description": (
            "File a dated brief for one source, such as Space Desk. items are the headlines. "
            "The same source, date, and title updates that brief. page_id links the vault page. "
            "Requires digest:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "source": {"type": "string"},
                "title": {"type": "string"},
                "date": {"type": "string"},
                "items": {"type": "array", "items": {"type": ["string", "object"]}},
                "page_id": {"type": ["integer", "null"]},
            },
            "required": ["source", "title", "date", "items"],
            "additionalProperties": False,
        },
    },
    {
        "name": "create_trip",
        "description": (
            "Create a trip. status is idea, planning, booked, or done. owner_bot defaults to this key. "
            "template true writes the standard page in 04 Travel (overview, dates, flights, lodging, budget, packing, to-dos). "
            "page_ids, shopping_item_ids, and handoff_ids set trip_id on those rows. "
            "A shopping order_wave or a handoff tag that matches the trip also links it. Requires trips:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "destination": {"type": "string"},
                "start_date": {"type": ["string", "null"]},
                "end_date": {"type": ["string", "null"]},
                "status": {"type": "string"},
                "owner_bot": {"type": ["string", "null"]},
                "tags": {"type": "array", "items": {"type": "string"}},
                "notes": {"type": "string"},
                "template": {"type": "boolean"},
                "page_ids": {"type": "array", "items": {"type": "integer"}},
                "shopping_item_ids": {"type": "array", "items": {"type": "integer"}},
                "handoff_ids": {"type": "array", "items": {"type": "integer"}},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_trip",
        "description": (
            "Change a trip. template true creates the 04 Travel page when the trip does not have one yet. "
            "Requires trips:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "trip_id": {"type": "integer"},
                "name": {"type": "string"},
                "destination": {"type": "string"},
                "start_date": {"type": ["string", "null"]},
                "end_date": {"type": ["string", "null"]},
                "status": {"type": "string"},
                "owner_bot": {"type": ["string", "null"]},
                "tags": {"type": "array", "items": {"type": "string"}},
                "notes": {"type": "string"},
                "template": {"type": "boolean"},
                "page_ids": {"type": "array", "items": {"type": "integer"}},
                "shopping_item_ids": {"type": "array", "items": {"type": "integer"}},
                "handoff_ids": {"type": "array", "items": {"type": "integer"}},
            },
            "required": ["trip_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_trips",
        "description": (
            "Trips for this user. Filter by status, owner_bot, tag, or upcoming true. Requires trips:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "status": {"type": "string"},
                "owner_bot": {"type": "string"},
                "tag": {"type": "string"},
                "upcoming": {"type": "boolean"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "get_trip",
        "description": (
            "One trip, plus vault pages, shopping items, and handoffs linked by trip_id or by a matching tag. "
            "Requires trips:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"trip_id": {"type": "integer"}},
            "required": ["trip_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "add_contact",
        "description": (
            "Save a contact by hand. Nothing is imported. phones, emails, org, notes, and tags are optional. "
            "Requires calls:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "phones": {"type": "array", "items": {"type": "string"}},
                "emails": {"type": "array", "items": {"type": "string"}},
                "org": {"type": "string"},
                "notes": {"type": "string"},
                "tags": {"type": "array", "items": {"type": "string"}},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_contact",
        "description": "Change a contact saved by hand. Requires calls:write.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "contact_id": {"type": "integer"},
                "name": {"type": "string"},
                "phones": {"type": "array", "items": {"type": "string"}},
                "emails": {"type": "array", "items": {"type": "string"}},
                "org": {"type": ["string", "null"]},
                "notes": {"type": ["string", "null"]},
                "tags": {"type": "array", "items": {"type": "string"}},
            },
            "required": ["contact_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "find_contacts",
        "description": "Find contacts by name, phone, email, org, notes, or tag. Requires calls:read.",
        "inputSchema": {
            "type": "object",
            "properties": {"q": {"type": "string"}},
            "required": ["q"],
            "additionalProperties": False,
        },
    },
    {
        "name": "add_video",
        "description": (
            "Add a video to the slate. status is idea, scripted, voiced, edited, scheduled, or published. "
            "Requires videos:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "channel": {"type": "string"},
                "title": {"type": "string"},
                "status": {"type": "string"},
                "target_date": {"type": ["string", "null"]},
                "publish_url": {"type": ["string", "null"]},
                "notes": {"type": "string"},
                "tags": {"type": "array", "items": {"type": "string"}},
            },
            "required": ["channel", "title"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_video",
        "description": "Change a video on the slate. Requires videos:write.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "video_id": {"type": "integer"},
                "channel": {"type": "string"},
                "title": {"type": "string"},
                "status": {"type": "string"},
                "target_date": {"type": ["string", "null"]},
                "publish_url": {"type": ["string", "null"]},
                "notes": {"type": "string"},
                "tags": {"type": "array", "items": {"type": "string"}},
            },
            "required": ["video_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_videos",
        "description": "Videos on the slate. Filter by channel, status, or tag. Requires videos:read.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "channel": {"type": "string"},
                "status": {"type": "string"},
                "tag": {"type": "string"},
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "log_finding",
        "description": (
            "Store a research finding. source_bot defaults to this key. "
            "append true adds a dated line to the vault page named by page_id or page_title. "
            "Requires findings:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "topic": {"type": "string"},
                "source_bot": {"type": "string"},
                "title": {"type": "string"},
                "summary": {"type": "string"},
                "url": {"type": ["string", "null"]},
                "found_at": {"type": "string"},
                "tags": {"type": "array", "items": {"type": "string"}},
                "page_id": {"type": ["integer", "null"]},
                "page_title": {"type": "string"},
                "append": {"type": "boolean"},
            },
            "required": ["topic", "title"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_findings",
        "description": "Research findings. Filter by topic, source_bot, tag, or since (YYYY-MM-DD). Requires findings:read.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "topic": {"type": "string"},
                "source_bot": {"type": "string"},
                "tag": {"type": "string"},
                "since": {"type": "string"},
            },
            "additionalProperties": False,
        },
    },
]


def resolve_board(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "publish_digest":
        return "POST", "/api/v1/digests", _digest(args)
    if name == "create_trip":
        return "POST", "/api/v1/trips", _trip(args, creating=True)
    if name == "update_trip":
        trip_id = _id(args.get("trip_id"), "trip_id")
        return "POST", f"/api/v1/trips/{trip_id}", _trip(args, creating=False)
    if name == "list_trips":
        return "GET", "/api/v1/trips" + _trip_query(args), None
    if name == "get_trip":
        if set(args) != {"trip_id"}:
            raise ValueError("get_trip accepts trip_id.")
        return "GET", f"/api/v1/trips/{_id(args.get('trip_id'), 'trip_id')}", None
    if name == "add_contact":
        return "POST", "/api/v1/calls/contacts/add", _contact(args, creating=True)
    if name == "update_contact":
        contact_id = _id(args.get("contact_id"), "contact_id")
        return "POST", f"/api/v1/calls/contacts/{contact_id}", _contact(args, creating=False)
    if name == "find_contacts":
        if set(args) != {"q"}:
            raise ValueError("find_contacts accepts q.")
        q = args.get("q")
        if not isinstance(q, str) or len(q.strip()) < 2:
            raise ValueError("q must be text.")
        return "GET", "/api/v1/calls/contacts/find?" + urlencode({"q": q.strip()}), None
    if name == "add_video":
        return "POST", "/api/v1/videos", _video(args, creating=True)
    if name == "update_video":
        video_id = _id(args.get("video_id"), "video_id")
        return "POST", f"/api/v1/videos/{video_id}", _video(args, creating=False)
    if name == "list_videos":
        return "GET", "/api/v1/videos" + _video_query(args), None
    if name == "log_finding":
        return "POST", "/api/v1/findings", _finding(args)
    if name == "list_findings":
        return "GET", "/api/v1/findings" + _finding_query(args), None
    return None


def _digest(args: dict[str, Any]) -> dict[str, Any]:
    unknown = set(args) - {"source", "title", "date", "items", "page_id"}
    if unknown:
        raise ValueError("publish_digest accepts source, title, date, items, and page_id.")
    body: dict[str, Any] = {
        "source": _text(args.get("source"), "source", 80, required=True),
        "title": _text(args.get("title"), "title", 200, required=True),
        "date": _date(args.get("date"), "date"),
        "items": _headlines(args.get("items")),
    }
    if "page_id" in args and args.get("page_id") is not None:
        body["page_id"] = _id(args.get("page_id"), "page_id")
    return body


def _trip(args: dict[str, Any], creating: bool) -> dict[str, Any]:
    allowed = {
        "name", "destination", "start_date", "end_date", "status", "owner_bot", "tags", "notes",
        "template", "page_ids", "shopping_item_ids", "handoff_ids",
    }
    if not creating:
        allowed.add("trip_id")
    unknown = set(args) - allowed
    if unknown:
        raise ValueError("Unexpected trip field.")
    body: dict[str, Any] = {}
    if creating or "name" in args:
        body["name"] = _text(args.get("name"), "name", 200, required=True)
    for key, limit in (("destination", 200), ("owner_bot", 80), ("notes", 8000)):
        if key in args:
            body[key] = _text(args.get(key), key, limit, required=False) if args.get(key) not in (None, "") else None
            if key == "notes" and body[key] is None:
                body[key] = ""
    for key in ("start_date", "end_date"):
        if key in args and args.get(key) not in (None, ""):
            body[key] = _date(args.get(key), key)
        elif key in args:
            body[key] = None
    if "status" in args and args.get("status") not in (None, ""):
        status = args.get("status")
        if status not in _TRIP_STATUS:
            raise ValueError("status must be idea, planning, booked, or done.")
        body["status"] = status
    if "tags" in args:
        body["tags"] = _strings(args.get("tags"), "tags", 12, 32)
    if "template" in args:
        if not isinstance(args.get("template"), bool):
            raise ValueError("template must be true or false.")
        body["template"] = args["template"]
    for key in ("page_ids", "shopping_item_ids", "handoff_ids"):
        if key in args:
            body[key] = _ids(args.get(key), key)
    return body


def _trip_query(args: dict[str, Any]) -> str:
    unknown = set(args) - {"status", "owner_bot", "tag", "upcoming"}
    if unknown:
        raise ValueError("list_trips accepts status, owner_bot, tag, and upcoming.")
    query: dict[str, str] = {}
    if args.get("status") not in (None, ""):
        if args.get("status") not in _TRIP_STATUS:
            raise ValueError("status must be idea, planning, booked, or done.")
        query["status"] = str(args["status"])
    if args.get("owner_bot") not in (None, ""):
        query["owner_bot"] = _text(args.get("owner_bot"), "owner_bot", 80, required=True)
    if args.get("tag") not in (None, ""):
        query["tag"] = _text(args.get("tag"), "tag", 32, required=True)
    if "upcoming" in args:
        if not isinstance(args.get("upcoming"), bool):
            raise ValueError("upcoming must be true or false.")
        if args["upcoming"]:
            query["upcoming"] = "1"
    return ("?" + urlencode(query)) if query else ""


def _contact(args: dict[str, Any], creating: bool) -> dict[str, Any]:
    allowed = {"name", "phones", "emails", "org", "notes", "tags"}
    if not creating:
        allowed.add("contact_id")
    unknown = set(args) - allowed
    if unknown:
        raise ValueError("Unexpected contact field.")
    body: dict[str, Any] = {}
    if creating or "name" in args:
        body["name"] = _text(args.get("name"), "name", 160, required=creating or "name" in args)
    for key in ("phones", "emails", "tags"):
        if key in args:
            body[key] = _strings(args.get(key), key, 12, 160)
    for key, limit in (("org", 160), ("notes", 4000)):
        if key in args:
            body[key] = None if args.get(key) in (None, "") else _text(args.get(key), key, limit, required=False)
    return body


def _video(args: dict[str, Any], creating: bool) -> dict[str, Any]:
    allowed = {"channel", "title", "status", "target_date", "publish_url", "notes", "tags"}
    if not creating:
        allowed.add("video_id")
    unknown = set(args) - allowed
    if unknown:
        raise ValueError("Unexpected video field.")
    body: dict[str, Any] = {}
    if creating or "channel" in args:
        body["channel"] = _text(args.get("channel"), "channel", 120, required=True)
    if creating or "title" in args:
        body["title"] = _text(args.get("title"), "title", 200, required=True)
    if "status" in args and args.get("status") not in (None, ""):
        if args.get("status") not in _VIDEO_STATUS:
            raise ValueError("status must be idea, scripted, voiced, edited, scheduled, or published.")
        body["status"] = args["status"]
    if "target_date" in args and args.get("target_date") not in (None, ""):
        body["target_date"] = _date(args.get("target_date"), "target_date")
    elif "target_date" in args:
        body["target_date"] = None
    if "publish_url" in args:
        body["publish_url"] = None if args.get("publish_url") in (None, "") else _text(args.get("publish_url"), "publish_url", 500, required=False)
    if "notes" in args:
        body["notes"] = _text(args.get("notes"), "notes", 8000, required=False)
    if "tags" in args:
        body["tags"] = _strings(args.get("tags"), "tags", 12, 32)
    return body


def _video_query(args: dict[str, Any]) -> str:
    unknown = set(args) - {"channel", "status", "tag"}
    if unknown:
        raise ValueError("list_videos accepts channel, status, and tag.")
    query: dict[str, str] = {}
    if args.get("channel") not in (None, ""):
        query["channel"] = _text(args.get("channel"), "channel", 120, required=True)
    if args.get("status") not in (None, ""):
        if args.get("status") not in _VIDEO_STATUS:
            raise ValueError("status must be idea, scripted, voiced, edited, scheduled, or published.")
        query["status"] = str(args["status"])
    if args.get("tag") not in (None, ""):
        query["tag"] = _text(args.get("tag"), "tag", 32, required=True)
    return ("?" + urlencode(query)) if query else ""


def _finding(args: dict[str, Any]) -> dict[str, Any]:
    unknown = set(args) - {"topic", "source_bot", "title", "summary", "url", "found_at", "tags", "page_id", "page_title", "append"}
    if unknown:
        raise ValueError("log_finding accepts topic, source_bot, title, summary, url, found_at, tags, page_id, page_title, and append.")
    body: dict[str, Any] = {
        "topic": _text(args.get("topic"), "topic", 120, required=True),
        "title": _text(args.get("title"), "title", 200, required=True),
    }
    if "source_bot" in args and args.get("source_bot") not in (None, ""):
        body["source_bot"] = _text(args.get("source_bot"), "source_bot", 80, required=True)
    if "summary" in args:
        body["summary"] = _text(args.get("summary"), "summary", 4000, required=False)
    if "url" in args and args.get("url") not in (None, ""):
        body["url"] = _text(args.get("url"), "url", 500, required=True)
    if "found_at" in args and args.get("found_at") not in (None, ""):
        body["found_at"] = _text(args.get("found_at"), "found_at", 40, required=True)
    if "tags" in args:
        body["tags"] = _strings(args.get("tags"), "tags", 12, 32)
    if "page_id" in args and args.get("page_id") is not None:
        body["page_id"] = _id(args.get("page_id"), "page_id")
    if "page_title" in args and args.get("page_title") not in (None, ""):
        body["page_title"] = _text(args.get("page_title"), "page_title", 200, required=True)
    if "append" in args:
        if not isinstance(args.get("append"), bool):
            raise ValueError("append must be true or false.")
        body["append"] = args["append"]
    return body


def _finding_query(args: dict[str, Any]) -> str:
    unknown = set(args) - {"topic", "source_bot", "tag", "since"}
    if unknown:
        raise ValueError("list_findings accepts topic, source_bot, tag, and since.")
    query: dict[str, str] = {}
    if args.get("topic") not in (None, ""):
        query["topic"] = _text(args.get("topic"), "topic", 120, required=True)
    if args.get("source_bot") not in (None, ""):
        query["source_bot"] = _text(args.get("source_bot"), "source_bot", 80, required=True)
    if args.get("tag") not in (None, ""):
        query["tag"] = _text(args.get("tag"), "tag", 32, required=True)
    if args.get("since") not in (None, ""):
        query["since"] = _date(args.get("since"), "since")
    return ("?" + urlencode(query)) if query else ""


def _headlines(value: Any) -> list[Any]:
    if not isinstance(value, list) or value == []:
        raise ValueError("items must list the headlines.")
    if len(value) > 30:
        raise ValueError("items has too many headlines.")
    out: list[Any] = []
    for item in value:
        if isinstance(item, str):
            if item.strip() == "":
                continue
            if len(item) > 240:
                raise ValueError("A headline is too long.")
            out.append(item)
            continue
        if isinstance(item, dict):
            out.append(item)
            continue
        raise ValueError("Each headline must be text.")
    if out == []:
        raise ValueError("items must list the headlines.")
    return out


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


def _date(value: Any, field: str) -> str:
    if not isinstance(value, str) or _DATE.fullmatch(value) is None:
        raise ValueError(f"{field} must be YYYY-MM-DD.")
    return value


def _id(value: Any, field: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise ValueError(f"{field} must be an id.")
    return value


def _ids(value: Any, field: str) -> list[int]:
    if not isinstance(value, list) or len(value) > 40:
        raise ValueError(f"{field} must be a list of ids.")
    return [_id(item, field) for item in value]


def _strings(value: Any, field: str, limit: int, item_limit: int) -> list[str]:
    if not isinstance(value, list) or len(value) > limit:
        raise ValueError(f"{field} must be a list.")
    out: list[str] = []
    for item in value:
        if not isinstance(item, str) or len(item) > item_limit:
            raise ValueError(f"{field} entries must be text.")
        out.append(item)
    return out
