const test = require('node:test');
const assert = require('node:assert/strict');
const { render } = require('../../public/assets/js/brain-markdown.js');

const sample = [
    '# Trip notes',
    '',
    'See [[Home|the house]] and [docs](https://example.com/docs).',
    '',
    '- cables',
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
    '```',
    '<script>alert(1)</script>',
    '```',
    '',
    '<img src=x onerror=alert(1)>',
].join('\n');

test('preview renders sanitized html', () => {
    const html = render(sample, [{ id: 4, title: 'Home' }]);
    assert.match(html, /<h1>Trip notes<\/h1>/);
    assert.match(html, /<ul><li>cables<\/li><\/ul>/);
    assert.match(html, /<ol><li>First<\/li><li>Second<\/li><\/ol>/);
    assert.match(html, /<input type="checkbox" disabled checked>/);
    assert.match(html, /<input type="checkbox" disabled>/);
    assert.match(html, /<a class="km-wiki" href="\/brain\?page=4">the house<\/a>/);
    assert.match(html, /<a href="https:\/\/example.com\/docs" rel="noopener noreferrer" target="_blank">docs<\/a>/);
    assert.match(html, /<table class="km-table">/);
    assert.match(html, /<th>Item<\/th>/);
    assert.match(html, /<td>XLR<\/td>/);
    assert.match(html, /<pre class="km-code"><code>&lt;script&gt;alert\(1\)&lt;\/script&gt;<\/code><\/pre>/);
    assert.doesNotMatch(html, /<script>/);
    assert.doesNotMatch(html, /<img/);
    assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
});
