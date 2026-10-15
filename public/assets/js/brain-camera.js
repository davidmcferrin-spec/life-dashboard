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
    const MIN_K = 0.4;
    const MAX_K = 4;

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

    return {
        MIN_K,
        MAX_K,
        reset,
        toWorld,
        toScreen,
        zoomAt,
        panBy,
        fit,
        matrix,
        wheelFactor,
    };
});
