/**
 * dashboard.js — client-side logic for Personal Dashboard
 *
 * Responsibilities:
 *   - Live clocks (4 US timezones, server-synced via DashboardTime)
 *   - Search engine switching + persistence
 *   - Weather widget (geolocation → /api/weather)
 *   - Flight countdown timers
 *   - Bookmark folder toggle
 */

'use strict';

// -------------------------------------------------------
// Utilities
// -------------------------------------------------------

const $ = (sel, ctx = document) => ctx.querySelector(sel);
const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

function timeAgo(dateStr) {
    return window.DashboardTime?.timeAgo(dateStr) ?? '';
}

function formatCountdown(departsAt) {
    const nowMs = window.DashboardTime?.nowMs() ?? Date.now();
    const diff = new Date(departsAt).getTime() - nowMs;
    if (diff <= 0) return 'Departed';
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    if (h > 0) return `in ${h}h ${m}m`;
    return `in ${m}m`;
}

// -------------------------------------------------------
// Live Clocks
// -------------------------------------------------------

const REF_TZ = 'America/New_York';

function updateBroadcastWeekLine() {
    const block = document.getElementById('broadcast-week-line');
    if (!block || !window.BroadcastWeek) return;

    const detail = block.querySelector('.broadcast-week-detail');
    if (!detail) return;

    const now = window.DashboardTime?.now() ?? new Date();
    detail.textContent = window.BroadcastWeek.formatBroadcastWeekLine(now);
}

function is24HourClock() {
    const bar = document.getElementById('timezone-bar');
    return bar?.dataset.clockFormat === '24h';
}

function formatJamTime(date) {
    if (!date) return '—';
    return date.toLocaleTimeString('en-US', {
        timeZone: REF_TZ,
        hour:     'numeric',
        minute:   '2-digit',
        second:   '2-digit',
        hour12:   true,
    }) + ' ET';
}

function updateClockPopovers() {
    const dt = window.DashboardTime;
    if (!dt) return;

    const offsetMs = dt.getOffsetMs();
    const offsetText = dt.formatOffsetMs(offsetMs);
    const jamText = 'Last jam: ' + formatJamTime(dt.getLastSyncAt());
    const warnOffset = Math.abs(offsetMs) > 30_000;

    $$('.clock-popover').forEach(pop => {
        const offsetEl = pop.querySelector('.clock-popover-offset');
        const jamEl = pop.querySelector('.clock-popover-jam');
        if (offsetEl) {
            offsetEl.textContent = 'Sync offset: ' + offsetText;
            offsetEl.classList.toggle('clock-popover-warn', warnOffset);
        }
        if (jamEl) {
            jamEl.textContent = jamText;
        }
    });
}

function flashJammed() {
    const bar = document.getElementById('timezone-bar');
    if (!bar) return;
    bar.classList.add('clock-jammed');
    setTimeout(() => bar.classList.remove('clock-jammed'), 600);
}

let jamInFlight = false;

async function performJam() {
    if (jamInFlight || !window.DashboardTime?.jamSync) return false;
    jamInFlight = true;
    $$('.clock-jam-btn').forEach(btn => { btn.disabled = true; });
    const ok = await window.DashboardTime.jamSync();
    jamInFlight = false;
    $$('.clock-jam-btn').forEach(btn => { btn.disabled = false; });
    if (ok) {
        flashJammed();
        updateClockPopovers();
    }
    return ok;
}

function isEditableTarget(el) {
    if (!el || !(el instanceof HTMLElement)) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

function initClockJam() {
    $$('.clock-jam-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            performJam();
        });
    });

    document.addEventListener('keydown', (e) => {
        if (!e.altKey || e.ctrlKey || e.metaKey || e.key.toLowerCase() !== 'j') return;
        if (isEditableTarget(document.activeElement)) return;
        e.preventDefault();
        performJam();
    });
}

function initFocusClockShortcut() {
    document.addEventListener('keydown', (e) => {
        if (!e.altKey || !e.shiftKey || e.ctrlKey || e.metaKey || e.key.toLowerCase() !== 'c') return;
        if (isEditableTarget(document.activeElement)) return;
        e.preventDefault();
        window.location.href = '/clock';
    });
}

function updateClocks() {
    const hour12 = !is24HourClock();
    const timeOpts = {
        hour:   hour12 ? 'numeric' : '2-digit',
        minute: '2-digit',
        hour12,
    };

    $$('.clock[data-tz]').forEach(el => {
        const tz   = el.dataset.tz;
        const now  = window.DashboardTime?.now() ?? new Date();
        const time = now.toLocaleTimeString('en-US', { timeZone: tz, ...timeOpts });

        if (hour12) {
            const [t, ap] = time.split(' ');
            el.textContent = t;
            const ampmEl = document.querySelector(`.ampm[data-tz="${CSS.escape(tz)}"]`);
            if (ampmEl) ampmEl.textContent = ap ?? '';
        } else {
            el.textContent = time;
            const ampmEl = document.querySelector(`.ampm[data-tz="${CSS.escape(tz)}"]`);
            if (ampmEl) ampmEl.textContent = '';
        }
    });

    // Live date in nav
    const dateEl = document.getElementById('live-date');
    if (dateEl) {
        const now = window.DashboardTime?.now() ?? new Date();
        dateEl.textContent = now.toLocaleDateString('en-US', {
            weekday: 'short', month: 'short', day: 'numeric'
        });
    }

    updateBroadcastWeekLine();
    updateClockPopovers();
}

setInterval(updateClocks, 1000);
updateClocks();
initClockJam();
initFocusClockShortcut();

// -------------------------------------------------------
// Search Engine Switching
// -------------------------------------------------------

const STORAGE_KEY_ENGINE = 'dashboard_search_engine';

function getActiveEngine() {
    const activeBtn = document.querySelector('.search-engine-btn.seg-btn-active');
    if (activeBtn?.dataset.engine) {
        return activeBtn.dataset.engine;
    }
    return localStorage.getItem(STORAGE_KEY_ENGINE) ?? 'google';
}

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function applyEngine(engineKey) {
    const btn  = $(`.search-engine-btn[data-engine="${engineKey}"]`);
    if (!btn) return;

    const url   = btn.dataset.url;
    const param = btn.dataset.param;
    const form  = document.getElementById('search-form');
    const input = document.getElementById('search-input');

    if (form) {
        if (engineKey === 'local') {
            form.removeAttribute('action');
            form.target = '';
            if (input) input.placeholder = 'Search this dashboard…';
        } else {
            form.action = url;
            form.target = '_blank';
            if (input) input.placeholder = 'Search...';
        }
        if (input) input.name = param;
    }
    if (engineKey !== 'local') {
        document.getElementById('local-search')?.classList.add('hidden');
    }

    // Update button styles
    $$('.search-engine-btn').forEach(b => {
        b.classList.toggle('seg-btn-active', b.dataset.engine === engineKey);
    });

    localStorage.setItem(STORAGE_KEY_ENGINE, engineKey);

    // Persist to server (fire-and-forget)
    fetch('/api/preferences', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': getCsrfToken() },
        body:    JSON.stringify({ key: 'search_engine', value: engineKey }),
    }).catch(() => {}); // non-critical
}

$$('.search-engine-btn').forEach(btn => {
    btn.addEventListener('click', () => applyEngine(btn.dataset.engine));
});

applyEngine(getActiveEngine());

document.getElementById('search-form')?.addEventListener('submit', async (e) => {
    if (getActiveEngine() !== 'local') return;
    e.preventDefault();
    const input = document.getElementById('search-input');
    const box = document.getElementById('local-search');
    const q = (input?.value || '').trim();
    if (!box) return;
    if (q.length < 2) {
        box.classList.remove('hidden');
        box.innerHTML = '<p class="empty-state mono text-xs">Enter at least two letters.</p>';
        return;
    }
    box.classList.remove('hidden');
    box.innerHTML = '<p class="text-faint text-xs mono">Searching…</p>';
    try {
        const res = await fetch('/api/v1/search?' + new URLSearchParams({ q }), { headers: { Accept: 'application/json' } });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Search failed.');
        const hits = data.hits || [];
        if (!hits.length) {
            box.innerHTML = '<p class="empty-state mono text-xs">No matches.</p>';
            return;
        }
        box.innerHTML = hits.map((hit) => {
            const rawUrl = String(hit.url || '');
            const safe = /^(https?:\/\/|\/)/i.test(rawUrl);
            const extra = /^https?:/i.test(rawUrl) ? ' target="_blank" rel="noopener noreferrer"' : '';
            const kind = hit.match === 'similar' ? ' · similar' : '';
            return `<a class="local-hit" href="${escHtml(safe ? rawUrl : '#')}"${extra}>
                <span class="local-hit-kicker">${escHtml(hit.dataset || '')}${escHtml(kind)}</span>
                <strong>${escHtml(hit.title || '')}</strong>
                <span>${escHtml(hit.snippet || '')}</span>
            </a>`;
        }).join('');
    } catch (err) {
        box.innerHTML = `<p class="text-danger text-xs mono">${escHtml(err.message || 'Search failed.')}</p>`;
    }
});

// -------------------------------------------------------
// Bookmark Folder Toggle
// -------------------------------------------------------

$$('.folder-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const folderId = btn.dataset.folder;
        const contents = document.getElementById(folderId);
        const arrow    = btn.querySelector('.folder-arrow');

        if (!contents) return;

        const isOpen = !contents.classList.contains('hidden');
        contents.classList.toggle('hidden', isOpen);
        if (arrow) {
            arrow.style.transform = isOpen ? '' : 'rotate(90deg)';
        }
    });
});

// -------------------------------------------------------
// Weather Widget
// -------------------------------------------------------

async function loadWeather(lat, lon) {
    const widget = document.getElementById('weather-widget');
    if (!widget) return;

    try {
        const res  = await fetch(`/api/weather?lat=${lat}&lon=${lon}`);
        const data = await res.json();

        if (!res.ok || data.error) {
            showWeatherPanel(
                'Weather unavailable',
                data.error || 'The forecast did not load.',
                'Try again',
                initWeather
            );
            return;
        }

        widget.innerHTML = `
            <div class="weather-compact">
                <div class="weather-compact-main">
                    <img src="https://openweathermap.org/img/wn/${data.icon}@2x.png" alt="${data.description}" class="weather-ico" width="36" height="36">
                    <div class="min-w-0">
                        <div class="weather-temp">${data.temp}°F</div>
                        <div class="weather-desc">${data.description}</div>
                    </div>
                </div>
                <div class="weather-details">
                    <span>Feels <b>${data.feels_like}°</b></span>
                    <span>Hum <b>${data.humidity}%</b></span>
                    <span>Wind <b>${data.wind_speed}</b></span>
                    <span>Vis <b>${data.visibility}mi</b></span>
                </div>
                <div class="weather-meta">${data.city} · ${timeAgo(data.fetched_at)}</div>
            </div>
        `;
    } catch (e) {
        showWeatherPanel(
            'Weather unavailable',
            'The forecast did not load.',
            'Try again',
            initWeather
        );
    }
}

function showWeatherPanel(title, body, actionLabel, onClick) {
    const widget = document.getElementById('weather-widget');
    if (!widget || !window.DashboardEmpty) return;
    widget.innerHTML = window.DashboardEmpty.html({
        title,
        body,
        actionLabel,
        actionAttrs: 'type="button" data-weather-action="1"',
    });
    widget.querySelector('[data-weather-action]')?.addEventListener('click', onClick);
}

function initWeather() {
    if (!document.getElementById('weather-widget')) return; // widget not on the board
    if (!navigator.geolocation) {
        showWeatherPanel(
            'Location unavailable',
            'This browser cannot share a location.',
            'Use Huntsville',
            () => loadWeather(34.7304, -86.5861)
        );
        return;
    }

    navigator.geolocation.getCurrentPosition(
        pos => loadWeather(pos.coords.latitude, pos.coords.longitude),
        ()  => loadWeather(34.7304, -86.5861) // Fallback: Huntsville, AL
    );
}

document.getElementById('weather-refresh')?.addEventListener('click', initWeather);
initWeather();

