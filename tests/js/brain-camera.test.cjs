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

test('pan and reset', () => {
    const moved = cam.panBy(cam.reset(), 12, -3);
    assert.deepEqual(moved, { k: 1, x: 12, y: -3 });
    assert.deepEqual(cam.reset(), { k: 1, x: 0, y: 0 });
});
