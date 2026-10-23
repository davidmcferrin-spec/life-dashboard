'use strict';

(function () {
    function icon(id) {
        const node = document.getElementById(id);
        return node ? node.innerHTML.trim() : '';
    }

    function esc(value) {
        return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function closeMenus() {
        document.querySelectorAll('.fin-menu-pop').forEach((pop) => {
            pop.hidden = true;
        });
        document.querySelectorAll('.fin-icon-btn[aria-expanded="true"]').forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
        });
    }

    function applyQuery(host, query) {
        if (!host) return;
        const needle = String(query || '').trim().toLowerCase();
        host.querySelectorAll('tbody tr').forEach((row) => {
            const hay = (row.getAttribute('data-filter') || '').toLowerCase();
            row.hidden = needle !== '' && !hay.includes(needle);
        });
    }

    function rowMenu(items) {
        const glyph = icon('fin-icon-ellipsis');
        const buttons = (items || []).map((item) => {
            return '<button type="button" class="fin-menu-item" role="menuitem" data-fin-act="'
                + esc(item.act) + '">' + esc(item.label) + '</button>';
        }).join('');
        if (!buttons) return '';
        return '<div class="fin-menu"><button type="button" class="fin-icon-btn" aria-haspopup="menu" aria-expanded="false" aria-label="Row menu">'
            + glyph + '</button><div class="fin-menu-pop" role="menu" hidden>' + buttons + '</div></div>';
    }

    function bindHost(host) {
        if (!host || host.dataset.finBound === '1') return;
        host.dataset.finBound = '1';
        host.addEventListener('click', (event) => {
            const button = event.target.closest('.fin-icon-btn');
            if (button && host.contains(button)) {
                event.stopPropagation();
                const pop = button.parentElement && button.parentElement.querySelector('.fin-menu-pop');
                const willOpen = !!(pop && pop.hidden);
                closeMenus();
                if (willOpen && pop) {
                    pop.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                }
                return;
            }
            if (event.target.closest('.fin-menu-item')) closeMenus();
        });
    }

    function bindFilter(button, input, row, host) {
        if (!button || !input || button.dataset.finBound === '1') return;
        button.dataset.finBound = '1';
        button.addEventListener('click', () => {
            const open = !!(row && row.hidden);
            if (row) row.hidden = !open;
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) input.focus();
        });
        input.addEventListener('input', () => applyQuery(host, input.value));
    }

    function setQuery(button, input, row, host, query) {
        if (!input) return;
        input.value = query;
        if (row) row.hidden = false;
        if (button) button.setAttribute('aria-expanded', 'true');
        applyQuery(host, query);
    }

    document.addEventListener('click', (event) => {
        if (event.target.closest('.fin-menu')) return;
        closeMenus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMenus();
    });

    window.FinTable = {
        rowMenu: rowMenu,
        bindHost: bindHost,
        bindFilter: bindFilter,
        setQuery: setQuery,
        closeMenus: closeMenus,
    };
})();
