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
        el.innerHTML = '<p class="empty-state mono">No targets yet.</p>';
        return;
    }

    el.innerHTML = targets.map(t => `
        <div class="surface p-4" data-id="${t.id}">
            <div class="flex justify-between items-start gap-2">
                <div>
                    <span class="text-primary font-medium">${esc(t.label)}</span>
                    <p class="mono text-xs text-faint mt-1">${esc(t.target)}</p>
                </div>
                ${statusChip(t)}
            </div>
            ${t.latency_ms != null ? `<p class="mono text-xs text-faint mt-1">${esc(t.latency_ms)} ms</p>` : ''}
            <button type="button" class="ping-delete btn-danger mt-2" data-id="${t.id}">Delete</button>
        </div>
    `).join('');

    el.querySelectorAll('.ping-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Delete this target?')) return;
            await api(`/api/pings/${btn.dataset.id}/delete`, { method: 'POST', body: '{}' });
            loadList();
        });
    });
}

document.getElementById('ping-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const body = {
        label:  form.label.value.trim(),
        target: form.target.value.trim(),
    };
    const { res, data } = await api('/api/pings/create', { method: 'POST', body: JSON.stringify(body) });
    if (!res.ok) {
        alert(data.error || 'Could not add target.');
        return;
    }
    form.reset();
    loadList();
});

loadList();
