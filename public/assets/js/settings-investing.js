'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const card = document.getElementById('invest-card');
    const walletModal = document.getElementById('invest-wallet-modal');
    const positionModal = document.getElementById('invest-position-modal');
    const cashModal = document.getElementById('invest-cash-modal');
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    let walletAddress = '';
    let holdings = [];

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

    function maskLast4(last4) {
        const digits = String(last4 || '').replace(/\D/g, '').slice(-4);
        if (digits.length !== 4) return '';
        return '•••• ' + digits;
    }

    function pointLabel(iso) {
        const month = MONTHS[Number(String(iso).slice(5, 7)) - 1] || '';
        const day = String(Number(String(iso).slice(8, 10)));
        return (month + ' ' + day).trim();
    }

    function dashMoney(value) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        return fmt.money(value);
    }

    function signedMoney(value) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        const number = Number(value);
        const text = fmt.money(Math.abs(number));
        if (number > 0) return '+' + text;
        if (number < 0) return '−' + text;
        return text;
    }

    function signedPct(ratio, digits) {
        if (ratio == null || !Number.isFinite(Number(ratio))) return null;
        const pct = Number(ratio) * 100;
        const sign = pct > 0 ? '+' : '';
        return sign + pct.toFixed(digits) + '%';
    }

    function statCard(label, value, opts) {
        const options = opts || {};
        const tone = options.cls ? ' ' + options.cls : '';
        let delta = '';
        if (options.pct) {
            const deltaTone = String(options.pct).startsWith('-') ? 'is-down' : 'is-up';
            delta = '<small class="fin-delta ' + deltaTone + '">' + esc(options.pct)
                + ' <span>' + esc(options.caption || 'vs previous day') + '</span></small>';
        }
        return '<article class="fin-stat"><span class="fin-stat-label">' + esc(label) + '</span><strong class="fin-stat-value' + tone + '">'
            + esc(value) + '</strong>' + delta + '</article>';
    }

    function lineChart(points, label) {
        const w = 700;
        const h = 180;
        const pad = 12;
        const values = points.map((point) => point.value);
        const min = Math.min(...values);
        const max = Math.max(...values);
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
        let labels = points.map((point) => point.label);
        if (labels.length > 6) {
            const mid = labels[Math.floor((labels.length - 1) / 2)];
            labels = [labels[0], mid, labels[labels.length - 1]];
        }
        const axis = labels.map((text) => '<span>' + esc(text) + '</span>').join('');
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

    function returnText(ratio) {
        return signedPct(ratio, 1) || '—';
    }

    bindModal(walletModal);
    bindModal(positionModal);
    bindModal(cashModal);

    function openWallet() {
        clearFieldError('invest-wallet-error');
        const input = document.querySelector('#invest-wallet [name=address]');
        if (input) input.value = walletAddress;
        openModal(walletModal);
    }

    function openPosition(position) {
        const form = document.getElementById('invest-position');
        const title = document.getElementById('invest-position-title');
        if (!form) return;
        form.reset();
        clearFieldError('invest-position-error');
        form.elements.id.value = position && position.id ? String(position.id) : '';
        if (position) {
            form.elements.name.value = position.name && position.name !== position.symbol ? position.name : '';
            form.elements.symbol.value = position.symbol || '';
            form.elements.quantity.value = position.quantity == null ? '' : String(position.quantity);
            form.elements.avg_cost.value = position.avg_cost == null ? '' : String(position.avg_cost);
            form.elements.mark.value = position.mark == null ? '' : String(position.mark);
        }
        if (title) title.textContent = position ? 'Edit position' : 'Add position';
        openModal(positionModal);
    }

    function openCash(score) {
        clearFieldError('invest-cash-error');
        const input = document.querySelector('#invest-cash [name=cash]');
        if (input) input.value = score && score.buying_power != null ? String(score.buying_power) : '';
        openModal(cashModal);
    }

    function blankNumber(value) {
        if (value == null) return null;
        const text = String(value).trim();
        if (text === '') return null;
        return Number(text);
    }

    function paintAccounts(accounts, score) {
        const icon = walletIcon();
        const rows = (accounts || []).filter((account) => account.kind === 'investment').map((account) => {
            const mask = maskLast4(account.last4);
            const meta = [account.kind, mask].filter(Boolean).join(' · ');
            const mark = icon ? '<span class="fin-icon" aria-hidden="true">' + icon + '</span>' : '';
            return '<div class="fin-account">' + mark
                + '<span><strong class="fin-account-name">' + esc(account.name) + '</strong><small class="fin-account-meta">' + esc(meta) + '</small></span>'
                + '<b class="fin-account-balance">' + esc(fmt.money(account.balance)) + '</b></div>';
        });
        (score.wallets || []).forEach((wallet) => {
            const mask = maskTail(wallet.address);
            const mark = icon ? '<span class="fin-icon" aria-hidden="true">' + icon + '</span>' : '';
            rows.push('<div class="fin-account">' + mark
                + '<span><strong class="fin-account-name">' + esc(wallet.venue || 'Wallet') + '</strong>'
                + '<small class="fin-account-meta">' + esc(mask || 'Saved') + '</small></span></div>');
        });
        const host = document.getElementById('invest-accounts');
        if (!host) return;
        host.innerHTML = rows.join('') || emptyPanel('No accounts', 'Investment accounts and a saved Polymarket wallet show up here.');
    }

    function paint(score, accounts) {
        const day = signedMoney(score.day_change);
        const dayPct = signedPct(score.day_change_ratio, 2);
        const annual = score.annual_return == null ? '—' : (signedPct(score.annual_return, 1) || '—');
        const dayTone = score.day_change < 0 ? 'is-down' : (score.day_change > 0 ? 'is-up' : '');
        const annualTone = score.annual_return < 0 ? 'is-down' : (score.annual_return > 0 ? 'is-up' : '');
        const stats = document.getElementById('invest-stats');
        if (stats) {
            stats.innerHTML = [
                statCard('Portfolio value', dashMoney(score.portfolio_value)),
                statCard('Day change', day, { cls: day === '—' ? '' : dayTone, pct: dayPct, caption: 'vs previous day' }),
                statCard('Buying power', dashMoney(score.buying_power)),
                statCard('Annual return', annual, { cls: annual === '—' ? '' : annualTone }),
            ].join('');
        }

        const series = Array.isArray(score.series) ? score.series : [];
        const points = series.filter((point) => point && point.market_value != null && Number.isFinite(Number(point.market_value))).map((point) => ({
            label: pointLabel(point.date),
            value: Number(point.market_value),
        }));
        const chart = document.getElementById('invest-chart');
        if (chart) {
            chart.innerHTML = points.length
                ? lineChart(points, 'Portfolio value for the last six months')
                : emptyPanel('No portfolio history yet', 'A point is stored when the portfolio value is known.');
        }

        paintAccounts(accounts, score);
        holdings = score.positions || [];
        const body = holdings.map((position) => {
            const ratio = position.return_ratio;
            const tone = ratio < 0 ? 'is-down' : (ratio > 0 ? 'is-up' : '');
            const menu = window.FinTable ? window.FinTable.rowMenu(
                position.manual
                    ? [{ act: 'edit', label: 'Edit holding' }, { act: 'remove', label: 'Remove holding' }, { act: 'filter', label: 'Filter to symbol' }]
                    : [{ act: 'filter', label: 'Filter to symbol' }]
            ) : '';
            const filter = [position.name, position.symbol, position.venue].join(' ');
            return '<tr data-id="' + esc(position.id) + '" data-symbol="' + esc(position.symbol) + '" data-filter="' + esc(filter) + '"><td>'
                + esc(position.name || position.symbol) + '</td><td class="fin-symbol">' + esc(position.symbol) + '</td><td class="fin-num">'
                + esc(shares(position.quantity)) + '</td><td class="fin-num">' + esc(dashMoney(position.mark)) + '</td><td class="fin-num">'
                + esc(dashMoney(position.market_value)) + '</td><td class="fin-num ' + tone + '">' + esc(returnText(ratio)) + '</td><td class="fin-actions">'
                + menu + '</td></tr>';
        }).join('');
        card.innerHTML = body
            ? '<div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>Asset</th><th>Symbol</th><th class="fin-num">Shares</th><th class="fin-num">Price</th><th class="fin-num">Value</th><th class="fin-num">Return</th><th></th></tr></thead><tbody>'
                + body + '</tbody></table></div>'
            : emptyPanel('No positions', 'Add a holding, import a Robinhood activity CSV, or refresh public Polymarket positions.');
        if (window.FinTable) {
            window.FinTable.bindHost(card);
            window.FinTable.bindFilter(
                document.getElementById('invest-filter'),
                document.getElementById('invest-filter-input'),
                document.getElementById('invest-filter-row'),
                card
            );
        }

        const wallet = (score.wallets || []).find((item) => item.venue === 'polymarket');
        walletAddress = wallet && wallet.address ? String(wallet.address) : '';
        const input = document.querySelector('#invest-wallet [name=address]');
        if (input && document.activeElement !== input) input.value = walletAddress;
        card.dataset.buyingPower = score.buying_power == null ? '' : String(score.buying_power);
    }

    async function load() {
        const [res, accounts] = await Promise.all([
            api.get('/invest/scorecard'),
            api.get('/money/accounts').catch(() => ({ accounts: [] })),
        ]);
        paint(res.scorecard, accounts.accounts || []);
    }

    document.getElementById('invest-wallet-open').addEventListener('click', openWallet);
    document.getElementById('invest-position-open').addEventListener('click', () => openPosition(null));
    document.getElementById('invest-cash-open').addEventListener('click', () => {
        const raw = card.dataset.buyingPower;
        openCash({ buying_power: raw === '' ? null : Number(raw) });
    });

    card.addEventListener('click', async (event) => {
        const item = event.target.closest('[data-fin-act]');
        if (!item) return;
        const row = item.closest('tr');
        if (!row) return;
        const act = item.getAttribute('data-fin-act');
        if (act === 'filter' && window.FinTable) {
            window.FinTable.setQuery(
                document.getElementById('invest-filter'),
                document.getElementById('invest-filter-input'),
                document.getElementById('invest-filter-row'),
                card,
                row.getAttribute('data-symbol') || ''
            );
            return;
        }
        const position = holdings.find((entry) => String(entry.id) === row.getAttribute('data-id'));
        if (act === 'edit' && position) {
            openPosition(position);
            return;
        }
        if (act !== 'remove' || !position) return;
        const ask = globalThis.AppModal && globalThis.AppModal.confirm;
        const ok = typeof ask === 'function'
            ? await ask({
                title: 'Remove holding',
                message: 'Remove this manual holding? This does not place an order.',
                confirmLabel: 'Remove',
                danger: true,
            })
            : false;
        if (!ok) return;
        try {
            await api.post('/invest/positions/' + position.id + '/delete', {});
            toast('Removed.', 'ok');
            await load();
        } catch (err) {
            toast(err && err.message ? err.message : 'Could not remove the holding.');
        }
    });

    document.getElementById('invest-position').addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = new FormData(event.target);
        clearFieldError('invest-position-error');
        const body = {
            symbol: String(data.get('symbol') || ''),
            name: String(data.get('name') || ''),
            quantity: blankNumber(data.get('quantity')),
            avg_cost: blankNumber(data.get('avg_cost')),
            mark: blankNumber(data.get('mark')),
        };
        const id = String(data.get('id') || '');
        if (id) body.id = Number(id);
        try {
            await api.post('/invest/positions', body);
            closeModal(positionModal);
            toast('Saved.', 'ok');
            await load();
        } catch (err) {
            showFieldError('invest-position-error', err);
        }
    });

    async function saveCash(cash) {
        clearFieldError('invest-cash-error');
        try {
            await api.post('/invest/cash', { cash: cash });
            closeModal(cashModal);
            toast('Saved.', 'ok');
            await load();
        } catch (err) {
            showFieldError('invest-cash-error', err);
        }
    }

    document.getElementById('invest-cash').addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = new FormData(event.target);
        await saveCash(blankNumber(data.get('cash')));
    });
    document.getElementById('invest-cash-clear').addEventListener('click', () => saveCash(null));

    document.getElementById('invest-csv').addEventListener('change', async (event) => {
        const file = event.target.files && event.target.files[0];
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

    document.getElementById('invest-wallet').addEventListener('submit', async (event) => {
        event.preventDefault();
        const address = String(new FormData(event.target).get('address') || '');
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
        if (chart) chart.innerHTML = emptyPanel('Could not load portfolio history', message);
        toast(message);
    });
})();
