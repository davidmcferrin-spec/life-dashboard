const test = require('node:test');
const assert = require('node:assert/strict');
const firing = require('../../public/assets/js/brain-firing.js');

test('firing strength fades across a couple of seconds', () => {
    assert.equal(firing.FIRE_MS, 2200);
    assert.equal(firing.firingStrength(0), 1);
    assert.ok(Math.abs(firing.firingStrength(1100) - 0.5) < 0.0001);
    assert.equal(firing.firingStrength(2200), 0);
    assert.equal(firing.firingStrength(4000), 0);
    assert.equal(firing.firingStrength(-1), 0);
    assert.equal(firing.signalLabel('read', firing.firingStrength(100)), 'READ ACTIVE');
    assert.equal(firing.signalLabel('write', firing.firingStrength(100)), 'WRITE ACTIVE');
    assert.equal(firing.signalLabel('read', 0), 'LIVE SIGNAL');
});

test('rates come from the rolling window and stay blank without events', () => {
    const now = 20000;
    const rates = firing.pageRates([
        { pageId: 5, verb: 'read', atMs: 12000 },
        { pageId: 5, verb: 'read', atMs: 15000 },
        { pageId: 5, verb: 'write', atMs: 18000 },
        { pageId: 9, verb: 'read', atMs: 1000 },
    ], now, firing.WINDOW_MS);
    const row = rates.get(5);
    assert.ok(Math.abs(row.reads - 0.2) < 0.0001);
    assert.ok(Math.abs(row.writes - 0.1) < 0.0001);
    assert.equal(row.activity, 30);
    assert.equal(rates.has(9), false);
    assert.equal(firing.formatRate(row.reads), '0.2/s');
    assert.equal(firing.formatRate(null), '\u2014');
    assert.equal(firing.pageRates([], now).size, 0);
});
