"""MCP tools for Hermes, OpenCode, and OpenClaw.

The Hermes API key stays in the gateway env file. These tools never take a
shell command. OpenCode is status only: its CLI is a coding agent.
"""

from __future__ import annotations

import re
from typing import Any

_ID = re.compile(r"^[A-Za-z0-9_-]{1,80}$")
_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}

AGENT_TOOLS: list[dict[str, Any]] = [
    {
        "name": "hermes_status",
        "description": (
            "Hermes install and gateway health: binary path, version, and whether 127.0.0.1:8642 answers. "
            "Does not return the gateway key."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "hermes_list_sessions",
        "description": "Hermes sessions on the loopback gateway. Ids and titles only.",
        "inputSchema": _EMPTY,
    },
    {
        "name": "hermes_start_session",
        "description": "Open a Hermes session on the loopback gateway. title is optional.",
        "inputSchema": {
            "type": "object",
            "properties": {"title": {"type": "string"}},
            "additionalProperties": False,
        },
    },
    {
        "name": "hermes_send",
        "description": (
            "Start one Hermes turn and return its run id. Poll hermes_poll for the reply. "
            "session_id is optional. This does not run a shell command."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "message": {"type": "string"},
                "session_id": {"type": "string"},
            },
            "required": ["message"],
            "additionalProperties": False,
        },
    },
    {
        "name": "hermes_poll",
        "description": "Read one Hermes turn started by hermes_send. Does not run a shell command.",
        "inputSchema": {
            "type": "object",
            "properties": {"run_id": {"type": "string"}},
            "required": ["run_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "hermes_stop",
        "description": (
            "Ask the Hermes gateway to stop one turn started by hermes_send. "
            "If that gateway has no cancel route, supported is false. Does not run a shell command. "
            "Needs an agents grant on this key."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"run_id": {"type": "string"}},
            "required": ["run_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "opencode_status",
        "description": (
            "OpenCode install: binary path, version, and the loopback Ollama model. "
            "There is no run tool. The CLI can edit files, so MCP does not start it."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "openclaw_status",
        "description": (
            "OpenClaw CLI install: binary path, version, and the loopback Ollama model. "
            "The gateway daemon is not started. Channels are not enabled."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "openclaw_ask",
        "description": (
            "Ask the local lifedash-coder model through the OpenClaw CLI. "
            "prompt is text. This does not take a shell command, a model override, or extra flags."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"prompt": {"type": "string"}},
            "required": ["prompt"],
            "additionalProperties": False,
        },
    },
]


def resolve_agent(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "hermes_status":
        _empty(name, args)
        return "GET", "/api/v1/agents/hermes/status", None
    if name == "hermes_list_sessions":
        _empty(name, args)
        return "GET", "/api/v1/agents/hermes/sessions", None
    if name == "hermes_start_session":
        unknown = set(args) - {"title"}
        if unknown:
            raise ValueError("hermes_start_session accepts only title.")
        body: dict[str, Any] = {}
        title = args.get("title")
        if title not in (None, ""):
            if not isinstance(title, str) or len(title) > 80 or "\n" in title or "\r" in title:
                raise ValueError("title must be 80 characters or less, on one line.")
            body["title"] = title
        return "POST", "/api/v1/agents/hermes/sessions", body
    if name == "hermes_send":
        unknown = set(args) - {"message", "session_id"}
        if unknown:
            raise ValueError("hermes_send accepts only message and session_id.")
        message = args.get("message")
        if not isinstance(message, str) or message.strip() == "":
            raise ValueError("message is required.")
        if len(message) > 8000:
            raise ValueError("message must be 8000 characters or less.")
        body = {"message": message}
        session = args.get("session_id")
        if session not in (None, ""):
            if not isinstance(session, str) or _ID.fullmatch(session) is None:
                raise ValueError("session_id is not valid.")
            body["session_id"] = session
        return "POST", "/api/v1/agents/hermes/runs", body
    if name == "hermes_poll":
        if set(args) != {"run_id"}:
            raise ValueError("hermes_poll accepts only run_id.")
        run_id = args.get("run_id")
        if not isinstance(run_id, str) or _ID.fullmatch(run_id) is None:
            raise ValueError("run_id is not valid.")
        return "GET", f"/api/v1/agents/hermes/runs/{run_id}", None
    if name == "hermes_stop":
        if set(args) != {"run_id"}:
            raise ValueError("hermes_stop accepts only run_id.")
        run_id = args.get("run_id")
        if not isinstance(run_id, str) or _ID.fullmatch(run_id) is None:
            raise ValueError("run_id is not valid.")
        return "POST", f"/api/v1/agents/hermes/runs/{run_id}/stop", {}
    if name == "opencode_status":
        _empty(name, args)
        return "GET", "/api/v1/agents/opencode/status", None
    if name == "openclaw_status":
        _empty(name, args)
        return "GET", "/api/v1/agents/openclaw/status", None
    if name == "openclaw_ask":
        if set(args) != {"prompt"}:
            raise ValueError("openclaw_ask accepts only prompt.")
        prompt = args.get("prompt")
        if not isinstance(prompt, str) or prompt.strip() == "":
            raise ValueError("prompt is required.")
        if len(prompt) > 4000:
            raise ValueError("prompt must be 4000 characters or less.")
        return "POST", "/api/v1/agents/openclaw/ask", {"prompt": prompt}
    if name.startswith(("hermes_", "opencode_", "openclaw_")):
        raise ValueError(f"Unknown tool {name}")
    return None


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")
