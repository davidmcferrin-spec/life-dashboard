/**
 * Hermes chat. The browser talks to /api/v1/hermes, which holds the gateway key.
 */
'use strict';

(function () {
    const root = document.getElementById('hermes-app');
    if (!root || !window.LD) return;

    const log = root.querySelector('[data-hermes-log]');
    const status = root.querySelector('[data-hermes-status]');
    const form = root.querySelector('[data-hermes-form]');
    const field = form.querySelector('textarea');
    const sendBtn = form.querySelector('[data-hermes-send]');
    const newBtn = root.querySelector('[data-hermes-new]');
    let timer = 0;
    let sending = false;

    function setStatus(text, failed) {
        status.textContent = text || '';
        status.dataset.status = failed ? 'fail' : '';
    }

    function render(data) {
        const messages = Array.isArray(data.messages) ? data.messages : [];
        log.replaceChildren();
        if (messages.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'hermes-empty';
            empty.textContent = data.reachable
                ? 'No messages yet. Ask Hermes something.'
                : (data.detail || 'Hermes is not answering.');
            log.appendChild(empty);
        } else {
            messages.forEach(function (row) {
                const item = document.createElement('div');
                const role = row && row.role === 'user' ? 'user' : 'assistant';
                item.className = 'hermes-msg hermes-msg-' + role;
                item.textContent = row && row.content ? String(row.content) : '';
                log.appendChild(item);
            });
            log.scrollTop = log.scrollHeight;
        }
        if (!data.reachable) {
            setStatus(data.detail || 'Hermes is not answering.', true);
        } else if (!data.run_id) {
            setStatus(data.detail || '', false);
        }
        if (data.run_id) {
            setStatus('Hermes is working…', false);
            sendBtn.disabled = true;
            schedule(String(data.run_id));
        } else if (!sending) {
            sendBtn.disabled = false;
        }
    }

    function fail(error) {
        setStatus(error && error.message ? error.message : 'The message was not sent.', true);
    }

    function schedule(runId) {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () { poll(runId); }, 2000);
    }

    function poll(runId) {
        window.LD.api.get('/hermes/runs/' + encodeURIComponent(runId)).then(function (data) {
            const done = ['completed', 'failed', 'cancelled', 'interrupted', 'unknown'];
            if (data.error) {
                setStatus(String(data.error), true);
            }
            if (done.indexOf(data.status) !== -1) {
                return refresh().then(function () {
                    if (data.error) {
                        setStatus(String(data.error), true);
                    } else if (data.status !== 'completed') {
                        setStatus('Hermes stopped before it finished.', true);
                    }
                });
            }
            setStatus('Hermes is working…', false);
            schedule(runId);
        }).catch(function (error) {
            fail(error);
        });
    }

    function refresh() {
        return window.LD.api.get('/hermes/thread').then(render).catch(fail);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (sending) return;
        const message = field.value.trim();
        if (!message) return;
        sending = true;
        sendBtn.disabled = true;
        setStatus('Sending…', false);
        window.LD.api.post('/hermes/chat', { message: message }).then(function () {
            field.value = '';
            return refresh();
        }).catch(function (error) {
            fail(error);
            sendBtn.disabled = false;
        }).finally(function () {
            sending = false;
        });
    });

    newBtn.addEventListener('click', function () {
        const ask = window.AppModal && window.AppModal.confirm
            ? window.AppModal.confirm({
                title: 'New chat',
                message: 'Start a fresh Hermes session? The current transcript stays on the server, and this page shows the new one.',
                confirmLabel: 'New chat',
                cancelLabel: 'Cancel',
            })
            : Promise.resolve(window.confirm('Start a fresh Hermes session?'));
        ask.then(function (yes) {
            if (!yes) return;
            window.clearTimeout(timer);
            return window.LD.api.post('/hermes/reset', {}).then(render);
        }).catch(fail);
    });

    refresh();
})();
