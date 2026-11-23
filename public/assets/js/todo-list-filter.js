/**
 * List filter for /todos.
 * A ?list= link wins. Otherwise the last choice is kept in sessionStorage,
 * the same way the news category and source filters are kept.
 * "all" is every list. A new task while All is selected uses the pinned list,
 * or the first list when nothing is pinned.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.TodoListFilter = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const KEY = 'todo_page_list';
    const ALL = 'all';

    function isAll(value) {
        return String(value == null ? '' : value).trim().toLowerCase() === ALL;
    }

    function listsOf(lists) {
        return Array.isArray(lists) ? lists : [];
    }

    function matchList(lists, raw) {
        const text = String(raw == null ? '' : raw).trim();
        if (text === '') {
            return null;
        }
        return listsOf(lists).find(function (list) {
            return list && (list.slug === text || String(list.id) === text);
        }) || null;
    }

    function defaultList(lists) {
        const rows = listsOf(lists);
        if (!rows.length) {
            return null;
        }
        return rows.find(function (list) { return list && list.pin_to_dashboard; }) || rows[0];
    }

    function resolve(lists, raw) {
        if (raw == null || String(raw).trim() === '') {
            return undefined;
        }
        if (isAll(raw)) {
            return ALL;
        }
        const match = matchList(lists, raw);
        return match ? match.id : undefined;
    }

    function choose(lists, urlList, stored) {
        const rows = listsOf(lists);
        if (!rows.length) {
            return { id: null, source: 'default' };
        }
        const fromUrl = resolve(rows, urlList);
        if (fromUrl !== undefined) {
            return { id: fromUrl, source: 'url' };
        }
        const fromStored = resolve(rows, stored);
        if (fromStored !== undefined) {
            return { id: fromStored, source: 'stored' };
        }
        const fallback = defaultList(rows);
        return { id: fallback ? fallback.id : null, source: 'default' };
    }

    function addTarget(activeId, lists) {
        if (!isAll(activeId) && activeId != null && String(activeId).trim() !== '') {
            return activeId;
        }
        const fallback = defaultList(lists);
        return fallback ? fallback.id : null;
    }

    function readStored(storage) {
        if (!storage || typeof storage.getItem !== 'function') {
            return '';
        }
        try {
            return storage.getItem(KEY) || '';
        } catch (err) {
            return '';
        }
    }

    function writeStored(storage, value) {
        if (!storage || typeof storage.setItem !== 'function' || value == null || value === '') {
            return false;
        }
        try {
            storage.setItem(KEY, String(value));
            return true;
        } catch (err) {
            return false;
        }
    }

    return {
        KEY: KEY,
        ALL: ALL,
        isAll: isAll,
        defaultList: defaultList,
        choose: choose,
        addTarget: addTarget,
        readStored: readStored,
        writeStored: writeStored,
    };
});
