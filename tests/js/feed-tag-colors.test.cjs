const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const css = fs.readFileSync(path.join(root, 'public/assets/css/dashboard.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'public/assets/js/news-feed.js'), 'utf8');

const DARK = [
    'html[data-theme="dark"] .feed-tag-0 { border-color: rgba(59, 130, 246, 0.35); color: #60a5fa; }',
    'html[data-theme="dark"] .feed-tag-1 { border-color: rgba(167, 139, 250, 0.35); color: #a78bfa; }',
    'html[data-theme="dark"] .feed-tag-2 { border-color: rgba(74, 222, 128, 0.35); color: #4ade80; }',
    'html[data-theme="dark"] .feed-tag-3 { border-color: rgba(251, 146, 60, 0.35); color: #fb923c; }',
    'html[data-theme="dark"] .feed-tag-4 { border-color: rgba(34, 211, 238, 0.35); color: #22d3ee; }',
    'html[data-theme="dark"] .feed-tag-5 { border-color: rgba(251, 113, 133, 0.35); color: #fb7185; }',
    'html[data-theme="dark"] .feed-tag-6 { border-color: rgba(250, 204, 21, 0.35); color: #facc15; }',
    'html[data-theme="dark"] .feed-tag-7 { border-color: rgba(45, 212, 191, 0.35); color: #2dd4bf; }',
    'html[data-theme="dark"] .feed-tag-8 { border-color: rgba(129, 140, 248, 0.35); color: #818cf8; }',
    'html[data-theme="dark"] .feed-tag-9 { border-color: rgba(163, 230, 53, 0.35); color: #a3e635; }',
    'html[data-theme="dark"] .feed-tag-10 { border-color: rgba(244, 114, 182, 0.35); color: #f472b6; }',
];

function sourceColor(slug) {
    const match = js.match(/function sourceColor\(slug\) \{[\s\S]*?\n\}/);
    assert.ok(match, 'sourceColor is defined in news-feed.js');
    return vm.runInNewContext(`${match[0]}\nsourceColor(${JSON.stringify(slug)})`);
}

test('dark mode source badges stay on the original palette', () => {
    for (const rule of DARK) {
        assert.equal(css.includes(rule), true, rule);
    }
    assert.equal(css.match(/html\[data-theme="dark"\] \.feed-tag-\d+/g).length, 11);
});

test('jarvis source badges use every dark hue and the user accent', () => {
    assert.equal(css.match(/html\[data-theme="jarvis"\] \.feed-tag-\d+/g).length, 11);
    for (let i = 0; i <= 10; i++) {
        const block = css.match(new RegExp(`html\\[data-theme="jarvis"\\] \\.feed-tag-${i} \\{[^}]+\\}`));
        assert.ok(block, `missing jarvis feed-tag-${i}`);
        assert.match(block[0], /var\(--hud-accent, var\(--accent\)\)/);
        assert.match(block[0], /color:/);
        assert.match(block[0], /border-color:/);
    }
    assert.match(css, /html\[data-theme="jarvis"\] \.feed-tag \{[^}]*currentColor/);
});

test('source slugs hash into the eleven badge classes', () => {
    const seen = new Set();
    for (const slug of ['xda', 'howtogeek', 'theverge', 'arstechnica', 'macrumors', 'engadget', 'bbc', 'wsj', 'cnn', 'nyt', 'wired']) {
        const cls = sourceColor(slug);
        assert.match(cls, /^feed-tag-(?:[0-9]|10)$/);
        seen.add(cls);
    }
    assert.equal(sourceColor('xda'), 'feed-tag-9');
    assert.equal(sourceColor('howtogeek'), 'feed-tag-4');
    assert.ok(seen.size >= 4);
});
