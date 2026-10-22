const test = require('node:test');
const assert = require('node:assert/strict');
const forces = require('../../public/assets/js/brain-forces.js');

function chain(count, title) {
    const nodes = [];
    const edges = [];
    for (let i = 1; i <= count; i++) {
        nodes.push({
            id: i,
            title: title ? title(i) : 'Harbor note ' + i,
            x: Math.cos(i) * 0.04,
            y: Math.sin(i) * 0.04,
        });
        if (i > 1) edges.push({ from: i - 1, to: i });
    }
    return { nodes, edges };
}

function dist(a, b) {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

function byId(bodies) {
    const map = new Map();
    bodies.forEach((body) => map.set(body.id, body));
    return map;
}

test('charge grows with the node count and spacing lengthens links', () => {
    assert.ok(Math.abs(forces.chargeStrength(200, 1)) > Math.abs(forces.chargeStrength(20, 1)));
    assert.ok(forces.linkRest(1.6) > forces.linkRest(0.8));
    assert.ok(forces.gravityStrength(1.6) < forces.gravityStrength(0.8));
    assert.equal(forces.clampSpacing(9), 1.8);
    assert.equal(forces.clampSpacing(0), 0.7);
});

test('a far linked page is pulled in beside its neighbor', () => {
    const { nodes, edges } = chain(40);
    nodes.push({ id: 41, title: 'Sailing across the gulf', x: 1.12, y: -0.95 });
    edges.push({ from: 40, to: 41 });
    const placed = forces.settle(nodes, edges, { spacing: 1 });
    const map = byId(placed);
    const gap = dist(map.get(40), map.get(41));
    const rest = forces.linkRest(1);
    assert.ok(gap < rest * 2.2, 'edge ' + gap + ' rest ' + rest);
    let cx = 0;
    let cy = 0;
    placed.forEach((body) => {
        cx += body.x;
        cy += body.y;
    });
    cx /= placed.length;
    cy /= placed.length;
    let max = 0;
    placed.forEach((body) => {
        max = Math.max(max, Math.hypot(body.x - cx, body.y - cy));
    });
    const outlier = Math.hypot(map.get(41).x - cx, map.get(41).y - cy);
    assert.ok(outlier < max * 0.95 + rest, 'outlier ' + outlier + ' span ' + max);
});

test('wider spacing spreads the same links', () => {
    const tight = chain(24);
    const wide = chain(24);
    const a = forces.settle(tight.nodes, tight.edges, { spacing: 0.75 });
    const b = forces.settle(wide.nodes, wide.edges, { spacing: 1.6 });
    const mean = (bodies) => {
        const map = byId(bodies);
        let sum = 0;
        for (let i = 1; i < 24; i++) sum += dist(map.get(i), map.get(i + 1));
        return sum / 23;
    };
    assert.ok(mean(b) > mean(a) * 1.4);
});

test('collision leaves room for a long label', () => {
    const title = 'Galley inventory for the north dock';
    const nodes = [
        { id: 1, title, x: 0, y: 0 },
        { id: 2, title, x: 0.01, y: 0 },
    ];
    const placed = forces.settle(nodes, [], { spacing: 1 });
    const gap = dist(placed[0], placed[1]);
    const need = forces.bodyRadius(title, 1) * 2;
    assert.ok(gap >= need * 0.9, 'gap ' + gap + ' need ' + need);
});

test('an isolated page stays next to the main group', () => {
    const { nodes, edges } = chain(28);
    nodes.push({ id: 99, title: 'Lone harbor log', x: 20, y: 18 });
    const placed = forces.settle(nodes, edges, { spacing: 1 });
    const map = byId(placed);
    let cx = 0;
    let cy = 0;
    for (let i = 1; i <= 28; i++) {
        cx += map.get(i).x;
        cy += map.get(i).y;
    }
    cx /= 28;
    cy /= 28;
    let radius = 0;
    for (let i = 1; i <= 28; i++) {
        radius = Math.max(radius, Math.hypot(map.get(i).x - cx, map.get(i).y - cy));
    }
    const lone = Math.hypot(map.get(99).x - cx, map.get(99).y - cy);
    assert.ok(lone < radius + forces.linkRest(1) * 3, 'lone ' + lone + ' radius ' + radius);
    assert.ok(lone > radius * 0.5);
});

test('two hundred nodes settle with room between neighbors', () => {
    const { nodes, edges } = chain(220);
    const started = Date.now();
    const placed = forces.settle(nodes, edges, { spacing: 1 });
    assert.ok(Date.now() - started < 2500);
    assert.equal(placed.length, 220);
    const map = byId(placed);
    const gaps = [];
    for (let i = 1; i < 220; i++) gaps.push(dist(map.get(i), map.get(i + 1)));
    gaps.sort((a, b) => a - b);
    const median = gaps[Math.floor(gaps.length / 2)];
    assert.ok(median > 40, 'median ' + median);
});

test('labels truncate and follow smart zoom hover rules', () => {
    assert.equal(forces.truncateTitle('short'), 'short');
    assert.equal(forces.truncateTitle('Sailing across the gulf today'), 'Sailing across the gu\u2026');
    assert.equal(forces.truncateTitle('caf\u00e9 notes'), 'caf\u00e9 notes');
    const nodes = [];
    const edges = [];
    for (let i = 1; i <= 20; i++) nodes.push({ id: i, title: 'Page ' + i });
    for (let i = 2; i <= 20; i++) edges.push({ from: 1, to: i });
    edges.push({ from: 2, to: 3 });
    const overview = new Set(forces.visibleLabelIds(nodes, edges, { mode: 'smart', zoom: 1 }));
    assert.ok(overview.has(1));
    assert.equal(overview.has(20), false);
    const hover = new Set(forces.visibleLabelIds(nodes, edges, { mode: 'smart', zoom: 1, hoverId: 20 }));
    assert.ok(hover.has(20));
    assert.ok(hover.has(1));
    const mid = new Set(forces.visibleLabelIds(nodes, edges, { mode: 'smart', zoom: 1.3 }));
    assert.ok(mid.has(2));
    const close = forces.visibleLabelIds(nodes, edges, { mode: 'smart', zoom: 1.8 });
    assert.equal(close.length, 20);
    assert.deepEqual(forces.visibleLabelIds(nodes, edges, { mode: 'none', zoom: 3 }), []);
    assert.equal(forces.visibleLabelIds(nodes, edges, { mode: 'all', zoom: 0.2 }).length, 20);
    const tiny = nodes.slice(0, 8);
    assert.equal(forces.visibleLabelIds(tiny, [], { mode: 'smart', zoom: 1 }).length, 8);
});
