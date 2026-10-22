/**
 * In-house vault map forces. Positions are pixels around the origin.
 * Charge grows with the square root of the node count so a larger vault
 * pushes apart instead of sitting in one ball. A disconnected page is held
 * beside the main group so one distant dot does not shrink the fit.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainForces = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const LABEL_LIMIT = 22;

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function clampSpacing(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return 1;
        return clamp(n, 0.7, 1.8);
    }

    function chars(title) {
        return Array.from(String(title || ''));
    }

    function truncateTitle(title, limit) {
        const text = chars(title);
        const max = limit || LABEL_LIMIT;
        if (text.length <= max) return text.join('');
        return text.slice(0, max - 1).join('') + '\u2026';
    }

    function labelWidth(title) {
        return 8 + chars(truncateTitle(title)).length * 6.6;
    }

    function bodyRadius(title, spacing) {
        return (10 + chars(truncateTitle(title)).length * 3.1) * clampSpacing(spacing);
    }

    function linkRest(spacing) {
        return 118 * clampSpacing(spacing);
    }

    function chargeStrength(count, spacing) {
        return -42 * clampSpacing(spacing) * Math.sqrt(Math.max(1, count));
    }

    function gravityStrength(spacing) {
        return 0.012 / clampSpacing(spacing);
    }

    function degrees(nodes, edges) {
        const deg = new Map();
        nodes.forEach((node) => deg.set(node.id, 0));
        edges.forEach((edge) => {
            if (!deg.has(edge.from) || !deg.has(edge.to) || edge.from === edge.to) return;
            deg.set(edge.from, deg.get(edge.from) + 1);
            deg.set(edge.to, deg.get(edge.to) + 1);
        });
        return deg;
    }

    function hubIds(nodes, deg) {
        if (nodes.length === 0) return [];
        const ranked = nodes.slice().sort((a, b) => (deg.get(b.id) - deg.get(a.id)) || (a.id - b.id));
        if (deg.get(ranked[0].id) < 2) return [];
        const cut = Math.max(2, Math.ceil(deg.get(ranked[0].id) * 0.6));
        const cap = Math.max(4, Math.round(nodes.length * 0.15));
        const ids = [];
        ranked.forEach((node) => {
            if (ids.length >= cap) return;
            if (deg.get(node.id) >= cut) ids.push(node.id);
        });
        return ids;
    }

    function visibleLabelIds(nodes, edges, options) {
        const opts = options || {};
        const mode = opts.mode === 'all' || opts.mode === 'none' ? opts.mode : 'smart';
        if (mode === 'none') return [];
        if (mode === 'all' || nodes.length <= 12) return nodes.map((node) => node.id);
        const deg = degrees(nodes, edges);
        const ids = new Set(hubIds(nodes, deg));
        const zoom = Number(opts.zoom) || 1;
        if (zoom >= 1.25) {
            nodes.forEach((node) => {
                if (deg.get(node.id) >= 2) ids.add(node.id);
            });
        }
        if (zoom >= 1.8) {
            nodes.forEach((node) => ids.add(node.id));
        }
        const focus = [];
        if (opts.hoverId != null) focus.push(opts.hoverId);
        if (opts.selectedId != null && opts.selectedId !== opts.hoverId) focus.push(opts.selectedId);
        const neighbors = new Set();
        edges.forEach((edge) => {
            if (focus.indexOf(edge.from) !== -1) neighbors.add(edge.to);
            if (focus.indexOf(edge.to) !== -1) neighbors.add(edge.from);
        });
        focus.forEach((id) => ids.add(id));
        neighbors.forEach((id) => ids.add(id));
        return Array.from(ids);
    }

    function find(parent, id) {
        let root = id;
        while (parent.get(root) !== root) root = parent.get(root);
        let cursor = id;
        while (parent.get(cursor) !== root) {
            const next = parent.get(cursor);
            parent.set(cursor, root);
            cursor = next;
        }
        return root;
    }

    function groupsOf(bodies, edges) {
        const parent = new Map();
        bodies.forEach((body) => parent.set(body.id, body.id));
        edges.forEach((edge) => {
            if (!parent.has(edge.from) || !parent.has(edge.to) || edge.from === edge.to) return;
            const a = find(parent, edge.from);
            const b = find(parent, edge.to);
            if (a === b) return;
            if (a < b) parent.set(b, a);
            else parent.set(a, b);
        });
        const buckets = new Map();
        bodies.forEach((body) => {
            const root = find(parent, body.id);
            if (!buckets.has(root)) buckets.set(root, []);
            buckets.get(root).push(body);
        });
        return Array.from(buckets.values()).sort((a, b) => b.length - a.length || a[0].id - b[0].id);
    }

    function spread(members, spacing) {
        if (members.length === 0) return;
        let cx = 0;
        let cy = 0;
        members.forEach((body) => {
            cx += body.x;
            cy += body.y;
        });
        cx /= members.length;
        cy /= members.length;
        let max = 0;
        members.forEach((body) => {
            max = Math.max(max, Math.hypot(body.x - cx, body.y - cy));
        });
        const target = linkRest(spacing) * Math.sqrt(members.length) * 0.42;
        if (max < 1) {
            members.forEach((body, index) => {
                const angle = index * 2.399963;
                const radius = target * Math.sqrt((index + 0.5) / members.length);
                body.x = Math.cos(angle) * radius;
                body.y = Math.sin(angle) * radius;
            });
            return;
        }
        const scale = target / max;
        members.forEach((body) => {
            body.x = (body.x - cx) * scale;
            body.y = (body.y - cy) * scale;
        });
    }

    function pack(groups, spacing) {
        groups.forEach((members) => spread(members, spacing));
        if (groups.length === 0) return [];
        const meta = groups.map((members) => {
            let radius = 0;
            members.forEach((body) => {
                radius = Math.max(radius, Math.hypot(body.x, body.y) + body.r);
            });
            return { members, radius };
        });
        const anchors = [];
        anchors.push({ members: meta[0].members, x: 0, y: 0, hold: gravityStrength(spacing) });
        let cursor = meta[0].radius + linkRest(spacing) * 0.9;
        let angle = -Math.PI / 2;
        for (let i = 1; i < meta.length; i++) {
            const group = meta[i];
            const x = Math.cos(angle) * cursor;
            const y = Math.sin(angle) * cursor;
            group.members.forEach((body) => {
                body.x += x;
                body.y += y;
            });
            anchors.push({ members: group.members, x, y, hold: 0.07 });
            angle += Math.max(0.45, (group.radius * 2.2) / Math.max(cursor, 1));
            if (angle > Math.PI * 1.15) {
                cursor += group.radius * 2 + linkRest(spacing);
                angle = -Math.PI / 2;
            }
        }
        return anchors;
    }

    function start(nodes, edges, options) {
        const opts = options || {};
        const spacing = clampSpacing(opts.spacing);
        const list = Array.isArray(nodes) ? nodes : [];
        const links = [];
        const seen = new Set();
        (Array.isArray(edges) ? edges : []).forEach((edge) => {
            const from = edge.from;
            const to = edge.to;
            if (from === to) return;
            const key = from < to ? from + ':' + to : to + ':' + from;
            if (seen.has(key)) return;
            seen.add(key);
            links.push({ from, to });
        });
        const bodies = list.map((node) => ({
            id: node.id,
            title: node.title || '',
            x: Number.isFinite(node.x) ? node.x : 0,
            y: Number.isFinite(node.y) ? node.y : 0,
            vx: 0,
            vy: 0,
            r: bodyRadius(node.title, spacing),
        }));
        const byId = new Map();
        bodies.forEach((body) => byId.set(body.id, body));
        const useLinks = links.filter((link) => byId.has(link.from) && byId.has(link.to));
        const anchors = pack(groupsOf(bodies, useLinks), spacing);
        const charge = Math.abs(chargeStrength(bodies.length, spacing));
        const rest = linkRest(spacing);
        const cutoff = rest * 3.2;
        let alpha = 1;
        let settled = bodies.length <= 1;

        function tick(steps) {
            const rounds = Math.max(1, steps || 1);
            for (let round = 0; round < rounds && !settled; round++) {
                for (let i = 0; i < bodies.length; i++) {
                    for (let j = i + 1; j < bodies.length; j++) {
                        repel(bodies[i], bodies[j], charge, alpha, cutoff);
                    }
                }
                useLinks.forEach((link) => {
                    spring(byId.get(link.from), byId.get(link.to), rest, alpha);
                });
                for (let i = 0; i < bodies.length; i++) {
                    for (let j = i + 1; j < bodies.length; j++) {
                        collide(bodies[i], bodies[j]);
                    }
                }
                anchors.forEach((anchor) => pullGroup(anchor, alpha));
                bodies.forEach((body) => {
                    body.vx *= 0.55;
                    body.vy *= 0.55;
                    const speed = Math.hypot(body.vx, body.vy);
                    if (speed > 28) {
                        body.vx = body.vx / speed * 28;
                        body.vy = body.vy / speed * 28;
                    }
                    body.x += body.vx;
                    body.y += body.vy;
                });
                alpha *= 0.972;
                if (alpha < 0.02) settled = true;
            }
            return settled;
        }

        return {
            spacing,
            get settled() { return settled; },
            get alpha() { return alpha; },
            bodies,
            body(id) { return byId.get(id) || null; },
            tick,
        };
    }

    function repel(a, b, charge, alpha, cutoff) {
        let dx = b.x - a.x;
        let dy = b.y - a.y;
        let dist2 = dx * dx + dy * dy;
        if (dist2 < 1) {
            dx = a.id < b.id ? 1 : -1;
            dy = 0.35;
            dist2 = dx * dx + dy * dy;
        }
        if (dist2 > cutoff * cutoff) return;
        const dist = Math.sqrt(dist2);
        const mag = Math.min(16, (charge * alpha) / dist2);
        const ux = dx / dist;
        const uy = dy / dist;
        a.vx -= ux * mag;
        a.vy -= uy * mag;
        b.vx += ux * mag;
        b.vy += uy * mag;
    }

    function spring(a, b, rest, alpha) {
        if (!a || !b) return;
        let dx = b.x - a.x;
        let dy = b.y - a.y;
        let dist = Math.hypot(dx, dy);
        if (dist < 0.01) {
            dx = rest;
            dy = 0;
            dist = rest;
        }
        const mag = (dist - rest) * 0.14 * Math.max(alpha, 0.3);
        const ux = dx / dist;
        const uy = dy / dist;
        a.vx += ux * mag;
        a.vy += uy * mag;
        b.vx -= ux * mag;
        b.vy -= uy * mag;
    }

    function collide(a, b) {
        let dx = b.x - a.x;
        let dy = b.y - a.y;
        let dist = Math.hypot(dx, dy);
        const min = a.r + b.r;
        if (dist >= min) return;
        if (dist < 0.01) {
            dx = 1;
            dy = 0;
            dist = 1;
        }
        const push = (min - dist) * 0.5;
        const ux = dx / dist;
        const uy = dy / dist;
        a.x -= ux * push;
        a.y -= uy * push;
        b.x += ux * push;
        b.y += uy * push;
    }

    function pullGroup(anchor, alpha) {
        const members = anchor.members;
        if (members.length === 0) return;
        let cx = 0;
        let cy = 0;
        members.forEach((body) => {
            cx += body.x;
            cy += body.y;
        });
        cx /= members.length;
        cy /= members.length;
        const dx = (anchor.x - cx) * anchor.hold * Math.max(alpha, 0.35);
        const dy = (anchor.y - cy) * anchor.hold * Math.max(alpha, 0.35);
        members.forEach((body) => {
            body.vx += dx;
            body.vy += dy;
        });
    }

    function settle(nodes, edges, options) {
        const sim = start(nodes, edges, options);
        let guard = 0;
        while (!sim.settled && guard < 50) {
            sim.tick(16);
            guard += 1;
        }
        return sim.bodies.map((body) => ({ id: body.id, x: body.x, y: body.y, r: body.r }));
    }

    return {
        LABEL_LIMIT,
        clampSpacing,
        truncateTitle,
        labelWidth,
        bodyRadius,
        linkRest,
        chargeStrength,
        gravityStrength,
        visibleLabelIds,
        start,
        settle,
    };
});
