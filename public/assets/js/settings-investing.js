'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const card = document.getElementById('invest-card');
    const walletModal = document.getElementById('invest-wallet-modal');
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
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
            return '<p class="fin-note">' + esc(title) + '</p>';
        }
        return window.DashboardEmpty.html({ title: title, body: body });
    }

    function status(id, message) {
        const el = document.getElementById(id);
        if (el) el.textContent = message;
    }

    function walletIcon() {
        const node = document.getElementById('fin-icon-wallet');
        return node ? node.innerHTML.trim() : '';
    }

    function maskTail(value) {
        const text = String(value || '').trim();
        if (text.length < 4) return '';
        return '•••• ' + text.slice(-4);
    }

    function monthLabel(iso) {
        const month = Number(String(iso).slice(5, 7));
        return MONTHS[month - 1] || String(iso);
    }

    function statCard(label, value, cls) {
        const tone = cls ? ' ' + cls : '';
        return '<article class="fin-stat"><span class="fin-stat-label">' + esc(label) + '</span><strong class="fin-stat-value' + tone + '">'
            + esc(value) + '</strong></article>';
    }

    function lineChart(points, label) {
        const w = 700;
        const h = 180;
        const pad = 12;
        const values = points.map((point) => point.value);
        const min = Math.min(0, ...values);
        const max = Math.max(0, ...values);
        const span = (max - min) || 1;
        let coords = values.map((value, index) => {
            const x = points.length === 1 ? w / 2 : (index / (points.length - 1)) * w;
            const y = pad + (1 - ((value - min) / span)) * (h - pad * 2);
            return [x, y];
        });
        if (coords.length === 1) {
            coords = [[0, coords[0][1]], [w, coords[0][1]]];
        }
        const line = coords.map((coord, index) => (index ? 'L' : 'M') + coord[0].toFixed(1) + ' ' + coord[1].toFixed(1)).join(' ');
        const area = line + ' L' + w + ' ' + h + ' L0 ' + h + ' Z';
        const axis = points.map((point) => '<span>' + esc(point.label) + '</span>').join('');
        return '<div class="fin-chart-frame"><div class="fin-chart-y"><span>' + esc(fmt.money(max)) + '</span><span>' + esc(fmt.money(min))
            + '</span></div><svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="' + esc(label) + '">'
            + '<path class="fin-chart-area" d="' + area + '"></path><path class="fin-chart-line" d="' + line + '"></path></svg></div>'
            + '<div class="fin-axis">' + axis + '</div>';
    }

    function shares(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return '—';
        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(number);
    }

    bindModal(walletModal);

    function openWallet() {
        clearFieldError('invest-wallet-error');
        const input = document.querySelector('#invest-wallet [name=address]');
        if (input) input.value = walletAddress;
        openModal(walletModal);
    }

    function paint(score) {
        const positions = score.positions || [];
        let cost = 0;
        positions.forEach((position) => {
            cost += Number(position.avg_cost) * Number(position.quantity);
        });
        const unrealized = Number(score.unrealized) || 0;
        const unrealTone = unrealized < 0 ? 'is-down' : (unrealized > 0 ? 'is-up' : '');
        const returnPct = cost !== 0 ? (unrealized / Math.abs(cost)) * 100 : null;
        const returnText = returnPct == null ? '—' : (returnPct > 0 ? '+' : '') + returnPct.toFixed(1) + '%';
        const stats = document.getElementById('invest-stats');
        if (stats) {
            stats.innerHTML = [
                statCard('Portfolio value', fmt.money(score.market_value)),
                statCard('Unrealized', fmt.money(unrealized), unrealTone),
                statCard('Positions', String(positions.length)),
                statCard('Unrealized return', returnText, returnPct == null ? '' : (returnPct < 0 ? 'is-down' : 'is-up')),
            ].join('');
        }

        const activity = score.activity || [];
        const totals = new Map();
        activity.forEach((row) => {
            if (row.amount == null || !row.date) return;
            const amount = Number(row.amount);
            if (!Number.isFinite(amount)) return;
            const key = String(row.date).slice(0, 7);
            totals.set(key, (totals.get(key) || 0) + amount);
        });
        const points = Array.from(totals.keys()).sort().map((key) => ({
            label: monthLabel(key + '-01'),
            value: totals.get(key),
        }));
        const chart = document.getElementById('invest-chart');
        if (chart) {
            const any = points.some((point) => point.value !== 0);
            chart.innerHTML = any
                ? lineChart(points, 'Recent activity amounts')
                : emptyPanel('No activity amounts yet', 'Import a Robinhood activity CSV to plot them.');
        }

        const icon = walletIcon();
        const wallets = (score.wallets || []).map((wallet) => {
            const mask = maskTail(wallet.address);
            const mark = icon ? '<span class="fin-icon" aria-hidden="true">' + icon + '</span>' : '';
            return '<div class="fin-account">' + mark
                + '<span><strong class="fin-account-name">' + esc(wallet.venue || 'Wallet') + '</strong>'
                + '<small class="fin-account-meta">' + esc(mask || 'Saved') + '</small></span></div>';
        }).join('');
        const walletHost = document.getElementById('invest-wallets');
        if (walletHost) {
            walletHost.innerHTML = wallets || emptyPanel(
                'No wallet saved',
                'Save a Polymarket address, then refresh public positions.'
            );
        }

        const body = positions.map((position) => {
            const rowCost = Number(position.avg_cost) * Number(position.quantity);
            const rowUnreal = Number(position.unrealized);
            const tone = rowUnreal < 0 ? 'is-down' : (rowUnreal > 0 ? 'is-up' : '');
            let ret = fmt.money(rowUnreal);
            if (rowCost !== 0 && Number.isFinite(rowUnreal)) {
                const pct = (rowUnreal / Math.abs(rowCost)) * 100;
                ret += ' (' + (pct > 0 ? '+' : '') + pct.toFixed(1) + '%)';
            }
            return '<tr><td>' + esc(position.symbol) + '</td><td>' + esc(position.venue) + '</td><td class="fin-num">'
                + esc(shares(position.quantity)) + '</td><td class="fin-num">' + esc(fmt.money(position.mark)) + '</td><td class="fin-num">'
                + esc(fmt.money(position.market_value)) + '</td><td class="fin-num ' + tone + '">' + esc(ret) + '</td></tr>';
        }).join('');
        card.innerHTML = body
            ? '<div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>Asset</th><th>Venue</th><th class="fin-num">Shares</th><th class="fin-num">Price</th><th class="fin-num">Value</th><th class="fin-num">Return</th></tr></thead><tbody>'
                + body + '</tbody></table></div>'
            : emptyPanel('No positions', 'Import a Robinhood activity CSV or refresh public Polymarket positions.');

        const wallet = (score.wallets || []).find((item) => item.venue === 'polymarket');
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
        const chart = document.getElementById('invest-chart');
        if (chart) chart.innerHTML = emptyPanel('Could not load activity', message);
        toast(message);
    });
})();
