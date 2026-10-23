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
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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

function emptyPanel(title, body, actionLabel, actionAttrs) {
    if (!window.DashboardEmpty) {
        return '<p class="fin-note">' + esc(title) + '</p>';
    }
    const opts = { title: title, body: body };
    if (actionLabel) {
        opts.actionLabel = actionLabel;
        opts.actionAttrs = actionAttrs || 'type="button"';
    }
    return window.DashboardEmpty.html(opts);
}

const addModal = document.getElementById('stocks-add-modal');
bindModal(addModal);

function openAdd() {
    clearFieldError('stocks-add-error');
    openModal(addModal);
}

let maxSymbols = 10;
let maxWidgetSymbols = 10;

function quoteCells(symbol) {
    if (symbol.price == null) {
        return '<td class="fin-num fin-await" colspan="3">Awaiting cron refresh</td>';
    }
    const change = Number(symbol.change_amount);
    const pct = Number(symbol.change_percent);
    if (!Number.isFinite(change) || !Number.isFinite(pct)) {
        return '<td class="fin-num">$' + esc(Number(symbol.price).toFixed(2)) + '</td><td class="fin-num">—</td><td class="fin-num">—</td>';
    }
    const tone = change < 0 ? 'is-down' : (change > 0 ? 'is-up' : '');
    const changeText = (change > 0 ? '+' : '') + change.toFixed(2);
    const pctText = (pct > 0 ? '+' : '') + pct.toFixed(2) + '%';
    return '<td class="fin-num">$' + esc(Number(symbol.price).toFixed(2)) + '</td>'
        + '<td class="fin-num ' + tone + '">' + esc(changeText) + '</td>'
        + '<td class="fin-num ' + tone + '">' + esc(pctText) + '</td>';
}

function paintMoves(symbols) {
    const host = document.getElementById('stocks-chart');
    if (!host) return;
    const priced = (symbols || []).filter((symbol) => symbol.price != null && Number.isFinite(Number(symbol.change_percent)));
    if (!priced.length) {
        host.innerHTML = emptyPanel('No quotes yet', 'Prices show up after the next cron refresh.');
        return;
    }
    const maxAbs = Math.max(...priced.map((symbol) => Math.abs(Number(symbol.change_percent))), 0.0001);
    host.innerHTML = '<div class="fin-bars" role="img" aria-label="Day percent change by symbol">' + priced.map((symbol) => {
        const pct = Number(symbol.change_percent);
        const height = Math.max(4, (Math.abs(pct) / maxAbs) * 100);
        const tone = pct < 0 ? 'is-down' : 'is-up';
        const text = (pct > 0 ? '+' : '') + pct.toFixed(2) + '%';
        return '<div class="fin-bar"><div class="fin-bar-track"><span class="fin-bar-fill ' + tone + '" style="height:' + height.toFixed(1) + '%"></span></div>'
            + '<span class="fin-bar-label"><strong>' + esc(symbol.symbol) + '</strong><span class="' + tone + '">' + esc(text) + '</span></span></div>';
    }).join('') + '</div>';
}

function paintPins(symbols) {
    const host = document.getElementById('stocks-pins');
    if (!host) return;
    const pinned = (symbols || []).filter((symbol) => symbol.pin_to_dashboard);
    if (!pinned.length) {
        host.innerHTML = emptyPanel('Nothing pinned', 'Choose up to ' + maxWidgetSymbols + ' symbols to show on the dashboard.');
        return;
    }
    host.innerHTML = '<div class="fin-accounts">' + pinned.map((symbol) => {
        const price = symbol.price == null ? 'Awaiting cron refresh' : '$' + Number(symbol.price).toFixed(2);
        return '<div class="fin-account fin-account-plain"><span><strong class="fin-account-name">' + esc(symbol.symbol) + '</strong>'
            + '<small class="fin-account-meta">Dashboard</small></span><b class="fin-account-balance">' + esc(price) + '</b></div>';
    }).join('') + '</div>';
}

async function loadList() {
    const el = document.getElementById('stocks-manage-list');
    const countEl = document.getElementById('stocks-manage-count');
    const pinCountEl = document.getElementById('stocks-pin-count');
    const upEl = document.getElementById('stocks-up-count');
    const downEl = document.getElementById('stocks-down-count');
    const addBtn = document.querySelector('#stocks-add-form button[type="submit"]');
    const openBtn = document.getElementById('stocks-add-open');
    if (!el) return;

    const { res, data } = await api('/api/stocks');
    if (!res.ok) {
        const message = 'Could not load symbols.';
        el.innerHTML = emptyPanel('Could not load symbols', message);
        paintMoves([]);
        paintPins([]);
        toast(message);
        return;
    }

    maxSymbols = data.max_symbols ?? 10;
    maxWidgetSymbols = data.max_widget_symbols ?? 10;
    const symbols = data.symbols || [];
    const pinnedCount = symbols.filter((symbol) => symbol.pin_to_dashboard).length;
    const priced = symbols.filter((symbol) => symbol.price != null);
    const up = priced.filter((symbol) => Number(symbol.change_amount) > 0).length;
    const down = priced.filter((symbol) => Number(symbol.change_amount) < 0).length;
    const atMax = symbols.length >= maxSymbols;

    if (countEl) countEl.textContent = `${symbols.length} / ${maxSymbols}`;
    if (pinCountEl) pinCountEl.textContent = `${pinnedCount} / ${maxWidgetSymbols}`;
    if (upEl) upEl.textContent = String(up);
    if (downEl) downEl.textContent = String(down);
    if (addBtn) addBtn.disabled = atMax;
    if (openBtn) openBtn.disabled = atMax;

    paintMoves(symbols);
    paintPins(symbols);

    if (symbols.length === 0) {
        el.innerHTML = emptyPanel(
            'No symbols yet',
            'Add a ticker to your watch list.',
            'Add symbol',
            'type="button" data-open-symbol'
        );
        return;
    }

    el.innerHTML = '<div class="fin-table-wrap"><table class="fin-table"><thead><tr>'
        + '<th>Symbol</th><th class="fin-num">Price</th><th class="fin-num">Change</th><th class="fin-num">Day</th><th>Dashboard</th><th></th>'
        + '</tr></thead><tbody>'
        + symbols.map((symbol) => '<tr><td class="fin-symbol">' + esc(symbol.symbol) + '</td>' + quoteCells(symbol)
            + '<td><label class="fin-pin"><input type="checkbox" class="stock-pin" data-id="' + esc(symbol.id) + '"'
            + (symbol.pin_to_dashboard ? ' checked' : '') + '> Show</label></td>'
            + '<td class="fin-actions"><button type="button" class="stock-delete btn-danger" data-id="' + esc(symbol.id) + '">Remove</button></td></tr>').join('')
        + '</tbody></table></div>';

    el.querySelectorAll('.stock-pin').forEach((box) => {
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

    el.querySelectorAll('.stock-delete').forEach((btn) => {
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

document.getElementById('stocks-add-form')?.addEventListener('submit', async (e) => {
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
