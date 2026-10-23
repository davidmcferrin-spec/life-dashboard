/**
 * Vault editor. Storage stays Markdown; the preview is generated here.
 */
'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const shell = document.querySelector('.brain-shell');
    const tree = document.getElementById('brain-tree');
    const titleEl = document.getElementById('brain-title');
    const bodyEl = document.getElementById('brain-body');
    const preview = document.getElementById('brain-preview');
    const kindEl = document.getElementById('brain-kind');
    const statusEl = document.getElementById('brain-status');
    const linksEl = document.getElementById('brain-links');
    const backsEl = document.getElementById('brain-backlinks');
    const revsEl = document.getElementById('brain-revs');
    const crumbsEl = document.getElementById('brain-crumbs');
    const moveEl = document.getElementById('brain-move');
    const stage = document.querySelector('.brain-stage');
    const redlineBox = document.getElementById('brain-redline');
    const redlineView = document.getElementById('brain-redline-view');
    const redlineSummary = document.getElementById('brain-redline-summary');
    const revCurrent = document.getElementById('brain-rev-current');
    const revRestore = document.getElementById('brain-rev-restore');
    const saveBtn = document.getElementById('brain-save');
    const REDLINE_KEY = 'ld_vault_redline';

    let pages = [];
    let folders = [];
    let current = null;
    let dirty = false;
    let viewing = null;
    let stash = null;
    let dirtyBeforeView = false;
    let activeFolder = null;
    let didExpand = false;
    const expanded = new Set();
    const split = document.getElementById('brain-split');
    preview.hidden = true;
    stage.classList.remove('is-preview');
    split.style.gridTemplateColumns = '1fr';
    try {
        redlineBox.checked = localStorage.getItem(REDLINE_KEY) === '1';
    } catch (err) {
        redlineBox.checked = false;
    }

    function setStatus(text) {
        statusEl.textContent = text || '';
    }

    function renderMarkdown(raw) {
        preview.innerHTML = window.BrainMarkdown.render(raw, pages);
    }

    function flattenFolders(nodes, depth, out) {
        nodes.forEach((folder) => {
            out.push({ id: folder.id, name: folder.name, parent_id: folder.parent_id, depth });
            flattenFolders(folder.children || [], depth + 1, out);
        });
        return out;
    }

    function flatFolders() {
        return flattenFolders(folders, 0, []);
    }

    function fillMoveMenu() {
        const currentId = current && current.folder_id ? String(current.folder_id) : '';
        const options = ['<option value="">Root</option>'].concat(flatFolders().map((folder) => {
            const pad = '\u00a0'.repeat(folder.depth * 2);
            return `<option value="${folder.id}">${pad}${esc(folder.name)}</option>`;
        }));
        moveEl.innerHTML = options.join('');
        moveEl.value = currentId;
    }

    function renderCrumbs(page) {
        const crumbs = (page && page.breadcrumbs) || [];
        if (!page) {
            crumbsEl.innerHTML = '';
            return;
        }
        const bits = [`<button type="button" data-folder="">Root</button>`];
        crumbs.forEach((crumb) => {
            bits.push(`<span class="brain-crumb-sep">/</span><button type="button" data-folder="${crumb.id}">${esc(crumb.name)}</button>`);
        });
        crumbsEl.innerHTML = bits.join('');
    }

    function pagesIn(folderId) {
        return pages.filter((page) => (page.folder_id || null) === folderId);
    }

    function folderHtml(nodes) {
        return nodes.map((folder) => {
            const open = expanded.has(folder.id);
            const kids = folderHtml(folder.children || []);
            const pageButtons = pagesIn(folder.id).map(pageButton).join('');
            return `<div class="brain-folder${activeFolder === folder.id ? ' is-active' : ''}" data-folder="${folder.id}">
                <div class="brain-folder-row" data-drop="${folder.id}">
                    <button type="button" class="brain-folder-toggle" data-toggle="${folder.id}" aria-label="${open ? 'Collapse' : 'Expand'}">${open ? '▾' : '▸'}</button>
                    <button type="button" class="brain-folder-name" data-folder-pick="${folder.id}">${esc(folder.name)}</button>
                </div>
                <div class="brain-folder-children"${open ? '' : ' hidden'}>${kids}${pageButtons}</div>
            </div>`;
        }).join('');
    }

    function pageButton(page) {
        return `<button type="button" class="brain-node${current && current.id === page.id ? ' is-active' : ''}" draggable="true" data-id="${page.id}">
            <i class="brain-kind brain-kind-${esc(page.kind)}"></i><span>${esc(page.title)}</span>
        </button>`;
    }

    function renderTree() {
        const rootPages = pagesIn(null).map(pageButton).join('');
        const rootDrop = '<div class="brain-folder-row" data-drop="root"><span class="brain-folder-name">Root</span></div>';
        const body = folderHtml(folders) + rootPages;
        tree.innerHTML = rootDrop + (body || window.DashboardEmpty.html({
            title: 'No pages yet',
            body: 'Create a page to start the vault.',
            actionLabel: 'New page',
            actionAttrs: 'type="button" id="brain-empty-new"',
        }));
        tree.querySelector('#brain-empty-new')?.addEventListener('click', () => {
            document.getElementById('brain-new')?.click();
        });
        fillMoveMenu();
    }

    function rememberRedline(on) {
        try {
            localStorage.setItem(REDLINE_KEY, on ? '1' : '0');
        } catch (err) {
            /* private mode can reject storage; the checkbox still works this visit */
        }
    }

    function clearRevisionChrome() {
        viewing = null;
        stash = null;
        dirtyBeforeView = false;
        titleEl.readOnly = false;
        bodyEl.readOnly = false;
        kindEl.disabled = false;
        saveBtn.disabled = false;
        revCurrent.hidden = true;
        revRestore.hidden = true;
        redlineView.hidden = true;
        redlineView.innerHTML = '';
        redlineSummary.textContent = '';
        stage.classList.remove('is-redline');
    }

    function paintRevButtons() {
        revsEl.querySelectorAll('[data-rev]').forEach((btn) => {
            const on = viewing !== null && String(viewing.version) === btn.dataset.rev;
            btn.classList.toggle('is-active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function applyRevisionView() {
        if (!viewing) return;
        titleEl.readOnly = true;
        bodyEl.readOnly = true;
        kindEl.disabled = true;
        saveBtn.disabled = true;
        revCurrent.hidden = false;
        revRestore.hidden = false;
        if (redlineBox.checked) {
            stage.classList.add('is-redline');
            redlineView.hidden = false;
            redlineView.innerHTML = viewing.diff.html;
            redlineSummary.textContent = viewing.diff.summary;
        } else {
            stage.classList.remove('is-redline');
            redlineView.hidden = true;
            redlineView.innerHTML = '';
            redlineSummary.textContent = '';
            titleEl.value = viewing.title;
            bodyEl.value = viewing.body;
            renderMarkdown(viewing.body);
        }
        paintRevButtons();
    }

    function leaveRevision() {
        const savedTitle = stash ? stash.title : titleEl.value;
        const savedBody = stash ? stash.body : bodyEl.value;
        const savedKind = stash ? stash.kind : kindEl.value;
        const wasDirty = dirtyBeforeView;
        clearRevisionChrome();
        titleEl.value = savedTitle;
        bodyEl.value = savedBody;
        kindEl.value = savedKind;
        dirty = wasDirty;
        renderMarkdown(bodyEl.value);
        paintRevButtons();
    }

    function show(page) {
        current = page;
        dirty = false;
        clearRevisionChrome();
        titleEl.value = page ? page.title : '';
        bodyEl.value = page ? (page.body || '') : '';
        kindEl.value = page ? page.kind : 'note';
        linksEl.innerHTML = (page && page.links || []).map((link) =>
            link.id ? `<a class="brain-back" href="/brain?page=${link.id}">${esc(link.title)}</a>` : `<span class="brain-back km-missing">${esc(link.title)}</span>`
        ).join('') || '<p class="ld-meta">No outgoing links.</p>';
        backsEl.innerHTML = (page && page.backlinks || []).map((link) =>
            `<a class="brain-back" href="/brain?page=${link.id}">${esc(link.title)}</a>`
        ).join('') || '<p class="ld-meta">No backlinks.</p>';
        revsEl.innerHTML = (page && page.revisions || []).map((rev) =>
            `<li><button type="button" data-rev="${rev.version}" aria-pressed="false">v${rev.version}</button> <span class="ld-meta">${esc(fmt.monthDay(String(rev.created_at).slice(0, 10)))}</span></li>`
        ).join('');
        renderCrumbs(page);
        renderTree();
        renderMarkdown(bodyEl.value);
        if (page) history.replaceState(null, '', '/brain?page=' + page.id);
    }

    async function loadList(q) {
        const folder = activeFolder ? '&folder_id=' + activeFolder : '';
        const res = await api.get('/pages' + (q ? '?q=' + encodeURIComponent(q) + folder : (folder ? '?' + folder.slice(1) : '')));
        pages = res.pages || [];
        const treeRes = await api.get('/pages/folders');
        folders = treeRes.folders || [];
        if (!didExpand) {
            folders.forEach((folderNode) => walkExpand(folderNode));
            didExpand = true;
        }
        renderTree();
    }

    function walkExpand(folder) {
        expanded.add(folder.id);
        (folder.children || []).forEach(walkExpand);
    }

    async function openPage(id) {
        const res = await api.get('/pages/' + id);
        const brief = pages.find((page) => page.id === res.page.id);
        if (!brief) pages.unshift({ id: res.page.id, title: res.page.title, kind: res.page.kind, slug: res.page.slug, folder_id: res.page.folder_id, updated_at: res.page.updated_at });
        show(res.page);
    }

    async function save() {
        const payload = { title: titleEl.value.trim(), body: bodyEl.value, kind: kindEl.value };
        if (!current && activeFolder) payload.folder_id = activeFolder;
        if (!payload.title) {
            setStatus('Add a title first.');
            return;
        }
        setStatus('Saving…');
        const res = current
            ? await api.post('/pages/' + current.id + '/update', payload)
            : await api.post('/pages', payload);
        await loadList(document.getElementById('brain-search').value.trim());
        show(res.page);
        setStatus('Saved.');
    }

    async function moveCurrent(folderId) {
        if (!current) return;
        const id = folderId === '' ? null : Number(folderId);
        setStatus('Moving…');
        const res = await api.post('/pages/' + current.id + '/move', { folder_id: id });
        await loadList(document.getElementById('brain-search').value.trim());
        show(res.page);
        setStatus('Moved.');
    }

    tree.addEventListener('click', (e) => {
        const toggle = e.target.closest('[data-toggle]');
        if (toggle) {
            const id = Number(toggle.dataset.toggle);
            if (expanded.has(id)) expanded.delete(id);
            else expanded.add(id);
            renderTree();
            return;
        }
        const folderBtn = e.target.closest('[data-folder-pick]');
        if (folderBtn) {
            activeFolder = Number(folderBtn.dataset.folderPick);
            loadList(document.getElementById('brain-search').value.trim()).catch((err) => setStatus(err.message));
            return;
        }
        const btn = e.target.closest('[data-id]');
        if (btn) openPage(Number(btn.dataset.id)).catch((err) => setStatus(err.message));
    });
    tree.addEventListener('dragstart', (e) => {
        const btn = e.target.closest('[data-id]');
        if (!btn) return;
        e.dataTransfer.setData('text/page-id', btn.dataset.id);
        e.dataTransfer.setData('text/plain', btn.dataset.id);
        e.dataTransfer.effectAllowed = 'move';
    });
    tree.addEventListener('dragover', (e) => {
        const row = e.target.closest('[data-drop]');
        if (!row) return;
        e.preventDefault();
        row.classList.add('is-drop');
    });
    tree.addEventListener('dragleave', (e) => {
        const row = e.target.closest('[data-drop]');
        if (row) row.classList.remove('is-drop');
    });
    tree.addEventListener('drop', (e) => {
        const row = e.target.closest('[data-drop]');
        if (!row) return;
        e.preventDefault();
        row.classList.remove('is-drop');
        const pageId = Number(e.dataTransfer.getData('text/page-id') || e.dataTransfer.getData('text/plain'));
        const raw = row.dataset.drop;
        const folderId = raw === 'root' ? null : Number(raw);
        if (!pageId || (folderId !== null && !folderId)) return;
        api.post('/pages/' + pageId + '/move', { folder_id: folderId })
            .then(() => loadList(document.getElementById('brain-search').value.trim()))
            .then(() => {
                if (current && current.id === pageId) return openPage(pageId);
            })
            .then(() => setStatus('Moved.'))
            .catch((err) => setStatus(err.message));
    });
    crumbsEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-folder]');
        if (!btn) return;
        activeFolder = btn.dataset.folder === '' ? null : Number(btn.dataset.folder);
        loadList(document.getElementById('brain-search').value.trim()).catch((err) => setStatus(err.message));
    });
    moveEl.addEventListener('change', () => {
        moveCurrent(moveEl.value).catch((err) => setStatus(err.message));
    });
    document.getElementById('brain-new').addEventListener('click', () => show(null));
    function openFolderDialog() {
        if (document.getElementById('brain-folder-modal')) return;

        let settled = false;
        const modal = document.createElement('div');
        modal.id = 'brain-folder-modal';
        modal.className = 'app-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'brain-folder-title');
        modal.innerHTML = ''
            + '<div class="app-modal-backdrop" data-close aria-hidden="true"></div>'
            + '<div class="app-modal-panel surface">'
            + '<div class="flex items-center justify-between gap-2 mb-3">'
            + '<h2 id="brain-folder-title" class="section-title text-sm">New folder</h2>'
            + '<button type="button" class="app-modal-close" data-close aria-label="Close">×</button>'
            + '</div>'
            + '<form id="brain-folder-form" class="space-y-3">'
            + '<label class="block">'
            + '<span class="mono text-xs text-faint">Folder name</span>'
            + '<input type="text" id="brain-folder-name" class="input w-full mt-1 text-sm" autocomplete="off">'
            + '</label>'
            + '<div class="flex flex-wrap gap-2">'
            + '<button type="submit" class="btn-primary text-sm">Create</button>'
            + '<button type="button" class="btn text-sm" data-close>Cancel</button>'
            + '</div>'
            + '</form>'
            + '</div>';

        function finish(name) {
            if (settled) return;
            settled = true;
            modal.classList.add('hidden');
            modal.remove();
            if (!document.querySelector('.app-modal:not(.hidden)')) {
                document.body.classList.remove('app-modal-open');
            }
            if (!name) return;
            api.post('/pages/folders', { name: name.trim(), parent_id: activeFolder })
                .then(() => loadList(document.getElementById('brain-search').value.trim()))
                .then(() => setStatus('Folder created.'))
                .catch((err) => setStatus(err.message));
        }

        modal.querySelector('#brain-folder-form').addEventListener('submit', (event) => {
            event.preventDefault();
            finish(modal.querySelector('#brain-folder-name').value);
        });
        modal.addEventListener('click', (event) => {
            const target = event.target;
            if (target && typeof target.closest === 'function' && target.closest('[data-close]')) {
                finish('');
            }
        });

        document.body.classList.add('app-modal-open');
        document.body.appendChild(modal);
    }

    document.getElementById('brain-folder').addEventListener('click', openFolderDialog);
    document.getElementById('brain-save').addEventListener('click', () => save().catch((err) => setStatus(err.message)));
    titleEl.addEventListener('input', () => { dirty = true; });
    bodyEl.addEventListener('input', () => { dirty = true; renderMarkdown(bodyEl.value); });
    document.querySelectorAll('[data-brain-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const previewing = btn.dataset.brainTab === 'preview';
            document.querySelectorAll('[data-brain-tab]').forEach((item) => item.classList.toggle('is-active', item === btn));
            bodyEl.hidden = previewing;
            preview.hidden = !previewing;
            stage.classList.toggle('is-preview', previewing);
            split.style.gridTemplateColumns = '1fr';
            if (previewing) renderMarkdown(bodyEl.value);
        });
    });
    async function viewRevision(version) {
        if (!current) return;
        if (!stash) {
            stash = { title: titleEl.value, body: bodyEl.value, kind: kindEl.value };
            dirtyBeforeView = dirty;
        }
        const res = await api.get('/pages/' + current.id + '/revisions/' + version);
        viewing = {
            version: res.revision.version,
            title: res.revision.title,
            body: res.revision.body,
            diff: res.diff,
        };
        dirty = false;
        applyRevisionView();
        setStatus('Viewing version ' + version + '.');
    }

    revsEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-rev]');
        if (!btn || !current) return;
        viewRevision(Number(btn.dataset.rev)).catch((err) => setStatus(err.message));
    });
    redlineBox.addEventListener('change', () => {
        rememberRedline(redlineBox.checked);
        if (viewing) applyRevisionView();
    });
    revCurrent.addEventListener('click', () => {
        leaveRevision();
        setStatus('');
    });
    revRestore.addEventListener('click', () => {
        if (!viewing || !current) return;
        api.post('/pages/' + current.id + '/restore', { version: viewing.version })
            .then((res) => show(res.page))
            .then(() => setStatus('Restored.'))
            .catch((err) => setStatus(err.message));
    });
    let searchTimer = null;
    document.getElementById('brain-search').addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadList(e.target.value.trim()).catch((err) => setStatus(err.message)), 200);
    });
    window.addEventListener('beforeunload', (e) => {
        if (dirty || dirtyBeforeView) e.preventDefault();
    });

    const open = Number(shell.dataset.openPage || 0);
    loadList('').then(() => {
        if (open > 0) return openPage(open);
        if (pages[0]) return openPage(pages[0].id);
        show(null);
    }).catch((err) => setStatus(err.message));
})();
