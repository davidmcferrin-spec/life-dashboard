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

function near(a, b, tol) {
    assert.ok(Math.abs(a - b) < (tol || 0.02), a + ' vs ' + b);
}

test('mesh perspective stays near 48 degrees and fit frames the cloud', () => {
    const height = 900;
    const perspective = cam.meshPerspective(height, cam.MESH_FOV_DEG);
    const fov = 2 * Math.atan((height / 2) / perspective) * 180 / Math.PI;
    near(fov, 48, 0.05);
    assert.ok(perspective > height);

    const home = cam.meshReset();
    assert.equal(home.yaw, cam.MESH_HOME_YAW);
    assert.equal(home.pitch, cam.MESH_HOME_PITCH);

    const fitted = cam.meshFit(
        [{ x: -200, y: 0, z: 0 }, { x: 200, y: 40, z: -80 }],
        1200,
        800
    );
    const fitPerspective = cam.meshPerspective(800);
    const center = cam.meshProject(fitted, { x: 0, y: 20, z: -40 }, fitPerspective);
    near(center.x, 0, 1);
    near(center.y, 0, 1);
    const edge = cam.meshProject(fitted, { x: 200, y: 40, z: -80 }, fitPerspective);
    assert.ok(Math.hypot(edge.x, edge.y) > 100, 'fit left the graph tiny: ' + edge.x + ',' + edge.y);
    const wide = [];
    for (let i = 0; i < 8; i++) {
        wide.push({ x: Math.cos(i) * 900, y: Math.sin(i) * 700, z: (i - 4) * 140 });
    }
    const wideFit = cam.meshFit(wide, 1200, 800);
    let span = 0;
    wide.forEach((point) => {
        const screen = cam.meshProject(wideFit, point, fitPerspective);
        span = Math.max(span, Math.abs(screen.x), Math.abs(screen.y));
    });
    assert.ok(span > 280, 'wide fit span ' + span);
    assert.ok(fitted.dist >= cam.MESH_MIN_DIST && fitted.dist <= cam.MESH_MAX_DIST);
    assert.equal(cam.meshLod(fitted.dist, fitted.dist, 'node'), 'mid');
    assert.equal(cam.meshLod(fitted.dist * 0.4, fitted.dist, 'node'), 'near');
});

test('orbit, pan, and zoom anchor on the cursor instead of the world origin', () => {
    const perspective = cam.meshPerspective(800, cam.MESH_FOV_DEG);
    let camState = cam.meshTarget({ yaw: 20, pitch: -15, dist: 900, px: 30, py: -10, pz: 40, ax: 4, ay: -6 });
    const cursor = { x: 120, y: -70 };
    const grabbedPoint = cam.screenToPivotPlane(camState, cursor.x, cursor.y, perspective);
    const other = cam.screenToPivotPlane(camState, -40, 25, perspective);
    const beforeGrab = cam.meshProject(camState, grabbedPoint, perspective);
    const beforeOther = cam.meshProject(camState, other, perspective);
    near(beforeGrab.x, cursor.x, 0.05);
    near(beforeGrab.y, cursor.y, 0.05);

    const grabbed = cam.meshGrabPivot(camState, cursor.x, cursor.y, perspective);
    const afterGrab = cam.meshProject(grabbed, grabbedPoint, perspective);
    const afterOther = cam.meshProject(grabbed, other, perspective);
    near(afterGrab.x, beforeGrab.x, 0.05);
    near(afterGrab.y, beforeGrab.y, 0.05);
    near(afterOther.x, beforeOther.x, 0.08);
    near(afterOther.y, beforeOther.y, 0.08);

    const orbited = cam.meshOrbit(grabbed, 30, -10);
    const pivotScreen = cam.meshProject(orbited, { x: grabbed.px, y: grabbed.py, z: grabbed.pz }, perspective);
    near(pivotScreen.x, cursor.x, 0.05);
    near(pivotScreen.y, cursor.y, 0.05);
    assert.ok(Math.abs(orbited.yaw - grabbed.yaw) > 1);

    const zoomed = cam.meshZoomAt(camState, cursor.x, cursor.y, 1.35, perspective);
    const stayed = cam.meshProject(zoomed, grabbedPoint, perspective);
    near(stayed.x, cursor.x, 0.08);
    near(stayed.y, cursor.y, 0.08);
    assert.ok(zoomed.dist < camState.dist);

    let tiny = camState;
    for (let i = 0; i < 40; i++) tiny = cam.meshZoomAt(tiny, 0, 0, 0.4, perspective);
    assert.equal(tiny.dist, cam.MESH_MAX_DIST);
    let huge = camState;
    for (let i = 0; i < 40; i++) huge = cam.meshZoomAt(huge, 0, 0, 3, perspective);
    assert.equal(huge.dist, cam.MESH_MIN_DIST);

    const marker = { x: camState.px + 15, y: camState.py - 8, z: camState.pz };
    const pannedFrom = cam.meshProject(camState, marker, perspective);
    const panned = cam.meshPanBy(camState, 48, -22, perspective);
    const pannedTo = cam.meshProject(panned, marker, perspective);
    near(pannedTo.x - pannedFrom.x, 48, 0.4);
    near(pannedTo.y - pannedFrom.y, -22, 0.4);
});

test('fly look keeps the camera in place and damping settles', () => {
    const start = cam.meshTarget({ yaw: 10, pitch: -20, dist: 700, px: 40, py: 15, pz: -25 });
    const before = cam.meshCameraPosition(start);
    const looked = cam.meshLook(start, 25, 8);
    const after = cam.meshCameraPosition(looked);
    near(after.x, before.x, 0.05);
    near(after.y, before.y, 0.05);
    near(after.z, before.z, 0.05);
    assert.ok(Math.abs(looked.pitch) <= 80);

    const flown = cam.meshFlyBy(start, 1, 0, 0, 100);
    const moved = Math.hypot(flown.px - start.px, flown.py - start.py, flown.pz - start.pz);
    near(moved, 100, 0.05);

    let display = start;
    const goal = cam.meshFrame(start, { x: 300, y: -20, z: 80 }, cam.MESH_FRAME_DIST);
    let settled = false;
    for (let i = 0; i < 90; i++) {
        const step = cam.meshStep(display, goal, 16, cam.MESH_DAMP);
        display = step.camera;
        settled = step.settled;
        if (settled) break;
    }
    assert.equal(settled, true);
    near(display.px, 300, 0.5);
    near(display.dist, cam.MESH_FRAME_DIST, 0.5);
    assert.equal(cam.meshLevel(goal).yaw, cam.MESH_HOME_YAW);
    assert.equal(cam.meshLevel(goal).px, goal.px);
});

test('camera prefs survive blocked storage and bad json', () => {
    const home = cam.meshFit([{ x: 0, y: 0, z: 0 }, { x: 100, y: 0, z: 0 }], 1000, 800);
    const prefs = cam.meshExportPrefs(cam.meshTarget({ yaw: 400, pitch: -90, dist: home.dist * 2 }), {
        homeDist: home.dist,
        mode3d: false,
        fly: true,
    });
    assert.ok(prefs.distFactor <= 4);
    assert.equal(prefs.mode3d, false);
    assert.equal(prefs.fly, true);
    const applied = cam.meshApplyPrefs(home, prefs);
    near(applied.dist, home.dist * prefs.distFactor, 1);
    assert.equal(applied.pitch, -80);

    const bag = {
        value: null,
        getItem() { return this.value; },
        setItem(key, value) { this.value = value; },
    };
    assert.equal(cam.meshSavePrefs(bag, prefs), true);
    const loaded = cam.meshLoadPrefs(bag);
    assert.equal(loaded.fly, true);
    assert.equal(loaded.mode3d, false);
    bag.value = '{nope';
    assert.equal(cam.meshLoadPrefs(bag), null);
    assert.equal(cam.meshLoadPrefs({ getItem() { throw new Error('blocked'); } }), null);
    assert.equal(cam.meshSavePrefs({ setItem() { throw new Error('blocked'); } }, prefs), false);
    assert.equal(cam.meshNormalizePrefs({ yaw: 'no' }), null);
});

test('shortcuts ignore typing and map fly, focus, and cycling keys', () => {
    assert.equal(cam.meshShortcut({ code: 'KeyW', key: 'w', typing: true }), null);
    assert.equal(cam.meshShortcut({ code: 'KeyH', key: 'h', ctrl: true }), null);
    assert.equal(cam.meshShortcut({ code: 'Space', key: ' ', onControl: true }), null);

    const fly = cam.meshShortcut({ code: 'KeyW', key: 'w', shift: true });
    assert.equal(fly.kind, 'move');
    assert.equal(fly.forward, 1);
    assert.equal(fly.fast, true);
    const precise = cam.meshShortcut({ code: 'KeyE', key: 'e', alt: true });
    assert.equal(precise.vertical, 1);
    assert.equal(precise.precise, true);
    assert.equal(cam.meshShortcut({ code: 'ArrowLeft', key: 'ArrowLeft' }).strafe, -1);
    assert.equal(cam.meshShortcut({ code: 'KeyQ', key: 'q' }).vertical, -1);

    assert.equal(cam.meshShortcut({ code: 'KeyH', key: 'h' }).name, 'home');
    assert.equal(cam.meshShortcut({ code: 'Home', key: 'Home' }).name, 'home');
    assert.equal(cam.meshShortcut({ code: 'KeyH', key: 'h', repeat: true }), null);
    assert.equal(cam.meshShortcut({ code: 'KeyF', key: 'f' }).name, 'focus');
    assert.equal(cam.meshShortcut({ code: 'KeyR', key: 'r' }).name, 'level');
    assert.equal(cam.meshShortcut({ code: 'Space', key: ' ' }).name, 'toggle-fly');
    assert.equal(cam.meshShortcut({ code: 'Digit2', key: '2' }).name, 'mode-2d');
    assert.equal(cam.meshShortcut({ code: 'Digit3', key: '3' }).name, 'mode-3d');
    assert.equal(cam.meshShortcut({ code: 'Slash', key: '/' }).name, 'search');
    assert.equal(cam.meshShortcut({ code: 'Slash', key: '?', shift: true }).name, 'help');
    assert.equal(cam.meshShortcut({ code: 'Escape', key: 'Escape', helpOpen: true }).name, 'close-help');
    assert.equal(cam.meshShortcut({ code: 'Escape', key: 'Escape' }).name, 'clear');
    assert.equal(cam.meshShortcut({ code: 'KeyF', key: 'f', helpOpen: true }), null);
    assert.equal(cam.meshShortcut({ code: 'BracketLeft', key: '[' }).name, 'cycle-prev');
    assert.equal(cam.meshShortcut({ code: 'BracketRight', key: ']' }).name, 'cycle-next');
    assert.equal(cam.meshShortcut({ code: 'Tab', key: 'Tab' }), null);
    assert.equal(cam.meshShortcut({ code: 'Tab', key: 'Tab', stageFocused: true }).name, 'cycle-next');
    assert.equal(cam.meshShortcut({ code: 'Tab', key: 'Tab', stageFocused: true, shift: true }).name, 'cycle-prev');
    assert.equal(cam.meshShortcut({ code: 'Equal', key: '+' }).kind, 'zoom');
    assert.ok(cam.meshShortcut({ code: 'Minus', key: '-' }).factor < 1);

    const held = cam.meshHeldMove(['KeyW', 'KeyD', 'ShiftLeft']);
    assert.equal(held.forward, 1);
    assert.equal(held.strafe, 1);
    assert.equal(held.fast, true);
    assert.equal(cam.meshHeldMove(['ShiftLeft']), null);

    const perspective = cam.meshPerspective(700);
    const nudged = cam.meshNudge(cam.meshReset(), 'pan', { forward: 1, strafe: 0, vertical: 0 }, 40, perspective);
    const from = cam.meshProject(cam.meshReset(), { x: 0, y: 0, z: 0 }, perspective);
    const to = cam.meshProject(nudged, { x: 0, y: 0, z: 0 }, perspective);
    assert.ok(to.y > from.y);
});
