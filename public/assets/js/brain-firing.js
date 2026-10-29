/**
 * Live read and write pulses on the vault mesh.
 * Strength falls to zero across a couple of seconds. Rates use a rolling window.
 * A page with no events in that window has no rate.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainFiring = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const FIRE_MS = 2200;
    const WINDOW_MS = 10000;
    const FRESH_MS = 12000;

    function firingStrength(ageMs, durationMs) {
        const duration = durationMs == null ? FIRE_MS : Number(durationMs);
        const age = Number(ageMs);
        if (!Number.isFinite(age) || !Number.isFinite(duration) || duration <= 0) return 0;
        if (age < 0 || age >= duration) return 0;
        return 1 - age / duration;
    }

    function formatRate(value) {
        if (value == null || !Number.isFinite(Number(value))) return '\u2014';
        const n = Number(value);
        const text = n >= 10 ? String(Math.round(n)) : n.toFixed(1);
        return text + '/s';
    }

    function pageRates(events, now, windowMs) {
        const span = windowMs == null ? WINDOW_MS : Number(windowMs);
        const at = Number(now);
        const buckets = new Map();
        (events || []).forEach((event) => {
            const when = Number(event.atMs);
            const pageId = event.pageId;
            if (!Number.isFinite(when) || pageId == null || !Number.isFinite(at) || !Number.isFinite(span)) return;
            if (when > at || at - when > span) return;
            const verb = event.verb === 'write' ? 'write' : event.verb === 'read' ? 'read' : null;
            if (!verb) return;
            if (!buckets.has(pageId)) buckets.set(pageId, { reads: 0, writes: 0 });
            const row = buckets.get(pageId);
            if (verb === 'write') row.writes += 1;
            else row.reads += 1;
        });
        const seconds = span / 1000;
        const out = new Map();
        buckets.forEach((row, pageId) => {
            if (row.reads + row.writes === 0 || seconds <= 0) return;
            const reads = row.reads / seconds;
            const writes = row.writes / seconds;
            out.set(pageId, {
                reads: reads,
                writes: writes,
                activity: Math.max(0, Math.min(100, Math.round((reads + writes) * 100))),
            });
        });
        return out;
    }

    function signalLabel(verb, strength) {
        if (!(strength > 0)) return 'LIVE SIGNAL';
        if (verb === 'write') return 'WRITE ACTIVE';
        if (verb === 'read') return 'READ ACTIVE';
        return 'LIVE SIGNAL';
    }

    function isFresh(atMs, now, freshMs) {
        const age = Number(now) - Number(atMs);
        const limit = freshMs == null ? FRESH_MS : Number(freshMs);
        return Number.isFinite(age) && age >= 0 && age <= limit;
    }

    return {
        FIRE_MS,
        WINDOW_MS,
        FRESH_MS,
        firingStrength,
        formatRate,
        pageRates,
        signalLabel,
        isFresh,
    };
});
