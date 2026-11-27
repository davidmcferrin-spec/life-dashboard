"""MCP tools for Wake Hub.

The dashboard calls Wake Hub's REST API. The bearer token stays in Settings
and is not an argument of these tools.
"""

from __future__ import annotations

from typing import Any

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}
_ACTIONS = ("on", "off", "cycle", "reboot", "shutdown")
_DESTRUCTIVE = frozenset({"off", "cycle", "reboot", "shutdown"})
_METHODS = ("auto", "wol", "pdu", "amt", "jetkvm", "plug", "ups", "roku")

WAKEHUB_TOOLS: list[dict[str, Any]] = [
    {
        "name": "wake_hub_list_devices",
        "description": (
            "Everything Wake Hub can control: Wake-on-LAN devices, AMT, PDUs and their outlets, plugs, JetKVM, UPS, and Roku. "
            "Each row has type, hub_id, and id. id is a composite such as amt:4 or pdu:1:3 so ids from different collections do not collide. "
            "A bare number is still the Wake-on-LAN device with that id. "
            "Requires wakehub:read. The error text is Wake Hub's own message when the hub answers. The token is not returned."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "wake_hub_device_status",
        "description": (
            "Read status for one Wake Hub target. device_id may be a number (a Wake-on-LAN device) or a composite id "
            "such as amt:4, pdu:1, pdu:1:3, plug:2, jetkvm:1, ups:1, or roku:5. "
            "Requires wakehub:read. The result includes sent, reachable, and elapsed_s when the hub sends them."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"device_id": {"type": ["integer", "string"]}},
            "required": ["device_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "wake_hub_wake",
        "description": (
            "Send Wake-on-LAN for one device and wait until it answers or the device timeout ends. "
            "device_id is a number or device:2. Other types use wake_hub_power_action with action on. "
            "The result includes sent, reachable, and elapsed_s. A client timeout after the request was sent says the packet may already have been sent. "
            "Requires wakehub:write. The call is written to the audit log with this key, the device, and the action."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {"device_id": {"type": ["integer", "string"]}},
            "required": ["device_id"],
            "additionalProperties": False,
        },
    },
    {
        "name": "wake_hub_power_action",
        "description": (
            "Power one Wake Hub target: on, off, cycle, reboot, or shutdown. "
            "device_id is a number for a Wake-on-LAN device, or a composite id: amt:4, pdu:1:3, plug:2, jetkvm:1, ups:1, roku:5. "
            "method (wol, pdu, amt, jetkvm, plug, ups, roku, or auto) applies to a device. Other types use their own hub endpoint. "
            "A Roku accepts on and off. A plug or PDU outlet accepts on, off, and cycle. "
            "off, cycle, reboot, and shutdown are refused unless confirm is true. "
            "The result includes sent, reachable, and elapsed_s when the hub sends them. "
            "Requires wakehub:write. The call is written to the audit log with this key, the device, and the action."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "device_id": {"type": ["integer", "string"]},
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


def _device_id(args: dict[str, Any], name: str) -> str:
    if name != "wake_hub_power_action":
        unknown = set(args) - {"device_id"}
        if unknown:
            raise ValueError(f"{name} accepts only device_id.")
    device_id = args.get("device_id")
    if isinstance(device_id, bool):
        raise ValueError("device_id must be a positive id or a target such as amt:4.")
    if isinstance(device_id, int) and device_id >= 1:
        return str(device_id)
    if isinstance(device_id, str):
        target = device_id.strip()
        if target != "" and "/" not in target and " " not in target:
            return target
    raise ValueError("device_id must be a positive id or a target such as amt:4 or pdu:1:3.")
