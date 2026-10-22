"""Spotify sync decisions. No network and no credentials."""

from datetime import datetime, timezone

import pytest

from lifedash.spotify_crypto import key_bytes, open_blob, seal
from lifedash.spotify_http import SpotifyHttp, SpotifyHttpError, error_message, scrub
from lifedash.spotify_plan import (
    NOT_CREATED,
    REORDER_CONFLICT,
    backoff_seconds,
    needs_refresh,
    new_plays,
    partition_playlists,
    plan_push,
)

GOLDEN = "AQEBAQEBAQEBAQEBCyhX8rybhPwLN74AdYWqtnK9ClT0kQmK7cpgu1lGd8AzDe4xlA=="
PLAIN = "refresh-token-example"


def test_partition_refreshes_changed_and_new_playlists_only():
    local = {"aaa": "snap-1", "bbb": "snap-1"}
    remote = [
        {"id": "aaa", "snapshot_id": "snap-1"},
        {"id": "bbb", "snapshot_id": "snap-2"},
        {"id": "ccc", "snapshot_id": "snap-9"},
        {"id": "ccc", "snapshot_id": "snap-9"},
        {"id": "", "snapshot_id": "nope"},
    ]
    result = partition_playlists(local, remote)
    assert result["refresh"] == ["bbb", "ccc"]
    assert result["removed"] == []


def test_partition_marks_missing_remote_playlists():
    result = partition_playlists({"aaa": "snap-1"}, [{"id": "zzz", "snapshot_id": "s"}])
    assert result == {"refresh": ["zzz"], "removed": ["aaa"]}


def test_token_refresh_window():
    now = int(datetime(2026, 10, 22, 12, 0, tzinfo=timezone.utc).timestamp())
    assert needs_refresh(None, now) is True
    assert needs_refresh("", now) is True
    assert needs_refresh("not-a-date", now) is True
    assert needs_refresh(datetime(2026, 10, 22, 12, 0, 30), now) is True
    assert needs_refresh(datetime(2026, 10, 22, 12, 0, 30, tzinfo=timezone.utc), now) is True
    assert needs_refresh("2026-10-22 12:02:00", now) is False
    assert needs_refresh(now + 30, now) is True
    assert needs_refresh(now + 120, now) is False


def test_backoff_matches_php():
    assert backoff_seconds(429, 90, 1) == 60
    assert backoff_seconds(429, None, 1) == 2
    assert backoff_seconds(503, None, 2) == 4
    assert backoff_seconds(500, None, 3) == 8
    assert backoff_seconds(500, None, 4) is None
    assert backoff_seconds(401, 5, 1) is None
    assert backoff_seconds(403, None, 1) is None
    assert backoff_seconds(404, None, 1) is None


def test_new_plays_keep_the_first_sighting():
    existing = {("track", "2026-10-22 12:00:00")}
    incoming = [
        ("track", "2026-10-22 12:00:00"),
        ("track", "2026-10-22 12:05:00"),
        ("track", "2026-10-22 12:05:00"),
    ]
    assert new_plays(existing, incoming) == [("track", "2026-10-22 12:05:00")]


def test_create_stops_so_the_caller_can_store_the_new_id():
    actions = plan_push(None, [], [
        {"id": 1, "op": "rename", "payload": {"name": "Later"}},
        {"id": 2, "op": "create", "payload": {"name": "Night drive", "description": "demo", "public": False}},
    ])
    assert actions[0] == {"change_id": 1, "kind": "fail", "error": NOT_CREATED}
    assert actions[1]["kind"] == "request"
    assert actions[1]["method"] == "POST"
    assert actions[1]["path"] == "/me/playlists"
    assert actions[1]["body"]["name"] == "Night drive"
    assert len(actions) == 2


def test_add_skips_tracks_already_present_and_batches_the_rest():
    present = ["spotify:track:a"]
    wanted = [f"spotify:track:t{i}" for i in range(101)]
    actions = plan_push("pl", present, [{"id": 3, "op": "add", "payload": {"uris": ["spotify:track:a", *wanted]}}])
    requests = [item for item in actions if item["kind"] == "request"]
    assert len(requests) == 2
    assert requests[0]["method"] == "POST"
    assert len(requests[0]["body"]["uris"]) == 100
    assert len(requests[1]["body"]["uris"]) == 1
    assert "position" not in requests[0]["body"]


def test_remove_of_a_missing_track_is_a_skip():
    actions = plan_push("pl", ["spotify:track:a"], [{"id": 4, "op": "remove", "payload": {"uris": ["spotify:track:z"]}}])
    assert actions == [{"change_id": 4, "kind": "skip"}]


def test_reorder_conflict_stops_later_ops():
    actions = plan_push("pl", ["spotify:track:a", "spotify:track:b"], [
        {"id": 5, "op": "reorder", "payload": {
            "range_start": 0, "insert_before": 2, "length": 1,
            "base_uris": ["spotify:track:b", "spotify:track:a"],
        }},
        {"id": 6, "op": "rename", "payload": {"name": "After"}},
    ])
    assert actions == [{"change_id": 5, "kind": "fail", "error": REORDER_CONFLICT}]


def test_reorder_matching_the_current_list_is_sent():
    uris = ["spotify:track:a", "spotify:track:b"]
    actions = plan_push("pl", uris, [{"id": 7, "op": "reorder", "payload": {
        "range_start": 0, "insert_before": 2, "length": 1, "base_uris": uris,
    }}])
    assert actions[0]["kind"] == "request"
    assert actions[0]["method"] == "PUT"
    assert actions[0]["body"] == {"range_start": 0, "insert_before": 2, "range_length": 1}


def test_replace_uses_put_then_post_past_one_hundred():
    uris = [f"spotify:track:t{i}" for i in range(120)]
    actions = plan_push("pl", [], [{"id": 8, "op": "replace", "payload": {"uris": uris}}])
    assert [item["method"] for item in actions] == ["PUT", "POST"]
    assert len(actions[0]["body"]["uris"]) == 100
    assert len(actions[1]["body"]["uris"]) == 20


def test_delete_stops_the_plan():
    actions = plan_push("pl", [], [
        {"id": 9, "op": "delete", "payload": {}},
        {"id": 10, "op": "rename", "payload": {"name": "Too late"}},
    ])
    assert len(actions) == 1
    assert actions[0]["method"] == "DELETE"
    assert actions[0]["path"] == "/playlists/pl"


def test_cipher_golden_vector_matches_php():
    key = key_bytes("spotify-test-key")
    iv = bytes([1] * 12)
    assert seal(PLAIN, key, iv) == GOLDEN
    assert open_blob(GOLDEN, key) == PLAIN


def test_cipher_rejects_the_wrong_key():
    with pytest.raises(ValueError):
        open_blob(GOLDEN, key_bytes("other-key"))


def test_recommendations_403_message():
    assert "recommendations or audio features" in error_message(403, "/recommendations", "{}")
    assert error_message(403, "/me/player", "{}") == "Spotify refused that request (403)."


def test_http_retries_429_and_stops_on_401():
    calls = {"n": 0}
    slept = []
    seen = {}

    def transport(method, url, body, headers):
        calls["n"] += 1
        seen["auth"] = headers.get("Authorization")
        if calls["n"] == 1:
            return 429, "{}", {"retry-after": "90"}
        return 200, '{"ok": true}', {}

    client = SpotifyHttp(transport, slept.append)
    assert client.api("GET", "/me", "access-token-example") == {"ok": True}
    assert slept == [60]
    assert seen["auth"] == "Bearer access-token-example"

    def denied(method, url, body, headers):
        return 401, '{"error":{"message":"expired"}}', {}

    client = SpotifyHttp(denied, lambda seconds: pytest.fail("401 must not wait"))
    with pytest.raises(SpotifyHttpError) as caught:
        client.api("GET", "/me", "access-token-example")
    assert caught.value.reauth is True
    assert caught.value.status == 409
    assert "access-token-example" not in str(caught.value)


def test_scrub_redacts_tokens():
    clean = scrub("Bearer abc.def access_token=secret client_secret=no")
    assert "abc.def" not in clean
    assert "=secret" not in clean
    assert "Bearer [redacted]" in clean
