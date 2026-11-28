"""MCP tools for the machine this dashboard runs on.

Power and voltage are whatever RAPL, amdgpu, and hwmon expose. A missing
sensor stays null. The UPS is not a source. Requires system:read.
"""

from __future__ import annotations

import re
from typing import Any
from urllib.parse import urlencode

_EMPTY = {"type": "object", "properties": {}, "additionalProperties": False}
_RANGES = ("6h", "24h", "7d", "30d", "90d")
_METRIC = re.compile(r"^[a-z][a-z0-9.]{0,63}$")


HOST_TOOLS: list[dict[str, Any]] = [
    {
        "name": "get_host_status",
        "description": (
            "Current readings from the machine the dashboard runs on: CPU, GPU, temperatures, "
            "disks, memory, power, voltage, network, processes, failed units, reboot-required, "
            "pending OS updates, and the Cloudflare tunnel. Power and voltage come from RAPL, "
            "amdgpu, and hwmon on this host, never from the UPS. A sensor the hardware does not "
            "expose is null. Requires system:read."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "get_host_history",
        "description": (
            "Stored host samples. metric is a name from list_host_metrics. subject is the sensor, "
            "core, disk, mount, interface, or hostname when that metric has more than one, or empty. "
            "range is 6h, 24h, 7d, 30d, or 90d. Longer ranges are averaged. Requires system:read."
        ),
        "inputSchema": {
            "type": "object",
            "properties": {
                "metric": {"type": "string"},
                "subject": {"type": "string"},
                "range": {"type": "string", "enum": list(_RANGES)},
            },
            "required": ["metric"],
            "additionalProperties": False,
        },
    },
    {
        "name": "list_host_metrics",
        "description": (
            "Metric names the host history chart can draw, with the unit and the subjects that "
            "have a live reading or a stored sample. Requires system:read."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "get_os_updates",
        "description": (
            "Pending apt packages from a simulated upgrade. security is true when the pocket is "
            "security or esm. checked is false when apt could not be read, and the count is then "
            "null. Requires system:read."
        ),
        "inputSchema": _EMPTY,
    },
    {
        "name": "get_tunnel_status",
        "description": (
            "Cloudflare tunnel on this host: cloudflared service and process, connector connections "
            "from the metrics URL, and HTTP status plus latency for each saved public hostname. "
            "alert is up, degraded, down, or unknown. Requires system:read."
        ),
        "inputSchema": _EMPTY,
    },
]


def resolve_host(name: str, args: dict[str, Any]) -> tuple[str, str, dict[str, Any] | None] | None:
    if name == "get_host_status":
        _empty(name, args)
        return "GET", "/api/v1/system/host", None
    if name == "list_host_metrics":
        _empty(name, args)
        return "GET", "/api/v1/system/host/metrics", None
    if name == "get_os_updates":
        _empty(name, args)
        return "GET", "/api/v1/system/host/updates", None
    if name == "get_tunnel_status":
        _empty(name, args)
        return "GET", "/api/v1/system/host/tunnel", None
    if name == "get_host_history":
        unknown = set(args) - {"metric", "subject", "range"}
        if unknown:
            raise ValueError("get_host_history accepts only metric, subject, and range.")
        metric = args.get("metric")
        if not isinstance(metric, str) or _METRIC.fullmatch(metric) is None:
            raise ValueError("metric must be a host metric name.")
        query = {"metric": metric}
        if "subject" in args and args["subject"] not in (None, ""):
            subject = args["subject"]
            if not isinstance(subject, str) or len(subject) > 160 or any(ord(ch) < 33 or ch in "\\\"'" for ch in subject):
                raise ValueError("subject must be a sensor, disk, interface, or hostname.")
            query["subject"] = subject
        if "range" in args and args["range"] not in (None, ""):
            rng = args["range"]
            if not isinstance(rng, str) or rng not in _RANGES:
                raise ValueError("range must be 6h, 24h, 7d, 30d, or 90d.")
            query["range"] = rng
        return "GET", "/api/v1/system/host/history?" + urlencode(query), None
    return None


def _empty(name: str, args: dict[str, Any]) -> None:
    if args:
        raise ValueError(f"{name} takes no arguments.")
