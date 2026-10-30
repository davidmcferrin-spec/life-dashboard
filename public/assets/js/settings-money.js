'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const addModal = document.getElementById('money-account-modal');
    const editModal = document.getElementById('money-edit-modal');
    const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

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
            return '<p class="fin-note">' + esc(title) + '</p>';
        }
        const opts = { title: title, body: body };
        if (actionLabel) {
            opts.actionLabel = actionLabel;
            opts.actionAttrs = 'type="button" data-open-account';
        }
        return window.DashboardEmpty.html(opts);
    }

    function walletIcon() {
        const node = document.getElementById('fin-icon-wallet');
        return node ? node.innerHTML.trim() : '';
    }

    function monthStart(iso) {
        return String(iso).slice(0, 8) + '01';
    }

    function addMonths(iso, delta) {
        const parts = String(iso).split('-').map(Number);
        const dt = new Date(Date.UTC(parts[0], parts[1] - 1 + delta, 1));
        const month = String(dt.getUTCMonth() + 1).padStart(2, '0');
        return dt.getUTCFullYear() + '-' + month + '-01';
    }

    function monthLabel(iso) {
        const month = Number(String(iso).slice(5, 7));
        return MONTHS[month - 1] || String(iso);
    }

    function lastMonths(end, count) {
        const first = addMonths(monthStart(end), -(count - 1));
        const out = [];
        for (let i = 0; i < count; i += 1) {
            const start = addMonths(first, i);
            out.push({ key: start.slice(0, 7), label: monthLabel(start), income: 0, spend: 0 });
        }
        return out;
    }

    function deltaPct(current, previous) {
        if (!Number.isFinite(current) || !Number.isFinite(previous) || previous === 0) return null;
        return ((current - previous) / Math.abs(previous)) * 100;
    }

    function rate(income, spend) {
        if (!(income > 0)) return null;
        return ((income - spend) / income) * 100;
    }

    // Last 4 only. A full account number never reaches the markup.
    function maskLast4(last4) {
        const digits = String(last4 || '').replace(/\D/g, '').slice(-4);
        if (digits.length !== 4) return '';
        return '•••• ' + digits;
    }

    function statCard(label, value, opts) {
        const options = opts || {};
        const tone = options.cls ? ' ' + options.cls : '';
        let delta = '';
        if (options.pct != null && Number.isFinite(options.pct)) {
            const deltaTone = options.pct < 0 ? 'is-down' : 'is-up';
            const sign = options.pct > 0 ? '+' : '';
            delta = '<small class="fin-delta ' + deltaTone + '">' + esc(sign + options.pct.toFixed(1) + '%')
                + ' <span>' + esc(options.caption || 'vs last month') + '</span></small>';
        }
        return '<article class="fin-stat"><span class="fin-stat-label">' + esc(label) + '</span><strong class="fin-stat-value' + tone + '">'
            + esc(value) + '</strong>' + delta + '</article>';
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

    function paint(accounts, transactions, summary, end) {
        const rows = transactions || [];
        const capped = rows.length >= 500;
        let earliest = '';
        rows.forEach((row) => {
            const date = String(row.date || '');
            if (date && (!earliest || date < earliest)) earliest = date;
        });
        let months = lastMonths(end, 6);
        if (capped && earliest) {
            const earliestKey = earliest.slice(0, 7);
            months = months.filter((month) => month.key >= earliestKey);
        }
        const byMonth = {};
        months.forEach((month) => {
            byMonth[month.key] = month;
        });
        rows.forEach((row) => {
            const bucket = byMonth[String(row.date || '').slice(0, 7)];
            if (!bucket) return;
            const amount = Number(row.amount);
            if (!Number.isFinite(amount)) return;
            if (amount >= 0) bucket.income += amount;
            else bucket.spend += -amount;
        });

        const current = months[months.length - 1] || { income: 0, spend: 0 };
        const previous = months.length > 1 ? months[months.length - 2] : null;
        const currentRate = rate(current.income, current.spend);
        const previousRate = previous ? rate(previous.income, previous.spend) : null;
        const snaps = (summary.snapshots || []).slice().sort((a, b) => String(a.date).localeCompare(String(b.date)));
        const thisKey = monthStart(end).slice(0, 7);
        const earlierSnap = snaps.filter((snap) => String(snap.date).slice(0, 7) < thisKey).pop();
        const latestSnap = snaps.length ? snaps[snaps.length - 1] : null;
        let balancePct = null;
        if (earlierSnap && latestSnap && String(latestSnap.date).slice(0, 7) >= thisKey) {
            balancePct = deltaPct(Number(latestSnap.net), Number(earlierSnap.net));
        }

        const stats = document.getElementById('money-stats');
        if (stats) {
            const savings = currentRate == null ? '—' : currentRate.toFixed(1) + '%';
            stats.innerHTML = [
                statCard('Total balance', fmt.money(summary.net_worth), balancePct == null ? {} : { pct: balancePct, caption: 'vs prior snapshot' }),
                statCard('Monthly income', fmt.money(current.income), previous ? { pct: deltaPct(current.income, previous.income) } : {}),
                statCard('Monthly spend', fmt.money(current.spend), previous ? { pct: deltaPct(current.spend, previous.spend) } : {}),
                statCard('Savings rate', savings, currentRate != null && previousRate != null ? { pct: deltaPct(currentRate, previousRate) } : {}),
            ].join('');
        }

        const chart = document.getElementById('money-chart');
        if (chart) {
            const points = months.map((month) => ({ label: month.label, value: month.income - month.spend }));
            const any = points.some((point) => point.value !== 0);
            chart.innerHTML = any
                ? lineChart(points, 'Cash flow for the last six months')
                : emptyPanel('No cash flow yet', 'Import a CSV to see the last six months.');
        }

        const icon = walletIcon();
        const accountRows = (accounts.accounts || []).map((account) => {
            const mask = maskLast4(account.last4);
            const meta = [account.kind, mask].filter(Boolean).join(' · ');
            const mark = icon ? '<span class="fin-icon" aria-hidden="true">' + icon + '</span>' : '';
            return '<div class="fin-account">' + mark
                + '<span><strong class="fin-account-name">' + esc(account.name) + '</strong><small class="fin-account-meta">' + esc(meta) + '</small></span>'
                + '<span class="fin-account-side"><b class="fin-account-balance">' + esc(fmt.money(account.balance)) + '</b>'
                + '<button type="button" class="btn" data-edit-account="' + esc(account.id) + '" data-balance="' + esc(account.balance) + '">Edit</button></span></div>';
        }).join('');
        document.getElementById('money-accounts').innerHTML = accountRows || emptyPanel(
            'No accounts',
            'Add an account to keep a balance.',
            'Add account'
        );

        const recent = rows.slice(0, 12);
        const txBody = recent.map((row) => {
            const amount = Number(row.amount);
            const tone = amount < 0 ? 'is-down' : (amount > 0 ? 'is-up' : '');
            // Payee text is the stored label. "Creator subscription" stays as written.
            const payee = row.payee ? String(row.payee) : '—';
            const category = row.category ? String(row.category) : '—';
            const menuItems = [];
            if (row.payee) menuItems.push({ act: 'merchant', label: 'Filter merchant' });
            if (row.category) menuItems.push({ act: 'category', label: 'Filter category' });
            const menu = window.FinTable ? window.FinTable.rowMenu(menuItems) : '';
            return '<tr data-filter="' + esc([row.date, payee, category].join(' ')) + '" data-payee="' + esc(payee) + '" data-category="' + esc(category) + '"><td>'
                + esc(row.date) + '</td><td>' + esc(payee) + '</td><td>' + esc(category) + '</td>'
                + '<td class="fin-num ' + tone + '">' + esc(fmt.money(amount)) + '</td><td class="fin-actions">' + menu + '</td></tr>';
        }).join('');
        const tx = document.getElementById('money-tx');
        tx.innerHTML = txBody
            ? '<div class="fin-table-wrap"><table class="fin-table"><thead><tr><th>Date</th><th>Merchant</th><th>Category</th><th class="fin-num">Amount</th><th></th></tr></thead><tbody>'
                + txBody + '</tbody></table></div>'
            : emptyPanel('No recent transactions', 'Imported rows show up here. A negative amount is money out.');
        if (window.FinTable) {
            window.FinTable.bindHost(tx);
            window.FinTable.bindFilter(
                document.getElementById('money-filter'),
                document.getElementById('money-filter-input'),
                document.getElementById('money-filter-row'),
                tx
            );
        }
    }

    async function load() {
        const probe = await api.get('/money/summary');
        const end = String(probe.summary && probe.summary.to ? probe.summary.to : '');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(end)) {
            throw new Error('Could not load accounts.');
        }
        const start = addMonths(monthStart(end), -5);
        const [accounts, tx, summary] = await Promise.all([
            api.get('/money/accounts'),
            api.get('/money/transactions?from=' + encodeURIComponent(start) + '&limit=500'),
            api.get('/money/summary?from=' + encodeURIComponent(start) + '&to=' + encodeURIComponent(end)),
        ]);
        paint(accounts, tx.transactions || [], summary.summary || {}, end);
    }

    document.getElementById('money-account-open').addEventListener('click', openAdd);

    document.getElementById('money-tx').addEventListener('click', (event) => {
        const item = event.target.closest('[data-fin-act]');
        if (!item || !window.FinTable) return;
        const row = item.closest('tr');
        if (!row) return;
        const query = item.getAttribute('data-fin-act') === 'category'
            ? (row.getAttribute('data-category') || '')
            : (row.getAttribute('data-payee') || '');
        window.FinTable.setQuery(
            document.getElementById('money-filter'),
            document.getElementById('money-filter-input'),
            document.getElementById('money-filter-row'),
            document.getElementById('money-tx'),
            query
        );
    });

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
            const body = {
                name: String(data.get('name')),
                kind: String(data.get('kind')),
                balance: Number(data.get('balance') || 0),
            };
            const last4 = String(data.get('last4') || '').trim();
            if (last4 !== '') body.last4 = last4;
            await api.post('/money/accounts', body);
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
        const chart = document.getElementById('money-chart');
        if (chart) chart.innerHTML = emptyPanel('Could not load cash flow', message);
        const tx = document.getElementById('money-tx');
        if (tx) tx.innerHTML = '';
        toast(message);
    });
})();
