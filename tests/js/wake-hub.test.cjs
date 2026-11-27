'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const root = path.join(__dirname, '..', '..');
const script = fs.readFileSync(path.join(root, 'public/assets/js/wake-hub.js'), 'utf8');

function load() {
    const sandbox = { console };
    sandbox.globalThis = sandbox;
    vm.runInNewContext(script, sandbox);
    return sandbox.WakeHubCard;
}

test('destructive power needs an explicit confirm', () => {
    const card = load();
    for (const action of ['off', 'cycle', 'reboot', 'shutdown']) {
        assert.equal(card.destructive(action), true, action);
        const packed = card.powerBody(4, action, false);
        assert.equal(packed.body.confirm, false);
        assert.equal(packed.body.action, action);
    }
    const sent = card.powerBody(4, 'off', true, 'pdu');
    assert.equal(sent.body.confirm, true);
    assert.equal(sent.body.method, 'pdu');
    const on = card.powerBody(4, 'on', false);
    assert.equal(on.body.confirm, false);
    assert.equal(card.destructive('on'), false);
});

test('confirm copy names the machine and the action', () => {
    const card = load();
    assert.match(card.confirmCopy('Office', 'off'), /Turn off Office/);
    assert.match(card.confirmCopy('Office', 'shutdown'), /Turn off Office/);
    assert.match(card.confirmCopy('Office', 'cycle'), /Power-cycle Office/);
    assert.match(card.confirmCopy('Office', 'reboot'), /Reboot Office/);
    assert.match(card.confirmCopy('', 'off'), /this machine/);
});

test('each hub type gets the actions that type accepts', () => {
    const card = load();
    assert.equal(card.canWake('device'), true);
    assert.equal(card.canWake('roku'), false);
    assert.equal(card.actionsFor('roku').map((item) => item.action).join(','), 'on,off');
    assert.equal(card.actionsFor('plug').map((item) => item.action).join(','), 'on,off,cycle');
    assert.equal(card.actionsFor('outlet').map((item) => item.action).join(','), 'on,off,cycle');
    assert.equal(card.actionsFor('pdu').length, 0);
    assert.equal(card.actionsFor('amt').some((item) => item.action === 'shutdown'), true);
});

test('errors name an unconfigured or unreachable hub', () => {
    const card = load();
    assert.match(card.errorText({ code: 'wakehub_unconfigured', error: 'Wake Hub is not configured.' }), /not configured/);
    assert.match(card.errorText({ code: 'wakehub_unreachable', error: 'Wake Hub is unreachable at http://hub.lan:9000.' }), /unreachable/);
    assert.match(card.errorText({ code: 'wakehub_unauthorized', error: 'Wake Hub rejected the token.' }), /rejected the token/);
    assert.match(card.errorText({ code: 'confirmation_required', error: 'off can turn a machine off or cycle its power. Set confirm to true to send it.' }), /Set confirm to true/);
    assert.equal(script.includes('window.confirm'), false);
    assert.equal(script.includes('AppModal'), true);
    assert.equal(script.includes('Bearer'), false);
});
