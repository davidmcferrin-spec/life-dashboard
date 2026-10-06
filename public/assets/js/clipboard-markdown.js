/**
 * clipboard-markdown.js — client-side Markdown preview for cloud clipboards
 *
 * Edit in raw text; preview renders a GFM subset (headings, lists, code, links, emphasis).
 * Storage stays plain text — HTML is generated at view time only.
 */
(function (global) {
    'use strict';

    const STORAGE_KEY = 'clipboard-view-mode';

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function safeUrl(url) {
        const u = String(url).trim();
        if (/^(https?:|mailto:|\/|#)/i.test(u)) {
            return u;
        }
        return '#';
    }

    function renderInline(text) {
        let s = escHtml(text);
        s = s.replace(/`([^`\n]+)`/g, (_, code) => `<code class="clipboard-code">${code}</code>`);
        s = s.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/__([^_\n]+)__/g, '<strong>$1</strong>');
        s = s.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
        s = s.replace(/_([^_\n]+)_/g, '<em>$1</em>');
        s = s.replace(/\[([^\]\n]+)\]\(([^)\n]+)\)/g, (_, label, href) => {
            const u = escHtml(safeUrl(href));
            return `<a href="${u}" class="clipboard-link" rel="noopener noreferrer" target="_blank">${label}</a>`;
        });
        return s;
    }

    function renderMarkdown(raw) {
        if (raw == null || raw === '') {
            return '<p class="clipboard-p clipboard-empty">(empty)</p>';
        }

        const lines = String(raw).replace(/\r\n/g, '\n').split('\n');
        const html = [];
        let i = 0;

        while (i < lines.length) {
            const line = lines[i];

            const fence = line.match(/^(`{3,})(\w*)\s*$/);
            if (fence) {
                const marker = fence[1];
                const lang = fence[2] || '';
                i++;
                const codeLines = [];
                while (i < lines.length && !lines[i].startsWith(marker)) {
                    codeLines.push(lines[i]);
                    i++;
                }
                if (i < lines.length) {
                    i++;
                }
                const langClass = lang ? ` language-${escHtml(lang)}` : '';
                html.push(
                    `<pre class="clipboard-pre"><code class="clipboard-code${langClass}">`
                    + escHtml(codeLines.join('\n'))
                    + '</code></pre>',
                );
                continue;
            }

            const heading = line.match(/^(#{1,6})\s+(.*)$/);
            if (heading) {
                const level = heading[1].length;
                html.push(`<h${level} class="clipboard-h${level}">${renderInline(heading[2])}</h${level}>`);
                i++;
                continue;
            }

            if (/^---+\s*$/.test(line) || /^\*\*\*+\s*$/.test(line)) {
                html.push('<hr class="clipboard-hr">');
                i++;
                continue;
            }

            if (line.startsWith('>')) {
                const bqLines = [];
                while (i < lines.length && lines[i].startsWith('>')) {
                    bqLines.push(lines[i].replace(/^>\s?/, ''));
                    i++;
                }
                html.push(`<blockquote class="clipboard-blockquote"><p class="clipboard-p">${renderInline(bqLines.join(' '))}</p></blockquote>`);
                continue;
            }

            if (/^[-*+]\s+/.test(line)) {
                const items = [];
                while (i < lines.length && /^[-*+]\s+/.test(lines[i])) {
                    items.push(lines[i].replace(/^[-*+]\s+/, ''));
                    i++;
                }
                html.push(
                    '<ul class="clipboard-ul">'
                    + items.map((it) => `<li>${renderInline(it)}</li>`).join('')
                    + '</ul>',
                );
                continue;
            }

            if (/^\d+\.\s+/.test(line)) {
                const items = [];
                while (i < lines.length && /^\d+\.\s+/.test(lines[i])) {
                    items.push(lines[i].replace(/^\d+\.\s+/, ''));
                    i++;
                }
                html.push(
                    '<ol class="clipboard-ol">'
                    + items.map((it) => `<li>${renderInline(it)}</li>`).join('')
                    + '</ol>',
                );
                continue;
            }

            if (line.trim() === '') {
                i++;
                continue;
            }

            const para = [line];
            i++;
            while (
                i < lines.length
                && lines[i].trim() !== ''
                && !/^(#{1,6}\s|[-*+]\s|\d+\.\s|>|```|---)/.test(lines[i])
            ) {
                para.push(lines[i]);
                i++;
            }
            html.push(`<p class="clipboard-p">${renderInline(para.join(' '))}</p>`);
        }

        return html.join('\n');
    }

    /**
     * @param {object} options
     * @returns {{ getMode: function, setMode: function, refresh: function }}
     */
    function attach(options) {
        const {
            editor,
            preview,
            btnEdit,
            btnPreview,
            canWrite = true,
            defaultMode = null,
            onModeChange = null,
        } = options;

        const noop = { getMode: () => 'edit', setMode: () => {}, refresh: () => {} };
        if (!editor || !preview) {
            return noop;
        }

        let mode = defaultMode
            ?? (canWrite ? (sessionStorage.getItem(STORAGE_KEY) || 'edit') : 'preview');

        function applyMode() {
            const isPreview = mode === 'preview';
            editor.classList.toggle('hidden', isPreview);
            preview.classList.toggle('hidden', !isPreview);
            btnEdit?.classList.toggle('clip-view-active', !isPreview);
            btnEdit?.setAttribute('aria-pressed', isPreview ? 'false' : 'true');
            btnPreview?.classList.toggle('clip-view-active', isPreview);
            btnPreview?.setAttribute('aria-pressed', isPreview ? 'true' : 'false');
            if (isPreview) {
                preview.innerHTML = renderMarkdown(editor.value);
            }
            if (canWrite) {
                sessionStorage.setItem(STORAGE_KEY, mode);
            }
            onModeChange?.(mode);
        }

        function setMode(next) {
            if (next === 'edit' && !canWrite) {
                return;
            }
            mode = next;
            applyMode();
        }

        function refresh() {
            if (mode === 'preview') {
                preview.innerHTML = renderMarkdown(editor.value);
            }
        }

        btnEdit?.addEventListener('click', () => setMode('edit'));
        btnPreview?.addEventListener('click', () => setMode('preview'));

        if (!canWrite) {
            btnEdit?.classList.add('hidden');
        }

        applyMode();

        return {
            getMode: () => mode,
            setMode,
            refresh,
        };
    }

    global.ClipboardMarkdown = {
        renderMarkdown,
        attach,
        MODES: { EDIT: 'edit', PREVIEW: 'preview' },
    };
}(window));
