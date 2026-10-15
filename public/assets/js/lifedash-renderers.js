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
                title="${esc(label)}" aria-label="Log ${esc(label)}">${face ? esc(face) : esc(v)}<small>${esc(label)}</small></button>`;
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
            <form class="ld-mood-note" data-note>
                <input class="input" name="note" maxlength="500" placeholder="Note (optional) — then tap a face" aria-label="Mood note" autocomplete="off">
            </form>
            <div class="ld-mood-foot"><span>${todayLine}</span><span>${esc(avg)}</span></div>
            <div data-spark style="height:3.25rem;margin-top:0.35rem"></div>
            ${notes ? `<ul class="ld-notes">${notes}</ul>` : ''}`;

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
    // Goal progress — fill is the user accent; past 100% shifts toward --ok
    // -----------------------------------------------------------------
    function goalPaint(ratio) {
        const capped = Math.max(0, Math.min(2, Number(ratio) || 0));
        const fill = Math.min(1, capped) * 100;
        const over = Math.max(0, capped - 1);
        return `--goal-fill:${fill.toFixed(2)}%;--goal-over:${over.toFixed(4)}`;
    }

    const goalUndo = new Map();

    function goalAmount(n, unit) {
        const text = fmt.number(n, Number.isInteger(Number(n)) ? 0 : 1);
        return unit ? `${text} ${unit}` : text;
    }

    function goalLogForm(ctx) {
        const incs = Array.isArray(ctx.data.increments) && ctx.data.increments.length
            ? ctx.data.increments : [250, 500];
        const unit = ctx.data.metric && ctx.data.metric.unit ? ctx.data.metric.unit : '';
        const buttons = incs.map((n) =>
            `<button type="button" class="btn text-xs ld-goal-add" data-delta="${Number(n)}">+${esc(goalAmount(n, unit))}</button>`
        ).join('');
        const step = Number(incs[0]) || 250;
        return `<form class="ld-goal-log">
            <div class="ld-goal-adds">${buttons}</div>
            <label class="ld-goal-custom">
                <input type="number" inputmode="decimal" min="0" step="any" class="input text-xs" aria-label="Custom amount${unit ? ` in ${esc(unit)}` : ''}" placeholder="${esc(unit || 'amount')}">
                <button type="submit" class="btn text-xs">Add</button>
            </label>
            <button type="button" class="btn text-xs ld-goal-undo" title="Undo the last add, or subtract ${esc(goalAmount(step, unit))}">−</button>
        </form>`;
    }

    async function logGoal(ctx, delta, btn) {
        if (!Number.isFinite(delta) || delta === 0) return;
        if (btn) btn.setAttribute('aria-busy', 'true');
        try {
            await api.post(`/widgets/${ctx.widget.id}/goal-log`, { delta });
            const stack = goalUndo.get(ctx.widget.id) || [];
            if (delta > 0) stack.push(delta);
            else if (stack.length) stack.pop();
            goalUndo.set(ctx.widget.id, stack);
            await ctx.refresh();
        } catch (e) {
            status(e.message || 'Could not log.', 'error');
        } finally {
            if (btn && btn.isConnected) btn.removeAttribute('aria-busy');
        }
    }

    function bindGoalLog(ctx) {
        const form = ctx.body.querySelector('.ld-goal-log');
        if (!form) return;
        const incs = Array.isArray(ctx.data.increments) && ctx.data.increments.length
            ? ctx.data.increments : [250, 500];
        form.addEventListener('click', (e) => {
            const add = e.target.closest('.ld-goal-add');
            if (add) {
                e.preventDefault();
                logGoal(ctx, Number(add.dataset.delta), add);
                return;
            }
            const undo = e.target.closest('.ld-goal-undo');
            if (!undo) return;
            e.preventDefault();
            const stack = goalUndo.get(ctx.widget.id) || [];
            const delta = stack.length ? -stack[stack.length - 1] : -(Number(incs[0]) || 250);
            logGoal(ctx, delta, undo);
        });
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            const input = form.querySelector('input');
            const raw = Number(input.value);
            if (!(raw > 0)) {
                status('Enter an amount.', 'error');
                return;
            }
            logGoal(ctx, raw, form.querySelector('[type=submit]')).then(() => {
                if (input.isConnected) input.value = '';
            });
        });
    }

    function goal(ctx) {
        const d = ctx.data;
        if (d.error) return errorBox(ctx, d.error);
        tools(ctx, '');
        const unit = d.metric.unit ? ' ' + d.metric.unit : '';
        const log = goalLogForm(ctx);
        if (d.view === 'week') {
            const cells = (d.days || []).map((day) => {
                const cls = [
                    'ld-goal-cell',
                    day.today ? 'is-today' : '',
                    day.future ? 'is-future' : '',
                    day.ratio > 1 ? 'is-over' : '',
                ].filter(Boolean).join(' ');
                const shown = day.value === null || day.future ? '—' : `${day.percent}%`;
                const label = day.value === null
                    ? `${day.label} no entry`
                    : `${day.label} ${fmt.value(d.metric, day.value)}, ${day.percent}% of goal`;
                return `<div class="${cls}" style="${goalPaint(day.ratio)}" role="img" aria-label="${esc(label)}">
                    <span class="ld-goal-fill"></span>
                    <span class="ld-goal-dow">${esc(day.label)}</span>
                    <span class="ld-goal-cap">${esc(shown)}</span>
                </div>`;
            }).join('');
            tools(ctx, '<span class="ld-sub">0 empty · 100% full · 200%</span>');
            ctx.body.innerHTML = `<div class="ld-goal"><div class="ld-goal-week">${cells}</div>${log}</div>`;
            bindGoalLog(ctx);
            return;
        }
        const shown = d.value === null ? '—' : fmt.value(d.metric, d.value);
        const over = d.ratio > 1 ? ' is-over' : '';
        ctx.body.innerHTML = `
            <div class="ld-goal">
            <div class="ld-goal-day">
                <div class="ld-goal-tank${over}" style="${goalPaint(d.ratio)}" role="img"
                     aria-label="${esc(d.metric.name)} ${esc(shown)}, ${d.percent}% of ${esc(fmt.number(d.goal, 0))}${esc(unit)}">
                    <span class="ld-goal-fill"></span>
                </div>
                <div class="ld-goal-readout">
                    <div class="ld-stat-value">${esc(shown)}</div>
                    <div class="ld-sub">${d.percent}% of ${esc(fmt.number(d.goal, 0))}${esc(unit)}</div>
                </div>
            </div>
            ${log}
            </div>`;
        bindGoalLog(ctx);
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

    window.LD.renderers = { habits, mood, stat, chart, gauge, goal, table, streak, life, spending, networth, baby_step: babyStep, debt_snowball: debtSnowball, food_week: foodWeek, budget_actual: budgetActual, sinking_funds: sinkingFunds, bills_due: billsDue, retirement, credit, portfolio, pages, youtube: carousel, reddit: carousel, x: carousel, reddit_feed: redditFeed, custom };
    window.LD.charts = { drawLineChart, drawBarChart, extent };
})();
