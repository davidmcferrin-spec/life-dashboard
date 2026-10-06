/**
 * files.js — private file uploads under Cloud clipboards
 * Wrapped in IIFE — clipboards.js on the same page also defines top-level helpers.
 */
(function () {
    'use strict';

    const $ = (sel) => document.querySelector(sel);

    let myFiles = [];
    let sharedFiles = [];
    let quota = null;
    let sharingFileId = null;
    let sharingSource = 'local';
    let uploadBusy = false;

    const el = {
        list:               $('#files-list'),
        sharedList:         $('#files-shared-list'),
        quota:              $('#files-quota'),
        uploadInput:        $('#files-upload-input'),
        uploadStatus:       $('#files-upload-status'),
        sharingPanel:       $('#files-sharing-panel'),
        sharingName:        $('#files-sharing-name'),
        sharingUnavailable: $('#files-sharing-unavailable'),
        sharingHelp:        $('#files-sharing-help'),
        shareLinks:         $('#files-share-links'),
        shareUsers:         $('#files-share-users'),
        shareLinkForm:      $('#files-share-link-form'),
        shareUserForm:      $('#files-share-user-form'),
        sharingClose:       $('#files-sharing-close'),
        tabPanel:           $('#files-tab-panel'),
        dropZone:           $('#files-drop-zone'),
    };

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatBytes(bytes) {
        const n = Number(bytes) || 0;
        if (n < 1024) return `${n} B`;
        if (n < 1024 * 1024) return `${(n / 1024).toFixed(1)} KB`;
        return `${(n / (1024 * 1024)).toFixed(1)} MB`;
    }

    function isFilesTabActive() {
        if (window.CLIPBOARDS_INITIAL_TAB === 'files') return true;
        return el.tabPanel !== null && !el.tabPanel.classList.contains('hidden');
    }

    function setUploadStatus(text, tone = 'muted') {
        if (!el.uploadStatus) return;
        if (!text) {
            el.uploadStatus.classList.add('hidden');
            el.uploadStatus.textContent = '';
            return;
        }
        el.uploadStatus.classList.remove('hidden');
        el.uploadStatus.textContent = text;
        el.uploadStatus.className = 'mono text-xs mb-3 ' + (
            tone === 'ok' ? 'text-ok' :
            tone === 'warn' ? 'text-warn' :
            tone === 'err' ? 'text-danger' :
            'text-secondary'
        );
    }

    async function apiJson(url, options = {}) {
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

    function isLocalMode() {
        return typeof window.filesStorageMode !== 'function' || window.filesStorageMode() === 'local';
    }

    async function uploadOneFile(file, progress) {
        const mode = typeof window.filesStorageMode === 'function'
            ? window.filesStorageMode()
            : 'local';

        if (mode !== 'local' && typeof window.filesCloudUploadOne === 'function') {
            return window.filesCloudUploadOne(file, progress);
        }

        const form = new FormData();
        form.append('file', file);

        if (progress && progress.total > 1) {
            setUploadStatus(`Uploading ${progress.index} of ${progress.total}: ${file.name}…`);
        } else {
            setUploadStatus('Uploading…');
        }

        const res = await fetch('/api/files/upload', {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': getCsrfToken() },
        });

        const text = await res.text();
        let data = {};
        try {
            data = JSON.parse(text);
        } catch (_) {
            data = { error: text.trim().slice(0, 240) || 'Upload failed.' };
        }

        return {
            ok: res.ok,
            name: file.name,
            error: data.error || (res.ok ? null : 'Upload failed.'),
        };
    }

    async function uploadFiles(fileList) {
        const files = Array.from(fileList || []).filter((f) => f instanceof File);
        if (files.length === 0) return;

        if (uploadBusy) {
            setUploadStatus('Upload already in progress.', 'err');
            setTimeout(() => setUploadStatus(''), 3000);
            return;
        }

        uploadBusy = true;
        const total = files.length;
        let okCount = 0;
        /** @type {{ name: string, error: string }[]} */
        const failures = [];

        for (let i = 0; i < files.length; i++) {
            const result = await uploadOneFile(files[i], { index: i + 1, total });
            if (result.ok) {
                okCount += 1;
            } else {
                failures.push({ name: result.name, error: result.error || 'Upload failed.' });
            }
        }

        if (el.uploadInput) el.uploadInput.value = '';
        if (isLocalMode()) {
            await loadMyFiles();
        }
        uploadBusy = false;

        if (total === 1) {
            if (okCount === 1) {
                setUploadStatus('Uploaded', 'ok');
            } else {
                setUploadStatus(failures[0]?.error || 'Upload failed.', 'err');
            }
        } else if (failures.length === 0) {
            setUploadStatus(`Uploaded ${okCount} files.`, 'ok');
        } else if (okCount === 0) {
            setUploadStatus(`${failures.length} failed — ${failures[0].error}`, 'err');
        } else {
            setUploadStatus(`Uploaded ${okCount} of ${total}. ${failures.length} failed.`, 'warn');
        }

        setTimeout(() => setUploadStatus(''), failures.length ? 5000 : 2500);
    }

    function renderQuota() {
        if (!el.quota || !quota || !isLocalMode()) return;
        el.quota.textContent =
            `${quota.count} / ${quota.max_count} files · ${formatBytes(quota.bytes)} / ${formatBytes(quota.max_bytes)}`;
    }

    function fileTypeLabel(ext) {
        const map = {
            jpg: 'Image', jpeg: 'Image', png: 'Image', gif: 'Image', webp: 'Image',
            zip: 'ZIP', exe: 'EXE', msi: 'MSI',
        };
        return map[(ext || '').toLowerCase()] || String(ext || '').toUpperCase();
    }

    function renderMyFiles() {
        if (!el.list) return;

        if (myFiles.length === 0) {
            el.list.innerHTML = '<li class="text-faint mono text-xs py-2">No files yet</li>';
            return;
        }

        el.list.innerHTML = myFiles.map(f => `
            <li class="surface px-3 py-3 rounded flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex-1 min-w-0">
                    <p class="mono text-sm truncate" title="${escHtml(f.original_name)}">${escHtml(f.original_name)}</p>
                    <p class="text-faint mono text-xs mt-0.5">${escHtml(fileTypeLabel(f.ext))} · ${formatBytes(f.size_bytes)} · ${escHtml(f.created_at)}</p>
                </div>
                <div class="flex flex-wrap gap-2 shrink-0">
                    <a href="/api/files/${f.id}/download" class="btn text-xs">Download</a>
                    <button type="button" class="btn text-xs files-share-btn" data-id="${f.id}" data-name="${escHtml(f.original_name)}">Share</button>
                    <button type="button" class="btn-danger text-xs files-delete-btn" data-id="${f.id}">Delete</button>
                </div>
            </li>
        `).join('');

        el.list.querySelectorAll('.files-share-btn').forEach(btn => {
            btn.addEventListener('click', () => openSharing(parseInt(btn.dataset.id, 10), btn.dataset.name || ''));
        });

        el.list.querySelectorAll('.files-delete-btn').forEach(btn => {
            btn.addEventListener('click', () => deleteFile(parseInt(btn.dataset.id, 10)));
        });
    }

    function renderSharedFiles() {
        if (!el.sharedList) return;

        if (sharedFiles.length === 0) {
            el.sharedList.innerHTML = '<li class="text-faint mono text-xs py-2">Nothing shared with you</li>';
            return;
        }

        el.sharedList.innerHTML = sharedFiles.map(f => {
            const downloadUrl = f.source === 'cloud'
                ? `/api/storage/${f.id}/download`
                : `/api/files/${f.id}/download`;
            const offline = f.source === 'cloud' && !f.connection_active;

            return `
            <li class="surface px-3 py-3 rounded flex flex-col sm:flex-row sm:items-center gap-3">
                <div class="flex-1 min-w-0">
                    <p class="mono text-sm truncate" title="${escHtml(f.original_name)}">${escHtml(f.original_name)}</p>
                    <p class="text-faint mono text-xs mt-0.5">
                        from ${escHtml(f.owner_username || 'user')} · ${escHtml(fileTypeLabel(f.ext))} · ${formatBytes(f.size_bytes)}
                        ${f.source === 'cloud' ? ' · cloud' : ''}
                        ${offline ? ' · owner disconnected' : ''}
                    </p>
                </div>
                ${offline
                    ? '<span class="text-warn text-xs mono shrink-0">Unavailable</span>'
                    : `<a href="${downloadUrl}" class="btn text-xs shrink-0">Download</a>`}
            </li>`;
        }).join('');
    }

    async function loadMyFiles() {
        if (!isLocalMode()) {
            return;
        }

        const { res, data } = await apiJson('/api/files');
        if (!res.ok) {
            const message = data.error || 'Could not load files.';
            if (el.list) {
                el.list.innerHTML = `<li class="text-danger mono text-xs py-2">${escHtml(message)}</li>`;
            }
            if (el.quota) {
                el.quota.textContent = 'Could not load quota';
            }
            return;
        }

        myFiles = data.files || [];
        quota = data.quota || null;
        renderQuota();
        renderMyFiles();

        if (sharingFileId !== null && !myFiles.some(f => f.id === sharingFileId)) {
            closeSharing();
        }
    }

    async function loadSharedFiles() {
        const [localRes, cloudRes] = await Promise.all([
            apiJson('/api/files/shared-with-me'),
            apiJson('/api/storage/shared-with-me'),
        ]);

        const localFiles = localRes.res.ok ? (localRes.data.files || []) : [];
        const cloudFiles = cloudRes.res.ok ? (cloudRes.data.files || []) : [];

        if (!localRes.res.ok && !cloudRes.res.ok) {
            const message = localRes.data.error || cloudRes.data.error || 'Could not load shared files.';
            if (el.sharedList) {
                el.sharedList.innerHTML = `<li class="text-danger mono text-xs py-2">${escHtml(message)}</li>`;
            }
            return;
        }

        sharedFiles = [...localFiles, ...cloudFiles];
        renderSharedFiles();
    }

    async function deleteFile(id) {
        const file = myFiles.find(f => f.id === id);
        if (!file) return;
        if (!confirm(`Delete "${file.original_name}"? This cannot be undone.`)) return;

        const { res, data } = await apiJson(`/api/files/${id}/delete`, {
            method: 'POST',
            body: JSON.stringify({}),
        });

        if (!res.ok) {
            alert(data.error || 'Delete failed.');
            return;
        }

        if (sharingFileId === id) closeSharing();
        await loadMyFiles();
    }

    function openSharing(fileId, name, source = 'local') {
        sharingFileId = fileId;
        sharingSource = source;
        el.sharingPanel?.classList.remove('hidden');
        if (el.sharingName) el.sharingName.textContent = name;
        loadShares(fileId);
        el.sharingPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    window.filesOpenSharing = openSharing;

    window.filesSetUploadStatus = setUploadStatus;

    function closeSharing() {
        sharingFileId = null;
        el.sharingPanel?.classList.add('hidden');
    }

    async function loadShares(fileId) {
        if (!el.shareLinks || !el.shareUsers) return;

        el.sharingUnavailable?.classList.add('hidden');
        el.sharingHelp?.classList.remove('hidden');
        el.shareLinkForm?.classList.remove('hidden');
        el.shareUserForm?.classList.remove('hidden');

        const apiBase = sharingSource === 'cloud' ? '/api/storage' : '/api/files';

        const { res, data } = await apiJson(`${apiBase}/${fileId}/shares`);
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
                    <span class="text-secondary">download only${l.expires_at ? ' · expires ' + escHtml(l.expires_at) : ''}</span>
                    <input type="text" readonly class="input mono text-xs w-full" value="${escHtml(l.url)}" onclick="this.select()">
                    <button type="button" class="text-danger text-left files-revoke-link" data-id="${l.id}">Revoke</button>
                </li>
            `).join('');

        el.shareUsers.innerHTML = users.length === 0
            ? '<li class="text-faint">Not shared with anyone</li>'
            : users.map(u => `
                <li class="flex items-center justify-between gap-2 surface px-2 py-2 rounded">
                    <span>${escHtml(u.username)}</span>
                    <button type="button" class="text-danger files-revoke-user" data-id="${u.id}">Remove</button>
                </li>
            `).join('');

        el.shareLinks.querySelectorAll('.files-revoke-link').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!confirm('Revoke this link?')) return;
                await apiJson(`${apiBase}/${fileId}/shares/link/${btn.dataset.id}/revoke`, {
                    method: 'POST',
                    body: JSON.stringify({}),
                });
                loadShares(fileId);
            });
        });

        el.shareUsers.querySelectorAll('.files-revoke-user').forEach(btn => {
            btn.addEventListener('click', async () => {
                await apiJson(`${apiBase}/${fileId}/shares/user/${btn.dataset.id}/remove`, {
                    method: 'POST',
                    body: JSON.stringify({}),
                });
                loadShares(fileId);
            });
        });
    }

    el.uploadInput?.addEventListener('change', () => {
        const input = el.uploadInput;
        if (!input?.files?.length) return;
        uploadFiles(input.files);
    });

    function initDragDrop() {
        const zone = el.dropZone;
        if (!zone) return;

        let dragDepth = 0;

        zone.addEventListener('dragenter', (e) => {
            e.preventDefault();
            dragDepth += 1;
            zone.classList.add('files-drop-active');
        });

        zone.addEventListener('dragover', (e) => {
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'copy';
            }
        });

        zone.addEventListener('dragleave', (e) => {
            e.preventDefault();
            dragDepth -= 1;
            if (dragDepth <= 0) {
                dragDepth = 0;
                zone.classList.remove('files-drop-active');
            }
        });

        zone.addEventListener('drop', (e) => {
            e.preventDefault();
            dragDepth = 0;
            zone.classList.remove('files-drop-active');

            const files = e.dataTransfer?.files;
            if (!files || files.length === 0) return;

            uploadFiles(files);
        });
    }

    initDragDrop();

    el.shareLinkForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (sharingFileId === null) return;

        const apiBase = sharingSource === 'cloud' ? '/api/storage' : '/api/files';

        const { res, data } = await apiJson(`${apiBase}/${sharingFileId}/shares/link`, {
            method: 'POST',
            body: JSON.stringify({
                expires_in: document.getElementById('files-link-expiry')?.value || null,
                label: document.getElementById('files-link-label')?.value || '',
            }),
        });

        if (!res.ok) {
            alert(data.error || 'Could not create link.');
            return;
        }

        if (data.link?.url) {
            prompt('Copy this share link:', data.link.url);
        }
        loadShares(sharingFileId);
    });

    el.shareUserForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (sharingFileId === null) return;

        const apiBase = sharingSource === 'cloud' ? '/api/storage' : '/api/files';

        const { res, data } = await apiJson(`${apiBase}/${sharingFileId}/shares/user`, {
            method: 'POST',
            body: JSON.stringify({
                identifier: document.getElementById('files-share-identifier')?.value || '',
            }),
        });

        if (!res.ok) {
            alert(data.error || 'Could not share.');
            return;
        }

        const ident = document.getElementById('files-share-identifier');
        if (ident) ident.value = '';
        loadShares(sharingFileId);
    });

    el.sharingClose?.addEventListener('click', closeSharing);

    function bootFiles() {
        if (!isFilesTabActive()) return;
        loadSharedFiles();
        if (window.filesCloudTabActivated) {
            window.filesCloudTabActivated();
        } else {
            loadMyFiles();
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (isFilesTabActive() && !document.hidden) {
            loadSharedFiles();
            if (isLocalMode()) {
                loadMyFiles();
            }
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootFiles);
    } else {
        bootFiles();
    }

    window.filesRefreshLocalPanel = function () {
        if (isLocalMode()) {
            return loadMyFiles();
        }
    };

    window.filesTabActivated = function () {
        loadSharedFiles();
        if (window.filesCloudTabActivated) {
            return window.filesCloudTabActivated();
        }
        return loadMyFiles();
    };
}());
