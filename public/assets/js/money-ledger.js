'use strict';

(function () {
    const root = document.getElementById('money-ledger');
    if (!root || !window.LD) return;
    const { api, esc, fmt } = window.LD;

    function showError(id, err) {
        const el = document.getElementById(id);
        const message = err && err.message ? err.message : 'Could not save.';
        if (!el) return;
        el.textContent = message;
        el.classList.remove('hidden');
    }

    function clearError(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = '';
        el.classList.add('hidden');
    }

    function moneyLine(label, value) {
        return '<span class="fin-account fin-account-plain"><span><strong class="fin-account-name">' + esc(label) + '</strong></span><b>' + esc(value) + '</b></span>';
    }

    function paint(summary) {
        const body = document.getElementById('money-ledger-body');
        if (!body || !summary) return;
        const fresh = summary.freshness || {};
        const food = summary.food_week || {};
        const util = summary.utilization && summary.utilization.overall ? summary.utilization.overall : {};
        const pots = summary.benefit_pots || [];
        const inflows = summary.expected_inflows || [];
        const payments = summary.upcoming_payments || [];
        const stale = fresh.stale ? ' · some balances are stale' : '';
        const utilText = util.utilization_percent == null ? '—' : util.utilization_percent + '%';
        const foodText = food.total
            ? fmt.money(food.total.spent) + ' of ' + fmt.money(food.total.target)
            : '—';
        const grocery = food.groceries ? fmt.money(food.groceries.spent) + ' / ' + fmt.money(food.groceries.target) : '—';
        const eating = food.eating_out ? fmt.money(food.eating_out.spent) + ' / ' + fmt.money(food.eating_out.target) : '—';
        const avg = food.two_week_average ? fmt.money(food.two_week_average.total) : '—';
        const payRows = payments.map((row) =>
            '<div class="fin-bill"><span><strong>' + esc(row.status) + '</strong><small class="fin-bill-meta">'
            + esc(row.due_on || 'no date') + '</small></span><span class="mono">' + esc(fmt.money(row.amount)) + '</span></div>'
        ).join('');
        body.innerHTML = [
            moneyLine('Net worth', fmt.money(summary.net_worth)),
            moneyLine('Cash', fmt.money(summary.cash)),
            moneyLine('Debts', fmt.money(summary.debts && summary.debts.total)),
            moneyLine('Utilization', utilText),
            moneyLine('Food this week', foodText),
            '<p class="fin-note">Groceries ' + esc(grocery) + ' · Eating out ' + esc(eating) + ' · Two-week average ' + esc(avg)
                + esc(stale) + '</p>',
            '<p class="fin-note">' + pots.length + ' benefit pot' + (pots.length === 1 ? '' : 's') + ' · '
                + inflows.length + ' expected inflow' + (inflows.length === 1 ? '' : 's') + '</p>',
            payRows ? '<div class="fin-bills">' + payRows + '</div>' : '<p class="fin-note">No scheduled or pending payments.</p>',
        ].join('');

        const options = document.getElementById('money-options');
        if (options && fresh.threshold_hours) {
            options.elements.stale_hours.value = fresh.threshold_hours;
        }
        if (options && food.groceries) options.elements.groceries_weekly.value = food.groceries.target;
        if (options && food.eating_out) options.elements.eating_out_weekly.value = food.eating_out.target;

        const potList = document.getElementById('money-pots');
        if (potList) {
            potList.innerHTML = pots.length ? pots.map((pot) => {
                const lose = pot.use_it_or_lose_it == null ? '' : ' · use or lose ' + fmt.money(pot.use_it_or_lose_it);
                const when = pot.deadline ? ' · by ' + pot.deadline : '';
                const stalePot = pot.stale ? ' · stale' : '';
                return '<div class="fin-bill"><span><strong>' + esc(String(pot.kind).toUpperCase()) + ' ' + esc(pot.name) + '</strong>'
                    + '<small class="fin-bill-meta' + (pot.stale ? ' fin-stale' : '') + '">' + esc(String(pot.plan_year)) + esc(when) + esc(lose) + esc(stalePot) + '</small></span>'
                    + '<span class="mono">' + esc(fmt.money(pot.balance)) + '</span></div>';
            }).join('') : '<p class="fin-note">No benefit pots yet.</p>';
        }

        const inflowList = document.getElementById('money-inflows');
        if (inflowList) {
            const rows = (summary.all_inflows || inflows);
            inflowList.innerHTML = rows.length ? rows.map((row) =>
                '<div class="fin-bill" data-inflow="' + esc(row.id) + '"><span><strong>' + esc(row.source) + '</strong>'
                + '<small class="fin-bill-meta">' + esc(row.kind) + ' · ' + esc(row.status) + ' · ' + esc(row.expected_on) + '</small></span>'
                + '<span class="mono">' + esc(fmt.money(row.amount)) + '</span>'
                + (row.status === 'expected' ? '<button type="button" class="btn" data-receive="' + esc(row.id) + '">Mark received</button>' : '')
                + '</div>'
            ).join('') : '<p class="fin-note">Nothing expected.</p>';
        }
    }

    async function load() {
        const [summaryRes, inflowRes] = await Promise.all([
            api.get('/money/overview'),
            api.get('/money/inflows'),
        ]);
        const summary = summaryRes.summary || {};
        summary.all_inflows = inflowRes.inflows || [];
        paint(summary);
    }

    document.getElementById('money-options').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError('money-options-error');
        const data = new FormData(event.target);
        try {
            await api.post('/money/options', {
                stale_hours: Number(data.get('stale_hours')),
                groceries_weekly: Number(data.get('groceries_weekly')),
                eating_out_weekly: Number(data.get('eating_out_weekly')),
            });
            await load();
        } catch (err) {
            showError('money-options-error', err);
        }
    });

    document.getElementById('money-pot-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError('money-pot-error');
        const data = new FormData(event.target);
        const body = {
            kind: String(data.get('kind')),
            name: String(data.get('name') || ''),
            balance: Number(data.get('balance') || 0),
            plan_year: Number(data.get('plan_year')),
        };
        const deadline = String(data.get('deadline') || '');
        const lose = String(data.get('use_it_or_lose_it') || '');
        if (deadline) body.deadline = deadline;
        if (lose !== '') body.use_it_or_lose_it = Number(lose);
        try {
            await api.post('/money/benefit-pots', body);
            event.target.reset();
            await load();
        } catch (err) {
            showError('money-pot-error', err);
        }
    });

    document.getElementById('money-inflow-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        clearError('money-inflow-error');
        const data = new FormData(event.target);
        try {
            await api.post('/money/inflows', {
                kind: String(data.get('kind')),
                source: String(data.get('source') || ''),
                amount: Number(data.get('amount')),
                expected_on: String(data.get('expected_on') || ''),
            });
            event.target.reset();
            await load();
        } catch (err) {
            showError('money-inflow-error', err);
        }
    });

    document.getElementById('money-inflows').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-receive]');
        if (!button) return;
        button.disabled = true;
        try {
            await api.post('/money/inflows/' + button.getAttribute('data-receive') + '/received', {});
            await load();
        } catch (err) {
            showError('money-inflow-error', err);
            button.disabled = false;
        }
    });

    document.addEventListener('money-ledger-reload', () => {
        load().catch(() => {});
    });

    load().catch((err) => {
        const body = document.getElementById('money-ledger-body');
        if (body) body.textContent = err && err.message ? err.message : 'Could not load the summary.';
    });
})();
