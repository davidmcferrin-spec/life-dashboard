/**
 * lifedash-grid.js — the dashboard widget grid.
 *
 *   - Canonical layout is 12 columns, stored on dashboard_widgets. The grid
 *     shows 12 columns under 1600px, 18 from 1600 to 3439, and 24 from 3440.
 *     Under 700px it is one column. Columns are a share of the grid (fluid)
 *     until a cap: 1600px at 12 columns, 2400px at 18, 3200px at 24. Past
 *     the cap the grid stops growing and sits in the center with equal
 *     margins. The cap is a user setting (data-grid-cap), on by default.
 *     A wider window adds columns and flows more cards onto each row.
 *     Crossing a boundary waits out a 120px band and a short pause.
 *     Cards keep their column count and their reading order. A short row is centered.
 *     Inside a breakpoint the column count stays put, so a window resize
 *     only grows columns until the cap, then only the side margins change.
 *     A phone reorder is saved as a 12-column flow of the desktop widths,
 *     never as a 1-column layout.
 *   - A drag or resize on 18 or 24 columns is saved for that width only;
 *     the 12-column x/y/w stay put.
 *   - Unlocked widgets size to their content in place. Extra rows open
 *     below that card and every card under it shifts down together, so a
 *     neighbor never changes column or swaps rows. A resize sets
 *     height_locked so that height stays. The Fit button clears it and packs.
 *   - Rows are 32px. Width snaps to one column of the current grid; top-row
 *     cards can step down to 2 columns.
 *   - Layout is saved debounced. Automatic fitting runs when content
 *     arrives and when the column count changes. It does not run while the
 *     window stays inside a breakpoint, and it does not write positions.
 *   - Native widgets render from /api/v1/widgets/{id}/data via LD.renderers;
 *     legacy widgets are server-rendered partials with their own scripts.
 *   - LD.grid.handleEvent() receives realtime events from lifedash-realtime.js.
 */
'use strict';

(function () {
    const { api, esc, status, debounce } = window.LD;
    const GS = window.GridStack;

    const boardEl = document.querySelector('.ld-board');
    const gridEl = document.getElementById('ld-grid');
    if (!boardEl || !gridEl || !GS) return;

    const COLUMNS = 12;
    const WIDE = [18, 24];
    const BREAK_NARROW = 700;
    const BREAK_WIDE = 1600;
    const BREAK_ULTRA = 3440;
    const HYSTERESIS = 120;
    /** Keep in step with WidgetService::GRID_MAX_WIDTH. */
    const GRID_MAX = { 12: 1600, 18: 2400, 24: 3200 };
    const capEnabled = gridEl.dataset.gridCap !== '0';

    // Keep in step with WidgetService::columnsForWidth.
    function columnsForWidth(width, previous) {
        if (previous === 1 && width < BREAK_NARROW + HYSTERESIS) return 1;
        if (previous === 24 && width >= BREAK_ULTRA - HYSTERESIS) return 24;
        if (previous === 18 && width < BREAK_ULTRA && width >= BREAK_WIDE - HYSTERESIS) return 18;
        if (previous === COLUMNS && width > BREAK_NARROW && width < BREAK_WIDE) return COLUMNS;
        if (width <= BREAK_NARROW) return 1;
        if (width < BREAK_WIDE) return COLUMNS;
        if (width < BREAK_ULTRA) return 18;
        return 24;
    }

    /** id → { widget, data, el, body, tools, loading, queued } */
    const widgets = new Map();
    let editing = false;
    let typesCache = null;
    let metricsCache = null;
    /** Column count last projected from the canonical (or saved wide) layout. */
    let projectedCols = 0;

    const initialEl = document.getElementById('ld-initial');
    const initial = initialEl ? JSON.parse(initialEl.textContent || '[]') : [];
    const layoutsEl = document.getElementById('ld-layouts');
    const savedLayouts = {};
    try {
        const raw = layoutsEl ? JSON.parse(layoutsEl.textContent || '{}') : {};
        if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
            Object.keys(raw).forEach((k) => { savedLayouts[Number(k)] = raw[k]; });
        }
    } catch (e) { /* project from the 12-column layout */ }

    let gridApi = null;

    /**
     * GridStack calls this with (newCols, oldCols, placed, remaining).
     * Nodes left in `remaining` are dropped, and a cached wider layout is
     * restored into `placed` before we run, so rewrite both lists from the
     * canonical records. Mirrors WidgetService::projectColumns.
     */
    function projectLayout(newCols, _oldCols, placed, remaining) {
        const all = placed.concat(remaining);
        placed.length = 0;
        remaining.length = 0;
        if (gridApi && gridApi.engine) gridApi.engine._layouts = [];
        const pos = positionsFor(all, newCols);
        const byId = new Map(pos.map((p) => [Number(p.id), p]));
        all.forEach((n) => {
            const fittedH = n.h;
            const p = byId.get(Number(n.id));
            if (p) {
                n.x = p.x;
                n.y = p.y;
                n.w = p.w;
                // Keep a content-sized height. commitPositions opens rows
                // under it; resetting h here would collapse that and overlap.
                n.h = fittedH || p.h;
            }
            placed.push(n);
        });
        projectedCols = newCols;
    }

    const grid = GS.init({
        column: COLUMNS,
        cellHeight: 32,
        margin: 6,
        float: true,
        animate: false,
        staticGrid: true,
        handle: '.ld-drag',
        resizable: { handles: 'e,se,s,sw,w' },
    }, gridEl);
    gridApi = grid;

    // -----------------------------------------------------------------
    // Widget registry + rendering
    // -----------------------------------------------------------------
    function register(widget, el) {
        const entry = {
            widget,
            data: null,
            el,
            body: el.querySelector('.ld-w-body'),
            tools: el.querySelector('.ld-w-tools'),
            loading: false,
            queued: false,
        };
        widgets.set(widget.id, entry);
        if (entry.body) {
            observeResize(entry);
            loadData(widget.id);
        }
        return entry;
    }

    function isNative(type) {
        return !type.startsWith('legacy.');
    }

    async function loadData(id) {
        const entry = widgets.get(id);
        if (!entry || !entry.body) return;
        if (entry.loading) {
            entry.queued = true; // coalesce bursts of realtime events
            return;
        }
        entry.loading = true;
        try {
            const res = await api.get(`/widgets/${id}/data`);
            entry.data = res.data;
            render(entry);
        } catch (e) {
            if (e.status === 404) {
                removeLocal(id);
                return;
            }
            entry.body.innerHTML = `<p class="ld-error">${esc(e.message)}</p>`;
        } finally {
            entry.loading = false;
            if (entry.queued) {
                entry.queued = false;
                loadData(id);
            }
        }
    }

    function render(entry, opts) {
        const renderer = window.LD.renderers[entry.widget.type];
        if (!renderer || !entry.data) return;
        const title = entry.el.querySelector('.ld-w-title');
        if (title) title.textContent = entry.widget.title || entry.widget.label;
        try {
            renderer({
                body: entry.body,
                tools: entry.tools,
                widget: entry.widget,
                data: entry.data,
                refresh: () => loadData(entry.widget.id),
            });
        } catch (e) {
            console.error('[lifedash] render failed', entry.widget, e);
            entry.body.innerHTML = '<p class="ld-error">This widget could not be displayed.</p>';
        }
        // A chart redraws when its box changes size. That is not new content,
        // and refitting here would move rows during a window resize.
        if (!opts || opts.fit !== false) scheduleFit();
    }

    // Charts are drawn at pixel size: redraw when the widget resizes.
    const ro = 'ResizeObserver' in window ? new ResizeObserver(debounce((items) => {
        items.forEach((it) => {
            const id = Number(it.target.closest('.grid-stack-item')?.dataset.widgetId);
            const entry = widgets.get(id);
            if (entry && entry.data && ['chart', 'mood'].includes(entry.widget.type)) render(entry, { fit: false });
        });
    }, 120)) : null;

    function observeResize(entry) {
        if (ro && ['chart', 'mood'].includes(entry.widget.type)) ro.observe(entry.body);
    }

    function nativeItemHtml(w) {
        return `
            <div class="grid-stack-item-content surface ld-w ld-w-native">
                <div class="ld-fit">
                <div class="ld-edit-bar ld-edit-only">
                    <span class="ld-drag" title="Drag to move" aria-hidden="true">⋮⋮</span>
                    <button type="button" class="ld-icon-btn" data-action="fit" title="Size to content" aria-label="Size to content">Fit</button>
                    <button type="button" class="ld-icon-btn" data-action="configure" title="Configure" aria-label="Configure widget">⚙</button>
                    <button type="button" class="ld-icon-btn ld-danger" data-action="remove" title="Remove" aria-label="Remove widget">✕</button>
                </div>
                <div class="ld-w-head dashboard-widget-head">
                    <h2 class="section-title ld-w-title">${esc(w.title || w.label)}</h2>
                    <div class="ld-w-tools"></div>
                </div>
                <div class="ld-w-body"><div class="empty-state mono text-xs">Loading…</div></div>
                </div>
            </div>`;
    }

    /** Add a native widget that exists server-side but not yet on this page. */
    function addLocal(w, flash) {
        if (widgets.has(w.id)) return;
        const el = document.createElement('div');
        el.className = 'grid-stack-item';
        el.dataset.widgetId = String(w.id);
        el.dataset.widgetType = w.type;
        el.innerHTML = nativeItemHtml(w);
        gridEl.appendChild(el);
        grid.makeWidget(el, { id: String(w.id), x: w.x, y: w.y, w: w.w, h: w.h, minW: w.min_w, minH: w.min_h });
        register(w, el);
        if (grid.getColumn() !== 1) projectInPlace();
        document.getElementById('ld-empty')?.remove();
        if (flash) {
            el.classList.add('ld-flash');
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            setTimeout(() => el.classList.remove('ld-flash'), 1300);
        }
    }

    function removeLocal(id) {
        const entry = widgets.get(id);
        if (!entry) return;
        if (ro && entry.body) ro.unobserve(entry.body);
        widgets.delete(id);
        grid.removeWidget(entry.el, true, false);
    }

    // -----------------------------------------------------------------
    // Edit mode + layout persistence
    // -----------------------------------------------------------------
    const editBtn = document.getElementById('ld-edit');

    function setEditing(on) {
        editing = on;
        boardEl.classList.toggle('ld-editing', on);
        grid.setStatic(!on);
        editBtn.textContent = on ? 'Done' : 'Edit layout';
        editBtn.setAttribute('aria-pressed', String(on));
        if (!on) {
            saveLayout.flush();
            saveWide.flush();
            saveHeights.flush();
            scheduleFit();
        }
    }

    editBtn.addEventListener('click', () => setEditing(!editing));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && editing && !document.querySelector('.app-modal:not(.hidden)')) setEditing(false);
    });

    function layoutNodes() {
        return grid.engine.nodes.filter((n) => n.id !== undefined && widgets.has(Number(n.id)));
    }

    function desktopItems() {
        return layoutNodes().map((n) => {
            const w = widgets.get(Number(n.id)).widget;
            return {
                id: Number(n.id), x: n.x, y: n.y, w: n.w, h: n.h,
                height_locked: w.height_locked ? 1 : 0,
            };
        });
    }

    function canonicalOf(node) {
        const w = widgets.get(Number(node.id))?.widget;
        if (w) return { id: Number(w.id), x: w.x, y: w.y, w: w.w, h: w.h };
        return {
            id: Number(node.id),
            x: node.x || 0,
            y: node.y || 0,
            w: node.w || 1,
            h: node.h || 1,
        };
    }

    function rectsOverlap(a, b) {
        return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
    }

    // Mirrors WidgetService::projectColumns / centerOccupied.
    function projectColumns(items, columns) {
        const from = COLUMNS;
        const nodes = items.slice().sort((a, b) => a.y - b.y || a.x - b.x || (a.id || 0) - (b.id || 0));
        if (columns === from) {
            return centerOccupied(nodes.map((it) => ({
                ...it,
                x: Math.max(0, it.x | 0),
                y: Math.max(0, it.y | 0),
                w: Math.max(1, it.w | 0),
                h: Math.max(1, Math.min(24, it.h | 0)),
            })), columns);
        }
        let cursorX = 0;
        let cursorY = 0;
        let rowH = 0;
        const flowed = nodes.map((it) => {
            const w = Math.max(1, Math.min(columns, it.w | 0));
            const h = Math.max(1, Math.min(24, it.h | 0));
            if (cursorX > 0 && cursorX + w > columns) {
                cursorY += rowH;
                cursorX = 0;
                rowH = 0;
            }
            const placed = { ...it, x: cursorX, y: cursorY, w, h };
            cursorX += w;
            rowH = Math.max(rowH, h);
            return placed;
        });
        return centerOccupied(flowed, columns);
    }

    function centerOccupied(nodes, columns) {
        const groups = new Map();
        nodes.forEach((it, i) => {
            const y = it.y | 0;
            if (!groups.has(y)) groups.set(y, []);
            groups.get(y).push(i);
        });
        groups.forEach((idxs) => {
            const min = Math.min(...idxs.map((i) => nodes[i].x));
            const max = Math.max(...idxs.map((i) => nodes[i].x + nodes[i].w));
            const span = max - min;
            if (span <= 0 || span >= columns) return;
            const offset = Math.floor((columns - span) / 2) - min;
            if (!offset) return;
            const moved = new Map();
            idxs.forEach((i) => moved.set(i, { ...nodes[i], x: nodes[i].x + offset }));
            const hit = nodes.some((other, j) => {
                if (moved.has(j)) return false;
                return [...moved.values()].some((trial) => rectsOverlap(trial, other));
            });
            if (hit) return;
            moved.forEach((trial, i) => { nodes[i] = trial; });
        });
        return nodes;
    }

    function savedCovering(cols, ids) {
        const saved = savedLayouts[cols];
        if (!Array.isArray(saved) || !saved.length) return null;
        const have = new Set(saved.map((s) => Number(s.id)));
        if (!ids.every((id) => have.has(Number(id)))) return null;
        return saved;
    }

    function positionsFor(nodes, cols) {
        const canon = nodes.filter((n) => n.id !== undefined).map(canonicalOf);
        const saved = cols === COLUMNS ? null : savedCovering(cols, canon.map((c) => c.id));
        if (saved) {
            const byId = new Map(saved.map((s) => [Number(s.id), s]));
            return canon.map((c) => {
                const s = byId.get(c.id);
                return { id: c.id, x: s.x, y: s.y, w: s.w, h: c.h };
            });
        }
        return projectColumns(canon, cols);
    }

    function writeNode(node) {
        grid._writePosAttr(node.el, node);
    }

    /**
     * Paint the projected columns without GridStack's collision solver.
     * A card taller than its saved height opens that many rows at its saved
     * bottom; every card that starts there or lower moves down by the same
     * amount. x and w stay on the projection, so order does not change.
     */
    function commitPositions() {
        const cols = grid.getColumn();
        if (cols === 1 || !grid.engine) return;
        grid.engine._layouts = [];
        const nodes = grid.engine.nodes.filter((n) => n.id !== undefined);
        const base = positionsFor(nodes, cols);
        const byId = new Map(base.map((p) => [Number(p.id), p]));
        const inserts = [];
        base.forEach((b) => {
            const node = nodes.find((n) => Number(n.id) === Number(b.id));
            const h = node ? node.h : b.h;
            const delta = h - b.h;
            if (delta > 0) inserts.push({ atY: b.y + b.h, delta });
        });
        nodes.forEach((n) => {
            const b = byId.get(Number(n.id));
            if (!b) return;
            let shift = 0;
            inserts.forEach((ins) => {
                if (b.y >= ins.atY) shift += ins.delta;
            });
            n.x = b.x;
            n.y = b.y + shift;
            n.w = b.w;
            writeNode(n);
        });
        grid._updateContainerHeight();
    }

    function projectInPlace() {
        commitPositions();
    }

    function sameLayout(items) {
        return items.every((it) => {
            const w = widgets.get(it.id)?.widget;
            return w && w.x === it.x && w.y === it.y && w.w === it.w && w.h === it.h
                && (w.height_locked ? 1 : 0) === (it.height_locked ? 1 : 0);
        });
    }

    function applyHeight(entry, it) {
        entry.widget.h = it.h;
        if (it.height_locked !== undefined) entry.widget.height_locked = it.height_locked ? 1 : 0;
    }

    async function postLayout(items, opts) {
        opts = opts || {};
        if (!items.length) return;
        if (opts.columns) {
            if (sameAsSaved(opts.columns, items)) return;
        } else if (opts.heightsOnly) {
            if (sameHeights(items)) return;
        } else if (!opts.pack && sameLayout(items)) return;
        try {
            status('Saving…', 'sticky');
            const body = { items };
            if (opts.pack) body.pack = true;
            if (opts.heightsOnly) body.heights_only = true;
            if (opts.columns) body.columns = opts.columns;
            const res = await api.post('/layout', body);
            const saved = res.items || items;
            if (opts.columns || opts.heightsOnly) {
                if (opts.columns) {
                    savedLayouts[opts.columns] = saved.map((it) => ({
                        id: it.id, x: it.x, y: it.y, w: it.w, h: it.h,
                    }));
                }
                saved.forEach((it) => {
                    const entry = widgets.get(it.id);
                    if (entry) applyHeight(entry, it);
                });
            } else {
                let geometry = false;
                saved.forEach((it) => {
                    const entry = widgets.get(it.id);
                    if (!entry) return;
                    const w = entry.widget;
                    if (w.x !== it.x || w.y !== it.y || w.w !== it.w) geometry = true;
                    Object.assign(w, {
                        x: it.x, y: it.y, w: it.w, h: it.h,
                        height_locked: it.height_locked === undefined ? w.height_locked : (it.height_locked ? 1 : 0),
                    });
                });
                if (geometry) {
                    WIDE.forEach((c) => { delete savedLayouts[c]; });
                }
            }
            status('Layout saved');
        } catch (e) {
            status(`Layout not saved: ${e.message}`, 'error');
        }
    }

    const saveLayout = debounce(() => {
        if (grid.getColumn() !== COLUMNS) return;
        postLayout(desktopItems(), {});
    }, 600);

    const saveWide = debounce(() => {
        const cols = grid.getColumn();
        if (!WIDE.includes(cols)) return;
        const items = layoutNodes().map((n) => {
            const w = widgets.get(Number(n.id)).widget;
            return {
                id: Number(n.id), x: n.x, y: n.y, w: n.w, h: n.h,
                height_locked: w.height_locked ? 1 : 0,
            };
        });
        postLayout(items, { columns: cols });
    }, 600);

    // Heights are shared across breakpoints. Column positions stay on the
    // canonical row (or the saved wide arrangement) and are not written here.
    const saveHeights = debounce(() => {
        if (grid.getColumn() === 1) return;
        postLayout(heightItems(), { heightsOnly: true });
    }, 600);

    function heightItems() {
        return layoutNodes().map((n) => {
            const w = widgets.get(Number(n.id)).widget;
            return { id: Number(n.id), h: n.h, height_locked: w.height_locked ? 1 : 0 };
        });
    }

    function sameHeights(items) {
        return items.every((it) => {
            const w = widgets.get(it.id)?.widget;
            return w && w.h === it.h && (w.height_locked ? 1 : 0) === it.height_locked;
        });
    }

    function sameAsSaved(cols, items) {
        const saved = savedLayouts[cols];
        if (!Array.isArray(saved) || saved.length !== items.length) return false;
        const byId = new Map(saved.map((s) => [Number(s.id), s]));
        return items.every((it) => {
            const s = byId.get(it.id);
            const w = widgets.get(it.id)?.widget;
            return s && w && s.x === it.x && s.y === it.y && s.w === it.w && s.h === it.h
                && (w.height_locked ? 1 : 0) === (it.height_locked ? 1 : 0);
        });
    }

    // Phone reorder: keep each widget's desktop width and flow them in the
    // visual order. The 1-column x/w is never written.
    function saveMobileOrder() {
        const items = layoutNodes()
            .slice()
            .sort((a, b) => a.y - b.y || a.x - b.x)
            .map((n) => {
                const w = widgets.get(Number(n.id)).widget;
                return { id: Number(n.id), w: w.w, h: n.h, height_locked: w.height_locked ? 1 : 0 };
            });
        postLayout(items, { pack: true });
    }

    grid.on('change', () => {
        if (!editing || grid.isIgnoreChangeCB()) return;
        const cols = grid.getColumn();
        if (cols === COLUMNS) saveLayout();
        else if (WIDE.includes(cols)) saveWide();
    });

    grid.on('resizestart', (_e, el) => {
        el.classList.add('ld-h-lock');
        const entry = widgets.get(Number(el.dataset.widgetId));
        if (entry) entry.widget.height_locked = 1;
    });

    grid.on('dragstop', () => {
        if (editing && grid.getColumn() === 1) saveMobileOrder();
    });

    grid.on('resizestop', () => {
        if (grid.getColumn() === 1) saveMobileOrder();
    });

    // -----------------------------------------------------------------
    // Remove (two-step confirm, no browser dialog) + configure
    // -----------------------------------------------------------------
    gridEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn || !editing) return;
        const id = Number(btn.closest('.grid-stack-item')?.dataset.widgetId);
        if (!widgets.has(id)) return;

        if (btn.dataset.action === 'fit') {
            const el = btn.closest('.grid-stack-item');
            el.classList.remove('ld-h-lock');
            widgets.get(id).widget.height_locked = 0;
            fitItem(el);
            const cols = grid.getColumn();
            if (cols === 1) {
                saveMobileOrder();
            } else if (WIDE.includes(cols)) {
                packUp();
                saveWide();
            } else {
                packUp();
                saveLayout();
            }
        } else if (btn.dataset.action === 'configure') {
            openConfig(widgets.get(id).widget);
        } else if (btn.dataset.action === 'remove') {
            if (btn.dataset.armed !== '1') {
                btn.dataset.armed = '1';
                btn.textContent = 'Remove?';
                setTimeout(() => {
                    if (btn.isConnected) {
                        btn.dataset.armed = '';
                        btn.textContent = '✕';
                    }
                }, 3000);
                return;
            }
            api.post(`/widgets/${id}/delete`, {})
                .then(() => { removeLocal(id); status('Widget removed'); })
                .catch((err) => status(err.message, 'error'));
        }
    });

    // -----------------------------------------------------------------
    // Modals
    // -----------------------------------------------------------------
    function openModal(id) {
        document.getElementById(id).classList.remove('hidden');
        document.body.classList.add('app-modal-open');
    }

    function closeModal(id) {
        document.getElementById(id).classList.add('hidden');
        if (!document.querySelector('.app-modal:not(.hidden)')) document.body.classList.remove('app-modal-open');
    }

    ['ld-add-modal', 'ld-config-modal'].forEach((id) => {
        const m = document.getElementById(id);
        m?.addEventListener('click', (e) => {
            if (e.target.closest('[data-close]')) closeModal(id);
        });
        m?.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal(id);
        });
    });

    async function types() {
        if (!typesCache) typesCache = (await api.get('/widget-types')).types;
        return typesCache;
    }

    async function metrics() {
        if (!metricsCache) metricsCache = (await api.get('/metrics')).metrics;
        return metricsCache;
    }

    function needsSetup(t) {
        return Object.values(t.fields || {}).some((f) =>
            (f.type === 'metric' && f.required) || (f.type === 'metrics' && (f.min_items || 0) > 0) || f.setup);
    }

    // ---- Add widget --------------------------------------------------
    document.getElementById('ld-add')?.addEventListener('click', async () => {
        const list = document.getElementById('ld-add-list');
        list.innerHTML = '<p class="empty-state mono text-xs">Loading…</p>';
        openModal('ld-add-modal');
        try {
            const all = await types();
            const present = new Set([...widgets.values()].map((e) => e.widget.type));
            const groups = {
                tracking: 'Tracking', data: 'Data', life: 'Life', money: 'Money',
                knowledge: 'Knowledge', media: 'Media', custom: 'Custom', dashboard: 'Dashboard',
            };
            list.innerHTML = Object.entries(groups).map(([cat, label]) => {
                const items = all.filter((t) => t.category === cat);
                if (!items.length) return '';
                return `<div class="ld-type-group">${esc(label)}</div>` + items.map((t) => {
                    const taken = t.singleton && present.has(t.type);
                    return `<button type="button" class="ld-type" data-type="${esc(t.type)}" ${taken ? 'disabled' : ''}>
                        <strong>${esc(t.label)}</strong><span>${esc(taken ? 'Already on the dashboard' : t.description)}</span></button>`;
                }).join('');
            }).join('');
        } catch (e) {
            list.innerHTML = `<p class="ld-error">${esc(e.message)}</p>`;
        }
    });

    document.getElementById('ld-add-list')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('.ld-type');
        if (!btn || btn.disabled) return;
        const t = (await types()).find((x) => x.type === btn.dataset.type);
        if (!t) return;
        closeModal('ld-add-modal');
        if (needsSetup(t)) {
            openConfig(null, t);
        } else {
            await createWidget(t, null, {});
        }
    });

    async function createWidget(t, title, config) {
        const res = await api.post('/widgets', { type: t.type, title, config });
        if (t.type.startsWith('legacy.')) {
            // Legacy widgets are server-rendered with page-level scripts.
            status('Added — reloading…', 'sticky');
            window.location.reload();
            return res.widget;
        }
        addLocal(res.widget, true);
        status('Widget added');
        return res.widget;
    }

    // ---- Configure (and create-with-config) ---------------------------
    let configTarget = null; // { widget } for edit, { type } for create

    async function openConfig(widget, typeForCreate) {
        const form = document.getElementById('ld-config-form');
        const fieldsEl = document.getElementById('ld-config-fields');
        const errEl = document.getElementById('ld-config-error');
        errEl.classList.add('hidden');
        fieldsEl.innerHTML = '<p class="empty-state mono text-xs">Loading…</p>';
        switchTab('settings');
        document.getElementById('ld-config-tabs').style.display = widget ? '' : 'none';
        openModal('ld-config-modal');

        try {
            const all = await types();
            const t = typeForCreate || all.find((x) => x.type === widget.type);
            if (!t) throw new Error('Unknown widget type.');
            configTarget = { widget, type: t };
            document.getElementById('ld-config-title').textContent = widget ? `Configure ${t.label}` : `Add ${t.label}`;
            document.getElementById('ld-config-save').textContent = widget ? 'Save' : 'Add widget';
            const ms = await metrics();
            fieldsEl.innerHTML = fieldHtml('title', { type: 'string', label: 'Title (optional)', max: 120 }, widget ? widget.title : '', ms, t.label)
                + Object.entries(t.fields || {}).map(([name, f]) => {
                    let value = widget ? widget.config[name] : f.default;
                    if (widget && t.type === 'goal' && name === 'metric_ids' && (!value || !value.length) && widget.config.metric_id) {
                        value = [widget.config.metric_id];
                    }
                    return fieldHtml(name, f, value, ms);
                }).join('');
            if (t.type === 'goal') bindGoalLogFields(ms, widget ? widget.config : {});
            form.querySelector('input, select')?.focus();
        } catch (e) {
            fieldsEl.innerHTML = `<p class="ld-error">${esc(e.message)}</p>`;
        }
    }

    function fieldHtml(name, f, value, ms, placeholder) {
        const id = `ld-f-${name}`;
        const label = `<label for="${id}">${esc(f.label || name)}</label>`;
        const err = `<div class="ld-field-error hidden" data-error-for="${esc(name)}"></div>`;
        const kindsOk = (m) => !m.archived && (!f.kinds || f.kinds.includes(m.kind));

        switch (f.type) {
            case 'int':
                return `<div class="ld-field">${label}<input id="${id}" class="input text-sm" type="number" name="${esc(name)}"
                    min="${f.min}" max="${f.max}" step="1" value="${esc(value ?? '')}">${err}</div>`;
            case 'enum':
                return `<div class="ld-field">${label}<select id="${id}" class="input text-sm" name="${esc(name)}">${
                    Object.entries(f.options).map(([k, v]) => `<option value="${esc(k)}" ${String(value) === k ? 'selected' : ''}>${esc(v)}</option>`).join('')
                }</select>${err}</div>`;
            case 'bool':
                return `<div class="ld-field"><label><input type="checkbox" name="${esc(name)}" ${value ? 'checked' : ''}> ${esc(f.label || name)}</label>${err}</div>`;
            case 'metric': {
                const opts = ms.filter(kindsOk);
                const empty = f.required ? '<option value="">Choose…</option>' : '<option value="">Default</option>';
                const hint = opts.length ? '' : '<div class="ld-sub">No matching metrics yet — create one under <a class="link" href="/settings/trackers">Habits &amp; trackers</a>.</div>';
                return `<div class="ld-field">${label}<select id="${id}" class="input text-sm" name="${esc(name)}">${empty}${
                    opts.map((m) => `<option value="${m.id}" ${Number(value) === m.id ? 'selected' : ''}>${esc(m.name)} (${esc(m.kind)})</option>`).join('')
                }</select>${hint}${err}</div>`;
            }
            case 'metrics': {
                const selected = new Set((value || []).map(Number));
                const opts = ms.filter(kindsOk);
                return `<div class="ld-field"><span>${esc(f.label || name)}</span><div class="ld-checks" data-multi="${esc(name)}">${
                    opts.length ? opts.map((m) => `<label><input type="checkbox" value="${m.id}" ${selected.has(m.id) ? 'checked' : ''}> ${esc(m.name)} <span class="text-faint mono text-xs">${esc(m.kind)}</span></label>`).join('')
                        : '<p class="empty-state mono text-xs">No metrics yet.</p>'
                }</div>${err}</div>`;
            }
            case 'goal_logs':
                return `<div class="ld-field"><span>${esc(f.label || name)}</span><div class="ld-goal-log-cfg" data-goal-logs></div>${err}</div>`;
            default: {
                const ph = esc(placeholder || f.placeholder || '');
                if ((f.max || 255) > 2000) {
                    return `<div class="ld-field">${label}<textarea id="${id}" class="input text-sm ld-markup" name="${esc(name)}" maxlength="${f.max}" rows="6" placeholder="${ph}">${esc(value ?? '')}</textarea>${err}</div>`;
                }
                return `<div class="ld-field">${label}<input id="${id}" class="input text-sm" type="text" name="${esc(name)}"
                    maxlength="${f.max || 255}" value="${esc(value ?? '')}" placeholder="${ph}" autocomplete="off">${err}</div>`;
            }
        }
    }

    function inferGoalMode(metric) {
        const unit = String(metric?.unit || '').trim().toLowerCase();
        const key = String(metric?.key || '');
        if (['session', 'sessions', 'count', 'times'].includes(unit)) return 'count';
        if (key === 'movement_sessions' || key.endsWith('_sessions') || key.endsWith('_count')) return 'count';
        if (unit) return 'amount';
        const goal = Number(metric?.config?.goal);
        if (Number.isInteger(goal) && goal >= 1 && goal <= 12) return 'count';
        return 'amount';
    }

    function bindGoalLogFields(ms, saved) {
        const box = document.querySelector('[data-multi="metric_ids"]');
        const host = document.querySelector('[data-goal-logs]');
        if (!box || !host) return;
        const logs = saved.logs || {};
        const legacyId = saved.metric_id ? String(saved.metric_id) : '';
        const paint = () => {
            const typed = {};
            host.querySelectorAll('[data-log-for]').forEach((row) => {
                typed[row.dataset.logFor] = {
                    mode: row.querySelector('[data-log-mode]')?.value,
                    increments: row.querySelector('[data-log-inc]')?.value || '',
                    dedupe_prefix: row.querySelector('[data-log-prefix]')?.value || '',
                };
            });
            const checked = [...box.querySelectorAll('input:checked')].map((i) => i.value);
            host.innerHTML = checked.map((id) => {
                const metric = ms.find((m) => String(m.id) === String(id)) || { id, name: id };
                const savedEntry = typed[id] || logs[id] || logs[String(Number(id))] || {};
                if (!savedEntry.increments && String(id) === legacyId && saved.increments) {
                    savedEntry.increments = saved.increments;
                }
                if (!savedEntry.dedupe_prefix && String(id) === legacyId && saved.dedupe_prefix) {
                    savedEntry.dedupe_prefix = saved.dedupe_prefix;
                }
                const mode = savedEntry.mode || inferGoalMode(metric);
                const incs = savedEntry.increments || (mode === 'count' ? '1' : '250,500');
                return `<div class="ld-goal-log-row" data-log-for="${esc(id)}">
                    <span>${esc(metric.name || id)}</span>
                    <select class="input text-sm" data-log-mode>
                        <option value="amount" ${mode === 'amount' ? 'selected' : ''}>Amount</option>
                        <option value="count" ${mode === 'count' ? 'selected' : ''}>Count</option>
                    </select>
                    <input class="input text-sm" data-log-inc value="${esc(incs)}" placeholder="250,500" aria-label="Quick-add amounts for ${esc(metric.name || id)}" ${mode === 'count' ? 'hidden' : ''}>
                    <input class="input text-sm" data-log-prefix value="${esc(savedEntry.dedupe_prefix || '')}" placeholder="key prefix" maxlength="40" aria-label="Daily key prefix for ${esc(metric.name || id)}">
                </div>`;
            }).join('') || '<p class="empty-state mono text-xs">Choose 1–4 metrics.</p>';
            host.querySelectorAll('[data-log-mode]').forEach((sel) => {
                sel.addEventListener('change', () => {
                    const row = sel.closest('[data-log-for]');
                    const inc = row.querySelector('[data-log-inc]');
                    const count = sel.value === 'count';
                    inc.hidden = count;
                    if (count && inc.value.trim() === '') inc.value = '1';
                    if (!count && (inc.value.trim() === '' || inc.value.trim() === '1')) inc.value = '250,500';
                });
            });
        };
        box.addEventListener('change', paint);
        paint();
    }

    function readConfigForm(t) {
        const form = document.getElementById('ld-config-form');
        const config = {};
        Object.entries(t.fields || {}).forEach(([name, f]) => {
            if (f.type === 'metrics') {
                config[name] = [...form.querySelectorAll(`[data-multi="${name}"] input:checked`)].map((i) => Number(i.value));
                return;
            }
            if (f.type === 'goal_logs') {
                const logs = {};
                form.querySelectorAll('[data-log-for]').forEach((row) => {
                    const id = row.dataset.logFor;
                    const entry = { mode: row.querySelector('[data-log-mode]')?.value || 'amount' };
                    const inc = row.querySelector('[data-log-inc]');
                    if (inc && inc.value.trim() !== '') entry.increments = inc.value.trim();
                    const pre = row.querySelector('[data-log-prefix]');
                    if (pre && pre.value.trim() !== '') entry.dedupe_prefix = pre.value.trim();
                    logs[id] = entry;
                });
                config.logs = logs;
                return;
            }
            const input = form.elements[name];
            if (!input) return;
            if (f.type === 'bool') {
                config[name] = input.checked;
            } else if (input.value !== '') {
                config[name] = f.type === 'int' || f.type === 'metric' ? Number(input.value) : input.value;
            }
        });
        const title = form.elements.title.value.trim();
        return { title: title === '' ? null : title, config };
    }

    document.getElementById('ld-config-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!configTarget) return;
        const { widget, type } = configTarget;
        const { title, config } = readConfigForm(type);
        const errEl = document.getElementById('ld-config-error');
        document.querySelectorAll('[data-error-for]').forEach((el) => el.classList.add('hidden'));
        errEl.classList.add('hidden');

        const btn = document.getElementById('ld-config-save');
        btn.disabled = true;
        try {
            if (widget) {
                const res = await api.post(`/widgets/${widget.id}/update`, { title, config });
                applyWidget(res.widget);
                status('Widget updated');
            } else {
                await createWidget(type, title, config);
            }
            closeModal('ld-config-modal');
        } catch (err) {
            const fields = err.fields || {};
            let shown = false;
            Object.entries(fields).forEach(([path, msg]) => {
                const el = document.querySelector(`[data-error-for="${CSS.escape(path.replace(/^config\./, ''))}"]`);
                if (el) {
                    el.textContent = msg;
                    el.classList.remove('hidden');
                    shown = true;
                }
            });
            if (!shown) {
                errEl.textContent = Object.values(fields)[0] || err.message;
                errEl.classList.remove('hidden');
            }
        } finally {
            btn.disabled = false;
        }
    });

    /** Server returned a newer widget definition: re-render if config changed. */
    function applyWidget(w) {
        const entry = widgets.get(w.id);
        if (!entry) return;
        const configChanged = w.version !== entry.widget.version;
        entry.widget = Object.assign(entry.widget, w);
        entry.el.classList.toggle('ld-h-lock', !!w.height_locked);
        const title = entry.el.querySelector('.ld-w-title');
        if (title) title.textContent = w.title || w.label;
        if (configChanged && entry.body) loadData(w.id);
    }

    // ---- History / rollback -------------------------------------------
    function switchTab(tab) {
        document.querySelectorAll('#ld-config-tabs [data-tab]').forEach((b) =>
            b.classList.toggle('seg-btn-active', b.dataset.tab === tab));
        document.getElementById('ld-config-form').classList.toggle('hidden', tab !== 'settings');
        document.getElementById('ld-config-history').classList.toggle('hidden', tab !== 'history');
        if (tab === 'history') loadHistory();
    }

    document.getElementById('ld-config-tabs')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-tab]');
        if (b) switchTab(b.dataset.tab);
    });

    async function loadHistory() {
        const list = document.getElementById('ld-history-list');
        const w = configTarget && configTarget.widget;
        if (!w) return;
        list.innerHTML = '<li class="empty-state mono text-xs">Loading…</li>';
        try {
            const { revisions } = await api.get(`/widgets/${w.id}/revisions`);
            const current = widgets.get(w.id)?.widget.version;
            list.innerHTML = revisions.map((r) => `
                <li>
                    <div class="min-w-0">
                        <div>v${r.version} · ${esc(r.title || '(default title)')}</div>
                        <div class="ld-meta">${esc(r.changed_by)} · ${esc(r.created_at)} UTC${r.note ? ' · ' + esc(r.note) : ''}</div>
                    </div>
                    ${r.version === current ? '<span class="ld-meta">current</span>'
                        : `<button type="button" class="btn text-xs" data-rollback="${r.version}">Restore</button>`}
                </li>`).join('');
        } catch (e) {
            list.innerHTML = `<li class="ld-error">${esc(e.message)}</li>`;
        }
    }

    document.getElementById('ld-history-list')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-rollback]');
        const w = configTarget && configTarget.widget;
        if (!btn || !w) return;
        btn.disabled = true;
        try {
            const res = await api.post(`/widgets/${w.id}/rollback`, { version: Number(btn.dataset.rollback) });
            applyWidget(res.widget);
            status(`Restored v${btn.dataset.rollback}`);
            closeModal('ld-config-modal');
        } catch (err) {
            status(err.message, 'error');
            btn.disabled = false;
        }
    });

    // -----------------------------------------------------------------
    // Realtime hooks
    // -----------------------------------------------------------------
    function refreshAll() {
        widgets.forEach((entry, id) => {
            if (entry.body) loadData(id);
        });
    }

    function refreshTypes(types) {
        widgets.forEach((entry, id) => {
            if (entry.body && types.includes(entry.widget.type)) loadData(id);
        });
    }

    function refreshForMetric(metricId, alsoAllHabits) {
        widgets.forEach((entry, id) => {
            if (!entry.body) return;
            const d = entry.data;
            const refs = d ? d.metric_ids || [] : entry.widget.metric_refs || [];
            if (refs.includes(metricId) || (alsoAllHabits && d && d.all_habits)
                || (!d && entry.widget.type === 'mood')) {
                loadData(id);
            }
        });
    }

    async function onWidgetEvent(evt) {
        const id = Number(evt.id);
        if (evt.op === 'delete') {
            removeLocal(id);
            return;
        }
        let w;
        try {
            w = (await api.get(`/widgets/${id}`)).widget;
        } catch (e) {
            if (e.status === 404) removeLocal(id);
            return;
        }
        if (!widgets.has(id)) {
            if (isNative(w.type)) {
                addLocal(w, true);
            } else {
                status(`${w.label} was added — reload to show it.`, 'sticky');
            }
            return;
        }
        applyWidget(w);
        // Position changes from another device. Wide screens keep a saved
        // arrangement and only take the shared height; otherwise reflow.
        const entry = widgets.get(id);
        const n = entry.el.gridstackNode;
        const cols = grid.getColumn();
        if (editing || !n) return;
        if (cols === 1) return;
        n.h = w.h;
        if (WIDE.includes(cols)) {
            const ids = layoutNodes().map((node) => Number(node.id));
            if (savedCovering(cols, ids)) {
                writeNode(n);
                return;
            }
        }
        commitPositions();
    }

    function handleEvent(evt) {
        switch (evt.table) {
            case 'datapoints':
                refreshForMetric(Number(evt.metric_id), false);
                break;
            case 'metrics':
                metricsCache = null;
                refreshForMetric(Number(evt.id), true);
                break;
            case 'dashboard_widgets':
                onWidgetEvent(evt);
                break;
            case 'pages':
                refreshTypes(['pages']);
                break;
            case 'money_accounts':
            case 'money_transactions':
            case 'money_snapshots':
                refreshTypes(['spending', 'networth']);
                break;
            case 'invest_positions':
            case 'invest_activity':
                refreshTypes(['portfolio']);
                break;
            case 'shopping_lists':
            case 'shopping_items':
                refreshTypes(['shopping']);
                break;
            default:
                break;
        }
    }

    // -----------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------
    const FIT_MAX = { goal: 6, chart: 8, mood: 8, habits: 10, shopping: 12, 'legacy.news': 8, 'legacy.clipboards': 8 };

    // Cell count for the content, without GridStack moveNode (that solver
    // pushes a neighbor onto another row).
    function measureContentCells(el) {
        const node = el.gridstackNode;
        if (!node || !el.clientHeight) return node ? node.h : 1;
        const cell = grid.getCellHeight(true);
        if (!cell) return node.h;
        const box = el.querySelector('.grid-stack-item-content');
        const inner = box ? box.firstElementChild : null;
        if (!inner) return node.h;
        const pad = el.clientHeight - box.clientHeight;
        const currentInner = node.h ? node.h * cell - pad : box.clientHeight;
        const measured = inner.getBoundingClientRect().height;
        if (measured === currentInner) return node.h;
        const px = (node.h ? node.h * cell : el.clientHeight) + (measured - currentInner);
        let cells = Math.ceil(px / cell);
        const cap = FIT_MAX[el.dataset.widgetType] || 8;
        if (cells > cap) cells = cap;
        if (node.minH && cells < node.minH) cells = node.minH;
        if (node.maxH && cells > node.maxH) cells = node.maxH;
        return Math.max(1, cells);
    }

    function fitItem(el) {
        const node = el.gridstackNode;
        if (!node || el.classList.contains('ld-h-lock')) return;
        const cells = measureContentCells(el);
        if (cells !== node.h) node.h = cells;
    }

    // Gravity pack: move each widget up, keep its column. Mirrors WidgetService::packUp.
    function packUp() {
        if (grid.getColumn() === 1) return;
        const nodes = grid.engine.nodes.slice().sort((a, b) => a.y - b.y || a.x - b.x);
        nodes.forEach((n) => {
            if (n.locked) return;
            let y = n.y;
            while (y > 0 && !grid.engine.collide(n, { x: n.x, y: y - 1, w: n.w, h: n.h })) y -= 1;
            if (y !== n.y) grid.update(n.el, { y });
        });
    }

    let fitting = false;
    let fitTimer = 0;
    function scheduleFit() {
        clearTimeout(fitTimer);
        fitTimer = setTimeout(runFit, 80);
    }

    function runFit() {
        if (fitting || editing) return;
        fitting = true;
        try {
            if (grid.getColumn() === 1) return;
            const busy = [...widgets.values()].some((entry) => entry.loading);
            if (busy) return;
            grid.getGridItems().forEach(fitItem);
            commitPositions();
        } finally {
            fitting = false;
        }
    }

    initial.forEach((w) => {
        const el = gridEl.querySelector(`.grid-stack-item[data-widget-id="${w.id}"]`);
        if (!el) return;
        register(w, el);
    });

    // Scale the 12-column records onto the viewport once. Later resizes stay
    // on that column count until the pointer rests past the hysteresis band.
    /**
     * Columns stay a fraction of the grid. The grid itself stops at the
     * breakpoint cap and is centered by CSS. One column, and the uncapped
     * setting, fill the board.
     */
    function applyGridCap(cols) {
        gridEl.style.width = '';
        gridEl.classList.remove('ld-cols-fixed');
        const max = capEnabled ? GRID_MAX[cols] : 0;
        if (!max) {
            gridEl.style.maxWidth = '';
            gridEl.classList.remove('ld-cols-capped');
            return;
        }
        gridEl.style.maxWidth = max + 'px';
        gridEl.classList.add('ld-cols-capped');
    }

    function applyColumns(cols, animate) {
        if (grid.engine) grid.engine._layouts = [];
        grid.setAnimation(!!animate);
        if (cols === 1) {
            if (grid.getColumn() !== 1) grid.column(1, 'list');
        } else if (grid.getColumn() !== cols) {
            grid.column(cols, projectLayout);
        } else {
            projectInPlace();
        }
        if (cols !== 1) commitPositions();
        applyGridCap(grid.getColumn());
        projectedCols = grid.getColumn();
        // Content is already on screen, so fit in this turn. One paint, one move.
        if (animate) runFit();
        else requestAnimationFrame(() => grid.setAnimation(true));
    }

    applyColumns(columnsForWidth(window.innerWidth, null), false);

    window.addEventListener('resize', debounce(() => {
        const next = columnsForWidth(window.innerWidth, grid.getColumn());
        if (next === grid.getColumn()) {
            applyGridCap(next);
            return;
        }
        applyColumns(next, true);
    }, 220));

    requestAnimationFrame(() => scheduleFit());

    window.LD.grid = { handleEvent, refreshAll, refreshForMetric, get editing() { return editing; } };
})();
