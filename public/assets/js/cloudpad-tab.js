/**
 * cloudpad-tab.js — CloudPad tab on Cloud clipboards settings page
 */
(function () {
    'use strict';

    const BUNDLE_URL = '/assets/js/cloudpad-canvas.bundle.js';
    const ID_PREFIX = 'cloudpad-embed-';

    const $ = (sel) => document.querySelector(sel);

    let canvases = [];
    let activeCanvasId = 0;
    let maxCanvases = 10;
    let bundleLoading = null;

    const el = {
        tabPanel:        $('#cloudpad-tab-panel'),
        list:            $('#cloudpad-canvas-list'),
        count:           $('#cloudpad-canvas-count'),
        newBtn:          $('#cloudpad-new-canvas'),
        newForm:         $('#cloudpad-new-canvas-form'),
        newName:         $('#cloudpad-new-canvas-name'),
        newCancel:       $('#cloudpad-new-canvas-cancel'),
        empty:           $('#cloudpad-embed-empty'),
        editorWrap:      $('#cloudpad-embed-editor-wrap'),
        root:            $('#cloudpad-embed-root'),
        deleteBtn:       $('#cloudpad-embed-delete'),
    };

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function isCloudpadTabActive() {
        if (window.CLIPBOARDS_INITIAL_TAB === 'cloudpad') {
            return true;
        }
        return el.tabPanel !== null && !el.tabPanel.classList.contains('hidden');
    }

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

    function setUrl(canvasId) {
        const params = new URLSearchParams({ tab: 'cloudpad' });
        if (canvasId > 0) {
            params.set('canvas', String(canvasId));
        }
        history.replaceState(null, '', `/settings/clipboards?${params}`);
    }

    function loadBundle() {
        if (window.CloudPad?.mount) {
            return Promise.resolve();
        }
        if (bundleLoading) {
            return bundleLoading;
        }
        const ver = window.CLOUDPAD_BUNDLE_VER || '1';
        bundleLoading = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = `${BUNDLE_URL}?v=${encodeURIComponent(ver)}`;
            script.onload = () => {
                if (!window.CloudPad?.mount) {
                    bundleLoading = null;
                    reject(new Error(
                        'CloudPad editor did not initialize. Hard-refresh the page (Ctrl+Shift+R). '
                        + 'If it persists, redeploy public/assets/js/cloudpad-canvas.bundle.js.',
                    ));
                    return;
                }
                resolve();
            };
            script.onerror = () => {
                bundleLoading = null;
                reject(new Error('Could not load CloudPad editor.'));
            };
            document.head.appendChild(script);
        });
        return bundleLoading;
    }

    function renderList() {
        if (!el.list) return;

        if (canvases.length === 0) {
            el.list.innerHTML = '<li class="text-faint mono text-xs py-2">No canvases yet.</li>';
            return;
        }

        el.list.innerHTML = canvases.map(c => {
            const isActive = c.id === activeCanvasId;
            const shared = c.access === 'shared'
                ? ` <span class="text-faint text-xs">(${escHtml(c.owner_username || 'shared')})</span>`
                : '';
            const perm = c.permission === 'write' ? '' : ' · read-only';
            return `
                <li>
                    <button type="button"
                        class="cloudpad-canvas-pick w-full text-left px-2 py-1.5 rounded mono text-sm truncate ${isActive ? 'clip-tab-active' : 'clip-tab hover:bg-surface-hover'}"
                        data-id="${c.id}" title="${escHtml(c.name)}${perm}">
                        ${escHtml(c.name)}${shared}
                    </button>
                </li>`;
        }).join('');

        el.list.querySelectorAll('.cloudpad-canvas-pick').forEach(btn => {
            btn.addEventListener('click', () => {
                selectCanvas(parseInt(btn.dataset.id, 10));
            });
        });
    }

    function updateDeleteButton() {
        if (!el.deleteBtn) return;
        const canvas = canvases.find(c => c.id === activeCanvasId);
        const show = canvas && canvas.access === 'owner';
        el.deleteBtn.classList.toggle('hidden', !show);
    }

    async function selectCanvas(id) {
        activeCanvasId = id;
        setUrl(id);
        renderList();
        updateDeleteButton();

        if (!el.editorWrap || !el.empty) return;

        if (!id) {
            window.CloudPad?.unmount();
            el.editorWrap.classList.add('hidden');
            el.empty.classList.remove('hidden');
            return;
        }

        el.empty.classList.add('hidden');
        el.editorWrap.classList.remove('hidden');

        try {
            await loadBundle();
            window.CloudPad.mount(id, el.root, { idPrefix: ID_PREFIX, embedded: true });
        } catch (err) {
            alert(err.message || 'Could not open canvas.');
        }
    }

    async function loadList() {
        if (!el.list) return;

        const { res, data } = await api('/api/cloudpad');
        if (!res.ok) {
            el.list.innerHTML = '<li class="text-danger mono text-xs py-2">Could not load canvases.</li>';
            return;
        }

        maxCanvases = data.max_canvases ?? 10;
        canvases = data.canvases || [];
        const owned = canvases.filter(c => c.access === 'owner');

        if (el.count) {
            el.count.textContent = `${owned.length} / ${maxCanvases}`;
        }

        renderList();
        updateDeleteButton();
    }

    async function bootCloudpadTab() {
        await loadList();

        let initialId = window.CLIPBOARDS_INITIAL_CANVAS_ID || 0;
        if (initialId && canvases.some(c => c.id === initialId)) {
            await selectCanvas(initialId);
        } else if (canvases.length > 0) {
            await selectCanvas(canvases[0].id);
        }
    }

    el.newBtn?.addEventListener('click', () => {
        el.newForm?.classList.remove('hidden');
        el.newBtn?.classList.add('hidden');
        el.newName?.focus();
    });

    document.getElementById('cloudpad-empty-new')?.addEventListener('click', () => {
        el.newBtn?.click();
    });

    el.newCancel?.addEventListener('click', () => {
        el.newForm?.classList.add('hidden');
        el.newBtn?.classList.remove('hidden');
        if (el.newName) el.newName.value = '';
    });

    el.newForm?.addEventListener('submit', async e => {
        e.preventDefault();
        const name = el.newName?.value.trim() || '';
        if (!name) return;

        const owned = canvases.filter(c => c.access === 'owner');
        if (owned.length >= maxCanvases) {
            alert(`Maximum ${maxCanvases} canvases per user.`);
            return;
        }

        const { res, data } = await api('/api/cloudpad/create', {
            method: 'POST',
            body: JSON.stringify({ name }),
        });
        if (!res.ok) {
            alert(data.error || 'Could not create canvas.');
            return;
        }

        el.newForm?.classList.add('hidden');
        el.newBtn?.classList.remove('hidden');
        if (el.newName) el.newName.value = '';

        await loadList();
        await selectCanvas(data.canvas.id);
    });

    el.deleteBtn?.addEventListener('click', async () => {
        if (!activeCanvasId) return;
        if (!confirm('Delete this canvas permanently?')) return;

        const { res, data } = await api(`/api/cloudpad/${activeCanvasId}/delete`, {
            method: 'POST',
            body: '{}',
        });
        if (!res.ok) {
            alert(data.error || 'Could not delete canvas.');
            return;
        }

        window.CloudPad?.unmount();
        activeCanvasId = 0;
        await loadList();

        if (canvases.length > 0) {
            await selectCanvas(canvases[0].id);
        } else {
            setUrl(0);
            el.editorWrap?.classList.add('hidden');
            el.empty?.classList.remove('hidden');
        }
    });

    document.addEventListener('cloudpad:renamed', e => {
        const { id, name } = e.detail || {};
        const row = canvases.find(c => c.id === id);
        if (row) {
            row.name = name;
            renderList();
        }
    });

    if (isCloudpadTabActive()) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', bootCloudpadTab);
        } else {
            bootCloudpadTab();
        }
    }
}());
