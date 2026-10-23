'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const card = document.getElementById('invest-card');
    const walletModal = document.getElementById('invest-wallet-modal');
    let walletAddress = '';

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

    function emptyPanel(title, body) {
        if (!window.DashboardEmpty) {
            return '<p class="ld-meta">' + esc(title) + '</p>';
        }
        return window.DashboardEmpty.html({ title: title, body: body });
    }

    function status(id, message) {
        const el = document.getElementById(id);
        if (el) el.textContent = message;
    }

    bindModal(walletModal);

    function openWallet() {
        clearFieldError('invest-wallet-error');
        const input = document.querySelector('#invest-wallet [name=address]');
        if (input) input.value = walletAddress;
        openModal(walletModal);
    }

    function paint(score) {
        const rows = (score.positions || []).map((p) =>
            `<div class="ld-life-row"><span>${esc(p.symbol)} <span class="ld-meta">${esc(p.venue)}</span></span><strong>${esc(fmt.money(p.unrealized))}</strong></div>`
        ).join('');
        const empty = rows ? '' : emptyPanel(
            'No positions',
            'Import a Robinhood activity CSV or refresh public Polymarket positions.'
        );
        card.innerHTML = `<div class="ld-stat-value">${esc(fmt.money(score.market_value))}</div><p class="ld-meta">market value · unrealized ${esc(fmt.money(score.unrealized))}</p>${rows}${empty}`;
        const wallet = (score.wallets || []).find((w) => w.venue === 'polymarket');
        walletAddress = wallet && wallet.address ? String(wallet.address) : '';
        const input = document.querySelector('#invest-wallet [name=address]');
        if (input && document.activeElement !== input) input.value = walletAddress;
    }

    async function load() {
        const res = await api.get('/invest/scorecard');
        paint(res.scorecard);
    }

    document.getElementById('invest-wallet-open').addEventListener('click', openWallet);

    document.getElementById('invest-csv').addEventListener('change', async (e) => {
        const file = e.target.files && e.target.files[0];
        if (!file) return;
        try {
            const res = await api.post('/invest/import', { csv: await file.text() });
            const message = `Imported ${res.imported} rows, ${res.positions} positions.`;
            status('invest-import-status', message);
            toast(message, 'ok');
            await load();
        } catch (err) {
            const message = err && err.message ? err.message : 'Could not import.';
            status('invest-import-status', message);
            toast(message);
        }
    });

    document.getElementById('invest-wallet').addEventListener('submit', async (e) => {
        e.preventDefault();
        const address = String(new FormData(e.target).get('address') || '');
        clearFieldError('invest-wallet-error');
        try {
            await api.post('/invest/wallet', { address });
            status('invest-wallet-status', 'Saved.');
            toast('Saved.', 'ok');
            closeModal(walletModal);
            await load();
        } catch (err) {
            showFieldError('invest-wallet-error', err);
        }
    });

    document.getElementById('invest-refresh').addEventListener('click', async () => {
        try {
            const res = await api.post('/invest/polymarket/refresh', {});
            const message = `Refreshed ${res.positions} public positions.`;
            status('invest-wallet-status', message);
            toast(message, 'ok');
            await load();
        } catch (err) {
            const message = err && err.message ? err.message : 'Could not refresh.';
            status('invest-wallet-status', message);
            toast(message);
        }
    });

    load().catch((err) => {
        const message = err && err.message ? err.message : 'Could not load the scorecard.';
        card.innerHTML = emptyPanel('Could not load the scorecard', message);
        toast(message);
    });
})();
