/**
 * theme.js — JARVIS / light / dark / system appearance, plus the HUD accent.
 */
'use strict';

const THEME_STORAGE_KEY = 'dashboard_theme';
const VALID_PREFS = ['jarvis', 'light', 'dark', 'system'];
const ACCENT_DEFAULT = '#00e5ff';

function isLocalOnly() {
    return document.documentElement.getAttribute('data-theme-local') === '1';
}

function getThemePref() {
    try {
        const stored = localStorage.getItem(THEME_STORAGE_KEY);
        if (stored && VALID_PREFS.includes(stored)) {
            return stored;
        }
    } catch (_) { /* ignore */ }

    const fromHtml = document.documentElement.getAttribute('data-theme-pref');
    return fromHtml && VALID_PREFS.includes(fromHtml) ? fromHtml : 'jarvis';
}

function resolveEffective(pref) {
    if (pref === 'light' || pref === 'dark' || pref === 'jarvis') {
        return pref;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function syncStarfieldWithTheme() {
    if (isLocalOnly()) {
        return;
    }
    if (window.DashboardStarfield?.syncStarfield) {
        window.DashboardStarfield.syncStarfield();
    }
}

function applyTheme(pref, persistLocal = true) {
    if (!VALID_PREFS.includes(pref)) {
        pref = 'jarvis';
    }

    const effective = resolveEffective(pref);
    document.documentElement.setAttribute('data-theme', effective);
    document.documentElement.setAttribute('data-theme-pref', pref);

    if (persistLocal) {
        try {
            localStorage.setItem(THEME_STORAGE_KEY, pref);
        } catch (_) { /* ignore */ }
    }

    document.querySelectorAll('[data-theme-pick]').forEach(btn => {
        btn.classList.toggle('is-active', btn.getAttribute('data-theme-pick') === pref);
    });

    document.querySelectorAll('input[name="theme"][type="radio"]').forEach(input => {
        input.checked = input.value === pref;
    });

    syncStarfieldWithTheme();

    document.dispatchEvent(new CustomEvent('dashboard:theme-applied', {
        detail: { pref, effective },
    }));
}

function persistThemeToServer(pref) {
    if (isLocalOnly() || !document.querySelector('meta[name="csrf-token"]')) {
        return;
    }

    fetch('/api/preferences', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken(),
        },
        body: JSON.stringify({ theme: pref }),
    }).catch(() => {});
}

function setThemePref(pref, syncServer = true) {
    applyTheme(pref, true);
    if (syncServer) {
        persistThemeToServer(pref);
    }
}

function bindThemeButtons() {
    document.querySelectorAll('[data-theme-pick]').forEach(btn => {
        if (btn.dataset.themeBound === '1') {
            return;
        }
        btn.dataset.themeBound = '1';
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const pref = btn.getAttribute('data-theme-pick');
            if (pref && VALID_PREFS.includes(pref)) {
                setThemePref(pref, true);
            }
        });
    });
}

function initThemeBindings() {
    bindThemeButtons();

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-theme-pick]');
        if (!btn || btn.dataset.themeBound === '1') {
            return;
        }
        const pref = btn.getAttribute('data-theme-pick');
        if (pref && VALID_PREFS.includes(pref)) {
            setThemePref(pref, true);
        }
    });

    document.addEventListener('change', (e) => {
        const input = e.target;
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        if (input.name !== 'theme' || input.type !== 'radio' || !input.checked) {
            return;
        }
        if (VALID_PREFS.includes(input.value)) {
            setThemePref(input.value, true);
        }
    });
}

function seedThemeFromServer() {
    if (isLocalOnly()) {
        return;
    }
    const htmlPref = document.documentElement.getAttribute('data-theme-pref');
    if (!htmlPref || !VALID_PREFS.includes(htmlPref)) {
        return;
    }
    try {
        if (!localStorage.getItem(THEME_STORAGE_KEY)) {
            localStorage.setItem(THEME_STORAGE_KEY, htmlPref);
        }
    } catch (_) { /* ignore */ }
}

function initTheme() {
    seedThemeFromServer();
    initThemeBindings();
    applyTheme(getThemePref(), true);

    document.addEventListener('DOMContentLoaded', () => {
        bindThemeButtons();
        applyTheme(getThemePref(), true);
        syncStarfieldWithTheme();
    });

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (getThemePref() === 'system') {
            applyTheme('system', true);
        }
    });

    bindAccentControls();
}

function clampByte(n) {
    return Math.max(0, Math.min(255, Math.round(Number(n) || 0)));
}

function parseHex(raw) {
    const match = /^#?([0-9a-fA-F]{6})$/.exec(String(raw || '').trim());
    return match ? '#' + match[1].toLowerCase() : null;
}

function hexToRgb(hex) {
    const n = parseInt(hex.slice(1), 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function rgbToHex(channels) {
    return '#' + [channels.r, channels.g, channels.b].map((v) => clampByte(v).toString(16).padStart(2, '0')).join('');
}

function mixChannel(channel, toward, amount) {
    return clampByte(channel + (toward - channel) * amount);
}

function accentLuminance(channels) {
    const lin = (v) => {
        const s = v / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * lin(channels.r) + 0.7152 * lin(channels.g) + 0.0722 * lin(channels.b);
}

function applyAccent(hex) {
    const color = parseHex(hex);
    if (!color) {
        return null;
    }
    const rgb = hexToRgb(color);
    const bright = rgbToHex({
        r: mixChannel(rgb.r, 255, 0.32),
        g: mixChannel(rgb.g, 255, 0.32),
        b: mixChannel(rgb.b, 255, 0.32),
    });
    const dim = rgbToHex({
        r: mixChannel(rgb.r, 4, 0.45),
        g: mixChannel(rgb.g, 12, 0.45),
        b: mixChannel(rgb.b, 24, 0.45),
    });
    const ink = accentLuminance(rgb) > 0.55 ? '#061018' : '#f4fbff';
    const root = document.documentElement;
    root.style.setProperty('--hud-accent', color);
    root.style.setProperty('--hud-rgb', rgb.r + ',' + rgb.g + ',' + rgb.b);
    root.style.setProperty('--hud-bright', bright);
    root.style.setProperty('--hud-dim', dim);
    root.style.setProperty('--hud-ink', ink);
    root.setAttribute('data-jarvis-accent', color);
    return color;
}

function persistAccent(hex) {
    if (isLocalOnly() || !document.querySelector('meta[name="csrf-token"]')) {
        return;
    }
    fetch('/api/preferences', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': getCsrfToken(),
        },
        body: JSON.stringify({ jarvis_accent: hex }),
        keepalive: true,
    }).catch(() => {});
}

let accentSaveTimer = 0;
function scheduleAccentSave(hex) {
    window.clearTimeout(accentSaveTimer);
    accentSaveTimer = window.setTimeout(() => persistAccent(hex), 280);
}

function syncAccentControls(hex) {
    const color = parseHex(hex);
    if (!color) {
        return;
    }
    const rgb = hexToRgb(color);
    const picker = document.getElementById('jarvis-accent-picker');
    const field = document.getElementById('jarvis-accent-hex');
    const red = document.getElementById('jarvis-accent-r');
    const green = document.getElementById('jarvis-accent-g');
    const blue = document.getElementById('jarvis-accent-b');
    if (picker instanceof HTMLInputElement && picker.value.toLowerCase() !== color) {
        picker.value = color;
    }
    if (field instanceof HTMLInputElement && document.activeElement !== field) {
        field.value = color;
        field.classList.remove('is-invalid');
    }
    if (red instanceof HTMLInputElement && document.activeElement !== red) red.value = String(rgb.r);
    if (green instanceof HTMLInputElement && document.activeElement !== green) green.value = String(rgb.g);
    if (blue instanceof HTMLInputElement && document.activeElement !== blue) blue.value = String(rgb.b);
}

function bindAccentControls() {
    const root = document.getElementById('jarvis-accent');
    if (!root || root.dataset.bound === '1') {
        return;
    }
    root.dataset.bound = '1';
    const picker = document.getElementById('jarvis-accent-picker');
    const field = document.getElementById('jarvis-accent-hex');
    const red = document.getElementById('jarvis-accent-r');
    const green = document.getElementById('jarvis-accent-g');
    const blue = document.getElementById('jarvis-accent-b');
    const reset = document.getElementById('jarvis-accent-reset');

    const push = (hex, save) => {
        const color = applyAccent(hex);
        if (!color) {
            return;
        }
        syncAccentControls(color);
        if (field instanceof HTMLInputElement) {
            field.value = color;
            field.classList.remove('is-invalid');
        }
        if (save) {
            scheduleAccentSave(color);
        }
    };

    if (picker instanceof HTMLInputElement) {
        picker.addEventListener('input', () => push(picker.value, true));
    }
    if (field instanceof HTMLInputElement) {
        field.addEventListener('input', () => {
            const color = parseHex(field.value);
            field.classList.toggle('is-invalid', color === null && field.value !== '');
            if (color) {
                push(color, true);
            }
        });
    }
    const fromRgb = () => {
        if (!(red instanceof HTMLInputElement) || !(green instanceof HTMLInputElement) || !(blue instanceof HTMLInputElement)) {
            return;
        }
        push(rgbToHex({ r: red.value, g: green.value, b: blue.value }), true);
    };
    [red, green, blue].forEach((el) => {
        if (el instanceof HTMLInputElement) {
            el.addEventListener('input', fromRgb);
        }
    });
    if (reset instanceof HTMLButtonElement) {
        reset.addEventListener('click', () => push(ACCENT_DEFAULT, true));
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTheme);
} else {
    initTheme();
}
