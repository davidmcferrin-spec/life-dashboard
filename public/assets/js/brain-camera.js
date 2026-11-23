/**
 * Pan and zoom for the vault map. Screen space stays CSS pixels.
 * Identity (k = 1, x = 0, y = 0) draws the graph where the layout placed it.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainCamera = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const MIN_K = 0.18;
    const MAX_K = 6;

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function reset() {
        return { k: 1, x: 0, y: 0 };
    }

    function toWorld(state, sx, sy, cx, cy) {
        return {
            x: (sx - cx * (1 - state.k) - state.x) / state.k,
            y: (sy - cy * (1 - state.k) - state.y) / state.k,
        };
    }

    function toScreen(state, wx, wy, cx, cy) {
        return {
            x: cx + (wx - cx) * state.k + state.x,
            y: cy + (wy - cy) * state.k + state.y,
        };
    }

    function zoomAt(state, px, py, cx, cy, factor) {
        const nextK = clamp(state.k * factor, MIN_K, MAX_K);
        const world = toWorld(state, px, py, cx, cy);
        return {
            k: nextK,
            x: px - cx - (world.x - cx) * nextK,
            y: py - cy - (world.y - cy) * nextK,
        };
    }

    function panBy(state, dx, dy) {
        return { k: state.k, x: state.x + dx, y: state.y + dy };
    }

    /**
     * Park a layout-space point on a screen destination without changing zoom.
     * wx/wy are the same pixels project() uses before the camera runs.
     */
    function focus(state, wx, wy, width, height, destX, destY) {
        const cx = width / 2;
        const cy = height / 2;
        const k = state.k;
        return {
            k,
            x: destX - cx - (wx - cx) * k,
            y: destY - cy - (wy - cy) * k,
        };
    }

    /**
     * Fit world-space points into a viewport rectangle on a canvas of width/height.
     * points are {x, y} in the same space project() uses at identity.
     */
    function fit(points, width, height, viewport) {
        if (!points || points.length === 0) {
            return reset();
        }
        let minX = Infinity;
        let maxX = -Infinity;
        let minY = Infinity;
        let maxY = -Infinity;
        points.forEach((point) => {
            minX = Math.min(minX, point.x);
            maxX = Math.max(maxX, point.x);
            minY = Math.min(minY, point.y);
            maxY = Math.max(maxY, point.y);
        });
        const pad = 28;
        const availW = Math.max(40, viewport.width - pad * 2);
        const availH = Math.max(40, viewport.height - pad * 2);
        const contentW = Math.max(1, maxX - minX);
        const contentH = Math.max(1, maxY - minY);
        const k = clamp(Math.min(availW / contentW, availH / contentH), MIN_K, MAX_K);
        const boxCx = (minX + maxX) / 2;
        const boxCy = (minY + maxY) / 2;
        const destCx = viewport.left + viewport.width / 2;
        const destCy = viewport.top + viewport.height / 2;
        const cx = width / 2;
        const cy = height / 2;
        return {
            k,
            x: destCx - cx - (boxCx - cx) * k,
            y: destCy - cy - (boxCy - cy) * k,
        };
    }

    function matrix(state, width, height, dpr) {
        const cx = width / 2;
        const cy = height / 2;
        return {
            a: dpr * state.k,
            b: 0,
            c: 0,
            d: dpr * state.k,
            e: dpr * (cx * (1 - state.k) + state.x),
            f: dpr * (cy * (1 - state.k) + state.y),
        };
    }

    function wheelFactor(deltaY, deltaMode) {
        const pixels = deltaMode === 1 ? deltaY * 16 : deltaMode === 2 ? deltaY * (typeof window !== 'undefined' ? window.innerHeight : 800) : deltaY;
        const factor = Math.exp(-pixels * 0.0016);
        return clamp(factor, 0.82, 1.22);
    }

    const MESH_FOV_DEG = 48;
    const MESH_MIN_DIST = 280;
    const MESH_MAX_DIST = 12000;
    const MESH_PITCH_MIN = -80;
    const MESH_PITCH_MAX = 80;
    const MESH_HOME_YAW = 28;
    const MESH_HOME_PITCH = -32;
    const MESH_DAMP = 9;
    const MESH_FRAME_DIST = 520;
    const MESH_CLOSE_DIST = 340;
    const MESH_ZOOM_STEP = 1.12;
    const MESH_PREF_KEY = 'ld_brain_mesh_camera';

    function meshClone(state) {
        return {
            px: state.px, py: state.py, pz: state.pz,
            ax: state.ax, ay: state.ay,
            yaw: state.yaw, pitch: state.pitch, dist: state.dist,
        };
    }

    function meshTarget(partial) {
        const extra = partial || {};
        return {
            px: Number(extra.px) || 0,
            py: Number(extra.py) || 0,
            pz: Number(extra.pz) || 0,
            ax: Number(extra.ax) || 0,
            ay: Number(extra.ay) || 0,
            yaw: Number.isFinite(Number(extra.yaw)) ? Number(extra.yaw) : MESH_HOME_YAW,
            pitch: Number.isFinite(Number(extra.pitch)) ? Number(extra.pitch) : MESH_HOME_PITCH,
            dist: clamp(Number(extra.dist) || 1400, MESH_MIN_DIST, MESH_MAX_DIST),
        };
    }

    function meshReset() {
        return meshTarget();
    }

    function rad(deg) {
        return deg * Math.PI / 180;
    }

    function rotX(p, pitchDeg) {
        const a = rad(pitchDeg);
        const c = Math.cos(a);
        const s = Math.sin(a);
        return { x: p.x, y: p.y * c - p.z * s, z: p.y * s + p.z * c };
    }

    function rotY(p, yawDeg) {
        const a = rad(yawDeg);
        const c = Math.cos(a);
        const s = Math.sin(a);
        return { x: p.x * c + p.z * s, y: p.y, z: -p.x * s + p.z * c };
    }

    function worldToCamera(cam, p) {
        let v = { x: p.x - cam.px, y: p.y - cam.py, z: p.z - cam.pz };
        v = rotY(v, cam.yaw);
        v = rotX(v, cam.pitch);
        return v;
    }

    function cameraToWorld(cam, v) {
        let p = rotX(v, -cam.pitch);
        p = rotY(p, -cam.yaw);
        return { x: p.x + cam.px, y: p.y + cam.py, z: p.z + cam.pz };
    }

    function meshPerspective(height, fovDeg) {
        const h = Math.max(1, Number(height) || 1);
        const fov = Number(fovDeg) > 0 ? Number(fovDeg) : MESH_FOV_DEG;
        return (h / 2) / Math.tan(rad(fov) / 2);
    }

    function viewScale(cam, perspective) {
        const p = Number(perspective) > 0 ? Number(perspective) : 1;
        return p / (p + cam.dist);
    }

    function meshProject(cam, world, perspective) {
        const c = worldToCamera(cam, world);
        const z = c.z - cam.dist;
        const denom = perspective - z;
        if (Math.abs(denom) < 0.0001) return { x: cam.ax, y: cam.ay, behind: true, scale: 0 };
        const s = perspective / denom;
        return { x: cam.ax + c.x * s, y: cam.ay + c.y * s, behind: denom <= 0, scale: s };
    }

    function screenToPivotPlane(cam, sx, sy, perspective) {
        const scale = viewScale(cam, perspective);
        const safe = Math.abs(scale) < 1e-6 ? 1e-6 : scale;
        return cameraToWorld(cam, {
            x: (sx - cam.ax) / safe,
            y: (sy - cam.ay) / safe,
            z: 0,
        });
    }

    function meshZoomAt(cam, sx, sy, factor, perspective) {
        const f = Number(factor);
        if (!Number.isFinite(f) || f <= 0) return meshClone(cam);
        const dist = clamp(cam.dist / f, MESH_MIN_DIST, MESH_MAX_DIST);
        const oldScale = viewScale(cam, perspective);
        const newScale = perspective / (perspective + dist);
        const safe = Math.abs(oldScale) < 1e-6 ? 1e-6 : oldScale;
        const cx = (sx - cam.ax) / safe;
        const cy = (sy - cam.ay) / safe;
        return {
            px: cam.px, py: cam.py, pz: cam.pz,
            yaw: cam.yaw, pitch: cam.pitch,
            dist: dist,
            ax: sx - cx * newScale,
            ay: sy - cy * newScale,
        };
    }

    function meshGrabPivot(cam, sx, sy, perspective) {
        const point = screenToPivotPlane(cam, sx, sy, perspective);
        return {
            px: point.x, py: point.y, pz: point.z,
            ax: sx, ay: sy,
            yaw: cam.yaw, pitch: cam.pitch, dist: cam.dist,
        };
    }

    function meshOrbit(cam, dx, dy) {
        return {
            px: cam.px, py: cam.py, pz: cam.pz,
            ax: cam.ax, ay: cam.ay,
            yaw: cam.yaw + dx * 0.22,
            pitch: clamp(cam.pitch + dy * 0.16, MESH_PITCH_MIN, MESH_PITCH_MAX),
            dist: cam.dist,
        };
    }

    function meshPanBy(cam, dx, dy, perspective) {
        const scale = viewScale(cam, perspective);
        const safe = Math.abs(scale) < 1e-6 ? 1e-6 : scale;
        const shift = cameraToWorld({ px: 0, py: 0, pz: 0, ax: 0, ay: 0, yaw: cam.yaw, pitch: cam.pitch, dist: cam.dist }, {
            x: -dx / safe,
            y: -dy / safe,
            z: 0,
        });
        return {
            px: cam.px + shift.x,
            py: cam.py + shift.y,
            pz: cam.pz + shift.z,
            ax: cam.ax, ay: cam.ay,
            yaw: cam.yaw, pitch: cam.pitch, dist: cam.dist,
        };
    }

    function meshFlyBy(cam, forward, strafe, vertical, distance) {
        const step = Number(distance) || 0;
        const shift = cameraToWorld({ px: 0, py: 0, pz: 0, ax: 0, ay: 0, yaw: cam.yaw, pitch: cam.pitch, dist: cam.dist }, {
            x: strafe * step,
            y: -vertical * step,
            z: -forward * step,
        });
        return {
            px: cam.px + shift.x,
            py: cam.py + shift.y,
            pz: cam.pz + shift.z,
            ax: cam.ax, ay: cam.ay,
            yaw: cam.yaw, pitch: cam.pitch, dist: cam.dist,
        };
    }

    function meshLook(cam, dYaw, dPitch) {
        const pos = cameraToWorld(cam, { x: 0, y: 0, z: cam.dist });
        const next = {
            px: cam.px, py: cam.py, pz: cam.pz,
            ax: cam.ax, ay: cam.ay,
            yaw: cam.yaw + dYaw,
            pitch: clamp(cam.pitch + dPitch, MESH_PITCH_MIN, MESH_PITCH_MAX),
            dist: cam.dist,
        };
        const offset = cameraToWorld({ px: 0, py: 0, pz: 0, ax: 0, ay: 0, yaw: next.yaw, pitch: next.pitch, dist: next.dist }, {
            x: 0, y: 0, z: cam.dist,
        });
        next.px = pos.x - offset.x;
        next.py = pos.y - offset.y;
        next.pz = pos.z - offset.z;
        return next;
    }

    function meshCameraPosition(cam) {
        return cameraToWorld(cam, { x: 0, y: 0, z: cam.dist });
    }

    function meshFrame(cam, point, dist) {
        return {
            px: point.x, py: point.y, pz: point.z,
            ax: 0, ay: 0,
            yaw: cam.yaw, pitch: cam.pitch,
            dist: clamp(Number(dist) || MESH_FRAME_DIST, MESH_MIN_DIST, MESH_MAX_DIST),
        };
    }

    function meshLevel(cam) {
        return {
            px: cam.px, py: cam.py, pz: cam.pz,
            ax: cam.ax, ay: cam.ay,
            yaw: MESH_HOME_YAW, pitch: MESH_HOME_PITCH,
            dist: cam.dist,
        };
    }

    function meshFit(points, width, height, fovDeg) {
        const list = points && points.length ? points : [{ x: 0, y: 0, z: 0 }];
        let cx = 0;
        let cy = 0;
        let cz = 0;
        list.forEach((point) => {
            cx += Number(point.x) || 0;
            cy += Number(point.y) || 0;
            cz += Number(point.z) || 0;
        });
        cx /= list.length;
        cy /= list.length;
        cz /= list.length;
        const fov = Number(fovDeg) > 0 ? Number(fovDeg) : MESH_FOV_DEG;
        const perspective = meshPerspective(Math.max(1, height), fov);
        const padX = Math.min(110, Math.max(36, width * 0.07));
        const padY = Math.min(84, Math.max(36, height * 0.08));
        const limitX = Math.max(48, width / 2 - padX);
        const limitY = Math.max(48, height / 2 - padY);
        let lo = MESH_MIN_DIST;
        let hi = MESH_MAX_DIST;
        let best = hi;
        for (let step = 0; step < 22; step++) {
            const mid = (lo + hi) / 2;
            const cam = {
                px: cx, py: cy, pz: cz,
                ax: 0, ay: 0,
                yaw: MESH_HOME_YAW,
                pitch: MESH_HOME_PITCH,
                dist: mid,
            };
            let ok = true;
            for (let i = 0; i < list.length; i++) {
                const screen = meshProject(cam, list[i], perspective);
                if (screen.behind || Math.abs(screen.x) > limitX || Math.abs(screen.y) > limitY) {
                    ok = false;
                    break;
                }
            }
            if (ok) {
                best = mid;
                hi = mid;
            } else {
                lo = mid;
            }
        }
        return meshTarget({
            px: cx, py: cy, pz: cz,
            ax: 0, ay: 0,
            yaw: MESH_HOME_YAW,
            pitch: MESH_HOME_PITCH,
            dist: best,
        });
    }

    function meshPinchGesture(start, panX, panY, factor, sx, sy, perspective) {
        return meshZoomAt(meshPanBy(start, panX, panY, perspective), sx, sy, factor, perspective);
    }

    function meshNudge(cam, mode, move, pixels, perspective) {
        const step = Number(pixels) || 0;
        const forward = Number(move.forward) || 0;
        const strafe = Number(move.strafe) || 0;
        const vertical = Number(move.vertical) || 0;
        const diagonal = (forward && strafe) ? (1 / Math.sqrt(2)) : 1;
        if (mode === 'fly') {
            return meshFlyBy(cam, forward * diagonal, strafe * diagonal, vertical, step);
        }
        let next = cam;
        if (strafe) next = meshPanBy(next, -strafe * step * diagonal, 0, perspective);
        if (forward) next = meshPanBy(next, 0, forward * step * diagonal, perspective);
        if (vertical) next = { ...meshClone(next), py: next.py - vertical * step };
        return next;
    }

    function angleDelta(a, b) {
        let d = b - a;
        while (d > 180) d -= 360;
        while (d < -180) d += 360;
        return d;
    }

    function meshSettled(current, target) {
        const pos = Math.hypot(current.px - target.px, current.py - target.py, current.pz - target.pz);
        const ang = Math.abs(angleDelta(current.yaw, target.yaw)) + Math.abs(current.pitch - target.pitch);
        const anchor = Math.hypot(current.ax - target.ax, current.ay - target.ay);
        return pos < 0.45 && ang < 0.08 && Math.abs(current.dist - target.dist) < 0.6 && anchor < 0.45;
    }

    function meshStep(current, target, dtMs, lambda) {
        const dt = Number(dtMs);
        if (!Number.isFinite(dt) || dt <= 0) return { camera: meshClone(current), settled: false };
        if (meshSettled(current, target)) return { camera: meshClone(target), settled: true };
        const rate = Number(lambda) > 0 ? Number(lambda) : MESH_DAMP;
        const t = 1 - Math.exp(-rate * Math.min(dt, 80) / 1000);
        const camera = {
            px: current.px + (target.px - current.px) * t,
            py: current.py + (target.py - current.py) * t,
            pz: current.pz + (target.pz - current.pz) * t,
            ax: current.ax + (target.ax - current.ax) * t,
            ay: current.ay + (target.ay - current.ay) * t,
            yaw: current.yaw + angleDelta(current.yaw, target.yaw) * t,
            pitch: current.pitch + (target.pitch - current.pitch) * t,
            dist: current.dist + (target.dist - current.dist) * t,
        };
        if (meshSettled(camera, target)) return { camera: meshClone(target), settled: true };
        return { camera: camera, settled: false };
    }

    function meshLod(distance, homeDist, kind) {
        const basis = Math.max(400, Number(homeDist) || 1400);
        const ratio = (Number(distance) || 0) / basis;
        if (kind === 'cloud') {
            if (ratio > 2.4) return 'far';
            if (ratio > 1.45) return 'mid';
            return 'near';
        }
        if (ratio > 1.45) return 'far';
        if (ratio > 0.62) return 'mid';
        return 'near';
    }

    function wrapYaw(yaw) {
        let y = Number(yaw) || 0;
        while (y > 180) y -= 360;
        while (y < -180) y += 360;
        return y;
    }

    function meshExportPrefs(cam, extra) {
        const info = extra || {};
        const homeDist = Number(info.homeDist) > 0 ? Number(info.homeDist) : cam.dist;
        return {
            yaw: Math.round(wrapYaw(cam.yaw) * 100) / 100,
            pitch: Math.round(clamp(cam.pitch, MESH_PITCH_MIN, MESH_PITCH_MAX) * 100) / 100,
            distFactor: Math.round(clamp(cam.dist / homeDist, 0.35, 4) * 1000) / 1000,
            mode3d: info.mode3d !== false,
            fly: info.fly === true,
        };
    }

    function meshNormalizePrefs(raw) {
        if (!raw || typeof raw !== 'object') return null;
        const yaw = Number(raw.yaw);
        const pitch = Number(raw.pitch);
        const distFactor = Number(raw.distFactor);
        if (!Number.isFinite(yaw) || !Number.isFinite(pitch) || !Number.isFinite(distFactor)) return null;
        return {
            yaw: wrapYaw(yaw),
            pitch: clamp(pitch, MESH_PITCH_MIN, MESH_PITCH_MAX),
            distFactor: clamp(distFactor, 0.35, 4),
            mode3d: raw.mode3d !== false,
            fly: raw.fly === true,
        };
    }

    function meshApplyPrefs(homeCam, prefs) {
        const clean = meshNormalizePrefs(prefs);
        if (!clean) return meshClone(homeCam);
        return meshTarget({
            px: homeCam.px, py: homeCam.py, pz: homeCam.pz,
            ax: 0, ay: 0,
            yaw: clean.yaw,
            pitch: clean.pitch,
            dist: homeCam.dist * clean.distFactor,
        });
    }

    function meshLoadPrefs(storage) {
        try {
            if (!storage || typeof storage.getItem !== 'function') return null;
            const raw = storage.getItem(MESH_PREF_KEY);
            if (raw == null || raw === '') return null;
            return meshNormalizePrefs(JSON.parse(raw));
        } catch (err) {
            return null;
        }
    }

    function meshSavePrefs(storage, prefs) {
        try {
            if (!storage || typeof storage.setItem !== 'function') return false;
            storage.setItem(MESH_PREF_KEY, JSON.stringify(prefs));
            return true;
        } catch (err) {
            return false;
        }
    }

    function meshHeldMove(codes) {
        const set = new Set(codes || []);
        let forward = 0;
        let strafe = 0;
        let vertical = 0;
        if (set.has('KeyW') || set.has('ArrowUp')) forward += 1;
        if (set.has('KeyS') || set.has('ArrowDown')) forward -= 1;
        if (set.has('KeyA') || set.has('ArrowLeft')) strafe -= 1;
        if (set.has('KeyD') || set.has('ArrowRight')) strafe += 1;
        if (set.has('KeyQ')) vertical -= 1;
        if (set.has('KeyE')) vertical += 1;
        if (!forward && !strafe && !vertical) return null;
        return {
            forward: forward,
            strafe: strafe,
            vertical: vertical,
            fast: set.has('ShiftLeft') || set.has('ShiftRight'),
            precise: set.has('AltLeft') || set.has('AltRight'),
        };
    }

    function meshShortcut(input) {
        const ev = input || {};
        if (ev.typing) return null;
        if (ev.meta || ev.ctrl) return null;
        const code = String(ev.code || '');
        const key = String(ev.key || '');
        const helpOpen = ev.helpOpen === true;
        if ((key === '?' || (code === 'Slash' && ev.shift && key === '?')) && !ev.repeat) {
            return { kind: 'action', name: 'help' };
        }
        if (helpOpen && key !== 'Escape' && code !== 'Escape') return null;
        const oneShot = (name) => (ev.repeat ? null : { kind: 'action', name: name });
        if (code === 'KeyW' || code === 'KeyA' || code === 'KeyS' || code === 'KeyD' || code === 'KeyQ' || code === 'KeyE' || code === 'ArrowUp' || code === 'ArrowDown' || code === 'ArrowLeft' || code === 'ArrowRight') {
            const move = meshHeldMove([code].concat(ev.shift ? ['ShiftLeft'] : [], ev.alt ? ['AltLeft'] : []));
            return move ? { kind: 'move', forward: move.forward, strafe: move.strafe, vertical: move.vertical, fast: !!ev.shift, precise: !!ev.alt } : null;
        }
        if (code === 'Equal' || code === 'NumpadAdd' || key === '+' || key === '=') {
            return ev.repeat ? { kind: 'zoom', factor: MESH_ZOOM_STEP } : { kind: 'zoom', factor: MESH_ZOOM_STEP };
        }
        if (code === 'Minus' || code === 'NumpadSubtract' || key === '-' || key === '_') {
            return { kind: 'zoom', factor: 1 / MESH_ZOOM_STEP };
        }
        if (code === 'Home' || code === 'KeyH') return oneShot('home');
        if (code === 'KeyF') return oneShot('focus');
        if (code === 'KeyR') return oneShot('level');
        if (code === 'Space') {
            if (ev.onControl) return null;
            return oneShot('toggle-fly');
        }
        if (code === 'Digit2' || code === 'Numpad2') return oneShot('mode-2d');
        if (code === 'Digit3' || code === 'Numpad3') return oneShot('mode-3d');
        if (code === 'Slash' && !ev.shift) return oneShot('search');
        if (code === 'Escape' || key === 'Escape') return oneShot(helpOpen ? 'close-help' : 'clear');
        if (code === 'BracketLeft') return oneShot('cycle-prev');
        if (code === 'BracketRight') return oneShot('cycle-next');
        if (code === 'Tab') {
            if (!ev.stageFocused) return null;
            return oneShot(ev.shift ? 'cycle-prev' : 'cycle-next');
        }
        return null;
    }

    return {
        MIN_K,
        MAX_K,
        reset,
        toWorld,
        toScreen,
        zoomAt,
        panBy,
        focus,
        fit,
        matrix,
        wheelFactor,
        MESH_FOV_DEG,
        MESH_MIN_DIST,
        MESH_MAX_DIST,
        MESH_HOME_YAW,
        MESH_HOME_PITCH,
        MESH_DAMP,
        MESH_FRAME_DIST,
        MESH_CLOSE_DIST,
        MESH_ZOOM_STEP,
        MESH_PREF_KEY,
        meshReset,
        meshTarget,
        meshClone,
        meshPerspective,
        meshProject,
        screenToPivotPlane,
        meshZoomAt,
        meshGrabPivot,
        meshOrbit,
        meshPanBy,
        meshFlyBy,
        meshLook,
        meshCameraPosition,
        meshFrame,
        meshLevel,
        meshFit,
        meshPinchGesture,
        meshNudge,
        meshSettled,
        meshStep,
        meshLod,
        meshExportPrefs,
        meshNormalizePrefs,
        meshApplyPrefs,
        meshLoadPrefs,
        meshSavePrefs,
        meshHeldMove,
        meshShortcut,
    };
});
