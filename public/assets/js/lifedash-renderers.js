/**
 * lifedash-renderers.js — renderers for native widget types.
 *
 * Each renderer receives ctx:
 *   ctx.body     the widget's scrollable body element (render target)
 *   ctx.tools    the header tools element (links/buttons next to the title)
 *   ctx.widget   the widget object from /api/v1/widgets
 *   ctx.data     payload from /api/v1/widgets/{id}/data
 *   ctx.refresh  () => Promise — reload this widget's data and re-render
 *
 * Renderers are pure DOM builders: all values go through LD.esc(); charts are
 * hand-rolled SVG sized to the body so they re-render cleanly on resize.
 */
'use strict';

(function () {
    const { api, esc, fmt, status } = window.LD;

    const PALETTE = ['var(--accent)', 'var(--ok)', 'var(--warn)', '#a855f7'];
    const AGG_LABEL = { last: 'Latest', avg: 'Average', sum: 'Total', min: 'Min', max: 'Max', count: 'Count' };
    const PERIOD_LABEL = { today: 'today', '7d': 'last 7 days', '30d': 'last 30 days', '90d': 'last 90 days', '365d': 'last year', all: 'all time' };

    function errorBox(ctx, message) {
        ctx.body.innerHTML = `<p class="ld-error">${esc(message)}</p>`;
    }

    function tools(ctx, html) {
        if (ctx.tools) ctx.tools.innerHTML = html;
    }

    async function guarded(btn, fn) {
        if (btn) btn.setAttribute('aria-busy', 'true');
        try {
            await fn();
        } catch (e) {
            status(e.message || 'Something went wrong.', 'error');
        } finally {
            if (btn) btn.removeAttribute('aria-busy');
        }
    }

    // -----------------------------------------------------------------
    // Habits
    // -----------------------------------------------------------------
    function habits(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/trackers" class="nav-link">Manage</a>');

        const rows = d.habits.map((h) => {
            const strip = h.history.map((done, i) => {
                const day = d.days[i];
                const isToday = day === d.today;
                return `<button type="button" class="ld-dot${done ? ' is-done' : ''}${isToday ? ' is-today' : ''}"
                    data-habit="${h.id}" data-date="${esc(day)}"
                    title="${esc(fmt.shortDate(day))}: ${done ? 'done' : 'not done'}"
                    aria-label="${esc(h.name)} ${esc(fmt.shortDate(day))} ${done ? 'done' : 'not done'}"></button>`;
            }).join('');
            const target = h.target_per_week ? `${h.week_count}/${h.target_per_week} this week` : `${h.week_count} this week`;
            const streak = h.streak > 0 ? `<span class="ld-hot">🔥${h.streak}</span> · ` : '';
            return `
                <div class="ld-habit">
                    <button type="button" class="ld-check${h.done_today ? ' is-done' : ''}" data-habit="${h.id}"
                        aria-pressed="${h.done_today}" aria-label="${esc(h.name)} today">✓</button>
                    <div class="min-w-0">
                        <div class="ld-habit-name">${h.emoji ? esc(h.emoji) + ' ' : ''}${esc(h.name)}</div>
                        <div class="ld-habit-meta">${streak}${esc(target)}</div>
                    </div>
                    <div class="ld-strip">${strip}</div>
                </div>`;
        }).join('');

        const quickAdd = d.all_habits ? `
            <form class="ld-mood-note" data-quick-add>
                <input class="input" name="name" maxlength="80" placeholder="New habit…" aria-label="New habit name" autocomplete="off">
                <button type="submit" class="btn text-xs">Add</button>
            </form>` : '';

        ctx.body.innerHTML = (rows || '<p class="empty-state mono text-xs py-2">No habits yet — add your first one below.</p>') + quickAdd;

        ctx.body.querySelectorAll('.ld-check, button.ld-dot').forEach((btn) => {
            btn.addEventListener('click', () => guarded(btn, async () => {
                const body = btn.dataset.date ? { date: btn.dataset.date } : {};
                btn.classList.toggle('is-done'); // optimistic
                await api.post(`/habits/${btn.dataset.habit}/toggle`, body);
                await ctx.refresh();
            }));
        });

        const form = ctx.body.querySelector('[data-quick-add]');
        form?.addEventListener('submit', (e) => {
            e.preventDefault();
            const name = form.elements.name.value.trim();
            if (!name) return;
            guarded(form.querySelector('button'), async () => {
                await api.post('/metrics', { name, kind: 'habit' });
                form.reset();
                await ctx.refresh();
            });
        });
    }

    // -----------------------------------------------------------------
    // Mood
    // -----------------------------------------------------------------
    function mood(ctx) {
        const d = ctx.data;
        const m = d.metric;
        const cfg = m.config || {};
        const min = cfg.min ?? 1;
        const max = cfg.max ?? 5;
        tools(ctx, '<a href="/settings/trackers" class="nav-link">Manage</a>');

        const current = d.latest ? Math.round(d.latest.value) : null;
        let buttons = '';
        for (let v = min; v <= max; v++) {
            const label = (cfg.labels && cfg.labels[String(v)]) || String(v);
            const face = fmt.face(m, v);
            buttons += `<button type="button" class="ld-mood-btn${v === current ? ' is-current' : ''}" data-value="${v}"
                title="${esc(label)}" aria-label="Log ${esc(label)}">${face ? esc(face) : esc(v)}</button>`;
        }

        const todayLine = d.latest
            ? `Today ${esc(fmt.face(m, d.latest.value) || fmt.number(d.latest.value, 0))} at ${esc(fmt.time(d.latest.ts))}${d.today_count > 1 ? ` (+${d.today_count - 1})` : ''}`
            : 'Not logged today';
        const avg = d.average !== null ? `${d.buckets.length}d avg ${fmt.number(d.average, 1)}` : '';

        const notes = (d.notes || []).map((n) =>
            `<li><span class="ld-meta">${esc(fmt.monthDay(n.date))}</span>${esc(fmt.face(m, n.value) || '')} ${esc(n.note)}</li>`
        ).join('');

        ctx.body.innerHTML = `
            <div class="ld-mood-scale">${buttons}</div>
            <div class="ld-mood-foot"><span>${todayLine}</span><span>${esc(avg)}</span></div>
            <div data-spark class="ld-mood-spark"></div>
            <details class="ld-mood-note">
                <summary>Note</summary>
                <form data-note>
                    <input class="input" name="note" maxlength="500" placeholder="Optional note, then tap a face" aria-label="Mood note" autocomplete="off">
                </form>
                ${notes ? `<ul class="ld-notes">${notes}</ul>` : ''}
            </details>`;

        const noteInput = ctx.body.querySelector('input[name=note]');
        ctx.body.querySelector('[data-note]').addEventListener('submit', (e) => e.preventDefault());
        ctx.body.querySelectorAll('.ld-mood-btn').forEach((btn) => {
            btn.addEventListener('click', () => guarded(btn, async () => {
                const payload = { value: Number(btn.dataset.value), source: 'widget' };
                const note = noteInput.value.trim();
                if (note) payload.note = note;
                await api.post(`/metrics/${m.id}/datapoints`, payload);
                noteInput.value = '';
                status('Mood logged');
                await ctx.refresh();
            }));
        });

        const byDay = new Map((d.series || []).map((p) => [p.bucket, p.value]));
        drawLineChart(ctx.body.querySelector('[data-spark]'), d.buckets, [{
            color: PALETTE[0],
            name: m.name,
            values: d.buckets.map((b) => (byDay.has(b) ? byDay.get(b) : null)),
        }], { min, max, compact: true, format: (v) => fmt.number(v, 1) });
    }

    // -----------------------------------------------------------------
    // Stat
    // -----------------------------------------------------------------
    function stat(ctx) {
        const d = ctx.data;
        if (d.error) return errorBox(ctx, d.error);
        tools(ctx, '');

        let delta = '';
        if (d.value !== null && d.previous !== null && d.previous !== undefined) {
            const diff = d.value - d.previous;
            if (Math.abs(diff) > 1e-9) {
                const goodUp = (d.metric.config || {}).goal_direction !== 'down';
                const good = diff > 0 ? goodUp : !goodUp;
                delta = `<span class="${good ? 'ld-delta-up' : 'ld-delta-down'}">${diff > 0 ? '▲' : '▼'} ${esc(fmt.value(d.metric, Math.abs(diff), d.decimals))}</span> vs prior`;
            }
        }
        ctx.body.innerHTML = `
            <div class="ld-center">
                <div class="ld-big">${esc(d.agg === 'count' ? fmt.number(d.value, 0) : fmt.value(d.metric, d.value, d.decimals))}</div>
                <div class="ld-sub">${esc(AGG_LABEL[d.agg] || d.agg)} · ${esc(PERIOD_LABEL[d.period] || d.period)}</div>
                ${delta ? `<div class="ld-sub">${delta}</div>` : ''}
            </div>`;
    }

    // -----------------------------------------------------------------
    // Chart
    // -----------------------------------------------------------------
    function chart(ctx) {
        const d = ctx.data;
        if (d.error) return errorBox(ctx, d.error);
        tools(ctx, '');

        const series = d.series.map((s, i) => {
            const map = new Map(s.points.map((p) => [p.bucket, p.value]));
            return {
                color: PALETTE[i % PALETTE.length],
                name: s.metric.name,
                metric: s.metric,
                values: d.buckets.map((b) => (map.has(b) ? map.get(b) : null)),
            };
        });
        const legend = series.length > 1
            ? `<div class="ld-legend">${series.map((s) => `<span><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join('')}</div>`
            : '';
        ctx.body.innerHTML = `<div class="ld-chart-wrap">${legend}<div class="ld-plot" data-plot></div></div>`;

        const plot = ctx.body.querySelector('[data-plot]');
        const metric = series[0] && series[0].metric;
        const opts = { format: (v) => fmt.value(metric, v), bucket: d.bucket };
        if (d.style === 'bar') {
            drawBarChart(plot, d.buckets, series, opts);
        } else {
            drawLineChart(plot, d.buckets, series, opts);
        }
    }

    // -----------------------------------------------------------------
    // Gauge
    // -----------------------------------------------------------------
    function gauge(ctx) {
        const d = ctx.data;
        if (d.error) return errorBox(ctx, d.error);
        tools(ctx, '');

        const range = d.max - d.min;
        const frac = d.value === null ? 0 : Math.max(0, Math.min(1, (d.value - d.min) / range));
        const r = 40;
        const cx = 50;
        const cy = 50;
        const pt = (f) => {
            const a = Math.PI * (1 - f);
            return [cx + r * Math.cos(a), cy - r * Math.sin(a)];
        };
        const [sx, sy] = pt(0);
        const [ex, ey] = pt(1);
        const [vx, vy] = pt(frac);
        const color = frac >= 1 ? 'var(--ok)' : 'var(--accent)';

        ctx.body.innerHTML = `
            <div class="ld-center">
                <svg class="ld-svg" viewBox="0 0 100 58" style="max-height:8rem" role="img"
                     aria-label="${esc(d.metric.name)} ${esc(fmt.value(d.metric, d.value))} of ${esc(d.max)}">
                    <path class="ld-track" d="M ${sx} ${sy} A ${r} ${r} 0 0 1 ${ex} ${ey}" stroke-width="9" stroke-linecap="round"/>
                    ${frac > 0 ? `<path class="ld-arc-value" d="M ${sx} ${sy} A ${r} ${r} 0 0 1 ${vx.toFixed(2)} ${vy.toFixed(2)}" fill="none" style="stroke:${color}" stroke-width="9" stroke-linecap="round"/>` : ''}
                    <text x="50" y="47" text-anchor="middle" class="ld-label" style="font-size:13px;fill:var(--text-primary)">${esc(fmt.value(d.metric, d.value))}</text>
                </svg>
                <div class="ld-sub">${Math.round(frac * 100)}% of ${esc(fmt.value(d.metric, d.max))} · ${esc(PERIOD_LABEL[d.period] || d.period)}</div>
            </div>`;
    }

    // -----------------------------------------------------------------
    // Goals — compact rows. Amount metrics tap to add; counts use pips.
    // Fill is the user accent; past 100% it shifts toward --ok, capped at 200%.
    // -----------------------------------------------------------------
    function goalPaint(ratio) {
        const capped = Math.max(0, Math.min(2, Number(ratio) || 0));
        const fill = Math.min(1, capped) * 100;
        const over = Math.max(0, capped - 1);
        return `--goal-fill:${fill.toFixed(2)}%;--goal-over:${over.toFixed(4)}`;
    }

    const goalUndo = new Map();
    let goalPop = null;

    function closeGoalPop() {
        if (goalPop) {
            goalPop.remove();
            goalPop = null;
        }
        document.querySelectorAll('.ld-goal-more[aria-expanded="true"]').forEach((b) => {
            b.setAttribute('aria-expanded', 'false');
        });
    }

    function goalUndoKey(widgetId, metricId) {
        return `${widgetId}:${metricId}`;
    }

    function goalAmount(n, unit) {
        const text = fmt.number(n, Number.isInteger(Number(n)) ? 0 : 1);
        return unit ? `${text} ${unit}` : text;
    }

    function goalMark(goal) {
        const metric = goal.metric || {};
        if (goal.icon) {
            return `<span class="ld-goal-ico" aria-hidden="true">${esc(goal.icon)}</span>`;
        }
        const letter = String(metric.name || '?').trim().charAt(0).toUpperCase() || '?';
        return `<span class="ld-goal-ico ld-goal-letter" aria-hidden="true">${esc(letter)}</span>`;
    }

    function goalPips(goal) {
        const total = Math.max(1, Number(goal.pips) || Math.round(Number(goal.goal) * 2) || 1);
        const goalN = Math.max(1, Math.round(Number(goal.goal) || 1));
        const filled = goal.value === null ? 0 : Math.max(0, Math.min(total, Math.round(Number(goal.value))));
        const dots = [];
        for (let i = 0; i < total; i++) {
            const extra = i >= goalN;
            const on = i < filled;
            const cls = ['ld-goal-pip', extra ? 'is-extra' : '', extra && i === goalN ? 'is-extra-first' : '', on ? 'is-on' : '', on && extra ? 'is-over' : ''].filter(Boolean).join(' ');
            dots.push(`<span class="${cls}"></span>`);
        }
        return `<div class="ld-goal-pips" aria-hidden="true">${dots.join('')}</div>`;
    }

    function goalControls(goal, single) {
        const metric = goal.metric || {};
        const id = metric.id;
        const unit = metric.unit || '';
        const incs = Array.isArray(goal.increments) && goal.increments.length ? goal.increments : [250, 500];
        if (goal.mode === 'count') {
            const step = Number(incs[0]) || 1;
            return `<div class="ld-goal-ctl" data-metric="${esc(id)}">
                <button type="button" class="btn text-xs ld-goal-step" data-delta="${-step}" aria-label="Subtract ${esc(goalAmount(step, unit))}">−</button>
                <button type="button" class="btn text-xs ld-goal-step" data-delta="${step}" aria-label="Add ${esc(goalAmount(step, unit))}">+</button>
            </div>`;
        }
        const first = Number(incs[0]) || 250;
        const label = single ? `+${goalAmount(first, unit)}` : `+${fmt.number(first, Number.isInteger(first) ? 0 : 1)}`;
        return `<div class="ld-goal-ctl" data-metric="${esc(id)}" data-incs="${esc(incs.join(','))}" data-unit="${esc(unit)}">
            <button type="button" class="btn text-xs ld-goal-tap" data-delta="${first}" aria-label="Add ${esc(goalAmount(first, unit))}">${esc(label)}</button>
            <button type="button" class="btn text-xs ld-goal-more" aria-expanded="false" aria-label="Other amounts and undo">▾</button>
        </div>`;
    }

    function goalReadout(goal) {
        const metric = goal.metric || {};
        const unit = metric.unit ? ` ${metric.unit}` : '';
        const value = goal.value === null || goal.value === undefined ? '—' : fmt.number(goal.value, metric.kind === 'number' && Number.isInteger(Number(goal.goal)) ? 0 : 1);
        const target = fmt.number(goal.goal, 0);
        return `${value} / ${target}${unit} · ${goal.percent}%`;
    }

    function goalWeekCells(goal) {
        const metric = goal.metric || {};
        return (goal.days || []).map((day) => {
            const cls = [
                'ld-goal-cell',
                day.today ? 'is-today' : '',
                day.future ? 'is-future' : '',
                day.ratio > 1 ? 'is-over' : '',
            ].filter(Boolean).join(' ');
            const shown = day.value === null || day.future ? '' : `${day.percent}`;
            const label = day.value === null
                ? `${day.label} no entry`
                : `${day.label} ${fmt.value(metric, day.value)}, ${day.percent}% of goal`;
            return `<div class="${cls}" style="${goalPaint(day.ratio)}" role="img" aria-label="${esc(label)}">
                <span class="ld-goal-fill"></span>
                <span class="ld-goal-dow">${esc(String(day.label || '').slice(0, 1))}</span>
                <span class="ld-goal-cap">${esc(shown)}</span>
            </div>`;
        }).join('');
    }

    function goalRow(goal, view, single) {
        if (goal.error) {
            return `<div class="ld-goal-row">${goalMark(goal)}<p class="ld-error">${esc(goal.error)}</p></div>`;
        }
        const metric = goal.metric || {};
        const label = `${metric.name} ${goalReadout(goal)}`;
        const visual = view === 'week'
            ? `<div class="ld-goal-week">${goalWeekCells(goal)}</div>`
            : (goal.mode === 'count' ? goalPips(goal) : `<div class="ld-goal-track" style="${goalPaint(goal.ratio)}" role="img" aria-label="${esc(label)}"><span class="ld-goal-fill"></span></div>`);
        const count = goal.mode === 'count' ? ' is-count' : '';
        return `<div class="ld-goal-row${count}" data-metric="${esc(metric.id)}">
            ${goalMark(goal)}
            <div class="ld-goal-copy">
                <div class="ld-goal-name">${esc(metric.name || '')}</div>
                <div class="ld-goal-nums">${esc(goalReadout(goal))}</div>
                ${visual}
            </div>
            ${goalControls(goal, single)}
        </div>`;
    }

    async function logGoal(ctx, metricId, delta, btn) {
        if (!Number.isFinite(delta) || delta === 0) return;
        if (btn) btn.setAttribute('aria-busy', 'true');
        try {
            await api.post(`/widgets/${ctx.widget.id}/goal-log`, { delta, metric_id: metricId });
            const key = goalUndoKey(ctx.widget.id, metricId);
            const stack = goalUndo.get(key) || [];
            if (delta > 0) stack.push(delta);
            else if (stack.length) stack.pop();
            goalUndo.set(key, stack);
            closeGoalPop();
            await ctx.refresh();
        } catch (e) {
            status(e.message || 'Could not log.', 'error');
        } finally {
            if (btn && btn.isConnected) btn.removeAttribute('aria-busy');
        }
    }

    function openGoalPop(ctx, ctl) {
        closeGoalPop();
        const metricId = Number(ctl.dataset.metric);
        const unit = ctl.dataset.unit || '';
        const incs = String(ctl.dataset.incs || '').split(',').map(Number).filter((n) => n > 0);
        const primary = Number(ctl.querySelector('.ld-goal-tap')?.dataset.delta);
        const extras = incs.filter((n) => n !== primary);
        const more = ctl.querySelector('.ld-goal-more');
        if (more) more.setAttribute('aria-expanded', 'true');
        const buttons = extras.map((n) =>
            `<button type="button" class="btn text-xs ld-goal-pop-add" data-delta="${n}">+${esc(goalAmount(n, unit))}</button>`
        ).join('');
        const pop = document.createElement('div');
        pop.className = 'ld-goal-pop';
        pop.innerHTML = `
            ${buttons}
            <form class="ld-goal-pop-custom">
                <input type="number" inputmode="decimal" min="0" step="any" class="input text-xs" aria-label="Custom amount${unit ? ` in ${esc(unit)}` : ''}" placeholder="${esc(unit || 'amount')}">
                <button type="submit" class="btn text-xs">Add</button>
            </form>
            <button type="button" class="btn text-xs ld-goal-pop-undo">Undo</button>`;
        document.body.appendChild(pop);
        const rect = (more || ctl).getBoundingClientRect();
        const width = pop.offsetWidth;
        let left = rect.right - width;
        if (left < 8) left = 8;
        if (left + width > window.innerWidth - 8) left = window.innerWidth - width - 8;
        let top = rect.bottom + 6;
        if (top + pop.offsetHeight > window.innerHeight - 8) top = Math.max(8, rect.top - pop.offsetHeight - 6);
        pop.style.left = `${left}px`;
        pop.style.top = `${top}px`;
        goalPop = pop;
        pop.addEventListener('click', (e) => {
            const add = e.target.closest('.ld-goal-pop-add');
            if (add) {
                e.preventDefault();
                logGoal(ctx, metricId, Number(add.dataset.delta), add);
                return;
            }
            if (e.target.closest('.ld-goal-pop-undo')) {
                e.preventDefault();
                const stack = goalUndo.get(goalUndoKey(ctx.widget.id, metricId)) || [];
                const step = primary || incs[0] || 250;
                logGoal(ctx, metricId, stack.length ? -stack[stack.length - 1] : -step, e.target.closest('.ld-goal-pop-undo'));
            }
        });
        pop.querySelector('form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            const input = pop.querySelector('input');
            const raw = Number(input.value);
            if (!(raw > 0)) {
                status('Enter an amount.', 'error');
                return;
            }
            logGoal(ctx, metricId, raw, pop.querySelector('[type=submit]'));
        });
        pop.querySelector('input')?.focus();
    }

    function bindGoalRows(ctx) {
        ctx.body.querySelectorAll('.ld-goal-ctl').forEach((ctl) => {
            const metricId = Number(ctl.dataset.metric);
            const tap = ctl.querySelector('.ld-goal-tap');
            if (tap) {
                let hold = null;
                let held = false;
                tap.addEventListener('pointerdown', () => {
                    held = false;
                    hold = setTimeout(() => {
                        held = true;
                        openGoalPop(ctx, ctl);
                    }, 450);
                });
                const clear = () => { if (hold) clearTimeout(hold); hold = null; };
                tap.addEventListener('pointerup', clear);
                tap.addEventListener('pointerleave', clear);
                tap.addEventListener('pointercancel', clear);
                tap.addEventListener('click', (e) => {
                    if (held) {
                        e.preventDefault();
                        held = false;
                        return;
                    }
                    logGoal(ctx, metricId, Number(tap.dataset.delta), tap);
                });
            }
            ctl.querySelector('.ld-goal-more')?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (goalPop && ctl.querySelector('.ld-goal-more')?.getAttribute('aria-expanded') === 'true') {
                    closeGoalPop();
                    return;
                }
                openGoalPop(ctx, ctl);
            });
            ctl.querySelectorAll('.ld-goal-step').forEach((btn) => {
                btn.addEventListener('click', () => logGoal(ctx, metricId, Number(btn.dataset.delta), btn));
            });
        });
    }

    if (!window.__ldGoalPopBound) {
        window.__ldGoalPopBound = true;
        document.addEventListener('click', (e) => {
            if (!goalPop) return;
            if (e.target.closest('.ld-goal-pop') || e.target.closest('.ld-goal-more') || e.target.closest('.ld-goal-tap')) return;
            closeGoalPop();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeGoalPop();
        });
    }

    function goal(ctx) {
        closeGoalPop();
        const d = ctx.data;
        const goals = Array.isArray(d.goals) && d.goals.length ? d.goals : (d.metric ? [d] : []);
        if (!goals.length) return errorBox(ctx, d.error || 'Choose a metric.');
        if (goals.every((g) => g.error)) return errorBox(ctx, goals[0].error || d.error);
        tools(ctx, d.view === 'week' ? '<span class="ld-sub">0 empty · 100% full · 200%</span>' : '');
        const single = goals.length === 1;
        const cls = ['ld-goal-list', single ? 'is-single' : '', d.view === 'week' ? 'is-week' : 'is-day'].filter(Boolean).join(' ');
        ctx.body.innerHTML = `<div class="${cls}">${goals.map((g) => goalRow(g, d.view, single)).join('')}</div>`;
        bindGoalRows(ctx);
    }

    // -----------------------------------------------------------------
    // Table
    // -----------------------------------------------------------------
    function table(ctx) {
        const d = ctx.data;
        if (d.error) return errorBox(ctx, d.error);
        tools(ctx, '');
        if (!d.rows.length) {
            ctx.body.innerHTML = '<p class="empty-state mono text-xs py-2">No entries yet.</p>';
            return;
        }
        ctx.body.innerHTML = `<table class="ld-table"><tbody>${d.rows.map((r) => `
            <tr>
                <td>${esc(fmt.monthDay(r.date))}<br>${esc(fmt.time(r.ts))}</td>
                <td class="ld-note">${esc(r.note || '')}</td>
                <td class="ld-num">${esc(fmt.value(d.metric, r.value))}</td>
            </tr>`).join('')}</tbody></table>`;
    }

    // -----------------------------------------------------------------
    // Streak
    // -----------------------------------------------------------------
    function streak(ctx) {
        const d = ctx.data;
        if (d.error || !d.habit) return errorBox(ctx, d.error || 'Habit not found.');
        const h = d.habit;
        tools(ctx, '');
        const dots = h.history.map((done, i) =>
            `<span class="ld-dot${done ? ' is-done' : ''}${d.days[i] === d.days[d.days.length - 1] ? ' is-today' : ''}" title="${esc(fmt.shortDate(d.days[i]))}"></span>`
        ).join('');
        ctx.body.innerHTML = `
            <div class="ld-center">
                <div class="ld-big">${h.streak > 0 ? '🔥' : ''}${h.streak}</div>
                <div class="ld-sub">${esc(h.emoji || '')} ${esc(h.name)} · best ${h.best_streak}</div>
                <div class="ld-strip" style="margin-top:0.5rem">${dots}</div>
                <button type="button" class="btn text-xs" style="margin-top:0.5rem" data-toggle aria-pressed="${h.done_today}">
                    ${h.done_today ? '✓ Done today' : 'Mark done today'}</button>
            </div>`;
        const btn = ctx.body.querySelector('[data-toggle]');
        btn.addEventListener('click', () => guarded(btn, async () => {
            await api.post(`/habits/${h.id}/toggle`, {});
            await ctx.refresh();
        }));
    }

    // -----------------------------------------------------------------
    // SVG chart primitives
    // -----------------------------------------------------------------
    function extent(series, opts) {
        let lo = Infinity;
        let hi = -Infinity;
        series.forEach((s) => s.values.forEach((v) => {
            if (v !== null) { lo = Math.min(lo, v); hi = Math.max(hi, v); }
        }));
        if (opts.min !== undefined) lo = Math.min(lo, opts.min);
        if (opts.max !== undefined) hi = Math.max(hi, opts.max);
        if (opts.zero) { lo = Math.min(lo, 0); hi = Math.max(hi, 0); }
        if (!Number.isFinite(lo)) return null;
        if (lo === hi) { lo -= 1; hi += 1; }
        if (opts.min === undefined && !opts.zero) {
            const pad = (hi - lo) * 0.08;
            lo -= pad;
            hi += pad;
        }
        return [lo, hi];
    }

    function frame(el, buckets, series, opts) {
        const W = Math.max(120, el.clientWidth || 300);
        const H = Math.max(48, el.clientHeight || 120);
        const padL = opts.compact ? 2 : 36;
        const padB = opts.compact ? 2 : 16;
        const padT = 6;
        const padR = 4;
        const ext = extent(series, opts);
        return { W, H, padL, padB, padT, padR, ext };
    }

    /** Baseline, min/max y labels and first/middle/last x labels. xl(i) = x of bucket i. */
    function axes(f, buckets, opts, xl, baseY) {
        if (opts.compact || !f.ext) return '';
        const [lo, hi] = f.ext;
        const fmtY = opts.format || ((v) => fmt.number(v, 1));
        const n = buckets.length;
        const labelIdx = n > 2 ? [0, Math.floor((n - 1) / 2), n - 1] : [...Array(n).keys()];
        const by = baseY ?? f.H - f.padB;
        return `
            <line class="ld-axis" x1="${f.padL}" y1="${by}" x2="${f.W - f.padR}" y2="${by}"/>
            <text class="ld-label" x="${f.padL - 4}" y="${f.padT + 8}" text-anchor="end">${esc(fmtY(hi))}</text>
            <text class="ld-label" x="${f.padL - 4}" y="${f.H - f.padB}" text-anchor="end">${esc(fmtY(lo))}</text>
            ${labelIdx.map((i) => `<text class="ld-label" x="${xl(i)}" y="${f.H - 3}" text-anchor="${i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}">${esc(fmt.monthDay(buckets[i]))}</text>`).join('')}`;
    }

    function drawLineChart(el, buckets, series, opts) {
        if (!el) return;
        const f = frame(el, buckets, series, opts);
        if (!f.ext) {
            el.innerHTML = '<p class="empty-state mono text-xs py-2">No data in this period.</p>';
            return;
        }
        const [lo, hi] = f.ext;
        const n = buckets.length;
        const x = (i) => f.padL + (n <= 1 ? (f.W - f.padL - f.padR) / 2 : (i * (f.W - f.padL - f.padR)) / (n - 1));
        const y = (v) => f.padT + (1 - (v - lo) / (hi - lo)) * (f.H - f.padT - f.padB);
        const fmtV = opts.format || ((v) => fmt.number(v, 1));

        const paths = series.map((s) => {
            let d = '';
            let pen = false;
            const dots = [];
            s.values.forEach((v, i) => {
                if (v === null) { pen = false; return; }
                d += `${pen ? 'L' : 'M'}${x(i).toFixed(1)} ${y(v).toFixed(1)} `;
                pen = true;
                dots.push(`<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="${opts.compact ? 1.8 : 2.4}" style="fill:${s.color}"><title>${esc(fmt.monthDay(buckets[i]))}: ${esc(fmtV(v))}</title></circle>`);
            });
            return `<path d="${d}" fill="none" style="stroke:${s.color}" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>${dots.join('')}`;
        }).join('');

        el.innerHTML = `<svg class="ld-svg" viewBox="0 0 ${f.W} ${f.H}" preserveAspectRatio="none" role="img" aria-label="${esc(series.map((s) => s.name).join(', '))} chart">${axes(f, buckets, opts, x)}${paths}</svg>`;
    }

    function drawBarChart(el, buckets, series, opts) {
        const f = frame(el, buckets, series, Object.assign({ zero: true }, opts));
        if (!f.ext) {
            el.innerHTML = '<p class="empty-state mono text-xs py-2">No data in this period.</p>';
            return;
        }
        const [lo, hi] = f.ext;
        const n = buckets.length;
        const band = (f.W - f.padL - f.padR) / Math.max(1, n);
        const barW = Math.max(1, (band * 0.8) / series.length);
        const y = (v) => f.padT + (1 - (v - lo) / (hi - lo)) * (f.H - f.padT - f.padB);
        const y0 = y(0);
        const fmtV = opts.format || ((v) => fmt.number(v, 1));

        const bars = series.map((s, si) => s.values.map((v, i) => {
            if (v === null) return '';
            const bx = f.padL + i * band + band * 0.1 + si * barW;
            const top = Math.min(y(v), y0);
            const h = Math.max(1, Math.abs(y(v) - y0));
            return `<rect x="${bx.toFixed(1)}" y="${top.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="1.5" style="fill:${s.color}"><title>${esc(fmt.monthDay(buckets[i]))}: ${esc(fmtV(v))}</title></rect>`;
        }).join('')).join('');

        const labels = axes(f, buckets, opts, (i) => f.padL + (i + 0.5) * band, y0);
        el.innerHTML = `<svg class="ld-svg" viewBox="0 0 ${f.W} ${f.H}" preserveAspectRatio="none" role="img" aria-label="${esc(series.map((s) => s.name).join(', '))} bar chart">${labels}${bars}</svg>`;
    }

    function life(ctx) {
        tools(ctx, '<a href="/settings/life" class="nav-link">Life</a>');
        const rows = (ctx.data.rows || []).map((row) => {
            const m = row.metric;
            const v = row.latest ? row.latest.value : null;
            let shown = '—';
            if (v !== null) {
                shown = m.kind === 'duration' ? fmt.duration(v) : (m.kind === 'habit' ? (v >= 1 ? 'Yes' : 'No') : fmt.number(v, m.unit === 'steps' ? 0 : 1));
                if (m.unit && m.kind === 'number') shown += ' ' + m.unit;
            }
            return `<div class="ld-life-row"><span>${esc(m.name)}</span><strong>${esc(shown)}</strong></div>`;
        }).join('');
        ctx.body.innerHTML = rows || '<p class="empty-state mono text-xs">No life metrics yet.</p>';
    }

    function spending(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const cats = (d.categories || []).map((c) =>
            `<div class="ld-life-row"><span>${esc(c.category)}</span><strong>${esc(fmt.money(c.total))}</strong></div>`
        ).join('');
        ctx.body.innerHTML = `<div class="ld-stat-value">${esc(fmt.money(d.spent))}</div><div class="ld-meta">spent · ${esc(PERIOD_LABEL[d.period] || d.period || '')}</div>${cats}`;
    }

    function networth(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const accounts = (d.accounts || []).map((a) =>
            `<div class="ld-life-row"><span>${esc(a.name)}</span><strong>${esc(fmt.money(a.kind === 'credit' ? -a.balance : a.balance))}</strong></div>`
        ).join('');
        ctx.body.innerHTML = `<div class="ld-stat-value">${esc(fmt.money(d.net_worth))}</div><div class="ld-meta">net worth trend</div>${accounts}`;
        const el = document.createElement('div');
        el.style.height = '7rem';
        ctx.body.appendChild(el);
        const snaps = d.snapshots || [];
        if (snaps.length > 1) {
            drawLineChart(el, snaps.map((s) => s.date), [{ name: 'Net worth', color: PALETTE[0], values: snaps.map((s) => s.net) }], { compact: true, format: fmt.money });
        }
    }

    function arcMeter(percent, opts) {
        const pct = Math.max(0, Math.min(100, Number(percent) || 0));
        const shown = pct >= 0.8;
        const dash = pct.toFixed(1);
        const size = opts.small ? ' ld-arc-sm' : '';
        const warn = opts.warn ? ' is-warn' : '';
        const tone = opts.warn ? 'text-warn' : '';
        const value = `<div class="ld-stat-value ${tone}">${esc(opts.value || '')}</div>`;
        const sub = opts.sub ? `<div class="ld-meta ${tone}">${esc(opts.sub)}</div>` : '';
        const note = opts.note ? `<p class="text-sm">${esc(opts.note)}</p>` : '';
        return `<div class="ld-arc${size}${warn}" role="img" aria-label="${esc(opts.label || opts.value || 'progress')}">
            <svg class="ld-arc-svg" viewBox="0 0 120 74" aria-hidden="true">
                <path class="ld-arc-track" d="M 14 64 A 46 46 0 0 1 106 64" pathLength="100"></path>
                <path class="ld-arc-ticks" d="M 14 64 A 46 46 0 0 1 106 64" pathLength="100" stroke-dasharray="0.6 7.6"></path>
                ${shown ? `<path class="ld-arc-value" d="M 14 64 A 46 46 0 0 1 106 64" pathLength="100" stroke-dasharray="${dash} 100"></path>` : ''}
            </svg>
            <div class="ld-arc-copy">${value}${sub}${note}</div>
        </div>`;
    }

    function babyStep(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        if (!d.step) {
            ctx.body.innerHTML = '<p class="empty-state mono text-xs">No Baby Step recorded yet.</p>';
            return;
        }
        const step = Number(d.step) || 0;
        ctx.body.innerHTML = arcMeter((step / 7) * 100, {
            label: 'Baby Step ' + step,
            value: 'Step ' + step,
            sub: d.name || '',
            note: d.note || '',
        });
    }

    function debtSnowball(ctx) {
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (ctx.data.debts || []).map((d) => {
            const dates = [d.target_payoff_on ? 'target ' + d.target_payoff_on : '', d.estimated_payoff_on ? 'estimate ' + d.estimated_payoff_on : 'estimate unavailable'].filter(Boolean).join(' · ');
            return `<div class="ld-life-row"><span>${esc(d.name)}${d.last4 ? ' · ' + esc(d.last4) : ''}<span class="ld-meta">${esc(d.apr_percent)}% · min ${esc(fmt.money(d.minimum_payment))} · ${esc(dates)}</span></span><strong>${esc(fmt.money(d.balance))}</strong></div>`;
        }).join('');
        ctx.body.innerHTML = rows || '<p class="empty-state mono text-xs">No debts recorded.</p>';
    }

    function foodWeek(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const cap = Number(d.cap) || 0;
        const spent = Number(d.spent) || 0;
        ctx.body.innerHTML = arcMeter(cap > 0 ? (spent / cap) * 100 : 0, {
            warn: !!d.over,
            label: 'Food this week',
            value: fmt.money(d.spent),
            sub: 'of ' + fmt.money(d.cap) + ' this week',
        });
    }

    function budgetActual(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (d.lines || []).map((line) =>
            `<div class="ld-life-row"><span>${esc(line.category)}<span class="ld-meta">planned ${esc(fmt.money(line.planned))}</span></span><strong class="${line.actual > line.planned ? 'text-danger' : ''}">${esc(fmt.money(line.actual))}</strong></div>`
        ).join('');
        ctx.body.innerHTML = `<div class="ld-meta">${esc(String(d.month))}/${esc(String(d.year))}</div>${rows || '<p class="empty-state mono text-xs">No budget lines this month.</p>'}`;
    }

    function sinkingFunds(ctx) {
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (ctx.data.funds || []).map((f) => {
            const pct = Number(f.percent) || 0;
            return `<div class="ld-fund">${arcMeter(pct, { small: true, label: f.name, value: Math.round(pct) + '%' })}<div class="ld-fund-copy"><span>${esc(f.name)}</span><span class="ld-meta">${esc(fmt.money(f.balance))} / ${esc(fmt.money(f.target_amount))}${f.due_on ? ' · due ' + esc(f.due_on) : ''}</span></div></div>`;
        }).join('');
        ctx.body.innerHTML = rows || '<p class="empty-state mono text-xs">No sinking funds yet.</p>';
    }

    function billsDue(ctx) {
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (ctx.data.bills || []).map((b) =>
            `<div class="ld-life-row"><span>${esc(b.name)}<span class="ld-meta">${esc(b.due_on)}</span></span><strong>${esc(fmt.money(b.amount))}</strong></div>`
        ).join('');
        ctx.body.innerHTML = rows || '<p class="empty-state mono text-xs">Nothing due in the next 10 days.</p>';
    }

    function retirement(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rate = d.contribution_rate == null ? null : Number(d.contribution_rate);
        const shown = rate == null ? '—' : Math.round(rate * 1000) / 10 + '%';
        ctx.body.innerHTML = arcMeter(rate == null ? 0 : (rate / 0.15) * 100, {
            warn: !d.on_track,
            label: 'Retirement contribution',
            value: shown,
            sub: 'of a 15% target',
        });
    }

    function credit(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const util = d.utilization_percent == null ? '—' : d.utilization_percent + '%';
        ctx.body.innerHTML = `<div class="ld-stat-value">${d.score == null ? '—' : esc(String(d.score))}</div><div class="ld-meta">utilization ${esc(String(util))}${d.as_of ? ' · ' + esc(d.as_of) : ''}</div>`;
    }

    function portfolio(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/investing" class="nav-link">Investing</a>');
        const rows = (d.positions || []).map((p) =>
            `<div class="ld-life-row"><span>${esc(p.symbol)} <span class="ld-meta">${esc(p.venue)}</span></span><strong class="${p.unrealized < 0 ? 'ld-down' : 'ld-up'}">${esc(fmt.money(p.unrealized))}</strong></div>`
        ).join('');
        ctx.body.innerHTML = `<div class="ld-stat-value">${esc(fmt.money(d.market_value))}</div><div class="ld-meta">market value · unrealized ${esc(fmt.money(d.unrealized))}</div>${rows || '<p class="empty-state mono text-xs">No positions imported.</p>'}`;
    }

    function pages(ctx) {
        tools(ctx, '<a href="/brain" class="nav-link">Vault</a> <a href="/brain/graph" class="nav-link">Brain</a>');
        const rows = (ctx.data.pages || []).map((p) =>
            `<a class="ld-life-row" href="/brain?page=${p.id}"><span>${esc(p.title)}</span><span class="ld-meta">${esc(p.kind)}</span></a>`
        ).join('');
        ctx.body.innerHTML = rows || '<p class="empty-state mono text-xs">The vault is empty. <a class="link" href="/brain">Write the first page.</a></p>';
    }

    function custom(ctx) {
        const data = JSON.stringify({ metrics: ctx.data.metrics || [], today: ctx.data.today || '' }).replace(/</g, '\\u003c');
        const markup = (ctx.widget.config && ctx.widget.config.markup) || '';
        const doc = `<!DOCTYPE html><html><head><meta charset="utf-8"><style>
            html,body{margin:0;background:transparent;color:#d7e2f2;font:13px/1.45 ui-sans-serif,system-ui,sans-serif}
            </style></head><body><script>window.__DATA__=${data};<\/script>${markup}</body></html>`;
        const frame = document.createElement('iframe');
        frame.setAttribute('sandbox', 'allow-scripts');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.title = 'Custom widget';
        frame.style.cssText = 'width:100%;height:100%;border:0;background:transparent;color-scheme:dark';
        frame.srcdoc = doc;
        ctx.body.replaceChildren(frame);
    }

    const carouselTimers = new WeakMap();

    function carousel(ctx) {
        const prev = carouselTimers.get(ctx.body);
        if (prev) clearInterval(prev);
        carouselTimers.delete(ctx.body);

        const d = ctx.data || {};
        const cards = d.cards || [];
        const noun = d.dataset === 'reddit' ? 'posts' : (d.dataset === 'x' ? 'posts' : 'videos');
        tools(ctx, cards.length ? `<span class="ld-meta">${cards.length} ${noun}</span>` : '');

        if (!cards.length) {
            const errs = (d.errors || []).map((e) => `<p class="ld-error">${esc(e)}</p>`).join('');
            ctx.body.innerHTML = `${errs}<p class="empty-state mono text-xs">${esc(d.empty || 'Nothing to show yet.')}</p>`;
            return;
        }

        const motion = d.motion === 'push' ? 'push' : 'wipe';
        const slides = cards.map((card, i) => {
            const when = card.published_at ? esc(String(card.published_at).slice(0, 10)) : '';
            const thumb = card.thumb
                ? `<img class="ld-card-thumb" alt="" referrerpolicy="no-referrer" src="${esc(card.thumb)}">`
                : '';
            return `<article class="ld-card${i === 0 ? ' is-on is-enter' : ''}" data-i="${i}">
                <div class="ld-card-kicker"><span>${esc(card.source || '')}</span><span>${when}</span></div>
                <a class="ld-card-title" href="${esc(card.url)}" target="_blank" rel="noopener noreferrer">${esc(card.title)}</a>
                ${card.body ? `<p class="ld-card-body">${esc(card.body)}</p>` : ''}
                ${thumb}
            </article>`;
        }).join('');
        const dots = cards.length > 1 ? `<div class="ld-card-nav">
            <button type="button" class="ld-icon-btn" data-step="-1" aria-label="Previous">‹</button>
            <div class="ld-card-dots">${cards.map((_, i) => `<button type="button" class="ld-card-dot${i === 0 ? ' is-on' : ''}" data-go="${i}" aria-label="Show item ${i + 1}"></button>`).join('')}</div>
            <button type="button" class="ld-icon-btn" data-step="1" aria-label="Next">›</button>
        </div>` : '';
        const errs = (d.errors || []).map((e) => `<p class="ld-error">${esc(e)}</p>`).join('');
        ctx.body.innerHTML = `${errs}<div class="ld-carousel is-${motion}" tabindex="0">
            <div class="ld-card-stack">${slides}</div>${dots}<div class="ld-carousel-bar"></div></div>`;

        const root = ctx.body.querySelector('.ld-carousel');
        if (!root || cards.length < 2) return;
        let index = 0;
        const bar = root.querySelector('.ld-carousel-bar');
        const ms = (Number(d.interval) || 12) * 1000;
        if (bar) bar.style.animationDuration = `${ms}ms`;

        function show(next) {
            const slides = [...root.querySelectorAll('.ld-card')];
            index = (next + slides.length) % slides.length;
            slides.forEach((el, n) => {
                const on = n === index;
                el.classList.toggle('is-on', on);
                el.classList.remove('is-enter');
                if (on) {
                    void el.offsetWidth;
                    el.classList.add('is-enter');
                }
            });
            root.querySelectorAll('.ld-card-dot').forEach((dot, n) => dot.classList.toggle('is-on', n === index));
            if (bar) {
                bar.style.animation = 'none';
                void bar.offsetWidth;
                bar.style.animation = '';
                bar.style.animationDuration = `${ms}ms`;
            }
        }

        function arm() {
            const existing = carouselTimers.get(ctx.body);
            if (existing) clearInterval(existing);
            const timer = setInterval(() => {
                if (!root.isConnected) {
                    clearInterval(timer);
                    carouselTimers.delete(ctx.body);
                    return;
                }
                if (document.hidden || root.classList.contains('is-paused')) return;
                show(index + 1);
            }, ms);
            carouselTimers.set(ctx.body, timer);
        }

        root.addEventListener('mouseenter', () => root.classList.add('is-paused'));
        root.addEventListener('mouseleave', () => root.classList.remove('is-paused'));
        root.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') { e.preventDefault(); show(index + 1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); show(index - 1); }
        });
        root.addEventListener('click', (e) => {
            const step = e.target.closest('[data-step]');
            const go = e.target.closest('[data-go]');
            if (step) show(index + Number(step.dataset.step));
            if (go) show(Number(go.dataset.go));
        });
        arm();
    }

    function redditFeed(ctx) {
        tools(ctx, '<a href="/reddit" class="nav-link">Reader</a>');
        const posts = ctx.data.posts || [];
        if (!posts.length) {
            ctx.body.innerHTML = `<p class="empty-state mono text-xs">${esc(ctx.data.empty || 'No posts yet.')}</p>`;
            return;
        }
        ctx.body.innerHTML = posts.map((post) => {
            const meta = [
                post.subreddit ? 'r/' + post.subreddit : '',
                post.author ? 'u/' + post.author : '',
                (post.score ?? 0) + ' pts',
                (post.num_comments ?? 0) + ' comments',
            ].filter(Boolean).join(' · ');
            return `<a class="ld-life-row" href="${esc(post.permalink)}" target="_blank" rel="noopener noreferrer">
                <span>${esc(post.title)}</span><span class="ld-meta">${esc(meta)}</span></a>`;
        }).join('');
    }

    function shopQty(item) {
        const q = Number(item.quantity);
        if (!Number.isFinite(q)) return '';
        const shown = Math.abs(q - Math.round(q)) < 0.001 ? String(Math.round(q)) : String(Math.round(q * 1000) / 1000);
        if (q === 1 && !item.unit) return '';
        return shown + (item.unit ? ' ' + item.unit : '');
    }

    function shopping(ctx) {
        const d = ctx.data || {};
        const lists = d.lists || [];
        const host = ctx.body.closest('.grid-stack-item');
        let active = Number(host && host.dataset.shopList);
        if (!lists.some((list) => list.id === active)) active = lists[0] ? lists[0].id : 0;
        const list = lists.find((row) => row.id === active) || null;
        const boughtOpen = host && host.dataset.shopBought === '1';

        const tabs = lists.map((row) =>
            `<button type="button" class="ld-shop-tab${row.id === active ? ' is-on' : ''}" data-shop-tab="${row.id}" aria-pressed="${row.id === active}">${esc(row.name)}<span>${row.open_count}</span></button>`
        ).join('');

        const groups = list ? (list.groups || []).map((group) => {
            const rows = group.items.map((item) => shopRow(item, false)).join('');
            return `<div class="ld-shop-cat">${esc(group.category)}</div>${rows}`;
        }).join('') : '';

        const bought = list ? (list.bought || []) : [];
        const boughtRows = bought.map((item) => shopRow(item, true)).join('');
        const boughtBlock = bought.length
            ? `<button type="button" class="ld-shop-bought-toggle" data-shop-bought aria-expanded="${boughtOpen}">Bought · ${bought.length}</button>
               <div class="ld-shop-bought${boughtOpen ? '' : ' hidden'}">${boughtRows}</div>`
            : '';

        const empty = list && !(list.groups || []).length
            ? '<p class="empty-state mono text-xs">Nothing open. Add an item below.</p>'
            : '';

        ctx.body.innerHTML = `
            <div class="ld-shop">
                <div class="ld-shop-tabs" role="tablist">${tabs}</div>
                <form class="ld-shop-add" data-shop-add>
                    <input class="input" name="text" maxlength="160" placeholder="2 lb chicken" aria-label="Add item" autocomplete="off">
                    <button type="submit" class="btn text-xs">Add</button>
                </form>
                <div class="ld-shop-items">${groups}${empty}${boughtBlock}</div>
            </div>`;

        ctx.body.querySelectorAll('[data-shop-tab]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (host) host.dataset.shopList = btn.dataset.shopTab;
                shopping(ctx);
            });
        });
        const form = ctx.body.querySelector('[data-shop-add]');
        if (form) {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const text = new FormData(form).get('text');
                if (!text || !String(text).trim() || !list) return;
                guarded(form.querySelector('button'), async () => {
                    await api.post('/shopping/items', { list_id: list.id, items: [String(text).trim()] });
                    await ctx.refresh();
                });
            });
        }
        ctx.body.querySelectorAll('[data-shop-check]').forEach((btn) => {
            btn.addEventListener('click', () => {
                guarded(btn, async () => {
                    await api.post(`/shopping/items/${btn.dataset.shopCheck}/check`, { bought: btn.dataset.bought !== '1' });
                    await ctx.refresh();
                });
            });
        });
        ctx.body.querySelectorAll('[data-shop-remove]').forEach((btn) => {
            btn.addEventListener('click', () => {
                guarded(btn, async () => {
                    await api.post(`/shopping/items/${btn.dataset.shopRemove}/delete`, {});
                    await ctx.refresh();
                });
            });
        });
        const toggle = ctx.body.querySelector('[data-shop-bought]');
        if (toggle) {
            toggle.addEventListener('click', () => {
                if (host) host.dataset.shopBought = host.dataset.shopBought === '1' ? '0' : '1';
                shopping(ctx);
            });
        }
    }

    function shopRow(item, bought) {
        const qty = shopQty(item);
        const meta = [qty, item.store || '', item.notes || ''].filter(Boolean).join(' · ');
        return `<div class="ld-shop-row${bought ? ' is-bought' : ''}">
            <button type="button" class="ld-check${bought ? ' is-done' : ''}" data-shop-check="${item.id}" data-bought="${bought ? '1' : '0'}"
                aria-pressed="${bought}" aria-label="${esc(bought ? 'Return' : 'Buy')} ${esc(item.name)}">✓</button>
            <div class="min-w-0">
                <div class="ld-shop-name">${esc(item.name)}</div>
                ${meta ? `<div class="ld-shop-meta">${esc(meta)}</div>` : ''}
            </div>
            <button type="button" class="ld-shop-x" data-shop-remove="${item.id}" aria-label="Remove ${esc(item.name)}">✕</button>
        </div>`;
    }

    function calls(ctx) {
        const d = ctx.data || {};
        const rows = d.calls || [];
        const counts = d.counts || {};
        const host = ctx.body.closest('.grid-stack-item');
        const filter = (host && host.dataset.callFilter) || 'open';
        const openId = host ? String(host.dataset.callOpen || '') : '';
        const tabs = [
            ['open', 'Open', counts.open_returns],
            ['urgent', 'Urgent', counts.urgent],
            ['debt', 'Debt', counts.debt],
            ['vip', 'VIP', counts.vip],
            ['all', 'All', counts.total],
        ];
        const shown = rows.filter((call) => callMatches(call, filter));
        const tabHtml = tabs.map(([id, label, n]) =>
            `<button type="button" class="ld-call-tab${id === filter ? ' is-on' : ''}" data-call-filter="${id}" aria-pressed="${id === filter}">${label}<span>${n ?? 0}</span></button>`
        ).join('');
        const list = shown.length
            ? shown.map((call) => callRow(call, String(call.id) === openId)).join('')
            : '<p class="empty-state mono text-xs">Nothing in this view.</p>';
        ctx.body.innerHTML = `<div class="ld-call"><div class="ld-call-tabs" role="tablist">${tabHtml}</div><div class="ld-call-list">${list}</div></div>`;
        ctx.body.querySelectorAll('[data-call-filter]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (host) host.dataset.callFilter = btn.dataset.callFilter;
                calls(ctx);
            });
        });
        ctx.body.querySelectorAll('[data-call-open]').forEach((btn) => {
            btn.addEventListener('click', () => {
                if (!host) return;
                host.dataset.callOpen = host.dataset.callOpen === btn.dataset.callOpen ? '' : btn.dataset.callOpen;
                calls(ctx);
            });
        });
        ctx.body.querySelectorAll('[data-call-status]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                guarded(btn, async () => {
                    await api.post(`/calls/${btn.dataset.callId}/status`, { status: btn.dataset.callStatus });
                    await ctx.refresh();
                });
            });
        });
    }

    function callMatches(call, filter) {
        if (filter === 'open') return !!call.open_return;
        if (filter === 'urgent') return call.urgency === 'urgent' || call.category === 'urgent';
        if (filter === 'debt') return call.category === 'debt_collector';
        if (filter === 'vip') return !!call.vip;
        return true;
    }

    function callRow(call, open) {
        const who = call.name || call.contact_name || call.company || 'Unknown';
        const company = call.company && call.company !== who ? call.company : '';
        const number = call.callback_display || call.phone_display || '';
        const href = call.callback_tel || call.phone_tel || '';
        const phone = number
            ? (href ? `<a class="ld-call-phone" href="${esc(href)}">${esc(number)}</a>` : `<span class="ld-call-phone">${esc(number)}</span>`)
            : '';
        const badges = [];
        if (call.urgency === 'urgent' || call.category === 'urgent') badges.push('<span class="ld-call-badge is-urgent">Urgent</span>');
        else if (call.urgency === 'high') badges.push('<span class="ld-call-badge is-high">High</span>');
        if (call.category === 'debt_collector') {
            const verdict = (call.debt && call.debt.verdict) ? call.debt.verdict.replaceAll('_', ' ') : 'unverified';
            badges.push(`<span class="ld-call-badge is-debt is-${esc((call.debt && call.debt.verdict) || 'unverified')}">Debt · ${esc(verdict)}</span>`);
        }
        if (call.vip) badges.push(`<span class="ld-call-badge is-vip">VIP${call.vip_tier ? ' · ' + esc(call.vip_tier) : ''}</span>`);
        const urgent = call.urgency === 'urgent' || call.category === 'urgent';
        const detail = open ? callDetail(call) : '';
        const statuses = ['returned', 'resolved', 'spam'].map((status) =>
            `<button type="button" class="ld-call-status${call.status === status ? ' is-on' : ''}" data-call-id="${call.id}" data-call-status="${status}">${status}</button>`
        ).join('');
        return `<article class="ld-call-row${urgent ? ' is-urgent' : ''}${open ? ' is-open' : ''}">
            <button type="button" class="ld-call-main" data-call-open="${call.id}" aria-expanded="${open}">
                <span class="ld-call-name">${esc(who)}${company ? ` <em>${esc(company)}</em>` : ''}</span>
                ${call.reason ? `<span class="ld-call-meta">${esc(call.reason)}</span>` : ''}
                ${badges.length ? `<span class="ld-call-badges">${badges.join('')}</span>` : ''}
            </button>
            ${phone ? `<div class="ld-call-meta">${phone}</div>` : ''}
            ${detail}
            <div class="ld-call-actions">${statuses}</div>
        </article>`;
    }

    function callDetail(call) {
        const bits = [];
        if (call.call_at_display) bits.push(`<p>${esc(call.call_at_display)} · ${esc(call.direction)}${call.handled_by ? ' · ' + esc(call.handled_by) : ''}</p>`);
        if (call.summary) bits.push(`<p>${esc(call.summary)}</p>`);
        if (call.notes) bits.push(`<p class="ld-call-notes">${esc(call.notes)}</p>`);
        if (call.deadline) bits.push(`<p>Deadline ${esc(call.deadline)}</p>`);
        if (call.phone_display && call.callback_display && call.phone_display !== call.callback_display) {
            bits.push(`<p>Called ${call.phone_tel ? `<a class="ld-call-phone" href="${esc(call.phone_tel)}">${esc(call.phone_display)}</a>` : esc(call.phone_display)}</p>`);
        }
        const debt = call.debt;
        if (debt) {
            const money = debt.amount_claimed == null ? '' : ' · $' + Number(debt.amount_claimed).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            const last4 = debt.reference_last4 ? ` · ····${esc(debt.reference_last4)}` : '';
            bits.push(`<p>${esc(debt.agency || debt.collector_name || 'Collector')}${money}${last4}</p>`);
            if (debt.verification_notes) bits.push(`<p class="ld-call-notes">${esc(debt.verification_notes)}</p>`);
        }
        return `<div class="ld-call-detail">${bits.join('')}</div>`;
    }

    function pantry(ctx) {
        const d = ctx.data || {};
        const counts = d.counts || {};
        const expiring = d.expiring || [];
        const low = d.low_stock || [];
        const countLine = ['fridge', 'freezer', 'pantry'].map((key) => {
            const label = key.charAt(0).toUpperCase() + key.slice(1);
            return label + ' ' + (counts[key] || 0);
        }).join(' · ');
        const soon = expiring.map((lot) => {
            const when = lot.days_left < 0 ? 'expired' : (lot.days_left === 0 ? 'today' : lot.days_left + 'd');
            return `<li class="ld-pantry-${esc(lot.freshness)}">${esc(lot.name)} <span class="ld-meta">${esc(when)}</span></li>`;
        }).join('');
        const lowRows = low.map((row) => {
            const meta = row.qty_level
                ? String(row.qty_level).replace('_', ' ')
                : (row.min_on_hand == null ? '' : row.on_hand + ' / ' + row.min_on_hand);
            return `<li>${esc(row.name)} <span class="ld-meta">${esc(meta)}</span></li>`;
        }).join('');
        ctx.body.innerHTML = `
            <div class="ld-pantry-card">
                <p class="ld-meta">${esc(countLine)}</p>
                ${soon ? `<p class="ld-pantry-label">Use soon</p><ul class="ld-pantry-list">${soon}</ul>` : '<p class="empty-state mono text-xs">Nothing due soon.</p>'}
                ${lowRows ? `<p class="ld-pantry-label">Low</p><ul class="ld-pantry-list">${lowRows}</ul>` : ''}
                <a class="ld-card-title" href="/pantry">Open pantry</a>
            </div>`;
    }

    function digest(ctx) {
        tools(ctx, '<a href="/digest" class="nav-link">Digest</a>');
        const data = ctx.data || {};
        const count = Number(data.new_count || 0);
        const rows = (data.items || []).slice(0, 3).map((item) => {
            const badge = item.is_new ? '<span class="ld-digest-badge">New</span> ' : '';
            const line = item.summary ? `<p class="ld-digest-blurb">${esc(item.summary)}</p>` : '';
            return `<p class="ld-card-title">${badge}<a href="${esc(item.url)}" target="_blank" rel="noopener">${esc(item.title)}</a></p><p class="ld-meta">${esc(item.product_label || '')}</p>${line}`;
        }).join('');
        ctx.body.innerHTML = `
            <p class="ld-card-title">${count} new</p>
            ${rows || '<p class="empty-state mono text-xs">No changelog entries yet.</p>'}
            <a class="ld-card-title" href="/digest">Grok Digest</a>`;
    }

    function spotify(ctx) {
        const data = ctx.data || {};
        if (!data.connected) {
            ctx.body.innerHTML = '<p class="empty-state mono text-xs">Spotify is not connected.</p><a class="ld-card-title" href="/settings/spotify">Connect</a>';
            return;
        }
        const now = data.now;
        const playing = now && now.name
            ? `<p class="ld-card-title">${esc(now.name)}</p><p class="ld-meta">${esc((now.artists || []).join(', '))}</p>`
            : `<p class="ld-meta">${esc(data.now_error || 'Nothing playing')}</p>`;
        const artists = (data.top_artists || []).map((row) => `${esc(row.name)} ${row.plays}`).join(' · ');
        ctx.body.innerHTML = `
            <div class="ld-spotify-card">
                ${playing}
                <p class="ld-meta">${data.minutes_today || 0} min today · ${data.minutes_week || 0} min this week</p>
                ${artists ? `<p class="ld-meta">${artists}</p>` : ''}
                <a class="ld-card-title" href="/spotify">Playlists</a>
            </div>`;
    }

    window.LD.renderers = { habits, mood, stat, chart, gauge, goal, table, streak, life, shopping, pantry, calls, spending, networth, baby_step: babyStep, debt_snowball: debtSnowball, food_week: foodWeek, budget_actual: budgetActual, sinking_funds: sinkingFunds, bills_due: billsDue, retirement, credit, portfolio, pages, youtube: carousel, reddit: carousel, x: carousel, reddit_feed: redditFeed, spotify, digest, custom };
    window.LD.charts = { drawLineChart, drawBarChart, extent };
})();
