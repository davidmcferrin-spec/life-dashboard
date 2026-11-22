"""Habit progress tools and health logs stay on the one tool list."""

import pytest

from lifedash.mcp_server import TOOLS, resolve


def test_habit_status_tools_are_registered_once():
    names = [tool["name"] for tool in TOOLS]
    for name in (
        "increment_datapoint",
        "get_today_status",
        "set_habit_state",
        "toggle_habit",
        "log_meal",
        "list_meals",
        "log_blood_pressure",
        "list_blood_pressure",
        "add_medication",
        "update_medication",
        "list_medications",
        "log_medication_dose",
        "add_appointment",
        "update_appointment",
        "list_appointments",
    ):
        assert names.count(name) == 1
    described = {tool["name"]: tool["description"] for tool in TOOLS}
    assert "daily_target" in described["get_today_status"]
    assert "one write" in described["increment_datapoint"]
    assert "done_today" in described["list_habits"]
    assert "health:write" in described["log_meal"]
    assert "health:read" in described["list_blood_pressure"]


def test_habit_tools_resolve_onto_the_habits_api():
    assert resolve("get_today_status", {}) == ("GET", "/api/v1/habits/today", None)
    method, path, body = resolve("increment_datapoint", {"key": "water_glasses", "amount": 1})
    assert (method, path, body) == ("POST", "/api/v1/datapoints/increment", {"key": "water_glasses", "amount": 1})
    method, path, body = resolve("increment_datapoint", {"metric_id": 4, "date": "2026-11-22"})
    assert (method, path, body) == (
        "POST",
        "/api/v1/datapoints/increment",
        {"metric_id": 4, "date": "2026-11-22"},
    )
    method, path, body = resolve("set_habit_state", {"habit": 4, "done": True, "date": "2026-11-22"})
    assert (method, path, body) == ("POST", "/api/v1/habits/4/state", {"done": True, "date": "2026-11-22"})
    method, path, body = resolve("toggle_habit", {"metric_id": 4})
    assert (method, path, body) == ("POST", "/api/v1/habits/4/toggle", {})
    method, path, body = resolve("create_habit", {"name": "Water glasses", "key": "water_glasses", "daily_target": 8})
    assert body["daily_target"] == 8
    method, path, body = resolve("update_habit", {"metric_id": 4, "daily_target": 0})
    assert (method, path, body) == ("POST", "/api/v1/habits/4/update", {"daily_target": 0})


def test_health_log_tools_resolve_onto_health_routes():
    method, path, body = resolve("log_meal", {
        "date": "2026-11-22",
        "meal_type": "Breakfast",
        "description": "Eggs",
        "items": ["eggs"],
        "eaten_out": False,
        "place": "Home",
        "cost": 0,
    })
    assert (method, path) == ("POST", "/api/v1/health/meal-log")
    assert body["meal_type"] == "breakfast"
    assert body["eaten_out"] is False
    assert resolve("list_meals", {"from": "2026-11-01", "to": "2026-11-22"})[1] == (
        "/api/v1/health/meal-log?from=2026-11-01&to=2026-11-22"
    )
    method, path, body = resolve("log_blood_pressure", {"systolic": 120, "diastolic": 80, "pulse": 60, "arm": "left"})
    assert (method, path, body["systolic"]) == ("POST", "/api/v1/health/blood-pressure", 120)
    assert resolve("list_blood_pressure", {}) == ("GET", "/api/v1/health/blood-pressure", None)
    method, path, body = resolve("add_medication", {"name": "Lisinopril", "dose": "10 mg", "active": True})
    assert (method, path) == ("POST", "/api/v1/health/medications")
    assert body["name"] == "Lisinopril"
    method, path, body = resolve("update_medication", {"medication_id": 3, "active": False})
    assert (method, path, body) == ("POST", "/api/v1/health/medications/3/update", {"active": False})
    assert resolve("list_medications", {"active": True})[1] == "/api/v1/health/medications?active=1"
    method, path, body = resolve("log_medication_dose", {"medication_id": 3, "notes": "morning"})
    assert (method, path, body) == ("POST", "/api/v1/health/medications/3/doses", {"notes": "morning"})
    method, path, body = resolve("add_appointment", {
        "provider": "Dr Lee",
        "starts_at": "2026-11-23 15:00:00",
        "status": "scheduled",
    })
    assert (method, path) == ("POST", "/api/v1/health/appointments")
    assert body["provider"] == "Dr Lee"
    method, path, body = resolve("update_appointment", {"appointment_id": 9, "status": "completed"})
    assert (method, path, body) == ("POST", "/api/v1/health/appointments/9/update", {"status": "completed"})
    assert resolve("list_appointments", {"upcoming": True})[1] == "/api/v1/health/appointments?upcoming=1"
    assert resolve("health_log_meal", {"note": "eggs"})[1] == "/api/v1/health/meals"


def test_habit_and_health_tools_reject_bad_arguments():
    with pytest.raises(ValueError):
        resolve("get_today_status", {"date": "2026-11-22"})
    with pytest.raises(ValueError):
        resolve("increment_datapoint", {})
    with pytest.raises(ValueError):
        resolve("increment_datapoint", {"metric_id": 1, "amount": 0})
    with pytest.raises(ValueError):
        resolve("set_habit_state", {"metric_id": 1, "habit": 2, "done": True})
    with pytest.raises(ValueError):
        resolve("set_habit_state", {"metric_id": 1, "done": 1})
    with pytest.raises(ValueError):
        resolve("log_meal", {"meal_type": "brunch", "description": "Eggs"})
    with pytest.raises(ValueError):
        resolve("log_blood_pressure", {"systolic": 80, "diastolic": 120})
    with pytest.raises(ValueError):
        resolve("log_meal", {"meal_type": "lunch", "description": "Soup", "provider": "samsung"})
    with pytest.raises(ValueError):
        resolve("add_appointment", {"provider": "Dr Lee"})
