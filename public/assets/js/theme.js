/**
 * theme.js — light / dark / system appearance
 */
'use strict';

const THEME_STORAGE_KEY = 'dashboard_theme';
const VALID_PREFS = ['light', 'dark', 'system'];

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
    return fromHtml && VALID_PREFS.includes(fromHtml) ? fromHtml : 'system';
}

function resolveEffective(pref) {
    if (pref === 'light' || pref === 'dark') {
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
        pref = 'system';
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
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTheme);
} else {
    initTheme();
}
