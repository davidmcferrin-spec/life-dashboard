'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const STATUS = {
        upcoming: ['Upcoming', ''],
        due_soon: ['Due soon', 'is-due'],
        paid: ['Paid this cycle', 'is-paid'],
        past_due: ['Past due', 'is-late'],
        skipped: ['Skipped', ''],
    };
    const FLAGS = {
        catch_up: 'More than one cycle unpaid. The cushion counts the latest one.',
        closed_account: 'Pay-from account is closed',
        over_limit: 'Account over limit',
        default_notice: 'Default notice',
        cancelled_charging: 'Cancelled sub charged again',
    };

    let accounts = [];
    let debts = [];
    let bills = [];
    let envelopes = [];
    let pressure = {};
    let proposal = null;
    const billById = new Map();
    const envelopeById = new Map();

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

    function toast(message, tone) {
        if (window.DashboardToast) {
            window.DashboardToast.show(message, tone || 'ok');
            return;
        }
        alert(message);
    }

    function showError(id, err) {
        const message = err && err.message ? err.message : 'Could not save.';
        const el = document.getElementById(id);
        if (!el) {
            toast(message, 'warn');
            return;
        }
        el.textContent = message;
        el.classList.remove('hidden');
    }

    function clearError(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = '';
        el.classList.add('hidden');
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

    function num(value) {
        if (value === null || value === undefined || value === '') return null;
        const n = Number(value);
        return Number.isFinite(n) ? n : null;
    }

    function field(form, name) {
        const el = form.elements[name];
        if (!el) return null;
        if (el.type === 'checkbox') return el.checked;
        const text = String(el.value || '').trim();
        return text === '' ? null : text;
    }

    function pill(status) {
        const pair = STATUS[status] || [status || 'Upcoming', ''];
        return '<span class="fin-pill ' + pair[1] + '">' + esc(pair[0]) + '</span>';
    }

    function flagText(flags, autopay) {
        return (flags || []).filter((flag) => flag !== 'past_due').map((flag) => {
            if (flag === 'closed_account' && autopay) return 'Autopay is set on a closed account';
            return FLAGS[flag] || flag;
        });
    }

    function payFrom(bill) {
        return bill.pay_from_name || bill.pay_from_label || 'No pay-from account';
    }

    function syncBillFields(form) {
        const cadence = field(form, 'cadence') || 'monthly';
        const dueDay = form.querySelector('[data-bill-field="due_day"]');
        const dueMonth = form.querySelector('[data-bill-field="due_month"]');
        const anchor = form.querySelector('[data-bill-field="anchor_on"]');
        if (dueDay) dueDay.hidden = cadence === 'biweekly';
        if (dueMonth) dueMonth.hidden = cadence !== 'yearly';
        if (anchor) anchor.hidden = cadence !== 'biweekly';
    }

    function fillSelect(select, rows, selected, blank) {
        if (!select) return;
        const options = ['<option value="">' + esc(blank) + '</option>'];
        rows.forEach((row) => {
            options.push('<option value="' + esc(row.id) + '">' + esc(row.name) + '</option>');
        });
        if (selected && !rows.some((row) => String(row.id) === String(selected))) {
            options.push('<option value="' + esc(selected) + '">Closed account</option>');
        }
        select.innerHTML = options.join('');
        select.value = selected ? String(selected) : '';
    }

    function renderPressure() {
        const root = document.getElementById('money-pressure');
        if (!root) return;
        const p = pressure || {};
        if (!p.next_payday && !(p.bills || []).length) {
            root.innerHTML = emptyPanel(
                'No payday yet',
                'Set the next payday to see which bills pull before the check, and what is left over.',
                'Payday settings',
                'type="button" data-open-payday'
            );
            return;
        }
        const unassigned = p.unassigned == null
            ? '<p class="fin-note">Set the paycheck amount to see unassigned leftover.</p>'
            : '<p class="fin-unassigned' + (Number(p.unassigned) < 0 ? ' is-down' : '') + '">' + esc(fmt.money(p.unassigned)) + '</p>';
        const stats = '<dl class="fin-pressure">'
            + stat('Next payday', p.next_payday || '—')
            + stat('Pulls', fmt.money(p.pulls_total || 0))
            + stat('Debt minimums', fmt.money(p.debt_total || 0))
            + stat('Living float', p.living_float == null ? '—' : fmt.money(p.living_float))
            + '<div class="is-wide"><dt>Unassigned leftover</dt><dd>' + unassigned + '</dd></div></dl>';
        const billRows = (p.bills || []).map((bill) => {
            const flags = flagText(bill.flags, bill.autopay);
            return '<div class="fin-bill"><span><strong class="fin-bill-name">' + esc(bill.name) + '</strong>'
                + '<small class="fin-bill-meta">' + esc(bill.due_on) + ' · ' + esc(bill.pay_from_name || bill.pay_from_label || 'No pay-from account')
                + (bill.autopay ? ' · Autopay' : '')
                + (bill.debt_name ? ' · Payment for ' + esc(bill.debt_name) : '')
                + (bill.estimated ? ' · Estimated' : '')
                + '</small>'
                + flags.map((flag) => '<small class="fin-flag">' + esc(flag) + '</small>').join('')
                + '</span><span class="mono">' + esc(fmt.money(bill.amount)) + '</span><span class="fin-pills">' + pill(bill.status) + '</span></div>';
        }).join('');
        const debtsHtml = (p.debt_minimums || []).map((debt) =>
            '<div class="fin-bill"><span><strong class="fin-bill-name">' + esc(debt.name) + '</strong>'
            + '<small class="fin-bill-meta">' + esc(debt.due_on) + ' · Minimum not already a bill</small></span>'
            + '<span class="mono">' + esc(fmt.money(debt.amount)) + '</span><span></span></div>'
        ).join('');
        const food = p.food_week;
        const foodHtml = food
            ? '<p class="fin-note">Food this week ' + esc(fmt.money(food.spent)) + ' of ' + esc(fmt.money(food.cap))
                + ' (' + esc(food.from) + ' – ' + esc(food.to) + '). Not an envelope, and not part of the leftover.</p>'
            : '';
        root.innerHTML = stats
            + (billRows ? '<div class="fin-group"><h3>Bills that pull</h3>' + billRows + '</div>' : '<p class="fin-note">No bills pull before the next payday.</p>')
            + (debtsHtml ? '<div class="fin-group"><h3>Debt minimums</h3>' + debtsHtml + '</div>' : '')
            + foodHtml;
    }

    function stat(label, value) {
        return '<div><dt>' + esc(label) + '</dt><dd>' + esc(value) + '</dd></div>';
    }

    function renderBills() {
        const root = document.getElementById('money-bills');
        if (!root) return;
        if (!bills.length) {
            root.innerHTML = emptyPanel(
                'No bills this cycle',
                'Add a recurring bill. Mortgage and Tesla can link to a debt without counting the balance twice.',
                'Add bill',
                'type="button" data-open-bill'
            );
            return;
        }
        const groups = new Map();
        bills.forEach((bill) => {
            const label = payFrom(bill);
            if (!groups.has(label)) groups.set(label, []);
            groups.get(label).push(bill);
        });
        const html = Array.from(groups.keys()).sort().map((label) => {
            const rows = groups.get(label).map((bill) => {
                const flags = flagText(bill.flags, bill.autopay);
                const actions = '<span class="fin-row-actions">'
                    + '<button type="button" class="btn" data-mark-paid="' + esc(bill.id) + '" data-due="' + esc(bill.cycle_due_on || '') + '">Mark paid</button>'
                    + '<button type="button" class="btn" data-skip-bill="' + esc(bill.id) + '" data-due="' + esc(bill.cycle_due_on || '') + '">Skip</button>'
                    + '<button type="button" class="btn" data-edit-bill="' + esc(bill.id) + '">Edit</button></span>';
                return '<div class="fin-bill"><span><strong class="fin-bill-name">' + esc(bill.name) + '</strong>'
                    + '<small class="fin-bill-meta">' + esc(bill.cycle_due_on || 'No date') + ' · ' + esc(bill.cadence)
                    + (bill.autopay ? ' · Autopay' : '')
                    + (bill.estimated ? ' · Estimated' : '')
                    + (bill.uses_statement_min ? ' · Using statement min' : '')
                    + '</small>'
                    + (bill.payment_event ? '<small class="fin-bill-meta">Payment for ' + esc(bill.debt_name || 'a debt') + '. The snowball keeps the balance.</small>' : '')
                    + flags.map((flag) => '<small class="fin-flag">' + esc(flag) + '</small>').join('')
                    + '</span><span><b class="mono">' + esc(fmt.money(bill.pull_amount)) + '</b><span class="fin-pills">' + pill(bill.status) + '</span></span>'
                    + actions + '</div>';
            }).join('');
            return '<div class="fin-group"><h3>' + esc(label) + '</h3>' + rows + '</div>';
        }).join('');
        root.innerHTML = html;
    }

    function renderEnvelopes() {
        const root = document.getElementById('money-envelopes');
        const slot = document.getElementById('money-proposal');
        if (slot) {
            if (!proposal) {
                slot.innerHTML = '';
            } else {
                const after = proposal.unassigned_after == null ? 'Paycheck is not set.' : 'Unassigned would be ' + fmt.money(proposal.unassigned_after) + '.';
                slot.innerHTML = '<div class="fin-proposal"><p><strong>Proposal: assign '
                    + esc(fmt.money(proposal.amount)) + ' to ' + esc(proposal.envelope_name) + '</strong></p><p>'
                    + esc(proposal.note) + ' ' + esc(after) + '</p>'
                    + '<button type="button" class="btn" data-dismiss-proposal>Dismiss</button></div>';
            }
        }
        if (!root) return;
        const leftover = pressure.unassigned == null
            ? ''
            : '<p class="fin-note">Unassigned leftover <strong class="mono">' + esc(fmt.money(pressure.unassigned)) + '</strong></p>';
        if (!envelopes.length) {
            root.innerHTML = leftover + emptyPanel(
                'No envelopes yet',
                'A named job for dollars. Link an X Money account when the bucket is real. Weekly food stays a weekly cap.',
                'Add envelope',
                'type="button" data-open-envelope'
            );
            return;
        }
        const rows = envelopes.map((env) => {
            const source = env.funded_source === 'account'
                ? 'Linked ' + (env.account_name || 'account')
                : (env.account_archived ? 'Manual — linked account is closed' : 'Manual');
            const track = env.on_track === true ? 'On track' : (env.on_track === false ? 'Behind' : (env.target_on ? 'Due ' + env.target_on : ''));
            const width = Math.max(0, Math.min(100, Number(env.percent) || 0));
            return '<div class="fin-bill"><span><strong class="fin-bill-name">' + esc(env.name) + '</strong>'
                + '<small class="fin-bill-meta">' + esc(env.job_label) + ' · ' + esc(source)
                + (track ? ' · ' + esc(track) : '') + '</small>'
                + '<span class="fin-progress" role="img" aria-label="' + esc(String(env.percent)) + ' percent"><i style="width:' + width.toFixed(1) + '%"></i></span>'
                + '</span><span class="mono">' + esc(fmt.money(env.funded)) + ' / ' + esc(fmt.money(env.target_amount)) + '</span>'
                + '<span class="fin-row-actions"><button type="button" class="btn" data-propose="' + esc(env.id) + '">Propose</button>'
                + '<button type="button" class="btn" data-edit-envelope="' + esc(env.id) + '">Edit</button></span></div>';
        }).join('');
        root.innerHTML = leftover + '<div class="fin-envelopes">' + rows + '</div>';
    }

    function renderSnowball() {
        const root = document.getElementById('money-snowball');
        if (!root) return;
        if (!debts.length) {
            root.innerHTML = emptyPanel('No debts in the snowball', 'Balances and payoff order stay here, separate from this cycle’s bills.');
            return;
        }
        root.innerHTML = debts.map((debt, index) =>
            '<div class="fin-bill"><span><strong class="fin-bill-name">' + (index + 1) + '. ' + esc(debt.name) + '</strong>'
            + '<small class="fin-bill-meta">' + esc(String(debt.apr_percent)) + '% · min ' + esc(fmt.money(debt.minimum_payment))
            + (debt.target_payoff_on ? ' · target ' + esc(debt.target_payoff_on) : '') + '</small></span>'
            + '<span class="mono">' + esc(fmt.money(debt.balance)) + '</span><span></span></div>'
        ).join('');
    }

    function render() {
        renderPressure();
        renderBills();
        renderEnvelopes();
        renderSnowball();
    }

    async function load() {
        const [billRes, pressureRes, envelopeRes, accountRes, debtRes] = await Promise.all([
            api.get('/money/bills'),
            api.get('/money/payday-pressure'),
            api.get('/money/envelopes'),
            api.get('/money/accounts'),
            api.get('/money/debts'),
        ]);
        bills = billRes.bills || [];
        pressure = pressureRes.payday_pressure || {};
        envelopes = envelopeRes.envelopes || [];
        accounts = accountRes.accounts || [];
        debts = debtRes.debts || [];
        billById.clear();
        bills.forEach((bill) => billById.set(String(bill.id), bill));
        envelopeById.clear();
        envelopes.forEach((env) => envelopeById.set(String(env.id), env));
        render();
    }

    function resetForm(form) {
        form.reset();
        if (form.elements.id) form.elements.id.value = '';
    }

    async function openBill(id) {
        const modal = document.getElementById('money-bill-modal');
        const form = document.getElementById('money-bill-form');
        clearError('money-bill-error');
        resetForm(form);
        let bill = null;
        if (id) {
            const full = await api.get('/money/bills/' + id);
            bill = full.bill;
        }
        document.getElementById('money-bill-title').textContent = bill ? 'Edit bill' : 'Add bill';
        document.getElementById('money-bill-archive').hidden = !bill;
        fillSelect(form.elements.pay_from_account_id, accounts, bill && bill.pay_from_account_id, 'No linked account');
        fillSelect(form.elements.debt_id, debts, bill && bill.debt_id, 'No linked debt');
        if (bill) {
            form.elements.id.value = bill.id;
            form.elements.name.value = bill.name;
            form.elements.amount.value = bill.amount;
            form.elements.estimated.checked = !!bill.estimated;
            form.elements.cadence.value = bill.cadence;
            form.elements.due_day.value = bill.due_day || '';
            form.elements.due_month.value = bill.due_month || '';
            form.elements.anchor_on.value = bill.anchor_on || '';
            form.elements.pay_from_label.value = bill.pay_from_label || '';
            form.elements.autopay.checked = !!bill.autopay;
            form.elements.statement_min.value = bill.statement_min == null ? '' : bill.statement_min;
            form.elements.default_notice.checked = !!bill.default_notice;
            form.elements.cancelled_charging.checked = !!bill.cancelled_charging;
            form.elements.notes.value = bill.notes || '';
            const history = document.getElementById('money-bill-history');
            const cycles = bill.cycles || [];
            history.textContent = cycles.length
                ? 'Recent cycles: ' + cycles.slice(0, 4).map((cycle) => cycle.cycle_due_on + ' ' + cycle.status).join(', ')
                : '';
        } else {
            document.getElementById('money-bill-history').textContent = '';
        }
        syncBillFields(form);
        openModal(modal);
    }

    function openEnvelope(id) {
        const modal = document.getElementById('money-envelope-modal');
        const form = document.getElementById('money-envelope-form');
        clearError('money-envelope-error');
        resetForm(form);
        const env = id ? envelopeById.get(String(id)) : null;
        document.getElementById('money-envelope-title').textContent = env ? 'Edit envelope' : 'Add envelope';
        document.getElementById('money-envelope-archive').hidden = !env;
        fillSelect(form.elements.account_id, accounts, env && env.account_id, 'No linked account');
        if (env) {
            form.elements.id.value = env.id;
            form.elements.name.value = env.name;
            form.elements.job_type.value = env.job_type;
            form.elements.target_amount.value = env.target_amount;
            form.elements.funded_amount.value = env.funded_amount;
            form.elements.target_on.value = env.target_on || '';
            form.elements.notes.value = env.notes || '';
        }
        openModal(modal);
    }

    function openPayday() {
        const form = document.getElementById('money-payday-form');
        clearError('money-payday-error');
        form.reset();
        form.elements.cadence.value = pressure.cadence || 'biweekly';
        form.elements.next_on.value = pressure.next_on || '';
        form.elements.anchor_on.value = pressure.anchor_on || '';
        form.elements.paycheck_amount.value = pressure.paycheck_amount == null ? '' : pressure.paycheck_amount;
        form.elements.living_float.value = pressure.living_float == null ? '' : pressure.living_float;
        openModal(document.getElementById('money-payday-modal'));
    }

    function openPropose(id) {
        const env = envelopeById.get(String(id));
        if (!env) return;
        const form = document.getElementById('money-propose-form');
        clearError('money-propose-error');
        form.reset();
        form.elements.envelope_id.value = env.id;
        document.getElementById('money-propose-name').textContent = env.name + ' · funded ' + fmt.money(env.funded);
        openModal(document.getElementById('money-propose-modal'));
    }

    function billPayload(form) {
        const cadence = field(form, 'cadence');
        return {
            name: field(form, 'name'),
            amount: num(field(form, 'amount')) ?? 0,
            estimated: !!field(form, 'estimated'),
            cadence: cadence,
            due_day: cadence === 'biweekly' ? null : num(field(form, 'due_day')),
            due_month: cadence === 'yearly' ? num(field(form, 'due_month')) : null,
            anchor_on: cadence === 'biweekly' ? field(form, 'anchor_on') : null,
            pay_from_account_id: num(field(form, 'pay_from_account_id')),
            pay_from_label: field(form, 'pay_from_label'),
            autopay: !!field(form, 'autopay'),
            debt_id: num(field(form, 'debt_id')),
            statement_min: num(field(form, 'statement_min')),
            default_notice: !!field(form, 'default_notice'),
            cancelled_charging: !!field(form, 'cancelled_charging'),
            notes: field(form, 'notes'),
        };
    }

    async function mark(id, due, status) {
        const body = { status: status };
        if (due) body.cycle_due_on = due;
        await api.post('/money/bills/' + id + '/paid', body);
        toast(status === 'skipped' ? 'Skipped this cycle. No money was moved.' : 'Marked paid. No money was moved.');
        await load();
    }

    document.addEventListener('click', (event) => {
        const openBillBtn = event.target.closest('[data-open-bill]');
        const editBill = event.target.closest('[data-edit-bill]');
        const openEnv = event.target.closest('[data-open-envelope]');
        const editEnv = event.target.closest('[data-edit-envelope]');
        const payday = event.target.closest('[data-open-payday]');
        const proposeBtn = event.target.closest('[data-propose]');
        const paid = event.target.closest('[data-mark-paid]');
        const skip = event.target.closest('[data-skip-bill]');
        const dismiss = event.target.closest('[data-dismiss-proposal]');
        if (openBillBtn) {
            openBill(null).catch((err) => toast(err.message, 'warn'));
        } else if (editBill) {
            openBill(editBill.getAttribute('data-edit-bill')).catch((err) => toast(err.message, 'warn'));
        } else if (openEnv) {
            openEnvelope(null);
        } else if (editEnv) {
            openEnvelope(editEnv.getAttribute('data-edit-envelope'));
        } else if (payday) {
            openPayday();
        } else if (proposeBtn) {
            openPropose(proposeBtn.getAttribute('data-propose'));
        } else if (paid) {
            mark(paid.getAttribute('data-mark-paid'), paid.getAttribute('data-due'), 'paid').catch((err) => toast(err.message, 'warn'));
        } else if (skip) {
            mark(skip.getAttribute('data-skip-bill'), skip.getAttribute('data-due'), 'skipped').catch((err) => toast(err.message, 'warn'));
        } else if (dismiss) {
            proposal = null;
            renderEnvelopes();
        }
    });

    const billForm = document.getElementById('money-bill-form');
    if (billForm) {
        billForm.elements.cadence.addEventListener('change', () => syncBillFields(billForm));
        billForm.addEventListener('submit', (event) => {
            event.preventDefault();
            clearError('money-bill-error');
            const id = field(billForm, 'id');
            const path = id ? '/money/bills/' + id : '/money/bills';
            api.post(path, billPayload(billForm)).then(() => {
                closeModal(document.getElementById('money-bill-modal'));
                toast('Bill saved. No money was moved.');
                return load();
            }).catch((err) => showError('money-bill-error', err));
        });
        document.getElementById('money-bill-archive').addEventListener('click', () => {
            const id = field(billForm, 'id');
            if (!id || !window.confirm('Archive this bill? It leaves the list. No money moves.')) return;
            api.post('/money/bills/' + id + '/archive', {}).then(() => {
                closeModal(document.getElementById('money-bill-modal'));
                return load();
            }).catch((err) => showError('money-bill-error', err));
        });
    }

    const envelopeForm = document.getElementById('money-envelope-form');
    if (envelopeForm) {
        envelopeForm.addEventListener('submit', (event) => {
            event.preventDefault();
            clearError('money-envelope-error');
            const id = field(envelopeForm, 'id');
            const body = {
                name: field(envelopeForm, 'name'),
                job_type: field(envelopeForm, 'job_type'),
                target_amount: num(field(envelopeForm, 'target_amount')) ?? 0,
                funded_amount: num(field(envelopeForm, 'funded_amount')) ?? 0,
                target_on: field(envelopeForm, 'target_on'),
                account_id: num(field(envelopeForm, 'account_id')),
                notes: field(envelopeForm, 'notes'),
            };
            const path = id ? '/money/envelopes/' + id : '/money/envelopes';
            api.post(path, body).then(() => {
                closeModal(document.getElementById('money-envelope-modal'));
                toast('Envelope saved. No money was moved.');
                return load();
            }).catch((err) => showError('money-envelope-error', err));
        });
        document.getElementById('money-envelope-archive').addEventListener('click', () => {
            const id = field(envelopeForm, 'id');
            if (!id || !window.confirm('Archive this envelope? No money moves.')) return;
            api.post('/money/envelopes/' + id + '/archive', {}).then(() => {
                closeModal(document.getElementById('money-envelope-modal'));
                return load();
            }).catch((err) => showError('money-envelope-error', err));
        });
    }

    const paydayForm = document.getElementById('money-payday-form');
    if (paydayForm) {
        paydayForm.addEventListener('submit', (event) => {
            event.preventDefault();
            clearError('money-payday-error');
            api.post('/money/payday', {
                cadence: field(paydayForm, 'cadence'),
                next_on: field(paydayForm, 'next_on'),
                anchor_on: field(paydayForm, 'anchor_on'),
                paycheck_amount: num(field(paydayForm, 'paycheck_amount')),
                living_float: num(field(paydayForm, 'living_float')),
            }).then(() => {
                closeModal(document.getElementById('money-payday-modal'));
                toast('Payday saved. No money was moved.');
                return load();
            }).catch((err) => showError('money-payday-error', err));
        });
    }

    const proposeForm = document.getElementById('money-propose-form');
    if (proposeForm) {
        proposeForm.addEventListener('submit', (event) => {
            event.preventDefault();
            clearError('money-propose-error');
            api.post('/money/envelopes/propose', {
                envelope_id: num(field(proposeForm, 'envelope_id')),
                amount: num(field(proposeForm, 'amount')),
            }).then((result) => {
                proposal = result.proposal;
                closeModal(document.getElementById('money-propose-modal'));
                toast('Proposal only. No money was moved.');
                return load();
            }).catch((err) => showError('money-propose-error', err));
        });
    }

    ['money-bill-modal', 'money-envelope-modal', 'money-payday-modal', 'money-propose-modal'].forEach((id) => {
        bindModal(document.getElementById(id));
    });

    if (document.getElementById('money-bills')) {
        load().catch((err) => {
            const root = document.getElementById('money-pressure');
            if (root) root.innerHTML = '<p class="fin-note">' + esc(err.message || 'Could not load bills.') + '</p>';
        });
    }
})();
