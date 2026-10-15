/**
 * lifedash-realtime.js — live updates over WebSocket with polling fallback.
 *
 * Protocol (served by services/lifedash/push.py behind Apache at /ws):
 *   1. GET /api/v1/realtime/token        → { token, url, ttl }   (session-authenticated)
 *   2. open  ws(s)://<host><url>
 *   3. send  {"type":"auth","token":"…"}  (never in the URL — URLs end up in logs)
 *   4. recv  {"type":"ready"} then {"type":"event","table":…,"op":…,"id":…,"metric_id"?:…}
 *            and {"type":"resync"} when the server's database listener reconnected
 *
 * While disconnected every native widget refreshes every 60 s, and on reconnect
 * everything refreshes once to catch up on missed events.
 */
'use strict';

(function () {
    const { api } = window.LD;
    if (!window.LD.grid) return;

    const POLL_MS = 60_000;
    const MAX_BACKOFF_MS = 60_000;

    let ws = null;
    let ready = false;
    let attempt = 0;
    let reconnectTimer = null;
    let pollTimer = null;
    let hiddenSince = null;

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(() => {
            if (!document.hidden) window.LD.grid.refreshAll();
        }, POLL_MS);
    }

    function stopPolling() {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    function scheduleReconnect() {
        clearTimeout(reconnectTimer);
        const base = Math.min(MAX_BACKOFF_MS, 1000 * 2 ** Math.min(attempt, 6));
        const delay = base / 2 + Math.random() * (base / 2); // jitter
        attempt++;
        reconnectTimer = setTimeout(connect, delay);
    }

    async function connect() {
        clearTimeout(reconnectTimer);
        if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;

        let ticket;
        try {
            ticket = await api.get('/realtime/token');
        } catch (e) {
            startPolling();
            scheduleReconnect();
            return;
        }

        const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
        try {
            ws = new WebSocket(`${scheme}//${location.host}${ticket.url}`);
        } catch (e) {
            startPolling();
            scheduleReconnect();
            return;
        }

        ws.addEventListener('open', () => {
            ws.send(JSON.stringify({ type: 'auth', token: ticket.token }));
        });

        ws.addEventListener('message', (msg) => {
            let evt;
            try {
                evt = JSON.parse(msg.data);
            } catch (e) {
                return;
            }
            if (evt.type === 'ready') {
                const wasDown = pollTimer !== null || attempt > 0;
                ready = true;
                attempt = 0;
                stopPolling();
                window.dispatchEvent(new CustomEvent('lifedash:status', { detail: { live: true } }));
                if (wasDown) window.LD.grid.refreshAll(); // catch up on anything missed
            } else if (evt.type === 'event') {
                window.LD.grid.handleEvent(evt);
                window.dispatchEvent(new CustomEvent('lifedash:event', { detail: evt }));
            } else if (evt.type === 'resync') {
                window.LD.grid.refreshAll(); // server's DB listener reconnected; events may be lost
                window.dispatchEvent(new CustomEvent('lifedash:resync'));
            }
        });

        ws.addEventListener('close', () => {
            ready = false;
            ws = null;
            startPolling();
            window.dispatchEvent(new CustomEvent('lifedash:status', { detail: { live: false } }));
            scheduleReconnect();
        });

        ws.addEventListener('error', () => {
            // 'close' follows; nothing else to do here.
        });
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            hiddenSince = Date.now();
            return;
        }
        const away = hiddenSince ? Date.now() - hiddenSince : 0;
        hiddenSince = null;
        if (!ready) {
            attempt = 0;
            connect();
        }
        // Laptop sleep / phone background: sockets may have silently died.
        if (away > POLL_MS) window.LD.grid.refreshAll();
    });

    window.addEventListener('online', () => {
        attempt = 0;
        connect();
    });

    connect();
})();
