const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const sphere = require('../../public/assets/js/brain-sphere.js');

test('sphere points sit on the radius and links meet them', () => {
    const radius = 96;
    const seen = new Set();
    for (let i = 0; i < 12; i++) {
        const point = sphere.placeOnSphere(i, 12, radius);
        const dist = Math.hypot(point.x, point.y, point.z);
        assert.ok(Math.abs(dist - radius) < 0.001);
        const key = point.x.toFixed(3) + ':' + point.y.toFixed(3) + ':' + point.z.toFixed(3);
        assert.equal(seen.has(key), false);
        seen.add(key);
        const back = sphere.cssPoint(point.az, point.el, point.radius);
        assert.ok(Math.abs(back.x - point.x) < 0.001);
        assert.ok(Math.abs(back.y - point.y) < 0.001);
        assert.ok(Math.abs(back.z - point.z) < 0.001);
    }

    const origin = { x: 0, y: 0, z: 0 };
    const target = sphere.cssPoint(40, -15, 80);
    const pose = sphere.linkPose(origin, target);
    const end = sphere.cssPoint(pose.az, pose.el, pose.length);
    assert.ok(Math.abs(end.x - target.x) < 0.001);
    assert.ok(Math.abs(end.y - target.y) < 0.001);
    assert.ok(Math.abs(end.z - target.z) < 0.001);
});

test('one cloud per folder and domains for loose pages', () => {
    const clouds = sphere.cloudsFrom([
        { id: 1, title: 'A', folder_id: 4, folder: 'Research', cluster: 'kind:note' },
        { id: 2, title: 'B', folder_id: 4, folder: 'Research', cluster: 'link:9' },
        { id: 3, title: 'C', folder_id: null, cluster: 'page:3' },
    ], [{ key: 'page:3', label: 'C' }]);
    assert.equal(clouds.length, 2);
    const research = clouds.find((cloud) => cloud.key === 'folder:4');
    assert.equal(research.nodes.length, 2);
    assert.equal(research.label, 'Research');
    const loose = clouds.find((cloud) => cloud.key === 'domain:page:3');
    assert.equal(loose.label, 'C');
});

test('projects and recent filters keep the project type', () => {
    const now = Date.parse('2026-10-23T20:00:00Z');
    assert.equal(sphere.passesFilter({ kind: 'project', updated_at: '2026-10-01 00:00:00' }, 'projects', now), true);
    assert.equal(sphere.passesFilter({ kind: 'note', updated_at: '2026-10-23 19:00:00' }, 'projects', now), false);
    assert.equal(sphere.designType('daily'), 'note');
    assert.equal(sphere.passesFilter({ kind: 'note', updated_at: '2026-10-23 19:00:00' }, 'recent', now), true);
    assert.equal(sphere.passesFilter({ kind: 'note', updated_at: '2026-01-01 00:00:00' }, 'recent', now), false);
    assert.equal(sphere.passesFilter({ kind: 'note' }, 'all', now), true);
});

test('large clouds cap nodes and simplify links', () => {
    const nodes = [];
    const edges = [];
    for (let i = 1; i <= 80; i++) {
        nodes.push({ id: i, updated_at: '2026-10-01 00:00:' + String(i).padStart(2, '0') });
        if (i > 1) edges.push({ from: 1, to: i });
    }
    const ids = sphere.capNodes(nodes, edges, { cap: 10, selectedId: 80 });
    assert.equal(ids.length, 10);
    assert.equal(ids[0], 80);
    const links = sphere.capLinks(edges, ids, { cap: 4, hotIds: [80] });
    assert.ok(links.length <= 4);
    assert.ok(links.every((edge) => edge.from === 80 || edge.to === 80 || ids.includes(edge.from)));
});

test('graph screen keeps the figma controls and spells vault correctly', () => {
    const html = fs.readFileSync(path.join(__dirname, '../../templates/brain-graph.php'), 'utf8');
    assert.match(html, /Search the brain/);
    assert.match(html, /All types/);
    assert.match(html, /Recent/);
    assert.match(html, /Projects/);
    assert.match(html, /Zoom in/);
    assert.match(html, /Zoom out/);
    assert.match(html, /Reset camera/);
    assert.match(html, /Pause live updates/);
    assert.match(html, /NEURAL MESH LIVE/);
    assert.match(html, /FIRING CONNECTIONS/);
    assert.match(html, /Open in Vault/);
    assert.match(html, /brain-horizon/);
    assert.match(html, /neural-world/);
    assert.doesNotMatch(html, /Alex Sterling/);
    assert.doesNotMatch(html, /Open in Valut/);
    assert.doesNotMatch(html, /brain\.png/);
    const head = fs.readFileSync(path.join(__dirname, '../../templates/partials/head.php'), 'utf8');
    assert.match(head, /\$assetVer = '20261023bs'/);
});
