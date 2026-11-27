from lifedash.mcp_server import TOOLS, oauth_scopes, resolve
from lifedash.wakehub_tools import resolve_wakehub


def test_wakehub_tools_are_registered():
    names = [tool["name"] for tool in TOOLS]
    expected = [
        "wake_hub_list_devices",
        "wake_hub_device_status",
        "wake_hub_wake",
        "wake_hub_power_action",
    ]
    for name in expected:
        assert name in names
    described = {tool["name"]: tool["description"] for tool in TOOLS if tool["name"] in expected}
    assert "wakehub:read" in described["wake_hub_list_devices"]
    assert "wakehub:read" in described["wake_hub_device_status"]
    assert "wakehub:write" in described["wake_hub_wake"]
    assert "confirm is true" in described["wake_hub_power_action"]
    assert "wakehub:read" in oauth_scopes()
    assert "wakehub:write" in oauth_scopes()


def test_wakehub_tools_resolve_to_rest():
    assert resolve("wake_hub_list_devices", {}) == ("GET", "/api/v1/wakehub/devices", None)
    assert resolve("wake_hub_device_status", {"device_id": 4}) == ("GET", "/api/v1/wakehub/devices/4/status", None)
    assert resolve("wake_hub_wake", {"device_id": 4}) == ("POST", "/api/v1/wakehub/devices/4/wake", {})
    method, path, body = resolve("wake_hub_power_action", {"device_id": 4, "action": "on", "confirm": False, "method": "plug"})
    assert (method, path, body) == ("POST", "/api/v1/wakehub/devices/4/power", {"action": "on", "confirm": False, "method": "plug"})


def test_composite_ids_keep_numeric_device_ids():
    assert resolve("wake_hub_device_status", {"device_id": "amt:4"}) == ("GET", "/api/v1/wakehub/devices/amt:4/status", None)
    assert resolve("wake_hub_device_status", {"device_id": 2}) == ("GET", "/api/v1/wakehub/devices/2/status", None)
    method, path, body = resolve("wake_hub_power_action", {"device_id": "pdu:1:3", "action": "off", "confirm": True})
    assert (method, path) == ("POST", "/api/v1/wakehub/devices/pdu:1:3/power")
    assert body["confirm"] is True
    method, path, body = resolve("wake_hub_power_action", {"device_id": "roku:6", "action": "on", "confirm": False})
    assert path == "/api/v1/wakehub/devices/roku:6/power"
    assert body["action"] == "on"


def test_destructive_power_is_refused_without_confirm():
    for action in ("off", "cycle", "reboot", "shutdown"):
        try:
            resolve_wakehub("wake_hub_power_action", {"device_id": 4, "action": action, "confirm": False})
        except ValueError as exc:
            assert "confirm" in str(exc)
        else:
            raise AssertionError(f"{action} was sent without confirm")
    try:
        resolve("wake_hub_power_action", {"device_id": 4, "action": "reboot", "confirm": False})
    except ValueError as exc:
        assert "confirm" in str(exc)
    else:
        raise AssertionError("reboot was sent")
    method, path, body = resolve("wake_hub_power_action", {"device_id": 4, "action": "shutdown", "confirm": True})
    assert path == "/api/v1/wakehub/devices/4/power"
    assert body["confirm"] is True
    assert body["action"] == "shutdown"
