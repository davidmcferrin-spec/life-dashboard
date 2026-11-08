/**
 * Collapsed state for the vault Favorites and Recent sections.
 * Each section is stored on its own. A missing or blocked store
 * falls back to the layout default and still renders.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainPins = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const KEY = 'ld_vault_pins';

    function fallback(layout) {
        const collapsed = !!(layout && (layout.narrow || layout.coarse));
        return { favorites: collapsed, recent: collapsed };
    }

    function read(storage, layout) {
        const base = fallback(layout);
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
        return {
            favorites: typeof data.favorites === 'boolean' ? data.favorites : base.favorites,
            recent: typeof data.recent === 'boolean' ? data.recent : base.recent,
        };
    }

    function write(storage, state) {
        if (!storage || typeof storage.setItem !== 'function') return false;
        try {
            storage.setItem(KEY, JSON.stringify({
                favorites: !!state.favorites,
                recent: !!state.recent,
            }));
            return true;
        } catch (err) {
            return false;
        }
    }

    function toggle(state, kind) {
        const next = {
            favorites: !!(state && state.favorites),
            recent: !!(state && state.recent),
        };
        if (kind !== 'favorites' && kind !== 'recent') return next;
        next[kind] = !next[kind];
        return next;
    }

    function press(state, kind, key) {
        if (key !== 'Enter' && key !== ' ' && key !== 'Spacebar') return state;
        return toggle(state, kind);
    }

    function section(kind, label, rows, collapsed, chevron) {
        const listId = 'brain-pin-' + kind;
        const open = !collapsed;
        const verb = open ? 'Collapse' : 'Expand';
        const items = (rows || []).map((page) =>
            `<button type="button" class="brain-pin" data-id="${Number(page.id)}">${escapeHtml(page.title)}</button>`
        ).join('');
        return `<section class="brain-pin-section">
            <h3 class="brain-pin-heading">
                <button type="button" class="brain-pin-toggle" data-pin-toggle="${kind}" aria-expanded="${open ? 'true' : 'false'}" aria-controls="${listId}" aria-label="${verb} ${escapeHtml(label)}">
                    <span class="brain-chevron" aria-hidden="true">${chevron || ''}</span>
                    <span class="brain-pin-name">${escapeHtml(label)}</span>
                    <span class="brain-pin-count">${(rows || []).length}</span>
                </button>
            </h3>
            <div id="${listId}" class="brain-pin-list" role="group" aria-label="${escapeHtml(label)}"${open ? '' : ' hidden'}>${items}</div>
        </section>`;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    return {
        key: KEY,
        fallback: fallback,
        read: read,
        write: write,
        toggle: toggle,
        press: press,
        section: section,
    };
});
