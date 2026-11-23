"""MCP tools for the morning brief, the link graph, and health.

Health device data is read from Open Wearables. These tools do not call Samsung.
Money tools stay proposal-only. This module does not add a transfer.
A valid key can call every tool here. The act is still written to the audit log.
"""

from __future__ import annotations

from typing import Any
from urllib.parse import urlencode

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}
_DAY = {"type": "string"}

PLATFORM_TOOLS: list[dict[str, Any]] = [
    {
        "name": "brief_me",
        "description": (
            "Write a short morning brief from stored digests, payday pressure, Home Assistant when it is connected, "
            "Spotify when connected, and health readiness when health data is stored. "
            "Calendar is included only when a calendar is connected. Missing facts are named, not invented. "
            "Nothing is transferred. When the key has handoffs:read, the response also lists open handoffs addressed to that key."
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
            "There is no Neo4j."
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
            "One hop from a graph node. kind is page, bill, envelope, digest, health, or brief. ref is that row's id."
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
            "Samsung stays behind Open Wearables."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "health_readiness",
        "description": (
            "Readiness score and confidence for one day, or the latest stored day. "
            "Score uses only stored sleep, HRV with a baseline, and steps. Missing inputs are not treated as zero."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"day": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "health_sleep",
        "description": "Stored sleep points between from and to (YYYY-MM-DD), with authority and provenance.",
        "inputSchema": {
            "type": "object",
            "properties": {"from": _DAY, "to": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "health_workouts",
        "description": "Stored workouts between from and to, including manual logs.",
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
            "Each point has authority and provenance."
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
            "Does not call Samsung."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "health_log_workout",
        "description": "Store a manual workout. title and minutes are required. day is optional. Does not call Samsung.",
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
        "description": "Store a manual meal note. calories is optional. Does not call Samsung.",
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
        "description": "Store a manual body weight in pounds. Does not call Samsung.",
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
        "name": "log_meal",
        "description": (
            "Store what was eaten. meal_type is breakfast, lunch, dinner, snack, or other. "
            "items is an optional list of names or {name, quantity}. eaten_out defaults to false. "
            "place and cost are optional. A matching meal habit is checked, and an eat-at-home habit "
            "is checked only when eaten_out is false. Does not change the pantry. Uses health:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "date": _DAY,
                "meal_type": {"type": "string"},
                "description": {"type": "string"},
                "items": {"type": "array"},
                "eaten_out": {"type": "boolean"},
                "place": {"type": "string"},
                "cost": {"type": "number"},
            },
            "required": ["meal_type", "description"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_meals",
        "description": "Meals logged between from and to (YYYY-MM-DD). Omit both for the last 14 days. Uses health:read.",
        "inputSchema": {
            "type": "object",
            "properties": {"from": _DAY, "to": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "log_blood_pressure",
        "description": (
            "Store a blood pressure reading. systolic and diastolic are required whole numbers, systolic higher. "
            "pulse, taken_at, arm (left or right), position (sitting, standing, or lying), and notes are optional. "
            "taken_at defaults to now. Uses health:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "systolic": {"type": "integer"},
                "diastolic": {"type": "integer"},
                "pulse": {"type": "integer"},
                "taken_at": {"type": "string"},
                "arm": {"type": "string"},
                "position": {"type": "string"},
                "notes": {"type": "string"},
            },
            "required": ["systolic", "diastolic"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_blood_pressure",
        "description": (
            "Blood pressure readings between from and to, plus average systolic, diastolic, and pulse. "
            "Omit both dates for the last 30 days. Uses health:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"from": _DAY, "to": _DAY},
            "additionalProperties": False,
        },
    },
    {
        "name": "add_medication",
        "description": (
            "Add a medication. name is required. dose, schedule, active (default true), "
            "started_on, stopped_on, and notes are optional. Uses health:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "name": {"type": "string"},
                "dose": {"type": "string"},
                "schedule": {"type": "string"},
                "active": {"type": "boolean"},
                "started_on": _DAY,
                "stopped_on": _DAY,
                "notes": {"type": "string"},
            },
            "required": ["name"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_medication",
        "description": "Change a medication. Omitted fields stay. active false stops it from the active list. Uses health:write.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "medication_id": {"type": "integer"},
                "name": {"type": "string"},
                "dose": {"type": "string"},
                "schedule": {"type": "string"},
                "active": {"type": "boolean"},
                "started_on": _DAY,
                "stopped_on": _DAY,
                "notes": {"type": "string"},
            },
            "required": ["medication_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_medications",
        "description": "Medications for this user. active true returns only current ones. Omit active for all. Uses health:read.",
        "inputSchema": {
            "type": "object",
            "properties": {"active": {"type": "boolean"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "log_medication_dose",
        "description": "Record that a dose was taken. taken_at defaults to now. The medication must belong to this user. Uses health:write.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "medication_id": {"type": "integer"},
                "taken_at": {"type": "string"},
                "notes": {"type": "string"},
            },
            "required": ["medication_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "add_appointment",
        "description": (
            "Add an appointment. provider and starts_at (or datetime) are required. "
            "specialty, location, reason, status (scheduled, completed, cancelled, missed), and follow_up are optional. "
            "Uses health:write."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "provider": {"type": "string"},
                "specialty": {"type": "string"},
                "starts_at": {"type": "string"},
                "datetime": {"type": "string"},
                "location": {"type": "string"},
                "reason": {"type": "string"},
                "status": {"type": "string"},
                "follow_up": {"type": "string"},
            },
            "required": ["provider"],
            "additionalProperties": False,
        },
    },
    {
        "name": "update_appointment",
        "description": "Change an appointment. Omitted fields stay. status can be scheduled, completed, cancelled, or missed. Uses health:write.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "appointment_id": {"type": "integer"},
                "provider": {"type": "string"},
                "specialty": {"type": "string"},
                "starts_at": {"type": "string"},
                "datetime": {"type": "string"},
                "location": {"type": "string"},
                "reason": {"type": "string"},
                "status": {"type": "string"},
                "follow_up": {"type": "string"},
            },
            "required": ["appointment_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_appointments",
        "description": (
            "Appointments. upcoming true returns scheduled visits from now on. "
            "Otherwise from and to limit the day range. Uses health:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "upcoming": {"type": "boolean"},
                "from": _DAY,
                "to": _DAY,
            },
            "additionalProperties": False,
        },
    },
    {
        "name": "home_status",
        "description": "Read Home Assistant when a URL and token are saved. Does not invent states.",
        "inputSchema": _EMPTY,
    },
    {
        "name": "mail_status",
        "description": "Mail is not connected on this server. This reports that fact.",
        "inputSchema": _EMPTY,
    },
]


def resolve_platform(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
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
    if name == "log_meal":
        return "POST", "/api/v1/health/meal-log", _meal_log_body(args)
    if name == "list_meals":
        _only(name, args, {"from", "to"})
        return "GET", "/api/v1/health/meal-log" + _days(args, ("from", "to")), None
    if name == "log_blood_pressure":
        return "POST", "/api/v1/health/blood-pressure", _pressure_body(args)
    if name == "list_blood_pressure":
        _only(name, args, {"from", "to"})
        return "GET", "/api/v1/health/blood-pressure" + _days(args, ("from", "to")), None
    if name == "add_medication":
        return "POST", "/api/v1/health/medications", _medication_body(args, False)
    if name == "update_medication":
        med_id = _positive(args.get("medication_id"), "medication_id")
        return "POST", f"/api/v1/health/medications/{med_id}/update", _medication_body(args, True)
    if name == "list_medications":
        _only(name, args, {"active"})
        if "active" not in args or args["active"] is None:
            return "GET", "/api/v1/health/medications", None
        if not isinstance(args["active"], bool):
            raise ValueError("active must be true or false.")
        return "GET", "/api/v1/health/medications?" + urlencode({"active": "1" if args["active"] else "0"}), None
    if name == "log_medication_dose":
        _only(name, args, {"medication_id", "taken_at", "notes"})
        med_id = _positive(args.get("medication_id"), "medication_id")
        body: dict[str, Any] = {}
        if args.get("taken_at") not in (None, ""):
            if not isinstance(args["taken_at"], str):
                raise ValueError("taken_at must be a date and time.")
            body["taken_at"] = args["taken_at"]
        if args.get("notes") not in (None, ""):
            if not isinstance(args["notes"], str):
                raise ValueError("notes must be text.")
            body["notes"] = args["notes"]
        return "POST", f"/api/v1/health/medications/{med_id}/doses", body
    if name == "add_appointment":
        return "POST", "/api/v1/health/appointments", _appointment_body(args, False)
    if name == "update_appointment":
        appt_id = _positive(args.get("appointment_id"), "appointment_id")
        return "POST", f"/api/v1/health/appointments/{appt_id}/update", _appointment_body(args, True)
    if name == "list_appointments":
        _only(name, args, {"upcoming", "from", "to"})
        query: dict[str, Any] = {}
        if "upcoming" in args and args["upcoming"] is not None:
            if not isinstance(args["upcoming"], bool):
                raise ValueError("upcoming must be true or false.")
            if args["upcoming"]:
                query["upcoming"] = "1"
        for key in ("from", "to"):
            if args.get(key) not in (None, ""):
                query[key] = _day(args[key], key)
        suffix = ("?" + urlencode(query)) if query else ""
        return "GET", "/api/v1/health/appointments" + suffix, None
    if name == "home_status":
        _empty(name, args)
        return "GET", "/api/v1/home/status", None
    if name == "mail_status":
        _empty(name, args)
        return "GET", "/api/v1/mail/status", None
    if name.startswith(("brief_", "graph_", "health_", "home_", "mail_")):
        raise ValueError(f"Unknown tool {name}")
    return None


def _positive(value: Any, field: str) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < 1:
        raise ValueError(f"{field} must be a positive id.")
    return value


def _meal_log_body(args: dict[str, Any]) -> dict[str, Any]:
    _only("log_meal", args, {"date", "meal_type", "description", "items", "eaten_out", "place", "cost"})
    meal_type = args.get("meal_type")
    if not isinstance(meal_type, str) or meal_type.strip().lower() not in ("breakfast", "lunch", "dinner", "snack", "other"):
        raise ValueError("meal_type must be breakfast, lunch, dinner, snack, or other.")
    description = args.get("description")
    if not isinstance(description, str) or description.strip() == "":
        raise ValueError("description is required.")
    body: dict[str, Any] = {"meal_type": meal_type.strip().lower(), "description": description}
    if args.get("date") not in (None, ""):
        body["date"] = _day(args["date"], "date")
    if "items" in args and args["items"] is not None:
        if not isinstance(args["items"], list):
            raise ValueError("items must be a list.")
        body["items"] = args["items"]
    if "eaten_out" in args and args["eaten_out"] is not None:
        if not isinstance(args["eaten_out"], bool):
            raise ValueError("eaten_out must be true or false.")
        body["eaten_out"] = args["eaten_out"]
    if args.get("place") not in (None, ""):
        if not isinstance(args["place"], str):
            raise ValueError("place must be text.")
        body["place"] = args["place"]
    if args.get("cost") not in (None, ""):
        cost = args["cost"]
        if isinstance(cost, bool) or not isinstance(cost, (int, float)) or cost < 0 or cost > 100000:
            raise ValueError("cost must be from 0 to 100000.")
        body["cost"] = cost
    return body


def _pressure_body(args: dict[str, Any]) -> dict[str, Any]:
    _only("log_blood_pressure", args, {"systolic", "diastolic", "pulse", "taken_at", "arm", "position", "notes"})
    systolic = _whole(args.get("systolic"), "systolic", 50, 300)
    diastolic = _whole(args.get("diastolic"), "diastolic", 30, 200)
    if systolic <= diastolic:
        raise ValueError("systolic must be higher than diastolic.")
    body: dict[str, Any] = {"systolic": systolic, "diastolic": diastolic}
    if args.get("pulse") not in (None, ""):
        body["pulse"] = _whole(args.get("pulse"), "pulse", 20, 250)
    if args.get("taken_at") not in (None, ""):
        if not isinstance(args["taken_at"], str):
            raise ValueError("taken_at must be a date and time.")
        body["taken_at"] = args["taken_at"]
    if args.get("arm") not in (None, ""):
        if args["arm"] not in ("left", "right"):
            raise ValueError("arm must be left or right.")
        body["arm"] = args["arm"]
    if args.get("position") not in (None, ""):
        if args["position"] not in ("sitting", "standing", "lying"):
            raise ValueError("position must be sitting, standing, or lying.")
        body["position"] = args["position"]
    if args.get("notes") not in (None, ""):
        if not isinstance(args["notes"], str):
            raise ValueError("notes must be text.")
        body["notes"] = args["notes"]
    return body


def _medication_body(args: dict[str, Any], updating: bool) -> dict[str, Any]:
    allowed = {"name", "dose", "schedule", "active", "started_on", "stopped_on", "notes"}
    if updating:
        allowed.add("medication_id")
    _only("update_medication" if updating else "add_medication", args, allowed)
    body: dict[str, Any] = {}
    if not updating or "name" in args:
        name = args.get("name")
        if not isinstance(name, str) or name.strip() == "":
            raise ValueError("name is required.")
        body["name"] = name
    for key in ("dose", "schedule", "notes"):
        if key in args and args[key] is not None:
            if not isinstance(args[key], str):
                raise ValueError(f"{key} must be text.")
            body[key] = args[key]
    if "active" in args and args["active"] is not None:
        if not isinstance(args["active"], bool):
            raise ValueError("active must be true or false.")
        body["active"] = args["active"]
    for key in ("started_on", "stopped_on"):
        if args.get(key) not in (None, ""):
            body[key] = _day(args[key], key)
    return body


def _appointment_body(args: dict[str, Any], updating: bool) -> dict[str, Any]:
    allowed = {"provider", "specialty", "starts_at", "datetime", "location", "reason", "status", "follow_up"}
    if updating:
        allowed.add("appointment_id")
    _only("update_appointment" if updating else "add_appointment", args, allowed)
    body: dict[str, Any] = {}
    if not updating or "provider" in args:
        provider = args.get("provider")
        if not isinstance(provider, str) or provider.strip() == "":
            raise ValueError("provider is required.")
        body["provider"] = provider
    when = args.get("starts_at", args.get("datetime"))
    if not updating and when in (None, ""):
        raise ValueError("starts_at is required.")
    if when not in (None, ""):
        if not isinstance(when, str):
            raise ValueError("starts_at must be a date and time.")
        body["starts_at"] = when
    for key in ("specialty", "location", "reason", "follow_up"):
        if key in args and args[key] is not None:
            if not isinstance(args[key], str):
                raise ValueError(f"{key} must be text.")
            body[key] = args[key]
    if args.get("status") not in (None, ""):
        if args["status"] not in ("scheduled", "completed", "cancelled", "missed"):
            raise ValueError("status must be scheduled, completed, cancelled, or missed.")
        body["status"] = args["status"]
    return body


def _whole(value: Any, field: str, low: int, high: int) -> int:
    if isinstance(value, bool) or not isinstance(value, int) or value < low or value > high:
        raise ValueError(f"{field} must be a whole number from {low} to {high}.")
    return value


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
