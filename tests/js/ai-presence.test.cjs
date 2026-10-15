const test = require('node:test');
const assert = require('node:assert/strict');
const presence = require('../../public/assets/js/ai-presence.js');

test('the bubble keeps the agent and the tool label only', () => {
    const text = presence.bubbleText({
        agent: 'Household',
        label: 'Money: list_transactions',
        notes: '4111111111111111',
        title: 'secret payee',
        resource: '/api/v1/money/accounts/4111111111111111',
    });
    assert.deepEqual(text, { agent: 'Household', label: 'Money: list_transactions' });
    const packed = JSON.stringify(text);
    assert.equal(packed.includes('4111'), false);
    assert.equal(packed.includes('secret'), false);
});

test('a money tool flies toward a money widget and reddit prefers the reader', () => {
    const money = presence.pickTarget('Money', [
        { type: 'legacy.todos' },
        { type: 'food_week' },
        { type: 'reddit' },
    ]);
    assert.equal(money.type, 'food_week');
    const reddit = presence.pickTarget('Reddit', [
        { type: 'reddit' },
        { type: 'reddit_feed' },
    ]);
    assert.equal(reddit.type, 'reddit_feed');
    assert.equal(presence.pickTarget('Search', [{ type: 'pages' }]), null);
});

test('quiet activity is not fresh', () => {
    const now = Date.parse('2026-10-15T12:00:00Z');
    assert.equal(presence.fresh({ at: '2026-10-15 11:59:40' }, now), true);
    assert.equal(presence.fresh({ at: '2026-10-15 11:00:00' }, now), false);
    assert.equal(presence.IDLE_MS, 30000);
});
