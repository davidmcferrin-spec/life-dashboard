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

    function emptyPanel(title, body, label, href) {
        return window.DashboardEmpty.html({
            title: title,
            body: body,
            actionLabel: label || '',
            actionHref: href || '',
        });
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
                const letter = String(fmt.shortDate(day) || '').slice(0, 1);
                return `<button type="button" class="ld-dot${done ? ' is-done' : ''}${isToday ? ' is-today' : ''}"
                    data-habit="${h.id}" data-date="${esc(day)}"
                    title="${esc(fmt.shortDate(day))}: ${done ? 'done' : 'not done'}"
                    aria-label="${esc(h.name)} ${esc(fmt.shortDate(day))} ${done ? 'done' : 'not done'}">${esc(letter)}</button>`;
            }).join('');
            const target = h.target_per_week ? `${h.week_count}/${h.target_per_week} this week` : `${h.week_count} this week`;
            const streak = h.streak > 0 ? `${h.streak} day streak` : 'No streak';
            return `
                <div class="ld-habit">
                    <button type="button" class="ld-check${h.done_today ? ' is-done' : ''}" data-habit="${h.id}"
                        aria-pressed="${h.done_today}" aria-label="${esc(h.name)} today"></button>
                    <div class="min-w-0">
                        <div class="ld-habit-name">${esc(h.name)}</div>
                        <div class="ld-habit-meta">${esc(streak)} · ${esc(target)}</div>
                    </div>
                    <div class="ld-strip">${strip}</div>
                </div>`;
        }).join('');

        const quickAdd = d.all_habits ? `
            <form class="ld-mood-note" data-quick-add>
                <input class="input" name="name" maxlength="80" placeholder="New habit…" aria-label="New habit name" autocomplete="off">
                <button type="submit" class="btn text-xs">Add</button>
            </form>` : '';

        ctx.body.innerHTML = `<div class="ld-habit-list ld-card-wipe">${rows || emptyPanel('No habits yet', 'Add the first one below.', 'Open habits', '/settings/trackers')}${quickAdd}</div>`;

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
            buttons += `<button type="button" class="ld-mood-btn${v === current ? ' is-current' : ''}" data-value="${v}"
                title="${esc(label)}" aria-label="Log ${esc(label)}">${esc(v)}</button>`;
        }

        const todayShown = d.latest
            ? ((cfg.labels && cfg.labels[String(Math.round(d.latest.value))]) || fmt.number(d.latest.value, 0))
            : '';
        const todayLine = d.latest
            ? `Today ${esc(todayShown)} at ${esc(fmt.time(d.latest.ts))}${d.today_count > 1 ? ` (+${d.today_count - 1})` : ''}`
            : 'Not logged today';
        const avg = d.average !== null ? `${d.buckets.length}d avg ${fmt.number(d.average, 1)}` : '';

        const notes = (d.notes || []).map((n) => {
            const noteLabel = (cfg.labels && cfg.labels[String(Math.round(n.value))]) || fmt.number(n.value, 0);
            return `<li><span class="ld-meta">${esc(fmt.monthDay(n.date))}</span>${esc(noteLabel)} ${esc(n.note)}</li>`;
        }).join('');

        ctx.body.innerHTML = `
            <div class="ld-mood ld-card-wipe">
            <div class="ld-mood-scale">${buttons}</div>
            <p class="ld-mood-foot"><span>${todayLine}</span><span>${esc(avg)}</span></p>
            <div data-spark class="ld-mood-spark"></div>
            <details class="ld-mood-note">
                <summary>Note</summary>
                <form data-note>
                    <input class="input" name="note" maxlength="500" placeholder="Optional note, then tap a score" aria-label="Mood note" autocomplete="off">
                </form>
                ${notes ? `<ul class="ld-notes">${notes}</ul>` : ''}
            </details>
            </div>`;

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
                delta = `<span class="${good ? 'ld-delta-up' : 'ld-delta-down'}">${diff > 0 ? '+' : '−'} ${esc(fmt.value(d.metric, Math.abs(diff), d.decimals))}</span> vs prior`;
            }
        }
        ctx.body.innerHTML = `
            <div class="ld-stat ld-card-wipe">
                <strong class="ld-big">${esc(d.agg === 'count' ? fmt.number(d.value, 0) : fmt.value(d.metric, d.value, d.decimals))}</strong>
                <span class="ld-sub">${esc(AGG_LABEL[d.agg] || d.agg)} · ${esc(PERIOD_LABEL[d.period] || d.period)}</span>
                ${delta ? `<small class="ld-sub">${delta}</small>` : ''}
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
        ctx.body.innerHTML = `<div class="ld-chart-wrap ld-card-wipe">${legend}<div class="ld-plot" data-plot></div></div>`;

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
            <div class="ld-center ld-gauge ld-card-wipe">
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
            <button type="button" class="btn text-xs ld-goal-more" aria-expanded="false" aria-label="Other amounts and undo">More</button>
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
        const cls = ['ld-goal-list', 'ld-card-wipe', single ? 'is-single' : '', d.view === 'week' ? 'is-week' : 'is-day'].filter(Boolean).join(' ');
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
            ctx.body.innerHTML = emptyPanel('No entries yet', 'The first check-in will show up here.');
            return;
        }
        ctx.body.innerHTML = `<div class="ld-entries ld-card-wipe">${d.rows.map((r) => `
            <div>
                <time>${esc(fmt.monthDay(r.date))}</time>
                <strong>${esc(fmt.value(d.metric, r.value))}</strong>
                <span>${esc(r.note || '')}</span>
            </div>`).join('')}</div>`;
    }

    // -----------------------------------------------------------------
    // Streak
    // -----------------------------------------------------------------
    function streak(ctx) {
        const d = ctx.data;
        if (d.error || !d.habit) return errorBox(ctx, d.error || 'Habit not found.');
        const h = d.habit;
        tools(ctx, '');
        const dots = h.history.map((done, i) => {
            const day = d.days[i];
            const letter = String(fmt.shortDate(day) || '').slice(0, 1);
            return `<span class="ld-dot${done ? ' is-done' : ''}${day === d.days[d.days.length - 1] ? ' is-today' : ''}" title="${esc(fmt.shortDate(day))}">${esc(letter)}</span>`;
        }).join('');
        ctx.body.innerHTML = `
            <div class="ld-streak ld-card-wipe">
                <span><strong>${h.streak}</strong><small>${esc(h.name)} · day current</small></span>
                <span><strong>${h.best_streak}</strong><small>personal best</small></span>
                <div class="ld-strip">${dots}</div>
                <button type="button" class="btn text-xs" data-toggle aria-pressed="${h.done_today}">
                    ${h.done_today ? 'Done today' : 'Mark done today'}</button>
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
            el.innerHTML = emptyPanel('No data in this period', 'Try a wider range, or add an entry.');
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
            el.innerHTML = emptyPanel('No data in this period', 'Try a wider range, or add an entry.');
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
            return `<div class="ld-today-cell"><span>${esc(m.name)}<strong>${esc(shown)}</strong></span></div>`;
        }).join('');
        ctx.body.innerHTML = rows
            ? `<div class="ld-today ld-card-wipe">${rows}</div>`
            : emptyPanel('No life metrics', 'Add one to start a chart.', 'Open life', '/settings/life');
    }

    // Last 4 only. The stored name, including "Creator subscription", is not rewritten.
    function moneyAccountLabel(name, last4) {
        const raw = String(last4 ?? '').trim();
        const masked = raw.match(/^[*xX•·.\-\s]*(\d{4})$/);
        const digits = masked && !/\d{5,}/.test(raw) ? masked[1] : '';
        const mask = digits ? '•••• ' + digits : '';
        const title = name == null ? '' : String(name);
        return [title, mask].filter((part) => part !== '').join(' ');
    }

    function barWidth(part, whole) {
        const top = Number(whole) || 0;
        if (top <= 0) return 0;
        return Math.max(0, Math.min(100, ((Number(part) || 0) / top) * 100));
    }

    function spending(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const cats = d.categories || [];
        let peak = 0;
        cats.forEach((c) => {
            peak = Math.max(peak, Math.abs(Number(c.total) || 0));
        });
        const bars = cats.map((c) =>
            `<span class="ld-money-bar"><small><b>${esc(c.category)}</b><em>${esc(fmt.money(c.total))}</em></small><span class="ld-money-track"><i style="width:${barWidth(Math.abs(Number(c.total) || 0), peak).toFixed(1)}%"></i></span></span>`
        ).join('');
        ctx.body.innerHTML = `<div class="ld-money ld-money-spend"><div class="ld-money-hero"><strong>${esc(fmt.money(d.spent))}</strong><span>spent · ${esc(PERIOD_LABEL[d.period] || d.period || '')}</span></div>${bars}</div>`;
    }

    function networth(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const accounts = (d.accounts || []).map((a) => {
            const signed = a.kind === 'credit' ? -a.balance : a.balance;
            return `<span class="ld-money-account"><em>${esc(moneyAccountLabel(a.name, a.last4))}</em><strong>${esc(fmt.money(signed))}</strong></span>`;
        }).join('');
        ctx.body.innerHTML = `<div class="ld-money ld-money-worth"><div class="ld-money-hero"><strong>${esc(fmt.money(d.net_worth))}</strong><span>net worth trend</span></div><div class="ld-money-line"></div>${accounts ? `<footer>${accounts}</footer>` : ''}</div>`;
        const snaps = d.snapshots || [];
        if (snaps.length > 1) {
            drawLineChart(ctx.body.querySelector('.ld-money-line'), snaps.map((s) => s.date), [{ name: 'Net worth', color: PALETTE[0], values: snaps.map((s) => s.net) }], { compact: true, format: fmt.money });
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
            ctx.body.innerHTML = emptyPanel('No Baby Step yet', 'Record the current step to see it here.', 'Open money', '/settings/money');
            return;
        }
        const step = Number(d.step) || 0;
        const note = d.note ? `<p>${esc(d.note)}</p>` : '';
        ctx.body.innerHTML = `<div class="ld-money ld-money-step" role="img" aria-label="Baby Step ${esc(String(step))}"><span>Step</span><strong>${esc(String(step))}</strong><div><p>${esc(d.name || '')}</p>${note}</div></div>`;
    }

    function debtSnowball(ctx) {
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (ctx.data.debts || []).map((d, index) => {
            const dates = [d.target_payoff_on ? 'target ' + d.target_payoff_on : '', d.estimated_payoff_on ? 'estimate ' + d.estimated_payoff_on : 'estimate unavailable'].filter(Boolean).join(' · ');
            return `<div class="ld-money-debt"><span>${index + 1}</span><p><strong>${esc(moneyAccountLabel(d.name, d.last4))}</strong><small>${esc(d.apr_percent)}% · min ${esc(fmt.money(d.minimum_payment))} · ${esc(dates)}</small></p><b>${esc(fmt.money(d.balance))}</b></div>`;
        }).join('');
        ctx.body.innerHTML = rows ? `<div class="ld-money ld-money-debts">${rows}</div>` : emptyPanel('No debts recorded', 'Add a balance to build the snowball.', 'Open money', '/settings/money');
    }

    function foodWeek(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const cap = Number(d.cap) || 0;
        const spent = Number(d.spent) || 0;
        const warn = d.over ? ' is-warn' : '';
        ctx.body.innerHTML = `<div class="ld-money ld-money-cap${warn}" role="img" aria-label="Food this week"><strong>${esc(fmt.money(d.spent))}</strong><span>of ${esc(fmt.money(d.cap))} this week</span><div class="ld-money-track"><i style="width:${barWidth(spent, cap).toFixed(1)}%"></i></div></div>`;
    }

    function budgetActual(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (d.lines || []).map((line) => {
            const over = line.actual > line.planned;
            const planned = Number(line.planned) || 0;
            const width = planned > 0 ? barWidth(line.actual, line.planned) : ((Number(line.actual) || 0) > 0 ? 100 : 0);
            return `<span class="ld-money-bar${over ? ' is-over' : ''}"><small><b>${esc(line.category)}</b><em>planned ${esc(fmt.money(line.planned))}</em><em class="${over ? 'text-danger' : ''}">${esc(fmt.money(line.actual))}</em></small><span class="ld-money-track"><i style="width:${width.toFixed(1)}%"></i></span></span>`;
        }).join('');
        ctx.body.innerHTML = `<div class="ld-money ld-money-budget"><div class="ld-money-kicker">${esc(String(d.month))}/${esc(String(d.year))}</div>${rows || emptyPanel('No budget lines', 'This month has no lines yet.', 'Open money', '/settings/money')}</div>`;
    }

    function sinkingFunds(ctx) {
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (ctx.data.funds || []).map((f) => {
            const pct = Number(f.percent) || 0;
            const due = f.due_on ? ' · due ' + esc(f.due_on) : '';
            return `<div class="ld-money-fund"><span><b>${esc(f.name)}</b><strong>${esc(String(Math.round(pct)))}%</strong></span><div class="ld-money-track"><i style="width:${Math.max(0, Math.min(100, pct)).toFixed(1)}%"></i></div><small>${esc(fmt.money(f.balance))} / ${esc(fmt.money(f.target_amount))}${due}</small></div>`;
        }).join('');
        ctx.body.innerHTML = rows ? `<div class="ld-money ld-money-funds">${rows}</div>` : emptyPanel('No sinking funds', 'Add a fund to track it here.', 'Open money', '/settings/money');
    }

    function billsDue(ctx) {
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rows = (ctx.data.bills || []).map((b) =>
            `<div class="ld-money-bill"><time datetime="${esc(b.due_on)}">${esc(b.due_on)}</time><strong>${esc(b.name)}</strong><span>${esc(fmt.money(b.amount))}</span></div>`
        ).join('');
        ctx.body.innerHTML = rows ? `<div class="ld-money ld-money-bills">${rows}</div>` : emptyPanel('Nothing due soon', 'No bills land in the next 10 days.');
    }

    function retirement(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const rate = d.contribution_rate == null ? null : Number(d.contribution_rate);
        const shown = rate == null ? '—' : Math.round(rate * 1000) / 10 + '%';
        const width = rate == null ? 0 : barWidth(rate, 0.15);
        const warn = d.on_track ? '' : ' is-warn';
        ctx.body.innerHTML = `<div class="ld-money ld-money-retire${warn}" role="img" aria-label="Retirement contribution"><strong>${esc(shown)}</strong><span>of a 15% target</span><div class="ld-money-track"><i style="width:${width.toFixed(1)}%"></i></div></div>`;
    }

    function credit(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/money" class="nav-link">Money</a>');
        const util = d.utilization_percent == null ? '—' : d.utilization_percent + '%';
        ctx.body.innerHTML = `<div class="ld-money ld-money-credit"><span><strong>${d.score == null ? '—' : esc(String(d.score))}</strong>score</span><span><strong>${esc(String(util))}</strong>utilization${d.as_of ? ' · ' + esc(d.as_of) : ''}</span></div>`;
    }

    function portfolio(ctx) {
        const d = ctx.data;
        tools(ctx, '<a href="/settings/investing" class="nav-link">Investing</a>');
        const tone = (n) => (n < 0 ? 'ld-money-down' : 'ld-money-up');
        const rows = (d.positions || []).map((p) =>
            `<div class="ld-money-pos"><span>${esc(p.symbol)} <span class="ld-money-meta">${esc(p.venue)}</span></span><strong class="${tone(p.unrealized)}">${esc(fmt.money(p.unrealized))}</strong></div>`
        ).join('');
        ctx.body.innerHTML = `<div class="ld-money ld-money-port"><div class="ld-money-hero"><strong>${esc(fmt.money(d.market_value))}</strong><span>market value · unrealized <b class="${tone(d.unrealized)}">${esc(fmt.money(d.unrealized))}</b></span></div>${rows || emptyPanel('No positions', 'Import holdings to see them here.', 'Open investing', '/settings/investing')}</div>`;
    }

    const MEDIA_ICONS = ['music-2', 'file-text', 'chevron-right', 'terminal', 'message-square', 'command', 'play', 'pause', 'skip-back', 'skip-forward', 'external-link'];
    const mediaIconSvg = Object.create(null);

    function mediaSheet() {
        if (document.querySelector('link[href*="/assets/css/widgets-media.css"]')) return;
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id = 'ld-media-widgets-css';
        const current = document.querySelector('link[href*="/assets/css/lifedash.css"]');
        const href = current && current.getAttribute('href');
        const version = href && href.match(/[?&]v=([^&]+)/);
        link.href = '/assets/css/widgets-media.css' + (version ? '?v=' + encodeURIComponent(version[1]) : '');
        document.head.appendChild(link);
    }

    function primeMediaIcons() {
        MEDIA_ICONS.forEach((name) => {
            fetch('/assets/vendor/lucide/' + name + '.svg', { credentials: 'same-origin' })
                .then((res) => (res.ok ? res.text() : ''))
                .then((text) => {
                    if (text.indexOf('<svg') === -1) return;
                    mediaIconSvg[name] = text;
                    document.querySelectorAll('[data-ld-icon="' + name + '"]').forEach((el) => {
                        el.innerHTML = text;
                    });
                })
                .catch(() => {});
        });
    }

    function mediaIcon(name) {
        if (MEDIA_ICONS.indexOf(name) === -1) return '';
        return '<span class="ld-mk-ico" data-ld-icon="' + name + '" aria-hidden="true">' + (mediaIconSvg[name] || '') + '</span>';
    }

    function mediaCssValue(name, fallback) {
        const raw = (getComputedStyle(document.documentElement).getPropertyValue(name) || '').trim() || fallback;
        return raw.replace(/[<>"';{}]/g, '') || fallback;
    }

    primeMediaIcons();

    function pages(ctx) {
        mediaSheet();
        tools(ctx, '<a href="/brain" class="nav-link ld-mk-tool">Vault</a> <a href="/brain/graph" class="nav-link ld-mk-tool">Brain</a>');
        const rows = (ctx.data.pages || []).map((p) => {
            const id = Number(p.id);
            if (!Number.isFinite(id)) return '';
            const when = p.updated_at ? String(p.updated_at).slice(0, 10) : '';
            const meta = [p.kind || '', when].filter(Boolean).join(' · ');
            return `<a href="/brain?page=${id}">${mediaIcon('file-text')}<span><strong>${esc(p.title)}</strong><small>${esc(meta)}</small></span>${mediaIcon('chevron-right')}</a>`;
        }).join('');
        ctx.body.innerHTML = `<div class="ld-mk ld-mk-knowledge">${rows || emptyPanel('The vault is empty', 'Write the first page to start the map.', 'Open the vault', '/brain')}</div>`;
    }

    function custom(ctx) {
        mediaSheet();
        const metrics = (ctx.data && ctx.data.metrics) || [];
        const markup = (ctx.widget.config && ctx.widget.config.markup) || '';
        if (!String(markup).trim()) {
            ctx.body.innerHTML = emptyPanel('No markup yet', 'Add sandboxed markup in the widget settings.');
            return;
        }
        const data = JSON.stringify({ metrics: metrics, today: (ctx.data && ctx.data.today) || '' }).replace(/</g, '\\u003c');
        const text = mediaCssValue('--text', '#e8efef');
        const muted = mediaCssValue('--text-muted', '#8b979e');
        const accent = mediaCssValue('--accent', '#44d7d0');
        const frameCss = "html,body{margin:0;background:transparent;color:" + text
            + ";font:13px/1.45 'IBM Plex Sans',sans-serif}a{color:" + accent
            + "}small{font-size:12px;color:" + muted + "}";
        const doc = `<!DOCTYPE html><html><head><meta charset="utf-8"><style>${frameCss}</style></head><body><script>window.__DATA__=${data};<\/script>${markup}</body></html>`;
        const frame = document.createElement('iframe');
        frame.setAttribute('sandbox', 'allow-scripts');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.title = 'Custom widget';
        const theme = document.documentElement.getAttribute('data-theme') || '';
        frame.style.colorScheme = theme === 'light' ? 'light' : 'dark';
        frame.srcdoc = doc;
        const countLabel = metrics.length === 1 ? '1 attached metric' : metrics.length + ' attached metrics';
        const shell = document.createElement('div');
        shell.className = 'ld-mk ld-mk-custom';
        shell.innerHTML = `<div class="ld-mk-custom-bar">${mediaIcon('terminal')}<span><strong>${esc(countLabel)}</strong><small>Sandboxed markup</small></span></div>`;
        shell.appendChild(frame);
        ctx.body.replaceChildren(shell);
    }

    const carouselTimers = new WeakMap();

    function carouselIcon(dataset) {
        if (dataset === 'youtube') return 'play';
        if (dataset === 'reddit') return 'message-square';
        return 'command';
    }

    function carousel(ctx) {
        mediaSheet();
        const prev = carouselTimers.get(ctx.body);
        if (prev) clearInterval(prev);
        carouselTimers.delete(ctx.body);

        const d = ctx.data || {};
        const cards = d.cards || [];
        const noun = d.dataset === 'reddit' ? 'posts' : (d.dataset === 'x' ? 'posts' : 'videos');
        tools(ctx, cards.length ? `<span class="ld-mk-tool">${cards.length} ${noun}</span>` : '');

        if (!cards.length) {
            const errs = (d.errors || []).map((e) => `<p class="ld-error">${esc(e)}</p>`).join('');
            ctx.body.innerHTML = `${errs}${emptyPanel('Nothing to show', d.empty || 'Nothing to show yet.')}`;
            return;
        }

        const motion = d.motion === 'push' ? 'push' : 'wipe';
        const mark = carouselIcon(d.dataset);
        const slides = cards.map((card, i) => {
            const when = card.published_at ? String(card.published_at).slice(0, 10) : '';
            const thumb = card.thumb
                ? `<img class="ld-card-thumb" alt="" referrerpolicy="no-referrer" src="${esc(card.thumb)}">`
                : '';
            const detail = [when, card.body || ''].filter(Boolean).join(' · ');
            return `<article class="ld-card${i === 0 ? ' is-on is-enter' : ''}" data-i="${i}">
                <div class="ld-card-kicker"><span>${mediaIcon(mark)}Card ${i + 1} of ${cards.length}</span><span>${esc(card.source || '')}</span></div>
                <a class="ld-card-title" href="${esc(card.url)}" target="_blank" rel="noopener noreferrer">${esc(card.title)}${mediaIcon('external-link')}</a>
                ${detail ? `<p class="ld-card-body">${esc(detail)}</p>` : ''}
                ${thumb}
            </article>`;
        }).join('');
        const dots = cards.length > 1 ? `<div class="ld-card-nav">
            <button type="button" class="ld-icon-btn" data-step="-1" aria-label="Previous">${mediaIcon('skip-back')}</button>
            <div class="ld-card-dots">${cards.map((_, i) => `<button type="button" class="ld-card-dot${i === 0 ? ' is-on' : ''}" data-go="${i}" aria-label="Show item ${i + 1}"></button>`).join('')}</div>
            <button type="button" class="ld-icon-btn" data-step="1" aria-label="Next">${mediaIcon('skip-forward')}</button>
        </div>` : '';
        const errs = (d.errors || []).map((e) => `<p class="ld-error">${esc(e)}</p>`).join('');
        ctx.body.innerHTML = `${errs}<div class="ld-mk ld-mk-rotating"><div class="ld-carousel is-${motion}" tabindex="0">
            <div class="ld-card-stack">${slides}</div>${dots}<div class="ld-carousel-bar"></div></div></div>`;

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
        mediaSheet();
        tools(ctx, '<a href="/reddit" class="nav-link ld-mk-tool">Reader</a>');
        const posts = ctx.data.posts || [];
        if (!posts.length) {
            ctx.body.innerHTML = emptyPanel('No posts yet', ctx.data.empty || 'Nothing has been saved for this feed.');
            return;
        }
        ctx.body.innerHTML = `<div class="ld-mk ld-mk-reader">${posts.map((post) => {
            const sub = post.subreddit ? 'r/' + post.subreddit : '';
            const who = [post.author ? 'u/' + post.author : '', (post.score ?? 0) + ' pts'].filter(Boolean).join(' · ');
            const comments = Number(post.num_comments) === 1 ? '1 comment' : (post.num_comments ?? 0) + ' comments';
            return `<a href="${esc(post.permalink)}" target="_blank" rel="noopener noreferrer">
                <span class="ld-mk-sub">${esc(sub)}</span>
                <span><strong>${esc(post.title)}</strong>${who ? `<small>${esc(who)}</small>` : ''}</span>
                <small>${esc(comments)}</small>${mediaIcon('external-link')}</a>`;
        }).join('')}</div>`;
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
            ? emptyPanel('Nothing open', 'Add an item below.')
            : '';

        ctx.body.innerHTML = `
            <div class="ld-shop ld-card-wipe">
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
                aria-pressed="${bought}" aria-label="${esc(bought ? 'Return' : 'Buy')} ${esc(item.name)}"></button>
            <div class="min-w-0">
                <div class="ld-shop-name">${esc(item.name)}</div>
                ${meta ? `<div class="ld-shop-meta">${esc(meta)}</div>` : ''}
            </div>
            <button type="button" class="ld-shop-x" data-shop-remove="${item.id}" aria-label="Remove ${esc(item.name)}"><svg class="lucide lucide-x" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
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
            : emptyPanel('Nothing in this view', 'Switch the filter, or add an item.');
        ctx.body.innerHTML = `<div class="ld-call ld-card-wipe"><div class="ld-call-tabs" role="tablist">${tabHtml}</div><div class="ld-call-list">${list}</div></div>`;
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
        const pip = call.vip ? 'is-vip' : (call.category === 'debt_collector' ? 'is-debt' : (urgent ? 'is-urgent' : ''));
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
            <i class="ld-call-pip ${pip}" aria-hidden="true"></i>
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
        const soonCount = Number(d.expiring_count || expiring.length);
        const lowCount = Number(d.low_stock_count || low.length);
        const chips = [
            soonCount ? `<span class="is-warn">${soonCount} use soon</span>` : '',
            lowCount ? `<span>${lowCount} below par</span>` : '',
        ].filter(Boolean).join('');
        ctx.body.innerHTML = `
            <div class="ld-pantry-counts ld-card-wipe">
                <div>
                    <span><strong>${counts.fridge || 0}</strong>Fridge</span>
                    <span><strong>${counts.freezer || 0}</strong>Freezer</span>
                    <span><strong>${counts.pantry || 0}</strong>Pantry</span>
                </div>
                ${chips ? `<p>${chips}</p>` : ''}
                ${soon ? `<ul class="ld-pantry-list">${soon}</ul>` : ''}
                ${lowRows ? `<ul class="ld-pantry-list">${lowRows}</ul>` : ''}
                ${soon || lowRows ? '' : emptyPanel('Nothing due soon', 'The pantry has no lots coming due.', 'Open pantry', '/pantry')}
                <a class="ld-pantry-open" href="/pantry">Open pantry</a>
            </div>`;
    }

    function meter(item) {
        const m = item.meter;
        if (!m) return '';
        const pct = Math.max(0, Math.min(100, Number(m.pct) || 0));
        return `<div class="ov-bar" role="meter" aria-valuemin="${esc(m.min)}" aria-valuemax="${esc(m.max)}" aria-valuenow="${esc(m.now)}" aria-label="${esc(item.label)}"><span style="width:${pct}%"></span></div>`;
    }

    function vitals(ctx) {
        const data = ctx.data || {};
        if (data.note) {
            ctx.body.innerHTML = `<p class="ov-empty">${esc(data.note)}</p>`;
            return;
        }
        const items = data.items || [];
        if (!items.length) {
            ctx.body.innerHTML = '<p class="ov-empty">Nothing logged yet.</p>';
            return;
        }
        ctx.body.innerHTML = `<ul class="ov-vitals ld-card-wipe">${items.map((item) => `
            <li class="ov-vital">
                <div class="ov-vital-row">
                    <span class="ov-vital-label">${esc(item.label)}</span>
                    <span class="ov-vital-detail">${esc(item.detail)}</span>
                </div>
                ${meter(item)}
            </li>`).join('')}</ul>`;
    }

    function agenda(ctx) {
        const data = ctx.data || {};
        if (data.note) {
            ctx.body.innerHTML = `<p class="ov-empty">${esc(data.note)}</p>`;
            return;
        }
        const items = data.items || [];
        if (!items.length) {
            ctx.body.innerHTML = '<p class="ov-empty">Nothing due today.</p>';
            return;
        }
        ctx.body.innerHTML = `<ol class="ov-agenda ld-card-wipe">${items.map((item) => `
            <li><time datetime="${esc(item.datetime)}">${esc(item.time)}</time><span><strong><a href="${esc(item.href)}">${esc(item.title)}</a></strong><small>Today</small></span><a class="ld-agenda-open" href="${esc(item.href)}">Open</a></li>`).join('')}</ol>`;
    }

    function quickActions(ctx) {
        const actions = (ctx.data && ctx.data.actions) || [];
        ctx.body.innerHTML = `<div class="ov-actions ld-card-wipe">${actions.map((action) => `
            <a class="ov-action" href="${esc(action.href)}">
                <span class="ov-action-title">${esc(action.title)}</span>
                <span class="ov-action-line">${esc(action.line)}</span>
            </a>`).join('')}</div>`;
    }

    function digest(ctx) {
        tools(ctx, '<a href="/digest" class="nav-link">Digest</a>');
        const data = ctx.data || {};
        const count = Number(data.new_count || 0);
        const items = (data.items || []).slice(0, 3);
        const fileIcon = '<svg class="lucide lucide-file-text" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>';
        const chevron = '<svg class="lucide lucide-chevron-right" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>';
        const headline = items[0] ? (items[0].product_label || items[0].title || 'Grok Digest') : 'Grok Digest';
        const rows = items.map((item) => {
            const line = item.summary ? `<small>${esc(item.summary)}</small>` : '';
            return `<a class="ld-digest-row" href="${esc(item.url)}" target="_blank" rel="noopener">${fileIcon}<span><strong>${esc(item.title)}</strong>${line}</span>${chevron}</a>`;
        }).join('');
        ctx.body.innerHTML = `
            <div class="ld-digest-mini ld-card-wipe">
                <span class="ld-digest-badge">${count} new</span>
                <h4>${esc(headline)}</h4>
                ${rows || emptyPanel('Not checked yet', 'Check now to pull the latest release notes.', 'Check now', '/digest')}
                <a class="ld-digest-all" href="/digest">Grok Digest</a>
            </div>`;
    }

    function spotify(ctx) {
        mediaSheet();
        const data = ctx.data || {};
        if (!data.connected) {
            ctx.body.innerHTML = emptyPanel('Spotify is not connected', 'Connect an account to show what is playing.', 'Connect Spotify', '/settings/spotify');
            return;
        }
        const now = data.now;
        const title = now && now.name ? String(now.name) : '';
        const artistsNow = now && Array.isArray(now.artists) ? now.artists.join(', ') : '';
        const art = now && /^https:\/\//i.test(String(now.image_url || '')) ? String(now.image_url) : '';
        const cover = art
            ? `<img alt="" referrerpolicy="no-referrer" src="${esc(art)}">`
            : mediaIcon('music-2');
        const known = now && typeof now.is_playing === 'boolean';
        const playing = !!(known && now.is_playing);
        // Status glyph only. Playback controls stay on the floating player.
        const transport = title && known
            ? `<span class="ld-mk-transport">${mediaIcon(playing ? 'pause' : 'play')}</span>`
            : '';
        const stateLabel = !known || playing ? 'Now playing' : 'Paused';
        const nowCopy = title
            ? `<small>${stateLabel}</small><strong>${esc(title)}</strong><p>${esc(artistsNow)}</p>`
            : `<p class="ld-mk-quiet">${esc(data.now_error || 'Nothing playing')}</p>`;
        const artists = (data.top_artists || []).map((row) => esc(String(row.name || '') + ' ' + (row.plays ?? ''))).join(' · ');
        const minutes = esc(String(data.minutes_today || 0)) + ' min today · ' + esc(String(data.minutes_week || 0)) + ' min this week';
        ctx.body.innerHTML = `
            <div class="ld-mk ld-mk-spotify">
                <span class="ld-mk-cover">${cover}</span>
                <span class="ld-mk-now">${nowCopy}</span>
                ${transport}
                <footer>
                    <span>${minutes}</span>
                    ${artists ? `<span class="ld-mk-artists">${artists}</span>` : ''}
                </footer>
                <a class="ld-mk-open" href="/spotify">Playlists ${mediaIcon('chevron-right')}</a>
            </div>`;
    }

    window.LD.renderers = { habits, mood, stat, chart, gauge, goal, table, streak, life, shopping, pantry, calls, spending, networth, baby_step: babyStep, debt_snowball: debtSnowball, food_week: foodWeek, budget_actual: budgetActual, sinking_funds: sinkingFunds, bills_due: billsDue, retirement, credit, portfolio, pages, youtube: carousel, reddit: carousel, x: carousel, reddit_feed: redditFeed, spotify, digest, vitals, agenda, quick_actions: quickActions, custom };
    window.LD.charts = { drawLineChart, drawBarChart, extent };
})();
