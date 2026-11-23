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

    function clusterRadius(count) {
        return 86 + 22 * Math.sqrt(Math.max(1, count));
    }

    function labelClearance(title) {
        const text = Array.from(String(title || 'Page'));
        const len = Math.min(28, text.length);
        const width = 40 + len * 6.4;
        const height = 40;
        return Math.max(34, Math.hypot(width * 0.5, height * 0.62));
    }

    function fibonacciPoint(index, count, radius) {
        if (count <= 1 || radius <= 0) return { x: 0, y: 0, z: 0 };
        const golden = Math.PI * (3 - Math.sqrt(5));
        const y = 1 - (index / (count - 1)) * 2;
        const ring = Math.sqrt(Math.max(0, 1 - y * y));
        const theta = golden * index;
        return {
            x: Math.cos(theta) * ring * radius,
            y: y * radius,
            z: Math.sin(theta) * ring * radius,
        };
    }

    function spreadRadius(count, clusterR) {
        const n = Math.max(2, count);
        const spacing = clusterR * 2.55 + 210;
        return spacing / (2 * Math.sqrt(Math.PI / n));
    }

    function limitStep(force, maxStep) {
        const mag = Math.hypot(force.x, force.y, force.z);
        if (mag <= maxStep || mag === 0) return force;
        const scale = maxStep / mag;
        return { x: force.x * scale, y: force.y * scale, z: force.z * scale };
    }

    function layoutWorld(nodes, edges, clusters) {
        const clouds = cloudsFrom(nodes, clusters);
        const positions = new Map();
        if (!clouds.length) return { clouds: [], positions: positions };

        const nodeCloud = new Map();
        const centers = clouds.map((cloud, index) => {
            cloud.nodes.forEach((node) => nodeCloud.set(node.id, index));
            return {
                key: cloud.key,
                label: cloud.label,
                count: cloud.nodes.length,
                radius: clusterRadius(cloud.nodes.length),
                x: 0,
                y: 0,
                z: 0,
            };
        });
        const avgR = centers.reduce((sum, center) => sum + center.radius, 0) / centers.length;
        const spread = centers.length === 1 ? 0 : spreadRadius(centers.length, avgR);
        centers.forEach((center, index) => {
            const point = fibonacciPoint(index, centers.length, spread);
            center.x = point.x;
            center.y = point.y;
            center.z = point.z;
        });

        const bridge = new Map();
        (edges || []).forEach((edge) => {
            const a = nodeCloud.get(edge.from);
            const b = nodeCloud.get(edge.to);
            if (a == null || b == null || a === b) return;
            const i = Math.min(a, b);
            const j = Math.max(a, b);
            const key = i + ':' + j;
            bridge.set(key, (bridge.get(key) || 0) + 1);
        });

        const passes = centers.length < 2 ? 0 : 72;
        for (let step = 0; step < passes; step++) {
            const cool = 1 - step / passes;
            const forces = centers.map(() => ({ x: 0, y: 0, z: 0 }));
            for (let i = 0; i < centers.length; i++) {
                for (let j = i + 1; j < centers.length; j++) {
                    let dx = centers[j].x - centers[i].x;
                    let dy = centers[j].y - centers[i].y;
                    let dz = centers[j].z - centers[i].z;
                    let dist = Math.hypot(dx, dy, dz);
                    if (dist < 1) {
                        dx = (j - i);
                        dy = 0.35;
                        dz = 0.2;
                        dist = Math.hypot(dx, dy, dz);
                    }
                    const nx = dx / dist;
                    const ny = dy / dist;
                    const nz = dz / dist;
                    const minSep = centers[i].radius + centers[j].radius + 150;
                    const rep = (18000 + centers[i].radius * centers[j].radius) / (dist * dist);
                    forces[i].x -= nx * rep;
                    forces[i].y -= ny * rep;
                    forces[i].z -= nz * rep;
                    forces[j].x += nx * rep;
                    forces[j].y += ny * rep;
                    forces[j].z += nz * rep;
                    const weight = bridge.get(i + ':' + j) || 0;
                    if (weight) {
                        const rest = minSep * 1.08;
                        const pull = (dist - rest) * 0.045 * Math.min(4, weight);
                        forces[i].x += nx * pull;
                        forces[i].y += ny * pull;
                        forces[i].z += nz * pull;
                        forces[j].x -= nx * pull;
                        forces[j].y -= ny * pull;
                        forces[j].z -= nz * pull;
                    }
                    if (dist < minSep) {
                        const push = (minSep - dist) * 0.65;
                        forces[i].x -= nx * push;
                        forces[i].y -= ny * push;
                        forces[i].z -= nz * push;
                        forces[j].x += nx * push;
                        forces[j].y += ny * push;
                        forces[j].z += nz * push;
                    }
                }
                forces[i].x -= centers[i].x * 0.012;
                forces[i].y -= centers[i].y * 0.012;
                forces[i].z -= centers[i].z * 0.012;
            }
            const maxStep = 28 + 90 * cool;
            centers.forEach((center, index) => {
                const stepForce = limitStep(forces[index], maxStep);
                center.x += stepForce.x;
                center.y += stepForce.y;
                center.z += stepForce.z;
            });
        }

        clouds.forEach((cloud, cloudIndex) => {
            const center = centers[cloudIndex];
            const shell = Math.max(72, center.radius * 0.62);
            const locals = cloud.nodes.map((node, index) => {
                const base = placeOnSphere(index, cloud.nodes.length, shell);
                return {
                    id: node.id,
                    x: base.x,
                    y: base.y,
                    z: base.z,
                    clear: labelClearance(node.title),
                };
            });
            const indexOf = new Map(locals.map((node, index) => [node.id, index]));
            const localLinks = [];
            (edges || []).forEach((edge) => {
                if (!indexOf.has(edge.from) || !indexOf.has(edge.to) || edge.from === edge.to) return;
                localLinks.push([indexOf.get(edge.from), indexOf.get(edge.to)]);
            });
            const localPasses = locals.length < 2 ? 0 : Math.min(36, 10 + locals.length);
            for (let step = 0; step < localPasses; step++) {
                const forces = locals.map(() => ({ x: 0, y: 0, z: 0 }));
                for (let i = 0; i < locals.length; i++) {
                    for (let j = i + 1; j < locals.length; j++) {
                        let dx = locals[j].x - locals[i].x;
                        let dy = locals[j].y - locals[i].y;
                        let dz = locals[j].z - locals[i].z;
                        let dist = Math.hypot(dx, dy, dz);
                        if (dist < 0.5) {
                            dx = 1;
                            dy = 0;
                            dz = 0;
                            dist = 1;
                        }
                        const nx = dx / dist;
                        const ny = dy / dist;
                        const nz = dz / dist;
                        const minSep = (locals[i].clear + locals[j].clear) * 0.78;
                        const rep = (minSep * minSep * 4.5) / (dist * dist);
                        forces[i].x -= nx * rep;
                        forces[i].y -= ny * rep;
                        forces[i].z -= nz * rep;
                        forces[j].x += nx * rep;
                        forces[j].y += ny * rep;
                        forces[j].z += nz * rep;
                        if (dist < minSep) {
                            const push = (minSep - dist) * 0.42;
                            forces[i].x -= nx * push;
                            forces[i].y -= ny * push;
                            forces[i].z -= nz * push;
                            forces[j].x += nx * push;
                            forces[j].y += ny * push;
                            forces[j].z += nz * push;
                        }
                    }
                    const home = placeOnSphere(i, locals.length, shell);
                    forces[i].x += (home.x - locals[i].x) * 0.12;
                    forces[i].y += (home.y - locals[i].y) * 0.12;
                    forces[i].z += (home.z - locals[i].z) * 0.12;
                }
                localLinks.forEach((pair) => {
                    const i = pair[0];
                    const j = pair[1];
                    let dx = locals[j].x - locals[i].x;
                    let dy = locals[j].y - locals[i].y;
                    let dz = locals[j].z - locals[i].z;
                    const dist = Math.hypot(dx, dy, dz) || 1;
                    const rest = Math.min(shell * 0.85, 86);
                    const pull = (dist - rest) * 0.06;
                    const nx = dx / dist;
                    const ny = dy / dist;
                    const nz = dz / dist;
                    forces[i].x += nx * pull;
                    forces[i].y += ny * pull;
                    forces[i].z += nz * pull;
                    forces[j].x -= nx * pull;
                    forces[j].y -= ny * pull;
                    forces[j].z -= nz * pull;
                });
                locals.forEach((node, index) => {
                    const stepForce = limitStep(forces[index], 22);
                    node.x += stepForce.x;
                    node.y += stepForce.y;
                    node.z += stepForce.z;
                });
            }
            locals.forEach((node) => {
                positions.set(node.id, {
                    x: center.x + node.x,
                    y: center.y + node.y,
                    z: center.z + node.z,
                    cloudKey: center.key,
                    clearance: node.clear,
                });
            });
        });

        return {
            clouds: centers.map((center) => ({
                key: center.key,
                label: center.label,
                count: center.count,
                radius: center.radius,
                x: center.x,
                y: center.y,
                z: center.z,
            })),
            positions: positions,
        };
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
        clusterRadius,
        labelClearance,
        layoutWorld,
    };
});
