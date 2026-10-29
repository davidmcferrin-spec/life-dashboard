/**
 * Sphere layout for the vault mesh.
 * CSS applies rotateY(az) rotateX(el), then translateZ(radius).
 * A cloud is one vault folder. A page with no folder stays in its domain cluster.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainSphere = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const NODE_CAP = 40;
    const LINK_CAP = 64;
    const RECENT_MS = 7 * 24 * 60 * 60 * 1000;
    const LEGEND = [
        { key: 'project', label: 'Project' },
        { key: 'topic', label: 'Topic' },
        { key: 'note', label: 'Note' },
        { key: 'person', label: 'Person' },
        { key: 'bot', label: 'Bot' },
    ];

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function cssPoint(azDeg, elDeg, radius) {
        const az = azDeg * Math.PI / 180;
        const el = elDeg * Math.PI / 180;
        const z1 = radius * Math.cos(el);
        return {
            x: z1 * Math.sin(az),
            y: -radius * Math.sin(el),
            z: z1 * Math.cos(az),
        };
    }

    function placeOnSphere(index, count, radius) {
        const n = Math.max(1, count | 0);
        const r = Number(radius) > 0 ? Number(radius) : 96;
        let az = 0;
        let el = 0;
        if (n > 1) {
            const golden = Math.PI * (3 - Math.sqrt(5));
            const i = Math.max(0, Math.min(n - 1, index));
            const y = 1 - (i / (n - 1)) * 2;
            az = ((golden * i) * 180 / Math.PI) % 360;
            el = -Math.asin(clamp(y, -1, 1)) * 180 / Math.PI;
        }
        const point = cssPoint(az, el, r);
        return { az: az, el: el, radius: r, x: point.x, y: point.y, z: point.z };
    }

    function linkPose(a, b) {
        const dx = b.x - a.x;
        const dy = b.y - a.y;
        const dz = b.z - a.z;
        const length = Math.hypot(dx, dy, dz);
        if (length < 0.001) {
            return { x: a.x, y: a.y, z: a.z, az: 0, el: 0, length: 0 };
        }
        const horiz = Math.hypot(dx, dz);
        return {
            x: a.x,
            y: a.y,
            z: a.z,
            az: Math.atan2(dx, dz) * 180 / Math.PI,
            el: Math.atan2(-dy, horiz) * 180 / Math.PI,
            length: length,
        };
    }

    function designType(kind) {
        const value = String(kind || '').toLowerCase();
        if (value === 'project') return 'project';
        if (value === 'reference' || value === 'review' || value === 'topic') return 'topic';
        if (value === 'person') return 'person';
        if (value === 'bot') return 'bot';
        return 'note';
    }

    function typeLabel(kind) {
        const key = designType(kind);
        const row = LEGEND.find((item) => item.key === key);
        return row ? row.label : 'Note';
    }

    function nodeSize(links) {
        const n = Number(links) || 0;
        return Math.max(18, Math.min(30, 18 + n * 2));
    }

    function cloudFrame(index, count) {
        const n = Math.max(1, count);
        const i = Math.max(0, index);
        if (n === 1) return { x: 50, y: 46, scale: 1 };
        const angle = -Math.PI / 2 + (i / n) * Math.PI * 2;
        const ring = n === 2 ? 18 : 22;
        const scale = n > 6 ? 0.56 : n > 3 ? 0.66 : 0.82;
        return {
            x: clamp(50 + Math.cos(angle) * ring, 18, 82),
            y: clamp(48 + Math.sin(angle) * ring * 0.72, 22, 78),
            scale: scale,
        };
    }

    function cloudsFrom(nodes, clusters) {
        const labels = new Map();
        (clusters || []).forEach((cluster) => {
            labels.set(String(cluster.key), String(cluster.label || 'Vault'));
        });
        const groups = new Map();
        (nodes || []).forEach((node) => {
            const raw = node.folder_id;
            const folderId = raw == null || raw === '' ? null : Number(raw);
            const hasFolder = folderId != null && Number.isFinite(folderId);
            const key = hasFolder ? 'folder:' + folderId : 'domain:' + String(node.cluster || ('page:' + node.id));
            const label = hasFolder
                ? (String(node.folder || '').trim() || 'Folder')
                : (labels.get(String(node.cluster)) || 'Vault');
            if (!groups.has(key)) groups.set(key, { key: key, label: label, nodes: [] });
            groups.get(key).nodes.push(node);
        });
        return Array.from(groups.values()).sort((a, b) => a.label.localeCompare(b.label) || a.key.localeCompare(b.key));
    }

    function updatedMs(value) {
        if (!value) return NaN;
        const norm = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
        const stamped = /Z|[+-]\d\d:?\d\d$/.test(norm) ? norm : norm + 'Z';
        return Date.parse(stamped);
    }

    function isRecent(node, now, windowMs) {
        const t = updatedMs(node && node.updated_at);
        const span = windowMs == null ? RECENT_MS : Number(windowMs);
        const at = Number(now);
        return Number.isFinite(t) && Number.isFinite(at) && at >= t && at - t <= span;
    }

    function passesFilter(node, filter, now) {
        if (filter === 'projects' || filter === 'project') return designType(node && node.kind) === 'project';
        if (filter === 'recent') return isRecent(node, now);
        return true;
    }

    function degrees(nodes, edges) {
        const deg = new Map();
        (nodes || []).forEach((node) => deg.set(node.id, 0));
        (edges || []).forEach((edge) => {
            if (!deg.has(edge.from) || !deg.has(edge.to) || edge.from === edge.to) return;
            deg.set(edge.from, deg.get(edge.from) + 1);
            deg.set(edge.to, deg.get(edge.to) + 1);
        });
        return deg;
    }

    function capNodes(nodes, edges, options) {
        const list = nodes || [];
        const opts = options || {};
        const cap = opts.cap || NODE_CAP;
        if (list.length <= cap) return list.map((node) => node.id);
        const present = new Set(list.map((node) => node.id));
        const deg = degrees(list, edges || []);
        const ranked = list.slice().sort((a, b) => {
            const ta = updatedMs(a.updated_at) || 0;
            const tb = updatedMs(b.updated_at) || 0;
            if (tb !== ta) return tb - ta;
            const delta = (deg.get(b.id) || 0) - (deg.get(a.id) || 0);
            if (delta !== 0) return delta;
            return a.id - b.id;
        });
        const ids = [];
        if (opts.selectedId != null && present.has(opts.selectedId)) ids.push(opts.selectedId);
        (opts.keepIds || []).forEach((id) => {
            if (present.has(id) && ids.indexOf(id) === -1 && ids.length < cap) ids.push(id);
        });
        ranked.forEach((node) => {
            if (ids.length >= cap) return;
            if (ids.indexOf(node.id) === -1) ids.push(node.id);
        });
        return ids;
    }

    function capLinks(edges, visibleIds, options) {
        const visible = new Set(visibleIds || []);
        const opts = options || {};
        const cap = opts.cap || LINK_CAP;
        const among = [];
        const seen = new Set();
        (edges || []).forEach((edge) => {
            if (!visible.has(edge.from) || !visible.has(edge.to) || edge.from === edge.to) return;
            const key = edge.from < edge.to ? edge.from + ':' + edge.to : edge.to + ':' + edge.from;
            if (seen.has(key)) return;
            seen.add(key);
            among.push(edge);
        });
        if (among.length <= cap) return among;
        const hot = new Set(opts.hotIds || []);
        const kept = [];
        const rest = [];
        among.forEach((edge) => {
            if (hot.has(edge.from) || hot.has(edge.to)) kept.push(edge);
            else rest.push(edge);
        });
        if (kept.length >= cap) return kept.slice(0, cap);
        return kept.concat(rest.slice(0, cap - kept.length));
    }

    function neighborIds(edges, id) {
        const out = [];
        (edges || []).forEach((edge) => {
            if (edge.from === id) out.push(edge.to);
            else if (edge.to === id) out.push(edge.from);
        });
        return out;
    }

    return {
        NODE_CAP,
        LINK_CAP,
        RECENT_MS,
        LEGEND,
        cssPoint,
        placeOnSphere,
        linkPose,
        designType,
        typeLabel,
        nodeSize,
        cloudFrame,
        cloudsFrom,
        updatedMs,
        isRecent,
        passesFilter,
        capNodes,
        capLinks,
        neighborIds,
    };
});
