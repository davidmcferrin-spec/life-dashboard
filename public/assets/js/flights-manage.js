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
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function fromLocalInputValue(val) {
    if (!val) return '';
    const d = new Date(val);
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:00`;
}

async function loadList() {
    const el = document.getElementById('flight-manage-list');
    const { res, data } = await api('/api/flights?all=1');
    if (!res.ok) {
        el.innerHTML = '<p class="text-danger text-sm mono">Could not load flights.</p>';
        return;
    }

    const flights = data.flights || [];
    if (flights.length === 0) {
        el.innerHTML = '<p class="empty-state mono">No watches yet.</p>';
        return;
    }

    el.innerHTML = flights.map(f => {
        const identHtml = f.external_url
            ? `<a href="${esc(f.external_url)}" class="mono text-primary font-semibold link" target="_blank" rel="noopener noreferrer" title="View on FlightAware">${esc(f.ident)}</a>`
            : `<span class="mono text-primary font-semibold">${esc(f.ident)}</span>`;

        return `
        <div class="surface p-4" data-id="${f.id}">
            <div class="flex justify-between items-start gap-2">
                ${identHtml}
                <span class="chip ${f.airborne ? 'chip-ok' : 'chip-neutral'}">${esc(f.status_label)}</span>
            </div>
            <p class="mono text-xs text-faint mt-1">Departs ${esc(f.departure_at)}${f.in_poll_window ? ' · polling active' : ' · polling starts 24h before'}</p>
            ${f.origin_display || f.dest_display ? `<p class="mono text-xs text-faint">${esc(f.origin_display || '???')} → ${esc(f.dest_display || '???')}</p>` : ''}
            <button type="button" class="flight-delete btn-danger mt-2" data-id="${f.id}">Delete</button>
        </div>
    `;
    }).join('');

    el.querySelectorAll('.flight-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this watch?')) return;
            await api(`/api/flights/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
            loadList();
        });
    });
}

document.getElementById('flight-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = {
        ident: fd.get('ident'),
        departure_at: fromLocalInputValue(fd.get('departure_at')),
        arrival_at: fd.get('arrival_at') ? fromLocalInputValue(fd.get('arrival_at')) : null,
        origin_icao: fd.get('origin_icao') || null,
    };
    const { res, data } = await api('/api/flights/create', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) {
        alert(data.error || 'Could not add flight.');
        return;
    }
    e.target.reset();
    loadList();
});

loadList();
