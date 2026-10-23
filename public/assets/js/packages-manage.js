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

const addModal = document.getElementById('package-add-modal');
if (addModal) bindModal(addModal);

function carrierLabel(carrier, resolved) {
    const c = resolved || carrier;
    return ({ ups: 'UPS', fedex: 'FedEx', dhl: 'DHL', usps: 'USPS', amazon: 'Amazon', ontrac: 'OnTrac', lasership: 'LaserShip', auto: 'Auto' }[c] || c);
}

function formatEta(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
}

async function loadList() {
    const el = document.getElementById('package-manage-list');
    const { res, data } = await api('/api/packages?all=1');
    if (!res.ok) {
        el.innerHTML = '<p class="text-danger text-sm mono">Could not load packages.</p>';
        return;
    }

    const packages = data.packages || [];
    if (packages.length === 0) {
        el.innerHTML = window.DashboardEmpty.html({
            title: 'No watches yet',
            body: 'Add a tracking number to follow a delivery.',
            actionLabel: 'Package tracking',
            actionHref: '/settings/packages',
        });
        return;
    }

    el.innerHTML = packages.map(p => {
        const title = p.label || p.tracking_number;
        const trackHtml = p.external_url
            ? `<a href="${esc(p.external_url)}" class="mono text-primary font-semibold link" target="_blank" rel="noopener noreferrer">${esc(title)}</a>`
            : `<span class="mono text-primary font-semibold">${esc(title)}</span>`;
        const inactive = !p.is_active ? ' · inactive' : '';
        const linkOnly = p.is_link_only ? ' · link only' : '';
        const eta = formatEta(p.estimated_delivery_at);

        return `
        <div class="surface p-4" data-id="${p.id}">
            <div class="flex justify-between items-start gap-2">
                ${trackHtml}
                <span class="chip chip-neutral">${esc(p.status_label)}</span>
            </div>
            <p class="mono text-xs text-faint mt-1">${esc(carrierLabel(p.carrier, p.carrier_resolved))} · ${esc(p.tracking_number)}${inactive}${linkOnly}</p>
            ${p.status_detail ? `<p class="mono text-xs text-faint mt-1">${esc(p.status_detail)}</p>` : ''}
            ${eta ? `<p class="mono text-xs text-faint mt-1">ETA ${esc(eta)}</p>` : ''}
            <button type="button" class="package-delete btn-danger mt-2" data-id="${p.id}">Delete</button>
        </div>
    `;
    }).join('');

    el.querySelectorAll('.package-delete').forEach(btn => {
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
                await api(`/api/packages/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
                loadList();
            } finally {
                if (btn.isConnected) btn.dataset.confirming = '';
            }
        });
    });
}

document.getElementById('package-add-open')?.addEventListener('click', () => {
    clearFieldError('package-add-error');
    openModal(addModal);
});

document.getElementById('package-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const body = {
        tracking_number: fd.get('tracking_number'),
        carrier: fd.get('carrier') || 'auto',
        label: fd.get('label') || null,
        external_url: fd.get('external_url') || null,
    };
    clearFieldError('package-add-error');
    const { res, data } = await api('/api/packages/create', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) {
        showFieldError('package-add-error', data.error || 'Could not add package.');
        return;
    }
    e.target.reset();
    closeModal(addModal);
    loadList();
});

loadList();
