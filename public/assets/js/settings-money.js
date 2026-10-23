'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const addModal = document.getElementById('money-account-modal');
    const editModal = document.getElementById('money-edit-modal');

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

    function showFieldError(id, err) {
        const message = err && err.message ? err.message : 'Could not save.';
        const el = document.getElementById(id);
        if (!el) {
            toast(message);
            return;
        }
        el.textContent = message;
        el.classList.remove('hidden');
    }

    function emptyPanel(title, body, actionLabel) {
        if (!window.DashboardEmpty) {
            return '<p class="ld-meta">' + esc(title) + '</p>';
        }
        const opts = { title: title, body: body };
        if (actionLabel) {
            opts.actionLabel = actionLabel;
            opts.actionAttrs = 'type="button" data-open-account';
        }
        return window.DashboardEmpty.html(opts);
    }

    bindModal(addModal);
    bindModal(editModal);

    function openAdd() {
        clearFieldError('money-account-error');
        openModal(addModal);
    }

    function openEdit(id, balance) {
        const idInput = document.getElementById('money-edit-id');
        const balanceInput = document.getElementById('money-edit-balance');
        if (!editModal || !idInput || !balanceInput) return;
        clearFieldError('money-edit-error');
        idInput.value = String(id);
        balanceInput.value = balance;
        openModal(editModal);
    }

    async function load() {
        const [accounts, tx, summary] = await Promise.all([
            api.get('/money/accounts'),
            api.get('/money/transactions?limit=12'),
            api.get('/money/summary'),
        ]);
        const accountRows = (accounts.accounts || []).map((a) =>
            `<div class="ld-life-row">
                <span>${esc(a.name)} <span class="ld-meta">${esc(a.kind)}</span></span>
                <span class="flex gap-2 items-center">
                    <strong>${esc(fmt.money(a.balance))}</strong>
                    <button type="button" class="btn text-xs" data-edit-account="${esc(a.id)}" data-balance="${esc(a.balance)}">Edit</button>
                </span>
            </div>`
        ).join('');
        document.getElementById('money-accounts').innerHTML = accountRows || emptyPanel(
            'No accounts',
            'Add an account to keep a balance.',
            'Add account'
        );
        const spent = summary.summary ? summary.summary.spent : 0;
        const net = summary.summary ? summary.summary.net_worth : 0;
        const rows = (tx.transactions || []).map((t) =>
            `<div class="ld-life-row"><span>${esc(t.date)} ${esc(t.payee || t.category)}</span><strong>${esc(fmt.money(t.amount))}</strong></div>`
        ).join('');
        const txEmpty = rows ? '' : emptyPanel(
            'No recent transactions',
            'Imported rows show up here. A negative amount is money out.'
        );
        document.getElementById('money-tx').innerHTML = `<p class="ld-meta">Spent ${esc(fmt.money(spent))} · net worth ${esc(fmt.money(net))}</p>${rows}${txEmpty}`;
    }

    document.getElementById('money-account-open').addEventListener('click', openAdd);

    document.getElementById('money-accounts').addEventListener('click', (event) => {
        if (event.target.closest('[data-open-account]')) {
            openAdd();
            return;
        }
        const edit = event.target.closest('[data-edit-account]');
        if (!edit) return;
        openEdit(edit.dataset.editAccount, edit.dataset.balance || '');
    });

    document.getElementById('money-account').addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = new FormData(e.target);
        clearFieldError('money-account-error');
        try {
            await api.post('/money/accounts', {
                name: String(data.get('name')),
                kind: String(data.get('kind')),
                balance: Number(data.get('balance') || 0),
            });
            e.target.reset();
            closeModal(addModal);
            await load();
        } catch (err) {
            showFieldError('money-account-error', err);
        }
    });

    document.getElementById('money-edit').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const data = new FormData(form);
        const id = String(data.get('id') || '');
        if (!id) return;
        clearFieldError('money-edit-error');
        try {
            await api.post('/money/accounts/' + id + '/update', {
                balance: Number(data.get('balance')),
            });
            closeModal(editModal);
            await load();
        } catch (err) {
            showFieldError('money-edit-error', err);
        }
    });

    document.getElementById('money-csv').addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const csv = await file.text();
        try {
            const res = await api.post('/money/import', { csv });
            const message = `Imported ${res.imported} rows.`;
            document.getElementById('money-import-status').textContent = message;
            toast(message, 'ok');
            await load();
        } catch (err) {
            const message = err && err.message ? err.message : 'Could not import.';
            document.getElementById('money-import-status').textContent = message;
            toast(message);
        }
    });

    document.getElementById('money-review').addEventListener('click', async () => {
        try {
            const res = await api.post('/money/weekly-review', {});
            location.href = '/brain?page=' + res.page.id;
        } catch (err) {
            toast(err && err.message ? err.message : 'Could not write the review.');
        }
    });

    load().catch((err) => {
        const message = err && err.message ? err.message : 'Could not load accounts.';
        document.getElementById('money-accounts').innerHTML = emptyPanel('Could not load accounts', message);
        toast(message);
    });
})();
