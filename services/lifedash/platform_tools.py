"""MCP tools for grants, the morning brief, the link graph, and health.

Health device data is read from Open Wearables. These tools do not call Samsung.
Money tools stay proposal-only. This module does not add a transfer.
"""

from __future__ import annotations

from typing import Any
from urllib.parse import urlencode

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}
_DAY = {"type": "string"}

PLATFORM_TOOLS: list[dict[str, Any]] = [
    {
        "name": "grants_status",
        "description": (
            "Show this key's sector grants: money, home, mail, agents, and health. "
            "Each is deny, once, or always. Deny is the default. This does not change a grant."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "brief_me",
        "description": (
            "Write a short morning brief from stored digests, payday pressure, Home Assistant when granted, "
            "Spotify when connected, and health readiness when health data is stored. "
            "Calendar is included only when a calendar is connected. Missing facts are named, not invented. "
            "Money, health, and home sections are omitted with a needs-grant line when this key is deny. "
            "Nothing is transferred."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "brief_history",
        "description": "The last morning briefs stored on this server, newest first.",
        "inputSchema": _EMPTY,
    },
    {
        "name": "graph_query",
        "description": (
            "Search the Postgres link graph. q matches a label. "
            "Nodes are pages, bills, envelopes, digests, health days, and briefs. "
            "Bill and envelope nodes need a money grant. Health nodes need a health grant. "
            "Omitted kinds are listed. There is no Neo4j."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"q": {"type": "string"}, "limit": {"type": "integer"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "graph_neighbors",
        "description": (
            "One hop from a graph node. kind is page, bill, envelope, digest, health, or brief. ref is that row's id. "
            "Money and health nodes need the matching grant."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"kind": {"type": "string"}, "ref": {"type": "integer"}},
            "required": ["kind", "ref"],
            "additionalProperties": False,
        },
    },
    {
        "name": "graph_link",
        "description": "Add a manual edge between two graph nodes. Does not move money or write a vital.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "from_kind": {"type": "string"},
                "from_ref": {"type": "integer"},
                "to_kind": {"type": "string"},
                "to_ref": {"type": "integer"},
            },
            "required": ["from_kind", "from_ref", "to_kind", "to_ref"],
            "additionalProperties": False,
        },
    },
    {
        "name": "health_status",
        "description": (
            "Open Wearables connection (URL and user id, never the API key), last sync, and the latest stored day. "
            "Needs a health grant. Samsung stays behind Open Wearables."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "health_readiness",
        "description": (
            "Readiness score and confidence for one day, or the latest stored day. "
            "Score uses only stored sleep, HRV with a baseline, and steps. Missing inputs are not treated as zero. "
            "Needs a health grant."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"day": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "health_sleep",
        "description": "Stored sleep points between from and to (YYYY-MM-DD), with authority and provenance. Needs a health grant.",
        "inputSchema": {
            "type": "object",
            "properties": {"from": _DAY, "to": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "health_workouts",
        "description": "Stored workouts between from and to, including manual logs. Needs a health grant.",
        "inputSchema": {
            "type": "object",
            "properties": {"from": _DAY, "to": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "health_metrics",
        "description": (
            "Stored points for sleep, hrv, resting_hr, steps, body, or meal. "
            "Each point has authority and provenance. Needs a health grant."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"metric": {"type": "string"}, "from": _DAY, "to": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "health_sync",
        "description": (
            "Pull the last 7 days from Open Wearables into stored points. "
            "Samsung Health and Health Connect outrank Garmin, WHOOP, and Oura when a day has more than one source. "
            "Needs a health grant. Does not call Samsung."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "health_log_workout",
        "description": "Store a manual workout. title and minutes are required. day is optional. Needs a health grant. Does not call Samsung.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "title": {"type": "string"},
                "minutes": {"type": "number"},
                "day": _DAY,
                "note": {"type": "string"},
            },
            "required": ["title", "minutes"],
            "additionalProperties": False,
        },
    },
    {
        "name": "health_log_meal",
        "description": "Store a manual meal note. calories is optional. Needs a health grant. Does not call Samsung.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "note": {"type": "string"},
                "calories": {"type": "number"},
                "day": _DAY,
            },
            "required": ["note"],
            "additionalProperties": False,
        },
    },
    {
        "name": "health_log_body",
        "description": "Store a manual body weight in pounds. Needs a health grant. Does not call Samsung.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "weight_lb": {"type": "number"},
                "day": _DAY,
                "note": {"type": "string"},
            },
            "required": ["weight_lb"],
            "additionalProperties": False,
        },
    },
    {
        "name": "home_status",
        "description": "Read Home Assistant when a URL and token are saved. Needs a home grant. Does not invent states.",
        "inputSchema": _EMPTY,
    },
    {
        "name": "mail_status",
        "description": "Mail is not connected on this server. This reports that fact.",
        "inputSchema": _EMPTY,
    },
]


def resolve_platform(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "grants_status":
        _empty(name, args)
        return "GET", "/api/v1/grants/mine", None
    if name == "brief_me":
        _empty(name, args)
        return "POST", "/api/v1/brief", {}
    if name == "brief_history":
        _empty(name, args)
        return "GET", "/api/v1/brief/history", None
    if name == "graph_query":
        unknown = set(args) - {"q", "limit"}
        if unknown:
            raise ValueError("graph_query accepts only q and limit.")
        query: dict[str, Any] = {}
        if args.get("q") not in (None, ""):
            if not isinstance(args["q"], str):
                raise ValueError("q must be text.")
            query["q"] = args["q"]
        if args.get("limit") not in (None, ""):
            limit = args["limit"]
            if isinstance(limit, bool) or not isinstance(limit, int) or limit < 1 or limit > 50:
                raise ValueError("limit must be from 1 to 50.")
            query["limit"] = limit
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/graph" + suffix, None
    if name == "graph_neighbors":
        if set(args) - {"kind", "ref"}:
            raise ValueError("graph_neighbors accepts only kind and ref.")
        kind = _kind(args.get("kind"))
        ref = _ref(args.get("ref"))
        return "GET", "/api/v1/graph/neighbors?" + urlencode({"kind": kind, "ref": ref}), None
    if name == "graph_link":
        if set(args) != {"from_kind", "from_ref", "to_kind", "to_ref"}:
            raise ValueError("graph_link accepts only from_kind, from_ref, to_kind, and to_ref.")
        return "POST", "/api/v1/graph/edges", {
            "from_kind": _kind(args.get("from_kind")),
            "from_ref": _ref(args.get("from_ref")),
            "to_kind": _kind(args.get("to_kind")),
            "to_ref": _ref(args.get("to_ref")),
        }
    if name == "health_status":
        _empty(name, args)
        return "GET", "/api/v1/health/status", None
    if name == "health_readiness":
        _only(name, args, {"day"})
        return "GET", "/api/v1/health/readiness" + _days(args, ("day",)), None
    if name == "health_sleep":
        _only(name, args, {"from", "to"})
        return "GET", "/api/v1/health/sleep" + _days(args, ("from", "to")), None
    if name == "health_workouts":
        _only(name, args, {"from", "to"})
        return "GET", "/api/v1/health/workouts" + _days(args, ("from", "to")), None
    if name == "health_metrics":
        _only(name, args, {"metric", "from", "to"})
        metric = args.get("metric") or ""
        if metric not in ("", "sleep", "hrv", "resting_hr", "steps", "body", "meal"):
            raise ValueError("metric must be sleep, hrv, resting_hr, steps, body, or meal.")
        query = {}
        if metric:
            query["metric"] = metric
        for key in ("from", "to"):
            if args.get(key) not in (None, ""):
                query[key] = _day(args[key], key)
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/health/metrics" + suffix, None
    if name == "health_sync":
        _empty(name, args)
        return "POST", "/api/v1/health/sync", {}
    if name == "health_log_workout":
        _only(name, args, {"title", "minutes", "day", "note"})
        title = args.get("title")
        if not isinstance(title, str) or title.strip() == "":
            raise ValueError("title is required.")
        minutes = args.get("minutes")
        if isinstance(minutes, bool) or not isinstance(minutes, (int, float)) or minutes <= 0 or minutes > 1440:
            raise ValueError("minutes must be greater than 0 and at most 1440.")
        body: dict[str, Any] = {"title": title, "minutes": minutes}
        if args.get("day") not in (None, ""):
            body["day"] = _day(args["day"], "day")
        if args.get("note") not in (None, ""):
            if not isinstance(args["note"], str):
                raise ValueError("note must be text.")
            body["note"] = args["note"]
        return "POST", "/api/v1/health/workouts", body
    if name == "health_log_meal":
        _only(name, args, {"note", "calories", "day"})
        note = args.get("note")
        if not isinstance(note, str) or note.strip() == "":
            raise ValueError("note is required.")
        body = {"note": note}
        if args.get("calories") not in (None, ""):
            calories = args["calories"]
            if isinstance(calories, bool) or not isinstance(calories, (int, float)) or calories < 0 or calories > 20000:
                raise ValueError("calories must be from 0 to 20000.")
            body["calories"] = calories
        if args.get("day") not in (None, ""):
            body["day"] = _day(args["day"], "day")
        return "POST", "/api/v1/health/meals", body
    if name == "health_log_body":
        _only(name, args, {"weight_lb", "day", "note"})
        weight = args.get("weight_lb")
        if isinstance(weight, bool) or not isinstance(weight, (int, float)) or weight <= 0 or weight > 1500:
            raise ValueError("weight_lb must be a number of pounds.")
        body = {"weight_lb": weight}
        if args.get("day") not in (None, ""):
            body["day"] = _day(args["day"], "day")
        if args.get("note") not in (None, ""):
            if not isinstance(args["note"], str):
                raise ValueError("note must be text.")
            body["note"] = args["note"]
        return "POST", "/api/v1/health/body", body
    if name == "home_status":
        _empty(name, args)
        return "GET", "/api/v1/home/status", None
    if name == "mail_status":
        _empty(name, args)
        return "GET", "/api/v1/mail/status", None
    if name.startswith(("brief_", "graph_", "health_", "grants_", "home_", "mail_")):
        raise ValueError(f"Unknown tool {name}")
    return None


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")


def _only(name: str, args: dict[str, Any], allowed: set[str]) -> None:
    unknown = set(args) - allowed
    if unknown:
        raise ValueError(f"{name} does not accept {', '.join(sorted(unknown))}.")


def _kind(value: Any) -> str:
    if value not in ("page", "bill", "envelope", "digest", "health", "brief"):
        raise ValueError("kind must be page, bill, envelope, digest, health, or brief.")
    return str(value)


def _ref(value: Any) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise ValueError("ref must be a positive id.")
    return value


def _day(value: Any, field: str) -> str:
    if not isinstance(value, str) or len(value) != 10 or value[4] != "-" or value[7] != "-":
        raise ValueError(f"{field} must be YYYY-MM-DD.")
    return value


def _days(args: dict[str, Any], keys: tuple[str, ...]) -> str:
    query = {}
    for key in keys:
        if args.get(key) not in (None, ""):
            query[key] = _day(args[key], key)
    return ("?" + urlencode(query)) if query else ""
