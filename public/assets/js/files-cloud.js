/**
 * files-cloud.js — Dropbox / S3 explorer on Cloud clipboards → Files tab
 */
(function () {
    'use strict';

    const $ = (sel) => document.querySelector(sel);

    let cloudStatus = null;
    let currentPath = '';
    let browseBusy = false;
    let cloudUploadBusy = false;
    let statusNotice = null;
    let pendingConfigProvider = null;

    const el = {
        statusText:       $('#cloud-status-text'),
        storageTitle:     $('#storage-section-title'),
        storageUsage:     $('#storage-usage'),
        connectActions:   $('#cloud-connect-actions'),
        localPanel:       $('#local-storage-panel'),
        dropboxWrap:      $('#cloud-dropbox-form-wrap'),
        dropboxForm:      $('#cloud-dropbox-form'),
        dropboxCancel:    $('#cloud-dropbox-cancel'),
        dropboxTest:      $('#cloud-dropbox-test'),
        dropboxAuthorize: $('#cloud-dropbox-authorize'),
        dropboxFormMsg:   $('#cloud-dropbox-form-msg'),
        dropboxRedirect:  $('#cloud-dropbox-redirect-uri'),
        dropboxCopyUri:   $('#cloud-dropbox-copy-uri'),
        s3Wrap:           $('#cloud-s3-form-wrap'),
        s3Form:           $('#cloud-s3-form'),
        migrateBanner:    $('#cloud-migrate-banner'),
        migrateCount:     $('#cloud-migrate-count'),
        migrateBtn:       $('#cloud-migrate-btn'),
        migrateDismiss:   $('#cloud-migrate-dismiss'),
        migrateStatus:    $('#cloud-migrate-status'),
        explorer:         $('#cloud-explorer'),
        breadcrumb:       $('#cloud-breadcrumb'),
        fileList:         $('#cloud-file-list'),
        dropZone:         $('#cloud-drop-zone'),
        uploadInput:      $('#cloud-upload-input'),
        newFolderBtn:     $('#cloud-new-folder-btn'),
        folderModal:      $('#cloud-folder-modal'),
        folderForm:       $('#cloud-folder-form'),
        disconnectBtn:    $('#cloud-disconnect-btn'),
        tabPanel:         $('#files-tab-panel'),
    };

    function openModal(modal) {
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('app-modal-open');
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add('hidden');
        if (!document.querySelector('.app-modal:not(.hidden)')) {
            document.body.classList.remove('app-modal-open');
        }
    }

    function bindModal(modal, onClose) {
        if (!modal) return;
        modal.addEventListener('click', (event) => {
            if (event.target.closest('[data-close]')) {
                if (onClose) onClose();
                else closeModal(modal);
            }
        });
    }

    function clearFieldError(id) {
        const node = document.getElementById(id);
        if (!node) return;
        node.textContent = '';
        node.classList.add('hidden');
    }

    function showFieldError(id, message) {
        const node = document.getElementById(id);
        if (!node) {
            alert(message);
            return;
        }
        node.textContent = message;
        node.classList.remove('hidden');
    }

    function closeDropbox() {
        closeModal(el.dropboxWrap);
        setDropboxFormMsg('', undefined);
    }

    function openDropbox() {
        closeS3();
        openModal(el.dropboxWrap);
        syncDropboxFormFromStatus();
    }

    function closeS3() {
        closeModal(el.s3Wrap);
        clearFieldError('cloud-s3-error');
    }

    function openS3() {
        closeDropbox();
        clearFieldError('cloud-s3-error');
        openModal(el.s3Wrap);
    }

    function closeFolder() {
        closeModal(el.folderModal);
        clearFieldError('cloud-folder-error');
    }

    function openFolder() {
        clearFieldError('cloud-folder-error');
        const input = document.getElementById('cloud-folder-name');
        if (input) input.value = '';
        openModal(el.folderModal);
    }

    bindModal(el.dropboxWrap, closeDropbox);
    bindModal(el.s3Wrap, closeS3);
    bindModal(el.folderModal, closeFolder);

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
        if (n < 1024 * 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
        if (n < 1024 * 1024 * 1024 * 1024) return `${(n / (1024 * 1024 * 1024)).toFixed(1)} GB`;
        return `${(n / (1024 * 1024 * 1024 * 1024)).toFixed(1)} TB`;
    }

    function storageMode() {
        if (cloudStatus?.connected) {
            const p = cloudStatus.provider;
            if (p === 'dropbox' || p === 's3') return p;
            return 'cloud';
        }
        return 'local';
    }

    function isFilesTabActive() {
        if (window.CLIPBOARDS_INITIAL_TAB === 'files') return true;
        return el.tabPanel !== null && !el.tabPanel.classList.contains('hidden');
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
            if (text.trim()) data = { error: text.trim().slice(0, 240) };
        }
        return { res, data };
    }

    function providerLabel(provider) {
        const map = { dropbox: 'Dropbox', s3: 'S3' };
        return map[provider] || provider || 'Cloud';
    }

    function providerConfigured(provider) {
        const opts = cloudStatus?.providers;
        if (!opts || !(provider in opts)) {
            return false;
        }
        return opts[provider]?.configured === true;
    }

    function dropboxFormPayload() {
        return {
            app_key: (document.getElementById('cloud-dropbox-app-key')?.value || '').trim(),
            app_secret: document.getElementById('cloud-dropbox-app-secret')?.value || '',
        };
    }

    function setDropboxFormMsg(text, ok) {
        const msgEl = el.dropboxFormMsg;
        if (!msgEl) return;
        msgEl.textContent = text || '';
        msgEl.classList.toggle('text-ok', ok === true);
        msgEl.classList.toggle('text-danger', ok === false);
        msgEl.classList.toggle('text-muted', ok === undefined && !!text);
    }

    function syncDropboxFormFromStatus() {
        const app = cloudStatus?.dropbox_app;
        if (!app) return;

        if (el.dropboxRedirect) {
            el.dropboxRedirect.textContent = app.redirect_uri || '';
        }

        const keyInput = document.getElementById('cloud-dropbox-app-key');
        const secretInput = document.getElementById('cloud-dropbox-app-secret');
        if (keyInput && app.app_key) keyInput.value = app.app_key;
        if (secretInput) {
            secretInput.value = '';
            secretInput.placeholder = app.app_secret_set
                ? 'Saved — leave blank to keep'
                : 'Required on first save';
            secretInput.required = !app.app_secret_set;
        }

        if (el.dropboxAuthorize) {
            el.dropboxAuthorize.classList.toggle('hidden', !app.configured);
        }
    }

    function renderConnectActions() {
        if (!el.connectActions) return;

        if (cloudStatus?.connected) {
            el.connectActions.innerHTML = '';
            return;
        }

        const parts = [];

        if (providerConfigured('dropbox')) {
            parts.push('<a href="/api/storage/connect/dropbox" class="btn text-xs">Authorize Dropbox</a>');
            parts.push('<button type="button" id="cloud-edit-dropbox" class="btn text-xs">Edit Dropbox app</button>');
        } else {
            parts.push('<button type="button" id="cloud-show-dropbox" class="btn text-xs">Connect Dropbox</button>');
        }

        parts.push('<button type="button" id="cloud-show-s3" class="btn text-xs">Connect S3</button>');

        el.connectActions.innerHTML = parts.join('\n            ');

        $('#cloud-show-dropbox')?.addEventListener('click', openDropbox);

        $('#cloud-edit-dropbox')?.addEventListener('click', openDropbox);

        $('#cloud-show-s3')?.addEventListener('click', openS3);
    }

    function applyStatusNotice() {
        if (!statusNotice || !el.statusText) return;
        el.statusText.textContent = statusNotice;
        statusNotice = null;
    }

    function renderStorageUsage() {
        const usageEl = el.storageUsage;
        if (!usageEl) return;

        const usage = cloudStatus?.storage_usage;
        if (!cloudStatus?.connected || !usage) {
            usageEl.classList.add('hidden');
            usageEl.textContent = '';
            return;
        }

        if (usage.available === false) {
            usageEl.textContent = 'Storage usage unavailable';
            usageEl.classList.remove('hidden');
            return;
        }

        const used = Number(usage.used_bytes) || 0;
        const limit = Number(usage.limit_bytes) || 0;
        const label = usage.label ? `${usage.label}: ` : '';

        if (limit > 0) {
            const free = Math.max(0, limit - used);
            usageEl.textContent =
                `${label}${formatBytes(used)} used · ${formatBytes(free)} free · ${formatBytes(limit)} total`;
        } else {
            usageEl.textContent = `${label}${formatBytes(used)} used`;
        }
        usageEl.classList.remove('hidden');
    }

    function applyStorageMode() {
        const connected = !!cloudStatus?.connected;
        const mode = storageMode();

        el.localPanel?.classList.toggle('hidden', connected);
        el.explorer?.classList.toggle('hidden', !connected);

        if (el.storageTitle) {
            if (mode === 'dropbox') {
                el.storageTitle.textContent = 'Dropbox';
            } else if (mode === 's3') {
                el.storageTitle.textContent = 'S3';
            } else {
                el.storageTitle.textContent = 'Storage';
            }
        }

        renderStorageUsage();
    }

    function renderStatus() {
        if (!el.statusText) return;

        if (!cloudStatus) {
            el.statusText.textContent = 'Loading…';
            return;
        }

        if (cloudStatus.connected) {
            el.statusText.textContent =
                `Connected · ${cloudStatus.root_path || '/PersonalDashboard'}`;
        } else {
            const was = cloudStatus.disconnected_at ? ' (disconnected)' : '';
            el.statusText.textContent =
                `Using local storage on this server${was}. Connect Dropbox or S3 below to switch.`;
        }

        applyStorageMode();
        renderConnectActions();
        renderMigrationBanner();

        if (pendingConfigProvider !== null) {
            if (pendingConfigProvider === 'dropbox') {
                openDropbox();
                statusNotice = 'Save your Dropbox app credentials below, then authorize with Dropbox.';
            } else {
                statusNotice = `${providerLabel(pendingConfigProvider)} is not configured.`;
            }
            pendingConfigProvider = null;
        }

        syncDropboxFormFromStatus();
        applyStatusNotice();
    }

    function renderMigrationBanner() {
        if (!el.migrateBanner || !cloudStatus?.connected) {
            el.migrateBanner?.classList.add('hidden');
            return;
        }

        const pending = Number(cloudStatus.local_files_pending) || 0;
        const show = pending > 0 && !cloudStatus.migration_dismissed;

        if (!show) {
            el.migrateBanner.classList.add('hidden');
            return;
        }

        el.migrateBanner.classList.remove('hidden');
        if (el.migrateCount) el.migrateCount.textContent = String(pending);
    }

    function cloudRootPath() {
        return cloudStatus?.root_path || '/PersonalDashboard';
    }

    function renderBreadcrumb(breadcrumb) {
        if (!el.breadcrumb) return;

        const root = cloudRootPath();
        const rootName = root.replace(/^\/+|\/+$/g, '').split('/').pop() || 'PersonalDashboard';
        const crumbs = Array.isArray(breadcrumb) && breadcrumb.length > 0
            ? breadcrumb
            : [{ name: rootName, path: root }];

        el.breadcrumb.innerHTML = crumbs.map((c, i) => {
            const isLast = i === crumbs.length - 1;
            const path = c.path || '';
            if (isLast) {
                return `<span class="text-muted truncate max-w-[12rem]" title="${escHtml(c.name)}">${escHtml(c.name)}</span>`;
            }
            return `<button type="button" class="text-link cloud-crumb" data-path="${escHtml(path)}">${escHtml(c.name)}</button><span class="text-faint">/</span>`;
        }).join('');

        el.breadcrumb.querySelectorAll('.cloud-crumb').forEach(btn => {
            btn.addEventListener('click', () => browse(btn.dataset.path || ''));
        });
    }

    function renderFileList(items) {
        if (!el.fileList) return;

        if (!items || items.length === 0) {
            el.fileList.innerHTML = '<li class="text-faint mono text-xs py-2">This folder is empty</li>';
            return;
        }

        el.fileList.innerHTML = items.map(item => {
            if (item.node_type === 'folder') {
                return `
                    <li class="surface px-3 py-3 rounded flex items-center gap-3">
                        <button type="button" class="flex-1 text-left mono text-sm cloud-open-folder truncate"
                            data-path="${escHtml(item.provider_path)}" title="${escHtml(item.name)}">
                            📁 ${escHtml(item.name)}
                        </button>
                    </li>`;
            }

            const id = item.id;
            const canShare = id != null;

            return `
                <li class="surface px-3 py-3 rounded flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <p class="mono text-sm truncate" title="${escHtml(item.name)}">${escHtml(item.name)}</p>
                        <p class="text-faint mono text-xs mt-0.5">${formatBytes(item.size_bytes)} · ${escHtml(item.modified_at || '')}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        ${canShare ? `<a href="/api/storage/${id}/download" class="btn text-xs">Download</a>` : ''}
                        ${canShare ? `<button type="button" class="btn text-xs cloud-share-btn" data-id="${id}" data-name="${escHtml(item.name)}">Share</button>` : ''}
                        ${canShare ? `<button type="button" class="btn-danger text-xs cloud-delete-btn" data-id="${id}" data-name="${escHtml(item.name)}">Delete</button>` : ''}
                    </div>
                </li>`;
        }).join('');

        el.fileList.querySelectorAll('.cloud-open-folder').forEach(btn => {
            btn.addEventListener('click', () => browse(btn.dataset.path || ''));
        });

        el.fileList.querySelectorAll('.cloud-share-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                if (window.filesOpenSharing) {
                    window.filesOpenSharing(parseInt(btn.dataset.id, 10), btn.dataset.name || '', 'cloud');
                }
            });
        });

        el.fileList.querySelectorAll('.cloud-delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (btn.dataset.confirming === '1') return;
                btn.dataset.confirming = '1';
                try {
                    await deleteNode(parseInt(btn.dataset.id, 10), btn.dataset.name || '');
                } finally {
                    if (btn.isConnected) btn.dataset.confirming = '';
                }
            });
        });
    }

    async function loadStatus() {
        const { res, data } = await apiJson('/api/storage/status');
        if (!res.ok) {
            if (el.statusText) {
                el.statusText.textContent = res.status === 404
                    ? 'Cloud storage API is unavailable on this server — redeploy public/index.php and src/CloudStorage/.'
                    : (data.error || 'Could not load cloud status.');
            }
            if (el.connectActions) el.connectActions.innerHTML = '';
            cloudStatus = { connected: false };
            applyStorageMode();
            applyStatusNotice();
            if (typeof window.filesRefreshLocalPanel === 'function') {
                window.filesRefreshLocalPanel();
            }
            return;
        }

        cloudStatus = data;
        renderStatus();

        if (cloudStatus?.connected) {
            await browse(currentPath || cloudStatus.root_path || '');
        }

        if (typeof window.filesRefreshLocalPanel === 'function') {
            window.filesRefreshLocalPanel();
        }
    }

    async function browse(path) {
        if (!cloudStatus?.connected || browseBusy) return;
        browseBusy = true;

        const qs = path ? `?path=${encodeURIComponent(path)}` : '';
        const { res, data } = await apiJson('/api/storage/browse' + qs);

        browseBusy = false;

        if (!res.ok) {
            if (el.fileList) {
                el.fileList.innerHTML = `<li class="text-danger mono text-xs py-2">${escHtml(data.error || 'Browse failed.')}</li>`;
            }
            return;
        }

        currentPath = data.path || path || '';
        renderBreadcrumb(data.breadcrumb);
        renderFileList(data.items || []);
    }

    async function uploadCloudFiles(fileList, pathOverride) {
        const files = Array.from(fileList || []).filter((f) => f instanceof File);
        if (files.length === 0 || cloudUploadBusy || !cloudStatus?.connected) return;

        cloudUploadBusy = true;
        const path = pathOverride ?? currentPath;
        let okCount = 0;
        const failures = [];

        for (let i = 0; i < files.length; i++) {
            const file = files[i];
            const form = new FormData();
            form.append('file', file);
            form.append('path', path);

            const res = await fetch('/api/storage/upload', {
                method: 'POST',
                body: form,
                credentials: 'same-origin',
                headers: { 'X-CSRF-Token': getCsrfToken() },
            });

            const text = await res.text();
            let data = {};
            try { data = JSON.parse(text); } catch (_) {
                data = { error: text.trim().slice(0, 240) || 'Upload failed.' };
            }

            if (res.ok) okCount += 1;
            else failures.push({ name: file.name, error: data.error || 'Upload failed.' });
        }

        if (el.uploadInput) el.uploadInput.value = '';
        cloudUploadBusy = false;
        await loadStatus();

        if (failures.length === 0 && okCount > 0) {
            // quiet success — explorer refreshes
        } else if (failures.length > 0) {
            alert(`${failures.length} upload(s) failed: ${failures[0].error}`);
        }
    }

    async function deleteNode(id, name) {
        const ok = await globalThis.AppModal.confirm({
            title: 'Delete file',
            message: `Delete "${name}" from cloud storage? This cannot be undone.`,
            confirmLabel: 'Delete',
            danger: true,
        });
        if (!ok) return;

        const { res, data } = await apiJson(`/api/storage/${id}/delete`, {
            method: 'POST',
            body: JSON.stringify({}),
        });

        if (!res.ok) {
            alert(data.error || 'Delete failed.');
            return;
        }

        await browse(currentPath);
    }

    async function disconnectCloud() {
        if (el.disconnectBtn?.dataset.confirming === '1') return;
        if (el.disconnectBtn) el.disconnectBtn.dataset.confirming = '1';
        try {
            await disconnectCloudConfirmed();
        } finally {
            if (el.disconnectBtn?.isConnected) el.disconnectBtn.dataset.confirming = '';
        }
    }

    async function disconnectCloudConfirmed() {
        const { res, data } = await apiJson('/api/storage/disconnect/stats');
        if (!res.ok) {
            alert(data.error || 'Could not load disconnect info.');
            return;
        }

        const files = Number(data.cloud_files) || 0;
        const links = Number(data.active_share_links) || 0;
        let msg = 'Disconnect cloud storage? Files stay in your provider; only this dashboard link is removed.';
        if (links > 0) {
            msg += `\n\n${links} active share link(s) will stop working until you reconnect.`;
        }
        if (files > 0) {
            msg += `\n\n${files} indexed cloud file(s) will no longer appear here.`;
        }
        const ok = await globalThis.AppModal.confirm({
            title: 'Disconnect',
            message: msg,
            confirmLabel: 'Disconnect',
            danger: true,
        });
        if (!ok) return;

        const disc = await apiJson('/api/storage/disconnect', {
            method: 'POST',
            body: JSON.stringify({}),
        });

        if (!disc.res.ok) {
            alert(disc.data.error || 'Disconnect failed.');
            return;
        }

        currentPath = '';
        await loadStatus();
        if (window.filesTabActivated) window.filesTabActivated();
    }

    async function migrateLocal() {
        if (!el.migrateBtn) return;
        el.migrateBtn.disabled = true;
        if (el.migrateStatus) {
            el.migrateStatus.classList.remove('hidden');
            el.migrateStatus.textContent = 'Moving files…';
            el.migrateStatus.className = 'mono text-xs mt-2 text-secondary';
        }

        const { res, data } = await apiJson('/api/storage/migrate-local', {
            method: 'POST',
            body: JSON.stringify({}),
        });

        el.migrateBtn.disabled = false;

        if (!res.ok) {
            if (el.migrateStatus) {
                el.migrateStatus.textContent = data.error || 'Migration failed.';
                el.migrateStatus.className = 'mono text-xs mt-2 text-danger';
            }
            return;
        }

        const result = data.result || {};
        const moved = Number(result.moved) || 0;
        const failed = Number(result.failed) || 0;

        if (el.migrateStatus) {
            el.migrateStatus.textContent = failed
                ? `Moved ${moved}; ${failed} failed.`
                : `Moved ${moved} file(s) to cloud.`;
            el.migrateStatus.className = 'mono text-xs mt-2 ' + (failed ? 'text-warn' : 'text-ok');
        }

        await loadStatus();
        if (window.filesTabActivated) window.filesTabActivated();
    }

    function initCloudDropZone() {
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
            if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
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
            if (files?.length) uploadCloudFiles(files);
        });
    }

    el.dropboxForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setDropboxFormMsg('Saving…', undefined);

        const { res, data } = await apiJson('/api/storage/dropbox-app', {
            method: 'POST',
            body: JSON.stringify(dropboxFormPayload()),
        });

        if (!res.ok) {
            setDropboxFormMsg(data.error || 'Save failed.', false);
            return;
        }

        if (data.status) cloudStatus = data.status;
        setDropboxFormMsg(data.message || 'Saved.', true);
        renderStatus();
    });

    el.dropboxTest?.addEventListener('click', async () => {
        setDropboxFormMsg('Testing…', undefined);
        const { res, data } = await apiJson('/api/storage/dropbox-app/test', {
            method: 'POST',
            body: JSON.stringify(dropboxFormPayload()),
        });
        setDropboxFormMsg(data.message || data.error || (res.ok ? 'OK' : 'Test failed.'), data.ok === true);
    });

    el.dropboxCancel?.addEventListener('click', closeDropbox);

    el.dropboxCopyUri?.addEventListener('click', () => {
        const text = el.dropboxRedirect?.textContent || '';
        if (text) navigator.clipboard?.writeText(text).catch(() => {});
    });

    el.s3Form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearFieldError('cloud-s3-error');
        const fd = new FormData(el.s3Form);
        const body = Object.fromEntries(fd.entries());
        body.path_style = fd.get('path_style') === '1';

        const { res, data } = await apiJson('/api/storage/connect/s3', {
            method: 'POST',
            body: JSON.stringify(body),
        });

        if (!res.ok) {
            showFieldError('cloud-s3-error', data.error || 'S3 connect failed.');
            return;
        }

        closeS3();
        el.s3Form?.reset();
        await loadStatus();
    });

    el.migrateBtn?.addEventListener('click', migrateLocal);

    el.migrateDismiss?.addEventListener('click', async () => {
        await apiJson('/api/storage/dismiss-migration', {
            method: 'POST',
            body: JSON.stringify({}),
        });
        if (cloudStatus) cloudStatus.migration_dismissed = true;
        renderMigrationBanner();
    });

    el.uploadInput?.addEventListener('change', () => {
        if (el.uploadInput?.files?.length) uploadCloudFiles(el.uploadInput.files);
    });

    el.newFolderBtn?.addEventListener('click', openFolder);

    el.folderForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = (document.getElementById('cloud-folder-name')?.value || '').trim();
        if (!name) {
            closeFolder();
            return;
        }

        clearFieldError('cloud-folder-error');
        const { res, data } = await apiJson('/api/storage/folder', {
            method: 'POST',
            body: JSON.stringify({ path: currentPath, name }),
        });

        if (!res.ok) {
            showFieldError('cloud-folder-error', data.error || 'Could not create folder.');
            return;
        }

        closeFolder();
        await browse(currentPath);
    });

    el.disconnectBtn?.addEventListener('click', disconnectCloud);

    initCloudDropZone();

    window.filesStorageMode = storageMode;

    window.filesCloudUploadOne = async function (file, progress) {
        if (!cloudStatus?.connected) {
            return { ok: false, name: file.name, error: 'Cloud storage is not connected.' };
        }

        const path = currentPath || cloudStatus.root_path || '';
        const form = new FormData();
        form.append('file', file);
        form.append('path', path);

        if (progress && progress.total > 1 && window.filesSetUploadStatus) {
            window.filesSetUploadStatus(`Uploading ${progress.index} of ${progress.total} to cloud: ${file.name}…`);
        } else if (window.filesSetUploadStatus) {
            window.filesSetUploadStatus('Uploading to cloud…');
        }

        const res = await fetch('/api/storage/upload', {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': getCsrfToken() },
        });

        const text = await res.text();
        let data = {};
        try { data = JSON.parse(text); } catch (_) {
            data = { error: text.trim().slice(0, 240) || 'Upload failed.' };
        }

        if (res.ok) await loadStatus();

        return {
            ok: res.ok,
            name: file.name,
            error: data.error || (res.ok ? null : 'Upload failed.'),
        };
    };

    function bootCloud() {
        if (!isFilesTabActive()) return;

        const params = new URLSearchParams(window.location.search);
        const cloudProvider = params.get('cloud_provider') || '';

        if (params.get('cloud_connected') === '1') {
            statusNotice = cloudProvider
                ? `Connected to ${providerLabel(cloudProvider)}.`
                : 'Cloud storage connected.';
            history.replaceState(null, '', window.location.pathname + '?tab=files');
        }

        const cloudError = params.get('cloud_error');
        const cloudMessage = params.get('cloud_message') || '';
        if (cloudError) {
            if (cloudError === 'config') {
                pendingConfigProvider = cloudProvider;
            } else if (cloudMessage) {
                statusNotice = cloudMessage;
                if (cloudProvider === 'dropbox') {
                    openDropbox();
                }
            } else if (cloudError === 'scope') {
                statusNotice = 'Dropbox permissions are incomplete. Enable files.content.read, files.content.write, and account_info.read on your app, then authorize again.';
                if (cloudProvider === 'dropbox') {
                    openDropbox();
                }
            } else if (cloudError === 'invalid') {
                statusNotice = 'Cloud connection request was invalid. Try again.';
            } else {
                statusNotice = cloudProvider
                    ? `${providerLabel(cloudProvider)} connection failed. Try again.`
                    : 'Cloud connection failed. Try again.';
            }
            history.replaceState(null, '', window.location.pathname + '?tab=files');
        }

        loadStatus();
    }

    document.addEventListener('visibilitychange', () => {
        if (isFilesTabActive() && !document.hidden && cloudStatus?.connected) {
            loadStatus();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootCloud);
    } else {
        bootCloud();
    }

    window.filesCloudTabActivated = function () {
        return loadStatus();
    };
}());
