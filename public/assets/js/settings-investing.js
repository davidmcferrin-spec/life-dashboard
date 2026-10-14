'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const card = document.getElementById('invest-card');

    function paint(score) {
        const rows = (score.positions || []).map((p) =>
            `<div class="ld-life-row"><span>${esc(p.symbol)} <span class="ld-meta">${esc(p.venue)}</span></span><strong>${esc(fmt.money(p.unrealized))}</strong></div>`
        ).join('');
        card.innerHTML = `<div class="ld-stat-value">${esc(fmt.money(score.market_value))}</div><p class="ld-meta">market value · unrealized ${esc(fmt.money(score.unrealized))}</p>${rows || '<p class="ld-meta">No positions.</p>'}`;
        const wallet = (score.wallets || []).find((w) => w.venue === 'polymarket');
        if (wallet) document.querySelector('#invest-wallet [name=address]').value = wallet.address;
    }

    async function load() {
        const res = await api.get('/invest/scorecard');
        paint(res.scorecard);
    }

    document.getElementById('invest-csv').addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        const res = await api.post('/invest/import', { csv: await file.text() });
        document.getElementById('invest-import-status').textContent = `Imported ${res.imported} rows, ${res.positions} positions.`;
        await load();
    });

    document.getElementById('invest-wallet').addEventListener('submit', async (e) => {
        e.preventDefault();
        const address = String(new FormData(e.target).get('address') || '');
        await api.post('/invest/wallet', { address });
        document.getElementById('invest-wallet-status').textContent = 'Saved.';
        await load();
    });

    document.getElementById('invest-refresh').addEventListener('click', async () => {
        const res = await api.post('/invest/polymarket/refresh', {});
        document.getElementById('invest-wallet-status').textContent = `Refreshed ${res.positions} public positions.`;
        await load();
    });

    load().catch((err) => { card.textContent = err.message; });
})();
