/**
 * Holographic vault map. Pages stay in topic clusters. Agent reads and
 * writes arrive over the same realtime socket as the dashboard.
 */
'use strict';

(function () {
    const { api, esc } = window.LD;
    const canvas = document.getElementById('brain-canvas');
    const ctx = canvas.getContext('2d');
    const ticker = document.getElementById('brain-ticker');
    const titleEl = document.getElementById('brain-panel-title');
    const copyEl = document.getElementById('brain-panel-copy');
    const openEl = document.getElementById('brain-panel-open');
    const legendEl = document.getElementById('brain-legend');
    const liveEl = document.getElementById('brain-live');

    const READ_MS = 4200;
    const WRITE_MS = 7600;
    const POLL_MS = 60000;

    let graph = { nodes: [], edges: [], clusters: [], tokens: [], activity: [] };
    let traces = [];
    const seen = new Set();
    let firstLoad = true;
    let angle = 0;
    let raf = 0;
    let view = window.BrainCamera.reset();
    let suppressClick = false;
    let ws = null;
    let attempt = 0;
    let reconnectTimer = null;
    let pollTimer = null;
    let refreshTimer = null;

    function resize() {
        const wrap = canvas.parentElement;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        canvas.width = Math.floor(wrap.clientWidth * dpr);
        canvas.height = Math.floor(wrap.clientHeight * dpr);
        canvas.style.width = wrap.clientWidth + 'px';
        canvas.style.height = wrap.clientHeight + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function parseUtc(value) {
        if (!value) return Date.now();
        const norm = value.includes('T') ? value : value.replace(' ', 'T');
        const stamped = /Z|[+-]\d\d:?\d\d$/.test(norm) ? norm : norm + 'Z';
        const parsed = Date.parse(stamped);
        return Number.isFinite(parsed) ? parsed : Date.now();
    }

    function tokenColor(id) {
        if (!id) {
            const style = getComputedStyle(document.documentElement);
            return style.getPropertyValue('--hud-accent').trim()
                || style.getPropertyValue('--accent').trim()
                || '#00e5ff';
        }
        const hue = ((id * 47) % 360 + 360) % 360;
        return 'hsl(' + hue + ' 80% 64%)';
    }

    function layout() {
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        const panel = document.querySelector('.brain-panel');
        const reserved = panel ? Math.min(w * 0.46, panel.offsetWidth + 40) : 0;
        const usableW = Math.max(220, w - reserved);
        return {
            x: usableW * 0.5,
            y: h * 0.48,
            scale: Math.min(usableW, h) * 0.36,
        };
    }

    function project(x, y) {
        const box = layout();
        return { sx: box.x + x * box.scale, sy: box.y + y * box.scale };
    }

    function nodeAt(id) {
        return (graph.nodes || []).find((node) => node.id === id) || null;
    }

    function satellite(id) {
        const box = layout();
        const spin = angle + id * 0.9;
        return {
            sx: box.x + Math.cos(spin) * box.scale * 1.35,
            sy: box.y + Math.sin(spin) * box.scale * 1.05,
        };
    }

    function ingest(data) {
        graph = data || graph;
        const now = Date.now();
        (graph.activity || []).forEach((item) => {
            if (seen.has(item.id)) return;
            seen.add(item.id);
            const age = now - parseUtc(item.at);
            const windowMs = firstLoad ? 45000 : 120000;
            if (age <= windowMs) {
                traces.push({
                    id: item.id,
                    token_id: item.token_id,
                    token: item.token,
                    verb: item.verb,
                    page_id: item.page_id,
                    label: item.label,
                    color: tokenColor(item.token_id),
                    born: now,
                });
            }
        });
        firstLoad = false;
        renderLegend();
        renderTicker();
    }

    function renderLegend() {
        const rows = [{ id: 0, name: 'You' }].concat(graph.tokens || []);
        legendEl.innerHTML = rows.map((token) =>
            `<div class="brain-token"><i style="background:${tokenColor(token.id)}"></i><span>${esc(token.name)}</span></div>`
        ).join('') + '<div class="brain-key"><i class="is-read"></i><span>read</span><i class="is-write"></i><span>write</span></div>';
    }

    function renderTicker() {
        ticker.innerHTML = (graph.activity || []).slice(0, 8).map((item) => {
            const color = tokenColor(item.token_id);
            return `<span style="color:${color}">${esc(item.token)} ${esc(item.verb)} ${esc(item.label || '')}</span>`;
        }).join('');
    }

    function setLive(text) {
        liveEl.textContent = text;
    }

    function frame() {
        const now = Date.now();
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);
        const cam = window.BrainCamera.matrix(view, w, h, dpr);
        ctx.setTransform(cam.a, cam.b, cam.c, cam.d, cam.e, cam.f);
        const style = getComputedStyle(document.documentElement);
        const accent = style.getPropertyValue('--hud-accent').trim()
            || style.getPropertyValue('--accent').trim()
            || '#00e5ff';
        const faint = style.getPropertyValue('--text-faint').trim() || '#8aa';
        const text = style.getPropertyValue('--text-secondary').trim() || '#d5e7ea';
        const box = layout();
        const o = { x: box.x, y: box.y };

        const wash = ctx.createRadialGradient(o.x, o.y, 20, o.x, o.y, Math.max(w, h) * 0.55);
        wash.addColorStop(0, 'rgba(0, 229, 255, 0.05)');
        wash.addColorStop(1, 'rgba(0, 0, 0, 0)');
        ctx.fillStyle = wash;
        ctx.fillRect(0, 0, w, h);

        ctx.save();
        ctx.strokeStyle = accent;
        ctx.globalAlpha = 0.08;
        ctx.lineWidth = 1;
        for (let ring = 1; ring <= 4; ring++) {
            ctx.beginPath();
            ctx.ellipse(o.x, o.y, box.scale * 0.42 * ring, box.scale * 0.32 * ring, 0, 0, Math.PI * 2);
            ctx.stroke();
        }
        ctx.globalAlpha = 0.12;
        ctx.beginPath();
        ctx.arc(o.x, o.y, box.scale * 1.45, angle, angle + 0.7);
        ctx.stroke();
        const scan = (now / 18) % h;
        ctx.globalAlpha = 0.05;
        ctx.fillStyle = accent;
        ctx.fillRect(0, scan, w, 2);
        ctx.restore();

        const placed = new Map();
        (graph.nodes || []).forEach((node) => {
            placed.set(node.id, { node, ...project(node.x, node.y) });
        });

        (graph.clusters || []).forEach((cluster) => {
            const members = (graph.nodes || []).filter((node) => node.cluster === cluster.key);
            if (members.length === 0) return;
            let radius = 36;
            const center = project(cluster.x, cluster.y);
            members.forEach((node) => {
                const point = placed.get(node.id);
                const dx = point.sx - center.sx;
                const dy = point.sy - center.sy;
                radius = Math.max(radius, Math.sqrt(dx * dx + dy * dy) + 28);
            });
            ctx.save();
            ctx.strokeStyle = accent;
            ctx.globalAlpha = 0.28;
            ctx.setLineDash([2, 7]);
            ctx.beginPath();
            ctx.arc(center.sx, center.sy, radius, 0, Math.PI * 2);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.globalAlpha = 0.7;
            ctx.fillStyle = faint;
            ctx.font = '11px JetBrains Mono, monospace';
            ctx.fillText(cluster.label, center.sx - radius, center.sy - radius + 12);
            ctx.restore();
        });

        ctx.save();
        ctx.lineWidth = 1;
        ctx.strokeStyle = accent;
        ctx.globalAlpha = 0.35;
        (graph.edges || []).forEach((edge) => {
            const a = placed.get(edge.from);
            const b = placed.get(edge.to);
            if (!a || !b) return;
            ctx.beginPath();
            ctx.moveTo(a.sx, a.sy);
            ctx.quadraticCurveTo((a.sx + b.sx) / 2, (a.sy + b.sy) / 2 - 10, b.sx, b.sy);
            ctx.stroke();
        });
        ctx.restore();

        ctx.beginPath();
        ctx.strokeStyle = accent;
        ctx.globalAlpha = 0.45;
        ctx.arc(o.x, o.y, 7, 0, Math.PI * 2);
        ctx.stroke();
        ctx.globalAlpha = 1;

        placed.forEach((point) => {
            const hot = point.node.hot;
            ctx.beginPath();
            ctx.fillStyle = hot ? accent : 'rgba(8, 18, 24, 0.85)';
            ctx.strokeStyle = hot ? accent : faint;
            ctx.globalAlpha = hot ? 0.95 : 0.8;
            ctx.lineWidth = hot ? 2 : 1;
            ctx.arc(point.sx, point.sy, hot ? 6 : 4.5, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
            ctx.globalAlpha = 0.9;
            ctx.fillStyle = text;
            ctx.font = '12px IBM Plex Sans, sans-serif';
            ctx.fillText(point.node.title, point.sx + 10, point.sy + 4);
        });
        ctx.globalAlpha = 1;

        const alive = [];
        traces.forEach((trace) => {
            const life = trace.verb === 'write' ? WRITE_MS : READ_MS;
            const age = now - trace.born;
            if (age >= life) return;
            alive.push(trace);
            const progress = Math.min(1, age / (life * 0.45));
            const fade = 1 - Math.max(0, (age - life * 0.55) / (life * 0.45));
            const from = satellite(trace.token_id || 0);
            const targetNode = trace.page_id ? placed.get(trace.page_id) : null;
            const target = targetNode ? { sx: targetNode.sx, sy: targetNode.sy } : o;
            const head = {
                sx: from.sx + (target.sx - from.sx) * progress,
                sy: from.sy + (target.sy - from.sy) * progress,
            };
            ctx.save();
            ctx.globalAlpha = Math.max(0, fade);
            ctx.strokeStyle = trace.color;
            ctx.fillStyle = trace.color;
            ctx.lineWidth = trace.verb === 'write' ? 2.4 : 1;
            if (trace.verb === 'write') {
                ctx.shadowColor = trace.color;
                ctx.shadowBlur = 14;
                ctx.setLineDash([]);
            } else {
                ctx.setLineDash([4, 6]);
            }
            ctx.beginPath();
            ctx.moveTo(from.sx, from.sy);
            ctx.lineTo(head.sx, head.sy);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.beginPath();
            ctx.arc(head.sx, head.sy, trace.verb === 'write' ? 4 : 2.5, 0, Math.PI * 2);
            ctx.fill();
            if (trace.verb === 'write' && targetNode) {
                ctx.globalAlpha = Math.max(0, fade) * 0.8;
                ctx.beginPath();
                ctx.arc(target.sx, target.sy, 8 + progress * 16, 0, Math.PI * 2);
                ctx.stroke();
            }
            ctx.restore();
        });
        traces = alive;
        angle += 0.004;
        raf = requestAnimationFrame(frame);
    }

    function canvasPoint(ev) {
        const rect = canvas.getBoundingClientRect();
        return { x: ev.clientX - rect.left, y: ev.clientY - rect.top };
    }

    function hitNode(ev) {
        const raw = canvasPoint(ev);
        const world = window.BrainCamera.toWorld(view, raw.x, raw.y, canvas.clientWidth / 2, canvas.clientHeight / 2);
        let hit = null;
        (graph.nodes || []).forEach((node) => {
            const point = project(node.x, node.y);
            const dx = point.sx - world.x;
            const dy = point.sy - world.y;
            const radius = 18 / view.k;
            if (dx * dx + dy * dy < radius * radius) hit = node;
        });
        return hit;
    }

    function usableRect() {
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        if (w < 720) {
            return { left: 20, top: 64, width: Math.max(80, w - 40), height: Math.max(80, h * 0.58) };
        }
        return { left: 20, top: 16, width: Math.max(80, w - 320), height: Math.max(80, h - 36) };
    }

    function nodePoints() {
        return (graph.nodes || []).map((node) => {
            const point = project(node.x, node.y);
            return { x: point.sx, y: point.sy };
        });
    }

    function zoomBy(factor) {
        view = window.BrainCamera.zoomAt(view, canvas.clientWidth / 2, canvas.clientHeight / 2, canvas.clientWidth / 2, canvas.clientHeight / 2, factor);
    }

    function fitView() {
        view = window.BrainCamera.fit(nodePoints(), canvas.clientWidth, canvas.clientHeight, usableRect());
    }

    const pointers = new Map();
    let drag = null;
    let pinch = null;

    canvas.addEventListener('pointerdown', (ev) => {
        if (ev.button !== undefined && ev.button !== 0) return;
        canvas.setPointerCapture(ev.pointerId);
        pointers.set(ev.pointerId, canvasPoint(ev));
        pinch = null;
        if (pointers.size === 1) {
            drag = { x: ev.clientX, y: ev.clientY, moved: false, panX: view.x, panY: view.y };
        }
    });
    canvas.addEventListener('pointermove', (ev) => {
        if (!pointers.has(ev.pointerId)) return;
        pointers.set(ev.pointerId, canvasPoint(ev));
        if (pointers.size >= 2) {
            const pts = [...pointers.values()];
            const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
            const mid = { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2 };
            if (!pinch) pinch = { dist: Math.max(1, dist), state: { k: view.k, x: view.x, y: view.y } };
            const factor = dist / pinch.dist;
            view = window.BrainCamera.zoomAt(pinch.state, mid.x, mid.y, canvas.clientWidth / 2, canvas.clientHeight / 2, factor);
            suppressClick = true;
            drag = null;
            return;
        }
        if (!drag) return;
        const dx = ev.clientX - drag.x;
        const dy = ev.clientY - drag.y;
        if (dx * dx + dy * dy > 16) drag.moved = true;
        if (drag.moved) {
            view = { k: view.k, x: drag.panX + dx, y: drag.panY + dy };
            canvas.classList.add('is-panning');
            suppressClick = true;
        }
    });
    function endPointer(ev) {
        pointers.delete(ev.pointerId);
        pinch = null;
        if (suppressClick || (drag && drag.moved)) {
            suppressClick = true;
            setTimeout(() => { suppressClick = false; }, 0);
        } else {
            suppressClick = false;
        }
        drag = null;
        if (pointers.size === 0) canvas.classList.remove('is-panning');
    }
    canvas.addEventListener('pointerup', endPointer);
    canvas.addEventListener('pointercancel', endPointer);
    canvas.addEventListener('wheel', (ev) => {
        ev.preventDefault();
        const point = canvasPoint(ev);
        const factor = window.BrainCamera.wheelFactor(ev.deltaY, ev.deltaMode);
        view = window.BrainCamera.zoomAt(view, point.x, point.y, canvas.clientWidth / 2, canvas.clientHeight / 2, factor);
    }, { passive: false });
    canvas.addEventListener('keydown', (ev) => {
        if (ev.key === '+' || ev.key === '=') zoomBy(1.18);
        else if (ev.key === '-' || ev.key === '_') zoomBy(1 / 1.18);
        else if (ev.key === '0') view = window.BrainCamera.reset();
        else if (ev.key === 'f' || ev.key === 'F') fitView();
        else if (ev.key === 'ArrowLeft') view = window.BrainCamera.panBy(view, 28, 0);
        else if (ev.key === 'ArrowRight') view = window.BrainCamera.panBy(view, -28, 0);
        else if (ev.key === 'ArrowUp') view = window.BrainCamera.panBy(view, 0, 28);
        else if (ev.key === 'ArrowDown') view = window.BrainCamera.panBy(view, 0, -28);
        else return;
        ev.preventDefault();
    });
    document.getElementById('brain-zoom-in').addEventListener('click', () => zoomBy(1.2));
    document.getElementById('brain-zoom-out').addEventListener('click', () => zoomBy(1 / 1.2));
    document.getElementById('brain-zoom-fit').addEventListener('click', fitView);
    document.getElementById('brain-zoom-reset').addEventListener('click', () => {
        view = window.BrainCamera.reset();
    });

    canvas.addEventListener('click', (ev) => {
        if (suppressClick) {
            suppressClick = false;
            return;
        }
        const hit = hitNode(ev);
        if (!hit) return;
        const cluster = (graph.clusters || []).find((item) => item.key === hit.cluster);
        titleEl.textContent = hit.title;
        copyEl.textContent = (cluster ? cluster.label + ' · ' : '') + (hit.hot ? 'Touched in the last 7 days.' : hit.kind);
        openEl.href = '/brain?page=' + hit.id;
        openEl.textContent = 'Open ' + hit.title;
    });

    function scheduleRefresh() {
        clearTimeout(refreshTimer);
        refreshTimer = setTimeout(() => {
            load().catch((err) => {
                copyEl.textContent = err.message;
            });
        }, 350);
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(() => {
            if (!document.hidden) scheduleRefresh();
        }, POLL_MS);
    }

    function stopPolling() {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    function scheduleReconnect() {
        clearTimeout(reconnectTimer);
        const base = Math.min(POLL_MS, 1000 * 2 ** Math.min(attempt, 6));
        attempt += 1;
        reconnectTimer = setTimeout(connect, base / 2 + Math.random() * (base / 2));
    }

    async function connect() {
        clearTimeout(reconnectTimer);
        if (ws && (ws.readyState === WebSocket.OPEN || ws.readyState === WebSocket.CONNECTING)) return;
        let ticket;
        try {
            ticket = await api.get('/realtime/token');
        } catch (e) {
            setLive('Polling');
            startPolling();
            scheduleReconnect();
            return;
        }
        const scheme = location.protocol === 'https:' ? 'wss:' : 'ws:';
        try {
            ws = new WebSocket(scheme + '//' + location.host + ticket.url);
        } catch (e) {
            setLive('Polling');
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
                const catchUp = pollTimer !== null || attempt > 0;
                attempt = 0;
                stopPolling();
                setLive('Live');
                if (catchUp) scheduleRefresh();
            } else if (evt.type === 'event' && (evt.table === 'pages' || evt.table === 'audit_events')) {
                scheduleRefresh();
            } else if (evt.type === 'resync') {
                scheduleRefresh();
            }
        });
        ws.addEventListener('close', () => {
            ws = null;
            setLive('Reconnecting');
            startPolling();
            scheduleReconnect();
        });
    }

    async function load() {
        ingest(await api.get('/pages/graph'));
    }

    window.addEventListener('resize', resize);
    resize();
    requestAnimationFrame(frame);
    setLive('Connecting');
    load().then(connect).catch((err) => {
        copyEl.textContent = err.message;
        setLive('Offline');
    });
})();
