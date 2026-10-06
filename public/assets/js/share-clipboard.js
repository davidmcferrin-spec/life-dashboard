/**
 * share-clipboard.js — public read/write access via share token
 */
'use strict';

const token = window.SHARE_CLIPBOARD_TOKEN || '';
const POLL_MS = 5000;

let localVersion = 0;
let canWrite = false;
let dirty = false;
let saveTimer = null;
let clipView = null;

const el = {
    wrap:   document.getElementById('share-editor-wrap'),
    name:   document.getElementById('share-board-name'),
    hint:   document.getElementById('share-perm-hint'),
    editor: document.getElementById('share-editor'),
    status: document.getElementById('share-status'),
    save:   document.getElementById('share-save'),
    copy:   document.getElementById('share-copy'),
    remote: document.getElementById('share-remote-banner'),
    reload: document.getElementById('share-reload'),
};

function initClipView() {
    if (!window.ClipboardMarkdown) {
        clipView = { getMode: () => 'edit', setMode: () => {}, refresh: () => {} };
        return;
    }
    clipView = window.ClipboardMarkdown.attach({
        editor: el.editor,
        preview: document.getElementById('share-preview'),
        btnEdit: document.getElementById('share-view-edit'),
        btnPreview: document.getElementById('share-view-preview'),
        canWrite: true,
    });
}

function updateClipViewAccess() {
    const btnEdit = document.getElementById('share-view-edit');
    if (!canWrite) {
        clipView?.setMode('preview');
        btnEdit?.classList.add('hidden');
    } else {
        btnEdit?.classList.remove('hidden');
    }
    clipView?.refresh();
}

function denyAccess() {
    window.location.replace('/share/denied');
}

function setStatus(text) {
    if (el.status) el.status.textContent = text;
}

async function api(path, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(path, { ...options, headers, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

async function loadBoard(sinceVersion = null) {
    if (!token) {
        denyAccess();
        return;
    }

    let url = `/api/share/clipboard/${token}`;
    if (sinceVersion !== null) url += `?since_version=${sinceVersion}`;

    const { res, data } = await api(url);
    if (res.status === 403 || !res.ok) {
        denyAccess();
        return;
    }

    const board = data.clipboard;
    if (!board || board.unchanged) return;

    canWrite = !!data.can_write;
    localVersion = board.content_version;
    dirty = false;

    el.wrap?.classList.remove('hidden');
    if (el.name) el.name.textContent = board.name || 'Clipboard';
    if (el.hint) {
        el.hint.textContent = canWrite
            ? 'You can view and edit this clipboard via the share link.'
            : 'Read-only — viewing via share link.';
    }
    if (el.editor) {
        el.editor.value = board.content || '';
        el.editor.readOnly = !canWrite;
    }
    updateClipViewAccess();
    el.save?.classList.toggle('hidden', !canWrite);
    el.remote?.classList.add('hidden');
    setStatus('Loaded');
}

async function saveNow() {
    if (!canWrite || !token) return;

    setStatus('Saving…');
    const { res, data } = await api(`/api/share/clipboard/${token}/save`, {
        method: 'POST',
        body: JSON.stringify({
            content: el.editor?.value ?? '',
            version: localVersion,
        }),
    });

    if (res.status === 403) {
        denyAccess();
        return;
    }

    if (res.status === 409) {
        el.remote?.classList.remove('hidden');
        setStatus('Conflict');
        return;
    }

    if (!res.ok) {
        denyAccess();
        return;
    }

    dirty = false;
    localVersion = data.clipboard?.content_version ?? localVersion;
    setStatus('Saved');
}

function scheduleSave() {
    if (!canWrite) return;
    dirty = true;
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNow, 800);
}

el.editor?.addEventListener('input', scheduleSave);
el.save?.addEventListener('click', saveNow);
el.reload?.addEventListener('click', () => loadBoard(localVersion));

el.copy?.addEventListener('click', () => {
    const text = el.editor?.value ?? '';
    navigator.clipboard?.writeText(text).then(() => setStatus('Copied')).catch(() => {});
});

setInterval(async () => {
    if (!token) return;

    const { res, data } = await api(`/api/share/clipboard/${token}?since_version=${localVersion}`);
    if (res.status === 403 || !res.ok) {
        denyAccess();
        return;
    }

    const board = data.clipboard;
    if (!board || board.unchanged) return;

    if (dirty) {
        el.remote?.classList.remove('hidden');
        return;
    }

    localVersion = board.content_version;
    if (el.editor) el.editor.value = board.content || '';
    clipView?.refresh();
    setStatus('Updated');
}, POLL_MS);

initClipView();
loadBoard();
