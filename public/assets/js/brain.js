/**
 * Vault editor. Storage stays Markdown; the preview is generated here.
 */
'use strict';

(function () {
    const { api, esc, fmt } = window.LD;
    const shell = document.querySelector('.brain-shell');
    const tree = document.getElementById('brain-tree');
    tree.setAttribute('role', 'tree');
    tree.setAttribute('aria-label', 'Vault folders');
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
    let treeCursor = '';
    const expanded = new Set();
    const split = document.getElementById('brain-split');
    try {
        redlineBox.checked = localStorage.getItem(REDLINE_KEY) === '1';
    } catch (err) {
        redlineBox.checked = false;
    }

    function setView(mode) {
        const previewing = mode === 'preview';
        document.querySelectorAll('[data-brain-tab]').forEach((item) => {
            const on = item.dataset.brainTab === mode;
            item.classList.toggle('is-active', on);
            item.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        bodyEl.hidden = previewing;
        preview.hidden = !previewing;
        stage.classList.toggle('is-preview', previewing);
        split.style.gridTemplateColumns = '1fr';
        if (previewing) renderMarkdown(bodyEl.value);
    }

    setView('preview');

    function setStatus(text) {
        statusEl.textContent = text || '';
    }

    function optional(id) {
        return typeof document.querySelector === 'function' ? document.querySelector('#' + id) : null;
    }

    function iconHtml(id) {
        const source = optional(id);
        return source && source.innerHTML ? source.innerHTML : '';
    }

    function renderMarkdown(raw) {
        preview.innerHTML = window.BrainMarkdown.render(raw, pages);
        if (typeof preview.querySelectorAll === 'function') {
            preview.querySelectorAll('h2, h3, h4, h5, h6').forEach((node, index) => {
                if (typeof node.setAttribute === 'function') node.setAttribute('id', 'brain-h-' + index);
            });
        }
        paintTags(raw);
        paintOutline(raw);
    }

    function addTag(tags, seen, name) {
        const clean = String(name || '').replace(/^#/, '').trim();
        if (!/^[A-Za-z][\w-]{0,40}$/.test(clean)) return;
        const key = clean.toLowerCase();
        if (seen.has(key)) return;
        seen.add(key);
        tags.push(clean);
    }

    function tagsFrom(raw) {
        const tags = [];
        const seen = new Set();
        const text = String(raw || '').replace(/\r\n/g, '\n');
        const fm = text.match(/^---\n([\s\S]*?)\n---\n?/);
        let body = text;
        if (fm) {
            body = text.slice(fm[0].length);
            const inline = fm[1].match(/^tags:\s*(.+)$/m);
            if (inline) inline[1].split(',').forEach((name) => addTag(tags, seen, name));
            const list = fm[1].match(/^tags:\s*\n((?:[ \t]*-[ \t]+.+\n?)*)/m);
            if (list) list[1].split('\n').forEach((line) => addTag(tags, seen, line.replace(/^[ \t]*-[ \t]+/, '')));
        }
        let fence = false;
        body.split('\n').forEach((line) => {
            if (/^```/.test(line)) {
                fence = !fence;
                return;
            }
            if (fence || /^#{1,6}\s/.test(line)) return;
            const re = /(^|[\s(])#([A-Za-z][\w-]{0,40})\b/g;
            let match;
            while ((match = re.exec(line))) addTag(tags, seen, match[2]);
        });
        return tags;
    }

    function headingsOf(raw) {
        const items = [];
        let fence = false;
        String(raw || '').replace(/\r\n/g, '\n').split('\n').forEach((line) => {
            if (/^```/.test(line)) {
                fence = !fence;
                return;
            }
            if (fence) return;
            const match = /^(#{2,6})\s+(.+)$/.exec(line);
            if (!match) return;
            const text = match[2].replace(/[`*_]/g, '').trim();
            if (text) items.push({ level: match[1].length, text });
        });
        return items;
    }

    function paintTags(raw) {
        const el = optional('brain-tags');
        if (!el) return;
        const tags = tagsFrom(raw);
        el.innerHTML = tags.length
            ? tags.map((tag) => `<span class="brain-chip">${esc(tag)}</span>`).join('')
            : '<p class="ld-meta">No tags.</p>';
    }

    function paintOutline(raw) {
        const el = optional('brain-outline');
        if (!el) return;
        const items = headingsOf(raw);
        el.innerHTML = items.length
            ? items.map((item, index) => `<button type="button" class="brain-outline" data-outline="${index}" data-level="${item.level}">${esc(item.text)}</button>`).join('')
            : '<p class="ld-meta">No outline.</p>';
    }

    function paintBacklinks(links) {
        const rows = links || [];
        const count = optional('brain-back-count');
        if (count) count.textContent = rows.length ? '· ' + rows.length : '';
        const icon = iconHtml('brain-icon-link');
        const chevron = iconHtml('brain-icon-chevron');
        backsEl.innerHTML = rows.map((link) =>
            `<a class="brain-back" href="/brain?page=${Number(link.id)}"><span class="brain-ctx-icon" aria-hidden="true">${icon}</span><span class="brain-tree-label">${esc(link.title)}</span><span class="brain-ctx-chevron" aria-hidden="true">${chevron}</span></a>`
        ).join('') || '<p class="ld-meta">No backlinks.</p>';
    }

    function linksFromGraph(page, graph) {
        if (!page || !graph || !Array.isArray(graph.edges) || !Array.isArray(graph.nodes)) return null;
        const id = Number(page.id);
        const titles = new Map();
        graph.nodes.forEach((node) => titles.set(Number(node.id), node.title));
        const rows = [];
        const seen = new Set();
        graph.edges.forEach((edge) => {
            if (Number(edge.to) !== id) return;
            const from = Number(edge.from);
            if (!titles.has(from) || seen.has(from)) return;
            seen.add(from);
            rows.push({ id: from, title: titles.get(from) });
        });
        rows.sort((a, b) => String(a.title).localeCompare(String(b.title)));
        return rows;
    }

    function refreshBacklinks(page) {
        api.get('/pages/graph').then((graph) => {
            if (!current || !page || current.id !== page.id) return;
            const rows = linksFromGraph(page, graph);
            if (rows) paintBacklinks(rows);
        }).catch(() => {});
    }

    function noteMeta(page) {
        if (!page || !page.updated_at) return '';
        const day = fmt && fmt.monthDay ? fmt.monthDay(String(page.updated_at).slice(0, 10)) : String(page.updated_at).slice(0, 10);
        return 'Updated ' + day;
    }

    function setTreeOpen(on) {
        shell.classList.toggle('is-tree-open', on);
        const toggle = optional('brain-tree-toggle');
        const scrim = optional('brain-tree-scrim');
        if (toggle) toggle.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (scrim) scrim.hidden = !on;
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

    function treeLabel(text) {
        return `<span class="brain-tree-label">${esc(text)}</span>`;
    }

    function treeIcon(id) {
        const source = document.getElementById(id);
        return source && source.innerHTML ? source.innerHTML : '';
    }

    function folderHtml(nodes, parentKey) {
        return nodes.map((folder) => {
            const open = expanded.has(folder.id);
            const key = 'folder:' + folder.id;
            const kids = folderHtml(folder.children || [], key);
            const pageButtons = pagesIn(folder.id).map((page) => pageButton(page, key)).join('');
            const selected = activeFolder === folder.id;
            const verb = open ? 'Collapse' : 'Expand';
            return `<div class="brain-folder${selected ? ' is-active' : ''}" data-folder="${folder.id}">
                <div class="brain-folder-row brain-tree-row" role="treeitem" data-drop="${folder.id}" data-tree-key="${key}" data-tree-kind="folder" data-tree-id="${folder.id}" data-parent-key="${parentKey}" aria-expanded="${open ? 'true' : 'false'}" aria-selected="${selected ? 'true' : 'false'}">
                    <button type="button" class="brain-folder-toggle" tabindex="-1" data-toggle="${folder.id}" aria-expanded="${open ? 'true' : 'false'}" aria-label="${verb} ${esc(folder.name)}"><span class="brain-chevron" aria-hidden="true">${treeIcon('brain-icon-chevron')}</span></button>
                    <button type="button" class="brain-folder-name" tabindex="-1" data-folder-pick="${folder.id}" title="${esc(folder.name)}"><span class="brain-tree-folder" aria-hidden="true">${treeIcon('brain-icon-folder')}</span>${treeLabel(folder.name)}</button>
                </div>
                <div class="brain-folder-children${open ? '' : ' is-collapsed'}" role="group"><div class="brain-folder-branch">${kids}${pageButtons}</div></div>
            </div>`;
        }).join('');
    }

    function pageButton(page, parentKey) {
        const on = current && current.id === page.id;
        return `<button type="button" class="brain-node brain-tree-row${on ? ' is-active' : ''}" role="treeitem" draggable="true" data-id="${page.id}" data-tree-key="page:${page.id}" data-tree-kind="page" data-tree-id="${page.id}" data-parent-key="${parentKey}" aria-selected="${on ? 'true' : 'false'}" title="${esc(page.title)}">
            <span class="brain-tree-slot" aria-hidden="true"></span><span class="brain-kind brain-kind-${esc(page.kind)}" aria-hidden="true">${treeIcon('brain-icon-file')}</span>${treeLabel(page.title)}
        </button>`;
    }

    function focusInsideTree() {
        const active = document.activeElement;
        if (!active || typeof tree.contains !== 'function') return false;
        return tree.contains(active);
    }

    function rowConcealed(row) {
        if (!row || typeof row.closest !== 'function') return false;
        return !!(row.closest('[hidden]') || row.closest('.brain-folder-children.is-collapsed'));
    }

    function visibleRows() {
        if (typeof tree.querySelectorAll !== 'function') return [];
        return Array.from(tree.querySelectorAll('[data-tree-key]')).filter((row) => !rowConcealed(row));
    }

    function syncCollapsedGroups() {
        if (typeof tree.querySelectorAll !== 'function') return;
        tree.querySelectorAll('.brain-folder-children').forEach((group) => {
            const collapsed = group.classList.contains('is-collapsed');
            if (collapsed) group.setAttribute('inert', '');
            else if (typeof group.removeAttribute === 'function') group.removeAttribute('inert');
        });
        tree.querySelectorAll('[data-tree-key]').forEach((row) => {
            if (rowConcealed(row)) row.tabIndex = -1;
        });
    }

    function placeTreeTab(restoreFocus) {
        const rows = visibleRows();
        let row = rows.find((item) => item.dataset.treeKey === treeCursor);
        if (!row) row = rows[0] || null;
        rows.forEach((item) => { item.tabIndex = item === row ? 0 : -1; });
        syncCollapsedGroups();
        if (restoreFocus && row && typeof row.focus === 'function') row.focus();
    }

    function moveTree(row) {
        if (!row) return;
        treeCursor = row.dataset.treeKey || '';
        visibleRows().forEach((item) => { item.tabIndex = item === row ? 0 : -1; });
        if (typeof row.focus === 'function') row.focus();
        if (typeof row.scrollIntoView === 'function') row.scrollIntoView({ block: 'nearest' });
    }

    function currentTreeRow() {
        const active = document.activeElement;
        if (active && typeof active.closest === 'function' && typeof tree.contains === 'function') {
            const row = active.closest('[data-tree-key]');
            if (row && tree.contains(row)) return row;
        }
        return visibleRows().find((item) => item.tabIndex === 0) || null;
    }

    function firstChildRow(row) {
        const folder = row.closest('.brain-folder');
        if (!folder || typeof folder.querySelector !== 'function') return null;
        const group = folder.querySelector('.brain-folder-children');
        if (!group || group.hidden || group.classList.contains('is-collapsed') || typeof group.querySelector !== 'function') return null;
        const child = group.querySelector('[data-tree-key]');
        if (!child || rowConcealed(child)) return null;
        return child;
    }

    function toggleFolder(id) {
        const folderId = Number(id);
        if (!folderId) return;
        treeCursor = 'folder:' + folderId;
        const willOpen = !expanded.has(folderId);
        if (willOpen) expanded.add(folderId);
        else expanded.delete(folderId);
        const foldersInTree = typeof tree.querySelectorAll === 'function' ? Array.from(tree.querySelectorAll('.brain-folder')) : [];
        const folder = foldersInTree.find((node) => node.dataset.folder === String(folderId));
        const group = folder && folder.querySelector('.brain-folder-children');
        if (!folder || !group) {
            renderTree();
            return;
        }
        group.classList.toggle('is-collapsed', !willOpen);
        const expandedText = willOpen ? 'true' : 'false';
        const row = folder.querySelector('.brain-tree-row');
        if (row) row.setAttribute('aria-expanded', expandedText);
        const toggle = folder.querySelector('.brain-folder-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', expandedText);
            const name = folder.querySelector('.brain-folder-name');
            const label = name && name.getAttribute ? (name.getAttribute('title') || '') : '';
            toggle.setAttribute('aria-label', (willOpen ? 'Collapse' : 'Expand') + (label ? ' ' + label : ''));
        }
        placeTreeTab(focusInsideTree());
    }

    function renderTree() {
        const restoreFocus = focusInsideTree();
        const rootPages = pagesIn(null).map((page) => pageButton(page, '')).join('');
        const rootDrop = `<div class="brain-folder-row brain-tree-row" role="treeitem" data-drop="root" data-tree-key="root" data-tree-kind="root" data-parent-key="" aria-selected="false">
            <span class="brain-tree-slot" aria-hidden="true"></span><span class="brain-tree-folder" aria-hidden="true">${treeIcon('brain-icon-folder')}</span><span class="brain-folder-name" title="Root">${treeLabel('Root')}</span>
        </div>`;
        const body = folderHtml(folders, '') + rootPages;
        tree.innerHTML = rootDrop + (body || window.DashboardEmpty.html({
            title: 'No pages yet',
            body: 'Create a page to start the vault.',
            actionLabel: 'New page',
            actionAttrs: 'type="button" id="brain-empty-new"',
        }));
        tree.querySelector('#brain-empty-new')?.addEventListener('click', () => {
            document.getElementById('brain-new')?.click();
        });
        placeTreeTab(restoreFocus);
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
        const archiveBtn = optional('brain-archive');
        if (archiveBtn) archiveBtn.disabled = !current;
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
        const archiveBtn = optional('brain-archive');
        if (archiveBtn) archiveBtn.disabled = true;
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
        paintBacklinks(page && page.backlinks || []);
        if (page) refreshBacklinks(page);
        revsEl.innerHTML = (page && page.revisions || []).map((rev) =>
            `<li><button type="button" data-rev="${rev.version}" aria-pressed="false">v${rev.version}</button> <span class="ld-meta">${esc(fmt.monthDay(String(rev.created_at).slice(0, 10)))}</span></li>`
        ).join('');
        renderCrumbs(page);
        if (page) treeCursor = 'page:' + page.id;
        renderTree();
        renderMarkdown(bodyEl.value);
        setStatus(noteMeta(page));
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
        const opened = !current || current.id !== res.page.id;
        const brief = pages.find((page) => page.id === res.page.id);
        if (!brief) pages.unshift({ id: res.page.id, title: res.page.title, kind: res.page.kind, slug: res.page.slug, folder_id: res.page.folder_id, updated_at: res.page.updated_at });
        show(res.page);
        setTreeOpen(false);
        // Opening a note starts in preview. Save and move keep the Edit toggle.
        if (opened) setView('preview');
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
            toggleFolder(toggle.dataset.toggle);
            return;
        }
        const folderBtn = e.target.closest('[data-folder-pick]');
        if (folderBtn) {
            activeFolder = Number(folderBtn.dataset.folderPick);
            treeCursor = 'folder:' + activeFolder;
            loadList(document.getElementById('brain-search').value.trim()).catch((err) => setStatus(err.message));
            return;
        }
        const btn = e.target.closest('[data-id]');
        if (btn) {
            setTreeOpen(false);
            openPage(Number(btn.dataset.id)).catch((err) => setStatus(err.message));
        }
    });
    // Space is handled here so the tree does not scroll the page.
    tree.addEventListener('keydown', (e) => {
        const key = e.key;
        if (key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'ArrowLeft' && key !== 'ArrowRight' && key !== 'Enter' && key !== ' ') return;
        const row = currentTreeRow();
        if (!row) return;
        if (key === 'ArrowDown' || key === 'ArrowUp') {
            e.preventDefault();
            const rows = visibleRows();
            const index = rows.indexOf(row);
            const next = key === 'ArrowDown' ? rows[index + 1] : rows[index - 1];
            if (next) moveTree(next);
            return;
        }
        if (key === 'ArrowLeft') {
            e.preventDefault();
            if (row.dataset.treeKind === 'folder' && expanded.has(Number(row.dataset.treeId))) {
                toggleFolder(row.dataset.treeId);
                return;
            }
            const parent = visibleRows().find((item) => item.dataset.treeKey === row.dataset.parentKey);
            if (parent) moveTree(parent);
            return;
        }
        if (key === 'ArrowRight') {
            e.preventDefault();
            if (row.dataset.treeKind !== 'folder') return;
            const id = Number(row.dataset.treeId);
            if (!expanded.has(id)) {
                toggleFolder(id);
                return;
            }
            const child = firstChildRow(row);
            if (child) moveTree(child);
            return;
        }
        if (key === ' ') {
            e.preventDefault();
            if (row.dataset.treeKind === 'folder') toggleFolder(row.dataset.treeId);
            return;
        }
        e.preventDefault();
        if (row.dataset.treeKind === 'folder') toggleFolder(row.dataset.treeId);
        else if (row.dataset.treeKind === 'page') openPage(Number(row.dataset.treeId)).catch((err) => setStatus(err.message));
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
            + '<button type="button" class="app-modal-close" data-close aria-label="Close">' + (iconHtml('brain-icon-x') || '') + '</button>'
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
    async function archiveCurrent() {
        if (!current || !window.AppModal) return;
        const ok = await window.AppModal.confirm({
            title: 'Archive page',
            message: 'Archive ' + (current.title || 'this page') + '? It leaves the vault list.',
            confirmLabel: 'Archive',
            danger: true,
        });
        if (!ok) return;
        const id = current.id;
        setStatus('Archiving…');
        await api.post('/pages/' + id + '/archive', {});
        current = null;
        await loadList(document.getElementById('brain-search').value.trim());
        if (pages[0]) await openPage(pages[0].id);
        else show(null);
        setStatus('Archived.');
    }
    const archiveBtn = optional('brain-archive');
    if (archiveBtn) archiveBtn.addEventListener('click', () => archiveCurrent().catch((err) => setStatus(err.message)));
    const treeToggle = optional('brain-tree-toggle');
    if (treeToggle) treeToggle.addEventListener('click', () => setTreeOpen(!shell.classList.contains('is-tree-open')));
    const treeScrim = optional('brain-tree-scrim');
    if (treeScrim) treeScrim.addEventListener('click', () => setTreeOpen(false));
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && shell.classList.contains('is-tree-open')) setTreeOpen(false);
    });
    const outlineEl = optional('brain-outline');
    if (outlineEl) {
        outlineEl.addEventListener('click', (event) => {
            const btn = event.target && event.target.closest ? event.target.closest('[data-outline]') : null;
            if (!btn || typeof preview.querySelector !== 'function') return;
            const node = preview.querySelector('#brain-h-' + btn.dataset.outline);
            if (node && typeof node.scrollIntoView === 'function') node.scrollIntoView({ block: 'nearest' });
        });
    }
    titleEl.addEventListener('input', () => { dirty = true; });
    bodyEl.addEventListener('input', () => { dirty = true; renderMarkdown(bodyEl.value); });
    document.querySelectorAll('[data-brain-tab]').forEach((btn) => {
        btn.addEventListener('click', () => setView(btn.dataset.brainTab));
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
