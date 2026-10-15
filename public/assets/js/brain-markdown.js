/**
 * Vault preview. Storage stays Markdown. This returns sanitized HTML only:
 * text is escaped, and the only tags emitted are the ones built below.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainMarkdown = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    function esc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function knownPages(pages) {
        const map = new Map();
        (pages || []).forEach((page) => {
            if (page && page.title) {
                map.set(String(page.title).toLowerCase(), page);
            }
        });
        return map;
    }

    function renderInline(text, known) {
        const src = String(text);
        const re = /\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/g;
        const chunks = [];
        let last = 0;
        let match;
        while ((match = re.exec(src))) {
            chunks.push(esc(src.slice(last, match.index)));
            const target = match[1].trim();
            const page = known.get(target.toLowerCase());
            const label = esc((match[2] || target).trim());
            chunks.push(page
                ? `<a class="km-wiki" href="/brain?page=${Number(page.id)}">${label}</a>`
                : `<span class="km-missing">${label}</span>`);
            last = match.index + match[0].length;
        }
        chunks.push(esc(src.slice(last)));
        let html = chunks.join('');
        html = html.replace(/`([^`\n]+)`/g, (_, code) => `<code>${code}</code>`);
        html = html.replace(/==([^=\n]+)==/g, '<mark>$1</mark>');
        html = html.replace(/\$([^$\n]+)\$/g, '<span class="km-math">$1</span>');
        html = html.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
        html = html.replace(/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/g, (_, label, href) => {
            return `<a href="${href}" rel="noopener noreferrer" target="_blank">${label}</a>`;
        });
        return html;
    }

    function isTableRow(line) {
        return /^\s*\|.+\|\s*$/.test(line);
    }

    function isDivider(line) {
        return /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line);
    }

    function tableCells(line) {
        return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((cell) => cell.trim());
    }

    function render(raw, pages) {
        const known = knownPages(pages);
        const lines = String(raw || '').replace(/\r\n/g, '\n').split('\n');
        const html = [];
        let i = 0;
        while (i < lines.length) {
            const line = lines[i];
            if (/^```/.test(line)) {
                i += 1;
                const code = [];
                while (i < lines.length && !/^```/.test(lines[i])) {
                    code.push(lines[i]);
                    i += 1;
                }
                if (i < lines.length) i += 1;
                html.push(`<pre class="km-code"><code>${esc(code.join('\n'))}</code></pre>`);
                continue;
            }
            if (isTableRow(line) && i + 1 < lines.length && isDivider(lines[i + 1])) {
                const head = tableCells(line);
                i += 2;
                const body = [];
                while (i < lines.length && isTableRow(lines[i])) {
                    body.push(tableCells(lines[i]));
                    i += 1;
                }
                const th = head.map((cell) => `<th>${renderInline(cell, known)}</th>`).join('');
                const trs = body.map((row) => `<tr>${row.map((cell) => `<td>${renderInline(cell, known)}</td>`).join('')}</tr>`).join('');
                html.push(`<table class="km-table"><thead><tr>${th}</tr></thead><tbody>${trs}</tbody></table>`);
                continue;
            }
            const callout = line.match(/^>\s*\[!([a-z]+)\]\s*(.*)$/i);
            if (callout) {
                const kind = esc(callout[1].toLowerCase());
                const bits = [callout[2]];
                i += 1;
                while (i < lines.length && lines[i].startsWith('>')) {
                    bits.push(lines[i].replace(/^>\s?/, ''));
                    i += 1;
                }
                html.push(`<div class="km-callout km-${kind}">${bits.map((bit) => `<p>${renderInline(bit, known)}</p>`).join('')}</div>`);
                continue;
            }
            if (/^>\s?/.test(line)) {
                const bits = [];
                while (i < lines.length && /^>\s?/.test(lines[i])) {
                    bits.push(lines[i].replace(/^>\s?/, ''));
                    i += 1;
                }
                html.push(`<blockquote>${bits.map((bit) => `<p>${renderInline(bit, known)}</p>`).join('')}</blockquote>`);
                continue;
            }
            const heading = line.match(/^(#{1,6})\s+(.*)$/);
            if (heading) {
                const level = heading[1].length;
                html.push(`<h${level}>${renderInline(heading[2], known)}</h${level}>`);
                i += 1;
                continue;
            }
            if (/^(-{3,}|\*{3,})$/.test(line.trim())) {
                html.push('<hr>');
                i += 1;
                continue;
            }
            if (/^[-*]\s+\[[ xX]\]\s+/.test(line)) {
                const items = [];
                while (i < lines.length && /^[-*]\s+\[[ xX]\]\s+/.test(lines[i])) {
                    const checked = /\[[xX]\]/.test(lines[i]);
                    const label = renderInline(lines[i].replace(/^[-*]\s+\[[ xX]\]\s+/, ''), known);
                    items.push(`<li class="km-task"><label><input type="checkbox" disabled${checked ? ' checked' : ''}> ${label}</label></li>`);
                    i += 1;
                }
                html.push(`<ul class="km-tasks">${items.join('')}</ul>`);
                continue;
            }
            if (/^[-*]\s+/.test(line)) {
                const items = [];
                while (i < lines.length && /^[-*]\s+/.test(lines[i]) && !/^[-*]\s+\[[ xX]\]\s+/.test(lines[i])) {
                    items.push(`<li>${renderInline(lines[i].replace(/^[-*]\s+/, ''), known)}</li>`);
                    i += 1;
                }
                html.push(`<ul>${items.join('')}</ul>`);
                continue;
            }
            if (/^\d+\.\s+/.test(line)) {
                const items = [];
                while (i < lines.length && /^\d+\.\s+/.test(lines[i])) {
                    items.push(`<li>${renderInline(lines[i].replace(/^\d+\.\s+/, ''), known)}</li>`);
                    i += 1;
                }
                html.push(`<ol>${items.join('')}</ol>`);
                continue;
            }
            if (line.trim() === '') {
                i += 1;
                continue;
            }
            html.push(`<p>${renderInline(line, known)}</p>`);
            i += 1;
        }
        return html.join('') || '<p class="ld-meta">Nothing to preview.</p>';
    }

    return { render, esc };
});
