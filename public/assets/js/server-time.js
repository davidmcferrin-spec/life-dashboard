/**
 * server-time.js — server-authoritative clock for dashboard UI
 *
 * Embeds initial offset from data-server-time-ms on <html>, then re-syncs via
 * GET /api/time (periodic + on tab focus). Exposes DashboardTime globally.
 */
'use strict';

const RESYNC_MS = 5 * 60 * 1000;
const WARN_OFFSET_MS = 30_000;

let clockOffsetMs = 0;
let lastSyncAt = null;

function applyOffset(serverMs, clientMsAtSync) {
    clockOffsetMs = serverMs - clientMsAtSync;
    lastSyncAt = new Date();
    updateWarn();
}

function nowMs() {
    return Date.now() + clockOffsetMs;
}

function now() {
    return new Date(nowMs());
}

function formatOffsetMs(ms) {
    const abs = Math.abs(ms);
    if (abs < 500) {
        return '±0.0s (in sync)';
    }
    const secs = (abs / 1000).toFixed(1);
    const sign = ms >= 0 ? '+' : '-';
    const label = ms >= 0 ? 'server ahead' : 'device ahead';
    return `${sign}${secs}s (${label})`;
}

function timeAgo(dateStr) {
    const date = new Date(dateStr);
    if (isNaN(date)) return '';
    const diffMs  = nowMs() - date.getTime();
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1)   return 'just now';
    if (diffMin < 60)  return `${diffMin}m ago`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24)    return `${diffH}h ago`;
    const diffD = Math.floor(diffH / 24);
    return `${diffD}d ago`;
}

function ensureWarnEl() {
    let el = document.getElementById('clock-sync-warn');
    if (el) return el;

    const nav = document.querySelector('.nav-meta');
    if (!nav) return null;

    el = document.createElement('span');
    el.id = 'clock-sync-warn';
    el.className = 'clock-sync-warn hidden';
    el.title = 'Your device clock differs from server time. Times shown use server time.';
    el.textContent = 'device clock off';
    nav.insertBefore(el, nav.firstChild);
    return el;
}

function updateWarn() {
    const el = ensureWarnEl();
    if (!el) return;
    el.classList.toggle('hidden', Math.abs(clockOffsetMs) <= WARN_OFFSET_MS);
}

async function syncFromServer() {
    const t0 = Date.now();
    try {
        const res = await fetch('/api/time', { credentials: 'same-origin' });
        if (!res.ok) return false;
        const data = await res.json();
        const t1 = Date.now();
        const serverMs = data.server_time_ms;
        if (typeof serverMs !== 'number' || Number.isNaN(serverMs)) return false;
        applyOffset(serverMs, t0 + (t1 - t0) / 2);
        return true;
    } catch (_) {
        return false;
    }
}

function jamSync() {
    return syncFromServer();
}

function initFromEmbed() {
    const embedded = document.documentElement.getAttribute('data-server-time-ms');
    if (!embedded) return;
    const serverMs = parseInt(embedded, 10);
    if (Number.isNaN(serverMs)) return;
    applyOffset(serverMs, Date.now());
}

function init() {
    initFromEmbed();
    syncFromServer();
    setInterval(syncFromServer, RESYNC_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            syncFromServer();
        }
    });
}

window.DashboardTime = {
    now,
    nowMs,
    timeAgo,
    syncFromServer,
    jamSync,
    formatOffsetMs,
    getOffsetMs: () => clockOffsetMs,
    getLastSyncAt: () => lastSyncAt,
};

init();
