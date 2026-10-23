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

function formatQuote(s) {
    if (s.price == null) {
        return '<span class="mono text-xs text-faint">Awaiting cron refresh</span>';
    }
    const up = s.change_amount >= 0;
    const color = up ? 'quote-up' : 'quote-down';
    const price = s.price.toFixed(2);
    const chg = `${s.change_amount >= 0 ? '+' : ''}${s.change_amount.toFixed(2)}`;
    const pct = `${s.change_percent >= 0 ? '+' : ''}${s.change_percent.toFixed(2)}%`;
    return `<span class="mono text-sm text-secondary">$${price}</span>
        <span class="mono text-xs ${color}">${chg} (${pct})</span>`;
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
    if (!modal) return;
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

function toast(message, tone) {
    if (window.DashboardToast) {
        window.DashboardToast.show(message, tone);
        return;
    }
    alert(message);
}

function showFieldError(id, message) {
    const el = document.getElementById(id);
    if (!el) {
        toast(message);
        return;
    }
    el.textContent = message;
    el.classList.remove('hidden');
}

const addModal = document.getElementById('stocks-add-modal');
bindModal(addModal);

function openAdd() {
    clearFieldError('stocks-add-error');
    openModal(addModal);
}

let maxSymbols = 10;
let maxWidgetSymbols = 10;

async function loadList() {
    const el = document.getElementById('stocks-manage-list');
    const countEl = document.getElementById('stocks-manage-count');
    const pinCountEl = document.getElementById('stocks-pin-count');
    const addBtn = document.querySelector('#stocks-add-form button[type="submit"]');
    const openBtn = document.getElementById('stocks-add-open');
    if (!el) return;

    const { res, data } = await api('/api/stocks');
    if (!res.ok) {
        const message = 'Could not load symbols.';
        el.innerHTML = window.DashboardEmpty
            ? window.DashboardEmpty.html({ title: 'Could not load symbols', body: message })
            : '<p class="text-danger text-sm mono">Could not load symbols.</p>';
        toast(message);
        return;
    }

    maxSymbols = data.max_symbols ?? 10;
    maxWidgetSymbols = data.max_widget_symbols ?? 10;
    const symbols = data.symbols || [];
    const pinnedCount = symbols.filter(s => s.pin_to_dashboard).length;
    const atMax = symbols.length >= maxSymbols;

    if (countEl) {
        countEl.textContent = `${symbols.length} / ${maxSymbols}`;
    }
    if (pinCountEl) {
        pinCountEl.textContent = `${pinnedCount} / ${maxWidgetSymbols} on dashboard`;
    }
    if (addBtn) {
        addBtn.disabled = atMax;
    }
    if (openBtn) {
        openBtn.disabled = atMax;
    }

    if (symbols.length === 0) {
        el.innerHTML = window.DashboardEmpty.html({
            title: 'No symbols yet',
            body: 'Add a ticker to your watch list.',
            actionLabel: 'Add symbol',
            actionAttrs: 'type="button" data-open-symbol',
        });
        return;
    }

    el.innerHTML = symbols.map(s => `
        <div class="border border-chrome rounded-lg p-3 flex flex-wrap items-center justify-between gap-3" data-id="${s.id}">
            <div class="flex flex-wrap items-center gap-3 min-w-0">
                <span class="mono text-primary font-semibold">${esc(s.symbol)}</span>
                <label class="flex items-center gap-1.5 text-xs text-muted cursor-pointer">
                    <input type="checkbox" class="stock-pin" data-id="${s.id}"${s.pin_to_dashboard ? ' checked' : ''}>
                    Show on dashboard
                </label>
            </div>
            <div class="flex flex-col items-end gap-0.5">${formatQuote(s)}</div>
            <button type="button" class="stock-delete btn-danger" data-id="${s.id}">Remove</button>
        </div>
    `).join('');

    el.querySelectorAll('.stock-pin').forEach(box => {
        box.addEventListener('change', async () => {
            if (box.checked) {
                const checked = el.querySelectorAll('.stock-pin:checked').length;
                if (checked > maxWidgetSymbols) {
                    box.checked = false;
                    toast(`Maximum of ${maxWidgetSymbols} symbols can appear on the dashboard.`);
                    return;
                }
            }

            const { res: pinRes, data: pinData } = await api(`/api/stocks/${box.dataset.id}/update`, {
                method: 'POST',
                body: JSON.stringify({ pin_to_dashboard: box.checked }),
            });
            if (!pinRes.ok) {
                box.checked = !box.checked;
                toast(pinData.error || 'Could not update dashboard display.');
                return;
            }
            loadList();
        });
    });

    el.querySelectorAll('.stock-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.dataset.confirming === '1') return;
            btn.dataset.confirming = '1';
            try {
                const ask = globalThis.AppModal && globalThis.AppModal.confirm;
                const ok = typeof ask === 'function'
                    ? await ask({
                        title: 'Remove symbol',
                        message: 'Remove this symbol from your watch list?',
                        confirmLabel: 'Remove',
                        danger: true,
                    })
                    : false;
                if (!ok) return;
                const { res: delRes, data: delData } = await api(`/api/stocks/${btn.dataset.id}/delete`, {
                    method: 'POST',
                    body: '{}',
                });
                if (!delRes.ok) {
                    toast(delData.error || 'Could not remove symbol.');
                    return;
                }
                loadList();
            } finally {
                if (btn.isConnected) btn.dataset.confirming = '';
            }
        });
    });
}

document.getElementById('stocks-add-open')?.addEventListener('click', openAdd);

document.getElementById('stocks-manage-list')?.addEventListener('click', (event) => {
    if (event.target.closest('[data-open-symbol]')) openAdd();
});

document.getElementById('stocks-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const symbol = String(fd.get('symbol') || '').trim();
    if (!symbol) return;

    clearFieldError('stocks-add-error');
    const { res, data } = await api('/api/stocks/create', {
        method: 'POST',
        body: JSON.stringify({ symbol }),
    });
    if (!res.ok) {
        showFieldError('stocks-add-error', data.error || 'Could not add symbol.');
        return;
    }
    e.target.reset();
    closeModal(addModal);
    loadList();
});

loadList();
