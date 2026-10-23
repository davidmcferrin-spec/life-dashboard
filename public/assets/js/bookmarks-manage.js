'use strict';

async function api(url, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    if (options.method && options.method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = getCsrfToken();
    }
    const res = await fetch(url, { ...options, headers, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    return { res, data };
}

function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function linkHost(url) {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch (_) {
        return url;
    }
}

function openModal(modal) {
    if (!modal) {
        return;
    }
    modal.classList.remove('hidden');
    document.body.classList.add('app-modal-open');
}

function closeModal(modal) {
    if (!modal) {
        return;
    }
    modal.classList.add('hidden');
    if (!document.querySelector('.app-modal:not(.hidden)')) {
        document.body.classList.remove('app-modal-open');
    }
}

function bindModal(modal, onClose) {
    if (!modal) {
        return;
    }
    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-close]')) {
            if (onClose) {
                onClose();
            } else {
                closeModal(modal);
            }
        }
    });
}

function clearFieldError(id) {
    const el = document.getElementById(id);
    if (!el) {
        return;
    }
    el.textContent = '';
    el.classList.add('hidden');
}

function showFieldError(id, message) {
    const el = document.getElementById(id);
    if (!el) {
        alert(message);
        return;
    }
    el.textContent = message;
    el.classList.remove('hidden');
}

const folderAddModal = document.getElementById('bm-folder-add-modal');
const linkAddModal = document.getElementById('bm-link-add-modal');
const folderRenameModal = document.getElementById('bm-folder-rename-modal');
const editModal = document.getElementById('bm-edit-modal');
bindModal(folderAddModal);
bindModal(linkAddModal);
bindModal(folderRenameModal);
bindModal(editModal, () => closeEdit());

const faviconClient = () => window.BookmarkFaviconClient;
const FAVICON_MAX_BYTES = () => faviconClient()?.FAVICON_MAX_BYTES ?? 1048576;

let bmFolders = [];
let bmUnfiled = [];
const collapsedFolders = new Set();
let editingId = null;
let backfillStarted = false;

function collectAllBookmarks() {
    const all = [...bmUnfiled];
    bmFolders.forEach(folder => {
        (folder.bookmarks || []).forEach(bm => all.push(bm));
    });

    return all;
}

async function runBackfillIfNeeded() {
    if (backfillStarted || !faviconClient()) {
        return;
    }

    const missing = collectAllBookmarks().filter(bm => !bm.has_favicon);
    if (missing.length === 0) {
        return;
    }

    backfillStarted = true;
    const status = document.getElementById('bm-backfill-status');

    await faviconClient().backfillMissingIcons(collectAllBookmarks(), (index, total) => {
        if (status) {
            status.textContent = `Fetching missing icons… ${index}/${total}`;
        }
    });

    if (status) {
        status.textContent = '';
    }

    await load({ skipBackfill: true });
}

function folderOptions(selectedId = null) {
    let html = '<option value="">Unfiled</option>';
    bmFolders.forEach(f => {
        const sel = selectedId !== null && String(f.id) === String(selectedId) ? ' selected' : '';
        html += `<option value="${f.id}"${sel}>${esc(f.name)}</option>`;
    });
    return html;
}

function syncFolderSelects() {
    const addSel = document.getElementById('bm-add-folder-select');
    const editSel = document.getElementById('bm-edit-folder-select');
    const addVal = addSel?.value ?? '';
    const editVal = editSel?.value ?? '';
    if (addSel) {
        addSel.innerHTML = folderOptions(addVal === '' ? null : addVal);
    }
    if (editSel) {
        editSel.innerHTML = folderOptions(editVal === '' ? null : editVal);
    }
}

function bookmarkRow(bm) {
    const iconSrc = bm.has_favicon && bm.favicon_href ? esc(bm.favicon_href) : null;
    const favicon = iconSrc
        ? `<img src="${iconSrc}" alt="" class="bm-row-icon" loading="lazy">`
        : '<span class="bm-row-dot">›</span>';

    return `
        <button type="button" class="bm-row w-full text-left" data-id="${bm.id}">
            ${favicon}
            <span class="bm-row-title truncate">${esc(bm.title)}</span>
            <span class="bm-row-host truncate">${esc(linkHost(bm.url))}</span>
        </button>
    `;
}

function folderSection(folder) {
    const collapsed = collapsedFolders.has(folder.id);
    const count = (folder.bookmarks || []).length;
    const icon = folder.icon ? esc(folder.icon) + ' ' : '';

    return `
        <section class="settings-block" data-folder-id="${folder.id}">
            <div class="flex items-center gap-2 mb-1">
                <button type="button" class="bm-folder-toggle flex items-center gap-1.5 flex-1 min-w-0 text-left" data-id="${folder.id}" aria-expanded="${!collapsed}">
                    <span class="bm-folder-arrow mono text-xs text-faint${collapsed ? '' : ' bm-folder-open'}">▶</span>
                    <span class="text-sm font-medium text-primary truncate">${icon}${esc(folder.name)}</span>
                    <span class="text-faint mono text-xs ml-1">${count}</span>
                </button>
                <button type="button" class="bm-folder-rename link text-xs flex-shrink-0" data-id="${folder.id}" data-name="${esc(folder.name)}" data-icon="${esc(folder.icon || '')}">Rename</button>
                <button type="button" class="bm-folder-delete text-danger text-xs flex-shrink-0" data-id="${folder.id}">Delete</button>
            </div>
            <div class="bm-folder-body ml-4 space-y-0.5${collapsed ? ' hidden' : ''}" data-folder-body="${folder.id}">
                ${count === 0
                    ? '<p class="text-faint mono text-xs py-1">No links yet.</p>'
                    : (folder.bookmarks || []).map(bookmarkRow).join('')}
            </div>
        </section>
    `;
}

function render() {
    const root = document.getElementById('bm-manage-root');
    if (!root) {
        return;
    }

    syncFolderSelects();

    if (bmFolders.length === 0 && bmUnfiled.length === 0) {
        root.innerHTML = window.DashboardEmpty.html({
            title: 'No bookmarks yet',
            body: 'Add a folder or a link.',
            actionLabel: 'Bookmarks',
            actionHref: '/settings/bookmarks',
        });
        return;
    }

    let html = bmFolders.map(folderSection).join('');

    html += `
        <section class="settings-block">
            <h3 class="section-title text-sm mb-2">Unfiled <span class="text-faint mono text-xs font-normal">${bmUnfiled.length}</span></h3>
            <div class="space-y-0.5">
                ${bmUnfiled.length === 0
                    ? '<p class="text-faint mono text-xs py-1">No unfiled links.</p>'
                    : bmUnfiled.map(bookmarkRow).join('')}
            </div>
        </section>
    `;

    root.innerHTML = html;
    bindListEvents();
}

function bindListEvents() {
    document.querySelectorAll('.bm-folder-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id, 10);
            const body = document.querySelector(`[data-folder-body="${id}"]`);
            const arrow = btn.querySelector('.bm-folder-arrow');
            if (collapsedFolders.has(id)) {
                collapsedFolders.delete(id);
                body?.classList.remove('hidden');
                arrow?.classList.add('bm-folder-open');
                btn.setAttribute('aria-expanded', 'true');
            } else {
                collapsedFolders.add(id);
                body?.classList.add('hidden');
                arrow?.classList.remove('bm-folder-open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
    });

    document.querySelectorAll('.bm-folder-rename').forEach(btn => {
        btn.addEventListener('click', () => {
            openFolderRename(btn.dataset.id, btn.dataset.name || '', btn.dataset.icon || '');
        });
    });

    document.querySelectorAll('.bm-folder-delete').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (btn.dataset.confirming === '1') {
                return;
            }
            btn.dataset.confirming = '1';
            try {
                const ok = await globalThis.AppModal.confirm({
                    title: 'Delete folder',
                    message: 'Delete this folder? Links inside become unfiled.',
                    confirmLabel: 'Delete',
                    danger: true,
                });
                if (!ok) {
                    return;
                }
                const { res, data } = await api(`/api/bookmarks/folders/${btn.dataset.id}/delete`, {
                    method: 'POST',
                    body: '{}',
                });
                if (!res.ok) {
                    alert(data.error || 'Could not delete folder.');
                    return;
                }
                load();
            } finally {
                if (btn.isConnected) {
                    btn.dataset.confirming = '';
                }
            }
        });
    });

    document.querySelectorAll('.bm-row').forEach(btn => {
        btn.addEventListener('click', () => openEdit(parseInt(btn.dataset.id, 10)));
    });
}

function findBookmark(id) {
    for (const folder of bmFolders) {
        for (const bm of folder.bookmarks || []) {
            if (bm.id === id) {
                return bm;
            }
        }
    }
    for (const bm of bmUnfiled) {
        if (bm.id === id) {
            return bm;
        }
    }
    return null;
}

function updateIconPreview(bm) {
    const img      = document.getElementById('bm-edit-icon-preview');
    const empty    = document.getElementById('bm-edit-icon-empty');
    const external = document.getElementById('bm-edit-icon-external-preview');
    if (!img || !empty) {
        return;
    }
    if (bm?.has_favicon && bm.favicon_href) {
        img.src = bm.favicon_href + (bm.favicon_href.includes('?') ? '&' : '?') + 't=' + Date.now();
        img.classList.remove('hidden');
        empty.classList.add('hidden');
        external?.removeAttribute('src');
        external?.classList.add('hidden');
    } else {
        img.removeAttribute('src');
        img.classList.add('hidden');
        empty.classList.remove('hidden');
        if (bm?.url && bm?.id) {
            showExternalIconPreview(bm.url, bm.id);
        }
    }
}

function iconCaptureUi() {
    return {
        statusEl: document.getElementById('bm-fetch-icon-status'),
        linksEl: document.getElementById('bm-icon-candidates'),
        previewEl: document.getElementById('bm-edit-icon-external-preview'),
    };
}

function iconCaptureOptions(autoOpenOnFail = false) {
    const ui = iconCaptureUi();

    return {
        autoOpenOnFail,
        linksEl: ui.linksEl,
        previewEl: ui.previewEl,
    };
}

function resetIconCaptureUi() {
    const ui = iconCaptureUi();
    if (ui.statusEl) {
        ui.statusEl.textContent = '';
    }
    ui.linksEl?.classList.add('hidden');
    ui.linksEl?.replaceChildren('');
    ui.previewEl?.removeAttribute('src');
    ui.previewEl?.classList.add('hidden');
}

async function showExternalIconPreview(pageUrl, bookmarkId) {
    const preview = document.getElementById('bm-edit-icon-external-preview');
    if (!preview || !faviconClient()) {
        return;
    }

    const candidates = await faviconClient().fetchIconCandidates(bookmarkId, pageUrl);
    if (candidates.length > 0) {
        preview.src = candidates[0];
        preview.classList.remove('hidden');
    } else {
        preview.removeAttribute('src');
        preview.classList.add('hidden');
    }
}

function openFolderRename(id, name, icon) {
    const idInput = document.getElementById('bm-folder-rename-id');
    const nameInput = document.getElementById('bm-folder-rename-name');
    const iconInput = document.getElementById('bm-folder-rename-icon');
    if (!folderRenameModal || !idInput || !nameInput || !iconInput) {
        return;
    }
    clearFieldError('bm-folder-rename-error');
    idInput.value = String(id);
    nameInput.value = name;
    iconInput.value = icon;
    openModal(folderRenameModal);
}

function openEdit(id) {
    const bm = findBookmark(id);
    if (!bm) {
        return;
    }

    editingId = id;
    clearFieldError('bm-edit-error');
    openModal(editModal);

    document.getElementById('bm-edit-id').value = String(id);
    document.getElementById('bm-edit-title').value = bm.title;
    document.getElementById('bm-edit-url').value = bm.url;
    resetIconCaptureUi();

    const editSel = document.getElementById('bm-edit-folder-select');
    if (editSel) {
        editSel.innerHTML = folderOptions(bm.folder_id);
    }

    updateIconPreview(bm);
    document.getElementById('bm-edit-title')?.focus();
}

function closeEdit() {
    editingId = null;
    closeModal(editModal);
}

async function load(options = {}) {
    const root = document.getElementById('bm-manage-root');
    if (!root) {
        return;
    }

    const { res, data } = await api('/api/bookmarks');
    if (!res.ok) {
        root.innerHTML = '<p class="text-danger mono text-xs">Could not load bookmarks.</p>';
        return;
    }

    bmFolders = data.folders || [];
    bmUnfiled = data.unfiled || [];
    render();

    if (editingId !== null && !findBookmark(editingId)) {
        closeEdit();
    } else if (editingId !== null) {
        openEdit(editingId);
    }

    if (!options.skipBackfill) {
        runBackfillIfNeeded();
    }
}

document.getElementById('bm-folder-add-open')?.addEventListener('click', () => {
    clearFieldError('bm-folder-add-error');
    openModal(folderAddModal);
});

document.getElementById('bm-folder-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    clearFieldError('bm-folder-add-error');
    const { res, data } = await api('/api/bookmarks/folders/create', {
        method: 'POST',
        body: JSON.stringify({
            name: form.name.value.trim(),
            icon: form.icon.value.trim() || null,
        }),
    });
    if (!res.ok) {
        showFieldError('bm-folder-add-error', data.error || 'Could not create folder.');
        return;
    }
    form.reset();
    closeModal(folderAddModal);
    load();
});

document.getElementById('bm-link-add-open')?.addEventListener('click', () => {
    clearFieldError('bm-link-add-error');
    openModal(linkAddModal);
});

document.getElementById('bm-link-add-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const form = e.target;
    const title = form.title.value.trim();
    const pageUrl = form.url.value.trim();
    const folderRaw = form.folder_id.value;
    const fetchIcon = document.getElementById('bm-add-fetch-icon')?.checked ?? true;
    clearFieldError('bm-link-add-error');
    const { res, data } = await api('/api/bookmarks/create', {
        method: 'POST',
        body: JSON.stringify({
            title,
            url: pageUrl,
            folder_id: folderRaw === '' ? null : parseInt(folderRaw, 10),
            fetch_favicon: fetchIcon,
        }),
    });
    if (!res.ok) {
        showFieldError('bm-link-add-error', data.error || 'Could not add link.');
        return;
    }

    form.title.value = '';
    form.url.value = '';
    closeModal(linkAddModal);

    if (fetchIcon && data.bookmark && faviconClient()) {
        if (data.favicon_client_fallback || !data.bookmark.has_favicon) {
            await faviconClient().resolveBookmarkIcon(
                data.bookmark.id,
                pageUrl || data.bookmark.url,
                null,
            );
        }
    }

    load();
});

document.getElementById('bm-folder-rename-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('bm-folder-rename-id')?.value;
    const name = document.getElementById('bm-folder-rename-name')?.value.trim() ?? '';
    const icon = document.getElementById('bm-folder-rename-icon')?.value.trim() ?? '';
    if (name === '') {
        closeModal(folderRenameModal);
        return;
    }
    if (!id) {
        return;
    }
    clearFieldError('bm-folder-rename-error');
    const { res, data } = await api(`/api/bookmarks/folders/${id}/update`, {
        method: 'POST',
        body: JSON.stringify({ name, icon: icon || null }),
    });
    if (!res.ok) {
        showFieldError('bm-folder-rename-error', data.error || 'Could not rename folder.');
        return;
    }
    closeModal(folderRenameModal);
    load();
});

document.getElementById('bm-edit-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const id = document.getElementById('bm-edit-id')?.value;
    const title = document.getElementById('bm-edit-title')?.value.trim() ?? '';
    const url = document.getElementById('bm-edit-url')?.value.trim() ?? '';
    if (!id) {
        return;
    }
    const folderRaw = document.getElementById('bm-edit-folder-select')?.value ?? '';
    clearFieldError('bm-edit-error');
    const { res, data } = await api(`/api/bookmarks/${id}/update`, {
        method: 'POST',
        body: JSON.stringify({
            title,
            url,
            folder_id: folderRaw === '' ? null : parseInt(folderRaw, 10),
        }),
    });
    if (!res.ok) {
        showFieldError('bm-edit-error', data.error || 'Could not save link.');
        return;
    }
    closeEdit();
    load();
});

document.getElementById('bm-fetch-icon')?.addEventListener('click', async () => {
    const id = document.getElementById('bm-edit-id')?.value;
    const url = document.getElementById('bm-edit-url')?.value.trim();
    const status = document.getElementById('bm-fetch-icon-status');
    if (!id || !url) {
        return;
    }

    const btn = document.getElementById('bm-fetch-icon');
    const uploadBtn = document.getElementById('bm-upload-icon');
    btn?.setAttribute('disabled', 'disabled');
    uploadBtn?.setAttribute('disabled', 'disabled');
    document.getElementById('bm-open-icon-tab')?.setAttribute('disabled', 'disabled');

    const bookmark = faviconClient()
        ? await faviconClient().resolveBookmarkIcon(id, url, status, iconCaptureOptions(true))
        : null;

    btn?.removeAttribute('disabled');
    uploadBtn?.removeAttribute('disabled');
    document.getElementById('bm-open-icon-tab')?.removeAttribute('disabled');

    if (bookmark) {
        updateIconPreview(bookmark);
        resetIconCaptureUi();
        load();
    }
});

document.getElementById('bm-open-icon-tab')?.addEventListener('click', async () => {
    const id = document.getElementById('bm-edit-id')?.value;
    const url = document.getElementById('bm-edit-url')?.value.trim();
    const status = document.getElementById('bm-fetch-icon-status');
    if (!id || !url || !faviconClient()) {
        return;
    }

    const btn = document.getElementById('bm-open-icon-tab');
    btn?.setAttribute('disabled', 'disabled');

    const candidates = await faviconClient().fetchIconCandidates(id, url);
    if (candidates.length === 0) {
        if (status) {
            status.textContent = 'No icon URL available for this link.';
        }
        btn?.removeAttribute('disabled');
        return;
    }

    faviconClient().openFaviconInNewTab(candidates, 0);
    const ui = iconCaptureUi();
    await faviconClient().offerManualIconCapture(id, url, status, {
        autoOpen: false,
        linksEl: ui.linksEl,
        previewEl: ui.previewEl,
    });
    if (status) {
        status.textContent = `Opened ${candidates[0]} — right-click → Save image, then click Upload icon.`;
    }

    btn?.removeAttribute('disabled');
});

document.getElementById('bm-upload-icon')?.addEventListener('click', () => {
    document.getElementById('bm-icon-file')?.click();
});

document.getElementById('bm-icon-file')?.addEventListener('change', async e => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) {
        return;
    }

    const id = document.getElementById('bm-edit-id')?.value;
    const status = document.getElementById('bm-fetch-icon-status');
    if (!id) {
        return;
    }

    if (file.size > FAVICON_MAX_BYTES()) {
        if (status) {
            status.textContent = 'File exceeds 1 MB limit.';
        }
        return;
    }

    const btn = document.getElementById('bm-upload-icon');
    const fetchBtn = document.getElementById('bm-fetch-icon');
    btn?.setAttribute('disabled', 'disabled');
    fetchBtn?.setAttribute('disabled', 'disabled');
    document.getElementById('bm-open-icon-tab')?.setAttribute('disabled', 'disabled');
    if (status) {
        status.textContent = 'Uploading…';
    }

    const { res, data } = faviconClient()
        ? await faviconClient().uploadFaviconBlob(id, file, file.name)
        : { res: { ok: false }, data: {} };

    btn?.removeAttribute('disabled');
    fetchBtn?.removeAttribute('disabled');
    document.getElementById('bm-open-icon-tab')?.removeAttribute('disabled');

    if (!res.ok) {
        if (status) {
            status.textContent = data.error || 'Upload failed.';
        }
        return;
    }

    if (status) {
        status.textContent = 'Icon saved.';
    }
    if (data.bookmark) {
        updateIconPreview(data.bookmark);
        resetIconCaptureUi();
    }
    load();
});

document.getElementById('bm-edit-delete')?.addEventListener('click', async () => {
    const id = document.getElementById('bm-edit-id')?.value;
    const btn = document.getElementById('bm-edit-delete');
    if (!id) {
        return;
    }
    if (btn?.dataset.confirming === '1') {
        return;
    }
    if (btn) {
        btn.dataset.confirming = '1';
    }
    try {
        const ok = await globalThis.AppModal.confirm({
            title: 'Delete link',
            message: 'Delete this link?',
            confirmLabel: 'Delete',
            danger: true,
        });
        if (!ok) {
            return;
        }
        const { res, data } = await api(`/api/bookmarks/${id}/delete`, { method: 'POST', body: '{}' });
        if (!res.ok) {
            alert(data.error || 'Could not delete link.');
            return;
        }
        closeEdit();
        load();
    } finally {
        if (btn?.isConnected) {
            btn.dataset.confirming = '';
        }
    }
});

load();
