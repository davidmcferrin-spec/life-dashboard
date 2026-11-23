/**
 * Width and hidden state for the vault notes column.
 * The in-memory choice is what the layout shows. Storage is only a copy,
 * read once, and a failed write does not put the column back.
 * A missing or blocked store keeps the stylesheet width and an open column.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainDrawer = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const KEY = 'ld_vault_drawer';
    const MIN_WIDTH = 168;
    const MAX_WIDTH = 520;
    const RESERVE = 280;
    const MOBILE_MAX = 620;
    const STEP = 16;
    const TAP_MS = 400;
    const TAP_SLOP = 4;

    function fallback() {
        return { width: null, hidden: false };
    }

    function read(storage) {
        const base = fallback();
        if (!storage || typeof storage.getItem !== 'function') return base;
        let raw;
        try {
            raw = storage.getItem(KEY);
        } catch (err) {
            return base;
        }
        if (!raw) return base;
        let data;
        try {
            data = JSON.parse(raw);
        } catch (err) {
            return base;
        }
        if (!data || typeof data !== 'object') return base;
        const width = typeof data.width === 'number' && Number.isFinite(data.width) ? data.width : null;
        return {
            width: width,
            hidden: typeof data.hidden === 'boolean' ? data.hidden : false,
        };
    }

    function write(storage, state) {
        if (!storage || typeof storage.setItem !== 'function') return false;
        const payload = { hidden: !!(state && state.hidden) };
        if (state && typeof state.width === 'number' && Number.isFinite(state.width)) {
            payload.width = Math.round(state.width);
        }
        try {
            storage.setItem(KEY, JSON.stringify(payload));
            return true;
        } catch (err) {
            return false;
        }
    }

    function limits(viewport) {
        const view = Number(viewport);
        const width = Number.isFinite(view) && view > 0 ? view : 1200;
        return {
            min: MIN_WIDTH,
            max: Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, width - RESERVE)),
        };
    }

    function clamp(width, viewport) {
        const bounds = limits(viewport);
        const n = Number(width);
        if (!Number.isFinite(n)) return bounds.min;
        return Math.round(Math.min(bounds.max, Math.max(bounds.min, n)));
    }

    function defaultWidth(viewport) {
        const width = Number(viewport);
        if (!Number.isFinite(width)) return 230;
        if (width <= 850) return 190;
        if (width <= 1100) return 210;
        return 230;
    }

    function safeCall(fn) {
        try {
            return fn();
        } catch (err) {
            return null;
        }
    }

    function setStyle(el, name, value) {
        if (!el || !el.style) return;
        if (value == null) {
            if (typeof el.style.removeProperty === 'function') el.style.removeProperty(name);
            else delete el.style[name];
            return;
        }
        if (typeof el.style.setProperty === 'function') el.style.setProperty(name, value);
        else el.style[name] = value;
    }

    /**
     * One controller for the notes column. Pointer and touch share a drag,
     * and a second touch start while a pointer drag is active is ignored.
     */
    function mount(shell, options) {
        const opts = options || {};
        const handle = opts.handle || null;
        const hideButton = opts.hideButton || null;
        const showButton = opts.showButton || null;
        let memory = null;
        let drag = null;
        let dragMemory = null;
        let lastTapAt = 0;

        function storage() {
            if (typeof opts.storage !== 'function') return null;
            return safeCall(opts.storage);
        }

        function viewport() {
            if (typeof opts.viewport !== 'function') return 1200;
            const value = Number(safeCall(opts.viewport));
            return Number.isFinite(value) && value > 0 ? value : 1200;
        }

        function measure() {
            if (typeof opts.measure !== 'function') return 0;
            const value = Number(safeCall(opts.measure));
            return Number.isFinite(value) && value > 0 ? value : 0;
        }

        function ensure() {
            if (memory === null) memory = read(storage());
            return memory;
        }

        function mobile() {
            return viewport() <= MOBILE_MAX;
        }

        function renderedWidth() {
            const current = ensure();
            if (typeof current.width === 'number') return clamp(current.width, viewport());
            const measured = measure();
            if (measured) return clamp(measured, viewport());
            return defaultWidth(viewport());
        }

        function body() {
            if (opts.body) return opts.body;
            try {
                return document.body;
            } catch (err) {
                return null;
            }
        }

        function setDragging(on) {
            if (shell && shell.classList) shell.classList.toggle('is-drawer-dragging', on);
            const host = body();
            if (host && host.classList) host.classList.toggle('is-drawer-dragging', on);
        }

        function pane() {
            if (!shell || typeof shell.querySelector !== 'function') return null;
            return shell.querySelector('#brain-tree-pane') || shell.querySelector('.brain-tree-pane');
        }

        function paint() {
            const current = ensure();
            const view = viewport();
            const narrow = view <= MOBILE_MAX;
            const hidden = !narrow && !!current.hidden;
            if (shell && shell.classList) shell.classList.toggle('is-drawer-collapsed', hidden);
            if (narrow || (!hidden && current.width == null)) setStyle(shell, '--brain-tree-width', null);
            else setStyle(shell, '--brain-tree-width', (hidden ? 0 : clamp(current.width, view)) + 'px');
            const shown = hidden ? 0 : (current.width == null ? defaultWidth(view) : clamp(current.width, view));
            if (handle) {
                const bounds = limits(view);
                handle.setAttribute('aria-valuemin', String(bounds.min));
                handle.setAttribute('aria-valuemax', String(bounds.max));
                handle.setAttribute('aria-valuenow', String(shown));
                if (typeof handle.tabIndex === 'number' || handle.tabIndex !== undefined) {
                    handle.tabIndex = hidden || narrow ? -1 : 0;
                }
            }
            if (hideButton && typeof hideButton.setAttribute === 'function') {
                hideButton.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            }
            if (showButton && typeof showButton.setAttribute === 'function') {
                if (narrow) {
                    const open = typeof opts.mobileOpen === 'function' ? !!safeCall(opts.mobileOpen) : false;
                    showButton.setAttribute('aria-expanded', open ? 'true' : 'false');
                } else {
                    showButton.setAttribute('aria-expanded', hidden ? 'false' : 'true');
                }
            }
            const column = pane();
            if (column && typeof column.setAttribute === 'function') {
                if (hidden) {
                    column.setAttribute('inert', '');
                    column.setAttribute('aria-hidden', 'true');
                } else if (typeof column.removeAttribute === 'function') {
                    column.removeAttribute('inert');
                    column.removeAttribute('aria-hidden');
                }
            }
            return current;
        }

        function persist(next) {
            memory = next;
            write(storage(), memory);
            return paint();
        }

        function resetWidth() {
            drag = null;
            setDragging(false);
            lastTapAt = 0;
            return persist({ width: null, hidden: !!ensure().hidden });
        }

        function hide() {
            const next = persist({ width: ensure().width, hidden: true });
            if (!mobile() && showButton && typeof showButton.focus === 'function') showButton.focus();
            return next;
        }

        function show() {
            const next = persist({ width: ensure().width, hidden: false });
            if (hideButton && typeof hideButton.focus === 'function') hideButton.focus();
            return next;
        }

        function beginDrag(clientX, id, fromPointer) {
            if (drag || mobile() || ensure().hidden) return false;
            dragMemory = { width: ensure().width, hidden: !!ensure().hidden };
            drag = {
                id: id,
                pointer: fromPointer,
                startX: clientX,
                startWidth: renderedWidth(),
            };
            setDragging(true);
            return true;
        }

        function moveDrag(clientX) {
            if (!drag || Math.abs(clientX - drag.startX) <= TAP_SLOP) return;
            memory = {
                width: clamp(drag.startWidth + (clientX - drag.startX), viewport()),
                hidden: false,
            };
            paint();
        }

        function endDrag(clientX, commit) {
            if (!drag) return;
            const startX = drag.startX;
            const startWidth = drag.startWidth;
            const moved = Math.abs(clientX - startX) > TAP_SLOP;
            drag = null;
            setDragging(false);
            if (!commit) {
                memory = dragMemory || fallback();
                paint();
                return;
            }
            const now = Date.now();
            if (!moved && now - lastTapAt < TAP_MS) {
                lastTapAt = 0;
                resetWidth();
                return;
            }
            lastTapAt = moved ? 0 : now;
            if (!moved) return;
            persist({
                width: clamp(startWidth + (clientX - startX), viewport()),
                hidden: false,
            });
        }

        if (handle && typeof handle.addEventListener === 'function') {
            setStyle(handle, 'touch-action', 'none');
            handle.addEventListener('pointerdown', (event) => {
                if (!event || (event.button != null && event.button !== 0)) return;
                if (!beginDrag(event.clientX, event.pointerId, true)) return;
                if (typeof handle.setPointerCapture === 'function') {
                    try { handle.setPointerCapture(event.pointerId); } catch (err) { /* already captured */ }
                }
            });
            handle.addEventListener('pointermove', (event) => {
                if (!drag || !drag.pointer || !event || drag.id !== event.pointerId) return;
                if (typeof event.preventDefault === 'function') event.preventDefault();
                moveDrag(event.clientX);
            });
            handle.addEventListener('pointerup', (event) => {
                if (!drag || !drag.pointer || !event || drag.id !== event.pointerId) return;
                endDrag(event.clientX, true);
            });
            handle.addEventListener('pointercancel', (event) => {
                if (!drag || !drag.pointer || !event || drag.id !== event.pointerId) return;
                endDrag(event.clientX, false);
            });
            handle.addEventListener('touchstart', (event) => {
                if (drag) return;
                const touch = event && event.changedTouches && event.changedTouches[0];
                if (!touch) return;
                if (!beginDrag(touch.clientX, touch.identifier, false)) return;
                if (typeof event.preventDefault === 'function') event.preventDefault();
            }, { passive: false });
            handle.addEventListener('touchmove', (event) => {
                if (!drag || drag.pointer) return;
                const touch = event && event.changedTouches && event.changedTouches[0];
                if (!touch || drag.id !== touch.identifier) return;
                if (typeof event.preventDefault === 'function') event.preventDefault();
                moveDrag(touch.clientX);
            }, { passive: false });
            handle.addEventListener('touchend', (event) => {
                if (!drag || drag.pointer) return;
                const touch = event && event.changedTouches && event.changedTouches[0];
                if (!touch || drag.id !== touch.identifier) return;
                endDrag(touch.clientX, true);
            });
            handle.addEventListener('touchcancel', (event) => {
                if (!drag || drag.pointer) return;
                const touch = event && event.changedTouches && event.changedTouches[0];
                if (!touch || drag.id !== touch.identifier) return;
                endDrag(touch.clientX, false);
            });
            handle.addEventListener('dblclick', (event) => {
                if (event && typeof event.preventDefault === 'function') event.preventDefault();
                resetWidth();
            });
            handle.addEventListener('keydown', (event) => {
                if (!event || mobile()) return;
                const key = event.key;
                const view = viewport();
                const bounds = limits(view);
                let next = null;
                if (key === 'ArrowLeft' || key === 'ArrowDown') next = clamp(renderedWidth() - STEP, view);
                else if (key === 'ArrowRight' || key === 'ArrowUp') next = clamp(renderedWidth() + STEP, view);
                else if (key === 'Home') next = bounds.min;
                else if (key === 'End') next = bounds.max;
                else if (key === 'Enter') {
                    if (typeof event.preventDefault === 'function') event.preventDefault();
                    resetWidth();
                    return;
                } else if (key === ' ' || key === 'Spacebar') {
                    if (typeof event.preventDefault === 'function') event.preventDefault();
                    return;
                } else return;
                if (typeof event.preventDefault === 'function') event.preventDefault();
                persist({ width: next, hidden: false });
            });
        }

        if (hideButton && typeof hideButton.addEventListener === 'function') {
            hideButton.addEventListener('click', () => hide());
        }
        if (showButton && typeof showButton.addEventListener === 'function') {
            showButton.addEventListener('click', () => {
                if (mobile()) {
                    if (typeof opts.onMobileToggle === 'function') opts.onMobileToggle();
                    return;
                }
                if (ensure().hidden) show();
            });
        }

        const listen = opts.window || (typeof window !== 'undefined' ? window : null);
        if (listen && typeof listen.addEventListener === 'function') {
            listen.addEventListener('resize', () => paint());
        }

        paint();

        return {
            state: ensure,
            paint: paint,
            hide: hide,
            show: show,
            reset: resetWidth,
        };
    }

    return {
        key: KEY,
        minWidth: MIN_WIDTH,
        maxWidth: MAX_WIDTH,
        reserve: RESERVE,
        mobileMax: MOBILE_MAX,
        step: STEP,
        read: read,
        write: write,
        limits: limits,
        clamp: clamp,
        defaultWidth: defaultWidth,
        mount: mount,
    };
});
