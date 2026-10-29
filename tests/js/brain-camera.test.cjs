const test = require('node:test');
const assert = require('node:assert/strict');
const cam = require('../../public/assets/js/brain-camera.js');

test('zoom keeps the point under the cursor and stays inside limits', () => {
    const cx = 200;
    const cy = 120;
    const px = 40;
    const py = 80;
    const identity = cam.reset();
    const world = cam.toWorld(identity, px, py, cx, cy);
    const zoomed = cam.zoomAt(identity, px, py, cx, cy, 2);
    const screen = cam.toScreen(zoomed, world.x, world.y, cx, cy);
    assert.ok(Math.abs(screen.x - px) < 0.001);
    assert.ok(Math.abs(screen.y - py) < 0.001);

    let tiny = identity;
    for (let i = 0; i < 40; i++) tiny = cam.zoomAt(tiny, cx, cy, cx, cy, 0.5);
    assert.equal(tiny.k, cam.MIN_K);

    let huge = identity;
    for (let i = 0; i < 40; i++) huge = cam.zoomAt(huge, cx, cy, cx, cy, 3);
    assert.equal(huge.k, cam.MAX_K);
});

test('fit centers the graph in the viewport', () => {
    const state = cam.fit(
        [{ x: 0, y: 0 }, { x: 100, y: 50 }],
        400,
        300,
        { left: 0, top: 0, width: 400, height: 300 }
    );
    const center = cam.toScreen(state, 50, 25, 200, 150);
    assert.ok(Math.abs(center.x - 200) < 0.02);
    assert.ok(Math.abs(center.y - 150) < 0.02);
    assert.ok(state.k >= cam.MIN_K && state.k <= cam.MAX_K);
});

test('focus parks a world point without changing zoom', () => {
    const state = { k: 2, x: 10, y: -4 };
    const next = cam.focus(state, 80, 40, 400, 300, 100, 120);
    assert.equal(next.k, 2);
    const screen = cam.toScreen(next, 80, 40, 200, 150);
    assert.ok(Math.abs(screen.x - 100) < 0.001);
    assert.ok(Math.abs(screen.y - 120) < 0.001);
});

test('pan and reset', () => {
    const moved = cam.panBy(cam.reset(), 12, -3);
    assert.deepEqual(moved, { k: 1, x: 12, y: -3 });
    assert.deepEqual(cam.reset(), { k: 1, x: 0, y: 0 });
});

test('mesh camera zooms, spins, pans, and idles', () => {
    const home = cam.meshReset();
    assert.deepEqual(home, { x: 0, y: 0, zoom: 1, rx: -7, ry: -12 });

    const closer = cam.meshZoomBy(home, cam.MESH_ZOOM_STEP);
    assert.equal(closer.zoom, 1.12);
    let huge = home;
    for (let i = 0; i < 40; i++) huge = cam.meshZoomBy(huge, 0.5);
    assert.equal(huge.zoom, cam.MESH_MAX_ZOOM);
    let tiny = home;
    for (let i = 0; i < 40; i++) tiny = cam.meshZoomBy(tiny, -0.5);
    assert.equal(tiny.zoom, cam.MESH_MIN_ZOOM);

    const wheeled = cam.meshWheel(home, 100);
    assert.ok(Math.abs(wheeled.zoom - 0.9) < 0.0001);

    const spun = cam.meshSpin(home, 10, 20);
    assert.equal(spun.ry, home.ry + 2.4);
    assert.ok(Math.abs(spun.rx - (home.rx - 3.6)) < 0.0001);
    const capped = cam.meshSpin(home, 0, -1000);
    assert.equal(capped.rx, 48);

    const panned = cam.meshPan(home, 12, -4);
    assert.equal(panned.x, 12);
    assert.equal(panned.y, -4);
    assert.equal(panned.zoom, 1);

    const turned = cam.meshIdle(home, 1000, false);
    assert.equal(turned.ry, home.ry + 4);
    assert.deepEqual(cam.meshIdle(home, 1000, true), home);

    const pinched = cam.meshPinch(home, 1, 100, 150);
    assert.equal(pinched.zoom, 1.5);

    const faced = cam.meshFace(home, 30, 10);
    assert.equal(faced.ry, -30);
    assert.equal(faced.rx, -10);
    assert.equal(faced.x, 0);
    assert.equal(faced.y, 0);
    assert.equal(faced.zoom, 1);
});
