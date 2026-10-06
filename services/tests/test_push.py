"""Tests for lifedash.push — ticket parity with PHP, routing, socket auth, and a live LISTEN round-trip."""
from __future__ import annotations

import asyncio
import json
import os
import socket

import pytest

from lifedash import push
from lifedash.config import Config, ConfigError

SECRET = "unit-test-secret-0123456789"
# Same vector as tests/Unit/RealtimeTokenTest.php::GOLDEN — change both or neither.
GOLDEN = "eyJ1Ijo3LCJleHAiOjE4MDAwMDAxMjB9.mIyxf9E4UQfAi6CPRpSzsSJwwjsOudad3v9UwPyWJrA"


# ----------------------------------------------------------------------
# Tickets
# ----------------------------------------------------------------------
def test_golden_vector_matches_php():
    assert push.issue_token(7, SECRET, now=1_800_000_000) == GOLDEN
    assert push.verify_token(GOLDEN, SECRET, now=1_800_000_000) == 7


def test_expiry_and_tampering():
    t = push.issue_token(42, SECRET, now=1000, ttl=120)
    assert push.verify_token(t, SECRET, now=1120) == 42
    assert push.verify_token(t, SECRET, now=1121) is None
    payload, sig = t.split(".")
    forged = push._b64(b'{"u":1,"exp":9999999999}')
    assert push.verify_token(f"{forged}.{sig}", SECRET, now=1000) is None
    assert push.verify_token(t, "another-secret-0123456789", now=1000) is None
    for junk in ("", "abc", "a.b.c", None, 123):
        assert push.verify_token(junk, SECRET, now=1000) is None  # type: ignore[arg-type]


def test_rejects_non_int_claims():
    bad = push._b64(b'{"u":true,"exp":9999999999}')
    sig = push._b64(__import__("hmac").new(push._key(SECRET), bad.encode(), "sha256").digest())
    assert push.verify_token(f"{bad}.{sig}", SECRET, now=0) is None


def test_short_secret_refused():
    with pytest.raises(ConfigError):
        push.issue_token(1, "")


# ----------------------------------------------------------------------
# Hub routing
# ----------------------------------------------------------------------
class FakeSink:
    def __init__(self, fail: bool = False):
        self.sent: list[dict] = []
        self.closed: tuple[int, str] | None = None
        self.fail = fail

    async def send(self, message: str) -> None:
        if self.fail:
            raise ConnectionError("gone")
        self.sent.append(json.loads(message))

    async def close(self, code: int = 1000, reason: str = "") -> None:
        self.closed = (code, reason)


def test_events_go_only_to_their_owner_and_drop_user_id():
    async def run():
        hub = push.Hub()
        a1, a2, b = FakeSink(), FakeSink(), FakeSink()
        hub.add(1, a1)
        hub.add(1, a2)
        hub.add(2, b)
        n = await hub.publish({"table": "datapoints", "op": "insert", "id": 5, "user_id": 1, "metric_id": 9, "extra": "x"})
        return hub, n, a1, a2, b

    hub, n, a1, a2, b = asyncio.run(run())
    assert n == 2
    expected = {"type": "event", "table": "datapoints", "op": "insert", "id": 5, "metric_id": 9}
    assert a1.sent == [expected] and a2.sent == [expected]
    assert b.sent == []


def test_failed_sink_is_dropped_and_malformed_events_ignored():
    async def run():
        hub = push.Hub()
        bad = FakeSink(fail=True)
        hub.add(1, bad)
        await hub.publish({"table": "metrics", "op": "update", "id": 1, "user_id": 1})
        ignored = await hub.publish({"table": "metrics", "op": "update", "id": 1, "user_id": "1"})
        return hub, bad, ignored

    hub, bad, ignored = asyncio.run(run())
    assert hub.count() == 0
    assert bad.closed is not None and bad.closed[0] == 1011
    assert ignored == 0
    assert push.parse_notification("not json") is None
    assert push.parse_notification("[1,2]") is None


def test_per_user_connection_cap():
    hub = push.Hub()
    for _ in range(push.MAX_CONNECTIONS_PER_USER):
        assert hub.add(1, FakeSink())
    assert not hub.add(1, FakeSink())


# ----------------------------------------------------------------------
# Socket auth handshake (fake websocket)
# ----------------------------------------------------------------------
class FakeWS:
    def __init__(self, frames: list[str], path: str = "/ws"):
        self.frames = frames
        self.sent: list[dict] = []
        self.closed: tuple[int, str] | None = None
        self.request = type("R", (), {"path": path})()

    async def recv(self) -> str:
        if not self.frames:
            await asyncio.sleep(3600)
        return self.frames.pop(0)

    async def send(self, message: str) -> None:
        self.sent.append(json.loads(message))

    async def close(self, code: int = 1000, reason: str = "") -> None:
        self.closed = (code, reason)

    def __aiter__(self):
        return self

    async def __anext__(self):
        if self.frames:
            return self.frames.pop(0)
        raise StopAsyncIteration


def test_handshake_ready_then_unregistered_on_close():
    token = push.issue_token(3, SECRET)
    ws = FakeWS([json.dumps({"type": "auth", "token": token})])
    hub = push.Hub()
    asyncio.run(push.handle_socket(ws, hub, SECRET))
    assert ws.sent == [{"type": "ready"}]
    assert hub.count() == 0  # drained & closed → removed


@pytest.mark.parametrize("frame,path", [
    (json.dumps({"type": "auth", "token": "nope"}), "/ws"),
    (json.dumps({"type": "hello"}), "/ws"),
    ("not json", "/ws"),
    (json.dumps({"type": "auth", "token": push.issue_token(3, SECRET)}), "/other"),
])
def test_handshake_rejections(frame, path):
    ws = FakeWS([frame], path=path)
    asyncio.run(push.handle_socket(ws, push.Hub(), SECRET))
    assert ws.sent == []
    assert ws.closed is not None and ws.closed[0] in (4401, 1008)


# ----------------------------------------------------------------------
# Live: PostgreSQL NOTIFY → WebSocket client (skipped without a test DB)
# ----------------------------------------------------------------------
PG_DB = os.environ.get("LIFEDASH_TEST_PG_DB")


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


@pytest.mark.skipif(not PG_DB, reason="set LIFEDASH_TEST_PG_DB (and _PASS) for the live round-trip")
def test_live_notify_reaches_authenticated_client():
    import psycopg
    from websockets.asyncio.client import connect as ws_connect

    cfg = Config(values={
        "APP_SECRET": SECRET,
        "DB_HOST": os.environ.get("LIFEDASH_TEST_PG_HOST", "127.0.0.1"),
        "DB_PORT": os.environ.get("LIFEDASH_TEST_PG_PORT", "5432"),
        "DB_NAME": PG_DB or "",
        "DB_USER": os.environ.get("LIFEDASH_TEST_PG_USER", "lifedash"),
        "DB_PASS": os.environ.get("LIFEDASH_TEST_PG_PASS", ""),
    })
    port = _free_port()

    async def run():
        server = asyncio.create_task(push.serve(cfg, "127.0.0.1", port))
        try:
            for _ in range(50):  # wait for bind
                try:
                    ws = await ws_connect(f"ws://127.0.0.1:{port}/ws")
                    break
                except OSError:
                    await asyncio.sleep(0.1)
            else:
                raise AssertionError("push server did not start")

            async with ws:
                await ws.send(json.dumps({"type": "auth", "token": push.issue_token(11, SECRET)}))
                assert json.loads(await asyncio.wait_for(ws.recv(), 5)) == {"type": "ready"}
                await asyncio.sleep(0.3)  # listener LISTENing

                from lifedash.db import conninfo
                async with await psycopg.AsyncConnection.connect(**conninfo(cfg), autocommit=True) as conn:
                    for uid, row_id in ((99, 2), (11, 1)):  # other user's event first: must not arrive
                        payload = json.dumps({"table": "datapoints", "op": "insert", "id": row_id, "user_id": uid, "metric_id": 4})
                        await conn.execute("SELECT pg_notify(%s, %s)", (push.CHANNEL, payload))

                got = json.loads(await asyncio.wait_for(ws.recv(), 5))
                assert got == {"type": "event", "table": "datapoints", "op": "insert", "id": 1, "metric_id": 4}
        finally:
            server.cancel()
            try:
                await server
            except (asyncio.CancelledError, Exception):
                pass

    asyncio.run(run())
