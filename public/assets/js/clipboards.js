/**
 * clipboards.js — full-page cloud clipboard editor
 *
 * Plain text edit + Markdown preview toggle, per-board auto-save, versioned sync via polling.
 */

'use strict';

const $ = (sel) => document.querySelector(sel);

const POLL_FAST_MS = 3000;
const POLL_SLOW_MS = 30000;
const AUTOSAVE_DEBOUNCE_MS = 1200;

let boards = [];
let activeId = null;
let localVersion = 0;
let canWrite = true;
let isOwner = true;
let dirty = false;
let saveTimer = null;
let pollTimer = null;
let saving = false;
let clipView = null;

const el = {
    list:           $('#clip-board-list'),
    editor:         $('#clip-editor'),
    name:           $('#clip-board-name'),
    pin:            $('#clip-pin-dashboard'),
    autoSave:       $('#clip-auto-save'),
    saveManual:     $('#clip-save-manual'),
    saveStatus:     $('#clip-save-status'),
    remoteBanner:   $('#clip-remote-banner'),
    remoteReload:   $('#clip-remote-reload'),
    newBoard:       $('#clip-new-board'),
    newBoardForm:   $('#clip-new-board-form'),
    newBoardName:   $('#clip-new-board-name'),
    newBoardCancel: $('#clip-new-board-cancel'),
    deleteBoard:    $('#clip-delete-board'),
    copyLocal:      $('#clip-copy-local'),
    sharingJump:    $('#clip-sharing-jump'),
    sharing:        $('#clip-sharing'),
    sharingHelp:    $('#clip-sharing-help'),
    sharingUnavailable: $('#clip-sharing-unavailable'),
    shareLinks:     $('#clip-share-links'),
    shareUsers:     $('#clip-share-users'),
    shareLinkForm:  $('#clip-share-link-form'),
    shareUserForm:  $('#clip-share-user-form'),
};

function initClipView() {
    if (!window.ClipboardMarkdown) {
        clipView = { getMode: () => 'edit', setMode: () => {}, refresh: () => {} };
        return;
    }
    clipView = window.ClipboardMarkdown.attach({
        editor: el.editor,
        preview: document.getElementById('clip-preview'),
        btnEdit: document.getElementById('clip-view-edit'),
        btnPreview: document.getElementById('clip-view-preview'),
        canWrite: true,
    });
}

function updateClipViewAccess() {
    const btnEdit = document.getElementById('clip-view-edit');
    if (!canWrite) {
        clipView?.setMode('preview');
        btnEdit?.classList.add('hidden');
    } else {
        btnEdit?.classList.remove('hidden');
    }
    clipView?.refresh();
}

function setStatus(text, tone = 'muted') {
    if (!el.saveStatus) return;
    el.saveStatus.textContent = text;
    el.saveStatus.className = 'mono text-xs ' + (
        tone === 'ok' ? 'text-ok' :
        tone === 'warn' ? 'text-warn' :
        tone === 'err' ? 'text-danger' :
        'text-secondary'
    );
}

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

async function api(url, options = {}) {
    const headers = {
        'Accept': 'application/json',
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
    } catch (_) {
        if (text.trim()) {
            data = { error: text.trim().slice(0, 240) };
        }
    }
    return { res, data };
}

function activeBoard() {
    return boards.find(b => b.id === activeId) ?? null;
}

function renderBoardList() {
    if (!el.list) return;

    if (boards.length === 0) {
        el.list.innerHTML = '<li class="text-faint mono text-xs py-2">No boards</li>';
        return;
    }

    el.list.innerHTML = boards.map(b => `
        <li>
            <button
                type="button"
                class="clip-board-item w-full text-left px-2 py-1.5 rounded mono text-xs transition-colors truncate
                    ${b.id === activeId ? 'clip-tab-active' : 'clip-tab'}"
                data-id="${b.id}"
            >
                ${escHtml(b.name)}
                ${b.pin_to_dashboard ? '<span class="text-faint ml-1" title="Pinned to dashboard">📌</span>' : ''}
                ${b.is_owner === false ? '<span class="text-faint ml-1" title="Shared with you">↗</span>' : ''}
            </button>
        </li>
    `).join('');

    el.list.querySelectorAll('.clip-board-item').forEach(btn => {
        btn.addEventListener('click', () => selectBoard(parseInt(btn.dataset.id, 10)));
    });
}

function applySettingsToForm(board) {
    if (!board) return;
    if (el.name) {
        el.name.value = board.name;
        el.name.readOnly = !isOwner;
    }
    if (el.pin) {
        el.pin.checked = !!board.pin_to_dashboard;
        el.pin.disabled = !isOwner;
    }
    if (el.autoSave) {
        el.autoSave.checked = !!board.auto_save;
        el.autoSave.disabled = !isOwner;
    }
    if (el.editor) el.editor.readOnly = !canWrite;
    el.deleteBoard?.classList.toggle('hidden', !isOwner);
    el.newBoard?.classList.remove('hidden');
    toggleManualSave();
}

function apiErrorMessage(data, res, fallback) {
    if (data?.error) {
        return String(data.error);
    }
    if (res.status === 403) {
        return 'Session expired or invalid CSRF token — refresh the page and try again.';
    }
    if (res.status === 404) {
        return 'Not found — refresh the page.';
    }
    return fallback;
}

function applyAccessFromBoard(board) {
    canWrite = board.can_write !== false;
    isOwner = board.is_owner !== false;
    el.sharing?.classList.toggle('hidden', !isOwner);
    el.sharingJump?.classList.toggle('hidden', !isOwner);
}

function toggleManualSave() {
    if (!el.saveManual) return;
    const autoOn = el.autoSave ? el.autoSave.checked : true;
    if (autoOn) {
        el.saveManual.classList.add('hidden');
    } else {
        el.saveManual.classList.remove('hidden');
    }
}

async function loadBoardList(selectId = null) {
    const { res, data } = await api('/api/clipboards');

    if (!res.ok) {
        setStatus(apiErrorMessage(data, res, 'Could not load boards.'), 'err');
        if (el.list) {
            el.list.innerHTML = '<li class="text-danger mono text-xs py-2">Could not load boards</li>';
        }
        return;
    }

    boards = data.clipboards || [];
    renderBoardList();

    if (selectId) {
        await selectBoard(selectId);
        return;
    }

    const slug = window.CLIPBOARDS_INITIAL_SLUG || '';
    if (slug) {
        const match = boards.find(b => b.slug === slug);
        if (match) {
            await selectBoard(match.id);
            return;
        }
    }

    if (boards.length > 0 && activeId === null) {
        await selectBoard(boards[0].id);
    }
}

async function fetchBoard(id, sinceVersion = null) {
    let url = `/api/clipboards/${id}`;
    if (sinceVersion !== null) {
        url += `?since_version=${sinceVersion}`;
    }
    return api(url);
}

async function selectBoard(id) {
    if (dirty && activeId !== null && activeId !== id) {
        const ok = await saveNow();
        if (!ok) return;
    }

    stopPolling();

    const { res, data } = await fetchBoard(id);

    if (!res.ok) {
        setStatus('Could not load clipboard.', 'err');
        return;
    }

    const board = data.clipboard;
    if (!board || board.unchanged) {
        return;
    }

    activeId = board.id;
    localVersion = board.content_version;
    dirty = false;

    if (el.editor) el.editor.value = board.content || '';
    applyAccessFromBoard(board);
    applySettingsToForm(board);
    updateClipViewAccess();
    hideRemoteBanner();
    renderBoardList();

    if (isOwner) {
        loadShares(id);
        scrollToSharingFromHash();
    }

    const summary = boards.find(b => b.id === id);
    if (summary) {
        summary.content_version = board.content_version;
        summary.updated_at = board.updated_at;
    }

    setStatus(canWrite ? (isOwner ? 'Synced' : 'Synced (shared)') : 'Read-only (shared)');
    startPolling();
}

async function loadShares(boardId) {
    if (!el.shareLinks || !el.shareUsers) return;

    el.sharingUnavailable?.classList.add('hidden');
    el.sharingHelp?.classList.remove('hidden');
    el.shareLinkForm?.classList.remove('hidden');
    el.shareUserForm?.classList.remove('hidden');

    const { res, data } = await api(`/api/clipboards/${boardId}/shares`);
    if (!res.ok) {
        const message = data.error || 'Could not load shares.';
        if (res.status === 422 || res.status === 404) {
            el.sharingUnavailable?.classList.remove('hidden');
            if (el.sharingUnavailable) el.sharingUnavailable.textContent = message;
            el.sharingHelp?.classList.add('hidden');
            el.shareLinkForm?.classList.add('hidden');
            el.shareUserForm?.classList.add('hidden');
        }
        el.shareLinks.innerHTML = '<li class="text-faint">Could not load shares.</li>';
        el.shareUsers.innerHTML = '';
        return;
    }

    const links = data.links || [];
    const users = data.users || [];

    el.shareLinks.innerHTML = links.length === 0
        ? '<li class="text-faint">No active links</li>'
        : links.map(l => `
            <li class="surface px-2 py-2 rounded flex flex-col gap-1">
                <span class="text-secondary">${escHtml(l.permission)}${l.expires_at ? ' · expires ' + escHtml(l.expires_at) : ''}</span>
                <input type="text" readonly class="input mono text-xs w-full" value="${escHtml(l.url)}" onclick="this.select()">
                <button type="button" class="text-danger text-left revoke-link" data-id="${l.id}">Revoke</button>
            </li>
        `).join('');

    el.shareUsers.innerHTML = users.length === 0
        ? '<li class="text-faint">Not shared with anyone</li>'
        : users.map(u => `
            <li class="flex items-center justify-between gap-2 surface px-2 py-2 rounded">
                <span>${escHtml(u.username)} <span class="text-faint">(${escHtml(u.permission)})</span></span>
                <button type="button" class="text-danger revoke-user" data-id="${u.id}">Remove</button>
            </li>
        `).join('');

    el.shareLinks.querySelectorAll('.revoke-link').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Revoke this link?')) return;
            await api(`/api/clipboards/${boardId}/shares/link/${btn.dataset.id}/revoke`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            loadShares(boardId);
        });
    });

    el.shareUsers.querySelectorAll('.revoke-user').forEach(btn => {
        btn.addEventListener('click', async () => {
            await api(`/api/clipboards/${boardId}/shares/user/${btn.dataset.id}/remove`, {
                method: 'POST',
                body: JSON.stringify({}),
            });
            loadShares(boardId);
        });
    });
}

async function saveNow() {
    if (activeId === null || saving || !canWrite) return true;

    saving = true;
    setStatus('Saving…');

    const { res, data } = await api(`/api/clipboards/${activeId}/save`, {
        method: 'POST',
        body: JSON.stringify({
            content: el.editor?.value ?? '',
            version: localVersion,
        }),
    });

    saving = false;

    if (res.status === 403) {
        setStatus('Read-only access.', 'err');
        return false;
    }

    if (res.status === 409) {
        setStatus('Conflict — reload required.', 'warn');
        showRemoteBanner(data.clipboard);
        return false;
    }

    if (!res.ok) {
        setStatus(apiErrorMessage(data, res, 'Save failed.'), 'err');
        return false;
    }

    const board = data.clipboard;
    localVersion = board.content_version;
    dirty = false;

    const idx = boards.findIndex(b => b.id === activeId);
    if (idx >= 0) {
        boards[idx].content_version = board.content_version;
        boards[idx].updated_at = board.updated_at;
        boards[idx].preview = (board.content || '').replace(/\s+/g, ' ').slice(0, 120);
    }

    setStatus('Saved', 'ok');
    return true;
}

function scheduleAutosave() {
    if (!el.autoSave?.checked) return;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
        if (dirty) saveNow();
    }, AUTOSAVE_DEBOUNCE_MS);
}

function showRemoteBanner(remoteBoard) {
    if (!el.remoteBanner) return;
    el.remoteBanner.classList.remove('hidden');
    el.remoteBanner.dataset.remoteVersion = remoteBoard?.content_version ?? '';
}

function hideRemoteBanner() {
    el.remoteBanner?.classList.add('hidden');
}

async function pollRemote() {
    if (activeId === null) return;

    const { res, data } = await fetchBoard(activeId, localVersion);

    if (!res.ok) return;

    const board = data.clipboard;
    if (!board) return;

    if (board.unchanged) return;

    if (dirty) {
        showRemoteBanner(board);
        return;
    }

    localVersion = board.content_version;
    if (el.editor) el.editor.value = board.content || '';
    clipView?.refresh();
    setStatus('Updated from another device', 'ok');
}

function pollInterval() {
    return document.hidden ? POLL_SLOW_MS : POLL_FAST_MS;
}

function startPolling() {
    stopPolling();
    const tick = async () => {
        await pollRemote();
        pollTimer = setTimeout(tick, pollInterval());
    };
    pollTimer = setTimeout(tick, pollInterval());
}

function stopPolling() {
    clearTimeout(pollTimer);
    pollTimer = null;
}

async function patchSettings(fields) {
    if (activeId === null || !isOwner) return;

    const { res, data } = await api(`/api/clipboards/${activeId}/settings`, {
        method: 'POST',
        body: JSON.stringify(fields),
    });

    if (!res.ok) {
        setStatus(data.error || 'Settings update failed.', 'err');
        return;
    }

    const board = data.clipboard;
    const idx = boards.findIndex(b => b.id === activeId);
    if (idx >= 0) {
        boards[idx].name = board.name;
        boards[idx].slug = board.slug;
        boards[idx].pin_to_dashboard = board.pin_to_dashboard;
        boards[idx].auto_save = board.auto_save;
    }
    renderBoardList();
    setStatus('Settings saved', 'ok');
}

async function createBoard(name) {
    const trimmed = (name || '').trim();
    if (!trimmed) return;

    setStatus('Creating board…');

    const { res, data } = await api('/api/clipboards/create', {
        method: 'POST',
        body: JSON.stringify({ name: trimmed }),
    });

    if (!res.ok) {
        setStatus(apiErrorMessage(data, res, 'Could not create board.'), 'err');
        return;
    }

    hideNewBoardForm();
    await loadBoardList(data.clipboard?.id ?? null);
    setStatus('Board created', 'ok');
}

function showNewBoardForm() {
    el.newBoardForm?.classList.remove('hidden');
    el.newBoard?.classList.add('hidden');
    if (el.newBoardName) {
        el.newBoardName.value = '';
        el.newBoardName.focus();
    }
}

function hideNewBoardForm() {
    el.newBoardForm?.classList.add('hidden');
    el.newBoard?.classList.remove('hidden');
    if (el.newBoardName) el.newBoardName.value = '';
}

el.shareLinkForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (activeId === null || !isOwner) return;

    const { res, data } = await api(`/api/clipboards/${activeId}/shares/link`, {
        method: 'POST',
        body: JSON.stringify({
            permission: document.getElementById('clip-link-permission')?.value || 'read',
            expires_in: document.getElementById('clip-link-expiry')?.value || null,
            label: document.getElementById('clip-link-label')?.value || '',
        }),
    });

    if (!res.ok) {
        alert(data.error || 'Could not create link.');
        return;
    }

    if (data.link?.url) {
        prompt('Copy this share link (shown once in list below):', data.link.url);
    }
    loadShares(activeId);
});

el.shareUserForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (activeId === null || !isOwner) return;

    const { res, data } = await api(`/api/clipboards/${activeId}/shares/user`, {
        method: 'POST',
        body: JSON.stringify({
            identifier: document.getElementById('clip-share-identifier')?.value || '',
            permission: document.getElementById('clip-user-permission')?.value || 'read',
        }),
    });

    if (!res.ok) {
        alert(data.error || 'Could not share.');
        return;
    }

    document.getElementById('clip-share-identifier').value = '';
    loadShares(activeId);
    await loadBoardList(activeId);
});

async function deleteBoard() {
    if (activeId === null || !isOwner) return;
    const board = activeBoard();
    if (!board) return;

    if (!confirm(`Delete "${board.name}"? This cannot be undone.`)) return;

    const { res, data } = await api(`/api/clipboards/${activeId}/delete`, {
        method: 'POST',
        body: JSON.stringify({}),
    });

    if (!res.ok) {
        alert(data.error || 'Delete failed.');
        return;
    }

    activeId = null;
    dirty = false;
    stopPolling();
    if (el.editor) el.editor.value = '';
    await loadBoardList();
    if (boards.length > 0) {
        await selectBoard(boards[0].id);
    } else {
        setStatus('No clipboards');
    }
}

// Event wiring
el.editor?.addEventListener('input', () => {
    if (!canWrite) return;
    dirty = true;
    setStatus(el.autoSave?.checked ? 'Editing…' : 'Unsaved changes', el.autoSave?.checked ? 'muted' : 'warn');
    if (el.autoSave?.checked) {
        scheduleAutosave();
    }
});

el.editor?.addEventListener('blur', () => {
    if (dirty && canWrite && (el.autoSave?.checked ?? true)) {
        saveNow();
    }
});

el.autoSave?.addEventListener('change', () => {
    toggleManualSave();
    patchSettings({ auto_save: el.autoSave.checked });
    if (el.autoSave.checked && dirty) scheduleAutosave();
});

el.pin?.addEventListener('change', () => {
    patchSettings({ pin_to_dashboard: el.pin.checked });
});

el.name?.addEventListener('change', () => {
    const name = el.name.value.trim();
    if (name) patchSettings({ name });
});

el.saveManual?.addEventListener('click', () => saveNow());

el.copyLocal?.addEventListener('click', async () => {
    const text = el.editor?.value ?? '';
    try {
        await navigator.clipboard.writeText(text);
        setStatus('Copied to local clipboard', 'ok');
    } catch {
        setStatus('Copy failed — check browser permissions', 'err');
    }
});

el.remoteReload?.addEventListener('click', () => {
    dirty = false;
    hideRemoteBanner();
    if (activeId !== null) selectBoard(activeId);
});

function scrollToSharing() {
    if (activeId === null || !isOwner || !el.sharing) {
        return;
    }
    el.sharing.classList.remove('hidden');
    el.sharing.scrollIntoView({ behavior: 'smooth', block: 'start' });
    const base = window.location.pathname + window.location.search;
    if (window.location.hash !== '#sharing') {
        history.replaceState(null, '', base + '#sharing');
    }
}

el.newBoard?.addEventListener('click', showNewBoardForm);
el.newBoardCancel?.addEventListener('click', hideNewBoardForm);
el.newBoardForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    await createBoard(el.newBoardName?.value ?? '');
});
el.sharingJump?.addEventListener('click', scrollToSharing);
el.deleteBoard?.addEventListener('click', deleteBoard);

document.addEventListener('visibilitychange', () => {
    if (activeId !== null) {
        stopPolling();
        startPolling();
    }
});

function scrollToSharingFromHash() {
    if (window.location.hash !== '#sharing') {
        return;
    }
    scrollToSharing();
}

window.addEventListener('hashchange', scrollToSharingFromHash);

function bootClipboards() {
    if (window.CLIPBOARDS_INITIAL_TAB && window.CLIPBOARDS_INITIAL_TAB !== 'boards') {
        return;
    }
    initClipView();
    toggleManualSave();
    loadBoardList().then(() => {
        toggleManualSave();
        scrollToSharingFromHash();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootClipboards);
} else {
    bootClipboards();
}
