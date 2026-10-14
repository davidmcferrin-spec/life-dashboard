'use strict';

(function () {
    const { api, esc, fmt } = window.LD;

    async function load() {
        const [accounts, tx, summary] = await Promise.all([
            api.get('/money/accounts'),
            api.get('/money/transactions?limit=12'),
            api.get('/money/summary'),
        ]);
        document.getElementById('money-accounts').innerHTML = (accounts.accounts || []).map((a) =>
            `<form class="ld-life-row" data-account="${a.id}">
                <span>${esc(a.name)} <span class="ld-meta">${esc(a.kind)}</span></span>
                <span class="flex gap-2 items-center">
                    <input class="input" name="balance" type="number" step="0.01" value="${a.balance}">
                    <button class="btn text-xs" type="submit">Save</button>
                </span>
            </form>`
        ).join('') || '<p class="ld-meta">No accounts.</p>';
        const spent = summary.summary ? summary.summary.spent : 0;
        const net = summary.summary ? summary.summary.net_worth : 0;
        const rows = (tx.transactions || []).map((t) =>
            `<div class="ld-life-row"><span>${esc(t.date)} ${esc(t.payee || t.category)}</span><strong>${esc(fmt.money(t.amount))}</strong></div>`
        ).join('');
        document.getElementById('money-tx').innerHTML = `<p class="ld-meta">Spent ${esc(fmt.money(spent))} · net worth ${esc(fmt.money(net))}</p>${rows}`;
    }

    document.getElementById('money-account').addEventListener('submit', async (e) => {
        e.preventDefault();
        const data = new FormData(e.target);
        await api.post('/money/accounts', {
            name: String(data.get('name')),
            kind: String(data.get('kind')),
            balance: Number(data.get('balance') || 0),
        });
        e.target.reset();
        await load();
    });

    document.getElementById('money-accounts').addEventListener('submit', async (e) => {
        const form = e.target.closest('[data-account]');
        if (!form) return;
        e.preventDefault();
        await api.post('/money/accounts/' + form.dataset.account + '/update', {
            balance: Number(new FormData(form).get('balance')),
        });
        await load();
    });

    document.getElementById('money-csv').addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const csv = await file.text();
        const res = await api.post('/money/import', { csv });
        document.getElementById('money-import-status').textContent = `Imported ${res.imported} rows.`;
        await load();
    });

    document.getElementById('money-review').addEventListener('click', async () => {
        const res = await api.post('/money/weekly-review', {});
        location.href = '/brain?page=' + res.page.id;
    });

    load().catch((err) => {
        document.getElementById('money-accounts').textContent = err.message;
    });
})();
