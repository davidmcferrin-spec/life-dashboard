/**
 * starfield.js — Grok-style slow parallax starfield + faint nebulas (dark mode)
 */
'use strict';

const STARFIELD_PREF_KEY = 'dashboard_starfield_pref';
const STARFIELD_REDUCE = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let canvas = null;
let ctx = null;
let stars = [];
let nebulae = [];
let rafId = null;
let running = false;
let width = 0;
let height = 0;
let startTime = 0;
let lastFrameMs = 0;

function readSavedStarfieldPref() {
    if (document.documentElement.getAttribute('data-starfield-pref') === '1') {
        return true;
    }
    const meta = document.querySelector('meta[name="dashboard-starfield-pref"]');
    if (meta?.getAttribute('content') === '1') {
        return true;
    }
    try {
        const stored = localStorage.getItem(STARFIELD_PREF_KEY);
        if (stored === '1') {
            return true;
        }
        if (stored === '0') {
            return false;
        }
    } catch (_) { /* ignore */ }
    return null;
}

function isStarfieldEnabled() {
    const navToggle = document.getElementById('starfield-toggle');
    if (navToggle instanceof HTMLInputElement) {
        return navToggle.checked;
    }
    const saved = readSavedStarfieldPref();
    if (saved === true) {
        return true;
    }
    if (saved === false) {
        return false;
    }
    return false;
}

function isDarkEffective() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
}

function shouldRun() {
    return isStarfieldEnabled() && isDarkEffective();
}

function seedNebulae() {
    nebulae = [
        { x: 0.18, y: 0.22, r: 0.48, color: [100, 40, 160], alpha: 0.14, driftX: 0.000008, driftY: 0.000005 },
        { x: 0.82, y: 0.58, r: 0.44, color: [25, 95, 165], alpha: 0.12, driftX: -0.000006, driftY: 0.000007 },
        { x: 0.52, y: 0.78, r: 0.36, color: [45, 130, 115], alpha: 0.1, driftX: 0.000005, driftY: -0.000004 },
        { x: 0.08, y: 0.68, r: 0.32, color: [140, 50, 100], alpha: 0.09, driftX: 0.000004, driftY: 0.000006 },
    ];
}

function seedStars(count) {
    stars = [];
    for (let i = 0; i < count; i++) {
        const depth = Math.random();
        const bright = Math.random() < 0.07;
        stars.push({
            x: Math.random(),
            y: Math.random(),
            depth,
            size: bright ? 1.8 : depth * 1.1 + 0.35,
            base: bright ? 0.95 : Math.random() * 0.45 + 0.3,
            phase: Math.random() * Math.PI * 2,
            twinkle: Math.random() * 0.5 + 0.15,
            driftY: (depth * 0.55 + 0.12) * 0.000028,
            driftX: (Math.random() - 0.5) * 0.000012,
        });
    }
}

function ensureCanvas() {
    if (canvas) {
        return;
    }
    canvas = document.createElement('canvas');
    canvas.id = 'starfield-canvas';
    canvas.setAttribute('aria-hidden', 'true');
    Object.assign(canvas.style, {
        position: 'fixed',
        top: '0',
        left: '0',
        width: '100%',
        height: '100%',
        margin: '0',
        padding: '0',
        border: 'none',
        display: 'block',
        pointerEvents: 'none',
    });
    document.body.prepend(canvas);
    ctx = canvas.getContext('2d');
    seedNebulae();
    seedStars(STARFIELD_REDUCE ? 200 : 380);
    window.addEventListener('resize', onResize);
    document.addEventListener('visibilitychange', onVisibility);
}

function removeCanvas() {
    if (rafId) {
        cancelAnimationFrame(rafId);
        rafId = null;
    }
    running = false;
    if (canvas) {
        canvas.remove();
        canvas = null;
        ctx = null;
    }
}

function onResize() {
    if (!canvas || !shouldRun()) {
        return;
    }
    width = window.innerWidth;
    height = window.innerHeight;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    drawFrame(0, 0);
}

function onVisibility() {
    if (document.hidden) {
        if (rafId) {
            cancelAnimationFrame(rafId);
            rafId = null;
        }
        running = false;
    } else if (shouldRun()) {
        startLoop();
    }
}

function updateMotion(dtMs) {
    for (const s of stars) {
        s.y += s.driftY * dtMs;
        s.x += s.driftX * dtMs;
        if (s.y > 1.03) {
            s.y = -0.03;
            s.x = Math.random();
        }
        if (s.x < -0.03) {
            s.x = 1.03;
        }
        if (s.x > 1.03) {
            s.x = -0.03;
        }
    }
    for (const n of nebulae) {
        n.x += n.driftX * dtMs;
        n.y += n.driftY * dtMs;
        if (n.x < -0.15 || n.x > 1.15) {
            n.driftX *= -1;
        }
        if (n.y < -0.15 || n.y > 1.15) {
            n.driftY *= -1;
        }
    }
}

function drawNebulae() {
    ctx.save();
    ctx.globalCompositeOperation = 'screen';
    for (const n of nebulae) {
        const cx = n.x * width;
        const cy = n.y * height;
        const radius = n.r * Math.max(width, height);
        const g = ctx.createRadialGradient(cx, cy, 0, cx, cy, radius);
        const [r, gCol, b] = n.color;
        g.addColorStop(0, `rgba(${r},${gCol},${b},${n.alpha})`);
        g.addColorStop(0.35, `rgba(${r},${gCol},${b},${n.alpha * 0.45})`);
        g.addColorStop(0.7, `rgba(${r},${gCol},${b},${n.alpha * 0.12})`);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, width, height);
    }
    ctx.restore();
}

function drawStars(t) {
    for (const s of stars) {
        let alpha = s.base;
        if (!STARFIELD_REDUCE) {
            alpha *= 1 - s.twinkle * 0.35 + s.twinkle * 0.35 * Math.sin(t * 0.4 + s.phase);
        }
        ctx.beginPath();
        ctx.fillStyle = `rgba(255,255,255,${alpha})`;
        ctx.arc(s.x * width, s.y * height, s.size, 0, Math.PI * 2);
        ctx.fill();
    }
}

function drawFrame(elapsed, dtMs) {
    if (!ctx) {
        return;
    }
    if (!STARFIELD_REDUCE && dtMs > 0) {
        updateMotion(dtMs);
    }
    ctx.globalCompositeOperation = 'source-over';
    ctx.fillStyle = '#030308';
    ctx.fillRect(0, 0, width, height);
    drawNebulae();
    drawStars(elapsed * 0.001);
}

function loop(now) {
    if (!running) {
        return;
    }
    const dtMs = lastFrameMs ? now - lastFrameMs : 16;
    lastFrameMs = now;
    drawFrame(now - startTime, dtMs);
    rafId = requestAnimationFrame(loop);
}

function startLoop() {
    lastFrameMs = 0;
    if (!shouldRun()) {
        return;
    }
    ensureCanvas();
    onResize();
    if (STARFIELD_REDUCE) {
        drawFrame(0, 0);
        return;
    }
    if (running) {
        return;
    }
    running = true;
    startTime = performance.now();
    rafId = requestAnimationFrame(loop);
}

function syncStarfield() {
    if (!shouldRun()) {
        removeCanvas();
        return;
    }
    ensureCanvas();
    onResize();
    startLoop();
}

function setStarfieldPref(enabled, syncServer = true) {
    const on = !!enabled;

    const navToggle = document.getElementById('starfield-toggle');
    if (navToggle instanceof HTMLInputElement) {
        navToggle.checked = on;
    }

    if (on) {
        document.documentElement.setAttribute('data-starfield-pref', '1');
    } else {
        document.documentElement.removeAttribute('data-starfield-pref');
    }

    try {
        localStorage.setItem(STARFIELD_PREF_KEY, on ? '1' : '0');
    } catch (_) { /* ignore */ }

    const meta = document.querySelector('meta[name="dashboard-starfield-pref"]');
    if (meta) {
        meta.setAttribute('content', on ? '1' : '0');
    }

    syncStarfield();

    if (syncServer && document.querySelector('meta[name="csrf-token"]')) {
        fetch('/api/preferences', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken(),
            },
            body: JSON.stringify({ starfield_background: on }),
        }).catch(() => {});
    }
}

function syncStarfieldWithThemeAttr() {
    syncStarfield();
}

function bindStarfieldToggle() {
    const el = document.getElementById('starfield-toggle');
    if (!(el instanceof HTMLInputElement) || el.dataset.starfieldBound === '1') {
        return;
    }
    el.dataset.starfieldBound = '1';
    el.addEventListener('change', () => {
        setStarfieldPref(el.checked, true);
    });
}

function initStarfield() {
    document.addEventListener('dashboard:theme-applied', () => syncStarfieldWithThemeAttr());
    bindStarfieldToggle();
    syncStarfieldWithThemeAttr();
    document.addEventListener('DOMContentLoaded', () => {
        bindStarfieldToggle();
        syncStarfieldWithThemeAttr();
    });
}

window.DashboardStarfield = {
    setStarfieldPref,
    syncStarfield,
    syncStarfieldWithThemeAttr,
    isStarfieldEnabled,
};

function bootStarfield() {
    initStarfield();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootStarfield);
} else {
    bootStarfield();
}

window.addEventListener('load', () => syncStarfieldWithThemeAttr());
