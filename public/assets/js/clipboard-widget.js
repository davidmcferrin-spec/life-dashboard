/**
 * clipboard-widget.js — pinned cloud clipboards on the dashboard
 */

(function () {
'use strict';

const POLL_FAST_MS = 3000;
const POLL_SLOW_MS = 30000;
const AUTOSAVE_DEBOUNCE_MS = 1200;

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function api(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        ...(options.headers || {}),
    };

    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = getCsrfToken();
    }

    const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    const text = await res.text();
    let data = {};
    try {
        data = JSON.parse(text);
    } catch (_) { /* ignore */ }
    return { res, data };
}

function initClipboardWidget() {
    const root = document.getElementById('clipboard-widget');
    if (!root) return;

    let boards = [];
    let activeId = null;
    let localVersion = 0;
    let canWrite = true;
    let dirty = false;
    let saveTimer = null;
    let pollTimer = null;
    let saving = false;
    let clipView = null;

    const tabsEl = root.querySelector('#clip-widget-tabs');
    const shareLink = document.getElementById('clip-widget-share');
    const openLink = document.getElementById('clip-widget-open');
    const editor = root.querySelector('#clip-widget-editor');
    const preview = root.querySelector('#clip-widget-preview');
    const viewToggle = root.querySelector('#clip-widget-view-toggle');
    const emptyEl = root.querySelector('#clip-widget-empty');
    const statusEl = root.querySelector('#clip-widget-status');
    const remoteEl = root.querySelector('#clip-widget-remote');

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function showEmpty(show) {
        emptyEl?.classList.toggle('hidden', !show);
        viewToggle?.classList.toggle('hidden', show);
        root.querySelector('#clip-widget-copy')?.classList.toggle('hidden', show);
        if (show) {
            editor?.classList.add('hidden');
            preview?.classList.add('hidden');
        }
        remoteEl?.classList.toggle('hidden', show || remoteEl.classList.contains('hidden'));
    }

    if (window.ClipboardMarkdown) {
        clipView = window.ClipboardMarkdown.attach({
            editor,
            preview,
            btnEdit: root.querySelector('#clip-widget-view-edit'),
            btnPreview: root.querySelector('#clip-widget-view-preview'),
            canWrite: true,
        });
    } else {
        clipView = { getMode: () => 'edit', setMode: () => {}, refresh: () => {} };
    }

    function updateClipViewAccess() {
        const btnEdit = root.querySelector('#clip-widget-view-edit');
        if (!canWrite) {
            clipView.setMode('preview');
            btnEdit?.classList.add('hidden');
        } else {
            btnEdit?.classList.remove('hidden');
        }
        clipView.refresh();
    }

    function renderTabs() {
        if (!tabsEl) return;

        tabsEl.innerHTML = boards.map(b => `
            <button
                type="button"
                class="clip-widget-tab mono text-xs px-2 py-1 rounded transition-colors truncate max-w-[8rem]
                    ${b.id === activeId ? 'clip-tab-active' : 'clip-tab'}"
                data-id="${b.id}"
            >${escHtml(b.name)}</button>
        `).join('');

        tabsEl.querySelectorAll('.clip-widget-tab').forEach(btn => {
            btn.addEventListener('click', () => loadBoard(parseInt(btn.dataset.id, 10)));
        });
    }

    async function loadBoardList() {
        setStatus('Loading…');

        const { res, data } = await api('/api/clipboards?pinned=1');

        if (!res.ok) {
            boards = [];
            showEmpty(true);
            setStatus('Unavailable');
            return;
        }

        boards = data.clipboards || [];

        if (boards.length === 0) {
            activeId = null;
            if (editor) editor.value = '';
            showEmpty(true);
            setStatus('');
            stopPoll();
            return;
        }

        showEmpty(false);
        renderTabs();

        if (activeId === null || !boards.find(b => b.id === activeId)) {
            await loadBoard(boards[0].id);
        }
    }

    async function loadBoard(id, sinceVersion = null) {
        let url = `/api/clipboards/${id}`;
        if (sinceVersion !== null) url += `?since_version=${sinceVersion}`;

        const { res, data } = await api(url);
        if (!res.ok) {
            setStatus('Load failed');
            return;
        }

        const board = data.clipboard;
        if (!board || board.unchanged) return;

        activeId = board.id;
        localVersion = board.content_version;
        canWrite = board.can_write !== false;
        dirty = false;

        if (editor) {
            editor.value = board.content || '';
            editor.readOnly = !canWrite;
        }
        updateClipViewAccess();
        const summary = boards.find(b => b.id === board.id);
        if (shareLink && summary?.slug) {
            shareLink.href = `/settings/clipboards?board=${encodeURIComponent(summary.slug)}#sharing`;
            shareLink.classList.remove('hidden');
        } else if (shareLink) {
            shareLink.href = '/settings/clipboards#sharing';
        }
        renderTabs();
        setStatus(canWrite ? 'Synced' : 'Read-only');
        remoteEl?.classList.add('hidden');
        startPoll();
    }

    async function saveNow() {
        if (activeId === null || saving || !canWrite) return;
        const board = boards.find(b => b.id === activeId);
        if (!board?.auto_save && !dirty) return;

        saving = true;
        setStatus('Saving…');

        const { res, data } = await api(`/api/clipboards/${activeId}/save`, {
            method: 'POST',
            body: JSON.stringify({
                content: editor?.value ?? '',
                version: localVersion,
            }),
        });

        saving = false;

        if (res.status === 409) {
            setStatus('Conflict');
            remoteEl?.classList.remove('hidden');
            return;
        }

        if (!res.ok) {
            setStatus(data.error || 'Save failed');
            return;
        }

        localVersion = data.clipboard.content_version;
        dirty = false;
        setStatus('Saved');
    }

    function scheduleSave() {
        if (!canWrite) return;
        const board = boards.find(b => b.id === activeId);
        if (!board?.auto_save) return;
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveNow, AUTOSAVE_DEBOUNCE_MS);
    }

    async function poll() {
        if (activeId === null) return;

        const { res, data } = await api(`/api/clipboards/${activeId}?since_version=${localVersion}`);
        if (!res.ok) return;

        const board = data.clipboard;
        if (!board || board.unchanged) return;

        if (dirty) {
            remoteEl?.classList.remove('hidden');
            return;
        }

        localVersion = board.content_version;
        if (editor) editor.value = board.content || '';
        clipView.refresh();
        setStatus('Updated remotely');
    }

    function pollMs() {
        return document.hidden ? POLL_SLOW_MS : POLL_FAST_MS;
    }

    function startPoll() {
        stopPoll();
        const tick = async () => {
            await poll();
            pollTimer = setTimeout(tick, pollMs());
        };
        pollTimer = setTimeout(tick, pollMs());
    }

    function stopPoll() {
        clearTimeout(pollTimer);
    }

    editor?.addEventListener('input', () => {
        if (!canWrite) return;
        dirty = true;
        setStatus('Editing…');
        scheduleSave();
    });

    editor?.addEventListener('blur', () => {
        if (dirty && canWrite) {
            saveNow();
        }
    });

    root.querySelector('#clip-widget-reload')?.addEventListener('click', () => {
        dirty = false;
        remoteEl?.classList.add('hidden');
        if (activeId) loadBoard(activeId);
    });

    root.querySelector('#clip-widget-copy')?.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(editor?.value ?? '');
            setStatus('Copied');
        } catch {
            setStatus('Copy failed');
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (activeId) {
            stopPoll();
            startPoll();
        }
    });

    loadBoardList();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initClipboardWidget);
} else {
    initClipboardWidget();
}

})();
