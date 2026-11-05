'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const blocks = require('../../public/assets/js/brain-blocks.js');

const sample = [
    '# Trip notes',
    '',
    'See [[Home|the house]] and a line.',
    '',
    '- cables',
    '- charger',
    '',
    '- [x] packed',
    '- [ ] tickets',
    '',
    '1. First',
    '2. Second',
    '',
    '| Item | Qty |',
    '| --- | --- |',
    '| XLR | 2 |',
    '',
    '```js',
    'const n = 1;',
    '```',
    '',
    '> [!note] Bring tape',
    '> under the desk',
    '',
    '![Rig](https://example.com/rig.png)',
    '',
    '---',
    '',
    'Tail',
].join('\n');

test('markdown blocks round-trip existing pages', () => {
    const parsed = blocks.parse(sample);
    assert.equal(blocks.serialize(parsed), sample);
    assert.equal(blocks.serialize(blocks.parse(blocks.serialize(parsed))), sample);
    const types = parsed.map((block) => block.type);
    assert.ok(types.includes('heading'));
    assert.ok(types.includes('bullet'));
    assert.ok(types.includes('todo'));
    assert.ok(types.includes('ordered'));
    assert.ok(types.includes('table'));
    assert.ok(types.includes('code'));
    assert.ok(types.includes('callout'));
    assert.ok(types.includes('image'));
    assert.ok(types.includes('divider'));
    assert.equal(parsed[0].text, 'Trip notes');
    assert.equal(parsed.find((block) => block.type === 'code').text, 'const n = 1;');
});

test('blank lines and windows newlines stay put', () => {
    const crlf = 'Hello\r\n\r\nWorld\r\n';
    assert.equal(blocks.serialize(blocks.parse(crlf)), crlf);
    assert.equal(blocks.serialize(blocks.parse('')), '');
    assert.equal(blocks.serialize(blocks.parse('\n\n')), '\n\n');
});

test('slash commands and markdown shortcuts name the block types', () => {
    assert.equal(blocks.markdownShortcut('# ').id, 'h1');
    assert.equal(blocks.markdownShortcut('## ').id, 'h2');
    assert.equal(blocks.markdownShortcut('- ').id, 'bullet');
    assert.equal(blocks.markdownShortcut('1. ').id, 'ordered');
    assert.equal(blocks.markdownShortcut('[] ').id, 'todo');
    assert.equal(blocks.markdownShortcut('```').id, 'code');
    assert.equal(blocks.markdownShortcut('---').id, 'divider');
    assert.equal(blocks.markdownShortcut('> [!warning] ').id, 'callout');
    assert.equal(blocks.markdownShortcut('plain'), null);
    const labels = blocks.matchCommands('tab').map((command) => command.id);
    assert.deepEqual(labels, ['table']);
    assert.equal(blocks.matchCommands('').length, blocks.commands().length);
    const paragraph = blocks.parse('notes')[0];
    blocks.applyCommand(paragraph, 'h2');
    assert.equal(paragraph.type, 'heading');
    assert.match(paragraph.source, /^## notes/);
});

test('wikilink query, wrap, and page suggestions', () => {
    assert.deepEqual(blocks.linkQuery('see [[Ho', 8), { start: 4, query: 'Ho' });
    assert.equal(blocks.linkQuery('see [[Home]]', 12), null);
    assert.equal(blocks.suggestPages([{ title: 'Home' }, { title: 'Hotel' }], 'ho').length, 2);
    assert.deepEqual(blocks.wrapText('word', 0, 4, '**', '**'), { text: '**word**', cursor: 8 });
    assert.equal(blocks.wikiAt('go [[Home|house]] now', 5).title, 'Home');
    assert.equal(blocks.excerpt('# Hello\n\n[[Home|house]]', 80), 'Hello house');
    assert.equal(blocks.safeImageUrl('https://example.com/a.png'), 'https://example.com/a.png');
    assert.equal(blocks.safeImageUrl('javascript:alert(1)'), '');
});
