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

const addModal = document.getElementById('flight-add-modal');
if (addModal) bindModal(addModal);

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
        el.innerHTML = window.DashboardEmpty.html({
            title: 'No watches yet',
            body: 'Add a flight to follow it here.',
            actionLabel: 'Flight watches',
            actionHref: '/flights',
        });
        return;
    }

    el.innerHTML = flights.map(f => {
        const identHtml = f.external_url
            ? `<a href="${esc(f.external_url)}" class="mono text-primary font-semibold link" target="_blank" rel="noopener noreferrer" title="View on FlightAware">${esc(f.ident)}</a>`
            : `<span class="mono text-primary font-semibold">${esc(f.ident)}</span>`;

        const route = (f.origin_display || f.dest_display)
            ? `<p class="watch-row-meta">${esc(f.origin_display || '???')} → ${esc(f.dest_display || '???')}</p>`
            : '';
        return `
        <div class="watch-row" data-id="${f.id}">
            <div class="watch-row-main">
                ${identHtml}
                <p class="watch-row-meta">Departs ${esc(f.departure_at)}${f.in_poll_window ? ' · polling now' : ''}</p>
                ${route}
            </div>
            <span class="chip ${f.airborne ? 'chip-ok' : 'chip-neutral'}">${esc(f.status_label)}</span>
            <button type="button" class="flight-delete btn-danger" data-id="${f.id}">Delete</button>
        </div>
    `;
    }).join('');

    el.querySelectorAll('.flight-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.dataset.confirming === '1') return;
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
                loadList();
            } finally {
                if (btn.isConnected) btn.dataset.confirming = '';
            }
        });
    });
}

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
