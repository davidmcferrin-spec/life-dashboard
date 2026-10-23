'use strict';

async function api(url, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = getCsrfToken();
    }
    const res = await fetch(url, { ...options, headers });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function openModal(modal) {
    if (!modal) return;
    modal.classList.remove('hidden');
    document.body.classList.add('app-modal-open');
}

function closeModal(modal) {
    if (!modal) return;
    modal.classList.add('hidden');
    if (!document.querySelector('.app-modal:not(.hidden)')) {
        document.body.classList.remove('app-modal-open');
    }
}

function bindModal(modal) {
    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) closeModal(modal);
    });
}

function clearFieldError(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = '';
    el.classList.add('hidden');
}

function showFieldError(id, message) {
    const el = document.getElementById(id);
    if (!el) {
        alert(message);
        return;
    }
    el.textContent = message;
    el.classList.remove('hidden');
}

function iconHtml(id) {
    const tpl = document.getElementById(id);
    const svg = tpl && tpl.content ? tpl.content.querySelector('svg') : null;
    return svg ? svg.outerHTML : '';
}

const icons = {
    plane: iconHtml('flight-icon-plane'),
    takeoff: iconHtml('flight-icon-takeoff'),
    landing: iconHtml('flight-icon-landing'),
    pin: iconHtml('flight-icon-pin'),
    clock: iconHtml('flight-icon-clock'),
    chevron: iconHtml('flight-icon-chevron'),
};

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const addModal = document.getElementById('flight-add-modal');
const detailModal = document.getElementById('flight-detail-modal');
const listEl = document.getElementById('flight-manage-list');
if (addModal) bindModal(addModal);
if (detailModal) bindModal(detailModal);

let flightById = new Map();

function fromLocalInputValue(val) {
    if (!val) return '';
    const d = new Date(val);
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

function stampDay(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    return match ? match[1] + '-' + match[2] + '-' + match[3] : '';
}

// departure_at is a wall clock in flight.timezone and has no offset.
function todayInZone(timeZone) {
    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timeZone || 'UTC',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(new Date());
        const bag = {};
        parts.forEach((part) => { bag[part.type] = part.value; });
        if (!bag.year || !bag.month || !bag.day) return '';
        return bag.year + '-' + bag.month + '-' + bag.day;
    } catch (e) {
        const now = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    }
}

function dayDiff(value, timeZone) {
    const day = stampDay(value);
    const today = todayInZone(timeZone);
    if (!day || !today) return null;
    const utc = (ymd) => {
        const bits = ymd.split('-').map(Number);
        return Date.UTC(bits[0], bits[1] - 1, bits[2]);
    };
    return Math.round((utc(day) - utc(today)) / 86400000);
}

function monthDay(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return { month: '', day: '' };
    return { month: MONTHS[Number(match[2]) - 1] || '', day: match[3] };
}

function clock(value) {
    const match = String(value || '').match(/(\d{2}):(\d{2})/);
    return match ? match[1] + ':' + match[2] : '';
}

function longDate(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (!match) return '';
    const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12));
    try {
        return new Intl.DateTimeFormat('en-US', {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'UTC',
        }).format(date);
    } catch (e) {
        return match[1] + '-' + match[2] + '-' + match[3];
    }
}

function airport(flight, which) {
    const display = which === 'origin' ? flight.origin_display : flight.dest_display;
    const icao = which === 'origin' ? flight.origin_icao : flight.dest_icao;
    return String(display || icao || '').trim();
}

function routeText(flight) {
    const from = airport(flight, 'origin');
    const to = airport(flight, 'dest');
    if (from && to) return from + ' → ' + to;
    if (from || to) return from || to;
    return flight.ident ? String(flight.ident) : 'Flight';
}

function statusTone(flight) {
    const label = String(flight.status_label || '').toLowerCase();
    if (label.includes('cancel')) return 'danger';
    if (label.includes('delay') || label.includes('late')) return 'warn';
    if (flight.airborne || label.includes('on time') || label.includes('en route') || label.includes('landed') || label.includes('arrived')) {
        return 'ok';
    }
    return 'neutral';
}

function countdown(flight) {
    if (flight.airborne) return 'In flight';
    const days = dayDiff(flight.departure_at, flight.timezone);
    if (days === null) return 'Next departure';
    if (days > 1) return 'Next departure · ' + days + ' days';
    if (days === 1) return 'Next departure · 1 day';
    if (days === 0) return 'Next departure · today';
    return 'Departed';
}

function pickNext(flights) {
    const open = flights.filter((flight) => !flight.is_complete);
    const pool = open.length ? open : flights.slice();
    const upcoming = pool.filter((flight) => {
        const days = dayDiff(flight.departure_at, flight.timezone);
        return days !== null && days >= 0;
    }).sort((a, b) => {
        const delta = dayDiff(a.departure_at, a.timezone) - dayDiff(b.departure_at, b.timezone);
        if (delta !== 0) return delta;
        return String(a.departure_at).localeCompare(String(b.departure_at));
    });
    if (upcoming.length) return upcoming[0];
    return pool.slice().sort((a, b) => String(b.departure_at).localeCompare(String(a.departure_at)))[0] || null;
}

function heroSub(flight) {
    const from = airport(flight, 'origin');
    const to = airport(flight, 'dest');
    const parts = [];
    if ((from || to) && flight.ident) parts.push(String(flight.ident));
    const when = longDate(flight.departure_at);
    if (when) parts.push(when);
    return parts.join(' · ');
}

function heroRoute(flight) {
    const from = airport(flight, 'origin');
    const to = airport(flight, 'dest');
    const plane = '<span class="flight-hero-plane flight-icon" aria-hidden="true">' + icons.plane + '</span>';
    if (from && to) {
        return '<span>' + esc(from) + '</span>' + plane + '<span>' + esc(to) + '</span>';
    }
    const label = from || to || (flight.ident ? String(flight.ident) : 'Flight');
    return plane + '<span>' + esc(label) + '</span>';
}

function heroHtml(flight) {
    const tone = statusTone(flight);
    const status = flight.status_label || 'Scheduled';
    const sub = heroSub(flight);
    return ''
        + '<section class="flight-hero" aria-label="Next departure">'
        + '<div class="flight-hero-copy">'
        + '<p class="flight-eyebrow"><span class="flight-icon" aria-hidden="true">' + icons.pin + '</span>' + esc(countdown(flight)) + '</p>'
        + '<h2 class="flight-hero-route">' + heroRoute(flight) + '</h2>'
        + (sub ? '<p class="flight-hero-sub">' + esc(sub) + '</p>' : '')
        + '</div>'
        + '<span class="flight-status is-' + tone + '"><i aria-hidden="true"></i>' + esc(status) + '</span>'
        + '</section>';
}

function cardMeta(flight) {
    const depart = clock(flight.departure_at);
    const arrive = clock(flight.arrival_at);
    let text = depart || '—';
    if (arrive) text += ' to ' + arrive;
    if (flight.in_poll_window) text += ' · polling now';
    return text;
}

function cardHtml(flight) {
    const date = monthDay(flight.departure_at);
    const route = routeText(flight);
    const tone = statusTone(flight);
    const status = flight.status_label || 'Scheduled';
    const ident = flight.ident ? String(flight.ident) : 'Flight';
    const label = route + ', ' + ident + ', ' + status;
    return ''
        + '<button type="button" class="flight-card" data-flight-id="' + esc(flight.id) + '" aria-haspopup="dialog" aria-label="' + esc(label) + '">'
        + '<span class="flight-date"><strong>' + esc(date.day || '—') + '</strong><span>' + esc(date.month) + '</span></span>'
        + '<span class="flight-card-copy">'
        + '<span class="flight-eyebrow">' + esc(ident) + '</span>'
        + '<span class="flight-card-route">' + esc(route) + '</span>'
        + '<span class="flight-card-meta"><span class="flight-icon" aria-hidden="true">' + icons.clock + '</span><span class="flight-card-meta-text">' + esc(cardMeta(flight)) + '</span></span>'
        + '</span>'
        + '<span class="flight-status is-' + tone + '"><i aria-hidden="true"></i>' + esc(status) + '</span>'
        + '<span class="flight-card-chevron flight-icon" aria-hidden="true">' + icons.chevron + '</span>'
        + '</button>';
}

function detailDesc(flight) {
    const parts = [];
    const route = routeText(flight);
    if (flight.ident && route !== String(flight.ident)) parts.push(String(flight.ident));
    const when = longDate(flight.departure_at);
    if (when) parts.push(when);
    if (flight.timezone) parts.push(String(flight.timezone));
    return parts.join(' · ');
}

function detailMeta(flight) {
    const parts = [];
    if (flight.in_poll_window) parts.push('Polling now');
    if (flight.last_checked_at) parts.push('Last checked ' + flight.last_checked_at);
    return parts.join(' · ');
}

function detailCell(label, icon, value, valueClass) {
    const mark = icon ? '<span class="flight-icon" aria-hidden="true">' + icon + '</span>' : '';
    return '<div><span class="flight-detail-label">' + esc(label) + '</span>'
        + '<strong class="flight-detail-value ' + valueClass + '">' + mark + esc(value) + '</strong></div>';
}

function detailHtml(flight) {
    const tone = statusTone(flight);
    const meta = detailMeta(flight);
    return ''
        + '<div class="flight-modal-details">'
        + detailCell('Departure', icons.takeoff, clock(flight.departure_at) || '—', '')
        + detailCell('Arrival', icons.landing, clock(flight.arrival_at) || '—', '')
        + detailCell('Status', '', flight.status_label || 'Scheduled', 'flight-tone-' + tone)
        + '</div>'
        + (meta ? '<p class="flight-detail-meta">' + esc(meta) + '</p>' : '');
}

function safeHttpUrl(value) {
    if (!value) return '';
    try {
        const url = new URL(String(value), window.location.origin);
        if (url.protocol !== 'https:' && url.protocol !== 'http:') return '';
        return url.href;
    } catch (e) {
        return '';
    }
}

function openDetail(id) {
    const flight = flightById.get(String(id));
    if (!flight || !detailModal) return;
    const title = document.getElementById('flight-detail-title');
    const desc = document.getElementById('flight-detail-desc');
    const body = document.getElementById('flight-detail-body');
    const link = document.getElementById('flight-detail-link');
    const del = document.getElementById('flight-detail-delete');
    if (title) title.textContent = routeText(flight);
    if (desc) desc.textContent = detailDesc(flight);
    if (body) body.innerHTML = detailHtml(flight);
    if (del) del.dataset.id = String(flight.id);
    if (link) {
        const href = safeHttpUrl(flight.external_url);
        if (href) {
            link.href = href;
            link.hidden = false;
        } else {
            link.removeAttribute('href');
            link.hidden = true;
        }
    }
    openModal(detailModal);
}

async function loadList() {
    if (!listEl) return;
    const { res, data } = await api('/api/flights?all=1');
    if (!res.ok) {
        listEl.innerHTML = '<p class="flight-error" role="alert">Could not load flights.</p>';
        return;
    }

    const flights = data.flights || [];
    flightById = new Map(flights.map((flight) => [String(flight.id), flight]));
    if (flights.length === 0) {
        listEl.innerHTML = window.DashboardEmpty.html({
            title: 'No watches yet',
            body: 'Add a flight to follow it here.',
            actionLabel: 'Flight watches',
            actionHref: '/flights',
        });
        return;
    }

    const next = pickNext(flights);
    listEl.innerHTML = (next ? heroHtml(next) : '')
        + '<div class="flight-grid">' + flights.map(cardHtml).join('') + '</div>';
}

if (listEl) {
    listEl.addEventListener('click', (event) => {
        const card = event.target.closest('.flight-card');
        if (!card || !listEl.contains(card)) return;
        openDetail(card.getAttribute('data-flight-id'));
    });
}

document.getElementById('flight-detail-delete')?.addEventListener('click', async () => {
    const btn = document.getElementById('flight-detail-delete');
    if (!btn || btn.dataset.confirming === '1' || !btn.dataset.id) return;
    btn.dataset.confirming = '1';
    try {
        const ok = await globalThis.AppModal.confirm({
            title: 'Delete watch',
            message: 'Delete this watch?',
            confirmLabel: 'Delete',
            danger: true,
        });
        if (!ok) return;
        await api(`/api/flights/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
        closeModal(detailModal);
        loadList();
    } finally {
        if (btn.isConnected) btn.dataset.confirming = '';
    }
});

document.getElementById('flight-add-open')?.addEventListener('click', () => {
    clearFieldError('flight-add-error');
    openModal(addModal);
});

document.getElementById('flight-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = {
        ident: fd.get('ident'),
        departure_at: fromLocalInputValue(fd.get('departure_at')),
        arrival_at: fd.get('arrival_at') ? fromLocalInputValue(fd.get('arrival_at')) : null,
        origin_icao: fd.get('origin_icao') || null,
    };
    clearFieldError('flight-add-error');
    const { res, data } = await api('/api/flights/create', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) {
        showFieldError('flight-add-error', data.error || 'Could not add flight.');
        return;
    }
    e.target.reset();
    closeModal(addModal);
    loadList();
});

loadList();
