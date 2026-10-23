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
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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

const addModal = document.getElementById('ping-add-modal');
if (addModal) bindModal(addModal);

function statusChip(target) {
    if (target.is_up === true) {
        return `<span class="chip chip-ok">${esc(target.status_message || 'Up')}</span>`;
    }
    if (target.is_up === false) {
        return `<span class="chip chip-neutral text-danger">${esc(target.status_message || 'Down')}</span>`;
    }
    return '<span class="chip chip-neutral">Pending</span>';
}

async function loadList() {
    const el = document.getElementById('ping-manage-list');
    if (!el) return;

    const { res, data } = await api('/api/pings');
    if (!res.ok) {
        el.innerHTML = '<p class="text-danger text-sm mono">Could not load ping targets.</p>';
        return;
    }

    const targets = data.targets || [];
    if (targets.length === 0) {
        el.innerHTML = window.DashboardEmpty.html({
            title: 'No targets yet',
            body: 'Add a host to watch whether it answers.',
            actionLabel: 'System status',
            actionHref: '/settings/pings',
        });
        return;
    }

    el.innerHTML = targets.map(t => `
        <div class="watch-row" data-id="${t.id}">
            <div class="watch-row-main">
                <span class="text-primary font-medium">${esc(t.label)}</span>
                <p class="watch-row-meta">${esc(t.target)}</p>
                ${t.latency_ms != null ? `<p class="watch-row-meta">${esc(t.latency_ms)} ms</p>` : ''}
            </div>
            ${statusChip(t)}
            <button type="button" class="ping-delete btn-danger" data-id="${t.id}">Delete</button>
        </div>
    `).join('');

    el.querySelectorAll('.ping-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.dataset.confirming === '1') return;
            btn.dataset.confirming = '1';
            try {
                const ok = await globalThis.AppModal.confirm({
                    title: 'Delete target',
                    message: 'Delete this target?',
                    confirmLabel: 'Delete',
                    danger: true,
                });
                if (!ok) return;
                await api(`/api/pings/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
                loadList();
            } finally {
                if (btn.isConnected) btn.dataset.confirming = '';
            }
        });
    });
}

document.getElementById('ping-add-open')?.addEventListener('click', () => {
    clearFieldError('ping-add-error');
    openModal(addModal);
});

document.getElementById('ping-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const body = {
        label:  form.label.value.trim(),
        target: form.target.value.trim(),
    };
    clearFieldError('ping-add-error');
    const { res, data } = await api('/api/pings/create', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) {
        showFieldError('ping-add-error', data.error || 'Could not add target.');
        return;
    }
    form.reset();
    closeModal(addModal);
    loadList();
});

loadList();
