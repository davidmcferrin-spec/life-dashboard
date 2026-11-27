"""MCP tools for Wake Hub.

The dashboard calls Wake Hub's REST API. The bearer token stays in Settings
and is not an argument of these tools.
"""

from __future__ import annotations

from typing import Any

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}
_ACTIONS = ("on", "off", "cycle", "reboot", "shutdown")
_DESTRUCTIVE = frozenset({"off", "cycle", "reboot", "shutdown"})
_METHODS = ("auto", "wol", "pdu", "amt", "jetkvm", "plug", "ups")

WAKEHUB_TOOLS: list[dict[str, Any]] = [
    {
        "name": "wake_hub_list_devices",
        "description": (
            "Machines saved in Wake Hub: name, host, whether each is enabled, and the last reachability probe. "
            "Requires wakehub:read. When Wake Hub is not configured, or the host does not answer, the error says so. "
            "The token is not returned."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "wake_hub_device_status",
        "description": (
            "Probe one Wake Hub device and return whether it answered. Requires wakehub:read. "
            "When Wake Hub is not configured, or the host does not answer, the error says so."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"device_id": {"type": "integer"}},
            "required": ["device_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "wake_hub_wake",
        "description": (
            "Send Wake-on-LAN for one device and wait until it answers or the device timeout ends. "
            "Requires wakehub:write. The call is written to the audit log with this key, the device, and the action."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"device_id": {"type": "integer"}},
            "required": ["device_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "wake_hub_power_action",
        "description": (
            "Power one Wake Hub device: on, off, cycle, reboot, or shutdown. "
            "method picks wol, pdu, amt, jetkvm, plug, or ups. Omit it for auto. "
            "off, cycle, reboot, and shutdown are refused unless confirm is true. "
            "Requires wakehub:write. The call is written to the audit log with this key, the device, and the action."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "device_id": {"type": "integer"},
                "action": {"type": "string", "enum": list(_ACTIONS)},
                "confirm": {"type": "boolean"},
                "method": {"type": "string", "enum": list(_METHODS)},
            },
            "required": ["device_id", "action", "confirm"],
            "additionalProperties": False,
        },
    },
]


def resolve_wakehub(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "wake_hub_list_devices":
        _empty(name, args)
        return "GET", "/api/v1/wakehub/devices", None
    if name == "wake_hub_device_status":
        device_id = _device_id(args, name)
        return "GET", f"/api/v1/wakehub/devices/{device_id}/status", None
    if name == "wake_hub_wake":
        device_id = _device_id(args, name)
        return "POST", f"/api/v1/wakehub/devices/{device_id}/wake", {}
    if name == "wake_hub_power_action":
        unknown = set(args) - {"device_id", "action", "confirm", "method"}
        if unknown:
            raise ValueError("wake_hub_power_action accepts only device_id, action, confirm, and method.")
        device_id = _device_id(args, name)
        action = args.get("action")
        if not isinstance(action, str) or action not in _ACTIONS:
            raise ValueError("action must be on, off, cycle, reboot, or shutdown.")
        if not isinstance(args.get("confirm"), bool):
            raise ValueError("confirm must be true or false.")
        if action in _DESTRUCTIVE and args["confirm"] is not True:
            raise ValueError(f"{action} can turn a machine off or cycle its power. Set confirm to true to send it.")
        body: dict[str, Any] = {"action": action, "confirm": args["confirm"]}
        if "method" in args:
            method = args["method"]
            if not isinstance(method, str) or method not in _METHODS:
                raise ValueError("method must be auto, wol, pdu, amt, jetkvm, plug, or ups.")
            body["method"] = method
        return "POST", f"/api/v1/wakehub/devices/{device_id}/power", body
    return None


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")


def _device_id(args: dict[str, Any], name: str) -> int:
    if name != "wake_hub_power_action":
        unknown = set(args) - {"device_id"}
        if unknown:
            raise ValueError(f"{name} accepts only device_id.")
    device_id = args.get("device_id")
    if isinstance(device_id, bool) or not isinstance(device_id, int) or device_id < 1:
        raise ValueError("device_id must be a positive id.")
    return device_id
