"""Host tools stay on the one public tool list and the system:read API."""

from lifedash.host_tools import HOST_TOOLS
from lifedash.mcp_server import TOOLS, resolve


def test_host_tools_are_registered():
    names = [tool["name"] for tool in TOOLS]
    expected = [tool["name"] for tool in HOST_TOOLS]
    for name in expected:
        assert names.count(name) == 1
    described = {tool["name"]: tool["description"] for tool in TOOLS if tool["name"] in expected}
    for name in expected:
        assert "system:read" in described[name]
    assert "UPS" in described["get_host_status"]
    assert "null" in described["get_os_updates"]


def test_host_tools_resolve_onto_the_system_api():
    assert resolve("get_host_status", {}) == ("GET", "/api/v1/system/host", None)
    assert resolve("list_host_metrics", {}) == ("GET", "/api/v1/system/host/metrics", None)
    assert resolve("get_os_updates", {}) == ("GET", "/api/v1/system/host/updates", None)
    assert resolve("get_tunnel_status", {}) == ("GET", "/api/v1/system/host/tunnel", None)
    assert resolve("get_host_history", {"metric": "temp.c", "subject": "k10temp:Tctl", "range": "7d"}) == (
        "GET",
        "/api/v1/system/host/history?metric=temp.c&subject=k10temp%3ATctl&range=7d",
        None,
    )


def test_host_tools_reject_extra_fields():
    for name in ("get_host_status", "list_host_metrics", "get_os_updates", "get_tunnel_status"):
        try:
            resolve(name, {"write": True})
        except ValueError:
            continue
        raise AssertionError(name)
    try:
        resolve("get_host_history", {})
    except ValueError:
        return
    raise AssertionError("metric is required")
