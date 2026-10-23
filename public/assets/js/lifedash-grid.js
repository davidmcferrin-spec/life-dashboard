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
     *     Cards keep their column count and their reading order. On 12 columns
     *     a short row starts at the left edge and fills the width. A wider
     *     grid centers a short row, leftover column on the right.
 *     Inside a breakpoint the column count stays put, so a window resize
 *     only grows columns until the cap, then only the side margins change.
 *     A phone reorder is saved as a 12-column flow of the desktop widths,
 *     never as a 1-column layout. Under 700px Edit layout still works and
 *     saves that same 12-column flow.
 *     The search box sits above this grid. It is not a widget.
 *   - A drag or resize on 18 or 24 columns is saved for that width only;
 *     the 12-column x/y/w stay put.
 *   - Unlocked widgets size to their content in place. Extra rows open
 *     below that card and every unlocked card under it shifts down together,
 *     so a neighbor never changes column or swaps rows. A resize sets
 *     height_locked so that height stays. Lock freezes size and position.
 *     Fit clears both locks and packs. Adding a widget appends it under the
 *     cards already on screen.
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
    const COLUMNS = 12;
    const WIDE = [18, 24];
    const BREAK_NARROW = 700;
    const BREAK_WIDE = 1600;
    const BREAK_ULTRA = 3440;
    const HYSTERESIS = 120;
    /** Keep in step with WidgetService::GRID_MAX_WIDTH. */
    const GRID_MAX = { 12: 1600, 18: 2400, 24: 3200 };
    /** GridStack item gutter. The visible card face sits this far inside the cell. */
    const GRID_MARGIN = 6;

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

    // One inline max-width for #ld-grid and any other .ld-grid[data-grid-cap].
    // CSS only centers that box; a copied media-query width drifts from the column count.
    function applyCap(el, cols) {
        if (!el) return;
        el.style.width = '';
        el.classList.remove('ld-cols-fixed');
        const max = el.dataset.gridCap !== '0' ? (GRID_MAX[cols] || 0) : 0;
        if (!max) {
            el.style.maxWidth = '';
            el.classList.remove('ld-cols-capped');
            return;
        }
        el.style.maxWidth = max + 'px';
        el.classList.add('ld-cols-capped');
    }

    function readJson(id, fallback) {
        const el = document.getElementById(id);
        if (!el) return fallback;
        try {
            const parsed = JSON.parse(el.textContent || '');
            return parsed == null ? fallback : parsed;
        } catch (e) {
            return fallback;
        }
    }

    function bandRecords(raw) {
        if (!Array.isArray(raw)) return [];
        return raw.filter((n) => n && n.id !== undefined).map((n) => ({
            id: Number(n.id),
            x: n.x | 0,
            y: n.y | 0,
            w: n.w | 0,
            h: n.h | 0,
        }));
    }

    function bandLayouts(raw) {
        const layouts = {};
        if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return layouts;
        Object.keys(raw).forEach((k) => { layouts[Number(k)] = raw[k]; });
        return layouts;
    }

    // Union of the top row after the same placement the dashboard grid uses.
    function topRowBand(placed, columns) {
        if (!placed.length || columns <= 1) return { x: 0, span: Math.max(1, columns) };
        let top = placed[0].y | 0;
        placed.forEach((p) => { top = Math.min(top, p.y | 0); });
        let minX = columns;
        let maxX = 0;
        placed.forEach((p) => {
            if ((p.y | 0) !== top) return;
            minX = Math.min(minX, p.x | 0);
            maxX = Math.max(maxX, (p.x | 0) + (p.w | 0));
        });
        const span = maxX - minX;
        if (span <= 0) return { x: 0, span: columns };
        return { x: minX, span };
    }

    // GridStack writes left/width from --gs-column-width (100/columns %). The
    // card face is GRID_MARGIN inside that cell. One formula for both.
    function placeBand(bandEl, columns, band) {
        const host = bandEl.closest('.ld-grid') || bandEl.parentElement;
        if (host) host.style.setProperty('--gs-column-width', (100 / columns) + '%');
        const col = 'var(--gs-column-width)';
        const origin = band.x ? (band.x === 1 ? col : 'calc(' + band.x + ' * ' + col + ')') : '0px';
        const span = band.span > 1 ? 'calc(' + band.span + ' * ' + col + ')' : col;
        bandEl.style.marginLeft = 'calc(' + origin + ' + ' + GRID_MARGIN + 'px)';
        bandEl.style.width = 'calc(' + span + ' - ' + (GRID_MARGIN * 2) + 'px)';
    }

    const digestBandEl = document.getElementById('digest-band');
    const digestCanon = bandRecords(readJson('ld-band-items', []));
    const digestLayouts = bandLayouts(readJson('ld-band-layouts', {}));
    const pageCaps = [];
    document.querySelectorAll('.ld-grid[data-grid-cap]').forEach((el) => {
        if (el.id !== 'ld-grid') pageCaps.push(el);
    });
    const pageCols = new WeakMap();

    function syncDigestBand(width) {
        if (!digestBandEl) return;
        const prev = pageCols.has(digestBandEl) ? pageCols.get(digestBandEl) : null;
        const cols = columnsForWidth(width, prev);
        pageCols.set(digestBandEl, cols);
        placeBand(digestBandEl, cols, topRowBand(layoutPositions(digestCanon, digestLayouts, cols), cols));
    }

    function syncPageCaps() {
        const width = window.innerWidth;
        pageCaps.forEach((el) => {
            const prev = pageCols.has(el) ? pageCols.get(el) : null;
            const cols = columnsForWidth(width, prev);
            pageCols.set(el, cols);
            applyCap(el, cols);
        });
        syncDigestBand(width);
    }
    if (pageCaps.length || digestBandEl) {
        syncPageCaps();
        let capTimer = 0;
        window.addEventListener('resize', () => {
            clearTimeout(capTimer);
            capTimer = setTimeout(syncPageCaps, 220);
        });
    }

    const { api, esc, status, debounce } = window.LD;
    const GS = window.GridStack;

    const boardEl = document.querySelector('.ld-board');
    const gridEl = document.getElementById('ld-grid');
    if (!boardEl || !gridEl || !GS) return;

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
        margin: GRID_MARGIN,
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
        el.querySelectorAll('.ld-w-icon[data-ld-icon]').forEach((slot) => {
            if (slot.innerHTML.trim() || !window.LD.catalog) return;
            const name = slot.dataset.ldIcon;
            if (!name) return;
            window.LD.catalog.iconMarkup(name).then((svg) => {
                if (svg && slot.isConnected && !slot.innerHTML.trim()) slot.innerHTML = svg;
            });
        });
        applyPin(entry);
        if (entry.body) {
            paintSkeleton(entry.body);
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
        if (!entry.data) paintSkeleton(entry.body);
        try {
            const res = await api.get(`/widgets/${id}/data`);
            entry.data = res.data;
            render(entry);
        } catch (e) {
            if (e.status === 404) {
                removeLocal(id);
                return;
            }
            entry.data = null;
            paintState(entry.body, {
                kind: 'error',
                title: 'Could not load this widget',
                body: e.message || 'Something went wrong.',
                id: id,
            });
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
        if (!entry.data) return;
        const title = entry.el.querySelector('.ld-w-title');
        if (title) title.textContent = entry.widget.title || entry.widget.label;
        const forced = forcedState(entry);
        if (forced) {
            if (entry.tools) entry.tools.innerHTML = '';
            paintState(entry.body, forced);
            if (!opts || opts.fit !== false) scheduleFit();
            return;
        }
        if (!renderer) return;
        try {
            renderer({
                body: entry.body,
                tools: entry.tools,
                widget: entry.widget,
                data: entry.data,
                refresh: () => loadData(entry.widget.id),
            });
            const adopted = readState(entry);
            if (adopted) paintState(entry.body, adopted);
        } catch (e) {
            console.error('[lifedash] render failed', entry.widget, e);
            paintState(entry.body, {
                kind: 'error',
                title: 'Could not load this widget',
                body: 'This widget could not be displayed.',
                id: entry.widget.id,
            });
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

    function ldIcon(name) {
        const tpl = document.getElementById('ld-ico-' + name);
        return tpl ? tpl.innerHTML : '';
    }

    function menuItem(action, icon, label, danger) {
        return `<button type="button" class="ld-menu-item${danger ? ' ld-danger' : ''}" role="menuitem" data-action="${action}" title="${esc(label)}" aria-label="${esc(label)}"><span class="ld-ico">${ldIcon(icon)}</span><span class="ld-menu-label">${esc(label)}</span></button>`;
    }

    function lockItem(action, kind) {
        const label = 'Lock ' + kind;
        return `<button type="button" class="ld-menu-item" role="menuitem" data-action="${action}" aria-pressed="false" title="${esc(label)}" aria-label="${esc(label)}"><span class="ld-ico ld-ico-open">${ldIcon('lock-keyhole-open')}</span><span class="ld-ico ld-ico-shut">${ldIcon('lock-keyhole')}</span><span class="ld-menu-label">${esc(label)}</span></button>`;
    }

    function editBarHtml() {
        return `
                <div class="ld-edit-bar">
                    <span class="ld-drag ld-edit-only" title="Drag to move" aria-hidden="true">${ldIcon('grip-vertical')}</span>
                    <div class="ld-menu">
                        <button type="button" class="ld-menu-trigger" aria-haspopup="menu" aria-expanded="false" aria-label="Widget menu">${ldIcon('ellipsis')}</button>
                        <div class="ld-menu-panel" role="menu" hidden>
                            ${menuItem('configure', 'sliders-horizontal', 'Settings')}
                            ${lockItem('lock-height', 'height')}
                            ${lockItem('lock-place', 'place')}
                            ${menuItem('fit', 'maximize-2', 'Fit to content')}
                            ${menuItem('remove', 'trash-2', 'Remove', true)}
                        </div>
                    </div>
                </div>`;
    }

    function nativeItemHtml(w) {
        return `
            <div class="grid-stack-item-content surface ld-w ld-w-native">
                <div class="ld-fit">
                ${editBarHtml()}
                <div class="ld-w-head dashboard-widget-head">
                    <h2 class="section-title ld-w-title"><span class="ld-w-icon" data-ld-icon="${esc(w.icon || '')}" aria-hidden="true">${ldIcon(w.icon || '')}</span>${esc(w.title || w.label)}</h2>
                    <span class="ld-lock-badge" hidden aria-label="Locked">${ldIcon('lock-keyhole')}</span>
                    <div class="ld-w-tools"></div>
                </div>
                <div class="ld-w-body">${skeletonHtml()}</div>
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
        const snapshot = captureNodes();
        let bottom = 0;
        snapshot.forEach((n) => { bottom = Math.max(bottom, n.y + n.h); });
        const cols = grid.getColumn();
        const width = cols === 1 ? 1 : Math.max(w.min_w || 1, Math.min(cols, w.w));
        grid.makeWidget(el, {
            id: String(w.id), x: 0, y: bottom, w: width, h: w.h, minW: w.min_w, minH: w.min_h, autoPosition: false,
        });
        restoreNodes(snapshot);
        register(w, el);
        if (WIDE.includes(cols)) rememberWide(snapshot, { id: w.id, x: 0, y: bottom, w: width, h: w.h });
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
        if (widgets.size === 0) showEmptyBoard();
    }

    function showEmptyBoard() {
        if (document.getElementById('ld-empty')) return;
        const tpl = document.getElementById('ld-empty-tpl');
        if (!tpl) return;
        const card = document.createElement('div');
        card.id = 'ld-empty';
        card.className = 'ld-empty-board';
        card.innerHTML = tpl.innerHTML;
        gridEl.before(card);
    }

    // -----------------------------------------------------------------
    // Edit mode + layout persistence
    // -----------------------------------------------------------------
    const editBtn = document.getElementById('ld-edit');

    function setEditing(on) {
        editing = on;
        boardEl.classList.toggle('ld-editing', on);
        grid.setStatic(!on);
        widgets.forEach((entry) => applyPin(entry));
        const editLabel = editBtn.querySelector('.ld-edit-label');
        if (editLabel) editLabel.textContent = on ? 'Done' : 'Edit layout';
        else editBtn.textContent = on ? 'Done' : 'Edit layout';
        editBtn.setAttribute('aria-pressed', String(on));
        if (!on) {
            saveLayout.flush();
            saveWide.flush();
            saveHeights.flush();
            scheduleFit();
        }
    }

    boardEl.addEventListener('click', (e) => {
        if (e.target.closest('.ld-empty-add')) document.getElementById('ld-add')?.click();
    });

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
                placement_locked: w.placement_locked ? 1 : 0,
            };
        });
    }

    function isPinned(node) {
        return !!widgets.get(Number(node.id))?.widget.placement_locked;
    }

    function captureNodes() {
        return layoutNodes().map((n) => ({ id: Number(n.id), x: n.x, y: n.y, w: n.w, h: n.h }));
    }

    function restoreNodes(snapshot) {
        const byId = new Map(snapshot.map((s) => [s.id, s]));
        layoutNodes().forEach((n) => {
            const s = byId.get(Number(n.id));
            if (!s) return;
            n.x = s.x;
            n.y = s.y;
            n.w = s.w;
            n.h = s.h;
            writeNode(n);
        });
    }

    function rememberWide(snapshot, extra) {
        const cols = grid.getColumn();
        const known = new Set(snapshot.map((s) => s.id));
        const prior = Array.isArray(savedLayouts[cols])
            ? savedLayouts[cols].filter((s) => known.has(Number(s.id)))
            : snapshot.slice();
        const had = Array.isArray(savedLayouts[cols]);
        const next = prior.concat([extra]);
        postLayout(next.map((s) => {
            const w = widgets.get(Number(s.id))?.widget;
            const fromScreen = !had || Number(s.id) === Number(extra.id);
            return {
                id: Number(s.id),
                x: s.x,
                y: s.y,
                w: s.w,
                h: s.h,
                height_locked: w && w.height_locked ? 1 : 0,
                placement_locked: w && w.placement_locked ? 1 : 0,
            };
        }), { columns: cols });
    }

    function setLockItem(root, action, on, kind) {
        const btn = root.querySelector(`[data-action="${action}"]`);
        if (!btn) return;
        const text = (on ? 'Unlock ' : 'Lock ') + kind;
        btn.classList.toggle('is-on', on);
        const label = btn.querySelector('.ld-menu-label');
        if (label) label.textContent = text;
        btn.title = text;
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        btn.setAttribute('aria-label', text);
    }

    function syncLockMenu(entry) {
        const place = !!entry.widget.placement_locked;
        const height = !!entry.widget.height_locked;
        entry.el.classList.toggle('ld-place-lock', place);
        entry.el.classList.toggle('ld-h-lock', place || height);
        const badge = entry.el.querySelector('.ld-lock-badge');
        if (badge) badge.hidden = !(place || height);
        setLockItem(entry.el, 'lock-place', place, 'place');
        setLockItem(entry.el, 'lock-height', height, 'height');
    }

    function applyPin(entry) {
        if (!entry) return;
        syncLockMenu(entry);
        const place = !!entry.widget.placement_locked;
        if (entry.el.gridstackNode) {
            grid.update(entry.el, { locked: place, noMove: place, noResize: place });
        }
    }

    function persistPin(entry) {
        const w = entry.widget;
        const node = entry.el.gridstackNode;
        const onCanon = grid.getColumn() === COLUMNS;
        const x = onCanon && node ? node.x : w.x;
        const y = onCanon && node ? node.y : w.y;
        const width = onCanon && node ? node.w : w.w;
        const height = node ? node.h : w.h;
        w.x = x;
        w.y = y;
        w.w = width;
        w.h = height;
        postLayout([{
            id: w.id,
            x,
            y,
            w: width,
            h: height,
            height_locked: w.height_locked ? 1 : 0,
            placement_locked: w.placement_locked ? 1 : 0,
        }], { force: true });
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
            return fillWidth(nodes.map((it) => ({
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

    // 12 columns: start at the left edge and fill the row when that does not cover another card.
    function fillWidth(nodes, columns) {
        const groups = new Map();
        nodes.forEach((it, i) => {
            const y = it.y | 0;
            if (!groups.has(y)) groups.set(y, []);
            groups.get(y).push(i);
        });
        groups.forEach((idxs) => {
            idxs.sort((a, b) => nodes[a].x - nodes[b].x || (Number(nodes[a].id) || 0) - (Number(nodes[b].id) || 0));
            const min = Math.min(...idxs.map((i) => nodes[i].x));
            const max = Math.max(...idxs.map((i) => nodes[i].x + nodes[i].w));
            const span = max - min;
            if (span <= 0 || span >= columns) return;
            const extra = columns - span;
            const add = Math.floor(extra / idxs.length);
            let rem = extra % idxs.length;
            let x = 0;
            const moved = new Map();
            idxs.forEach((i) => {
                const w = nodes[i].w + add + (rem > 0 ? 1 : 0);
                if (rem > 0) rem -= 1;
                moved.set(i, { ...nodes[i], x, w });
                x += w;
            });
            const hits = (trial) => nodes.some((other, j) => !trial.has(j) && [...trial.values()].some((row) => rectsOverlap(row, other)));
            if (hits(moved)) {
                if (min === 0) return;
                const shifted = new Map();
                idxs.forEach((i) => shifted.set(i, { ...nodes[i], x: nodes[i].x - min }));
                if (hits(shifted)) return;
                shifted.forEach((trial, i) => { nodes[i] = trial; });
                return;
            }
            moved.forEach((trial, i) => { nodes[i] = trial; });
        });
        return nodes;
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

    // Saved 18/24 arrangements win. Otherwise project and center, same as the dashboard.
    function layoutPositions(canon, layouts, cols) {
        if (cols !== COLUMNS) {
            const saved = layouts && layouts[cols];
            if (Array.isArray(saved) && saved.length) {
                const byId = new Map(saved.map((s) => [Number(s.id), s]));
                let bottom = 0;
                const placed = [];
                const extra = [];
                canon.forEach((c) => {
                    const s = byId.get(c.id);
                    if (!s) {
                        extra.push(c);
                        return;
                    }
                    placed.push({ id: c.id, x: s.x, y: s.y, w: s.w, h: c.h });
                    bottom = Math.max(bottom, s.y + c.h);
                });
                extra.forEach((c) => {
                    const w = Math.max(1, Math.min(cols, c.w));
                    placed.push({ id: c.id, x: 0, y: bottom, w, h: c.h });
                    bottom += c.h;
                });
                return placed;
            }
        }
        return projectColumns(canon, cols);
    }

    function positionsFor(nodes, cols) {
        const canon = nodes.filter((n) => n.id !== undefined).map(canonicalOf);
        return layoutPositions(canon, savedLayouts, cols);
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
    function nodesOverlap(a, b) {
        return a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
    }

    function settleAroundPins(nodes) {
        const list = nodes.filter((n) => n.id !== undefined);
        const pins = list.filter((n) => isPinned(n));
        if (!pins.length) return;
        for (let pass = 0; pass < list.length; pass++) {
            let moved = false;
            list.forEach((n) => {
                if (isPinned(n)) return;
                pins.forEach((o) => {
                    if (!nodesOverlap(n, o)) return;
                    const next = o.y + o.h;
                    if (n.y < next) {
                        n.y = next;
                        moved = true;
                    }
                });
            });
            if (!moved) break;
        }
    }

    function pinSnapshot(nodes) {
        const pins = new Map();
        nodes.forEach((n) => {
            if (!isPinned(n)) return;
            pins.set(Number(n.id), { x: n.x, y: n.y, w: n.w, h: n.h });
        });
        return pins;
    }

    function restorePins(nodes, pins) {
        nodes.forEach((n) => {
            const pos = pins.get(Number(n.id));
            if (!pos) return;
            n.x = pos.x;
            n.y = pos.y;
            n.w = pos.w;
            n.h = pos.h;
        });
        settleAroundPins(nodes);
        nodes.forEach(writeNode);
        grid._updateContainerHeight();
    }

    /**
     * Content fit only pushes unlocked cards down. It does not change columns.
     */
    function growInPlace(prevHeights) {
        const nodes = layoutNodes();
        nodes.forEach((n) => {
            if (n._ldY !== undefined) n.y = n._ldY;
        });
        const pins = pinSnapshot(nodes);
        const inserts = [];
        nodes.forEach((n) => {
            if (pins.has(Number(n.id))) return;
            const prev = prevHeights.has(Number(n.id)) ? prevHeights.get(Number(n.id)) : n.h;
            const delta = n.h - prev;
            if (delta > 0) inserts.push({ id: Number(n.id), atY: n.y + prev, delta });
        });
        nodes.forEach((n) => {
            if (pins.has(Number(n.id))) return;
            let shift = 0;
            inserts.forEach((ins) => {
                if (ins.id === Number(n.id)) return;
                if (n.y >= ins.atY) shift += ins.delta;
            });
            if (shift) n.y += shift;
        });
        restorePins(nodes, pins);
        nodes.forEach((n) => { n._ldY = n.y; });
    }

    function commitPositions(prevHeights) {
        const cols = grid.getColumn();
        if (cols === 1 || !grid.engine) {
            return;
        }
        if (prevHeights) {
            growInPlace(prevHeights);
            return;
        }
        grid.engine._layouts = [];
        const nodes = layoutNodes();
        nodes.forEach((n) => {
            if (n._ldY !== undefined) n.y = n._ldY;
        });
        const pins = pinSnapshot(nodes);
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
        restorePins(nodes, pins);
        nodes.forEach((n) => { n._ldY = n.y; });
    }

    function projectInPlace() {
        commitPositions();
    }

    function sameLayout(items) {
        return items.every((it) => {
            const w = widgets.get(it.id)?.widget;
            return w && w.x === it.x && w.y === it.y && w.w === it.w && w.h === it.h
                && (w.height_locked ? 1 : 0) === (it.height_locked ? 1 : 0)
                && (w.placement_locked ? 1 : 0) === (it.placement_locked ? 1 : 0);
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
        } else if (!opts.force && !opts.pack && sameLayout(items)) return;
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
                return {
                    id: Number(n.id),
                    x: w.x,
                    y: w.y,
                    w: w.w,
                    h: n.h,
                    height_locked: w.height_locked ? 1 : 0,
                    placement_locked: w.placement_locked ? 1 : 0,
                };
            });
        postLayout(items, { pack: true });
    }

    function rememberDraggedY() {
        layoutNodes().forEach((n) => {
            if (n._moving || n._resizing) return;
            n._ldY = n.y;
        });
    }

    grid.on('change', () => {
        if (!editing || grid.isIgnoreChangeCB()) return;
        rememberDraggedY();
        const cols = grid.getColumn();
        if (cols === COLUMNS) saveLayout();
        else if (WIDE.includes(cols)) saveWide();
    });

    grid.on('resizestart', (_e, el) => {
        el.classList.add('ld-h-lock');
        const entry = widgets.get(Number(el.dataset.widgetId));
        if (!entry) return;
        entry.widget.height_locked = 1;
        syncLockMenu(entry);
    });

    grid.on('dragstop', () => {
        rememberDraggedY();
        if (editing && grid.getColumn() === 1) saveMobileOrder();
    });

    grid.on('resizestop', () => {
        rememberDraggedY();
        if (grid.getColumn() === 1) saveMobileOrder();
    });

    // -----------------------------------------------------------------
    // Remove (shared confirm) + configure
    // -----------------------------------------------------------------
    async function confirmRemove(id) {
        const entry = widgets.get(id);
        if (!entry) return;
        const name = String(entry.widget.title || entry.widget.label || '').trim() || 'this widget';
        const ok = await globalThis.AppModal.confirm({
            title: 'Remove widget',
            message: 'Remove ' + name + '?',
            confirmLabel: 'Remove',
            danger: true,
        });
        if (!ok || !widgets.has(id)) return;
        try {
            await api.post(`/widgets/${id}/delete`, {});
            removeLocal(id);
            status('Widget removed');
        } catch (err) {
            status(err.message, 'error');
        }
    }

    gridEl.addEventListener('click', (e) => {
        const retry = e.target.closest('[data-ld-retry]');
        if (retry) {
            const id = Number(retry.dataset.ldRetry);
            const entry = widgets.get(id);
            if (entry && entry.body) {
                entry.data = null;
                paintSkeleton(entry.body);
                loadData(id);
            }
            return;
        }
        const setupBtn = e.target.closest('[data-ld-setup]');
        if (setupBtn) {
            const id = Number(setupBtn.dataset.ldSetup);
            const entry = widgets.get(id);
            if (entry) openConfig(entry.widget);
            return;
        }
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const id = Number(btn.closest('.grid-stack-item')?.dataset.widgetId);
        if (!widgets.has(id)) return;

        if (btn.dataset.action === 'lock-place') {
            const entry = widgets.get(id);
            entry.widget.placement_locked = entry.widget.placement_locked ? 0 : 1;
            applyPin(entry);
            persistPin(entry);
        } else if (btn.dataset.action === 'lock-height') {
            const entry = widgets.get(id);
            entry.widget.height_locked = entry.widget.height_locked ? 0 : 1;
            applyPin(entry);
            persistPin(entry);
        } else if (btn.dataset.action === 'fit') {
            const el = btn.closest('.grid-stack-item');
            const entry = widgets.get(id);
            entry.widget.height_locked = 0;
            entry.widget.placement_locked = 0;
            if (el.gridstackNode) entry.widget.h = el.gridstackNode.h;
            applyPin(entry);
            fitItem(el);
            entry.widget.h = el.gridstackNode ? el.gridstackNode.h : entry.widget.h;
            persistPin(entry);
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
            if (btn.dataset.confirming === '1') return;
            btn.dataset.confirming = '1';
            confirmRemove(id).finally(() => {
                if (btn.isConnected) btn.dataset.confirming = '';
            });
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

    // Escape is handled by app-modal.js, which clicks [data-close].
    ['ld-add-modal', 'ld-config-modal'].forEach((id) => {
        const m = document.getElementById(id);
        m?.addEventListener('click', (e) => {
            if (e.target.closest('[data-close]')) closeModal(id);
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

    function skeletonHtml() {
        const states = window.LD && window.LD.states;
        if (states && typeof states.skeleton === 'function') return states.skeleton();
        return '<div class="ld-skeleton" role="status" aria-label="Loading"><i></i><i></i><i></i><i></i></div>';
    }

    function paintSkeleton(body) {
        if (body) body.innerHTML = skeletonHtml();
    }

    function stateHtml(kind, opts) {
        const states = window.LD && window.LD.states;
        if (states && typeof states.html === 'function') return states.html(kind, opts || {});
        const o = opts || {};
        return '<div class="ld-state ld-state-' + kind + '"><strong>' + esc(o.title || '') + '</strong></div>';
    }

    function fillStateIcon(root, kind) {
        const slot = root && root.querySelector('.ld-state-icon');
        const catalog = window.LD && window.LD.catalog;
        if (!slot || !catalog) return;
        const name = kind === 'error' ? 'activity' : (kind === 'setup' ? 'settings' : 'inbox');
        catalog.iconMarkup(name).then((svg) => {
            if (svg && slot.isConnected) slot.innerHTML = svg;
        });
    }

    function paintState(body, spec) {
        if (!body || !spec) return;
        body.innerHTML = stateHtml(spec.kind, spec);
        fillStateIcon(body, spec.kind);
    }

    // Needs-setup is decided here so a missing source never renders as live data.
    function forcedState(entry) {
        const catalog = window.LD && window.LD.catalog;
        if (!catalog) return null;
        const setup = catalog.setupFor(entry.widget.type);
        if (!setup) return null;
        const data = entry.data || {};
        if (entry.widget.type === 'spotify' && data.connected === false) {
            return {
                kind: 'setup',
                title: 'Setup required',
                body: 'Connect a data source in Settings.',
                href: setup.href,
                label: setup.label,
                id: entry.widget.id,
            };
        }
        if (setup.sources && !String((entry.widget.config || {}).sources || '').trim()) {
            return {
                kind: 'setup',
                title: 'Setup required',
                body: 'Connect a data source in Settings.',
                configure: true,
                label: setup.label,
                id: entry.widget.id,
            };
        }
        return null;
    }

    // A renderer that produced only an empty panel or an error becomes a standard state.
    function readState(entry) {
        const body = entry.body;
        if (!body) return null;
        const kids = [...body.children];
        if (kids.length !== 1) return null;
        const only = kids[0];
        if (only.classList.contains('ld-error')) {
            return {
                kind: 'error',
                title: 'Could not load this widget',
                body: only.textContent.trim() || 'Something went wrong.',
                id: entry.widget.id,
            };
        }
        if (!only.classList.contains('empty-panel')) return null;
        const title = only.querySelector('.empty-panel-title');
        const text = only.querySelector('.empty-panel-body');
        const link = only.querySelector('a[href]');
        const href = link ? (link.getAttribute('href') || '') : '';
        const setup = href.indexOf('/settings/') !== -1;
        return {
            kind: setup ? 'setup' : 'empty',
            title: setup ? 'Setup required' : (title ? title.textContent.trim() : 'Nothing here yet'),
            body: text ? text.textContent.trim() : 'New data will appear here.',
            href: href,
            label: link ? link.textContent.trim() : 'Open Settings',
            id: entry.widget.id,
        };
    }

    // ---- Add widget --------------------------------------------------
    let catalogIcons = null;
    let catalogGen = 0;

    function sizeLabel(t) {
        const w = t.size && t.size.w;
        const h = t.size && t.size.h;
        if (!w || !h) return '';
        return 'Default size ' + w + ' × ' + h;
    }

    async function paintCatalog(query) {
        const list = document.getElementById('ld-add-list');
        const catalog = window.LD && window.LD.catalog;
        if (!list) return;
        const gen = ++catalogGen;
        const q = String(query || '').trim().toLowerCase();
        if (!catalog) {
            list.innerHTML = stateHtml('error', { title: 'Could not load widgets', body: 'The catalog is unavailable.', retry: 'catalog' });
            fillStateIcon(list, 'error');
            return;
        }
        try {
            const all = await types();
            if (gen !== catalogGen) return;
            const iconNameOf = (t) => (t && t.icon) || catalog.iconOf(t.type);
            if (!catalogIcons) {
                const names = [...new Set(all.map(iconNameOf))];
                const pack = {};
                await Promise.all(names.map(async (name) => { pack[name] = await catalog.iconMarkup(name); }));
                if (gen !== catalogGen) return;
                catalogIcons = pack;
            }
            const present = new Set([...widgets.values()].map((e) => e.widget.type));
            const groups = catalog.group(all).map((group) => ({
                name: group.name,
                items: group.items.filter((t) => {
                    if (!q) return true;
                    return (String(t.label || '') + ' ' + String(t.description || '') + ' ' + group.name).toLowerCase().includes(q);
                }),
            })).filter((group) => group.items.length);
            if (!groups.length) {
                list.innerHTML = stateHtml('empty', { title: 'No matching widgets', body: 'Try another name.' });
                fillStateIcon(list, 'empty');
                return;
            }
            list.innerHTML = groups.map((group) => {
                const items = group.items.map((t) => {
                    const taken = !!(t.singleton && present.has(t.type));
                    const icon = catalogIcons[iconNameOf(t)] || '';
                    const desc = taken ? 'Already on the dashboard' : (t.description || '');
                    return `<button type="button" class="ld-catalog-item" data-type="${esc(t.type)}" ${taken ? 'disabled' : ''}>
                        <span class="ld-catalog-icon" aria-hidden="true">${icon}</span>
                        <span class="ld-catalog-copy">
                            <strong>${esc(t.label)}</strong>
                            <span class="ld-catalog-desc">${esc(desc)}</span>
                            <span class="ld-catalog-size">${esc(sizeLabel(t))}</span>
                        </span>
                    </button>`;
                }).join('');
                return `<section class="ld-catalog-group"><h4 class="ld-catalog-label">${esc(group.name)}</h4><div class="ld-catalog-grid">${items}</div></section>`;
            }).join('');
        } catch (e) {
            if (gen !== catalogGen) return;
            list.innerHTML = stateHtml('error', {
                title: 'Could not load widgets',
                body: e.message || 'Something went wrong.',
                retry: 'catalog',
            });
            fillStateIcon(list, 'error');
        }
    }

    document.getElementById('ld-add')?.addEventListener('click', () => {
        const list = document.getElementById('ld-add-list');
        const query = document.getElementById('ld-catalog-query');
        if (list) paintSkeleton(list);
        openModal('ld-add-modal');
        if (query) query.focus();
        paintCatalog(query ? query.value : '');
    });

    document.getElementById('ld-catalog-query')?.addEventListener('input', (e) => {
        paintCatalog(e.target.value);
    });

    document.getElementById('ld-add-list')?.addEventListener('click', async (e) => {
        if (e.target.closest('[data-ld-retry="catalog"]')) {
            const list = document.getElementById('ld-add-list');
            const query = document.getElementById('ld-catalog-query');
            if (list) paintSkeleton(list);
            paintCatalog(query ? query.value : '');
            return;
        }
        const btn = e.target.closest('.ld-catalog-item');
        if (!btn || btn.disabled) return;
        const t = (await types()).find((x) => x.type === btn.dataset.type);
        if (!t) return;
        closeModal('ld-add-modal');
        if (t.floating) {
            document.dispatchEvent(new CustomEvent('ld-spotify-player', { detail: { on: true } }));
            status('Spotify player on');
            return;
        }
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
                }).join('')
                + (widget ? lockChrome(widget) : '');
            if (t.type === 'goal') bindGoalLogFields(ms, widget ? widget.config : {});
            form.querySelector('input, select')?.focus();
        } catch (e) {
            fieldsEl.innerHTML = `<p class="ld-error">${esc(e.message)}</p>`;
        }
    }

    function lockChrome(widget) {
        const entry = widgets.get(widget.id);
        const height = !!(entry && entry.widget.height_locked);
        const place = !!(entry && entry.widget.placement_locked);
        const toggle = (kind, on, label) => `<div class="ld-setting-row"><span>${label}</span><button type="button" class="ld-toggle" data-lock="${kind}" aria-pressed="${on ? 'true' : 'false'}">${on ? 'On' : 'Off'}</button></div>`;
        return toggle('height', height, 'Lock height')
            + toggle('place', place, 'Lock place')
            + `<div class="ld-state-preview">
                <span class="ld-state-preview-label">State preview</span>
                <div class="ld-state-preview-modes">
                    <button type="button" data-preview="loading">Loading</button>
                    <button type="button" data-preview="empty">Empty</button>
                    <button type="button" data-preview="error">Error</button>
                    <button type="button" data-preview="setup">Setup</button>
                </div>
                <div class="ld-state-preview-stage"></div>
            </div>`;
    }

    function previewChrome(kind) {
        const stage = document.querySelector('#ld-config-fields .ld-state-preview-stage');
        if (!stage) return;
        if (kind === 'loading') {
            stage.innerHTML = skeletonHtml();
            return;
        }
        const copy = {
            empty: { title: 'Nothing here yet', body: 'New data will appear here.' },
            error: { title: 'Could not load this widget', body: 'Something went wrong.', retry: '' },
            setup: { title: 'Setup required', body: 'Connect a data source in Settings.', href: '/settings', label: 'Open Settings' },
        };
        stage.innerHTML = stateHtml(kind, copy[kind] || copy.empty);
        fillStateIcon(stage, kind);
    }

    document.getElementById('ld-config-fields')?.addEventListener('click', (e) => {
        const lock = e.target.closest('[data-lock]');
        if (lock && configTarget && configTarget.widget) {
            const entry = widgets.get(configTarget.widget.id);
            if (!entry) return;
            if (lock.dataset.lock === 'height') entry.widget.height_locked = entry.widget.height_locked ? 0 : 1;
            else entry.widget.placement_locked = entry.widget.placement_locked ? 0 : 1;
            applyPin(entry);
            persistPin(entry);
            const on = lock.dataset.lock === 'height' ? !!entry.widget.height_locked : !!entry.widget.placement_locked;
            lock.setAttribute('aria-pressed', on ? 'true' : 'false');
            lock.textContent = on ? 'On' : 'Off';
            return;
        }
        const preview = e.target.closest('[data-preview]');
        if (preview) previewChrome(preview.dataset.preview);
    });

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
                        : window.DashboardEmpty.html({
                            title: 'No metrics yet',
                            body: 'Add a life metric before charting it.',
                            actionLabel: 'Life',
                            actionHref: '/settings/life',
                        })
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
            }).join('') || window.DashboardEmpty.html({
                title: 'Choose metrics',
                body: 'Pick 1 to 4 metrics for this chart.',
                actionLabel: 'Life',
                actionHref: '/settings/life',
            });
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
        applyPin(entry);
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
                refreshTypes(['shopping', 'pantry']);
                break;
            case 'pantry_products':
            case 'pantry_lots':
            case 'pantry_recipes':
            case 'pantry_recipe_ingredients':
            case 'meal_components':
            case 'meal_pairings':
                refreshTypes(['pantry']);
                break;
            case 'calls':
            case 'call_debt':
            case 'contacts':
            case 'vetted_agencies':
                refreshTypes(['calls']);
                break;
            case 'digest_sources':
            case 'digest_entries':
            case 'digest_cursors':
                refreshTypes(['digest']);
                break;
            case 'spotify_account':
            case 'spotify_playlists':
            case 'spotify_playlist_tracks':
            case 'spotify_play_history':
            case 'spotify_top_items':
                refreshTypes(['spotify']);
                break;
            default:
                break;
        }
    }

    // -----------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------
    const FIT_MAX = { goal: 6, chart: 8, mood: 8, habits: 10, shopping: 12, pantry: 8, calls: 12, 'legacy.news': 8, 'legacy.clipboards': 8 };

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
        rememberDraggedY();
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
            const prevHeights = new Map(layoutNodes().map((n) => [Number(n.id), n.h]));
            grid.getGridItems().forEach(fitItem);
            commitPositions(prevHeights);
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
    function applyGridCap(cols) {
        applyCap(gridEl, cols);
        if (boardEl && boardEl !== gridEl) {
            boardEl.dataset.gridCap = gridEl.dataset.gridCap || '0';
            applyCap(boardEl, cols);
        }
    }

    function applyColumns(cols, animate) {
        if (grid.engine) grid.engine._layouts = [];
        grid.setAnimation(!!animate);
        if (cols === 1) {
            if (grid.getColumn() !== 1) {
                grid.column(1, (_newCols, _oldCols, placed, remaining) => {
                    const all = placed.concat(remaining);
                    placed.length = 0;
                    remaining.length = 0;
                    all.forEach((n) => {
                        n.x = 0;
                        n.w = 1;
                        placed.push(n);
                    });
                });
            }
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

    requestAnimationFrame(() => {
        scheduleFit();
    });

    window.LD.grid = { handleEvent, refreshAll, refreshForMetric, get editing() { return editing; } };
})();
