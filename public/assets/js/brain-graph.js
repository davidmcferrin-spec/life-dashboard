/**
 * Sphere projection of the vault. Hot nodes were touched in the audit log this week.
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

    let graph = { nodes: [], edges: [], recent: [] };
    let points = [];
    let angle = 0;
    let raf = 0;

    function resize() {
        const wrap = canvas.parentElement;
        const dpr = Math.min(2, window.devicePixelRatio || 1);
        canvas.width = Math.floor(wrap.clientWidth * dpr);
        canvas.height = Math.floor(wrap.clientHeight * dpr);
        canvas.style.width = wrap.clientWidth + 'px';
        canvas.style.height = wrap.clientHeight + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    function layout(nodes) {
        const n = Math.max(1, nodes.length);
        return nodes.map((node, i) => {
            const y = n === 1 ? 0 : 1 - (2 * i) / (n - 1);
            const r = Math.sqrt(Math.max(0, 1 - y * y));
            const t = Math.PI * (3 - Math.sqrt(5)) * i;
            return { node, x: Math.cos(t) * r, y, z: Math.sin(t) * r };
        });
    }

    function project(p, w, h) {
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);
        const x = p.x * cos - p.z * sin;
        const z = p.x * sin + p.z * cos;
        const scale = (w * 0.28) / (2.2 - z);
        return { sx: w / 2 + x * scale, sy: h / 2 + p.y * scale * 0.9, z, scale };
    }

    function frame() {
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        ctx.clearRect(0, 0, w, h);
        const style = getComputedStyle(document.documentElement);
        const accent = style.getPropertyValue('--accent').trim() || '#7eb6ff';
        const muted = style.getPropertyValue('--text-faint').trim() || '#8aa';
        const placed = points.map((p) => ({ ...p, ...project(p, w, h) }));
        const byId = new Map(placed.map((p) => [p.node.id, p]));
        ctx.lineWidth = 1;
        graph.edges.forEach((e) => {
            const a = byId.get(e.from);
            const b = byId.get(e.to);
            if (!a || !b) return;
            ctx.strokeStyle = muted;
            ctx.globalAlpha = 0.35;
            ctx.beginPath();
            ctx.moveTo(a.sx, a.sy);
            ctx.lineTo(b.sx, b.sy);
            ctx.stroke();
        });
        ctx.globalAlpha = 1;
        placed.sort((a, b) => a.z - b.z).forEach((p) => {
            const radius = p.node.hot ? 7 : 4;
            ctx.beginPath();
            ctx.fillStyle = p.node.hot ? accent : muted;
            ctx.arc(p.sx, p.sy, radius, 0, Math.PI * 2);
            ctx.fill();
            if (p.z > 0) {
                ctx.fillStyle = style.getPropertyValue('--text-secondary').trim() || '#ddd';
                ctx.font = '12px IBM Plex Sans, sans-serif';
                ctx.fillText(p.node.title, p.sx + 10, p.sy + 4);
            }
        });
        angle += 0.004;
        raf = requestAnimationFrame(frame);
    }

    canvas.addEventListener('click', (ev) => {
        const rect = canvas.getBoundingClientRect();
        const x = ev.clientX - rect.left;
        const y = ev.clientY - rect.top;
        const w = canvas.clientWidth;
        const h = canvas.clientHeight;
        let hit = null;
        points.forEach((p) => {
            const q = project(p, w, h);
            const dx = q.sx - x;
            const dy = q.sy - y;
            if (dx * dx + dy * dy < 18 * 18) hit = p.node;
        });
        if (!hit) return;
        titleEl.textContent = hit.title;
        copyEl.textContent = hit.hot ? 'Touched in the last 7 days.' : hit.kind;
        openEl.href = '/brain?page=' + hit.id;
        openEl.textContent = 'Open ' + hit.title;
    });

    window.addEventListener('resize', resize);
    resize();

    api.get('/pages/graph').then((data) => {
        graph = data;
        points = layout(data.nodes || []);
        ticker.innerHTML = (data.recent || []).map((e) =>
            `<span>${esc(e.actor)} ${esc(e.action)} ${esc(e.resource || '')}</span>`
        ).join('');
        cancelAnimationFrame(raf);
        frame();
    }).catch((err) => {
        copyEl.textContent = err.message;
    });
})();
