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

test('clusters spread in three dimensions and linked pages stay closer', () => {
    const nodes = [];
    const edges = [];
    for (let folder = 1; folder <= 6; folder++) {
        for (let n = 0; n < 4; n++) {
            const id = folder * 10 + n;
            nodes.push({
                id: id,
                title: 'Folder ' + folder + ' note ' + n + ' with a long label',
                folder_id: folder,
                folder: 'Folder ' + folder,
                kind: 'note',
            });
            if (n > 0) edges.push({ from: folder * 10, to: id });
        }
    }
    edges.push({ from: 10, to: 20 });
    const layout = sphere.layoutWorld(nodes, edges, []);
    assert.equal(layout.clouds.length, 6);
    const again = sphere.layoutWorld(nodes, edges, []);
    assert.equal(again.clouds[3].x, layout.clouds[3].x);
    assert.equal(again.positions.get(12).z, layout.positions.get(12).z);

    const zs = layout.clouds.map((cloud) => cloud.z);
    const xs = layout.clouds.map((cloud) => cloud.x);
    const zSpan = Math.max(...zs) - Math.min(...zs);
    const xSpan = Math.max(...xs) - Math.min(...xs);
    assert.ok(zSpan > 200, 'z span ' + zSpan);
    assert.ok(zSpan > xSpan * 0.35, 'flat ring z ' + zSpan + ' x ' + xSpan);

    function meanPair(ids) {
        let sum = 0;
        let count = 0;
        for (let i = 0; i < ids.length; i++) {
            for (let j = i + 1; j < ids.length; j++) {
                const a = layout.positions.get(ids[i]);
                const b = layout.positions.get(ids[j]);
                sum += Math.hypot(a.x - b.x, a.y - b.y, a.z - b.z);
                count += 1;
            }
        }
        return sum / count;
    }
    const intra = meanPair([10, 11, 12, 13]);
    const a = layout.clouds.find((cloud) => cloud.key === 'folder:1');
    const c = layout.clouds.find((cloud) => cloud.key === 'folder:6');
    const inter = Math.hypot(a.x - c.x, a.y - c.y, a.z - c.z);
    assert.ok(intra < inter * 0.55, 'intra ' + intra + ' inter ' + inter);

    let minGap = Infinity;
    const ids = [10, 11, 12, 13];
    for (let i = 0; i < ids.length; i++) {
        for (let j = i + 1; j < ids.length; j++) {
            const left = layout.positions.get(ids[i]);
            const right = layout.positions.get(ids[j]);
            minGap = Math.min(minGap, Math.hypot(left.x - right.x, left.y - right.y, left.z - right.z));
        }
    }
    assert.ok(minGap > 60, 'label gap ' + minGap);

    const linked = layout.clouds.find((cloud) => cloud.key === 'folder:2');
    const far = layout.clouds.find((cloud) => cloud.key === 'folder:5');
    const nearLink = Math.hypot(a.x - linked.x, a.y - linked.y, a.z - linked.z);
    const farLink = Math.hypot(a.x - far.x, a.y - far.y, a.z - far.z);
    assert.ok(nearLink < farLink, 'bridge ' + nearLink + ' vs ' + farLink);
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
    assert.match(html, /id="brain-home"/);
    assert.match(html, />Home</);
    assert.match(html, /id="brain-help-open"/);
    assert.match(html, /id="brain-help"/);
    assert.match(html, /Free-fly/);
    assert.match(html, /WASD/);
    assert.match(html, /Shift/);
    assert.match(html, /Pause live updates/);
    const mesh = fs.readFileSync(path.join(__dirname, '../../public/assets/js/brain-mesh.js'), 'utf8');
    assert.match(mesh, /document\.hidden/);
    assert.match(mesh, /meshGrabPivot/);
    assert.match(mesh, /meshLoadPrefs/);
    assert.match(mesh, /is-idle/);
    assert.doesNotMatch(mesh, /meshIdle/);
    const css = fs.readFileSync(path.join(__dirname, '../../public/assets/css/brain-mesh.css'), 'utf8');
    assert.match(css, /--scene-perspective/);
    assert.match(css, /animation-play-state:\s*paused/);
    assert.match(css, /lod-far/);
    assert.doesNotMatch(css, /margin-top:\s*-7px/);
    assert.match(html, /NEURAL MESH LIVE/);
    assert.match(html, /FIRING CONNECTIONS/);
    assert.match(html, /Open in Vault/);
    assert.match(html, /brain-horizon/);
    assert.match(html, /neural-world/);
    assert.doesNotMatch(html, /Alex Sterling/);
    assert.doesNotMatch(html, /Open in Valut/);
    assert.doesNotMatch(html, /brain\.png/);
    const head = fs.readFileSync(path.join(__dirname, '../../templates/partials/head.php'), 'utf8');
    assert.match(head, /AssetStamp::current\(\)/);
    assert.doesNotMatch(head, /\$assetVer = '20261023bs'/);
});
