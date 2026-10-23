'use strict';

(function () {
    const ld = window.LD;
    const positionModal = document.getElementById('stocks-position-modal');
    const addModal = document.getElementById('stocks-add-modal');
    const list = document.getElementById('stocks-manage-list');
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    let maxSymbols = 10;
    let maxWidgetSymbols = 10;
    let holdings = [];
    let quotes = [];

    function esc(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

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

    function dashMoney(value) {
        if (!ld || value == null || !Number.isFinite(Number(value))) return '—';
        return ld.fmt.money(value);
    }

    function signedMoney(value) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        const number = Number(value);
        const text = dashMoney(Math.abs(number));
        if (text === '—') return text;
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

    function statCard(label, value, cls) {
        const tone = cls ? ' ' + cls : '';
        return '<article class="fin-stat"><span class="fin-stat-label">' + esc(label) + '</span><strong class="fin-stat-value' + tone + '">'
            + esc(value) + '</strong></article>';
    }

    function pointLabel(iso) {
        const month = MONTHS[Number(String(iso).slice(5, 7)) - 1] || '';
        const day = String(Number(String(iso).slice(8, 10)));
        return (month + ' ' + day).trim();
    }

    function shares(value) {
        if (value == null || !Number.isFinite(Number(value))) return '—';
        return new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(Number(value));
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
            labels = [labels[0], labels[Math.floor((labels.length - 1) / 2)], labels[labels.length - 1]];
        }
        return '<div class="fin-chart-frame"><div class="fin-chart-y"><span>' + esc(dashMoney(max)) + '</span><span>' + esc(dashMoney(min))
            + '</span></div><svg viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" role="img" aria-label="' + esc(label) + '">'
            + '<path class="fin-chart-area" d="' + area + '"></path><path class="fin-chart-line" d="' + line + '"></path></svg></div>'
            + '<div class="fin-axis">' + labels.map((text) => '<span>' + esc(text) + '</span>').join('') + '</div>';
    }

    function walletIcon() {
        const node = document.getElementById('fin-icon-wallet');
        return node ? node.innerHTML.trim() : '';
    }

    function maskLast4(last4) {
        const digits = String(last4 || '').replace(/\D/g, '').slice(-4);
        if (digits.length !== 4) return '';
        return '•••• ' + digits;
    }

    function blankNumber(value) {
        if (value == null) return null;
        const text = String(value).trim();
        if (text === '') return null;
        return Number(text);
    }

    bindModal(positionModal);
    bindModal(addModal);

    function openPosition(position) {
        const form = document.getElementById('stocks-position-form');
        const title = document.getElementById('stocks-position-title');
        if (!form) return;
        form.reset();
        clearFieldError('stocks-position-error');
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

    function openAdd() {
        clearFieldError('stocks-add-error');
        openModal(addModal);
    }

    function paintStats(score) {
        const host = document.getElementById('stocks-stats');
        if (!host) return;
        const day = signedMoney(score.day_change);
        const annual = score.annual_return == null ? '—' : (signedPct(score.annual_return, 1) || '—');
        host.innerHTML = [
            statCard('Portfolio value', dashMoney(score.portfolio_value)),
            statCard('Day change', day, day === '—' ? '' : (score.day_change < 0 ? 'is-down' : 'is-up')),
            statCard('Buying power', dashMoney(score.buying_power)),
            statCard('Annual return', annual, annual === '—' ? '' : (score.annual_return < 0 ? 'is-down' : 'is-up')),
        ].join('');
    }

    function paintChart(score) {
        const host = document.getElementById('stocks-chart');
        if (!host) return;
        const points = (score.series || []).filter((point) => point && point.market_value != null && Number.isFinite(Number(point.market_value))).map((point) => ({
            label: pointLabel(point.date),
            value: Number(point.market_value),
        }));
        host.innerHTML = points.length
            ? lineChart(points, 'Portfolio value for the last six months')
            : emptyPanel('No portfolio history yet', 'A point is stored when the portfolio value is known.');
    }

    function paintAccounts(accounts) {
        const host = document.getElementById('stocks-accounts');
        if (!host) return;
        const icon = walletIcon();
        const rows = (accounts || []).filter((account) => account.kind === 'investment').map((account) => {
            const mask = maskLast4(account.last4);
            const meta = [account.kind, mask].filter(Boolean).join(' · ');
            const mark = icon ? '<span class="fin-icon" aria-hidden="true">' + icon + '</span>' : '';
            return '<div class="fin-account">' + mark
                + '<span><strong class="fin-account-name">' + esc(account.name) + '</strong><small class="fin-account-meta">' + esc(meta) + '</small></span>'
                + '<b class="fin-account-balance">' + esc(dashMoney(account.balance)) + '</b></div>';
        }).join('');
        host.innerHTML = rows || emptyPanel('No investment accounts', 'Accounts marked investment show up here.');
    }

    function paintTable() {
        if (!list) return;
        const seen = new Set();
        const rows = [];
        holdings.forEach((position) => {
            const symbol = String(position.symbol || '').toUpperCase();
            seen.add(symbol);
            const quote = quotes.find((item) => String(item.symbol || '').toUpperCase() === symbol);
            rows.push({
                kind: 'position',
                position: position,
                quote: quote || null,
                symbol: symbol,
            });
        });
        quotes.forEach((quote) => {
            const symbol = String(quote.symbol || '').toUpperCase();
            if (seen.has(symbol)) return;
            rows.push({ kind: 'quote', position: null, quote: quote, symbol: symbol });
        });

        if (!rows.length) {
            list.innerHTML = emptyPanel('No holdings yet', 'Add a position to record shares. Add a symbol to watch a quote.', 'Add position', 'type="button" data-open-position');
            return;
        }

        const body = rows.map((row) => {
            const position = row.position;
            const quote = row.quote;
            const asset = position ? (position.name || position.symbol) : '—';
            const shareText = position ? shares(position.quantity) : '—';
            const price = position && position.mark != null ? position.mark : null;
            const value = position ? position.market_value : null;
            const ratio = position ? position.return_ratio : null;
            const tone = ratio < 0 ? 'is-down' : (ratio > 0 ? 'is-up' : '');
            const items = [];
            if (position && position.manual) {
                items.push({ act: 'edit', label: 'Edit holding' });
                items.push({ act: 'remove-position', label: 'Remove holding' });
            }
            if (quote) {
                items.push({ act: 'pin', label: quote.pin_to_dashboard ? 'Unpin from dashboard' : 'Pin to dashboard' });
                items.push({ act: 'remove-symbol', label: 'Remove symbol' });
            }
            items.push({ act: 'filter', label: 'Filter to symbol' });
            const menu = window.FinTable ? window.FinTable.rowMenu(items) : '';
            const filter = [asset, row.symbol].join(' ');
            return '<tr data-filter="' + esc(filter) + '" data-symbol="' + esc(row.symbol) + '" data-position="' + esc(position ? position.id : '') + '" data-watch="' + esc(quote ? quote.id : '') + '"><td>'
                + esc(asset) + '</td><td class="fin-symbol">' + esc(row.symbol) + '</td><td class="fin-num">' + esc(shareText) + '</td><td class="fin-num">'
                + esc(price == null ? '—' : dashMoney(price)) + '</td><td class="fin-num">' + esc(dashMoney(value)) + '</td><td class="fin-num ' + tone + '">'
                + esc(signedPct(ratio, 1) || '—') + '</td><td class="fin-actions">' + menu + '</td></tr>';
        }).join('');
        list.innerHTML = '<div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>Asset</th><th>Symbol</th><th class="fin-num">Shares</th><th class="fin-num">Price</th><th class="fin-num">Value</th><th class="fin-num">Return</th><th></th></tr></thead><tbody>'
            + body + '</tbody></table></div>';
        if (window.FinTable) {
            window.FinTable.bindHost(list);
            window.FinTable.bindFilter(
                document.getElementById('stocks-filter'),
                document.getElementById('stocks-filter-input'),
                document.getElementById('stocks-filter-row'),
                list
            );
        }
    }

    async function loadList() {
        const [stockRes, scoreRes, accountRes] = await Promise.all([
            api('/api/stocks'),
            ld ? ld.api.get('/invest/scorecard') : Promise.resolve(null),
            ld ? ld.api.get('/money/accounts').catch(() => ({ accounts: [] })) : Promise.resolve({ accounts: [] }),
        ]);
        if (!stockRes.res.ok || !scoreRes) {
            const message = 'Could not load the portfolio.';
            if (list) list.innerHTML = emptyPanel('Could not load the portfolio', message);
            toast(message);
            return;
        }
        maxSymbols = stockRes.data.max_symbols ?? 10;
        maxWidgetSymbols = stockRes.data.max_widget_symbols ?? 10;
        quotes = stockRes.data.symbols || [];
        const score = scoreRes.scorecard || {};
        holdings = score.positions || [];
        const openBtn = document.getElementById('stocks-add-open');
        if (openBtn) openBtn.disabled = quotes.length >= maxSymbols;
        paintStats(score);
        paintChart(score);
        paintAccounts(accountRes.accounts || []);
        paintTable();
    }

    document.getElementById('stocks-position-open')?.addEventListener('click', () => openPosition(null));
    document.getElementById('stocks-add-open')?.addEventListener('click', openAdd);
    list?.addEventListener('click', (event) => {
        if (event.target.closest('[data-open-position]')) openPosition(null);
    });

    list?.addEventListener('click', async (event) => {
        const item = event.target.closest('[data-fin-act]');
        if (!item) return;
        const row = item.closest('tr');
        if (!row) return;
        const act = item.getAttribute('data-fin-act');
        const symbol = row.getAttribute('data-symbol') || '';
        if (act === 'filter' && window.FinTable) {
            window.FinTable.setQuery(
                document.getElementById('stocks-filter'),
                document.getElementById('stocks-filter-input'),
                document.getElementById('stocks-filter-row'),
                list,
                symbol
            );
            return;
        }
        if (act === 'edit') {
            const position = holdings.find((entry) => String(entry.id) === row.getAttribute('data-position'));
            if (position) openPosition(position);
            return;
        }
        if (act === 'remove-position') {
            const ask = globalThis.AppModal && globalThis.AppModal.confirm;
            const ok = typeof ask === 'function'
                ? await ask({
                    title: 'Remove holding',
                    message: 'Remove this manual holding? This does not place an order.',
                    confirmLabel: 'Remove',
                    danger: true,
                })
                : false;
            if (!ok || !ld) return;
            try {
                await ld.api.post('/invest/positions/' + row.getAttribute('data-position') + '/delete', {});
                toast('Removed.', 'ok');
                await loadList();
            } catch (err) {
                toast(err && err.message ? err.message : 'Could not remove the holding.');
            }
            return;
        }
        if (act === 'pin') {
            const quote = quotes.find((entry) => String(entry.id) === row.getAttribute('data-watch'));
            if (!quote) return;
            const next = !quote.pin_to_dashboard;
            if (next) {
                const pinned = quotes.filter((entry) => entry.pin_to_dashboard).length;
                if (pinned >= maxWidgetSymbols) {
                    toast('Maximum of ' + maxWidgetSymbols + ' symbols can appear on the dashboard.');
                    return;
                }
            }
            const { res, data } = await api('/api/stocks/' + quote.id + '/update', {
                method: 'POST',
                body: JSON.stringify({ pin_to_dashboard: next }),
            });
            if (!res.ok) {
                toast(data.error || 'Could not update dashboard display.');
                return;
            }
            loadList();
            return;
        }
        if (act === 'remove-symbol') {
            const watchId = row.getAttribute('data-watch');
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
            const { res, data } = await api('/api/stocks/' + watchId + '/delete', { method: 'POST', body: '{}' });
            if (!res.ok) {
                toast(data.error || 'Could not remove symbol.');
                return;
            }
            loadList();
        }
    });

    document.getElementById('stocks-position-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!ld) return;
        const data = new FormData(event.target);
        clearFieldError('stocks-position-error');
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
            await ld.api.post('/invest/positions', body);
            closeModal(positionModal);
            toast('Saved.', 'ok');
            await loadList();
        } catch (err) {
            showFieldError('stocks-position-error', err && err.message ? err.message : 'Could not save the holding.');
        }
    });

    document.getElementById('stocks-add-form')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const symbol = String(new FormData(event.target).get('symbol') || '').trim();
        if (!symbol) return;
        clearFieldError('stocks-add-error');
        const { res, data } = await api('/api/stocks/create', {
            method: 'POST',
            body: JSON.stringify({ symbol: symbol }),
        });
        if (!res.ok) {
            showFieldError('stocks-add-error', data.error || 'Could not add symbol.');
            return;
        }
        event.target.reset();
        closeModal(addModal);
        loadList();
    });

    loadList();
})();
