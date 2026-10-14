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

    let pages = [];
    let current = null;
    let dirty = false;
    const split = document.getElementById('brain-split');
    preview.hidden = true;
    split.style.gridTemplateColumns = '1fr';

    function setStatus(text) {
        statusEl.textContent = text || '';
    }

    function titles() {
        const map = new Map();
        pages.forEach((p) => map.set(p.title.toLowerCase(), p));
        return map;
    }

    function renderInline(text, known) {
        const src = String(text);
        const re = /\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/g;
        const chunks = [];
        let last = 0;
        let m;
        while ((m = re.exec(src))) {
            chunks.push(esc(src.slice(last, m.index)));
            const target = m[1].trim();
            const page = known.get(target.toLowerCase());
            const label = esc((m[2] || target).trim());
            chunks.push(page ? `<a href="/brain?page=${page.id}">${label}</a>` : `<span class="km-missing">${label}</span>`);
            last = m.index + m[0].length;
        }
        chunks.push(esc(src.slice(last)));
        let s = chunks.join('');
        s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>');
        s = s.replace(/==([^=\n]+)==/g, '<mark>$1</mark>');
        s = s.replace(/\$([^$\n]+)\$/g, '<span class="km-math">$1</span>');
        s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\[([^\]\n]+)\]\((https?:[^)\s]+)\)/g, '<a href="$2" rel="noopener noreferrer" target="_blank">$1</a>');
        return s;
    }

    function renderMarkdown(raw) {
        const known = titles();
        const lines = String(raw || '').replace(/\r\n/g, '\n').split('\n');
        const html = [];
        let i = 0;
        while (i < lines.length) {
            const line = lines[i];
            if (/^```/.test(line)) {
                i++;
                const code = [];
                while (i < lines.length && !/^```/.test(lines[i])) code.push(lines[i++]);
                if (i < lines.length) i++;
                html.push(`<pre class="km-code">${esc(code.join('\n'))}</pre>`);
                continue;
            }
            const callout = line.match(/^>\s*\[!([a-z]+)\]\s*(.*)$/i);
            if (callout) {
                const kind = callout[1].toLowerCase();
                const bits = [callout[2]];
                i++;
                while (i < lines.length && lines[i].startsWith('>')) {
                    bits.push(lines[i].replace(/^>\s?/, ''));
                    i++;
                }
                html.push(`<div class="km-callout km-${esc(kind)}">${bits.map((b) => `<p>${renderInline(b, known)}</p>`).join('')}</div>`);
                continue;
            }
            const heading = line.match(/^(#{1,3})\s+(.*)$/);
            if (heading) {
                html.push(`<h${heading[1].length}>${renderInline(heading[2], known)}</h${heading[1].length}>`);
                i++;
                continue;
            }
            if (/^[-*]\s+\[[ xX]\]\s+/.test(line)) {
                const items = [];
                while (i < lines.length && /^[-*]\s+\[[ xX]\]\s+/.test(lines[i])) {
                    const checked = /\[[xX]\]/.test(lines[i]);
                    items.push(`<li class="km-task"><label><input type="checkbox" disabled ${checked ? 'checked' : ''}> ${renderInline(lines[i].replace(/^[-*]\s+\[[ xX]\]\s+/, ''), known)}</label></li>`);
                    i++;
                }
                html.push(`<ul>${items.join('')}</ul>`);
                continue;
            }
            if (/^[-*]\s+/.test(line)) {
                const items = [];
                while (i < lines.length && /^[-*]\s+/.test(lines[i])) {
                    items.push(`<li>${renderInline(lines[i].replace(/^[-*]\s+/, ''), known)}</li>`);
                    i++;
                }
                html.push(`<ul>${items.join('')}</ul>`);
                continue;
            }
            if (line.trim() === '') {
                i++;
                continue;
            }
            html.push(`<p>${renderInline(line, known)}</p>`);
            i++;
        }
        preview.innerHTML = html.join('') || '<p class="ld-meta">Nothing to preview.</p>';
    }

    function renderTree() {
        tree.innerHTML = pages.map((p) =>
            `<button type="button" class="brain-node${current && current.id === p.id ? ' is-active' : ''}" data-id="${p.id}">
                <i class="brain-kind brain-kind-${esc(p.kind)}"></i><span>${esc(p.title)}</span>
            </button>`
        ).join('') || '<p class="empty-state mono text-xs">No pages yet.</p>';
    }

    function show(page) {
        current = page;
        dirty = false;
        titleEl.value = page ? page.title : '';
        bodyEl.value = page ? (page.body || '') : '';
        kindEl.value = page ? page.kind : 'note';
        linksEl.innerHTML = (page && page.links || []).map((l) =>
            l.id ? `<a class="brain-back" href="/brain?page=${l.id}">${esc(l.title)}</a>` : `<span class="brain-back km-missing">${esc(l.title)}</span>`
        ).join('') || '<p class="ld-meta">No outgoing links.</p>';
        backsEl.innerHTML = (page && page.backlinks || []).map((l) =>
            `<a class="brain-back" href="/brain?page=${l.id}">${esc(l.title)}</a>`
        ).join('') || '<p class="ld-meta">No backlinks.</p>';
        revsEl.innerHTML = (page && page.revisions || []).map((r) =>
            `<li><button type="button" data-rev="${r.version}">v${r.version}</button> <span class="ld-meta">${esc(fmt.monthDay(String(r.created_at).slice(0, 10)))}</span></li>`
        ).join('');
        renderTree();
        renderMarkdown(bodyEl.value);
        if (page) history.replaceState(null, '', '/brain?page=' + page.id);
    }

    async function loadList(q) {
        const res = await api.get('/pages' + (q ? '?q=' + encodeURIComponent(q) : ''));
        pages = res.pages || [];
        renderTree();
    }

    async function openPage(id) {
        const res = await api.get('/pages/' + id);
        const brief = pages.find((p) => p.id === res.page.id);
        if (!brief) pages.unshift({ id: res.page.id, title: res.page.title, kind: res.page.kind, slug: res.page.slug, updated_at: res.page.updated_at });
        show(res.page);
    }

    async function save() {
        const payload = { title: titleEl.value.trim(), body: bodyEl.value, kind: kindEl.value };
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

    tree.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-id]');
        if (btn) openPage(Number(btn.dataset.id)).catch((err) => setStatus(err.message));
    });
    document.getElementById('brain-new').addEventListener('click', () => show(null));
    document.getElementById('brain-save').addEventListener('click', () => save().catch((err) => setStatus(err.message)));
    titleEl.addEventListener('input', () => { dirty = true; });
    bodyEl.addEventListener('input', () => { dirty = true; renderMarkdown(bodyEl.value); });
    document.querySelectorAll('[data-brain-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const previewing = btn.dataset.brainTab === 'preview';
            document.querySelectorAll('[data-brain-tab]').forEach((b) => b.classList.toggle('is-active', b === btn));
            bodyEl.hidden = previewing;
            preview.hidden = !previewing;
            split.style.gridTemplateColumns = '1fr';
            if (previewing) renderMarkdown(bodyEl.value);
        });
    });
    revsEl.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-rev]');
        if (!btn || !current) return;
        api.post('/pages/' + current.id + '/restore', { version: Number(btn.dataset.rev) })
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
        if (dirty) e.preventDefault();
    });

    const open = Number(shell.dataset.openPage || 0);
    loadList('').then(() => {
        if (open > 0) return openPage(open);
        if (pages[0]) return openPage(pages[0].id);
        show(null);
    }).catch((err) => setStatus(err.message));
})();
