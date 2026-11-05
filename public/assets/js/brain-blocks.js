/**
 * Block editor over vault Markdown.
 * Unedited blocks keep their original bytes, including blank lines,
 * so an existing page round-trips until someone changes a block.
 */
(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.BrainBlocks = api;
    }
})(typeof window !== 'undefined' ? window : null, function () {
    const COMMANDS = [
        { id: 'h1', label: 'Heading 1', hint: '#', keywords: 'heading h1 title' },
        { id: 'h2', label: 'Heading 2', hint: '##', keywords: 'heading h2 subtitle' },
        { id: 'h3', label: 'Heading 3', hint: '###', keywords: 'heading h3' },
        { id: 'todo', label: 'Checklist', hint: '[]', keywords: 'todo task check checkbox' },
        { id: 'bullet', label: 'Bulleted list', hint: '-', keywords: 'bullet list ul' },
        { id: 'ordered', label: 'Numbered list', hint: '1.', keywords: 'numbered ordered ol' },
        { id: 'table', label: 'Table', hint: '|', keywords: 'table grid' },
        { id: 'callout', label: 'Callout', hint: '>', keywords: 'callout note tip warning aside' },
        { id: 'code', label: 'Code block', hint: '```', keywords: 'code fence pre' },
        { id: 'image', label: 'Image', hint: '!', keywords: 'image picture photo' },
        { id: 'divider', label: 'Divider', hint: '---', keywords: 'divider line horizontal rule hr' },
    ];

    function splitLines(text) {
        const lines = [];
        let i = 0;
        if (text === '') return lines;
        while (i < text.length) {
            let j = i;
            while (j < text.length && text[j] !== '\n' && text[j] !== '\r') j += 1;
            let ending = '';
            if (text[j] === '\r' && text[j + 1] === '\n') ending = '\r\n';
            else if (j < text.length && (text[j] === '\n' || text[j] === '\r')) ending = text[j];
            const content = text.slice(i, j);
            lines.push({ content: content, ending: ending, raw: content + ending });
            if (ending === '') break;
            i = j + ending.length;
        }
        return lines;
    }

    function isTableRow(line) {
        return /^\s*\|.+\|\s*$/.test(line);
    }

    function isDividerRow(line) {
        return /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line);
    }

    function tableCells(line) {
        return String(line).trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((cell) => cell.trim());
    }

    function endingOf(raw) {
        if (raw.endsWith('\r\n')) return '\r\n';
        if (raw.endsWith('\n')) return '\n';
        if (raw.endsWith('\r')) return '\r';
        return '';
    }

    function decorate(block) {
        const lines = splitLines(block.body);
        block.trail = endingOf(block.body);
        if (block.type === 'heading') {
            const match = /^(#{1,6})\s+(.*)$/.exec(lines[0] ? lines[0].content : '');
            block.level = match ? match[1].length : 1;
            block.text = match ? match[2].replace(/\s+#+\s*$/, '') : '';
        } else if (block.type === 'paragraph') {
            block.text = lines[0] ? lines[0].content : '';
        } else if (block.type === 'bullet' || block.type === 'ordered' || block.type === 'todo') {
            block.items = lines.filter((line) => line.content.trim() !== '').map((line) => {
                let text = line.content;
                let checked = false;
                if (block.type === 'todo') {
                    checked = /\[[xX]\]/.test(line.content);
                    text = line.content.replace(/^[-*]\s+\[[ xX]\]\s?/, '');
                } else if (block.type === 'bullet') {
                    text = line.content.replace(/^[-*]\s+/, '');
                } else {
                    text = line.content.replace(/^\d+\.\s+/, '');
                }
                return { raw: line.raw, text: text, checked: checked };
            });
            block.text = '';
        } else if (block.type === 'code') {
            const open = lines[0] ? lines[0].content : '';
            block.lang = open.replace(/^(?:`+|~+)/, '').trim();
            const inner = lines.slice(1);
            if (inner.length && /^(?:`{3,}|~{3,})\s*$/.test(inner[inner.length - 1].content)) inner.pop();
            block.text = inner.map((line) => line.content).join('\n');
        } else if (block.type === 'callout') {
            const first = lines[0] ? lines[0].content : '';
            const match = /^>\s*\[!([A-Za-z][\w-]*)\]\s*(.*)$/.exec(first);
            block.kind = match ? match[1].toLowerCase() : 'note';
            block.title = match ? match[2] : '';
            block.text = lines.slice(1).map((line) => line.content.replace(/^>\s?/, '')).join('\n');
        } else if (block.type === 'quote') {
            block.text = lines.map((line) => line.content.replace(/^>\s?/, '')).join('\n');
        } else if (block.type === 'image') {
            const match = /^!\[([^\]]*)\]\(([^)\s]+)\)/.exec(lines[0] ? lines[0].content : '');
            block.alt = match ? match[1] : '';
            block.url = match ? match[2] : '';
        } else if (block.type === 'table') {
            const rows = lines.map((line) => line.content).filter((line) => line.trim() !== '');
            block.head = tableCells(rows[0] || '|  |  |');
            block.rows = rows.slice(2).filter(isTableRow).map(tableCells);
            if (!block.rows.length) block.rows = [block.head.map(() => '')];
        }
        return block;
    }

    function commit(block) {
        block.source = (block.prefix || '') + (block.body || '');
        return block;
    }

    function parse(markdown) {
        const text = String(markdown == null ? '' : markdown);
        const lines = splitLines(text);
        const blocks = [];
        let i = 0;
        let prefix = '';
        function push(start, end, type) {
            const body = lines.slice(start, end).map((line) => line.raw).join('');
            blocks.push(decorate({ type: type, prefix: prefix, body: body, source: prefix + body }));
            prefix = '';
        }
        while (i < lines.length) {
            const content = lines[i].content;
            if (content.trim() === '') {
                prefix += lines[i].raw;
                i += 1;
                continue;
            }
            const fence = /^(```+|~~~+)/.exec(content);
            if (fence) {
                const mark = fence[1][0];
                const close = new RegExp('^' + mark + '{3,}\\s*$');
                let j = i + 1;
                while (j < lines.length && !close.test(lines[j].content)) j += 1;
                if (j < lines.length) j += 1;
                push(i, j, 'code');
                i = j;
                continue;
            }
            if (isTableRow(content) && i + 1 < lines.length && isDividerRow(lines[i + 1].content)) {
                let j = i + 2;
                while (j < lines.length && isTableRow(lines[j].content)) j += 1;
                push(i, j, 'table');
                i = j;
                continue;
            }
            if (/^>\s*\[![A-Za-z][\w-]*\]/.test(content)) {
                let j = i + 1;
                while (j < lines.length && /^>/.test(lines[j].content)) j += 1;
                push(i, j, 'callout');
                i = j;
                continue;
            }
            if (/^>/.test(content)) {
                let j = i + 1;
                while (j < lines.length && /^>/.test(lines[j].content)) j += 1;
                push(i, j, 'quote');
                i = j;
                continue;
            }
            if (/^[-*]\s+\[[ xX]\]/.test(content)) {
                let j = i + 1;
                while (j < lines.length && /^[-*]\s+\[[ xX]\]/.test(lines[j].content)) j += 1;
                push(i, j, 'todo');
                i = j;
                continue;
            }
            if (/^[-*]\s+/.test(content)) {
                let j = i + 1;
                while (j < lines.length && /^[-*]\s+/.test(lines[j].content) && !/^[-*]\s+\[[ xX]\]/.test(lines[j].content)) j += 1;
                push(i, j, 'bullet');
                i = j;
                continue;
            }
            if (/^\d+\.\s+/.test(content)) {
                let j = i + 1;
                while (j < lines.length && /^\d+\.\s+/.test(lines[j].content)) j += 1;
                push(i, j, 'ordered');
                i = j;
                continue;
            }
            if (/^(#{1,6})\s+/.test(content)) {
                push(i, i + 1, 'heading');
                i += 1;
                continue;
            }
            if (/^(-{3,}|\*{3,}|_{3,})$/.test(content.trim())) {
                push(i, i + 1, 'divider');
                i += 1;
                continue;
            }
            if (/^!\[[^\]]*\]\([^)\s]+\)\s*$/.test(content)) {
                push(i, i + 1, 'image');
                i += 1;
                continue;
            }
            push(i, i + 1, 'paragraph');
            i += 1;
        }
        if (prefix) {
            if (!blocks.length) {
                blocks.push(decorate({ type: 'paragraph', prefix: '', body: prefix, source: prefix, text: '' }));
            } else {
                const last = blocks[blocks.length - 1];
                last.body += prefix;
                last.source += prefix;
            }
        }
        if (!blocks.length) {
            blocks.push(decorate({ type: 'paragraph', prefix: '', body: '', source: '', text: '', trail: '' }));
        }
        return blocks;
    }

    function serialize(blocks) {
        return (blocks || []).map((block) => block.source).join('');
    }

    function newline(block) {
        return (block && block.trail) || '\n';
    }

    function fresh(type, body) {
        return decorate({ type: type, prefix: '', body: body, source: body });
    }

    function slashless(block) {
        const text = block && block.text ? block.text : '';
        if (text.charAt(0) === '/') return '';
        return text;
    }

    function applyCommand(block, id, kind) {
        const end = newline(block);
        const kept = slashless(block);
        let type = id;
        let body = '';
        if (id === 'h1' || id === 'h2' || id === 'h3') {
            type = 'heading';
            body = '#'.repeat(Number(id.slice(1))) + ' ' + kept + end;
        } else if (id === 'bullet') {
            body = '- ' + kept + end;
        } else if (id === 'ordered') {
            body = '1. ' + kept + end;
        } else if (id === 'todo') {
            body = '- [ ] ' + kept + end;
        } else if (id === 'divider') {
            body = '---' + end;
        } else if (id === 'code') {
            body = '```' + end + (kept ? kept + end : '') + '```' + end;
        } else if (id === 'callout') {
            body = '> [!' + (kind || 'note') + '] ' + kept + end;
        } else if (id === 'quote') {
            body = '> ' + kept + end;
        } else if (id === 'image') {
            body = '![' + kept + '](https://)' + end;
        } else if (id === 'table') {
            body = '| Column | Column |' + end + '| --- | --- |' + end + '|  |  |' + end;
        } else {
            return block;
        }
        block.type = type;
        block.body = body;
        return decorate(commit(block));
    }

    function matchCommands(query) {
        const q = String(query || '').trim().toLowerCase();
        if (!q) return COMMANDS.slice();
        return COMMANDS.filter((command) => (command.label + ' ' + command.keywords + ' ' + command.hint).toLowerCase().indexOf(q) !== -1);
    }

    function markdownShortcut(text) {
        const value = String(text);
        let match = /^(#{1,6}) $/.exec(value);
        if (match) return { id: 'h' + match[1].length };
        if (/^[-*] $/.test(value)) return { id: 'bullet' };
        if (/^(?:\d+)\. $/.test(value)) return { id: 'ordered' };
        if (/^(?:\[\]|\[ \]|- \[ \]) $/.test(value)) return { id: 'todo' };
        if (value === '```' || value === '~~~') return { id: 'code' };
        if (value === '---' || value === '***' || value === '___') return { id: 'divider' };
        match = /^> \[!(note|tip|warning)\] $/i.exec(value);
        if (match) return { id: 'callout', kind: match[1].toLowerCase() };
        if (value === '> ') return { id: 'quote' };
        return null;
    }

    function linkQuery(text, cursor) {
        const source = String(text);
        const at = cursor == null ? source.length : cursor;
        const before = source.slice(0, at);
        const open = before.lastIndexOf('[[');
        if (open < 0) return null;
        const slice = before.slice(open + 2);
        if (slice.indexOf(']') !== -1 || slice.indexOf('\n') !== -1 || slice.indexOf('\r') !== -1) return null;
        return { start: open, query: slice };
    }

    function suggestPages(pages, query) {
        const q = String(query || '').trim().toLowerCase();
        return (pages || []).filter((page) => page && page.title && (!q || String(page.title).toLowerCase().indexOf(q) !== -1)).slice(0, 8);
    }

    function wrapText(text, start, end, before, after) {
        const source = String(text);
        const from = Math.max(0, Math.min(start, end, source.length));
        const to = Math.max(from, Math.min(Math.max(start, end), source.length));
        const next = source.slice(0, from) + before + source.slice(from, to) + after + source.slice(to);
        return { text: next, cursor: from + before.length + (to - from) + after.length };
    }

    function wikiAt(text, offset) {
        const re = /\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|[^\]]+)?\]\]/g;
        let match;
        const source = String(text);
        const at = offset == null ? 0 : offset;
        while ((match = re.exec(source))) {
            const end = match.index + match[0].length;
            if (at >= match.index && at <= end) {
                return { title: match[1].trim(), start: match.index, end: end };
            }
        }
        return null;
    }

    function excerpt(markdown, limit) {
        const cap = limit || 180;
        const plain = String(markdown || '')
            .replace(/```[\s\S]*?```/g, ' ')
            .replace(/~~~[\s\S]*?~~~/g, ' ')
            .replace(/!\[[^\]]*\]\([^)]*\)/g, ' ')
            .replace(/\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/g, (_, title, alias) => (alias || title))
            .replace(/[#>*_`[\]]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
        return plain.length > cap ? plain.slice(0, cap - 1) + '…' : plain;
    }

    function safeImageUrl(url) {
        const value = String(url || '').trim();
        if (/^https?:\/\//i.test(value)) return value;
        if (value.charAt(0) === '/' && value.charAt(1) !== '/') return value;
        return '';
    }

    function slashQuery(text) {
        const match = /^\/([^\s]*)$/.exec(String(text || ''));
        if (!match) return null;
        return match[1];
    }

    function commands() {
        return COMMANDS.slice();
    }

    function mount(host, options) {
        const opts = options || {};
        let blocks = parse('');
        let locked = false;
        let menuMode = '';
        let menuIndex = 0;
        let menuBlock = -1;
        let menuItem = -1;
        const menu = document.createElement('div');
        menu.className = 'vault-menu';
        menu.hidden = true;
        menu.setAttribute('role', 'listbox');
        document.body.appendChild(menu);
        host.classList.add('vault-doc');
        host.setAttribute('role', 'group');
        host.setAttribute('aria-label', 'Page');

        function pages() {
            return typeof opts.pages === 'function' ? opts.pages() : (opts.pages || []);
        }

        function emit() {
            if (typeof opts.onChange === 'function') opts.onChange(serialize(blocks));
        }

        function hideMenu() {
            menu.hidden = true;
            menuMode = '';
            menu.innerHTML = '';
        }

        function fieldText(index) {
            const block = blocks[index];
            if (!block) return '';
            if (block.type === 'heading' || block.type === 'paragraph') return block.text || '';
            if (block.items && block.items.length) return block.items.map((item) => item.text).join('\n');
            return block.text || '';
        }

        function writeParagraph(index, text) {
            const block = blocks[index];
            const end = block.trail || (block.body === '' ? '' : newline(block));
            if (block.type === 'heading') {
                block.body = '#'.repeat(block.level || 1) + ' ' + text + end;
            } else {
                block.body = text + end;
            }
            block.text = text;
            commit(block);
        }

        function writeList(index) {
            const block = blocks[index];
            const end = newline(block);
            const lines = (block.items || []).map((item, n) => {
                if (block.type === 'todo') return '- [' + (item.checked ? 'x' : ' ') + '] ' + item.text + end;
                if (block.type === 'ordered') return (n + 1) + '. ' + item.text + end;
                const marker = item.raw && /^(\s*\*)/.test(item.raw) ? '* ' : '- ';
                return marker + item.text + end;
            });
            block.body = lines.join('');
            commit(block);
        }

        function paint() {
            const frag = document.createDocumentFragment();
            blocks.forEach((block, index) => {
                frag.appendChild(renderBlock(block, index));
            });
            host.textContent = '';
            host.appendChild(frag);
        }

        function renderBlock(block, index) {
            const row = document.createElement('div');
            row.className = 'vault-block vault-' + block.type;
            row.dataset.index = String(index);
            row.dataset.type = block.type;
            const handle = document.createElement('button');
            handle.type = 'button';
            handle.className = 'vault-handle';
            handle.dataset.action = 'menu';
            handle.setAttribute('aria-label', 'Block menu');
            handle.textContent = '/';
            row.appendChild(handle);
            const body = document.createElement('div');
            body.className = 'vault-block-body';
            if (block.type === 'heading' || block.type === 'paragraph') {
                body.appendChild(editable(block.text || '', 'text', block.type === 'heading' ? 'Heading' : 'Type / for blocks, [[ for a page'));
                if (block.type === 'heading') row.dataset.level = String(block.level || 1);
            } else if (block.type === 'bullet' || block.type === 'ordered' || block.type === 'todo') {
                (block.items || []).forEach((item, itemIndex) => {
                    const line = document.createElement('div');
                    line.className = 'vault-item';
                    if (block.type === 'todo') {
                        const box = document.createElement('button');
                        box.type = 'button';
                        box.className = 'vault-check';
                        box.dataset.action = 'check';
                        box.dataset.item = String(itemIndex);
                        box.setAttribute('aria-pressed', item.checked ? 'true' : 'false');
                        box.setAttribute('aria-label', item.checked ? 'Mark not done' : 'Mark done');
                        box.textContent = item.checked ? '☑' : '☐';
                        line.appendChild(box);
                    } else {
                        const marker = document.createElement('span');
                        marker.className = 'vault-marker';
                        marker.textContent = block.type === 'ordered' ? (itemIndex + 1) + '.' : '•';
                        line.appendChild(marker);
                    }
                    const field = editable(item.text, 'item', 'List');
                    field.dataset.item = String(itemIndex);
                    line.appendChild(field);
                    body.appendChild(line);
                });
            } else if (block.type === 'code') {
                const area = document.createElement('textarea');
                area.className = 'vault-code';
                area.dataset.field = 'code';
                area.value = block.text || '';
                area.spellcheck = false;
                area.setAttribute('aria-label', 'Code');
                body.appendChild(area);
            } else if (block.type === 'callout' || block.type === 'quote') {
                if (block.type === 'callout') {
                    const kind = document.createElement('select');
                    kind.className = 'vault-kind';
                    kind.dataset.field = 'kind';
                    kind.setAttribute('aria-label', 'Callout type');
                    ['note', 'tip', 'warning'].forEach((name) => {
                        const option = document.createElement('option');
                        option.value = name;
                        option.textContent = name;
                        if ((block.kind || 'note') === name) option.selected = true;
                        kind.appendChild(option);
                    });
                    const title = editable(block.title || '', 'callout-title', 'Callout');
                    body.appendChild(kind);
                    body.appendChild(title);
                }
                const area = document.createElement('textarea');
                area.className = 'vault-quote';
                area.dataset.field = 'quote';
                area.value = block.text || '';
                area.setAttribute('aria-label', block.type === 'callout' ? 'Callout' : 'Quote');
                body.appendChild(area);
            } else if (block.type === 'image') {
                const figure = document.createElement('figure');
                figure.className = 'vault-figure';
                const safe = safeImageUrl(block.url);
                if (safe) {
                    const img = document.createElement('img');
                    img.alt = block.alt || '';
                    img.src = safe;
                    figure.appendChild(img);
                }
                const url = document.createElement('input');
                url.type = 'url';
                url.className = 'input';
                url.dataset.field = 'url';
                url.value = block.url || '';
                url.placeholder = 'https://';
                url.setAttribute('aria-label', 'Image address');
                const alt = document.createElement('input');
                alt.type = 'text';
                alt.className = 'input';
                alt.dataset.field = 'alt';
                alt.value = block.alt || '';
                alt.placeholder = 'Description';
                alt.setAttribute('aria-label', 'Image description');
                figure.appendChild(url);
                figure.appendChild(alt);
                body.appendChild(figure);
            } else if (block.type === 'table') {
                const table = document.createElement('table');
                table.className = 'km-table';
                const head = document.createElement('tr');
                (block.head || []).forEach((cell, cellIndex) => {
                    const th = document.createElement('th');
                    const field = editable(cell, 'cell', 'Column');
                    field.dataset.row = '-1';
                    field.dataset.cell = String(cellIndex);
                    th.appendChild(field);
                    head.appendChild(th);
                });
                table.appendChild(head);
                (block.rows || []).forEach((rowCells, rowIndex) => {
                    const tr = document.createElement('tr');
                    rowCells.forEach((cell, cellIndex) => {
                        const td = document.createElement('td');
                        const field = editable(cell, 'cell', '');
                        field.dataset.row = String(rowIndex);
                        field.dataset.cell = String(cellIndex);
                        td.appendChild(field);
                        tr.appendChild(td);
                    });
                    table.appendChild(tr);
                });
                body.appendChild(table);
            } else if (block.type === 'divider') {
                body.appendChild(document.createElement('hr'));
            }
            row.appendChild(body);
            return row;
        }

        function editable(text, field, placeholder) {
            const el = document.createElement('div');
            el.className = 'vault-text';
            el.contentEditable = locked ? 'false' : 'plaintext-only';
            if (el.contentEditable !== 'plaintext-only') el.contentEditable = locked ? 'false' : 'true';
            el.dataset.field = field;
            el.dataset.placeholder = placeholder || '';
            el.textContent = text || '';
            el.setAttribute('role', 'textbox');
            el.setAttribute('aria-multiline', 'false');
            return el;
        }

        function blockIndex(node) {
            const row = node && node.closest ? node.closest('.vault-block') : null;
            if (!row) return -1;
            return Number(row.dataset.index);
        }

        function showMenu(index, mode, query, itemIndex) {
            menuBlock = index;
            menuMode = mode;
            menuIndex = 0;
            menuItem = itemIndex == null ? -1 : itemIndex;
            const items = mode === 'link' ? suggestPages(pages(), query) : matchCommands(query);
            menu.innerHTML = '';
            if (!items.length) {
                const empty = document.createElement('p');
                empty.className = 'vault-menu-empty';
                empty.textContent = mode === 'link' ? 'No matching page' : 'No matching block';
                menu.appendChild(empty);
            }
            items.forEach((item, itemIndex) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'vault-menu-item';
                button.setAttribute('role', 'option');
                button.dataset.pick = String(itemIndex);
                if (mode === 'link') {
                    button.textContent = item.title;
                    button.dataset.title = item.title;
                } else {
                    button.textContent = item.label;
                    const hint = document.createElement('span');
                    hint.className = 'vault-menu-hint';
                    hint.textContent = item.hint;
                    button.appendChild(hint);
                    button.dataset.command = item.id;
                }
                if (itemIndex === 0) button.classList.add('is-active');
                menu.appendChild(button);
            });
            const row = host.querySelector('.vault-block[data-index="' + index + '"]');
            const rect = row && row.getBoundingClientRect ? row.getBoundingClientRect() : { left: 16, bottom: 16, width: 280 };
            menu.hidden = false;
            menu.style.position = 'fixed';
            menu.style.left = Math.max(8, rect.left) + 'px';
            menu.style.top = (rect.bottom + 4) + 'px';
            menu.style.width = Math.max(220, Math.min(rect.width || 280, 420)) + 'px';
        }

        function chooseMenu(pick) {
            const index = menuBlock;
            const mode = menuMode;
            const button = menu.querySelector('[data-pick="' + pick + '"]');
            hideMenu();
            if (!button || index < 0 || !blocks[index]) return;
            if (mode === 'link') {
                const title = button.dataset.title || button.textContent;
                insertLink(index, title);
            } else if (button.dataset.command) {
                applyCommand(blocks[index], button.dataset.command);
                emit();
                paint();
                focusBlock(index, 0);
            }
        }

        function insertLink(index, title) {
            const block = blocks[index];
            const text = fieldText(index);
            const found = linkQuery(text, text.length);
            const start = found ? found.start : text.length;
            const next = text.slice(0, start) + '[[' + title + ']]';
            if (block.type === 'paragraph' || block.type === 'heading') {
                writeParagraph(index, next);
            } else if (block.items && block.items.length) {
                const itemIndex = menuItem >= 0 && block.items[menuItem] ? menuItem : block.items.length - 1;
                const item = block.items[itemIndex];
                const itemText = item.text || '';
                const itemLink = linkQuery(itemText, itemText.length);
                const itemStart = itemLink ? itemLink.start : itemText.length;
                item.text = itemText.slice(0, itemStart) + '[[' + title + ']]';
                writeList(index);
                next = item.text;
            } else {
                block.text = next;
                applyCommand(block, block.type === 'quote' ? 'quote' : 'h1');
            }
            emit();
            paint();
            focusBlock(index, next.length);
        }

        function focusBlock(index, offset) {
            const row = host.querySelector('.vault-block[data-index="' + index + '"]');
            if (!row) return;
            const field = row.querySelector('[data-field="text"], [data-field="item"], textarea, input');
            if (!field || typeof field.focus !== 'function') return;
            field.focus();
            if (field.dataset && field.dataset.field && offset != null && document.createRange) {
                placeCaret(field, offset);
            }
        }

        function placeCaret(field, offset) {
            if (field.setSelectionRange && typeof field.value === 'string') {
                const at = Math.min(offset, field.value.length);
                field.setSelectionRange(at, at);
                return;
            }
            const selection = window.getSelection && window.getSelection();
            if (!selection || !document.createRange) return;
            const range = document.createRange();
            const node = field.firstChild || field;
            const at = Math.min(offset, (node.textContent || '').length);
            try {
                range.setStart(node, node.nodeType === 3 ? at : 0);
                range.collapse(true);
                selection.removeAllRanges();
                selection.addRange(range);
            } catch (err) {
                /* a browser may refuse a caret in an empty element */
            }
        }

        function onInput(event) {
            if (locked) return;
            const target = event.target;
            const index = blockIndex(target);
            if (index < 0) return;
            const block = blocks[index];
            const field = target.dataset ? target.dataset.field : '';
            if (field === 'text' || field === 'item') {
                const value = target.textContent || '';
                if (field === 'text') {
                    const shortcut = markdownShortcut(value);
                    if (shortcut) {
                        block.text = '';
                        applyCommand(block, shortcut.id, shortcut.kind);
                        emit();
                        paint();
                        focusBlock(index, 0);
                        return;
                    }
                    writeParagraph(index, value);
                    const slash = slashQuery(value);
                    const link = linkQuery(value, value.length);
                    if (slash != null) showMenu(index, 'slash', slash);
                    else if (link && link.query.length >= 0 && value.slice(link.start).indexOf(']]') === -1) showMenu(index, 'link', link.query);
                    else hideMenu();
                } else {
                    const itemIndex = Number(target.dataset.item);
                    if (block.items && block.items[itemIndex]) {
                        const shortcut = markdownShortcut(value);
                        if (itemIndex === 0 && block.items.length === 1 && shortcut) {
                            block.text = '';
                            applyCommand(block, shortcut.id, shortcut.kind);
                            emit();
                            paint();
                            focusBlock(index, 0);
                            return;
                        }
                    block.items[itemIndex].text = value;
                    writeList(index);
                    const link = linkQuery(value, value.length);
                    if (link) showMenu(index, 'link', link.query, itemIndex);
                    else hideMenu();
                    } else {
                        hideMenu();
                    }
                }
                emit();
                return;
            }
            if (field === 'code') {
                const end = newline(block);
                const lang = block.lang ? block.lang : '';
                block.body = '```' + lang + end + target.value + (target.value.endsWith('\n') || target.value.endsWith('\r') ? '' : end) + '```' + end;
                block.text = target.value;
                commit(block);
                emit();
                return;
            }
            if (field === 'quote' || field === 'callout-title' || field === 'kind') {
                writeCallout(index);
                emit();
                return;
            }
            if (field === 'url' || field === 'alt') {
                const url = (host.querySelector('.vault-block[data-index="' + index + '"] [data-field="url"]') || {}).value || '';
                const alt = (host.querySelector('.vault-block[data-index="' + index + '"] [data-field="alt"]') || {}).value || '';
                block.url = url;
                block.alt = alt;
                block.body = '![' + alt.replace(/\]/g, '') + '](' + url.replace(/[)\s]/g, '') + ')' + newline(block);
                commit(block);
                emit();
                return;
            }
            if (field === 'cell') {
                writeTable(index);
                emit();
            }
        }

        function writeCallout(index) {
            const block = blocks[index];
            const row = host.querySelector('.vault-block[data-index="' + index + '"]');
            if (!row) return;
            const end = newline(block);
            const text = (row.querySelector('[data-field="quote"]') || {}).value || '';
            if (block.type === 'quote') {
                block.text = text;
                block.body = text.split(/\r?\n/).map((line) => '> ' + line).join(end) + end;
                commit(block);
                return;
            }
            const kind = (row.querySelector('[data-field="kind"]') || {}).value || block.kind || 'note';
            const title = (row.querySelector('[data-field="callout-title"]') || {}).textContent || '';
            block.kind = kind;
            block.title = title;
            block.text = text;
            const lines = ['> [!' + kind + '] ' + title];
            text.split(/\r?\n/).forEach((line) => { if (line !== '' || text !== '') lines.push('> ' + line); });
            block.body = lines.join(end) + (block.body.endsWith('\n') || block.body.endsWith('\r') || true ? end : '');
            commit(block);
        }

        function writeTable(index) {
            const block = blocks[index];
            const row = host.querySelector('.vault-block[data-index="' + index + '"]');
            if (!row) return;
            const head = [];
            const body = [];
            row.querySelectorAll('[data-field="cell"]').forEach((cell) => {
                const r = Number(cell.dataset.row);
                const c = Number(cell.dataset.cell);
                const value = cell.textContent || '';
                if (r < 0) head[c] = value;
                else {
                    body[r] = body[r] || [];
                    body[r][c] = value;
                }
            });
            const width = Math.max(head.length, 1);
            const end = newline(block);
            const line = (cells) => '| ' + cells.map((cell) => cell || ' ').join(' | ') + ' |';
            const divider = '|' + ' --- |'.repeat(width);
            block.head = head;
            block.rows = body;
            block.body = line(head) + end + divider + end + body.map((cells) => line(cells)).join(end) + end;
            commit(block);
        }

        function onKeyDown(event) {
            if (locked) return;
            if (event.isComposing) return;
            if (!menu.hidden && (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === 'Escape')) {
                const buttons = Array.prototype.slice.call(menu.querySelectorAll('.vault-menu-item'));
                if (event.key === 'Escape') {
                    hideMenu();
                    event.preventDefault();
                    return;
                }
                if (event.key === 'Enter' && buttons[menuIndex]) {
                    event.preventDefault();
                    chooseMenu(menuIndex);
                    return;
                }
                if (buttons.length) {
                    event.preventDefault();
                    menuIndex = event.key === 'ArrowDown' ? (menuIndex + 1) % buttons.length : (menuIndex - 1 + buttons.length) % buttons.length;
                    buttons.forEach((button, n) => button.classList.toggle('is-active', n === menuIndex));
                }
                return;
            }
            const mod = event.metaKey || event.ctrlKey;
            if (mod && !event.altKey) {
                const key = String(event.key || '').toLowerCase();
                if (key === 'b' || key === 'i' || key === 'e') {
                    event.preventDefault();
                    wrapField(event.target, key === 'b' ? '**' : key === 'i' ? '*' : '`');
                    return;
                }
                if (event.shiftKey && (key === '1' || key === '2' || key === '3' || key === '7' || key === '8' || key === '9')) {
                    event.preventDefault();
                    const index = blockIndex(event.target);
                    if (index < 0) return;
                    const id = key === '1' || key === '2' || key === '3' ? 'h' + key : key === '8' ? 'bullet' : key === '7' ? 'ordered' : 'todo';
                    blocks[index].text = fieldText(index).replace(/^\/\S*$/, '');
                    applyCommand(blocks[index], id);
                    emit();
                    paint();
                    focusBlock(index, 0);
                    return;
                }
            }
            if (event.key === 'Enter' && !event.shiftKey) {
                const index = blockIndex(event.target);
                if (index < 0) return;
                const block = blocks[index];
                if (block.type === 'code' || block.type === 'quote' || block.type === 'callout' || block.type === 'table' || block.type === 'image') return;
                event.preventDefault();
                splitAt(index, event.target);
                return;
            }
            if (event.key === 'Backspace') {
                const index = blockIndex(event.target);
                if (index < 0) return;
                const text = event.target.textContent || '';
                const atStart = caretOffset(event.target) === 0;
                if (atStart && text === '') {
                    event.preventDefault();
                    removeBlock(index);
                }
            }
        }

        function caretOffset(field) {
            const selection = window.getSelection && window.getSelection();
            if (!selection || !selection.rangeCount || !field.contains(selection.anchorNode)) return 0;
            const range = selection.getRangeAt(0).cloneRange();
            range.selectNodeContents(field);
            range.setEnd(selection.anchorNode, selection.anchorOffset);
            return range.toString().length;
        }

        function wrapField(field, mark) {
            if (!field || field.dataset.field !== 'text' && field.dataset.field !== 'item') return;
            const index = blockIndex(field);
            if (index < 0) return;
            const text = field.textContent || '';
            const start = caretOffset(field);
            const wrapped = wrapText(text, start, start, mark, mark);
            if (field.dataset.field === 'text') writeParagraph(index, wrapped.text);
            else if (blocks[index].items) {
                const item = Number(field.dataset.item);
                blocks[index].items[item].text = wrapped.text;
                writeList(index);
            }
            emit();
            paint();
            focusBlock(index, wrapped.cursor);
        }

        function splitAt(index, field) {
            const block = blocks[index];
            if (block.type === 'paragraph' || block.type === 'heading') {
                const text = block.text || '';
                const at = field && field.dataset.field === 'text' ? caretOffset(field) : text.length;
                const right = text.slice(at);
                writeParagraph(index, text.slice(0, at));
                if (!block.trail) {
                    block.body = text.slice(0, at) + '\n';
                    block.trail = '\n';
                    commit(block);
                }
                const added = fresh('paragraph', right + '\n');
                blocks.splice(index + 1, 0, added);
            } else if (block.items) {
                const itemIndex = field && field.dataset.item ? Number(field.dataset.item) : block.items.length - 1;
                const item = block.items[itemIndex];
                const at = caretOffset(field);
                const right = (item.text || '').slice(at);
                item.text = (item.text || '').slice(0, at);
                block.items.splice(itemIndex + 1, 0, { raw: '', text: right, checked: false });
                writeList(index);
            }
            emit();
            paint();
            focusBlock(index + (block.items ? 0 : 1), 0);
            if (block.items) {
                const row = host.querySelector('.vault-block[data-index="' + index + '"]');
                const next = row && row.querySelectorAll('[data-field="item"]')[1];
                if (next && next.focus) next.focus();
            }
        }

        function removeBlock(index) {
            if (blocks.length === 1) {
                blocks[0] = fresh('paragraph', '');
                blocks[0].source = '';
                blocks[0].body = '';
                blocks[0].text = '';
            } else {
                blocks.splice(index, 1);
            }
            hideMenu();
            emit();
            paint();
            focusBlock(Math.max(0, index - 1), 0);
        }

        function onPaste(event) {
            if (locked) return;
            const text = event.clipboardData && event.clipboardData.getData ? event.clipboardData.getData('text/plain') : '';
            if (!text) return;
            const index = blockIndex(event.target);
            if (index < 0) return;
            const looks = /^(?:#{1,6}\s|[-*]\s|\d+\.\s|>|```|\|)/m.test(text) || text.indexOf('[[') !== -1 || text.indexOf('\n') !== -1;
            if (!looks) return;
            event.preventDefault();
            const parsed = parse(text).filter((block) => block.source !== '');
            if (!parsed.length) return;
            const current = blocks[index];
            const empty = !(current.text || '').trim() && !(current.items && current.items.some((item) => item.text));
            if (empty) blocks.splice(index, 1, ...parsed);
            else blocks.splice(index + 1, 0, ...parsed);
            emit();
            paint();
            focusBlock(index, 0);
        }

        function onClick(event) {
            const pick = event.target.closest ? event.target.closest('.vault-menu-item') : null;
            if (pick && menu.contains(pick)) {
                chooseMenu(Number(pick.dataset.pick));
                return;
            }
            const action = event.target.closest ? event.target.closest('[data-action]') : null;
            if (!action || !host.contains(action)) return;
            const index = blockIndex(action);
            if (action.dataset.action === 'menu') {
                event.preventDefault();
                showMenu(index, 'slash', '');
                return;
            }
            if (action.dataset.action === 'check' && blocks[index] && blocks[index].items) {
                event.preventDefault();
                const item = blocks[index].items[Number(action.dataset.item)];
                if (!item) return;
                item.checked = !item.checked;
                writeList(index);
                emit();
                paint();
            }
        }

        host.addEventListener('input', onInput);
        host.addEventListener('keydown', onKeyDown);
        host.addEventListener('paste', onPaste);
        host.addEventListener('click', onClick);
        menu.addEventListener('click', onClick);
        document.addEventListener('click', (event) => {
            if (menu.hidden) return;
            const target = event.target;
            if (menu.contains(target)) return;
            if (target.closest && target.closest('.vault-handle')) return;
            hideMenu();
        });

        function load(markdown) {
            const next = String(markdown == null ? '' : markdown);
            if (serialize(blocks) === next && host.querySelector('.vault-block')) return;
            blocks = parse(next);
            hideMenu();
            paint();
        }

        function setLocked(on) {
            locked = !!on;
            host.classList.toggle('is-locked', locked);
            host.querySelectorAll('[contenteditable], textarea, input, select, button').forEach((el) => {
                if (el.dataset && el.dataset.action === 'menu') el.disabled = locked;
                if (el.tagName === 'TEXTAREA' || el.tagName === 'INPUT' || el.tagName === 'SELECT') el.disabled = locked;
                if (el.isContentEditable || el.getAttribute('contenteditable') != null) {
                    el.contentEditable = locked ? 'false' : 'plaintext-only';
                    if (el.contentEditable !== 'plaintext-only' && !locked) el.contentEditable = 'true';
                }
            });
        }

        load('');
        return {
            load: load,
            markdown: function () { return serialize(blocks); },
            setLocked: setLocked,
        };
    }

    return {
        parse: parse,
        serialize: serialize,
        commands: commands,
        matchCommands: matchCommands,
        markdownShortcut: markdownShortcut,
        applyCommand: applyCommand,
        linkQuery: linkQuery,
        suggestPages: suggestPages,
        wrapText: wrapText,
        wikiAt: wikiAt,
        excerpt: excerpt,
        safeImageUrl: safeImageUrl,
        mount: mount,
    };
});
