"""``lifedash-push`` — realtime push: PostgreSQL LISTEN → WebSocket fan-out.

Topology::

    browser ──wss──▶ Apache /ws ──ws──▶ 127.0.0.1:8766/ws (this process)
                                                 ▲
    PostgreSQL trigger lifedash_notify() ──NOTIFY lifedash_events──┘

Protocol (see public/assets/js/lifedash-realtime.js):

1. The browser fetches a short-lived ticket from ``GET /api/v1/realtime/token``
   (session-authenticated PHP) and opens the socket.
2. First frame must be ``{"type":"auth","token":"…"}`` within ``AUTH_TIMEOUT``.
   The ticket format is pinned by tests on both sides (``RealtimeToken.php``).
3. Server replies ``{"type":"ready"}`` then forwards the user's own events as
   ``{"type":"event","table":…,"op":…,"id":…,"metric_id"?:…}``.
4. After the database listener reconnects, clients get ``{"type":"resync"}``
   (events may have been missed) and reload their widgets.

The service holds no state worth persisting; systemd restarts it on failure and
the browser falls back to polling while it is down.
"""
from __future__ import annotations

import argparse
import asyncio
import base64
import hashlib
import hmac
import json
import logging
import signal
import time
from dataclasses import dataclass, field
from typing import Any, Protocol

from .config import Config, ConfigError

LOG = logging.getLogger("lifedash.push")

CHANNEL = "lifedash_events"
TOKEN_CONTEXT = b"lifedash-realtime-v1"
AUTH_TIMEOUT = 10.0
SEND_TIMEOUT = 5.0
MAX_CONNECTIONS_PER_USER = 20
MAX_MESSAGE_BYTES = 4096
FORWARDED_KEYS = ("table", "op", "id", "metric_id")


# ----------------------------------------------------------------------
# Tickets (must match src/Support/RealtimeToken.php)
# ----------------------------------------------------------------------
def _b64(raw: bytes) -> str:
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def _unb64(text: str) -> bytes:
    return base64.urlsafe_b64decode(text + "=" * (-len(text) % 4))


def _key(app_secret: str) -> bytes:
    if len(app_secret.encode()) < 16:
        raise ConfigError("APP_SECRET must be set (>= 16 chars) for realtime tokens.")
    return hmac.new(app_secret.encode(), TOKEN_CONTEXT, hashlib.sha256).digest()


def issue_token(user_id: int, app_secret: str, now: int | None = None, ttl: int = 120) -> str:
    """Mirror of RealtimeToken::issue — used by tests to prove format parity."""
    payload = json.dumps({"u": user_id, "exp": (int(time.time()) if now is None else now) + ttl}, separators=(",", ":"))
    p = _b64(payload.encode())
    return p + "." + _b64(hmac.new(_key(app_secret), p.encode(), hashlib.sha256).digest())


def verify_token(token: str, app_secret: str, now: int | None = None) -> int | None:
    """User id for a valid, unexpired ticket; None otherwise."""
    if not isinstance(token, str) or token.count(".") != 1:
        return None
    payload, sig = token.split(".")
    expected = _b64(hmac.new(_key(app_secret), payload.encode(), hashlib.sha256).digest())
    if not hmac.compare_digest(expected, sig):
        return None
    try:
        data = json.loads(_unb64(payload))
    except (ValueError, json.JSONDecodeError):
        return None
    if not isinstance(data, dict):
        return None
    uid, exp = data.get("u"), data.get("exp")
    # bool is an int subclass in Python; reject it like PHP's is_int would.
    if not isinstance(uid, int) or isinstance(uid, bool) or not isinstance(exp, int) or isinstance(exp, bool):
        return None
    return uid if exp >= (int(time.time()) if now is None else now) else None


# ----------------------------------------------------------------------
# Fan-out hub
# ----------------------------------------------------------------------
class Sink(Protocol):
    async def send(self, message: str) -> None: ...
    async def close(self, code: int = 1000, reason: str = "") -> None: ...


@dataclass
class Hub:
    """Tracks authenticated sockets per user and routes events to their owner only."""

    clients: dict[int, set[Sink]] = field(default_factory=dict)

    def add(self, user_id: int, sink: Sink) -> bool:
        conns = self.clients.setdefault(user_id, set())
        if len(conns) >= MAX_CONNECTIONS_PER_USER:
            return False
        conns.add(sink)
        return True

    def remove(self, user_id: int, sink: Sink) -> None:
        conns = self.clients.get(user_id)
        if conns is not None:
            conns.discard(sink)
            if not conns:
                del self.clients[user_id]

    def count(self) -> int:
        return sum(len(c) for c in self.clients.values())

    async def _send(self, user_id: int, sink: Sink, message: str) -> None:
        try:
            await asyncio.wait_for(sink.send(message), SEND_TIMEOUT)
        except Exception:  # slow or dead client: drop it; the browser reconnects
            self.remove(user_id, sink)
            try:
                await sink.close(1011, "send failed")
            except Exception:
                pass

    async def publish(self, event: dict[str, Any]) -> int:
        """Deliver one NOTIFY payload to its user's sockets. Returns deliveries attempted."""
        uid = event.get("user_id")
        if not isinstance(uid, int) or isinstance(uid, bool):
            return 0
        conns = list(self.clients.get(uid, ()))
        if not conns:
            return 0
        message = json.dumps({"type": "event", **{k: event[k] for k in FORWARDED_KEYS if k in event}})
        await asyncio.gather(*(self._send(uid, c, message) for c in conns))
        return len(conns)

    async def broadcast(self, message: dict[str, Any]) -> None:
        text = json.dumps(message)
        await asyncio.gather(*(self._send(uid, c, text) for uid, conns in list(self.clients.items()) for c in list(conns)))


def parse_notification(payload: str) -> dict[str, Any] | None:
    try:
        event = json.loads(payload)
    except json.JSONDecodeError:
        LOG.warning("bad notify payload", extra={"payload": payload[:200]})
        return None
    return event if isinstance(event, dict) else None


# ----------------------------------------------------------------------
# WebSocket side
# ----------------------------------------------------------------------
async def handle_socket(ws: Any, hub: Hub, app_secret: str) -> None:
    """One browser connection: authenticate, register, then just keep it open."""
    path = getattr(getattr(ws, "request", None), "path", "/ws")
    if path.split("?", 1)[0] != "/ws":
        await ws.close(1008, "not found")
        return

    try:
        first = await asyncio.wait_for(ws.recv(), AUTH_TIMEOUT)
        msg = json.loads(first)
        user_id = verify_token(msg.get("token", ""), app_secret) if isinstance(msg, dict) and msg.get("type") == "auth" else None
    except (asyncio.TimeoutError, json.JSONDecodeError, TypeError):
        user_id = None
    except Exception:  # connection closed during auth
        return

    if user_id is None:
        await ws.close(4401, "unauthorized")
        return
    if not hub.add(user_id, ws):
        await ws.close(4429, "too many connections")
        return

    LOG.info("client connected", extra={"user_id": user_id, "clients": hub.count()})
    try:
        await ws.send(json.dumps({"type": "ready"}))
        async for _ in ws:  # clients have nothing to say after auth; drain until close
            pass
    except Exception:
        pass
    finally:
        hub.remove(user_id, ws)
        LOG.info("client disconnected", extra={"user_id": user_id, "clients": hub.count()})


# ----------------------------------------------------------------------
# PostgreSQL side
# ----------------------------------------------------------------------
async def listen_forever(cfg: Config, hub: Hub, stop: asyncio.Event) -> None:
    """LISTEN with reconnect/backoff. Clients are told to resync after a gap."""
    import psycopg

    from .db import conninfo

    backoff = 1.0
    first = True
    while not stop.is_set():
        try:
            conn = await psycopg.AsyncConnection.connect(**conninfo(cfg, "lifedash-push"), autocommit=True)
        except Exception as exc:
            LOG.error("database connect failed", extra={"error": str(exc), "retry_s": backoff})
            await _sleep_or_stop(stop, backoff)
            backoff = min(backoff * 2, 30.0)
            continue

        try:
            await conn.execute(f"LISTEN {CHANNEL}")
            LOG.info("listening", extra={"channel": CHANNEL})
            backoff = 1.0
            if not first:
                await hub.broadcast({"type": "resync"})
            first = False

            notifies = conn.notifies()
            while not stop.is_set():
                get_next = asyncio.ensure_future(anext(notifies))
                stopper = asyncio.ensure_future(stop.wait())
                done, _ = await asyncio.wait({get_next, stopper}, return_when=asyncio.FIRST_COMPLETED)
                if stopper in done:
                    get_next.cancel()
                    break
                stopper.cancel()
                note = get_next.result()
                event = parse_notification(note.payload)
                if event is not None:
                    await hub.publish(event)
        except Exception as exc:
            LOG.error("listener lost", extra={"error": str(exc), "retry_s": backoff})
            await _sleep_or_stop(stop, backoff)
            backoff = min(backoff * 2, 30.0)
        finally:
            try:
                await conn.close()
            except Exception:
                pass


async def _sleep_or_stop(stop: asyncio.Event, seconds: float) -> None:
    try:
        await asyncio.wait_for(stop.wait(), seconds)
    except asyncio.TimeoutError:
        pass


# ----------------------------------------------------------------------
# Entry point
# ----------------------------------------------------------------------
async def serve(cfg: Config, host: str, port: int) -> None:
    from websockets.asyncio.server import serve as ws_serve

    app_secret = cfg.get("APP_SECRET")
    _key(app_secret)  # fail fast on a missing/short secret
    hub = Hub()
    stop = asyncio.Event()

    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)

    async with ws_serve(
        lambda ws: handle_socket(ws, hub, app_secret),
        host,
        port,
        max_size=MAX_MESSAGE_BYTES,
        ping_interval=20,
        ping_timeout=20,
    ):
        LOG.info("push service up", extra={"host": host, "port": port})
        await listen_forever(cfg, hub, stop)
    LOG.info("push service stopped")


def main(argv: list[str] | None = None) -> int:
    from . import log

    parser = argparse.ArgumentParser(description="life-dashboard realtime push (LISTEN → WebSocket)")
    parser.add_argument("--host", default=None, help="bind address (default PUSH_HOST or 127.0.0.1)")
    parser.add_argument("--port", type=int, default=None, help="port (default PUSH_PORT or 8766)")
    args = parser.parse_args(argv)

    try:
        cfg = Config.load()
    except ConfigError as exc:
        print(f"lifedash-push: {exc}")
        return 2
    log.setup(cfg.get("LOG_LEVEL", "INFO"))
    host = args.host or cfg.get("PUSH_HOST", "127.0.0.1")
    port = args.port or cfg.int("PUSH_PORT", 8766)
    try:
        asyncio.run(serve(cfg, host, port))
    except ConfigError as exc:
        LOG.error(str(exc))
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
