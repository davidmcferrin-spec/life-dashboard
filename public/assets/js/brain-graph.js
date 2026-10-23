/**
 * Vault map. Pages stay in topic clusters drawn as clouds.
 * Free roam pans. Attach keeps one cloud in frame and orbits it.
 * Read and write pulses come from /api/v1/audit/presence.
 */
'use strict';

(function () {
    const { api, esc } = window.LD;
    const canvas = document.getElementById('brain-canvas');
    // The vault editor still draws this canvas. The graph screen has none.
    if (!canvas || typeof canvas.getContext !== 'function') return;
    const ctx = canvas.getContext('2d');
    const ticker = document.getElementById('brain-ticker');
    const titleEl = document.getElementById('brain-panel-title');
    const copyEl = document.getElementById('brain-panel-copy');
    const openEl = document.getElementById('brain-panel-open');
    const legendEl = document.getElementById('brain-legend');
    const liveEl = document.getElementById('brain-live');
    const signalEl = document.getElementById('brain-graph-signal');
    const readoutEl = document.getElementById('brain-graph-readout');
    const modeNameEl = document.getElementById('brain-graph-mode-name');
    const modeHintEl = document.getElementById('brain-graph-mode-hint');
    const freeBtn = document.getElementById('brain-camera-free');
    const clusterHost = document.getElementById('brain-camera-clusters');

    const POLL_MS = 60000;
    const PRESENCE_MS = 4000;
    const PULSE_MS = 2400;
    const FRESH_MS = 30000;
    const SPACING_KEY = 'ld_brain_spacing';
    const LABEL_KEY = 'ld_brain_labels';

    const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    let reduceMotion = motionQuery.matches;
    let pulses = [];

    function onMotion() {
        reduceMotion = motionQuery.matches;
        if (reduceMotion) pulses = [];
        updateModeUi();
    }
    if (motionQuery.addEventListener) motionQuery.addEventListener('change', onMotion);
    else if (motionQuery.addListener) motionQuery.addListener(onMotion);

    function readSpacing() {
        try {
            const raw = localStorage.getItem(SPACING_KEY);
            if (raw == null || raw === '') return 1;
            return window.BrainForces.clampSpacing(raw);
        } catch (err) {
            return 1;
        }
    }

    function readLabels() {
        try {
            const value = localStorage.getItem(LABEL_KEY);
            if (value === 'all' || value === 'none' || value === 'smart') return value;
        } catch (err) {
            return 'smart';
        }
        return 'smart';
    }

    function storePref(key, value) {
        try {
            localStorage.setItem(key, String(value));
        } catch (err) {
            /* private mode can reject storage; the control still works this visit */
        }
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    let graph = { nodes: [], edges: [], clusters: [], tokens: [], activity: [] };
    let raf = 0;
    let view = window.BrainCamera.reset();
    let suppressClick = false;
    let sim = null;
    let seedNodes = [];
    let hoverId = null;
    let selectedId = null;
    let labelMode = readLabels();
    let spacing = readSpacing();
    let fittedInitial = false;
    let fittedSettle = false;
    let userMovedCamera = false;
    let ws = null;
    let attempt = 0;
    let reconnectTimer = null;
    let pollTimer = null;
    let refreshTimer = null;
    let presenceTimer = null;
    let presenceAfter = 0;
    const seenPresence = new Set();
    let presenceLog = [];
    let latestSignal = { verb: '', at: 0 };
    let mode = 'free';
    let attachedKey = null;
    let orbitAngle = 0;
    let orbitRadius = 28;
    let manual = { x: 0, y: 0 };
    const colorProbe = document.createElement('span');
    colorProbe.setAttribute('aria-hidden', 'true');
    colorProbe.style.cssText = 'position:absolute;width:0;height:0;overflow:hidden;pointer-events:none;';
    canvas.parentElement.appendChild(colorProbe);

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
        const norm = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
        const stamped = /Z|[+-]\d\d:?\d\d$/.test(norm) ? norm : norm + 'Z';
        const parsed = Date.parse(stamped);
        return Number.isFinite(parsed) ? parsed : Date.now();
    }

    let palette = null;

    function colors() {
        if (palette) return palette;
        palette = {
            accent: cssColor('--accent', '#44d7d0'),
            warn: cssColor('--warn', '#e3b45f'),
            ok: cssColor('--ok', '#62c99a'),
            faint: cssColor('--text-secondary', '#8b979e'),
            text: cssColor('--text-primary', '#e7ebeb'),
            bg: cssColor('--bg', '#101314'),
        };
        return palette;
    }

    function tokenColor(id) {
        if (!id) return colors().accent;
        const hue = ((id * 47) % 360 + 360) % 360;
        return 'hsl(' + hue + ' 80% 64%)';
    }

    new MutationObserver(() => {
        palette = null;
        if (legendEl) renderLegend();
    }).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme', 'style', 'class'],
    });

    function cssColor(name, fallback) {
        colorProbe.style.color = 'var(' + name + ')';
        const value = getComputedStyle(colorProbe).color;
        return value && value !== 'rgba(0, 0, 0, 0)' ? value : fallback;
    }

    function paintAlpha(color, alpha) {
        const rgb = String(color).match(/^rgba?\(([^)]+)\)$/);
        if (!rgb) return color;
        const parts = rgb[1].split(',').slice(0, 3).map((part) => part.trim());
        return 'rgba(' + parts.join(',') + ',' + alpha + ')';
    }

    function layout() {
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        const panel = document.querySelector('.brain-panel');
        const side = panel && w >= 720;
        const reserved = side ? Math.min(w * 0.46, panel.offsetWidth + 40) : 0;
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

    function screenOf(node) {
        const body = sim && sim.body(node.id);
        const box = layout();
        if (!body) return project(node.x, node.y);
        return { sx: box.x + body.x, sy: box.y + body.y };
    }

    function sameIds(next) {
        if (!sim || seedNodes.length !== next.length) return false;
        for (let i = 0; i < next.length; i++) {
            if (seedNodes[i].id !== next[i].id) return false;
        }
        return true;
    }

    function beginSim(nodes, refit) {
        seedNodes = nodes.map((node) => ({
            id: node.id,
            title: node.title,
            x: node.x,
            y: node.y,
        }));
        sim = window.BrainForces.start(seedNodes, graph.edges || [], { spacing });
        if (refit) {
            fittedInitial = false;
            fittedSettle = false;
            userMovedCamera = false;
        }
    }

    function nodeAt(id) {
        return (graph.nodes || []).find((node) => node.id === id) || null;
    }

    function clusterByKey(key) {
        if (key == null) return null;
        return (graph.clusters || []).find((item) => String(item.key) === String(key)) || null;
    }

    function clusterLabel(cluster) {
        const label = String((cluster && cluster.label) || '').trim();
        return label || 'Cloud';
    }

    function clusterMembers(key) {
        const cluster = clusterByKey(key);
        if (!cluster) return [];
        return (graph.nodes || []).filter((node) => String(node.cluster) === String(cluster.key));
    }

    function clusterCenter(key) {
        const members = clusterMembers(key);
        if (!members.length) return null;
        let sx = 0;
        let sy = 0;
        members.forEach((node) => {
            const point = screenOf(node);
            sx += point.sx;
            sy += point.sy;
        });
        return { sx: sx / members.length, sy: sy / members.length, count: members.length };
    }

    function presenceVerb(event) {
        const hay = (String(event && event.tool || '') + ' ' + String(event && event.label || '')).toLowerCase();
        if (/(write|create|update|delete|remove|rename|move|post|put|patch|insert|archive|restore)/.test(hay)) return 'write';
        return 'read';
    }

    function pulsePageId(event) {
        const nodes = graph.nodes || [];
        if (!nodes.length || !event || event.area !== 'Vault') return null;
        const id = Math.abs(Number(event.id) || 0);
        return nodes[id % nodes.length].id;
    }

    function kindColor(kind, accent, ok, warn) {
        if (kind === 'daily') return ok;
        if (kind === 'project') return warn;
        if (kind === 'reference') return '#b894ff';
        return accent;
    }

    function linkCount(id) {
        let count = 0;
        (graph.edges || []).forEach((edge) => {
            if (edge.from === id || edge.to === id) count += 1;
        });
        return count;
    }

    function ingest(data) {
        const next = data || graph;
        const nodes = next.nodes || [];
        const keep = sameIds(nodes);
        graph = next;
        if (!keep) beginSim(nodes, mode !== 'attach');
        else seedNodes.forEach((seed) => {
            const node = nodes.find((item) => item.id === seed.id);
            if (node) seed.title = node.title;
        });
        if (mode === 'attach' && !clusterByKey(attachedKey)) {
            mode = 'free';
            attachedKey = null;
        }
        renderLegend();
        renderReadout();
        updateModeUi();
    }

    function renderLegend() {
        const rows = [{ id: 0, name: 'You' }].concat(graph.tokens || []);
        legendEl.innerHTML = rows.map((token) =>
            `<div class="brain-token"><i style="background:${tokenColor(token.id)}"></i><span>${esc(token.name)}</span></div>`
        ).join('') + '<div class="brain-key"><i class="is-read"></i><span>Read</span><i class="is-write"></i><span>Write</span></div>';
    }

    function renderReadout() {
        if (!readoutEl) return;
        const nodes = (graph.nodes || []).length;
        const edges = (graph.edges || []).length;
        const clouds = (graph.clusters || []).length;
        readoutEl.textContent = nodes + ' nodes · ' + edges + ' links · ' + clouds + ' clouds';
    }

    function renderPresenceTicker() {
        if (!ticker) return;
        ticker.innerHTML = presenceLog.slice(0, 8).map((item) => {
            const color = item.verb === 'write' ? 'var(--warn)' : 'var(--accent)';
            const label = item.label ? item.label : item.verb;
            return `<span style="color:${color}">${esc(item.agent)} · ${esc(label)}</span>`;
        }).join('');
    }

    function renderSignal() {
        if (!signalEl) return;
        const fresh = latestSignal.verb && (Date.now() - latestSignal.at) < FRESH_MS;
        const verb = fresh ? latestSignal.verb : '';
        signalEl.classList.toggle('is-read', verb === 'read');
        signalEl.classList.toggle('is-write', verb === 'write');
        const text = signalEl.querySelector('span');
        const label = verb === 'write' ? 'Write' : verb === 'read' ? 'Read' : 'Live';
        if (text) text.textContent = label;
        else signalEl.textContent = label;
    }

    function renderClusterButtons() {
        if (!clusterHost) return;
        const clusters = graph.clusters || [];
        clusterHost.innerHTML = clusters.map((cluster) => {
            const on = mode === 'attach' && String(attachedKey) === String(cluster.key);
            return `<button type="button" class="brain-graph-attach${on ? ' is-active' : ''}" data-cluster="${esc(cluster.key)}" aria-pressed="${on ? 'true' : 'false'}">${esc(clusterLabel(cluster))}</button>`;
        }).join('');
    }

    function updateModeUi() {
        const cluster = mode === 'attach' ? clusterByKey(attachedKey) : null;
        const attached = !!cluster;
        if (freeBtn) {
            freeBtn.setAttribute('aria-pressed', attached ? 'false' : 'true');
            freeBtn.classList.toggle('is-active', !attached);
        }
        if (modeNameEl) modeNameEl.textContent = attached ? 'Attached · ' + clusterLabel(cluster) : 'Free roam';
        if (modeHintEl) {
            if (!attached) modeHintEl.textContent = 'Drag to travel. Scroll to zoom.';
            else if (reduceMotion) modeHintEl.textContent = 'Drag to turn. This view stays still on its own.';
            else modeHintEl.textContent = 'Drag to orbit. Arrow keys still pan.';
        }
        canvas.classList.toggle('is-attached', attached);
        renderClusterButtons();
    }

    function freeRoam() {
        mode = 'free';
        attachedKey = null;
        updateModeUi();
    }

    function attachTo(key) {
        const cluster = clusterByKey(key);
        if (!cluster) return;
        mode = 'attach';
        attachedKey = cluster.key;
        manual = { x: 0, y: 0 };
        orbitAngle = 0;
        orbitRadius = 28;
        userMovedCamera = true;
        updateModeUi();
    }

    function setLive(text) {
        liveEl.textContent = text;
    }

    function screenFont(px) {
        return (px / view.k) + 'px "Inter", sans-serif';
    }

    function holdCluster(w, h) {
        const center = clusterCenter(attachedKey);
        if (!center) {
            mode = 'free';
            attachedKey = null;
            updateModeUi();
            return;
        }
        if (!drag && pointers.size < 2 && !reduceMotion) orbitAngle += 0.004;
        const rect = usableRect();
        const destX = rect.left + rect.width / 2;
        const destY = rect.top + rect.height / 2;
        const radius = reduceMotion ? 0 : orbitRadius;
        let next = window.BrainCamera.focus(view, center.sx, center.sy, w, h, destX, destY);
        next = window.BrainCamera.panBy(
            next,
            Math.cos(orbitAngle) * radius + manual.x,
            Math.sin(orbitAngle) * radius + manual.y
        );
        view = next;
    }

    function frame() {
        const now = Date.now();
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, w, h);

        if (sim && !sim.settled) sim.tick(8);
        if (sim && canvas.clientWidth > 40 && mode !== 'attach') {
            if (!fittedInitial) {
                fitView();
                fittedInitial = true;
                if (sim.settled) fittedSettle = true;
            } else if (sim.settled && !fittedSettle && !userMovedCamera) {
                fitView();
                fittedSettle = true;
            }
        }
        if (mode === 'attach') holdCluster(w, h);

        const tone = colors();
        const accent = tone.accent;
        const warn = tone.warn;
        const ok = tone.ok;
        const faint = tone.faint;
        const text = tone.text;
        const bg = tone.bg;
        const box = layout();
        const origin = { x: box.x, y: box.y };

        ctx.save();
        const wash = ctx.createRadialGradient(w * 0.5, h * 0.42, 20, w * 0.5, h * 0.42, Math.max(w, h) * 0.62);
        wash.addColorStop(0, paintAlpha(accent, 0.09));
        wash.addColorStop(1, paintAlpha(accent, 0));
        ctx.fillStyle = wash;
        ctx.fillRect(0, 0, w, h);
        ctx.strokeStyle = paintAlpha(accent, 0.06);
        ctx.lineWidth = 1;
        const gap = 64;
        for (let x = gap; x < w; x += gap) {
            ctx.beginPath();
            ctx.moveTo(x, 0);
            ctx.lineTo(x, h);
            ctx.stroke();
        }
        for (let y = gap; y < h; y += gap) {
            ctx.beginPath();
            ctx.moveTo(0, y);
            ctx.lineTo(w, y);
            ctx.stroke();
        }
        ctx.restore();

        const cam = window.BrainCamera.matrix(view, w, h, dpr);
        ctx.setTransform(cam.a, cam.b, cam.c, cam.d, cam.e, cam.f);

        const placed = new Map();
        (graph.nodes || []).forEach((node) => {
            placed.set(node.id, { node, ...screenOf(node) });
        });
        const shown = new Set(window.BrainForces.visibleLabelIds(graph.nodes || [], graph.edges || [], {
            mode: labelMode,
            zoom: view.k,
            hoverId,
            selectedId,
        }));

        (graph.clusters || []).forEach((cluster) => {
            const members = clusterMembers(cluster.key);
            if (!members.length) return;
            let radius = 48;
            let sx = 0;
            let sy = 0;
            members.forEach((node) => {
                const point = placed.get(node.id);
                if (!point) return;
                sx += point.sx;
                sy += point.sy;
            });
            const center = { sx: sx / members.length, sy: sy / members.length };
            members.forEach((node) => {
                const point = placed.get(node.id);
                if (!point) return;
                const dx = point.sx - center.sx;
                const dy = point.sy - center.sy;
                radius = Math.max(radius, Math.sqrt(dx * dx + dy * dy) + 36);
            });
            const attached = mode === 'attach' && String(attachedKey) === String(cluster.key);
            ctx.save();
            ctx.fillStyle = paintAlpha(accent, attached ? 0.16 : 0.08);
            ctx.beginPath();
            ctx.arc(center.sx, center.sy, radius, 0, Math.PI * 2);
            ctx.fill();
            ctx.strokeStyle = accent;
            ctx.globalAlpha = attached ? 0.55 : 0.28;
            ctx.lineWidth = (attached ? 1.5 : 1) / view.k;
            ctx.setLineDash([4 / view.k, 7 / view.k]);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.globalAlpha = 0.9;
            ctx.fillStyle = accent;
            ctx.beginPath();
            ctx.arc(center.sx, center.sy, 4.5 / Math.min(view.k, 1), 0, Math.PI * 2);
            ctx.fill();
            ctx.globalAlpha = 1;
            ctx.fillStyle = text;
            ctx.font = screenFont(12);
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            ctx.lineJoin = 'round';
            ctx.lineWidth = 4 / view.k;
            ctx.strokeStyle = bg;
            const labelY = center.sy - radius - (6 / view.k);
            ctx.strokeText(clusterLabel(cluster), center.sx, labelY);
            ctx.fillText(clusterLabel(cluster), center.sx, labelY);
            ctx.restore();
        });

        ctx.save();
        ctx.lineWidth = 1 / view.k;
        ctx.strokeStyle = accent;
        (graph.edges || []).forEach((edge) => {
            const a = placed.get(edge.from);
            const b = placed.get(edge.to);
            if (!a || !b) return;
            const hot = selectedId != null && (edge.from === selectedId || edge.to === selectedId);
            ctx.globalAlpha = hot ? 0.72 : 0.28;
            ctx.beginPath();
            ctx.moveTo(a.sx, a.sy);
            ctx.quadraticCurveTo((a.sx + b.sx) / 2, (a.sy + b.sy) / 2 - (10 / view.k), b.sx, b.sy);
            ctx.stroke();
        });
        ctx.restore();

        placed.forEach((point) => {
            const hot = point.node.hot;
            const picked = point.node.id === hoverId || point.node.id === selectedId;
            const tone = kindColor(point.node.kind, accent, ok, warn);
            const radius = (picked ? 7 : 5) / Math.min(view.k, 1);
            ctx.beginPath();
            ctx.fillStyle = hot ? tone : paintAlpha(bg, 0.88);
            ctx.strokeStyle = picked || hot ? tone : faint;
            ctx.globalAlpha = hot || picked ? 1 : 0.9;
            ctx.lineWidth = (picked ? 2 : 1) / view.k;
            ctx.arc(point.sx, point.sy, radius, 0, Math.PI * 2);
            ctx.fill();
            ctx.stroke();
            if (!shown.has(point.node.id)) return;
            const full = point.node.id === hoverId || point.node.id === selectedId;
            const label = full ? String(point.node.title || '') : window.BrainForces.truncateTitle(point.node.title);
            ctx.globalAlpha = 1;
            ctx.font = screenFont(12);
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.lineJoin = 'round';
            ctx.lineWidth = 4 / view.k;
            ctx.strokeStyle = bg;
            const lx = point.sx + radius + (6 / view.k);
            ctx.strokeText(label, lx, point.sy);
            ctx.fillStyle = text;
            ctx.fillText(label, lx, point.sy);
        });
        ctx.globalAlpha = 1;

        if (!reduceMotion) {
            const alive = [];
            pulses.forEach((pulse) => {
                const age = now - pulse.born;
                if (age >= PULSE_MS) return;
                alive.push(pulse);
                const t = age / PULSE_MS;
                const point = pulse.pageId ? placed.get(pulse.pageId) : null;
                const target = point ? { sx: point.sx, sy: point.sy } : origin;
                const color = pulse.verb === 'write' ? warn : accent;
                ctx.save();
                ctx.strokeStyle = color;
                ctx.fillStyle = color;
                ctx.globalAlpha = 1 - t;
                ctx.lineWidth = (pulse.verb === 'write' ? 2 : 1.25) / view.k;
                if (pulse.verb === 'write') {
                    ctx.shadowColor = color;
                    ctx.shadowBlur = 12 / view.k;
                } else {
                    ctx.setLineDash([3 / view.k, 5 / view.k]);
                }
                ctx.beginPath();
                ctx.arc(target.sx, target.sy, (8 + t * 36) / view.k, 0, Math.PI * 2);
                ctx.stroke();
                ctx.setLineDash([]);
                ctx.globalAlpha = (1 - t) * 0.9;
                ctx.beginPath();
                ctx.arc(target.sx, target.sy, (3.5 / view.k), 0, Math.PI * 2);
                ctx.fill();
                ctx.restore();
            });
            pulses = alive;
        } else {
            pulses = [];
        }

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
            const point = screenOf(node);
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
            const point = screenOf(node);
            const extra = window.BrainForces.labelWidth(node.title);
            return [
                { x: point.sx - 12, y: point.sy },
                { x: point.sx + 10 + extra, y: point.sy },
            ];
        }).flat();
    }

    function clusterPoints(key) {
        return clusterMembers(key).map((node) => {
            const point = screenOf(node);
            const extra = window.BrainForces.labelWidth(node.title);
            return [
                { x: point.sx - 12, y: point.sy },
                { x: point.sx + 10 + extra, y: point.sy },
            ];
        }).flat();
    }

    function zoomBy(factor) {
        userMovedCamera = true;
        view = window.BrainCamera.zoomAt(view, canvas.clientWidth / 2, canvas.clientHeight / 2, canvas.clientWidth / 2, canvas.clientHeight / 2, factor);
    }

    function fitView() {
        const points = mode === 'attach' ? clusterPoints(attachedKey) : nodePoints();
        view = window.BrainCamera.fit(points.length ? points : nodePoints(), canvas.clientWidth, canvas.clientHeight, usableRect());
        manual = { x: 0, y: 0 };
    }

    function focusMap() {
        canvas.focus();
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
            drag = {
                x: ev.clientX,
                y: ev.clientY,
                moved: false,
                panX: view.x,
                panY: view.y,
                orbit: orbitAngle,
                radius: orbitRadius,
            };
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
            userMovedCamera = true;
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
            userMovedCamera = true;
            if (mode === 'attach') {
                orbitAngle = drag.orbit + dx * 0.008;
                orbitRadius = clamp(drag.radius + dy * 0.12, 0, 96);
            } else {
                view = { k: view.k, x: drag.panX + dx, y: drag.panY + dy };
            }
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
        userMovedCamera = true;
        view = window.BrainCamera.zoomAt(view, point.x, point.y, canvas.clientWidth / 2, canvas.clientHeight / 2, factor);
    }, { passive: false });
    canvas.addEventListener('keydown', (ev) => {
        if (ev.key === '+' || ev.key === '=') zoomBy(1.18);
        else if (ev.key === '-' || ev.key === '_') zoomBy(1 / 1.18);
        else if (ev.key === '0') {
            userMovedCamera = true;
            manual = { x: 0, y: 0 };
            orbitAngle = 0;
            orbitRadius = 28;
            view = window.BrainCamera.reset();
        }
        else if (ev.key === 'f' || ev.key === 'F') fitView();
        else if (ev.key === 'ArrowLeft') nudge(28, 0);
        else if (ev.key === 'ArrowRight') nudge(-28, 0);
        else if (ev.key === 'ArrowUp') nudge(0, 28);
        else if (ev.key === 'ArrowDown') nudge(0, -28);
        else return;
        ev.preventDefault();
    });

    function nudge(dx, dy) {
        userMovedCamera = true;
        if (mode === 'attach') manual = { x: manual.x + dx, y: manual.y + dy };
        else view = window.BrainCamera.panBy(view, dx, dy);
    }

    document.getElementById('brain-zoom-in').addEventListener('click', () => {
        zoomBy(1.2);
        focusMap();
    });
    document.getElementById('brain-zoom-out').addEventListener('click', () => {
        zoomBy(1 / 1.2);
        focusMap();
    });
    document.getElementById('brain-zoom-fit').addEventListener('click', () => {
        fitView();
        focusMap();
    });
    document.getElementById('brain-zoom-reset').addEventListener('click', () => {
        userMovedCamera = true;
        manual = { x: 0, y: 0 };
        orbitAngle = 0;
        orbitRadius = 28;
        view = window.BrainCamera.reset();
        focusMap();
    });
    if (freeBtn) {
        freeBtn.addEventListener('click', () => {
            freeRoam();
            focusMap();
        });
    }
    if (clusterHost) {
        clusterHost.addEventListener('click', (ev) => {
            const btn = ev.target.closest('[data-cluster]');
            if (!btn) return;
            attachTo(btn.getAttribute('data-cluster'));
            focusMap();
        });
    }
    const spacingEl = document.getElementById('brain-spacing');
    const labelsEl = document.getElementById('brain-labels');
    if (spacingEl) {
        spacingEl.value = String(spacing);
        let spacingTimer = null;
        spacingEl.addEventListener('input', () => {
            spacing = window.BrainForces.clampSpacing(spacingEl.value);
            storePref(SPACING_KEY, spacing);
            clearTimeout(spacingTimer);
            spacingTimer = setTimeout(() => {
                if (seedNodes.length) beginSim(seedNodes, mode !== 'attach');
            }, 120);
        });
    }
    if (labelsEl) {
        labelsEl.value = labelMode;
        labelsEl.addEventListener('change', () => {
            labelMode = labelsEl.value === 'all' || labelsEl.value === 'none' ? labelsEl.value : 'smart';
            storePref(LABEL_KEY, labelMode);
        });
    }
    canvas.addEventListener('pointermove', (ev) => {
        if (drag || pointers.size > 0) return;
        const hit = hitNode(ev);
        hoverId = hit ? hit.id : null;
    });

    canvas.addEventListener('click', (ev) => {
        if (suppressClick) {
            suppressClick = false;
            return;
        }
        const hit = hitNode(ev);
        if (!hit) return;
        const cluster = (graph.clusters || []).find((item) => String(item.key) === String(hit.cluster));
        selectedId = hit.id;
        titleEl.textContent = hit.title;
        const bits = [];
        if (cluster) bits.push(clusterLabel(cluster));
        if (hit.kind) bits.push(hit.kind);
        bits.push(linkCount(hit.id) + ' links');
        if (hit.hot) bits.push('Touched in the last 7 days.');
        copyEl.textContent = bits.join(' · ');
        openEl.href = '/brain?page=' + encodeURIComponent(hit.id);
        openEl.textContent = 'Open in Vault';
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
                if (evt.table === 'audit_events') pullPresence();
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

    function ingestPresence(events) {
        const now = Date.now();
        (events || []).forEach((event) => {
            const id = Number(event.id) || 0;
            if (id > presenceAfter) presenceAfter = id;
            if (id && seenPresence.has(id)) return;
            if (id) seenPresence.add(id);
            const at = parseUtc(event.at);
            if (now - at > FRESH_MS) return;
            const verb = presenceVerb(event);
            latestSignal = { verb, at };
            presenceLog.unshift({
                id,
                verb,
                agent: event.agent || 'Agent',
                label: event.label || '',
            });
            if (!reduceMotion) {
                pulses.push({
                    id,
                    verb,
                    pageId: pulsePageId(event),
                    born: now,
                });
            }
        });
        if (presenceLog.length > 8) presenceLog = presenceLog.slice(0, 8);
        if (seenPresence.size > 400) seenPresence.clear();
        renderPresenceTicker();
        renderSignal();
    }

    async function pullPresence() {
        if (document.hidden) return;
        try {
            const res = await api.get('/audit/presence?after=' + presenceAfter + '&limit=20');
            ingestPresence(res.events || []);
        } catch (e) {
            /* the map keeps drawing if presence is quiet */
        }
    }

    function startPresence() {
        if (presenceTimer) return;
        presenceTimer = setInterval(() => {
            if (!document.hidden) pullPresence();
        }, PRESENCE_MS);
    }

    async function load() {
        ingest(await api.get('/pages/graph'));
    }

    window.addEventListener('resize', () => {
        resize();
        if (!userMovedCamera && mode !== 'attach' && canvas.clientWidth > 40) fitView();
    });
    resize();
    updateModeUi();
    requestAnimationFrame(frame);
    setLive('Connecting');
    load().then(connect).catch((err) => {
        copyEl.textContent = err.message;
        setLive('Offline');
    }).then(() => {
        pullPresence();
        startPresence();
    });
})();
