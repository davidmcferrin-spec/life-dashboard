"""MCP tools for the local Ollama service."""

from __future__ import annotations

import re
from typing import Any

from .ollama_plan import model_ok

OLLAMA_TOOLS: list[dict[str, Any]] = [
    {
        "name": "ollama_status",
        "description": "Local Ollama status: service, API, hardware-sized defaults, and whether a model pull is running.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "ollama_list_models",
        "description": "Models installed in the local Ollama service.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
    },
    {
        "name": "ollama_generate",
        "description": "Ask the local chat model. Uses the saved chat model when model is omitted. Does not change settings.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "prompt": {"type": "string"},
                "model": {"type": "string"},
            },
            "required": ["prompt"],
            "additionalProperties": False,
        },
    },
    {
        "name": "ollama_pull",
        "description": "Queue a model download on this machine. Needs Allow MCP updates.",
        "inputSchema": {
            "type": "object",
            "properties": {"model": {"type": "string"}},
            "required": ["model"],
            "additionalProperties": False,
        },
    },
    {
        "name": "ollama_remove",
        "description": "Queue removal of one installed model. Needs Allow MCP updates.",
        "inputSchema": {
            "type": "object",
            "properties": {"model": {"type": "string"}},
            "required": ["model"],
            "additionalProperties": False,
        },
    },
]


def resolve_ollama(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "ollama_status":
        if args:
            raise ValueError("ollama_status takes no arguments.")
        return "GET", "/api/v1/ollama/status", None
    if name == "ollama_list_models":
        if args:
            raise ValueError("ollama_list_models takes no arguments.")
        return "GET", "/api/v1/ollama/models", None
    if name == "ollama_generate":
        unknown = set(args) - {"prompt", "model"}
        if unknown:
            raise ValueError("ollama_generate accepts only prompt and model.")
        prompt = args.get("prompt")
        if not isinstance(prompt, str) or prompt.strip() == "":
            raise ValueError("prompt is required.")
        if len(prompt) > 4000:
            raise ValueError("prompt must be 4000 characters or less.")
        body: dict[str, Any] = {"prompt": prompt}
        model = args.get("model")
        if model not in (None, ""):
            if not isinstance(model, str) or not model_ok(model):
                raise ValueError("model name is not valid.")
            body["model"] = model
        return "POST", "/api/v1/ollama/generate", body
    if name in ("ollama_pull", "ollama_remove"):
        if set(args) != {"model"}:
            raise ValueError(f"{name} accepts only model.")
        model = args.get("model")
        if not isinstance(model, str) or not model_ok(model):
            raise ValueError("model name is not valid.")
        action = "pull" if name == "ollama_pull" else "remove"
        return "POST", f"/api/v1/ollama/{action}", {"model": model}
    if name.startswith("ollama_"):
        raise ValueError(f"Unknown tool {name}")
    return None


def listen_public(text: str) -> bool:
    """True when ss output shows Ollama bound somewhere other than loopback."""
    for line in text.splitlines():
        if "11434" not in line:
            continue
        if re.search(r"(0\.0\.0\.0|\[::\]|\*):11434", line):
            return True
    return False
