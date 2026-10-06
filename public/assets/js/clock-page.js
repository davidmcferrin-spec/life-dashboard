/**
 * clock-page.js — focus clock display (/clock)
 */
'use strict';

function isEditableTarget(el) {
    if (!el || !(el instanceof HTMLElement)) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

function parsePrefs() {
    const el = document.getElementById('focus-clock-config');
    if (!el?.textContent) return null;
    try {
        return JSON.parse(el.textContent);
    } catch (_) {
        return null;
    }
}

function applyStackStyles(stack, prefs) {
    stack.style.setProperty('--fc-time-color', prefs.time_color || '#39ff14');
    stack.style.setProperty('--fc-date-color', prefs.date_color || '#64748b');
    stack.style.setProperty('--focus-clock-scale', String((prefs.scale_percent || 100) / 100));
    stack.dataset.datePosition = prefs.date_position || 'below';
}

function formatDateLine(now, tz) {
    return now.toLocaleDateString('en-US', {
        weekday: 'long',
        month:   'long',
        day:     'numeric',
        year:    'numeric',
        timeZone: tz,
    });
}

function renderTime(prefs) {
    const timeEl = document.getElementById('focus-clock-time');
    if (!timeEl) return;

    const tz     = window.effectiveFocusClockTimezone?.(prefs) ?? prefs.timezone ?? 'America/Chicago';
    const hour12 = prefs.format !== '24h';
    const now    = window.DashboardTime?.now() ?? new Date();
    const font   = prefs.font || 'dseg7';

    timeEl.className = `focus-clock-time font-${font}`;
    const showSeconds = prefs.show_seconds !== false;
    timeEl.dataset.colonBlink = prefs.colon_blink && font === 'dseg7' && showSeconds ? '1' : '0';

    const timeStr = now.toLocaleTimeString('en-US', {
        timeZone: tz,
        hour:     hour12 ? 'numeric' : '2-digit',
        minute:   '2-digit',
        second:   showSeconds ? '2-digit' : undefined,
        hour12,
    });

    if (hour12) {
        const parts = timeStr.split(' ');
        const tPart = parts[0] || timeStr;
        const ap    = parts[1] || '';
        if (font === 'dseg7') {
            const segments = tPart.split(':');
            const h = segments[0] || '';
            const m = segments[1] || '';
            const s = segments[2] || '';
            if (s) {
                timeEl.innerHTML =
                    `<span>${h}</span><span class="fc-colon">:</span><span>${m}</span><span class="fc-colon">:</span><span>${s}</span><span class="fc-ampm">${ap}</span>`;
            } else {
                timeEl.innerHTML =
                    `<span>${h}</span><span class="fc-colon">:</span><span>${m}</span><span class="fc-ampm">${ap}</span>`;
            }
        } else {
            timeEl.textContent = `${tPart} ${ap}`.trim();
        }
    } else {
        if (font === 'dseg7') {
            const segments = timeStr.split(':');
            const h = segments[0] || '';
            const m = segments[1] || '';
            const s = segments[2] || '';
            if (s) {
                timeEl.innerHTML =
                    `<span>${h}</span><span class="fc-colon">:</span><span>${m}</span><span class="fc-colon">:</span><span>${s}</span>`;
            } else {
                timeEl.innerHTML =
                    `<span>${h}</span><span class="fc-colon">:</span><span>${m}</span>`;
            }
        } else {
            timeEl.textContent = timeStr;
        }
    }

    const dateEl = document.getElementById('focus-clock-date');
    const weekEl = document.getElementById('focus-clock-week');
    const meta   = document.getElementById('focus-clock-meta');
    const showMeta = prefs.date_position !== 'hidden';

    if (meta) {
        meta.classList.toggle('hidden', !showMeta);
    }

    if (dateEl && showMeta) {
        dateEl.textContent = formatDateLine(now, tz);
    }

    if (weekEl) {
        const showWeek = prefs.show_broadcast_week && window.BroadcastWeek;
        weekEl.classList.toggle('hidden', !showWeek);
        if (showWeek) {
            weekEl.textContent = window.BroadcastWeek.formatBroadcastWeekLine(now);
        }
    }

    const liveDate = document.getElementById('live-date');
    if (liveDate) {
        liveDate.textContent = now.toLocaleDateString('en-US', {
            weekday: 'short', month: 'short', day: 'numeric',
        });
    }
}

function initFocusClockNav() {
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            e.preventDefault();
            window.location.href = '/';
            return;
        }
        if (!e.altKey || !e.shiftKey || e.ctrlKey || e.metaKey || e.key.toLowerCase() !== 'c') return;
        if (isEditableTarget(document.activeElement)) return;
        e.preventDefault();
        window.location.href = '/';
    });
}

function init() {
    const prefs = parsePrefs();
    if (!prefs) return;

    const stack = document.getElementById('focus-clock-stack');
    if (stack) {
        applyStackStyles(stack, prefs);
    }

    initFocusClockNav();
    renderTime(prefs);
    setInterval(() => renderTime(prefs), 1000);
}

init();
